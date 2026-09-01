<?php
// ontvang_versies.php
// Dit bestand staat op de monitor (wetesten.nl/MonitorServerBianca/).
// Het ontvangt versiedata vanuit versie_check.php (geplaatst op elke site)
// en slaat die op in de Sites-tabel.

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain; charset=utf-8');

require_once 'config.php';

// Moet hetzelfde zijn als in versie_check.php
$geheimeCode = 'BiancaMonitor2026!';

$inhoud = file_get_contents('php://input');
$data = json_decode($inhoud, true);

if (!$data || ($data['geheime_code'] ?? '') !== $geheimeCode) {
    http_response_code(403);
    die('Ongeldige of ontbrekende geheime code.');
}

$domein = trim($data['domein'] ?? '');
$versies = $data['versies'] ?? [];

if ($domein === '') {
    http_response_code(400);
    die('Geen domein meegestuurd.');
}

$jceVersie = $versies['jce'] ?? null;
$akeebaVersie = $versies['akeeba'] ?? null;
$yoothemeVersie = $versies['yootheme'] ?? null;

$stmt = $pdo->prepare("
    UPDATE Sites
    SET jce_versie = ?,
        akeeba_versie = ?,
        yootheme_versie = ?,
        laatste_controle = CURDATE()
    WHERE domein = ?
");

$stmt->execute([
    $jceVersie,
    $akeebaVersie,
    $yoothemeVersie,
    $domein
]);

if ($stmt->rowCount() === 0) {
    echo "Waarschuwing: geen site gevonden in database met domein '$domein'.";
} else {
    echo "OK: versies opgeslagen voor $domein.";
}
