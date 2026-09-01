<?php
// catalogus_github_ajax.php
//
// AJAX-endpoint voor de melding op extensie_beheer.php: vergelijkt de
// lokale extensie_catalogus met het gedeelde catalogus.json-bestand op
// Github, en importeert - alleen na een bewuste klik - de door de
// gebruiker geselecteerde nieuwe/gewijzigde items.

require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['succes' => false, 'foutmelding' => 'Niet ingelogd.']);
    exit;
}
require_once 'config.php';
require_once 'csrf_functies.php';
require_once 'catalogus_github_sync.php';

header('Content-Type: application/json; charset=utf-8');

$actie = $_GET['actie'] ?? $_POST['actie'] ?? '';

if ($actie === 'controleer') {
    $resultaat = vergelijkCatalogusMetGithub($pdo);

    if ($resultaat['fout'] !== null) {
        echo json_encode(['succes' => false, 'foutmelding' => $resultaat['fout']]);
        exit;
    }

    echo json_encode([
        'succes'          => true,
        'nieuw'           => $resultaat['nieuw'],
        'gewijzigd'       => $resultaat['gewijzigd'],
        'bijgewerkt_door' => $resultaat['bijgewerkt_door'],
        'bijgewerkt_op'   => $resultaat['bijgewerkt_op'],
    ]);
    exit;
}

if ($actie === 'importeer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    vereistGeldigCsrfToken();

    $sleutels = $_POST['sleutels'] ?? [];
    if (!is_array($sleutels) || empty($sleutels)) {
        echo json_encode(['succes' => false, 'foutmelding' => 'Geen items geselecteerd.']);
        exit;
    }

    $aantal = importeerUitGithub($pdo, $sleutels);
    echo json_encode(['succes' => true, 'aantal' => $aantal]);
    exit;
}

echo json_encode(['succes' => false, 'foutmelding' => 'Onbekende actie.']);
