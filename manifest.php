<?php
// manifest.php
//
// Web-app-manifest voor "installeren op beginscherm" (Android/Chrome) en
// als bron voor de PWA-iconen. Dynamisch i.p.v. een vast manifest.json,
// zodat de naam meebeweegt met de instelling "Naam van de monitor" op de
// configuratiepagina (dezelfde instelling die ook de titel linksboven en
// de e-mailafzendernaam bepaalt).

require_once 'sessie_start.php';
require_once 'config.php';
require_once 'instellingen_functies.php';

$programmaNaam = trim(haalInstelling($pdo, 'email_afzendernaam', '')) ?: 'Mijn Websites Monitor';
$pwaIconen = huidigePwaIconenPaden(haalAlleInstellingen($pdo));

// Cache-buster op basis van de daadwerkelijke laatste-wijzigingstijd van
// elk icoonbestand: zonder dit blijft de URL van een icoon exact hetzelfde
// (dezelfde bestandsnaam, want die is afgeleid van de ORIGINELE
// logo-bestandsnaam, niet van de inhoud) ook nadat het logo is vervangen -
// en dus heeft een browser/besturingssysteem geen enkele reden om het
// icoon van een al geïnstalleerde snelkoppeling opnieuw op te halen. Met
// deze toevoeging verandert de URL wél zodra het bestand verandert, wat
// vooral op Android/Chrome (dat het manifest van tijd tot tijd opnieuw
// controleert bij een bezoek aan de site) de kans aanzienlijk vergroot dat
// een nieuw logo ook echt wordt opgepikt. Op iOS/Safari helpt dit niet -
// daar wordt een eenmaal toegevoegde snelkoppeling in de praktijk nooit
// meer gecontroleerd op een gewijzigd manifest; daar is verwijderen en
// opnieuw toevoegen het enige zekere middel.
foreach ($pwaIconen as $sleutel => $pad) {
    $volledigPad = __DIR__ . '/' . $pad;
    $laatsteWijziging = is_file($volledigPad) ? @filemtime($volledigPad) : false;
    if ($laatsteWijziging !== false) {
        $pwaIconen[$sleutel] = $pad . '?v=' . $laatsteWijziging;
    }
}

header('Content-Type: application/manifest+json; charset=utf-8');

echo json_encode([
    'name' => $programmaNaam,
    'short_name' => $programmaNaam,
    'description' => 'Overzicht en beveiligingsmonitor voor Joomla-websites',
    'start_url' => 'index.php',
    'display' => 'standalone',
    'background_color' => '#f5f5f5',
    'theme_color' => '#1f6fa8',
    'icons' => [
        [
            'src' => $pwaIconen['192'],
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any maskable',
        ],
        [
            'src' => $pwaIconen['512'],
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable',
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
