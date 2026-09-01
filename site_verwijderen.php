<?php
// site_verwijderen.php
//
// Verwijdert een site volledig: alle bijbehorende gegevens in ONZE eigen
// database (gescande extensies, bestandshashes, vertrouwde items,
// scanscript-geschiedenis, en de site zelf), én - als er FTP/SFTP-gegevens
// bekend zijn - een poging om het scanscript-bestand (of de meerdere
// bestandsnamen die het ooit heeft gehad, zie site_scanscript_geschiedenis)
// ook daadwerkelijk van de site zelf te verwijderen.
//
// Het verwijderen van het bestand op de site is bewust "beste poging": als
// dat om welke reden dan ook niet lukt (geen FTP-gegevens, verkeerd
// wachtwoord, bestand al weg, enz.), wordt de LOKALE opruiming gewoon
// voortgezet - de gebruiker krijgt daarna een duidelijke melding over wat
// wel/niet is gelukt, en kan het bestand zo nodig zelf handmatig verwijderen.
//
// Alleen via POST, met CSRF-bescherming, en vereist een bevestiging aan de
// kant van de gebruiker (zie de confirm()-dialoog in index.php).

require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
require_once 'csrf_functies.php';
require_once 'versleuteling_functies.php';
require_once 'instellingen_functies.php';
require_once 'sftp_functies.php';
require_once 'ftp_rechten_functies.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

vereistGeldigCsrfToken();

$siteId = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;

if ($siteId <= 0) {
    header("Location: index.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ?");
$stmt->execute([$siteId]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$site) {
    header("Location: index.php");
    exit;
}

$domein = $site['domein'];

// ------------------------------------------------------------------
// Stap 1: alle ooit gebruikte scanscript-bestandsnamen verzamelen. Naast
// de geregistreerde geschiedenis (site_scanscript_geschiedenis) nemen we
// voor de zekerheid ook de HUIDIGE bestandsnaam en de kale standaardnaam
// mee - zo worden ook oudere sites (van vóór deze geschiedenisregistratie
// bestond) netjes meegenomen.
// ------------------------------------------------------------------
$bestandsnamen = [];

$geschiedenisStmt = $pdo->prepare("SELECT bestandsnaam FROM site_scanscript_geschiedenis WHERE site_id = ?");
$geschiedenisStmt->execute([$siteId]);
foreach ($geschiedenisStmt->fetchAll(PDO::FETCH_COLUMN) as $naam) {
    $bestandsnamen[$naam] = true;
}

$bestandsnamen[bepaalScanBestandsnaam($site)] = true;
$bestandsnamen['scan-en-check-website.php'] = true;

$bestandsnamen = array_keys($bestandsnamen);

// ------------------------------------------------------------------
// Stap 2: een beste-poging om die bestanden ook daadwerkelijk van de site
// zelf te verwijderen, als er FTP/SFTP-gegevens bekend zijn.
// ------------------------------------------------------------------
$scanbestandStatus = 'geen_ftp'; // geen_ftp | gelukt | deels_mislukt | verbinding_mislukt

if (!empty($site['ftp_host'])) {
    $pad = trim($site['ftp_pad'] ?? '/', '/');
    $protocol = ($site['ftp_protocol'] ?? 'ftp') === 'sftp' ? 'sftp' : 'ftp';

    $aantalGelukt = 0;
    $aantalGeprobeerd = 0;
    $verbindingGelukt = false;

    if ($protocol === 'sftp') {
        $host       = $site['ftp_host'];
        $poort      = !empty($site['ftp_poort']) ? (int) $site['ftp_poort'] : 22;
        $gebruiker  = $site['ftp_gebruikersnaam'] ?? '';
        $wachtwoord = ontsleutelWaarde($site['ftp_wachtwoord'] ?? '');

        [$sftp, $sftpFoutmelding] = sftpVerbind($host, $poort, $gebruiker, $wachtwoord);
        if ($sftp !== null) {
            $verbindingGelukt = true;
            foreach ($bestandsnamen as $naam) {
                $aantalGeprobeerd++;
                $remotePad = $pad !== '' ? "/$pad/$naam" : "/$naam";
                // Bestaat het bestand niet (meer)? Dan is het gewenste
                // eindresultaat (bestand weg) al bereikt - dat telt hier
                // als geslaagd, niet als mislukking.
                if (!@$sftp->file_exists($remotePad) || @$sftp->delete($remotePad, false)) {
                    $aantalGelukt++;
                }
            }

            // Ook de eigen beheermap (quarantaine/geblokkeerd/prullenbak,
            // en de .htaccess die deze afschermt) opruimen - phpseclib's
            // delete() is standaard al recursief (tweede parameter true).
            $aantalGeprobeerd++;
            $beheerMapPad = $pad !== '' ? "/$pad/_scan_beheer" : '/_scan_beheer';
            if (!@$sftp->file_exists($beheerMapPad) || @$sftp->delete($beheerMapPad, true)) {
                $aantalGelukt++;
            }
        }
    } else {
        $host       = $site['ftp_host'];
        $poort      = !empty($site['ftp_poort']) ? (int) $site['ftp_poort'] : 21;
        $gebruiker  = $site['ftp_gebruikersnaam'] ?? '';
        $wachtwoord = ontsleutelWaarde($site['ftp_wachtwoord'] ?? '');
        $gebruikSsl = !empty($site['ftp_ssl']);

        $conn = $gebruikSsl
            ? @ftp_ssl_connect($host, $poort, 10)
            : @ftp_connect($host, $poort, 10);

        if ($conn !== false && @ftp_login($conn, $gebruiker, $wachtwoord)) {
            $verbindingGelukt = true;
            ftp_pasv($conn, true);

            foreach ($bestandsnamen as $naam) {
                $aantalGeprobeerd++;
                $remotePad = $pad !== '' ? "/$pad/$naam" : "/$naam";
                // Bestaat het bestand niet (meer)? Dan is het gewenste
                // eindresultaat (bestand weg) al bereikt - dat telt hier
                // als geslaagd, niet als mislukking. ftp_size() geeft -1
                // terug als het bestand niet bestaat (of de grootte
                // onbekend is, wat op de meeste servers ook -1 oplevert).
                if (@ftp_size($conn, $remotePad) === -1 || @ftp_delete($conn, $remotePad)) {
                    $aantalGelukt++;
                }
            }

            // Ook de eigen beheermap (quarantaine/geblokkeerd/prullenbak,
            // en de .htaccess die deze afschermt) opruimen - dit is een
            // NIET-lege map, dus recursief per bestand/submap opruimen
            // vóórdat de map zelf verwijderd kan worden.
            $aantalGeprobeerd++;
            $beheerMapPad = $pad !== '' ? "/$pad/_scan_beheer" : '/_scan_beheer';
            if (verwijderMapRecursiefFtp($conn, $beheerMapPad)) {
                $aantalGelukt++;
            }

            ftp_close($conn);
        } elseif ($conn !== false) {
            ftp_close($conn);
        }
    }

    if (!$verbindingGelukt) {
        $scanbestandStatus = 'verbinding_mislukt';
    } elseif ($aantalGelukt >= $aantalGeprobeerd) {
        // Let op: "gelukt" betekent hier "geen fouten tegengekomen" - een
        // bestand dat al niet meer bestond, telt hier ook als geslaagd,
        // want het eindresultaat (bestand is weg) klopt.
        $scanbestandStatus = 'gelukt';
    } else {
        $scanbestandStatus = 'deels_mislukt';
    }
}

// ------------------------------------------------------------------
// Stap 3: lokale database volledig opruimen - alle tabellen die een
// site_id-koppeling hebben, plus de site zelf.
// ------------------------------------------------------------------
$pdo->prepare("DELETE FROM site_alle_extensies WHERE site_id = ?")->execute([$siteId]);
$pdo->prepare("DELETE FROM site_extensies WHERE site_id = ?")->execute([$siteId]);
$pdo->prepare("DELETE FROM verdacht_vertrouwd WHERE site_id = ?")->execute([$siteId]);
$pdo->prepare("DELETE FROM extensie_bestand_hashes WHERE site_id = ?")->execute([$siteId]);
$pdo->prepare("DELETE FROM extensie_bestand_afwijkingen WHERE site_id = ?")->execute([$siteId]);
$pdo->prepare("DELETE FROM site_scanscript_geschiedenis WHERE site_id = ?")->execute([$siteId]);
$pdo->prepare("DELETE FROM sites WHERE id = ?")->execute([$siteId]);

header("Location: index.php?verwijderd=" . urlencode($domein) . "&scanbestand_status=" . urlencode($scanbestandStatus));
exit;
