<?php
// markeer_vertrouwd.php
// Wordt aangeroepen via AJAX (fetch) vanaf beveiliging.php wanneer een
// checkbox in de kolom "Vertrouwd" wordt aan- of uitgevinkt.

require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'fout' => 'Niet ingelogd.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

require_once 'config.php';
require_once 'csrf_functies.php';

if (!csrfTokenGeldig()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'fout' => 'Ongeldige aanvraag (CSRF-token klopt niet). Ververs de pagina.']);
    exit;
}

$siteId = isset($_POST['site_id']) ? (int)$_POST['site_id'] : 0;
$hash   = $_POST['hash'] ?? '';
$naam   = $_POST['naam'] ?? '';
$actie  = $_POST['actie'] ?? '';

if ($siteId <= 0 || $hash === '' || !in_array($actie, ['vertrouw', 'ontvertrouw'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'fout' => 'Ongeldige aanvraag.']);
    exit;
}

if ($actie === 'vertrouw') {

    $stmt = $pdo->prepare("
        INSERT INTO verdacht_vertrouwd (site_id, item_hash, item_naam, toegevoegd_op)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE item_naam = VALUES(item_naam)
    ");
    $stmt->execute([$siteId, $hash, $naam]);

} else {

    $stmt = $pdo->prepare("DELETE FROM verdacht_vertrouwd WHERE site_id = ? AND item_hash = ?");
    $stmt->execute([$siteId, $hash]);

}

echo json_encode(['ok' => true]);
