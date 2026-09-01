<?php
// vergelijk_extensie_bestanden.php
//
// Vergelijkt, over ALLE gemonitorde sites heen, de bestandshashes van
// extensies die op meerdere sites voorkomen (zelfde type/element/versie).
// Voor elk bestand wordt bepaald welke hash-waarde bij de MEERDERHEID van
// de sites voorkomt ("de meerderheidsversie") - elke site die daarvan
// afwijkt, wordt vastgelegd als mogelijk verdacht. Dit werkt volledig
// zonder externe downloads: het enige wat nodig is, is dat dezelfde
// extensie+versie op minimaal 2 sites bij jou voorkomt.
//
// Sinds 1.18 BEWUST exclusief Joomla's eigen kernbestanden (groep_sleutel
// kern_joomla_*) - die hebben een eigen, preciezere vergelijking tegen het
// officiële Joomla-pakket (zie kern_integriteit_functies.php /
// vergelijk_kern_bestanden.php).
//
// Wordt aangeroepen als laatste stap ná haal_versies_op.php, zowel vanuit
// de monitorpagina (knop "Scan en check sites") als vanuit de cronjob -
// pas als alle sites hun actuele hashes hebben ingestuurd, is deze
// vergelijking zinvol.

error_reporting(E_ALL);
ini_set('display_errors', 1);
// Haalt de bestandshashes van ALLE sites in één keer volledig op (zie de
// query hieronder) - bij een groeiend aantal sites met elk duizenden
// bestanden per extensie kan dit al snel honderdduizenden rijen worden,
// wat de standaard tijds-/geheugenlimiet van de hostingpartij kan
// benaderen. Zonder deze verruiming leidt dat tot een kale HTTP 500 in
// plaats van een nette afronding.
@set_time_limit(180);
@ini_set('memory_limit', '512M');

require_once 'sessie_start.php';
require_once 'config.php';
require_once 'endpoint_beveiliging.php';

header('Content-Type: text/plain; charset=utf-8');

// Alle hashes ophalen, gegroepeerd op extensie+versie én bestandspad.
// BEWUST exclusief de kern_joomla_*-groepen: die worden sinds 1.18 preciezer
// gedekt door de aparte vergelijking tegen het officiële Joomla-pakket (zie
// kern_integriteit_functies.php / vergelijk_kern_bestanden.php) - zonder
// deze uitsluiting zou hetzelfde kernbestand door twee onafhankelijke
// vergelijkingen apart gemeld kunnen worden (mét, verwarrend genoeg, elk
// hun eigen "vertrouwen"-mechanisme dat niets van elkaar weet).
$rijen = $pdo->query("
    SELECT site_id, groep_sleutel, relatief_pad, hash
    FROM extensie_bestand_hashes
    WHERE groep_sleutel NOT LIKE 'kern_joomla\_%' ESCAPE '\\\\'
    ORDER BY groep_sleutel, relatief_pad
")->fetchAll(PDO::FETCH_ASSOC);

$perBestand = [];
foreach ($rijen as $rij) {
    $sleutel = $rij['groep_sleutel'] . '|' . $rij['relatief_pad'];
    $perBestand[$sleutel][] = $rij;
}

// Resultaat volledig opnieuw opbouwen - dit is altijd een verse
// momentopname, geen historie.
$pdo->exec("TRUNCATE TABLE extensie_bestand_afwijkingen");

$invoegStmt = $pdo->prepare("
    INSERT INTO extensie_bestand_afwijkingen
        (site_id, groep_sleutel, relatief_pad, eigen_hash, meerderheid_hash, aantal_sites_meerderheid, aantal_sites_totaal)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");

$aantalVergeleken = 0;
$aantalAfwijkingen = 0;
$aantalGroepenMetAfwijking = [];

foreach ($perBestand as $entries) {
    // Vergelijken heeft alleen zin als hetzelfde bestand (zelfde extensie +
    // versie) op minimaal 2 sites is aangetroffen.
    if (count($entries) < 2) {
        continue;
    }
    $aantalVergeleken++;

    $telling = [];
    foreach ($entries as $entry) {
        $telling[$entry['hash']] = ($telling[$entry['hash']] ?? 0) + 1;
    }

    // Zijn alle hashes gelijk? Dan is er niets te melden voor dit bestand.
    if (count($telling) === 1) {
        continue;
    }

    arsort($telling);
    $meerderheidHash = array_key_first($telling);
    $aantalMeerderheid = $telling[$meerderheidHash];
    $totaal = count($entries);

    foreach ($entries as $entry) {
        if ($entry['hash'] === $meerderheidHash) {
            continue;
        }

        $invoegStmt->execute([
            $entry['site_id'],
            $entry['groep_sleutel'],
            $entry['relatief_pad'],
            $entry['hash'],
            $meerderheidHash,
            $aantalMeerderheid,
            $totaal,
        ]);

        $aantalAfwijkingen++;
        $aantalGroepenMetAfwijking[$entry['groep_sleutel']] = true;
    }
}

echo date('Y-m-d H:i:s') . " - vergelijking klaar.\n";
echo "Bestanden vergeleken (op minimaal 2 sites aanwezig): $aantalVergeleken\n";
echo "Afwijkende bestanden gevonden: $aantalAfwijkingen (in " . count($aantalGroepenMetAfwijking) . " extensie(s))\n";
