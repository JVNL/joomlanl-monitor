<?php
// TIJDELIJK SCRIPT - upload naar de map van monitor.compactweb.nl, open
// eenmalig in de browser, en verwijder daarna weer van de server.
//
// Zet in één keer, betrouwbaar, ALLE momenteel genegeerde extensies in
// extensie_catalogus terug op genegeerd = 0 - ongeacht hoe ze precies op
// genegeerd zijn gekomen. Bedoeld als schone startsituatie: alles staat
// weer actief, en je kunt vandaar bewust opnieuw bepalen wat je écht wil
// negeren (bijv. via "Extensietabel beheren").
//
// Voert één enkele UPDATE-query uit (geen aparte requests per rij, dus
// geen kans op een deel-mislukking zoals bij losse formulierklikken).

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== VOOR de wijziging ===\n\n";
$stmt = $pdo->query("SELECT sleutel, label FROM extensie_catalogus WHERE genegeerd = 1 ORDER BY sleutel ASC");
$genegeerdVoor = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Aantal genegeerde extensies: " . count($genegeerdVoor) . "\n\n";
foreach ($genegeerdVoor as $rij) {
    echo "- {$rij['sleutel']} ({$rij['label']})\n";
}

if (empty($genegeerdVoor)) {
    echo "\nEr staat momenteel niets op genegeerd - niets te doen.\n";
    exit;
}

echo "\n" . str_repeat('=', 80) . "\n\n";
echo "=== Wijziging uitvoeren ===\n\n";

try {
    $stmt = $pdo->prepare("UPDATE extensie_catalogus SET genegeerd = 0, genegeerd_op = NULL WHERE genegeerd = 1");
    $stmt->execute();
} catch (PDOException $e) {
    // De kolom genegeerd_op bestaat mogelijk nog niet (migratie loopt pas
    // bij het eerstvolgende bezoek aan een gewone pagina van de monitor,
    // via voerAutoMigratieUit() in config.php) - dan gewoon zonder die
    // kolom updaten, geen reden om dit script te laten mislukken.
    $stmt = $pdo->prepare("UPDATE extensie_catalogus SET genegeerd = 0 WHERE genegeerd = 1");
    $stmt->execute();
}
$aantalGewijzigd = $stmt->rowCount();

echo "$aantalGewijzigd rij(en) van genegeerd=1 naar genegeerd=0 gezet.\n";

echo "\n" . str_repeat('=', 80) . "\n\n";
echo "=== NA de wijziging (controle) ===\n\n";

$nogGenegeerd = (int) $pdo->query("SELECT COUNT(*) FROM extensie_catalogus WHERE genegeerd = 1")->fetchColumn();
$totaal = (int) $pdo->query("SELECT COUNT(*) FROM extensie_catalogus")->fetchColumn();

echo "Totaal aantal rijen in extensie_catalogus: $totaal\n";
echo "Waarvan nu nog genegeerd = 1: $nogGenegeerd\n";

if ($nogGenegeerd === 0) {
    echo "\n✅ Klaar - niets staat meer op genegeerd.\n";
} else {
    echo "\n⚠️  Er staan nog steeds $nogGenegeerd rij(en) op genegeerd - dat zou hier niet moeten kunnen.\n";
    echo "Rijen die nog genegeerd zijn:\n";
    $stmt = $pdo->query("SELECT sleutel, label FROM extensie_catalogus WHERE genegeerd = 1 ORDER BY sleutel ASC");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
        echo "- {$rij['sleutel']} ({$rij['label']})\n";
    }
}

echo "\nVerwijder dit bestand nu van de server.\n";
echo "Ga daarna naar \"Extensietabel beheren\" om desgewenst bewust opnieuw dingen te negeren.\n";
