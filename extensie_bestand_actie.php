<?php
// extensie_bestand_actie.php
// Wordt aangeroepen via AJAX (fetch) vanaf beveiliging.php wanneer de
// "Vertrouwen"/"Niet meer vertrouwen"-knop bij een extensie-bestand-
// afwijking (zie vergelijk_extensie_bestanden.php) wordt aangeklikt. Zelfde
// soort opzet als markeer_vertrouwd.php, maar met een eigen tabel omdat de
// sleutel hier anders is opgebouwd (groep_sleutel + pad + hash, geen losse
// item_naam).

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

$siteId       = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;
$groepSleutel = $_POST['groep_sleutel'] ?? '';
$relatiefPad  = $_POST['relatief_pad'] ?? '';
$hash         = $_POST['hash'] ?? '';
$actie        = $_POST['actie'] ?? '';

if ($siteId <= 0 || $relatiefPad === '' || $hash === '' || !in_array($actie, ['vertrouw', 'ontvertrouw'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'fout' => 'Ongeldige aanvraag.']);
    exit;
}

if ($actie === 'vertrouw') {

    // BEWUST geen rij in extensie_bestand_afwijkingen verwijderen: vertrouwen
    // is een weergave-keuze (verdwijnt uit het actieve overzicht, blijft
    // zichtbaar onder "Toon ook vertrouwde items"), geen definitieve actie -
    // precies zoals bij kern_vertrouwd. De afwijkingen-tabel wordt bovendien
    // bij elke scan volledig herbouwd (TRUNCATE + INSERT), dus een losse rij
    // daarin verwijderen zou hier toch geen blijvend effect hebben.
    $stmt = $pdo->prepare("
        INSERT INTO extensie_bestand_vertrouwd (site_id, groep_sleutel, relatief_pad, hash, toegevoegd_op)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE toegevoegd_op = NOW()
    ");
    $stmt->execute([$siteId, $groepSleutel, $relatiefPad, $hash]);

} else {

    $stmt = $pdo->prepare("DELETE FROM extensie_bestand_vertrouwd WHERE site_id = ? AND relatief_pad = ? AND hash = ?");
    $stmt->execute([$siteId, $relatiefPad, $hash]);

}

echo json_encode(['ok' => true]);
