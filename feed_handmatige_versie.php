<?php
// feed_handmatige_versie.php
//
// Slaat een handmatig ingevulde nieuwste versie op voor een centraal
// opgehaalde update-feed (sinds 1.29), vanuit het blok "Centraal
// opgehaalde update-feeds" op het extensieoverzicht.
//
// Bedoeld voor update-servers die alle geautomatiseerde verzoeken weigeren
// (van de sites én van de monitor), maar waarvan de beheerder de feed in
// zijn eigen browser wel kan openen: versienummer daar aflezen, hier
// invullen. De versie geldt voor alle sites met die extensie. Een leeg veld
// wist de handmatige waarde weer.

require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: login.php");
    exit;
}
require_once 'config.php';
require_once 'csrf_functies.php';
require_once 'versie_vergelijk_functies.php';
require_once 'feed_terugval_functies.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

vereistGeldigCsrfToken();

$siteId  = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;
$feedUrl = trim((string) ($_POST['feed_url'] ?? ''));
$versie  = trim((string) ($_POST['versie'] ?? ''));

$terug = 'extensies.php?id=' . $siteId;

// Alleen feeds die de monitor al kent (uit een scan van een site), en alleen
// een echt versienummer.
$kentFeedStmt = $pdo->prepare("SELECT COUNT(*) FROM site_alle_extensies WHERE update_feed_url = ?");
$kentFeedStmt->execute([$feedUrl]);
$feedBekend = $feedUrl !== '' && (int) $kentFeedStmt->fetchColumn() > 0;
$versieGeldig = $versie === '' || preg_match('/^[0-9][0-9A-Za-z.\-]{0,30}$/', $versie) === 1;

if (!$feedBekend || !$versieGeldig) {
    header('Location: ' . $terug . '&feed_versie=ongeldig');
    exit;
}

slaHandmatigeFeedVersieOp($pdo, $feedUrl, $versie !== '' ? $versie : null);

header('Location: ' . $terug . '&feed_versie=' . ($versie !== '' ? 'opgeslagen' : 'gewist'));
exit;
