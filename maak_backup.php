<?php
// maak_backup.php
// Genereert op aanvraag een back-up van ofwel alle PHP-bestanden (als zip),
// ofwel alle database-tabellen (als .sql-dump) - voor de zekerheid, zodat
// je zelf een kopie kan bewaren buiten de hostingpartij om.

require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: login.php");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: configuratie.php");
    exit;
}
require_once 'csrf_functies.php';
vereistGeldigCsrfToken();

$type = $_POST['type'] ?? '';

// ============================================================================
// BACKUP VAN ALLE PHP-BESTANDEN (ZIP)
// ============================================================================
if ($type === 'php') {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo "Kan geen zip-bestand maken: de PHP-extensie 'zip' is niet beschikbaar op deze server.";
        exit;
    }

    $tijdelijkPad = tempnam(sys_get_temp_dir(), 'backup') . '.zip';
    $zip = new ZipArchive();
    $zip->open($tijdelijkPad, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $basisMap = __DIR__;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($basisMap, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $bestand) {
        if (!$bestand->isFile()) {
            continue;
        }
        // PHP-, SQL-migratie- en de meegeleverde afbeeldingen (logo/favicon/
        // PWA-iconen in images/) meenemen - geen losse logbestanden of
        // eventuele tijdelijke bestanden die niet bij de broncode horen.
        $extensie = strtolower(pathinfo($bestand->getFilename(), PATHINFO_EXTENSION));
        if (!in_array($extensie, ['php', 'sql', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'md'], true)) {
            continue;
        }

        $relatiefPad = substr($bestand->getPathname(), strlen($basisMap) + 1);
        $zip->addFile($bestand->getPathname(), $relatiefPad);
    }

    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="websites-monitor-php-backup-' . date('Ymd-His') . '.zip"');
    header('Content-Length: ' . filesize($tijdelijkPad));
    readfile($tijdelijkPad);
    unlink($tijdelijkPad);
    exit;
}

// ============================================================================
// BACKUP VAN ALLE DATABASE-TABELLEN (.sql)
// ============================================================================
if ($type === 'database') {
    require_once 'config.php';

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="websites-monitor-database-backup-' . date('Ymd-His') . '.sql"');

    echo "-- Mijn Websites Monitor - database-backup\n";
    echo "-- Gegenereerd: " . date('Y-m-d H:i:s') . "\n\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n";
    echo "SET NAMES utf8mb4;\n\n";

    $tabellen = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tabellen as $tabel) {
        echo "-- --------------------------------------------------------\n";
        echo "-- Structuur voor tabel `$tabel`\n";
        echo "-- --------------------------------------------------------\n";
        echo "DROP TABLE IF EXISTS `$tabel`;\n";

        $createRij = $pdo->query("SHOW CREATE TABLE `$tabel`")->fetch(PDO::FETCH_ASSOC);
        echo $createRij['Create Table'] . ";\n\n";

        echo "-- Gegevens voor tabel `$tabel`\n";

        $rijenStmt = $pdo->query("SELECT * FROM `$tabel`");
        $kolommen = null;

        while ($rij = $rijenStmt->fetch(PDO::FETCH_ASSOC)) {
            if ($kolommen === null) {
                $kolommen = array_keys($rij);
            }

            $waarden = array_map(function ($waarde) use ($pdo) {
                return $waarde === null ? 'NULL' : $pdo->quote($waarde);
            }, array_values($rij));

            echo "INSERT INTO `$tabel` (`" . implode('`, `', $kolommen) . "`) VALUES (" . implode(', ', $waarden) . ");\n";
        }

        echo "\n";
    }

    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
}

header("Location: configuratie.php");
exit;
