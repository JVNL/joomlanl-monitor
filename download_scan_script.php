<?php
// download_scan_script.php
// Genereert een kant-en-klare scan-en-check-website.php met de actuele
// geheime code en monitor-URL uit de instellingen-tabel, zodat je nooit
// meer handmatig in dat bestand hoeft te knippen/plakken na een wijziging
// op de configuratiepagina. Geef optioneel ?site_id=X mee om ook het
// per-site ingestelde "extra scanpad" (buiten de website-root) mee te
// bakken - zonder site_id wordt dat leeg gelaten (geen extra scan).

require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'genereer_scanscript_functies.php';
require_once 'instellingen_functies.php';

$site = null;
$siteId = isset($_GET['site_id']) ? (int) $_GET['site_id'] : 0;
if ($siteId > 0) {
    $stmt = $pdo->prepare("SELECT domein, extra_scan_pad, scan_bestandsnaam FROM sites WHERE id = ?");
    $stmt->execute([$siteId]);
    $gevonden = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($gevonden) {
        $site = $gevonden;
    }
}

try {
    $inhoud = genereerScanScriptInhoud($pdo, $site);
} catch (RuntimeException $e) {
    http_response_code(500);
    die($e->getMessage());
}

// Zonder site_id (de algemene download op de configuratiepagina) is er
// geen specifieke, unieke naam bekend - dan de kale standaardnaam
// gebruiken. Mét site_id moet de gedownloade bestandsnaam EXACT
// overeenkomen met wat in de database voor deze site is vastgelegd
// (scan_bestandsnaam), anders herkent de monitor het bestand na een
// handmatige FTP-plaatsing niet meer terug.
$bestandsnaam = $site !== null ? bepaalScanBestandsnaam($site) : 'scan-en-check-website.php';

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $bestandsnaam . '"');
header('Content-Length: ' . strlen($inhoud));
echo $inhoud;

