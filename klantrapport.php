<?php
// klantrapport.php
//
// Klantvriendelijk beveiligingsrapport voor één site - een overzichtelijke
// HTML-pagina, bedoeld om via de eigen "Afdrukken -> Opslaan als PDF"-
// functie van de browser (Ctrl+P) als PDF-bestand te bewaren en door te
// sturen naar de eigenaar van de website.
//
// BEWUST GEEN losse PDF-bibliotheek (zie de eerdere, teruggedraaide opzet
// met FPDF in genereer_pdf_rapport.php/lib/fpdf/): dit is een gewone
// HTML-pagina, precies zoals alle andere pagina's in deze monitor die al
// probleemloos werken - geen aparte serverconfiguratie, geen binaire
// download-headers, geen mogelijke wringpunten daar. Wat de browser zelf
// al kan (een pagina nette afdrukken/als PDF opslaan), hoeven we niet zelf
// opnieuw te bouwen.
//
// Bewust een APARTE pagina, geen vervanging van het interactieve
// beveiligingsrapport (beveiliging.php): heel ander doel (extern, statisch
// document) en heel andere doelgroep (de site-eigenaar zelf).

require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: login.php");
    exit;
}
// Zelfde reden als bij extensies.php/index.php: dit rapport toont per
// definitie actuele scangegevens en mag dus nooit door een browser/cache-
// laag bewaard en later hergebruikt worden.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once 'config.php';
require_once 'verdacht_functies.php';
require_once 'versie_vergelijk_functies.php';
require_once 'instellingen_functies.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ?");
$stmt->execute([$id]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$site) {
    http_response_code(404);
    die("Site niet gevonden.");
}

$domein = $site['domein'];

// ----------------------------------------------------------------------
// Gegevens verzamelen - zelfde bronnen/logica als de eerdere FPDF-opzet.
// ----------------------------------------------------------------------

$alleItems       = parseVerdachtDetails($site['verdacht_details'] ?? '');
$vertrouwdHashes = haalVertrouwdeHashes($pdo, $id);

$teRapporterenItems = array_values(array_filter(
    $alleItems,
    fn($item) => !isset($vertrouwdHashes[$item['hash']])
));

$bedreigingen     = [];
$bestandenRechten = [];
$mappenRechten    = [];
$onbekendeItems   = [];

foreach ($teRapporterenItems as $item) {
    $reden = $item['reden'];

    if (stripos($reden, 'Afwijkende rechten') === 0) {
        if ($item['type'] === 'map') {
            $mappenRechten[] = $item;
        } else {
            $bestandenRechten[] = $item;
        }
    } elseif (stripos($reden, 'onbekend') === 0 || stripos($reden, 'geneste, complete Joomla-installatie') === 0 || stripos($reden, 'andere, volledig losstaande Joomla-installatie') === 0 || stripos($reden, 'de accountroot zelf') === 0) {
        $onbekendeItems[] = $item;
    } else {
        $bedreigingen[] = $item;
    }
}

$sorteerOpRisico = fn($a, $b) => $b['risico'] <=> $a['risico'];
usort($bedreigingen, $sorteerOpRisico);
usort($bestandenRechten, $sorteerOpRisico);
usort($mappenRechten, $sorteerOpRisico);
usort($onbekendeItems, $sorteerOpRisico);

$alleExtensies = haalDerdePartijExtensies($pdo, $id);
$verouderdeExtensies = array_values(array_filter($alleExtensies, fn($e) => $e['status'] === false));
$onbekendeExtensies  = array_values(array_filter($alleExtensies, fn($e) => $e['status'] === null));

$nieuwsteVersies = haalNieuwsteVersies($pdo);
$joomlaHuidig = $site['joomla_versie'] ?? null;
$joomlaMajor  = bepaalMajorVersie($joomlaHuidig);
$joomlaNieuwsteBinnenMajor = $joomlaMajor !== null ? ($nieuwsteVersies['joomla_' . $joomlaMajor] ?? null) : null;
$joomlaStatus = ($joomlaHuidig !== null && $joomlaHuidig !== '') ? isUpToDate($joomlaHuidig, $joomlaNieuwsteBinnenMajor) : null;

$programmaNaam = trim(haalInstelling($pdo, 'email_afzendernaam', '')) ?: 'Mijn Websites Monitor';
$laatsteScan = $site['verdacht_laatste_scan'] ? date('d-m-Y H:i', strtotime($site['verdacht_laatste_scan'])) : 'nog niet gescand';

$aantalBedreigingen        = count($bedreigingen);
$aantalRechtenBestanden    = count($bestandenRechten);
$aantalRechtenMappen       = count($mappenRechten);
$aantalOnbekend            = count($onbekendeItems);
$aantalVerouderdeExtensies = count($verouderdeExtensies);
$totaalProblemen = $aantalBedreigingen + $aantalRechtenBestanden + $aantalRechtenMappen + $aantalOnbekend + $aantalVerouderdeExtensies + ($joomlaStatus === false ? 1 : 0);

function h($tekst)
{
    return htmlspecialchars((string) $tekst, ENT_QUOTES, 'UTF-8');
}

function risicoKleur(int $risico): string
{
    if ($risico >= 90) return '#7b241c';
    if ($risico >= 70) return '#c0392b';
    if ($risico >= 40) return '#e67e22';
    return '#7f8c8d';
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<script>
// Voorkeur voor licht/donker zo vroeg mogelijk toepassen (vóór de rest van
// de pagina rendert), zodat er geen flits van het verkeerde thema is -
// zelfde mechanisme als op alle andere pagina's van deze monitor.
(function () {
    var voorkeur = localStorage.getItem('thema_voorkeur');
    if (voorkeur === 'licht' || voorkeur === 'donker') {
        document.documentElement.setAttribute('data-thema', voorkeur);
    }
})();
</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Beveiligingsrapport - <?php echo h($domein); ?></title>
<?php include 'responsive_stijlen.php'; ?>
<style>
    body {
        font-family: Arial, Helvetica, sans-serif;
        max-width: 800px;
        margin: 0 auto;
        padding: 30px 20px 60px 20px;
        line-height: 1.5;
    }
    h1 { font-size: 22px; margin-bottom: 2px; }
    .subtitel { color: var(--thema-uitleg-tekst); margin-bottom: 20px; }
    h2 {
        font-size: 16px;
        margin-top: 30px;
        padding-bottom: 6px;
        border-bottom: 1px solid var(--thema-rand);
    }
    .samenvatting-regel { display: flex; align-items: center; gap: 8px; margin: 4px 0; }
    .bolletje-rood { color: var(--thema-genegeerd-tekst); }
    .bolletje-groen { color: var(--thema-groen); }
    .item-blok { margin: 10px 0; padding-left: 4px; border-left: 3px solid var(--thema-rand); padding: 6px 0 6px 10px; }
    .item-risico { font-weight: bold; display: inline-block; min-width: 90px; }
    .item-naam { font-weight: bold; }
    .item-reden { color: var(--thema-uitleg-tekst); font-size: 13px; margin-left: 2px; }
    .uitleg-alinea { color: var(--thema-uitleg-tekst); font-size: 13.5px; margin-bottom: 10px; }
    .extensie-regel { margin: 6px 0; }
    .extensie-naam { font-weight: bold; display: inline-block; min-width: 200px; }
    .alles-schoon { color: var(--thema-groen); font-weight: bold; font-size: 15px; margin-top: 10px; }
    footer {
        margin-top: 40px;
        padding-top: 10px;
        border-top: 1px solid var(--thema-rand);
        color: var(--thema-uitleg-tekst);
        font-size: 11px;
    }
    header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 10px;
        position: sticky;
        top: 0;
        background: var(--thema-bg);
        padding: 10px 0;
        margin-bottom: 10px;
        border-bottom: 2px solid var(--thema-tekst);
    }
    header h1 { margin: 0 0 2px 0; }
    .knoppenbalk { display: flex; align-items: center; gap: 8px; }
    .knop {
        display: inline-block;
        padding: 8px 14px;
        background: #333;
        color: white;
        text-decoration: none;
        border-radius: 4px;
        border: none;
        font-size: 13px;
        cursor: pointer;
    }
    .knop-print {
        background: var(--thema-groen);
        color: #fff;
        border: none;
        padding: 10px 18px;
        border-radius: 4px;
        font-size: 14px;
        cursor: pointer;
    }
    /* In donkere modus leest zwarte tekst beter op de (dan iets fellere)
       groene knopachtergrond dan de standaard witte tekst. */
    html[data-thema="donker"] .knop-print {
        color: #000;
    }
    @media (prefers-color-scheme: dark) {
        html:not([data-thema="licht"]) .knop-print {
            color: #000;
        }
    }

    .print-hint {
        background: var(--thema-badge-bg);
        color: var(--thema-tekst);
        border: 1px solid var(--thema-rand);
        border-radius: 4px;
        padding: 8px 12px;
        font-size: 13px;
        margin-bottom: 15px;
    }

    /* Bij afdrukken/PDF-opslaan bewust ALTIJD een licht, "papieren" document
       - een donker document is onprettig/duur om af te drukken en oogt op
       een gedeeld PDF-bestand niet als een "officieel" rapport. Daarom hier
       alle thema-variabelen expliciet terugzetten naar de lichte waarden,
       ongeacht wat er op het scherm gekozen was. */
    @media print {
        :root {
            --thema-bg: #ffffff !important;
            --thema-tekst: #222222 !important;
            --thema-rand: #dddddd !important;
            --thema-uitleg-tekst: #666666 !important;
            --thema-groen: #1e7e34 !important;
            --thema-genegeerd-tekst: #a83232 !important;
        }
        header {
            position: static;
            border-bottom: 1px solid #dddddd;
            padding: 0 0 10px 0;
            background: none;
        }
        .knoppenbalk { display: none; }
        .print-hint { display: none; }
        body { padding: 0; max-width: none; }
        h2 { page-break-after: avoid; }
        .item-blok { page-break-inside: avoid; }
    }
</style>
</head>
<body>

<header>
    <div>
        <h1>Beveiligingsrapport</h1>
        <div class="subtitel"><?php echo h($domein); ?> &middot; laatste scan: <?php echo h($laatsteScan); ?></div>
    </div>
    <div class="knoppenbalk">
        <button class="knop-print" onclick="window.print()">🖨️ Opslaan als PDF / afdrukken</button>
        <button type="button" class="knop" id="thema-knop" onclick="wisselThema()" title="Licht/donker wisselen"><span class="icoon-glyph">🌙</span></button>
        <a class="knop" href="index.php?categorie=<?php echo htmlspecialchars($site['categorie'] ?? 'eigen'); ?>">Terug naar monitor</a>
    </div>
</header>
<div class="print-hint">
    💡 Zie je hierna het printvoorbeeld van je browser? Klik daar op <strong>"Annuleren"</strong> om terug te keren naar deze pagina (niet op het kruisje van het browservenster - dat sluit de hele browser).
</div>
<script>
document.addEventListener('DOMContentLoaded', werkThemaKnopBij);
</script>

<h2>Samenvatting</h2>
<?php
$samenvattingRegels = [
    [$aantalBedreigingen, 'mogelijke bedreiging(en) (bijv. verdachte code, gemanipuleerde bestanden)'],
    [$aantalRechtenBestanden, 'bestand(en) met afwijkende bestandsrechten'],
    [$aantalRechtenMappen, 'map(pen) met afwijkende maprechten'],
    [$aantalOnbekend, 'onbekend(e) bestand(en)/map(pen) die een controle waard zijn'],
    [$aantalVerouderdeExtensies, 'extensie(s) die niet up-to-date zijn'],
];
foreach ($samenvattingRegels as [$aantal, $omschrijving]):
?>
<div class="samenvatting-regel">
    <span class="<?php echo $aantal > 0 ? 'bolletje-rood' : 'bolletje-groen'; ?>"><?php echo $aantal > 0 ? '●' : '○'; ?></span>
    <span><?php echo (int) $aantal; ?> <?php echo h($omschrijving); ?></span>
</div>
<?php endforeach; ?>

<?php if ($joomlaHuidig !== null && $joomlaHuidig !== ''): ?>
<div class="samenvatting-regel">
    <?php if ($joomlaStatus === false): ?>
        <span class="bolletje-rood">●</span>
        <span>Joomla-kernversie <?php echo h($joomlaHuidig); ?> is niet de nieuwste (nieuwste binnen dezelfde hoofdversie: <?php echo h($joomlaNieuwsteBinnenMajor); ?>)</span>
    <?php elseif ($joomlaStatus === true): ?>
        <span class="bolletje-groen">○</span>
        <span>Joomla-kernversie <?php echo h($joomlaHuidig); ?> is up-to-date</span>
    <?php else: ?>
        <span style="width: 14px; display: inline-block;"></span>
        <span>Joomla-kernversie: <?php echo h($joomlaHuidig); ?> (nieuwste versie niet automatisch te bepalen)</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($totaalProblemen === 0): ?>
<div class="alles-schoon">Er zijn bij de laatste scan geen aandachtspunten gevonden.</div>
<?php endif; ?>

<?php
function toonItems(array $items): void
{
    foreach ($items as $item) {
        [$risicoLabelTekst] = risicoLabel($item['risico']);
        $kleur = risicoKleur($item['risico']);
        $regel = h($item['reden']);
        if ($item['gewijzigd'] !== '-') {
            $regel .= ' (gewijzigd: ' . h($item['gewijzigd']) . ')';
        }
        echo '<div class="item-blok" style="border-left-color: ' . $kleur . ';">';
        echo '<span class="item-risico" style="color: ' . $kleur . ';">' . h($risicoLabelTekst . ' - ' . $item['risico']) . '</span>';
        echo '<span class="item-naam">' . h($item['naam']) . '</span><br>';
        echo '<span class="item-reden">' . $regel . '</span>';
        echo '</div>';
    }
}
?>

<?php if (!empty($bedreigingen)): ?>
<h2 style="color: #7b241c;">Mogelijke bedreigingen</h2>
<div class="uitleg-alinea">Deze bestanden/mappen vertonen kenmerken van kwetsbare of gemanipuleerde code. Controleer ze zo snel mogelijk handmatig - bij twijfel: verwijderen of veiligstellen buiten de website-root, en het bijbehorende wachtwoord/de configuratie preventief wijzigen als het om een configuratiebestand gaat.</div>
<?php toonItems($bedreigingen); ?>
<?php endif; ?>

<?php if (!empty($bestandenRechten)): ?>
<h2 style="color: #c0392b;">Bestanden met afwijkende rechten</h2>
<div class="uitleg-alinea">De gebruikelijke, veilige waarde voor een los bestand is 644 (leesbaar voor iedereen, alleen schrijfbaar voor de eigenaar). Een afwijkende waarde is niet per definitie gevaarlijk, maar wel de moeite van het narekenen waard - vraag bij twijfel de hostingpartij om het te laten herstellen.</div>
<?php toonItems($bestandenRechten); ?>
<?php endif; ?>

<?php if (!empty($mappenRechten)): ?>
<h2 style="color: #c0392b;">Mappen met afwijkende rechten</h2>
<div class="uitleg-alinea">De gebruikelijke, veilige waarde voor een map is 755 (doorzoekbaar/leesbaar voor iedereen, alleen schrijfbaar voor de eigenaar).</div>
<?php toonItems($mappenRechten); ?>
<?php endif; ?>

<?php if (!empty($onbekendeItems)): ?>
<h2 style="color: #e67e22;">Onbekende bestanden en mappen</h2>
<div class="uitleg-alinea">Deze bestanden/mappen zijn niet automatisch te herkennen als standaard onderdeel van de website. Vaak onschuldig (een eigen back-upmap, een test-/staging-versie), maar het is verstandig om zelf te controleren of je ze herkent.</div>
<?php toonItems($onbekendeItems); ?>
<?php endif; ?>

<?php if (!empty($verouderdeExtensies) || !empty($onbekendeExtensies)): ?>
<h2 style="color: #29628a;">Extensies</h2>
<?php if (!empty($verouderdeExtensies)): ?>
<div class="uitleg-alinea">De volgende extensies zijn niet up-to-date. Verouderde extensies zijn een veelvoorkomende ingang voor aanvallers - werk ze bij via de Joomla-beheerder ("Systeem" -&gt; "Bijwerken" -&gt; "Extensies").</div>
<?php foreach ($verouderdeExtensies as $extensie): ?>
<div class="extensie-regel">
    <span class="extensie-naam"><?php echo h($extensie['naam']); ?></span>
    <span style="color: #c0392b;">huidig: <?php echo h($extensie['versie'] ?: 'onbekend'); ?> &rarr; nieuwste bekende versie: <?php echo h($extensie['nieuwste_versie'] ?: 'onbekend'); ?></span>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php if (!empty($onbekendeExtensies)): ?>
<div class="uitleg-alinea" style="margin-top: 14px;">Van de volgende extensies kon niet automatisch worden vastgesteld of er een nieuwere versie is - controleer deze zelf handmatig.</div>
<?php foreach ($onbekendeExtensies as $extensie): ?>
<div class="extensie-regel">
    <span class="extensie-naam"><?php echo h($extensie['naam']); ?></span>
    <span style="color: #888;">huidige versie: <?php echo h($extensie['versie'] ?: 'onbekend'); ?></span>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>

<footer>Gegenereerd door <?php echo h($programmaNaam); ?> op <?php echo date('d-m-Y H:i'); ?></footer>

</body>
</html>
