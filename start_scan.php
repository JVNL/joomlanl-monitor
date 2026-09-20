<?php
// start_scan.php
//
// Dit script benadert op elke site het bestand "scan-en-check-website.php"
// (bijv. https://voorbeeld.nl/scan-en-check-website.php), zodat het
// scanscript op de site zelf wordt gestart.
//
// Dit script wacht NIET op het volledige scanresultaat: het scanscript
// stuurt de uitkomst zelf (asynchroon) terug naar ontvang_scan.php.
// Zowel index.php als beveiliging.php roepen na dit script zelf automatisch
// check_sites.php en haal_versies_op.php aan (met een korte wachttijd en
// een voortgangsbalk) - er is geen handmatige "Check sites"-stap meer
// nodig. De cronjob (cron_alles_scannen.php) doet dit ook al zelf,
// stapsgewijs, zonder op deze tekst te vertrouwen.
//
// Dit bestand wordt niet automatisch gedraaid, maar handmatig via de
// knop "Scan verdacht" op index.php.
//
// BELANGRIJK: alle sites worden PARALLEL benaderd (via curl_multi), niet
// na elkaar. Bij veel sites duurt elke afzonderlijke scan al snel 10+
// seconden - na elkaar afgehandeld liep de totale tijd bij meerdere sites
// dan ook zo op tot boven de time-outgrens van de server/proxy (HTTP 504).
// Parallel duurt het geheel nog maar zo lang als de traagste site.

error_reporting(E_ALL);
ini_set('display_errors', 1);
// PHP's eigen standaard max_execution_time (vaak 30 of 60 seconden, afhankelijk
// van de hostingpartij) zou anders los van onderstaande curl-timeouts alsnog
// het hele script kunnen afkappen, met een onduidelijke lege/halve reactie
// tot gevolg in plaats van een net afgeronde lijst met resultaten.
set_time_limit(0);

require_once 'config.php';
require_once 'endpoint_beveiliging.php';
require_once 'instellingen_functies.php';

// Alleen-status-modus (gebruikt door beveiliging.php bij "Herscan alleen deze website"): geeft
// terug wanneer het meest recente scanresultaat van deze site BIJ DE MONITOR is aangekomen, zonder
// een scan te starten. Zo kan de pagina wachten tot er echt een NIEUW resultaat is, in plaats van
// na een vaste wachttijd aan te nemen dat het gelukt is - het scanscript stuurt zijn uitkomst zelf
// terug, en dat kan langer duren of mislukken. Toegang is al geregeld door endpoint_beveiliging.php
// hierboven (ingelogde sessie of cron-code).
if (isset($_GET['alleen_status'])) {
    header('Content-Type: application/json; charset=utf-8');
    $statusStmt = $pdo->prepare("SELECT verdacht_laatste_scan FROM sites WHERE id = ?");
    $statusStmt->execute([(int) ($_GET['site_id'] ?? 0)]);
    $laatsteScanTijd = $statusStmt->fetchColumn();
    echo json_encode(['laatste_scan' => ($laatsteScanTijd !== false && $laatsteScanTijd !== null) ? (string) $laatsteScanTijd : '']);
    exit;
}

// We doen net alsof we een gewone browser zijn (Chrome), zodat
// firewalls/security-plugins ons niet als "bot" blokkeren.
const SCAN_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
    . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

/**
 * Herkent aan de responsheaders dat een antwoord uit een cache komt (browser-achtige headers doen er hier
 * niet toe: dit is een server-naar-server-verzoek). Geeft een korte beschrijving terug, of null.
 * $koppen: header-naam (kleine letters) => waarde.
 */
function bepaalCacheTrefferUitKoppen(array $koppen): ?string
{
    // Verschillende cache-lagen melden een treffer in hun eigen header (HIT/STALE/REVALIDATED...).
    $cacheKoppen = ['x-cache', 'x-cache-status', 'x-proxy-cache', 'x-fastcgi-cache', 'x-nginx-cache',
        'cf-cache-status', 'x-litespeed-cache', 'x-lsadc-cache', 'x-varnish-cache', 'x-sg-cache', 'x-cache-hit'];
    foreach ($cacheKoppen as $naam) {
        if (isset($koppen[$naam]) && preg_match('/\b(hit|stale|revalidated|updating)\b/i', $koppen[$naam])) {
            return $naam . ': ' . $koppen[$naam];
        }
    }
    // "Age" > 0: een tussenliggende cache heeft dit antwoord al een tijd vast.
    if (isset($koppen['age']) && (int) $koppen['age'] > 0) {
        return 'age: ' . $koppen['age'];
    }
    // Varnish: twee ids in X-Varnish = uit de cache geleverd.
    if (isset($koppen['x-varnish']) && preg_match('/^\d+\s+\d+/', trim($koppen['x-varnish']))) {
        return 'x-varnish: ' . $koppen['x-varnish'];
    }

    return null;
}

/**
 * Controleert of het antwoord van het scanscript VERS is, dus echt door een nu uitgevoerde scan is
 * geschreven, en niet uit een cache komt. Twee onafhankelijke aanwijzingen:
 *  1. de responsheaders melden een cache-treffer (werkt ook bij een nog niet bijgewerkt scanscript);
 *  2. een bijgewerkt scanscript ("Scanmap:" in de uitvoer) echoot altijd de meegestuurde ververs-code
 *     terug ("Ververs-code: ..."); ontbreekt die, dan is dit een oud opgeslagen antwoord.
 * Een ouder scanscript zonder beide kenmerken geeft geen melding (die werkt zichzelf bij zodra hij
 * één keer echt draait) - er wordt alleen gewaarschuwd bij een aantoonbaar verouderd antwoord.
 *
 * @return string|null beschrijving van waaróm het antwoord verouderd lijkt, of null als het in orde is
 */
function bepaalVerouderdAntwoord(string $inhoud, string $verversCode, array $koppen): ?string
{
    $cacheTreffer = bepaalCacheTrefferUitKoppen($koppen);
    if ($cacheTreffer !== null) {
        return 'de cache van de site meldt een treffer: ' . $cacheTreffer;
    }

    $isBijgewerktScanscript = stripos($inhoud, 'Scanmap:') !== false || stripos($inhoud, 'Ververs-code:') !== false;
    if ($isBijgewerktScanscript && $verversCode !== '' && strpos($inhoud, $verversCode) === false) {
        return 'de meegestuurde ververs-code komt niet terug in de uitvoer';
    }

    return null;
}

/**
 * Start de scan op alle meegegeven sites tegelijk (parallel), en geeft per
 * site een leesbare statusregel terug.
 *
 * @param array $sites elk element: ['domein' => ..., 'scan_bestandsnaam' => ...|null]
 * @param int $poging huidige pogingnummer (1 = eerste poging) - intern gebruikt
 *   om de automatische herhaalpoging hieronder te begrenzen, zodat een
 *   structureel (niet-tijdelijk) kapotte site niet tot een onbegrensde
 *   herhaling leidt.
 * @param int $timeoutSeconden maximale wachttijd per site voor deze ronde -
 *   lager bij een herhaalpoging (zie hieronder), om de totale tijd van dit
 *   hele PHP-verzoek ruim onder een gebruikelijke gateway-/proxy-timeout
 *   (vaak 60 seconden) te houden. Bij veel sites tegelijk (bijv. "alles
 *   scannen") loopt anders eerste-poging-timeout + wachtpauze +
 *   herhaalpoging-timeout samen op tot ruim boven zo'n limiet, met een
 *   HTTP 504 tot gevolg - ook al waren de individuele sites zelf niet het
 *   probleem.
 * @return string[] statusregels, in dezelfde volgorde als $sites
 */
function startScansParallel(array $sites, int $poging = 1, int $timeoutSeconden = 30): array
{
    $multiHandle = curl_multi_init();
    $handles = [];
    $verversCodes = [];
    $antwoordKoppen = [];

    foreach ($sites as $index => $site) {
        $domein = $site['domein'];
        $bestandsnaam = bepaalScanBestandsnaam($site);

        // Elke aanroep een unieke URL (?nc=...) en expliciet "niet uit een cache": anders kan een cache
        // van de site (browser-achtig, Cloudflare, LiteSpeed/Varnish) een oud antwoord uitleveren, waarbij
        // het scanscript helemaal niet draait - terwijl deze pagina dan toch "scan gestart" meldt.
        $verversCode = '';
        $antwoordKoppen[$index] = [];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => bepaalVerseScanUrl($site, $bestandsnaam, $verversCode),
            CURLOPT_HTTPHEADER => ['Cache-Control: no-cache', 'Pragma: no-cache'],
            CURLOPT_HEADERFUNCTION => function ($ch, $regel) use (&$antwoordKoppen, $index) {
                $delen = explode(':', $regel, 2);
                if (count($delen) === 2) {
                    $antwoordKoppen[$index][strtolower(trim($delen[0]))] = trim($delen[1]);
                }
                return strlen($regel);
            },
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeoutSeconden,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => SCAN_USER_AGENT,
        ]);
        curl_multi_add_handle($multiHandle, $ch);
        $handles[$index] = $ch;
        $verversCodes[$index] = $verversCode;
    }

    // Alle verzoeken tegelijk laten lopen totdat ze allemaal klaar zijn.
    $actief = null;
    do {
        $status = curl_multi_exec($multiHandle, $actief);
        if ($actief) {
            curl_multi_select($multiHandle, 1.0);
        }
    } while ($actief && $status === CURLM_OK);

    $resultaten = [];
    foreach ($sites as $index => $site) {
        $domein = $site['domein'];
        $ch = $handles[$index];
        $errno = curl_errno($ch);
        $errstr = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $inhoud = curl_multi_getcontent($ch);

        if ($errno !== 0) {
            $resultaten[$index] = "$domein: FOUT ($errno: $errstr)";
        } elseif ($httpCode === 403 || $httpCode === 401) {
            $resultaten[$index] = "$domein: ⚠️ Toegang geweigerd (HTTP $httpCode) - dit wijst vaak op een .htaccess-bestand "
                . "in de hoofdmap van de site (of een daarboven liggende map) dat het verzoek blokkeert, bijvoorbeeld "
                . "door een kwaadwillende \"deny from all\"-regel. Controleer de .htaccess-bestanden handmatig via FTP.";
        } elseif ($httpCode >= 200 && $httpCode < 300 && stripos((string) $inhoud, 'JOOMLA BACKDOOR-SCAN') === false) {
            // Verzoek kwam "ergens" aan (HTTP 200), maar de inhoud is niet
            // ons eigen scanscript - bijv. omdat een .htaccess het verzoek
            // stiekem doorstuurt naar een heel andere pagina (een
            // ongebruikelijke, maar wel voorkomende manier waarop een
            // kwaadwillend .htaccess-bestand zich gedraagt).
            $resultaten[$index] = "$domein: ⚠️ Onverwachte inhoud ontvangen (geen scanresultaat herkend, ondanks HTTP $httpCode) - "
                . "mogelijk stuurt een .htaccess-bestand in de hoofdmap van de site dit verzoek door naar iets anders. "
                . "Controleer de .htaccess-bestanden handmatig via FTP.";
        } elseif ($httpCode >= 200 && $httpCode < 300
            && ($verouderdReden = bepaalVerouderdAntwoord((string) $inhoud, $verversCodes[$index] ?? '', $antwoordKoppen[$index] ?? [])) !== null) {
            // Er kwam wél een scanuitvoer terug, maar een OUDE: het scanscript is niet echt uitgevoerd, dus
            // er komt ook geen nieuw resultaat bij de monitor aan. Zonder deze controle werd dit als
            // "gestart" gemeld en bleef het rapport ongemerkt de oude stand tonen.
            $resultaten[$index] = "$domein: ⚠️ De site gaf een verouderd antwoord terug ($verouderdReden) - het scanscript is dus niet echt uitgevoerd "
                . "en er komt geen nieuw scanresultaat. Waarschijnlijk houdt een cache (de hostingpartij, Cloudflare of LiteSpeed) het antwoord van deze URL vast. "
                . "Sluit het scanscript uit van caching, of hernoem het via Site-instellingen: een nieuwe bestandsnaam is een nieuwe URL, zonder oude cache-invoer.";
        } else {
            $resultaten[$index] = "$domein: gestart (HTTP $httpCode)";
        }

        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }

    curl_multi_close($multiHandle);

    // ------------------------------------------------------------------
    // Automatische herhaalpoging: bij sommige sites (bijv. door een
    // beveiligingslaag/WAF die een onbekend verzoek de eerste keer met een
    // tussenpagina afvangt, of een kort moment van cold-start-traagheid)
    // faalt de EERSTE aanvraag structureel, terwijl een tweede aanvraag
    // vlak daarna gewoon slaagt. In plaats van de gebruiker dat zelf
    // handmatig te laten herhalen, proberen we dat hier automatisch één
    // keer opnieuw - alleen voor de sites waar dat nodig is, en pas na een
    // korte pauze (direct opnieuw hetzelfde verzoek sturen heeft bij zo'n
    // tussenpagina namelijk vaak geen zin).
    $opnieuwProberen = [];
    if ($poging < 2) {
        foreach ($resultaten as $index => $regel) {
            if (strpos($regel, 'Onverwachte inhoud ontvangen') !== false) {
                $opnieuwProberen[$index] = $sites[$index];
            }
        }
    }

    if (!empty($opnieuwProberen)) {
        sleep(2);
        $herhaalResultaten = startScansParallel($opnieuwProberen, $poging + 1, 15);
        foreach ($herhaalResultaten as $index => $herhaalRegel) {
            $resultaten[$index] = strpos($herhaalRegel, 'Onverwachte inhoud ontvangen') !== false
                ? $herhaalRegel // ook de herhaalpoging mislukte - waarschuwing laten staan
                : $herhaalRegel . ' (na automatische herhaalpoging)';
        }
    }

    return $resultaten;
}

// Alle sites ophalen, of - als ?site_id= is meegegeven - alleen die ene site.
$siteId = isset($_GET['site_id']) ? (int) $_GET['site_id'] : 0;
// Bij "alle sites" (geen site_id) kan de indexpagina meegeven welke
// categorie momenteel wordt bekeken, zodat "Scan en check sites" alleen de
// sites in die categorie raakt - dus niet zomaar alles door elkaar. Wordt
// dit helemaal niet meegegeven (bijv. de cronjob, die altijd alles wil
// scannen), dan blijven ALLE sites - ongeacht categorie - gewoon
// meegenomen, zoals altijd.
$categorie = isset($_GET['categorie']) && $_GET['categorie'] === 'anderen' ? 'anderen' : (isset($_GET['categorie']) ? 'eigen' : null);

if ($siteId > 0) {
    $stmt = $pdo->prepare("SELECT domein, scan_bestandsnaam, url_subpad FROM sites WHERE id = ?");
    $stmt->execute([$siteId]);
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($categorie !== null) {
    $sql = "SELECT domein, scan_bestandsnaam, url_subpad FROM sites WHERE categorie = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$categorie]);
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sql = "SELECT domein, scan_bestandsnaam, url_subpad FROM sites";
    $resultaat = $pdo->query($sql);
    $sites = $resultaat->fetchAll(PDO::FETCH_ASSOC);
}

$log = startScansParallel($sites);

echo implode("\n", $log);
echo "\n\n" . date('Y-m-d H:i:s') . " - scanscript aangeroepen op " . count($sites) . " site(s).\n";
