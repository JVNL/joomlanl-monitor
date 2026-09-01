<?php
/**
 * opschoon_catalogus.php
 *
 * Ruimt "verweesde" rijen op in extensie_catalogus: rijen zonder eigen
 * update_feed_url (en nog niet genegeerd) die er ooit automatisch bij
 * kwamen omdat een scan op dat moment, op die ene site, geen automatische
 * nieuwste_versie kon vinden (zie de auto-insert-logica in
 * ontvang_scan.php). Als er inmiddels ELDERS (op een andere site, of
 * dezelfde site na een latere, succesvolle scan) wél een automatische
 * nieuwste_versie bekend is voor dezelfde sleutel, is zo'n rij overbodig
 * geworden - hij wordt dan alsnog genegeerd, precies zoals "Negeren" in
 * Extensies beheren dat handmatig zou doen.
 *
 * Bewust GEEN rijen verwijderen (alleen genegeerd = 1 zetten): zo blijft
 * de rij zichtbaar via "Toon ook genegeerde extensies" en kan iemand 'm
 * altijd terugzetten via "Terugzetten", mocht dat ooit nodig zijn.
 *
 * Gebruik:
 *   php opschoon_catalogus.php            -> alleen een rapport tonen (proefdraai, wijzigt niets)
 *   php opschoon_catalogus.php --toepassen -> daadwerkelijk negeer_nieuw toepassen op de gevonden rijen
 *
 * Uitsluitend bedoeld om vanaf de command line (of eenmalig handmatig via
 * de browser met ?toepassen=1) te draaien - geen CSRF-bescherming nodig,
 * want dit script wijzigt alleen "genegeerd", nooit een feed-URL zelf.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/versie_vergelijk_functies.php';

$toepassen = in_array('--toepassen', $argv ?? [], true)
    || (($_GET['toepassen'] ?? '') === '1');

echo $toepassen
    ? "=== Opschonen van extensie_catalogus (WIJZIGINGEN WORDEN TOEGEPAST) ===\n\n"
    : "=== Proefdraai opschonen extensie_catalogus (er wordt NIETS gewijzigd - gebruik --toepassen om het echt te doen) ===\n\n";

// ------------------------------------------------------------------
// Stap 1: alle sleutels verzamelen waarvoor ELDERS (op minstens één
// site, via Joomla's eigen automatische update-registratie) al een
// bruikbare nieuwste_versie bekend is. Dezelfde sleutel-opbouw als de
// rest van het systeem (maakExtensieSleutel), zodat dit exact aansluit
// bij hoe de catalogus zelf sleutels toekent.
// ------------------------------------------------------------------
$stmt = $pdo->query("
    SELECT DISTINCT type, element
    FROM site_alle_extensies
    WHERE nieuwste_versie IS NOT NULL AND nieuwste_versie != ''
");

$sleutelsMetAutomatischeVersie = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
    $sleutel = maakExtensieSleutel($rij['type'] ?? '', $rij['element'] ?? '');
    if ($sleutel !== '') {
        $sleutelsMetAutomatischeVersie[$sleutel] = true;
    }
}

echo 'Aantal unieke sleutels met elders een automatische versie: ' . count($sleutelsMetAutomatischeVersie) . "\n\n";

// ------------------------------------------------------------------
// Stap 2: catalogus-rijen zonder eigen feed-URL en nog niet genegeerd -
// dit zijn de kandidaten. Alleen degene waarvan de sleutel ook in de
// zojuist opgebouwde lijst voorkomt, worden overbodig geacht.
// ------------------------------------------------------------------
$catalogusStmt = $pdo->query("
    SELECT sleutel, label
    FROM extensie_catalogus
    WHERE (update_feed_url IS NULL OR update_feed_url = '')
      AND genegeerd = 0
    ORDER BY sleutel ASC
");

$teNegeren = [];
$blijvenStaan = [];
foreach ($catalogusStmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
    if (isset($sleutelsMetAutomatischeVersie[$rij['sleutel']])) {
        $teNegeren[] = $rij;
    } else {
        $blijvenStaan[] = $rij;
    }
}

echo 'Rijen zonder feed-URL, nog niet genegeerd: ' . (count($teNegeren) + count($blijvenStaan)) . "\n";
echo '  - waarvan overbodig (elders al automatisch bekend), kandidaat om te negeren: ' . count($teNegeren) . "\n";
echo '  - waarvan blijven staan (nergens automatisch bekend, dus terecht "zonder feed"): ' . count($blijvenStaan) . "\n\n";

if (empty($teNegeren)) {
    echo "Niets te doen.\n";
    exit;
}

echo "Kandidaten om te negeren:\n";
foreach ($teNegeren as $rij) {
    echo '  - ' . $rij['sleutel'] . ' (' . $rij['label'] . ")\n";
}
echo "\n";

if (!$toepassen) {
    echo "Dit was een proefdraai - er is niets aangepast.\n";
    echo "Draai met --toepassen (CLI) of ?toepassen=1 (browser) om deze " . count($teNegeren) . " rij(en) daadwerkelijk te negeren.\n";
    exit;
}

$negeerStmt = $pdo->prepare("UPDATE extensie_catalogus SET genegeerd = 1 WHERE sleutel = ?");
$aantalGenegeerd = 0;
foreach ($teNegeren as $rij) {
    $negeerStmt->execute([$rij['sleutel']]);
    $aantalGenegeerd += $negeerStmt->rowCount();
}

echo "Klaar: $aantalGenegeerd rij(en) genegeerd.\n";
echo "Vergeet niet om de catalogus opnieuw naar Github te pushen (Extensies beheren) als je die daar deelt, anders komen deze rijen bij een volgende import weer terug als 'gewijzigd'.\n";