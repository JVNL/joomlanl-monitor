<?php
// toon_changelog.php
//
// Toont CHANGELOG.md met een expliciete UTF-8-headerinstructie. Nodig omdat
// een browser die het .md-bestand rechtstreeks opent, zelf moet gokken
// welke tekencodering het is als de server dat niet meestuurt - en dat gokt
// bij tekens als é/ë/ë vaak verkeerd (levert dan Ã©/Ã« op).

require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: login.php");
    exit;
}

$pad = __DIR__ . '/CHANGELOG.md';

header('Content-Type: text/plain; charset=utf-8');

if (!is_readable($pad)) {
    echo "CHANGELOG.md niet gevonden.";
    exit;
}

readfile($pad);
