<?php
// hosting_info.php
//
// AJAX-endpoint voor hosting_overzicht.php: geeft voor één site de
// hostinggegevens (hostingpartij, server, IP-adres) terug als JSON. Per site
// een los verzoek, zodat de overzichtspagina meteen verschijnt en de rijen
// één voor één worden ingevuld - de opzoekingen (DNS, reverse DNS, RIPEstat)
// kosten per site een paar honderd milliseconden tot een paar seconden, en
// zouden bij alle sites in één verzoek de PHP-tijdslimiet kunnen raken.

require_once 'sessie_start.php';

if (!isset($_SESSION['ingelogd'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'fout' => 'Niet ingelogd']);
    exit;
}

// De sessie direct weer vrijgeven: PHP vergrendelt het sessiebestand zolang
// het open is, waardoor de parallelle verzoeken van de overzichtspagina
// anders netjes achter elkaar in de rij zouden moeten wachten.
session_write_close();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: application/json; charset=utf-8');

require_once 'config.php';
require_once 'hosting_functies.php';

@set_time_limit(60);

$siteId = isset($_GET['site_id']) ? (int) $_GET['site_id'] : 0;

$stmt = $pdo->prepare("SELECT id, domein, ftp_host FROM sites WHERE id = ?");
$stmt->execute([$siteId]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$site) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'fout' => 'Site niet gevonden']);
    exit;
}

try {
    $info = bepaalHostingInfo($site);
    echo json_encode(['ok' => true, 'site_id' => (int) $site['id']] + $info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'fout' => 'Fout bij het ophalen: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
