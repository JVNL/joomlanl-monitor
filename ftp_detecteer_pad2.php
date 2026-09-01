<?php
// ftp_detecteer_pad.php
// Doorzoekt de FTP-mappenstructuur op zoek naar configuration.php - het
// bestand dat Joomla altijd precies in de echte website-root neerzet,
// ongeacht op welk niveau het FTP-account zelf binnenkomt (soms 2-3 mappen
// hoger dan public_html). Geeft het gevonden pad terug, zodat de gebruiker
// niet zelf hoeft te achterhalen/gokken op welk niveau public_html zit.
//
// Sommige hostingpartijen geven één FTP-account toegang tot MEERDERE
// domeinen tegelijk (bijv. een map "/domains" met daarin een submap per
// klantdomein). Zonder domeinbewustzijn zou de zoekfunctie zomaar de
// configuration.php van een ánder domein op hetzelfde account kunnen
// vinden. Daarom wordt, als het domein van deze site bekend is, elke
// submap die er zelf ook als een domeinnaam uitziet (bijv. "anderesite.nl")
// maar niet overeenkomt met dit domein, bewust overgeslagen.

require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: login.php");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
require_once 'csrf_functies.php';
require_once 'sftp_functies.php';

vereistGeldigCsrfToken();

// De zoekdiepte/-omvang hieronder is met opzet ruim (zie maxDiepte/
// maxBezochteMappen) - sommige hostingpartijen nesten de website-root
// dieper dan andere. Dat kan, met een trage FTP-/SFTP-verbinding, langer
// duren dan de standaard PHP-tijdslimiet toestaat.
@set_time_limit(90);

header('Content-Type: application/json; charset=utf-8');

/**
 * Haalt een mapinhoud op via de gewone FTP-extensie, met een curl-
 * gebaseerde terugval (net als bij het uploaden, zie
 * ftp_verstuur_scanscript.php) voor het geval de server een verkeerd/
 * onbereikbaar IP-adres teruggeeft bij het opzetten van de PASV-
 * databaseverbinding - een bekend probleem bij sommige hostingpartijen.
 * Zonder deze terugval kan het doorzoeken van mappen op zo'n server
 * halverwege stilzwijgend afbreken, ook al zijn inloggen en de
 * bestandsoverdracht zelf (bijv. bij het versturen van het scanscript)
 * geen probleem - listing gebruikt namelijk hetzelfde PASV-datakanaal.
 *
 * Geeft, net als ftp_rawlist(), een array met ruwe "ls -l"-achtige regels
 * terug, of false als het écht niet lukt (ook niet via de terugval).
 * Houdt daarnaast (via een statische teller, zodat de aanroepers hierboven
 * niet allemaal een extra parameter hoeven door te geven) bij hoe vaak dit
 * wel/niet lukte, puur voor diagnostische doeleinden bij een mislukking -
 * zie haalMaplijstDiagnose() verderop.
 *
 * @return string[]|false
 */
function haalMaplijstMetTerugval($conn, string $pad, string $host, int $poort, string $gebruiker, string $wachtwoord, bool $ssl)
{
    static $diagnose = ['normaal' => 0, 'curl' => 0, 'curl_actief' => 0, 'mislukt' => 0, 'curl_beschikbaar' => null, 'laatste_curl_fout' => null];

    if ($diagnose['curl_beschikbaar'] === null) {
        $diagnose['curl_beschikbaar'] = function_exists('curl_init');
    }

    $rawlist = @ftp_rawlist($conn, $pad);
    if ($rawlist !== false) {
        $diagnose['normaal']++;
        haalMaplijstDiagnose($diagnose);
        return $rawlist;
    }

    if (!$diagnose['curl_beschikbaar']) {
        $diagnose['mislukt']++;
        haalMaplijstDiagnose($diagnose);
        return false;
    }

    $padVoorUrl = trim($pad, '/');
    // Bewust altijd "ftp://" (nooit "ftps://") als schema: het "ftps://"-
    // schema laat curl een IMPLICIETE TLS-verbinding verwachten (meteen
    // vanaf de eerste byte, zoals bij poort 990) - bij poort 21 gebruikt
    // FTPS echter EXPLICIETE TLS (eerst een gewoon, onversleuteld
    // welkomstbericht, pas daarna een "AUTH TLS"-upgrade). CURLOPT_FTP_SSL
    // hieronder regelt die juiste, expliciete upgrade zelf al.
    $url = 'ftp://' . $host . ':' . $poort . '/' . $padVoorUrl . '/';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL              => $url,
        CURLOPT_USERPWD          => $gebruiker . ':' . $wachtwoord,
        CURLOPT_RETURNTRANSFER   => true,
        CURLOPT_FTP_SKIP_PASV_IP => true,
        CURLOPT_CONNECTTIMEOUT   => 10,
        CURLOPT_TIMEOUT          => 20,
        CURLOPT_FTPSSLAUTH       => CURLFTPAUTH_TLS,
        // Certificaatverificatie uitgeschakeld: dit is een TERUGVAL (wordt
        // alleen geprobeerd als de gewone methode al faalde), naar een
        // server-adres dat de gebruiker zelf heeft ingevuld (geen
        // willekeurige derde partij) - sommige hostingpartijen leveren een
        // onvolledige certificaatketen of missen een actuele CA-bundel,
        // wat verificatie anders altijd zou laten mislukken, ook bij een
        // op zich prima werkende, versleutelde verbinding.
        CURLOPT_SSL_VERIFYPEER  => false,
        CURLOPT_SSL_VERIFYHOST  => false,
    ]);
    if ($ssl) {
        curl_setopt($ch, CURLOPT_FTP_SSL, CURLFTPSSL_ALL);
    }

    $resultaat = curl_exec($ch);
    $curlFout = curl_error($ch);
    curl_close($ch);

    if ($resultaat === false || trim($resultaat) === '') {
        // Laatste redmiddel: actieve modus proberen (zie
        // haalMaplijstViaCurlActief() hierboven) - een wezenlijk ander
        // mechanisme, voor het geval passieve modus structureel wordt
        // geblokkeerd door de hostingpartij van de monitor zelf.
        $actieveModusResultaat = haalMaplijstViaCurlActief($pad, $host, $poort, $gebruiker, $wachtwoord, $ssl);
        if ($actieveModusResultaat !== false) {
            $diagnose['curl_actief']++;
            haalMaplijstDiagnose($diagnose);
            return $actieveModusResultaat;
        }

        $diagnose['mislukt']++;
        if ($curlFout !== '') {
            $diagnose['laatste_curl_fout'] = $curlFout;
        }
        haalMaplijstDiagnose($diagnose);
        return false;
    }

    $diagnose['curl']++;
    haalMaplijstDiagnose($diagnose);

    $regels = preg_split('/\r\n|\r|\n/', trim($resultaat));
    return array_values(array_filter($regels, fn($r) => $r !== ''));
}

/**
 * Laatste redmiddel: dezelfde curl-poging, maar dan in ACTIEVE modus in
 * plaats van passief. Bij passieve modus moet de monitor zelf naar een
 * willekeurige, hoge poort op de FTP-server verbinden voor de data-
 * verbinding - blokkeert de hostingpartij van de MONITOR zelf uitgaand
 * verkeer naar zulke poorten (een niet ongebruikelijke beperking), dan
 * mislukt dat altijd, ongeacht wat er met de doelserver zelf aan de hand
 * is. Bij actieve modus draait dit om: dan verbindt de FTP-server terug
 * naar de monitor. Geen garantie dat dit wél werkt (veel hostingpartijen
 * blokkeren ook inkomend verkeer op willekeurige poorten), maar wel een
 * wezenlijk ander mechanisme, dus de moeite van het proberen waard.
 *
 * @return string[]|false
 */
function haalMaplijstViaCurlActief(string $pad, string $host, int $poort, string $gebruiker, string $wachtwoord, bool $ssl)
{
    if (!function_exists('curl_init')) {
        return false;
    }

    $padVoorUrl = trim($pad, '/');
    $url = 'ftp://' . $host . ':' . $poort . '/' . $padVoorUrl . '/';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL              => $url,
        CURLOPT_USERPWD          => $gebruiker . ':' . $wachtwoord,
        CURLOPT_RETURNTRANSFER   => true,
        CURLOPT_FTPPORT          => '-', // activeert actieve modus (curl kiest zelf een lokale poort)
        CURLOPT_CONNECTTIMEOUT   => 10,
        CURLOPT_TIMEOUT          => 20,
        CURLOPT_FTPSSLAUTH       => CURLFTPAUTH_TLS,
        CURLOPT_SSL_VERIFYPEER   => false,
        CURLOPT_SSL_VERIFYHOST   => false,
    ]);
    if ($ssl) {
        curl_setopt($ch, CURLOPT_FTP_SSL, CURLFTPSSL_ALL);
    }

    $resultaat = curl_exec($ch);
    curl_close($ch);

    if ($resultaat === false || trim($resultaat) === '') {
        return false;
    }

    $regels = preg_split('/\r\n|\r|\n/', trim($resultaat));
    return array_values(array_filter($regels, fn($r) => $r !== ''));
}

/**
 * Geeft de tot nu toe verzamelde diagnose terug (roep aan zonder argument),
 * of slaat een bijgewerkte diagnose op (intern gebruik door
 * haalMaplijstMetTerugval() hierboven).
 */
function haalMaplijstDiagnose(?array $bijwerken = null): array
{
    static $laatst = ['normaal' => 0, 'curl' => 0, 'curl_actief' => 0, 'mislukt' => 0, 'curl_beschikbaar' => null, 'laatste_curl_fout' => null];
    if ($bijwerken !== null) {
        $laatst = $bijwerken;
    }
    return $laatst;
}

/**
 * Herkent of een mapnaam er zelf uitziet als een domeinnaam
 * (bijv. "voorbeeld.nl", "sub.voorbeeld.co.uk").
 */
function lijktOpDomeinNaam(string $naam): bool
{
    return (bool) preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+\.[a-z]{2,}$/i', $naam);
}

/**
 * Vergelijkt twee domeinnamen ongevoelig voor hoofdletters en een eventueel
 * "www."-voorvoegsel.
 */
function isZelfdeDomein(string $naamA, string $naamB): bool
{
    $normaliseer = function (string $naam): string {
        $naam = strtolower(trim($naam, '/'));
        return preg_replace('/^www\./', '', $naam);
    };

    $a = $normaliseer($naamA);
    $b = $normaliseer($naamB);

    return $a !== '' && $a === $b;
}

/**
 * Zoekt recursief (tot een beperkte diepte) naar configuration.php, en
 * geeft het pad van de map terug waar het gevonden is. Slaat een aantal
 * standaard irrelevante mappen bewust over, om tijd te besparen en te
 * voorkomen dat er in bijv. cache-mappen met duizenden bestanden wordt
 * gezocht. Is $doelDomein bekend, dan worden mappen die op een ánder
 * domein lijken (zie lijktOpDomeinNaam) ook overgeslagen.
 */
/**
 * Legt vast (via een statische lijst, dus zonder een extra parameter door
 * de hele recursie heen te hoeven geven) dat een map die overeenkomt met de
 * doelsubmap wel is aangetroffen, maar niet succesvol kon worden
 * doorzocht - zie de toelichting bij het gebruik in zoekConfigurationPhp().
 * Zonder argument geeft dit de tot nu toe geregistreerde paden terug.
 */
function ontoegankelijkeDoelsubmapPaden(?string $nieuwPad = null): array
{
    static $paden = [];

    if ($nieuwPad !== null) {
        $paden[] = $nieuwPad;
    }

    return $paden;
}

function zoekConfigurationPhp($conn, string $pad, int $diepte, int &$bezochteMappen, string $doelDomein, string $doelSubmap, string $host, int $poort, string $gebruiker, string $wachtwoord, bool $ssl, int $maxDiepte = 7, int $maxBezochteMappen = 600, bool $doelSubmapAlBereikt = false): ?string
{
    if ($diepte > $maxDiepte || $bezochteMappen > $maxBezochteMappen) {
        return null;
    }
    $bezochteMappen++;

    $rawlist = haalMaplijstMetTerugval($conn, $pad, $host, $poort, $gebruiker, $wachtwoord, $ssl);
    if ($rawlist === false) {
        return null;
    }

    $overslaan = ['cache', 'logs', 'log', 'tmp', 'cgi-bin', '.well-known', 'mail', 'ssl', 'backup',
                  'backups', 'node_modules', '.git', 'etc', 'proc', 'dev', '.trash', 'error_log'];

    $submappen = [];
    $bevatConfigurationPhp = false;

    foreach ($rawlist as $regel) {
        $isMap = ($regel[0] ?? '') === 'd';

        $delen = preg_split('/\s+/', $regel, 9);
        $naam  = $delen[8] ?? null;
        if ($naam === null || $naam === '.' || $naam === '..') {
            continue;
        }

        // Bestandscontrole: staat configuration.php gewoon rechtstreeks in
        // deze map? Dan is dit dezelfde maplijst-aanroep die dat al meteen
        // laat zien - geen aparte ftp_nlist()-aanroep (en dus ook geen
        // apart risico op een stille PASV-mislukking) meer nodig. Bewust
        // hier nog NIET meteen terugkeren (zie hieronder, na de
        // diagnose-registratie) - anders zou de diagnose van DEZE map
        // zelf nooit vastgelegd worden.
        if (!$isMap && strtolower($naam) === 'configuration.php') {
            $bevatConfigurationPhp = true;
            continue;
        }

        if (!$isMap) {
            continue;
        }
        if (in_array(strtolower($naam), $overslaan, true)) {
            continue;
        }
        if ($doelDomein !== '' && lijktOpDomeinNaam($naam) && !isZelfdeDomein($naam, $doelDomein)) {
            continue; // duidelijk een ánder klantdomein op hetzelfde FTP-account
        }

        $submappen[] = $naam;
    }

    // Staat de submap die bij déze site hoort ertussen (bijv. "cuppen" als
    // er ook een sibling-installatie "clabbers" naast staat, OF - zoals bij
    // "vmuikit" - een GENESTE installatie binnen dezelfde website-root)?
    // Probeer die dan EERST, VOORDAT een eventuele configuration.php die
    // hier toevallig al ligt wordt geaccepteerd. Zonder dit zou een site
    // die specifiek in "public_html/vmuikit" staat nooit gevonden worden:
    // "public_html" zelf bevat namelijk ook gewoon een (andere) website
    // met een eigen configuration.php, dus die zou zonder deze voorrang
    // altijd als eerste worden teruggegeven, ongeacht wat er nog dieper in
    // een submap met de juiste naam staat.
    $doelSubmapNogNietBereikt = $doelSubmap !== '' && !$doelSubmapAlBereikt;
    $heeftOnverkendeDoelsubmap = $doelSubmapNogNietBereikt
        && in_array(strtolower($doelSubmap), array_map('strtolower', $submappen), true);

    if ($heeftOnverkendeDoelsubmap) {
        foreach ($submappen as $naam) {
            if (strtolower($naam) !== strtolower($doelSubmap)) {
                continue;
            }
            $subPad = rtrim($pad, '/') . '/' . $naam;
            $gevonden = zoekConfigurationPhp($conn, $subPad, $diepte + 1, $bezochteMappen, $doelDomein, $doelSubmap, $host, $poort, $gebruiker, $wachtwoord, $ssl, $maxDiepte, $maxBezochteMappen, true);

            if ($gevonden !== null) {
                return $gevonden;
            }

            // De doelsubmap zelf leverde niets op (leeg, geen Joomla, of
            // ontoegankelijk) - zie ook de toelichting bij
            // ontoegankelijkeDoelsubmapPaden() verderop in dit bestand.
            // Val nu terug op een configuration.php die eventueel al hier,
            // op dit niveau zelf, lag (zie hieronder), in plaats van die
            // volledig te negeren.
            ontoegankelijkeDoelsubmapPaden($subPad);
            break;
        }
    }

    if ($bevatConfigurationPhp) {
        return $pad;
    }

    if ($doelSubmap !== '') {
        usort($submappen, function (string $a, string $b) use ($doelSubmap): int {
            $aMatch = strtolower($a) === strtolower($doelSubmap) ? 0 : 1;
            $bMatch = strtolower($b) === strtolower($doelSubmap) ? 0 : 1;

            return $aMatch <=> $bMatch;
        });
    }

    foreach ($submappen as $naam) {
        if ($heeftOnverkendeDoelsubmap && strtolower($naam) === strtolower($doelSubmap)) {
            continue; // hierboven al geprobeerd (en leverde niets op), niet nogmaals
        }

        $subPad = rtrim($pad, '/') . '/' . $naam;
        $gevonden = zoekConfigurationPhp($conn, $subPad, $diepte + 1, $bezochteMappen, $doelDomein, $doelSubmap, $host, $poort, $gebruiker, $wachtwoord, $ssl, $maxDiepte, $maxBezochteMappen, $doelSubmapAlBereikt);

        // Diagnose: de doelsubmap zelf (bijv. "vmuikit") is wel aangetroffen
        // in een maplijst, maar leverde bij het doorzoeken niets op - dat
        // gebeurt onder meer als dit FTP-account wel de NAAM van de map kan
        // zien, maar er niet daadwerkelijk in mag (een aparte, striktere
        // toegangsbeperking op die ene submap, bijv. bij een reseller-
        // achtige hostingstructuur). Zonder dit expliciet te melden, valt
        // de zoekfunctie hieronder stilzwijgend terug op de eerstvolgende
        // kandidaat - wat dan verwarrend een HELEMAAL ANDERE site kan zijn.
        if ($doelSubmap !== '' && $gevonden === null && strtolower($naam) === strtolower($doelSubmap)) {
            ontoegankelijkeDoelsubmapPaden($subPad);
        }

        if ($gevonden !== null) {
            return $gevonden;
        }
    }

    return null;
}

/**
 * Zoekt (breedte-eerst: eerst alle directe submappen, dan pas dieper)
 * naar een map die EXACT zo heet als het opgegeven domein, ongeacht waar
 * die in de mappenboom zit. Dit lost het volgende probleem op: bij een
 * FTP-account met meerdere sites kunnen er ook andere, niet-domeinachtig
 * genoemde mappen zijn (bijv. een back-upmap "bck-anderesite") die
 * toevallig een configuration.php van een ANDERE site bevatten en eerder
 * in de lijst staan dan de map met het juiste domein - een gewone
 * "eerste-de-beste-configuration.php"-zoektocht zou dan het verkeerde
 * resultaat vinden. Door eerst gericht de domeinmap zelf te lokaliseren,
 * en pas dáárna (en alleen dáárbinnen) naar configuration.php te zoeken,
 * wordt dat voorkomen.
 */
function zoekMapMetExacteNaam($conn, string $pad, string $doelDomein, int $diepte, int &$bezochteMappen, string $host, int $poort, string $gebruiker, string $wachtwoord, bool $ssl, int $maxDiepte = 7, int $maxBezochteMappen = 600): ?string
{
    if ($diepte > $maxDiepte || $bezochteMappen > $maxBezochteMappen) {
        return null;
    }
    $bezochteMappen++;

    $overslaan = ['cache', 'logs', 'log', 'tmp', 'cgi-bin', '.well-known', 'mail', 'ssl',
                  'node_modules', '.git', 'etc', 'proc', 'dev', '.trash', 'error_log'];

    $rawlist = haalMaplijstMetTerugval($conn, $pad, $host, $poort, $gebruiker, $wachtwoord, $ssl);
    if ($rawlist === false) {
        return null;
    }

    $submappen = [];
    foreach ($rawlist as $regel) {
        if (($regel[0] ?? '') !== 'd') {
            continue;
        }
        $delen = preg_split('/\s+/', $regel, 9);
        $naam  = $delen[8] ?? null;
        if ($naam === null || $naam === '.' || $naam === '..') {
            continue;
        }
        if (in_array(strtolower($naam), $overslaan, true)) {
            continue;
        }
        $submappen[] = $naam;
    }

    // Eerst alle directe submappen op een exacte naamovereenkomst checken -
    // dat vindt "domains/mijndomein.nl" bijvoorbeeld eerder dan wanneer we
    // per submap meteen de volle diepte in zouden gaan.
    foreach ($submappen as $naam) {
        if (isZelfdeDomein($naam, $doelDomein)) {
            return rtrim($pad, '/') . '/' . $naam;
        }
    }

    foreach ($submappen as $naam) {
        $subPad = rtrim($pad, '/') . '/' . $naam;
        $gevonden = zoekMapMetExacteNaam($conn, $subPad, $doelDomein, $diepte + 1, $bezochteMappen, $host, $poort, $gebruiker, $wachtwoord, $ssl, $maxDiepte, $maxBezochteMappen);
        if ($gevonden !== null) {
            return $gevonden;
        }
    }

    return null;
}

/**
 * Losse (fuzzy) vergelijking voor als een submap NIET exact zo heet als het
 * domein, maar er wel duidelijk bij hoort - bijv. de map "thriller" voor het
 * domein "thrillers-leestafel.info" (een addon-domein dat als submap van de
 * hoofdsite is geplaatst, met een verkorte eigen mapnaam). Vergelijkt het
 * hoofddeel van het domein (vóór de eerste punt) met de mapnaam: is de één
 * het begin van de ander, dan is dat een goede aanwijzing.
 */
function lijktOpDomeinSubmap(string $mapNaam, string $doelDomein): bool
{
    $mapNaam = strtolower(trim($mapNaam, '/'));
    if (strlen($mapNaam) < 3) {
        return false; // te kort, te veel kans op toevalstreffers
    }

    $domeinDelen = explode('.', strtolower($doelDomein));
    $domeinHoofddeel = $domeinDelen[0] ?? '';
    if ($domeinHoofddeel === '') {
        return false;
    }

    return strpos($domeinHoofddeel, $mapNaam) === 0 || strpos($mapNaam, $domeinHoofddeel) === 0;
}

/**
 * Zoekt (breedte-eerst) naar een map waarvan de naam los overeenkomt met het
 * domein (zie lijktOpDomeinSubmap) - gebruikt als tussenstap tussen de
 * exacte zoektocht en de brede terugval, specifiek voor het geval dat een
 * addon-domein in een submap met een eigen, kortere naam is geplaatst
 * (i.p.v. een submap die letterlijk de volledige domeinnaam heet).
 */
function zoekMapMetGelijkendeNaam($conn, string $pad, string $doelDomein, int $diepte, int &$bezochteMappen, string $host, int $poort, string $gebruiker, string $wachtwoord, bool $ssl, int $maxDiepte = 7, int $maxBezochteMappen = 600): ?string
{
    if ($diepte > $maxDiepte || $bezochteMappen > $maxBezochteMappen) {
        return null;
    }
    $bezochteMappen++;

    $overslaan = ['cache', 'logs', 'log', 'tmp', 'cgi-bin', '.well-known', 'mail', 'ssl',
                  'node_modules', '.git', 'etc', 'proc', 'dev', '.trash', 'error_log',
                  'administrator', 'components', 'modules', 'plugins', 'templates',
                  'libraries', 'media', 'includes', 'language', 'layouts', 'images', 'api'];

    $rawlist = haalMaplijstMetTerugval($conn, $pad, $host, $poort, $gebruiker, $wachtwoord, $ssl);
    if ($rawlist === false) {
        return null;
    }

    $submappen = [];
    foreach ($rawlist as $regel) {
        if (($regel[0] ?? '') !== 'd') {
            continue;
        }
        $delen = preg_split('/\s+/', $regel, 9);
        $naam  = $delen[8] ?? null;
        if ($naam === null || $naam === '.' || $naam === '..') {
            continue;
        }
        if (in_array(strtolower($naam), $overslaan, true)) {
            continue;
        }
        $submappen[] = $naam;
    }

    foreach ($submappen as $naam) {
        if (lijktOpDomeinSubmap($naam, $doelDomein)) {
            return rtrim($pad, '/') . '/' . $naam;
        }
    }

    foreach ($submappen as $naam) {
        $subPad = rtrim($pad, '/') . '/' . $naam;
        $gevonden = zoekMapMetGelijkendeNaam($conn, $subPad, $doelDomein, $diepte + 1, $bezochteMappen, $host, $poort, $gebruiker, $wachtwoord, $ssl, $maxDiepte, $maxBezochteMappen);
        if ($gevonden !== null) {
            return $gevonden;
        }
    }

    return null;
}

$host       = trim($_POST['ftp_host'] ?? '');
$protocol   = ($_POST['ftp_protocol'] ?? 'ftp') === 'sftp' ? 'sftp' : 'ftp';
$poort      = (int) ($_POST['ftp_poort'] ?? ($protocol === 'sftp' ? 22 : 21));
$gebruiker  = trim($_POST['ftp_gebruikersnaam'] ?? '');
$wachtwoord = $_POST['ftp_wachtwoord'] ?? '';
$ssl        = ($_POST['ftp_ssl'] ?? '') === '1';

// Domein van deze site, om mappen van ándere klantdomeinen op hetzelfde
// FTP-account te kunnen herkennen en overslaan (zie uitleg bovenaan).
$doelDomein = trim($_POST['domein'] ?? '');
$doelDomein = preg_replace('#^https?://#i', '', $doelDomein);
$doelDomein = preg_replace('#^www\.#i', '', $doelDomein);
$doelDomein = rtrim($doelDomein, '/');

/**
 * Splitst een (mogelijk met een submap geregistreerd) domein op in de
 * kale domeinnaam en, indien aanwezig, de submap erna (bijv.
 * "voorbeeld.nl/cuppen" -> ["voorbeeld.nl", "cuppen"]). Nodig omdat
 * sommige klanten meerdere, losse Joomla-installaties in submappen onder
 * hetzelfde domein hebben, elk apart geregistreerd als "domein.nl/submap":
 * de kale domeinnaam blijft nodig om de juiste domeinmap te herkennen
 * t.o.v. ándere klantdomeinen op hetzelfde account, en de submap om -
 * als er meerdere zulke installaties naast elkaar staan (bijv.
 * "public_html/clabbers" én "public_html/cuppen") - de bíj déze site
 * horende submap te verkiezen boven zomaar de eerst gevondene.
 */
function splitsDoelDomein(string $doelDomein): array
{
    if (strpos($doelDomein, '/') === false) {
        return [$doelDomein, ''];
    }
    [$kaal, $submap] = explode('/', $doelDomein, 2);

    return [$kaal, trim($submap, '/')];
}

[$doelDomeinKaal, $doelSubmap] = splitsDoelDomein($doelDomein);

// Is het wachtwoordveld leeg meegestuurd (bijv. gebruiker heeft het niet
// aangepast in het formulier), val dan terug op het al opgeslagen
// (versleutelde) wachtwoord van deze site, indien bekend.
$siteId = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;
if ($wachtwoord === '' && $siteId > 0) {
    $stmt = $pdo->prepare("SELECT ftp_wachtwoord FROM sites WHERE id = ?");
    $stmt->execute([$siteId]);
    $opgeslagen = $stmt->fetchColumn();
    if ($opgeslagen) {
        $wachtwoord = ontsleutelWaarde($opgeslagen);
    }
}

if ($host === '' || $gebruiker === '') {
    echo json_encode(['succes' => false, 'foutmelding' => 'Vul minimaal de server en gebruikersnaam in.']);
    exit;
}

// ============================================================================
// SFTP - via phpseclib (compleet ander protocol dan FTP/FTPS, gebaseerd op SSH)
// ============================================================================
if ($protocol === 'sftp') {
    try {
        [$sftp, $foutmelding] = sftpVerbind($host, $poort, $gebruiker, $wachtwoord);
        if ($sftp === null) {
            echo json_encode(['succes' => false, 'foutmelding' => $foutmelding]);
            exit;
        }

        $gevondenPad = null;

        // Fase 1: gericht de map met de exacte domeinnaam lokaliseren.
        if ($doelDomein !== '') {
            $bezochteMappen = 0;
            $domeinMapPad = sftpZoekMapMetExacteNaam($sftp, '.', $doelDomeinKaal, 0, $bezochteMappen);

            if ($domeinMapPad !== null) {
                $bezochteMappen = 0;
                $gevondenPad = sftpZoekConfigurationPhp($sftp, $domeinMapPad, 0, $bezochteMappen, $doelDomeinKaal, $doelSubmap);
            }
        }

        // Fase 1b: geen exacte match? Probeer een losser overeenkomende
        // mapnaam (bijv. een addon-domein in een submap met een kortere,
        // eigen naam - zie lijktOpDomeinSubmap hieronder).
        if ($gevondenPad === null && $doelDomein !== '') {
            $bezochteMappen = 0;
            $gelijkendeMapPad = sftpZoekMapMetGelijkendeNaam($sftp, '.', $doelDomeinKaal, 0, $bezochteMappen);

            if ($gelijkendeMapPad !== null) {
                $bezochteMappen = 0;
                $gevondenPad = sftpZoekConfigurationPhp($sftp, $gelijkendeMapPad, 0, $bezochteMappen, $doelDomeinKaal, $doelSubmap);
            }
        }

        // Fase 2: terugvallen op de brede zoektocht vanaf de root.
        if ($gevondenPad === null) {
            $bezochteMappen = 0;
            $gevondenPad = sftpZoekConfigurationPhp($sftp, '.', 0, $bezochteMappen, $doelDomeinKaal, $doelSubmap);
        }
    } catch (\Throwable $e) {
        echo json_encode(['succes' => false, 'foutmelding' => 'Onverwachte fout tijdens SFTP-verbinding: ' . $e->getMessage()]);
        exit;
    }

    if ($gevondenPad === null) {
        echo json_encode([
            'succes' => false,
            'foutmelding' => 'Geen configuration.php gevonden (tot 7 mappen diep doorzocht). Vul het pad handmatig in - controleer of Joomla wel op dit account staat.',
        ]);
        exit;
    }

    $pad = ltrim($gevondenPad, './');
    $pad = trim($pad, '/');
    if ($pad === '') {
        $pad = '/';
    }

    echo json_encode(['succes' => true, 'pad' => $pad]);
    exit;
}

// ============================================================================
// FTP / FTPS - de bestaande aanpak via PHP's ingebouwde ftp-extensie
// ============================================================================

$conn = $ssl
    ? @ftp_ssl_connect($host, $poort, 10)
    : @ftp_connect($host, $poort, 10);

if ($conn === false) {
    echo json_encode(['succes' => false, 'foutmelding' => "Kon geen FTP-verbinding maken met \"$host:$poort\"."]);
    exit;
}

if (!@ftp_login($conn, $gebruiker, $wachtwoord)) {
    ftp_close($conn);
    echo json_encode(['succes' => false, 'foutmelding' => 'FTP-inloggen mislukt (controleer gebruikersnaam/wachtwoord).']);
    exit;
}

ftp_pasv($conn, true);

$gevondenPad = null;

// Fase 1: gericht de map met de exacte domeinnaam lokaliseren, en ALLEEN
// daarbinnen naar configuration.php zoeken - voorkomt dat een andere,
// niet-domeinachtig genoemde map (bijv. een back-up van een andere site)
// per ongeluk als eerste wordt gevonden.
if ($doelDomein !== '') {
    $bezochteMappen = 0;
    $domeinMapPad = zoekMapMetExacteNaam($conn, '.', $doelDomeinKaal, 0, $bezochteMappen, $host, $poort, $gebruiker, $wachtwoord, $ssl);

    if ($domeinMapPad !== null) {
        $bezochteMappen = 0;
        $gevondenPad = zoekConfigurationPhp($conn, $domeinMapPad, 0, $bezochteMappen, $doelDomeinKaal, $doelSubmap, $host, $poort, $gebruiker, $wachtwoord, $ssl);
    }
}

// Fase 1b: geen exacte match? Probeer een losser overeenkomende mapnaam
// (bijv. een addon-domein in een submap met een kortere, eigen naam).
if ($gevondenPad === null && $doelDomein !== '') {
    $bezochteMappen = 0;
    $gelijkendeMapPad = zoekMapMetGelijkendeNaam($conn, '.', $doelDomeinKaal, 0, $bezochteMappen, $host, $poort, $gebruiker, $wachtwoord, $ssl);

    if ($gelijkendeMapPad !== null) {
        $bezochteMappen = 0;
        $gevondenPad = zoekConfigurationPhp($conn, $gelijkendeMapPad, 0, $bezochteMappen, $doelDomeinKaal, $doelSubmap, $host, $poort, $gebruiker, $wachtwoord, $ssl);
    }
}

// Fase 2: niets gevonden via de gerichte domeinmap (of geen domein bekend)?
// Val terug op de brede zoektocht vanaf de root, zoals voorheen.
if ($gevondenPad === null) {
    $bezochteMappen = 0;
    $gevondenPad = zoekConfigurationPhp($conn, '.', 0, $bezochteMappen, $doelDomeinKaal, $doelSubmap, $host, $poort, $gebruiker, $wachtwoord, $ssl);
}

ftp_close($conn);

if ($gevondenPad === null) {
    $diagnose = haalMaplijstDiagnose();
    $diagnoseTekst = " (diagnose: {$diagnose['normaal']}x normaal gelukt, {$diagnose['curl']}x via curl-terugval gelukt, "
        . "{$diagnose['curl_actief']}x via curl-actieve-modus gelukt, {$diagnose['mislukt']}x volledig mislukt"
        . ($diagnose['curl_beschikbaar'] === false ? '; curl-extensie niet beschikbaar op deze server' : '')
        . (!empty($diagnose['laatste_curl_fout']) ? "; laatste curl-foutmelding: {$diagnose['laatste_curl_fout']}" : '')
        . ")";
    echo json_encode([
        'succes' => false,
        'foutmelding' => 'Geen configuration.php gevonden (tot 7 mappen diep doorzocht). Vul het pad handmatig in - controleer of Joomla wel op dit FTP-account staat.' . $diagnoseTekst,
    ]);
    exit;
}

// Pad opschonen: "./public_html" -> "public_html", geen dubbele/rand-slashes.
$pad = ltrim($gevondenPad, './');
$pad = trim($pad, '/');
if ($pad === '') {
    $pad = '/';
}

$respons = ['succes' => true, 'pad' => $pad];

// Waarschuwing: de submap die specifiek bij déze site hoort (bijv.
// "vmuikit") is wel aangetroffen tijdens het zoeken, maar kon niet
// doorzocht worden (vaak een aparte toegangsbeperking specifiek op die
// ene submap) - en het uiteindelijk gevonden pad zit daar niet in, dus dit
// is vermoedelijk het pad van een ANDERE site op hetzelfde account.
$ontoegankelijk = ontoegankelijkeDoelsubmapPaden();
if ($doelSubmap !== '' && !empty($ontoegankelijk) && stripos($pad, $doelSubmap) === false) {
    $respons['waarschuwing'] = 'Let op: de map "' . $doelSubmap . '" (die bij de sitenaam van deze site hoort) is wel gezien '
        . '(' . implode(', ', array_unique($ontoegankelijk)) . '), maar kon met deze FTP-inloggegevens niet doorzocht worden - '
        . 'vermoedelijk een aparte toegangsbeperking op specifiek die map. Het hieronder ingevulde pad ("' . $pad . '") '
        . 'hoort daardoor mogelijk bij een ANDERE site op hetzelfde hostingaccount, niet bij deze - controleer dit handmatig '
        . 'voordat je opslaat.';
}

echo json_encode($respons);
