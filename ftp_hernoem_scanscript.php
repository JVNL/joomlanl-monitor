<?php
// ftp_hernoem_scanscript.php
//
// Vergelijkbaar met ftp_verstuur_scanscript.php, maar dan met een NIEUWE
// bestandsnaam (gebaseerd op de huidige monitornaam) in plaats van de al
// bestaande naam: genereert per site een nieuwe, unieke bestandsnaam, zet
// het scanscript daaronder op de server, verwijdert daarna actief het
// bestand met de OUDE naam, en werkt de database bij. Wordt aangeroepen
// vanaf de knop bij de (optionele) waarschuwing op configuratie.php, ná
// het wijzigen van de monitornaam.
//
// BELANGRIJK, EN DAAROM OOK EXPLICIET VERMELD BIJ DIE KNOP: als een site
// Akeeba Admin Tools' ".htaccess Maker" gebruikt met de instelling die
// PHP-uitvoering beperkt tot een handmatig opgegeven lijst bestandsnamen,
// moet de NIEUWE bestandsnaam ook daar handmatig aan die lijst worden
// toegevoegd - anders blokkeert Admin Tools zelf de uitvoering van het
// zojuist hernoemde scanscript. Dat is niet iets wat dit script kan
// detecteren of automatisch oplossen (die instelling staat in Joomla's
// eigen database/configuratiebestand van Admin Tools, niet in een bestand
// dat wij hier kunnen aanpassen).

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

vereistGeldigCsrfToken();

require_once 'genereer_scanscript_functies.php';
require_once 'sftp_functies.php';
require_once 'instellingen_functies.php';
require_once 'ftp_rechten_functies.php';

header('Content-Type: text/plain; charset=utf-8');

$siteId = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;

if ($siteId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ?");
    $stmt->execute([$siteId]);
    $rij = $stmt->fetch(PDO::FETCH_ASSOC);
    $sites = $rij ? [$rij] : [];
} else {
    $sites = $pdo->query("
        SELECT * FROM sites
        WHERE ftp_host IS NOT NULL AND ftp_host != ''
        ORDER BY domein ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

if (empty($sites)) {
    echo "Geen site(s) gevonden met ingevulde FTP-gegevens.\n";
    exit;
}

function hernoemVoorSiteViaSftp(PDO $pdo, array $site): string
{
    $domein = $site['domein'];
    $oudeBestandsnaam = bepaalScanBestandsnaam($site);
    $nieuweBestandsnaam = genereerUniekeScanBestandsnaam($pdo);

    $host       = $site['ftp_host'];
    $poort      = !empty($site['ftp_poort']) ? (int) $site['ftp_poort'] : 22;
    $gebruiker  = $site['ftp_gebruikersnaam'] ?? '';
    $wachtwoord = ontsleutelWaarde($site['ftp_wachtwoord'] ?? '');
    $pad        = trim($site['ftp_pad'] ?? '/', '/');

    // Site-rij met de NIEUWE naam alvast even doorgeven aan de generator,
    // zodat de vingerafdruk/eventuele naamsverwijzingen in het scanscript
    // zelf ook meteen kloppen (niet dat dit vandaag al gebeurt, maar mocht
    // dat ooit toegevoegd worden, dan werkt dit meteen goed mee).
    $siteMetNieuweNaam = $site;
    $siteMetNieuweNaam['scan_bestandsnaam'] = $nieuweBestandsnaam;

    try {
        $inhoud = genereerScanScriptInhoud($pdo, $siteMetNieuweNaam);
    } catch (RuntimeException $e) {
        return "❌ $domein: kon scanscript niet genereren - " . $e->getMessage();
    }

    [$sftp, $foutmelding] = sftpVerbind($host, $poort, $gebruiker, $wachtwoord);
    if ($sftp === null) {
        return "❌ $domein: $foutmelding";
    }

    $nieuwRemotePad = $pad !== '' ? "/$pad/$nieuweBestandsnaam" : "/$nieuweBestandsnaam";
    $oudRemotePad   = $pad !== '' ? "/$pad/$oudeBestandsnaam" : "/$oudeBestandsnaam";

    if (!sftpUploadInhoud($sftp, $nieuwRemotePad, $inhoud)) {
        $doelMap = $pad !== '' ? "/$pad" : '/';
        $rechtenDiagnose = diagnoseerMapRechtenSftp($sftp, $doelMap);

        return "❌ $domein: uploaden naar \"$nieuwRemotePad\" via SFTP is mislukt - oude bestand is NIET verwijderd, blijft dus gewoon werken."
            . ($rechtenDiagnose !== null ? " $rechtenDiagnose" : ' (controleer het ingestelde pad en de maprechten.)');
    }

    $verwijderd = sftpVerwijderBestand($sftp, $oudRemotePad);

    werkScanBestandsnaamBij($pdo, (int) $site['id'], $nieuweBestandsnaam);

    if (!$verwijderd) {
        return "⚠️ $domein: nieuw scanscript \"$nieuweBestandsnaam\" geplaatst en in gebruik genomen, maar het oude "
            . "bestand \"$oudeBestandsnaam\" kon niet automatisch verwijderd worden - verwijder dat zelf nog even "
            . "handmatig via FTP. Vergeet niet: als je Admin Tools' bestandsnaam-restrictie gebruikt, voeg daar "
            . "\"$nieuweBestandsnaam\" aan toe.";
    }

    return "✅ $domein: hernoemd naar \"$nieuweBestandsnaam\" (oude bestand \"$oudeBestandsnaam\" verwijderd). "
        . "Gebruik je Admin Tools' bestandsnaam-restrictie? Voeg \"$nieuweBestandsnaam\" daar handmatig aan toe.";
}

function hernoemVoorSiteViaFtp(PDO $pdo, array $site): string
{
    $domein = $site['domein'];

    if (empty($site['ftp_host'])) {
        return "❌ $domein: geen server ingesteld - overgeslagen.";
    }

    // SFTP is een compleet ander protocol - apart afgehandeld.
    if (($site['ftp_protocol'] ?? 'ftp') === 'sftp') {
        return hernoemVoorSiteViaSftp($pdo, $site);
    }

    $oudeBestandsnaam = bepaalScanBestandsnaam($site);
    $nieuweBestandsnaam = genereerUniekeScanBestandsnaam($pdo);

    $host       = $site['ftp_host'];
    $poort      = !empty($site['ftp_poort']) ? (int) $site['ftp_poort'] : 21;
    $gebruiker  = $site['ftp_gebruikersnaam'] ?? '';
    $wachtwoord = ontsleutelWaarde($site['ftp_wachtwoord'] ?? '');
    $pad        = trim($site['ftp_pad'] ?? '/', '/');
    $gebruikSsl = !empty($site['ftp_ssl']);

    $siteMetNieuweNaam = $site;
    $siteMetNieuweNaam['scan_bestandsnaam'] = $nieuweBestandsnaam;

    try {
        $inhoud = genereerScanScriptInhoud($pdo, $siteMetNieuweNaam);
    } catch (RuntimeException $e) {
        return "❌ $domein: kon scanscript niet genereren - " . $e->getMessage();
    }

    $conn = $gebruikSsl
        ? @ftp_ssl_connect($host, $poort, 10)
        : @ftp_connect($host, $poort, 10);

    if ($conn === false) {
        return "❌ $domein: kon geen FTP-verbinding maken met $host:$poort.";
    }

    if (!@ftp_login($conn, $gebruiker, $wachtwoord)) {
        ftp_close($conn);
        return "❌ $domein: FTP-inloggen mislukt (controleer gebruikersnaam/wachtwoord).";
    }

    ftp_pasv($conn, true);

    $nieuwRemotePad = $pad !== '' ? "/$pad/$nieuweBestandsnaam" : "/$nieuweBestandsnaam";
    $oudRemotePad   = $pad !== '' ? "/$pad/$oudeBestandsnaam" : "/$oudeBestandsnaam";

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $inhoud);
    rewind($stream);

    $gelukt = @ftp_fput($conn, $nieuwRemotePad, $stream, FTP_BINARY);

    fclose($stream);

    if (!$gelukt) {
        $doelMap = $pad !== '' ? "/$pad" : '/';
        $rechtenDiagnose = diagnoseerMapRechtenFtp($conn, $doelMap);
        ftp_close($conn);

        return "❌ $domein: uploaden naar \"$nieuwRemotePad\" is mislukt - oude bestand is NIET verwijderd, blijft dus gewoon werken."
            . ($rechtenDiagnose !== null ? " $rechtenDiagnose" : ' (controleer het ingestelde pad en de maprechten.)');
    }

    // Alleen het oude bestand verwijderen als het NIET (per ongeluk)
    // dezelfde naam is als het nieuwe - zeer onwaarschijnlijk, maar
    // voorkomt dat we per ongeluk het net geüploade nieuwe bestand weer
    // meteen verwijderen.
    $verwijderd = true;
    if ($oudRemotePad !== $nieuwRemotePad) {
        $verwijderd = @ftp_delete($conn, $oudRemotePad);
    }

    ftp_close($conn);

    werkScanBestandsnaamBij($pdo, (int) $site['id'], $nieuweBestandsnaam);

    if (!$verwijderd) {
        return "⚠️ $domein: nieuw scanscript \"$nieuweBestandsnaam\" geplaatst en in gebruik genomen, maar het oude "
            . "bestand \"$oudeBestandsnaam\" kon niet automatisch verwijderd worden - verwijder dat zelf nog even "
            . "handmatig via FTP. Vergeet niet: als je Admin Tools' bestandsnaam-restrictie gebruikt, voeg daar "
            . "\"$nieuweBestandsnaam\" aan toe.";
    }

    return "✅ $domein: hernoemd naar \"$nieuweBestandsnaam\" (oude bestand \"$oudeBestandsnaam\" verwijderd). "
        . "Gebruik je Admin Tools' bestandsnaam-restrictie? Voeg \"$nieuweBestandsnaam\" daar handmatig aan toe.";
}

if ($siteId > 0) {
    echo hernoemVoorSiteViaFtp($pdo, $sites[0]);
    exit;
}

echo "=== Hernoemen gestart: " . date('Y-m-d H:i:s') . " ===\n\n";
echo "⚠️  Let op: controleer bij elke site die hieronder ✅/⚠️ meldt of daar Admin Tools' \"Alleen toestane bestandsnamen\"-restrictie actief is - zo ja, voeg de nieuwe naam daar zelf nog handmatig aan toe.\n\n";

foreach ($sites as $site) {
    echo hernoemVoorSiteViaFtp($pdo, $site) . "\n";
}

echo "\n=== Klaar ===\n";
