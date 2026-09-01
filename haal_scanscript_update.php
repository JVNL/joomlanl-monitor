<?php
// haal_scanscript_update.php
//
// Onderdeel van het zelf-bijwerkende scanscript (zie scan_template.php,
// functie probeerZelfBijTeWerken()): elke keer dat het scanscript op een
// site draait, controleert het via dit eindpunt of er een nieuwere versie
// van zichzelf beschikbaar is bij de monitor, en werkt zichzelf dan
// automatisch bij - via een heel gewoon uitgaand HTTPS-verzoek vanaf de
// site, zonder dat daar ooit een FTP-/SFTP-verbinding voor nodig is. Dat
// omzeilt de bij steeds meer hostingpartijen voorkomende blokkade van
// uitgaand FTP-verkeer.
//
// Zelfde authenticatie als ontvang_scan.php: de gedeelde geheime code +
// het domein (incl. eventuele submap) van de aanvragende site.

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');

require_once 'config.php';
require_once 'instellingen_functies.php';
require_once 'genereer_scanscript_functies.php';

$geheimeCodeVerwacht = ontsleutelWaarde(haalInstelling($pdo, 'geheime_code', ''));

$inhoud = file_get_contents('php://input');
$data = json_decode($inhoud, true);

if (!$data || ($data['geheime_code'] ?? '') !== $geheimeCodeVerwacht) {
    http_response_code(403);
    echo json_encode(['succes' => false, 'foutmelding' => 'Ongeldige of ontbrekende geheime code.']);
    exit;
}

$domein = trim($data['domein'] ?? '');
if ($domein === '') {
    http_response_code(400);
    echo json_encode(['succes' => false, 'foutmelding' => 'Geen domein meegestuurd.']);
    exit;
}

// Zelfde "www."-afhandeling als ontvang_scan.php - sites worden altijd
// zonder "www." opgeslagen, maar HTTP_HOST op de site zelf kan dat wel
// bevatten.
$domeinVoorVergelijk = preg_replace('#^www\.#i', '', $domein);

$stmt = $pdo->prepare("SELECT * FROM sites WHERE domein = ?");
$stmt->execute([$domeinVoorVergelijk]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$site) {
    // Bewust GEEN foutmelding met details - dit eindpunt is (net als
    // ontvang_scan.php) vanaf buitenaf bereikbaar, dus geen onnodige
    // informatie weggeven over welke domeinen wel/niet bekend zijn.
    http_response_code(404);
    echo json_encode(['succes' => false, 'foutmelding' => 'Onbekend domein.']);
    exit;
}

try {
    $verseInhoud = genereerScanScriptInhoud($pdo, $site);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['succes' => false, 'foutmelding' => $e->getMessage()]);
    exit;
}

// Twee losse acties, zodat het scanscript eerst goedkoop (alleen de hash)
// kan controleren of er überhaupt iets veranderd is, en alleen bij een
// daadwerkelijk verschil de volledige (grotere) inhoud hoeft op te halen.
$actie = $data['actie'] ?? 'hash';

if ($actie === 'inhoud') {
    echo json_encode(['succes' => true, 'inhoud' => $verseInhoud]);
} else {
    echo json_encode(['succes' => true, 'hash' => hash('sha256', $verseInhoud)]);
}
