<?php
// controleer_oud_scanbestand.php
//
// Controleert, voor een site die inmiddels een EIGEN scanscript-bestandsnaam
// heeft ingesteld, of het bestand onder de oude STANDAARDNAAM
// (scan-en-check-website.php) nog steeds op de server aanwezig is. Puur
// informatief - er wordt hier bewust niets verwijderd (zie de toelichting
// in de site-instellingenpagina): de monitor kan niet met zekerheid weten
// of zo'n bestand een eigen, overbodig geworden kopie is, of legitieme
// monitorsoftware van iemand anders.

require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'csrf_functies.php';
require_once 'instellingen_functies.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['succes' => false, 'foutmelding' => 'Ongeldige aanvraag.']);
    exit;
}

vereistGeldigCsrfToken();

$siteId = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;

if ($siteId <= 0) {
    echo json_encode(['succes' => false, 'foutmelding' => 'Ongeldige site.']);
    exit;
}

$stmt = $pdo->prepare("SELECT domein, url_subpad FROM sites WHERE id = ?");
$stmt->execute([$siteId]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$site) {
    echo json_encode(['succes' => false, 'foutmelding' => 'Site niet gevonden.']);
    exit;
}

const STANDAARD_SCAN_BESTANDSNAAM = 'scan-en-check-website.php';

$url = bepaalSiteUrl($site, STANDAARD_SCAN_BESTANDSNAAM);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
]);
$body = curl_exec($ch);
$errno = curl_errno($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($errno !== 0) {
    // Geen verbinding kunnen maken - zegt niets definitiefs, dus geen
    // "aanwezig"/"afwezig"-conclusie trekken.
    echo json_encode([
        'succes' => true,
        'gevonden' => null,
        'melding' => 'Kon geen verbinding maken om dit te controleren (' . $errno . '). Probeer het later opnieuw.',
    ]);
    exit;
}

if ($httpCode === 404) {
    echo json_encode([
        'succes' => true,
        'gevonden' => false,
        'melding' => 'Niet gevonden (HTTP 404) - er staat geen bestand meer onder de oude standaardnaam. Niets op te ruimen.',
    ]);
    exit;
}

if ($httpCode >= 200 && $httpCode < 300) {
    // Bevat de respons herkenbare tekst van het scanscript zelf? Dat maakt
    // het bijna zeker dat dit inderdaad nog een oude kopie van ONS eigen
    // scanscript is (en niet toevallig een andere pagina die daar toevallig
    // ook op reageert).
    $lijktOpScanscript = $body !== false && (
        stripos($body, 'JOOMLA BACKDOOR-SCAN') !== false
        || stripos($body, 'RESULTATEN') !== false
    );

    $melding = $lijktOpScanscript
        ? 'Gevonden (HTTP 200), en de inhoud lijkt op ons scanscript - waarschijnlijk een overbodig geworden oude kopie die je zelf via FTP kunt verwijderen.'
        : 'Er staat nog iets op deze locatie (HTTP 200), maar de inhoud lijkt niet (meer) op ons scanscript - controleer dit handmatig voordat je iets verwijdert.';

    echo json_encode([
        'succes' => true,
        'gevonden' => true,
        'melding' => $melding,
    ]);
    exit;
}

echo json_encode([
    'succes' => true,
    'gevonden' => null,
    'melding' => "Onduidelijk resultaat (HTTP $httpCode) - controleer dit handmatig.",
]);
