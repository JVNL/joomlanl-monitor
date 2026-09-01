<?php
// vergelijk_kern_bestanden.php
//
// Vergelijkt Joomla-kernbestanden van alle gemonitorde sites met het
// OFFICIËLE, ongewijzigde Joomla-pakket van downloads.joomla.org - als
// aanvulling op vergelijk_extensie_bestanden.php (dat sites onderling met
// elkaar vergelijkt, en dus niets kan zeggen over een kernversie die maar
// op 1 site voorkomt, of over een bestand dat toevallig op alle sites
// identiek gecompromitteerd is).
//
// Bewust een LOS bestand, niet samengevoegd met
// vergelijk_extensie_bestanden.php: een downloadfout bij het ophalen van
// het officiële pakket (bijv. downloads.joomla.org tijdelijk onbereikbaar)
// mag nooit de bestaande, volledig lokale meerderheidsvergelijking kunnen
// laten mislukken.
//
// Wordt, net als vergelijk_extensie_bestanden.php, als laatste stap ná
// haal_versies_op.php aangeroepen - pas als alle sites hun actuele
// kernbestand-hashes hebben ingestuurd, is deze vergelijking zinvol.

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Kan bij de eerste keer per Joomla-kernversie een download van enkele
// tientallen MB doen - ruimer tijdsbudget dan de reguliere vergelijking,
// die volledig lokaal blijft.
@set_time_limit(180);
@ini_set('memory_limit', '512M');

require_once 'sessie_start.php';
require_once 'config.php';
require_once 'endpoint_beveiliging.php';
require_once 'kern_integriteit_functies.php';

header('Content-Type: text/plain; charset=utf-8');

$resultaat = vergelijkKernBestandenMetOfficieel($pdo);

echo "OK: {$resultaat['vergeleken_groepen']} Joomla-kernversie(s) vergeleken met het officiële pakket, "
    . "{$resultaat['afwijkingen']} afwijking(en) gevonden.";

if (!empty($resultaat['download_fouten'])) {
    echo "\n\nWaarschuwing - kon niet voor elke kernversie het officiële pakket ophalen:";
    foreach ($resultaat['download_fouten'] as $fout) {
        echo "\n  - $fout";
    }
    echo "\n(Deze versies zijn overgeslagen; overige vergelijkingen zijn gewoon uitgevoerd.)";
}
