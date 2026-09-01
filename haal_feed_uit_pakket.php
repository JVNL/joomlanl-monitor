<?php
// haal_feed_uit_pakket.php
//
// Leest een lokaal Joomla-installatiepakket (.zip) uit dat de gebruiker
// vanaf zijn eigen pc heeft geüpload, op zoek naar de update-feed-URL die
// in het manifest-bestand van de extensie staat (het <updateservers>-blok -
// exact dezelfde soort URL die we ook via Joomla's eigen update-registratie
// binnenhalen). Het pakket wordt tijdelijk in een eigen map op de server
// gezet, direct uitgelezen, en daarna - ongeacht of het lukte - meteen weer
// verwijderd. Er wordt niets van de inhoud zelf bewaard.

require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: login.php");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: extensie_beheer.php");
    exit;
}
require_once 'config.php';
require_once 'csrf_functies.php';
require_once 'versie_vergelijk_functies.php'; // voor maakExtensieSleutel()

vereistGeldigCsrfToken();

header('Content-Type: application/json; charset=utf-8');

if (!class_exists('ZipArchive')) {
    echo json_encode(['succes' => false, 'foutmelding' => "De PHP-extensie 'zip' is niet beschikbaar op deze server."]);
    exit;
}

if (!isset($_FILES['pakket']) || $_FILES['pakket']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['succes' => false, 'foutmelding' => 'Geen geldig bestand ontvangen. Kies een .zip-installatiepakket.']);
    exit;
}

// Eigen tijdelijke map binnen de monitor zelf, puur voor dit ene moment -
// wordt aan het einde van dit script sowieso weer leeggemaakt.
$tmpMap = __DIR__ . '/tmp_pakket_upload';
if (!is_dir($tmpMap)) {
    @mkdir($tmpMap, 0700, true);
}

$tmpBestand = $tmpMap . '/' . uniqid('pakket_', true) . '.zip';

/**
 * Ruimt het tijdelijke bestand altijd op, ongeacht of het verwerken lukte -
 * wordt aan het eind van elk pad door dit script aangeroepen.
 */
function ruimTijdelijkBestandOp(string $pad): void
{
    if (is_file($pad)) {
        @unlink($pad);
    }
}

if (!move_uploaded_file($_FILES['pakket']['tmp_name'], $tmpBestand)) {
    echo json_encode(['succes' => false, 'foutmelding' => 'Kon het geüploade bestand niet opslaan.']);
    exit;
}

$zip = new ZipArchive();
if ($zip->open($tmpBestand) !== true) {
    ruimTijdelijkBestandOp($tmpBestand);
    echo json_encode(['succes' => false, 'foutmelding' => 'Dit is geen geldig zip-bestand.']);
    exit;
}

// Op zoek naar het Joomla-manifestbestand: een .xml-bestand met <extension>
// als hoofdelement. Bij meerdere kandidaten (bijv. een pakket met losse
// sub-extensies) geven we voorrang aan het bestand dat het ondiepst in de
// mappenstructuur zit - dat is vrijwel altijd het manifest van de extensie
// zelf, niet van een onderdeel daarbinnen.
$besteManifest = null;
$besteDiepte = PHP_INT_MAX;

for ($i = 0; $i < $zip->numFiles; $i++) {
    $naam = $zip->getNameIndex($i);
    if (strtolower(pathinfo($naam, PATHINFO_EXTENSION)) !== 'xml') {
        continue;
    }

    $inhoud = $zip->getFromIndex($i);
    if ($inhoud === false || stripos($inhoud, '<extension') === false) {
        continue;
    }

    $diepte = substr_count($naam, '/');
    if ($diepte < $besteDiepte) {
        $besteDiepte = $diepte;
        $besteManifest = ['naam' => $naam, 'inhoud' => $inhoud];
    }
}

if ($besteManifest === null) {
    $zip->close();
    ruimTijdelijkBestandOp($tmpBestand);
    echo json_encode(['succes' => false, 'foutmelding' => 'Geen Joomla-manifestbestand (met <extension>-hoofdelement) gevonden in dit pakket.']);
    exit;
}

libxml_use_internal_errors(true);
$xml = simplexml_load_string($besteManifest['inhoud']);

if ($xml === false) {
    $zip->close();
    ruimTijdelijkBestandOp($tmpBestand);
    echo json_encode(['succes' => false, 'foutmelding' => 'Het manifestbestand kon niet worden gelezen (ongeldige XML).']);
    exit;
}

$type = (string) ($xml['type'] ?? '');

// Element bepalen: voor de meeste typen (component/module/package/library/
// template) komt dit overeen met de bestandsnaam van het manifest zelf
// (zonder .xml) - dat is de gangbare Joomla-pakketconventie. Voor plugins
// staat de echte elementnaam in het <files>-blok, als filename="...".
$bestandsnaamZonderExtensie = strtolower(pathinfo(basename($besteManifest['naam']), PATHINFO_FILENAME));
$element = $bestandsnaamZonderExtensie;

if ($type === 'plugin' && isset($xml->files->filename)) {
    foreach ($xml->files->filename as $bestandRegel) {
        $pluginAttr = (string) ($bestandRegel['plugin'] ?? '');
        if ($pluginAttr !== '') {
            $element = strtolower($pluginAttr);
            break;
        }
    }
}

// Update-feed-URL zoeken in het <updateservers>-blok.
$feedUrl = null;
$naamUitManifest = null;
if (isset($xml->updateservers->server)) {
    foreach ($xml->updateservers->server as $server) {
        $url = trim((string) $server);
        if ($url !== '') {
            $feedUrl = $url;
            break;
        }
    }
}

// Terugval voor "pakket-in-pakket"-structuren: bij een package-type manifest
// zonder eigen <updateservers>-blok staat de daadwerkelijke update-feed-URL
// vaak in het manifest van één van de LOSSE, GENESTE deel-ZIP's die het
// pakket bevat (bijv. <files folder="packages"><file>com_iets.zip</file>
// ...). Zulke geneste ZIP's kunnen niet rechtstreeks uit het geheugen
// geopend worden - ze worden daarom eerst naar een eigen tijdelijk bestand
// weggeschreven, en dan op precies dezelfde manier doorzocht.
$tijdelijkeNestedBestanden = [];
if ($feedUrl === null && $type === 'package' && isset($xml->files->file)) {
    foreach ($xml->files->file as $bestandRegel) {
        $relatiefPad = trim((string) $bestandRegel);
        if ($relatiefPad === '') {
            continue;
        }

        $mapAttr = (string) ($xml->files['folder'] ?? '');
        $volledigPadInZip = $mapAttr !== '' ? rtrim($mapAttr, '/') . '/' . $relatiefPad : $relatiefPad;

        $nestedInhoud = $zip->getFromName($volledigPadInZip);
        if ($nestedInhoud === false) {
            // Pad in het manifest klopt soms niet exact met hoe het zip-
            // bestand het intern heeft opgeslagen - dan proberen we het op
            // de bestandsnaam alleen (zonder mappad).
            $nestedInhoud = $zip->getFromName(basename($relatiefPad));
        }
        if ($nestedInhoud === false || strtolower(pathinfo($relatiefPad, PATHINFO_EXTENSION)) !== 'zip') {
            continue;
        }

        $nestedTmpPad = tempnam(sys_get_temp_dir(), 'nested_');
        file_put_contents($nestedTmpPad, $nestedInhoud);
        $tijdelijkeNestedBestanden[] = $nestedTmpPad;

        $nestedZip = new ZipArchive();
        if ($nestedZip->open($nestedTmpPad) !== true) {
            continue;
        }

        $nestedBesteManifest = null;
        $nestedBesteDiepte = PHP_INT_MAX;
        for ($j = 0; $j < $nestedZip->numFiles; $j++) {
            $nestedNaam = $nestedZip->getNameIndex($j);
            if (strtolower(pathinfo($nestedNaam, PATHINFO_EXTENSION)) !== 'xml') {
                continue;
            }
            $nestedXmlInhoud = $nestedZip->getFromIndex($j);
            if ($nestedXmlInhoud === false || stripos($nestedXmlInhoud, '<extension') === false) {
                continue;
            }
            $nestedDiepte = substr_count($nestedNaam, '/');
            if ($nestedDiepte < $nestedBesteDiepte) {
                $nestedBesteDiepte = $nestedDiepte;
                $nestedBesteManifest = $nestedXmlInhoud;
            }
        }
        $nestedZip->close();

        if ($nestedBesteManifest === null) {
            continue;
        }

        $nestedXml = simplexml_load_string($nestedBesteManifest);
        if ($nestedXml !== false && isset($nestedXml->updateservers->server)) {
            foreach ($nestedXml->updateservers->server as $server) {
                $url = trim((string) $server);
                if ($url !== '') {
                    $feedUrl = $url;
                    break 2; // eerste gevonden update-feed-URL in het pakket is voldoende
                }
            }
        }
    }
}

foreach ($tijdelijkeNestedBestanden as $pad) {
    @unlink($pad);
}

// Nog bredere, laatste terugval: sommige ontwikkelaars (bijv. Community
// Builder's "CB Package Installer") gebruiken een volledig eigen
// installatiesysteem in plaats van Joomla's standaard package-conventie -
// geen <extension type="package"> met een <files folder="packages">-blok,
// maar een eigen mapstructuur (bijv. "extensions/plugins/...") met daarin
// alsnog gewoon losse, geneste ZIP-bestanden. In dat geval doorzoeken we
// het hele pakket op ELKE aanwezige .zip, ongeacht pad of het type van het
// buitenste manifest.
if ($feedUrl === null) {
    $tijdelijkeNestedBestanden2 = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $naam = $zip->getNameIndex($i);
        if (strtolower(pathinfo($naam, PATHINFO_EXTENSION)) !== 'zip') {
            continue;
        }

        $nestedInhoud = $zip->getFromIndex($i);
        if ($nestedInhoud === false) {
            continue;
        }

        $nestedTmpPad = tempnam(sys_get_temp_dir(), 'nested2_');
        file_put_contents($nestedTmpPad, $nestedInhoud);
        $tijdelijkeNestedBestanden2[] = $nestedTmpPad;

        $nestedZip = new ZipArchive();
        if ($nestedZip->open($nestedTmpPad) !== true) {
            continue;
        }

        $nestedBesteManifest = null;
        $nestedBesteManifestNaam = null;
        $nestedBesteDiepte = PHP_INT_MAX;
        for ($j = 0; $j < $nestedZip->numFiles; $j++) {
            $nestedNaam = $nestedZip->getNameIndex($j);
            if (strtolower(pathinfo($nestedNaam, PATHINFO_EXTENSION)) !== 'xml') {
                continue;
            }
            $nestedXmlInhoud = $nestedZip->getFromIndex($j);
            if ($nestedXmlInhoud === false || stripos($nestedXmlInhoud, '<extension') === false) {
                continue;
            }
            $nestedDiepte = substr_count($nestedNaam, '/');
            if ($nestedDiepte < $nestedBesteDiepte) {
                $nestedBesteDiepte = $nestedDiepte;
                $nestedBesteManifest = $nestedXmlInhoud;
                $nestedBesteManifestNaam = $nestedNaam;
            }
        }
        $nestedZip->close();

        if ($nestedBesteManifest === null) {
            continue;
        }

        $nestedXml = simplexml_load_string($nestedBesteManifest);
        if ($nestedXml !== false && isset($nestedXml->updateservers->server)) {
            foreach ($nestedXml->updateservers->server as $server) {
                $url = trim((string) $server);
                if ($url !== '') {
                    $feedUrl = $url;
                    // Het type/element mogen dan ook wel van DIT gevonden
                    // onderdeel komen, niet van het buitenste, niet-relevante
                    // installer-manifest.
                    $type = (string) ($nestedXml['type'] ?? $type);
                    $nestedElement = strtolower(pathinfo(basename($nestedBesteManifestNaam ?? ''), PATHINFO_FILENAME));
                    if ($type === 'plugin' && isset($nestedXml->files->filename)) {
                        foreach ($nestedXml->files->filename as $bestandRegel) {
                            $pluginAttr = (string) ($bestandRegel['plugin'] ?? '');
                            if ($pluginAttr !== '') {
                                $nestedElement = strtolower($pluginAttr);
                                break;
                            }
                        }
                    }
                    $element = $nestedElement !== '' ? $nestedElement : $element;
                    $naamUitManifest = isset($nestedXml->name) ? trim((string) $nestedXml->name) : $naamUitManifest;
                    break 2;
                }
            }
        }
    }

    foreach ($tijdelijkeNestedBestanden2 as $pad) {
        @unlink($pad);
    }
}

$zip->close();
ruimTijdelijkBestandOp($tmpBestand);

if ($feedUrl === null) {
    echo json_encode([
        'succes' => false,
        'foutmelding' => 'Geen update-feed-URL (<updateservers>-blok) gevonden in het manifest van dit pakket. '
            . 'Sommige ontwikkelaars registreren hun update-locatie niet in het pakket zelf.',
    ]);
    exit;
}

$sleutel = ($type !== '' && $element !== '') ? maakExtensieSleutel($type, $element) : null;
$naamUitManifest = $naamUitManifest ?? (isset($xml->name) ? trim((string) $xml->name) : $element);

echo json_encode([
    'succes' => true,
    'feed_url' => $feedUrl,
    'sleutel' => $sleutel,
    'naam' => $naamUitManifest,
    'type' => $type,
    'element' => $element,
]);
