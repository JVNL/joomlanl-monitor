<?php
// favicon_tags.php
//
// Favicon- en "installeren op beginscherm"-tags, in te laden op elke
// pagina met een PHP include-aanroep ergens in de head-sectie (net als
// responsive_stijlen.php en terug_naar_boven.php).
//
// Zorgt voor: het favicon in het browsertabblad, het icoon dat gebruikt
// wordt als iemand deze pagina op een telefoon/tablet "toevoegt aan
// beginscherm" of "installeert" (zowel iOS/Safari als Android/Chrome
// hebben hier hun eigen, iets afwijkende tags voor nodig), en de koppeling
// naar het PWA-manifest.
//
// Is er een eigen logo geüpload (zie Configuratie), dan worden hier de
// daarvan gegenereerde favicon-varianten getoond in plaats van de vaste
// standaardbestanden - zie genereerFaviconsVanLogo() in
// instellingen_functies.php.
require_once __DIR__ . '/instellingen_functies.php';
$faviconPaden = huidigeFaviconPaden(haalAlleInstellingen($pdo));

// Zelfde cache-buster-redenering als in manifest.php: zonder dit blijft de
// URL van een favicon/apple-touch-icon exact hetzelfde nadat het logo is
// vervangen (de bestandsnaam is afgeleid van de ORIGINELE logo-bestandsnaam,
// niet van de inhoud), waardoor een browser het bestand nooit opnieuw zou
// ophalen.
foreach ($faviconPaden as $sleutel => $pad) {
    $volledigPad = __DIR__ . '/' . $pad;
    $laatsteWijziging = is_file($volledigPad) ? @filemtime($volledigPad) : false;
    if ($laatsteWijziging !== false) {
        $faviconPaden[$sleutel] = $pad . '?v=' . $laatsteWijziging;
    }
}
?>
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo htmlspecialchars($faviconPaden['32']); ?>">
<link rel="icon" type="image/png" sizes="16x16" href="<?php echo htmlspecialchars($faviconPaden['16']); ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?php echo htmlspecialchars($faviconPaden['apple']); ?>">
<link rel="manifest" href="manifest.php">
<meta name="theme-color" content="#1f6fa8">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Websites Monitor">
