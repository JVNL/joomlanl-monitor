<?php
// scanscript_vervangen.php
//
// Vervangt, voor precies één site, het scanscript-bestand door een nieuw
// gegenereerd exemplaar met een unieke bestandsnaam - zie
// scanscript_vervangen_functies.php voor de daadwerkelijke logica.
// Gebruikt door zowel de "Vervang door nieuwe naam"-knop op de
// site-instellingenpagina, als door de eenmalige migratieknop op de
// configuratiepagina (die dit endpoint voor elke site apart aanroept,
// dezelfde aanpak als bij de FTP-bulkverzending, om een gateway-timeout
// bij veel sites te voorkomen).

require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: login.php");
    exit;
}
require_once 'config.php';
require_once 'csrf_functies.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['succes' => false, 'melding' => 'Ongeldige aanvraag.']);
    exit;
}

vereistGeldigCsrfToken();

require_once 'scanscript_vervangen_functies.php';

$siteId = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;

if ($siteId <= 0) {
    echo json_encode(['succes' => false, 'melding' => 'Ongeldige site.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ?");
$stmt->execute([$siteId]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$site) {
    echo json_encode(['succes' => false, 'melding' => 'Site niet gevonden.']);
    exit;
}

$resultaat = vervangScanscriptDoorUniekeNaam($pdo, $site);
echo json_encode($resultaat);
