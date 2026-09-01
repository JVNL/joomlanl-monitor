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

// We doen net alsof we een gewone browser zijn (Chrome), zodat
// firewalls/security-plugins ons niet als "bot" blokkeren.
const SCAN_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
    . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

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

    foreach ($sites as $index => $site) {
        $domein = $site['domein'];
        $bestandsnaam = bepaalScanBestandsnaam($site);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => bepaalSiteUrl($site, $bestandsnaam),
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
