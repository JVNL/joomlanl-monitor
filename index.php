<?php
require_once 'sessie_start.php';

// Zie login.php voor de toelichting - zonder deze controle zou het openen
// van deze pagina vóór het doorlopen van de installatiewizard alsnog op
// een 500-fout uitlopen (via de latere require_once 'config.php' verderop
// in dit bestand), in plaats van netjes door te sturen naar installeer.php.
if (!file_exists(__DIR__ . '/config.php')) {
    if (file_exists(__DIR__ . '/installeer.php')) {
        header('Location: installeer.php');
        exit;
    }
    http_response_code(500);
    die('config.php ontbreekt, en installeer.php is niet gevonden - upload eerst alle bestanden van de monitor (inclusief installeer.php) naar deze map.');
}

if (!isset($_SESSION['ingelogd'])) {
    header("Location: login.php");
    exit;
}

// Deze pagina bevat eenmalige meldingen (bijv. "site toegevoegd") die
// gekoppeld zijn aan query-parameters in de URL - die parameters worden na
// het tonen direct via JavaScript (history.replaceState) uit de adresbalk
// verwijderd, zodat een verversing van het scherm de melding niet opnieuw
// laat zien. Zonder onderstaande headers zou een browser (of een eventuele
// proxy/CDN ertussen) bij "F5" soms alsnog een eerder gecachete versie van
// deze pagina kunnen tonen - inclusief de oude melding - in plaats van een
// verse versie op te halen. Deze headers dwingen af dat dat nooit gebeurt.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'versie.php';
require_once 'csrf_functies.php';
require_once 'verdacht_functies.php';
require_once 'versie_vergelijk_functies.php';
require_once 'instellingen_functies.php';

$programmaNaam = trim(haalInstelling($pdo, 'email_afzendernaam', '')) ?: 'Mijn Websites Monitor';

// Welke categorie wordt getoond - standaard "eigen", schakelt om via de
// knoppen onder de titel. Bewust een GET-parameter (geen sessie-opgeslagen
// voorkeur): zo is een link naar "alleen websites van anderen" ook los te
// delen/bookmarken, en blijft elk tabblad onafhankelijk instelbaar.
$categorie = ($_GET['categorie'] ?? 'eigen') === 'anderen' ? 'anderen' : 'eigen';

$sql = "SELECT * FROM sites WHERE categorie = ? ORDER BY domein ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$categorie]);
$sites = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Aantallen voor de omschakelknoppen zelf (los van de huidig getoonde
// categorie) - zodat je op de knop al ziet hoeveel sites in de andere
// categorie zitten, ook vóór het omschakelen.
$aantalPerCategorie = $pdo->query("SELECT categorie, COUNT(*) AS aantal FROM sites GROUP BY categorie")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$aantalEigen    = (int) ($aantalPerCategorie['eigen'] ?? 0);
$aantalAnderen  = (int) ($aantalPerCategorie['anderen'] ?? 0);

$totaal = count($sites);

// Sortering: standaard alfabetisch op domein, maar op verzoek (via de
// klikbare kolomkoppen) op de "ernst" van de Joomla-, extensie- of
// beveiligingsstatus, zodat de sites die de meeste aandacht nodig hebben
// bovenaan komen.
$sorteerOp = $_GET['sorteer'] ?? 'domein';
if (!in_array($sorteerOp, ['domein', 'joomla', 'extensies', 'beveiliging'], true)) {
    $sorteerOp = 'domein';
}

// Sorteerrichting: "normaal" is per kolom de standaardvolgorde hierboven
// (bijv. bij Domein alfabetisch A-Z, bij de andere drie meeste aandacht
// eerst); "omgekeerd" draait die exacte volgorde volledig om, inclusief de
// gelijke-stand-tiebreaks. Bewust GEEN los "ASC"/"DESC" per kolom (dat zou
// bij elke kolom een andere betekenis hebben, verwarrend) - dit is puur
// "wat je nu ziet, maar dan achterstevoren", ongeacht welke kolom actief is.
$richting = ($_GET['richting'] ?? 'normaal') === 'omgekeerd' ? 'omgekeerd' : 'normaal';

/**
 * Bouwt de klikbare kolomkop-link voor een sorteerbare kolom, inclusief
 * pijltje en de omkeerlogica: bij een klik op een NIET-actieve kolom start
 * je altijd in de normale richting voor die kolom (ongeacht de richting die
 * je toevallig had op de kolom waar je nu op kijkt); bij een klik op de AL
 * actieve kolom draait de richting om.
 */
function sorteerKopLink(string $kolom, string $label, string $pijlNormaal, string $title, string $categorie, string $sorteerOp, string $richting): string
{
    $actief = $sorteerOp === $kolom;
    $nieuweRichting = ($actief && $richting === 'normaal') ? 'omgekeerd' : 'normaal';
    $pijl = ($actief && $richting === 'omgekeerd')
        ? ($pijlNormaal === '▲' ? '▼' : '▲')
        : $pijlNormaal;
    $klasse = 'kop-link' . ($actief ? ' actief' : '');
    $url = '?categorie=' . urlencode($categorie) . '&amp;sorteer=' . urlencode($kolom) . '&amp;richting=' . urlencode($nieuweRichting);

    return '<a href="' . $url . '" class="' . $klasse . '" title="' . htmlspecialchars($title) . '">'
        . htmlspecialchars($label) . ' ' . $pijl . '</a>';
}

$alleVertrouwdeHashes = haalAlleVertrouwdeHashes($pdo);
$nieuwsteVersies = haalNieuwsteVersies($pdo);
$alleDerdePartijSamenvatting = haalAlleDerdePartijSamenvatting($pdo);

// Aantal kernbestand-afwijkingen (vs. het officiële Joomla-pakket) per site
// - los van $verdacht_aantal, want dat gaat over de reguliere backdoor-/
// bestandsscan. Alleen als lookup-array opgehaald, niet als losse query per
// site in de weergavelus hieronder.
$kernAfwijkingenPerSite = $pdo->query("
        SELECT k.site_id, COUNT(*) AS aantal
        FROM kern_bestand_afwijkingen k
        LEFT JOIN kern_vertrouwd v
            ON v.site_id = k.site_id AND v.relatief_pad = k.relatief_pad AND v.hash = k.eigen_hash
        WHERE v.id IS NULL
        GROUP BY k.site_id
    ")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$kernVertrouwdPerSite = $pdo->query("SELECT site_id, COUNT(*) AS aantal FROM kern_vertrouwd GROUP BY site_id")
    ->fetchAll(PDO::FETCH_KEY_PAIR);

// Aantal extensie-bestand-afwijkingen (vergeleken met andere sites, zie
// vergelijk_extensie_bestanden.php) per site - net als bij de
// kernbestand-afwijkingen hierboven, als losse lookup-array opgehaald.
// Vertrouwde afwijkingen (extensie_bestand_vertrouwd) tellen hier niet mee,
// exact dezelfde LEFT JOIN-opzet als bij kern_vertrouwd hierboven.
$bestandAfwijkingenPerSite = $pdo->query("
        SELECT b.site_id, COUNT(*) AS aantal
        FROM extensie_bestand_afwijkingen b
        LEFT JOIN extensie_bestand_vertrouwd v
            ON v.site_id = b.site_id AND v.relatief_pad = b.relatief_pad AND v.hash = b.eigen_hash
        WHERE v.id IS NULL
        GROUP BY b.site_id
    ")
    ->fetchAll(PDO::FETCH_KEY_PAIR);

$laatsteVerdachtScan = null;
foreach ($sites as $site) {
    if (!empty($site['verdacht_laatste_scan'])) {
        if ($laatsteVerdachtScan === null || $site['verdacht_laatste_scan'] > $laatsteVerdachtScan) {
            $laatsteVerdachtScan = $site['verdacht_laatste_scan'];
        }
    }
}
$laatsteVerdachtScanTekst = $laatsteVerdachtScan ? date('d-m-Y H:i', strtotime($laatsteVerdachtScan)) : 'Nog niet gescand';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<script>
// Voorkeur voor licht/donker zo vroeg mogelijk toepassen (vóór de rest van
// de pagina rendert), zodat er geen flits van het verkeerde thema is.
(function () {
    var voorkeur = localStorage.getItem('thema_voorkeur');
    if (voorkeur === 'licht' || voorkeur === 'donker') {
        document.documentElement.setAttribute('data-thema', voorkeur);
    }
})();
</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include 'favicon_tags.php'; ?>
<title><?php echo htmlspecialchars($programmaNaam); ?></title>
<style>

body {
    font-family: Arial, sans-serif;
    margin: 10px;
    font-size: 13px;
}

table {
    border-collapse: collapse;
    width: 100%;
    table-layout: fixed;
}

th {
    background: #333;
    color: white;
    padding: 6px 4px;
    text-align: left;
    font-size: 12px;
    word-wrap: break-word;
}

.kop-link {
    color: white;
    text-decoration: none;
}

.kop-link:hover {
    text-decoration: underline;
}

.kop-link.actief {
    color: #f4c542;
    font-weight: bold;
}

td {
    border-left: 1px solid #ddd;
    border-right: 1px solid #ddd;
    border-top: 1px solid #ddd;
    border-bottom: 2px solid #bbb;
    padding: 5px 4px;
    word-wrap: break-word;
    overflow-wrap: break-word;
    font-size: 12px;
    vertical-align: top;
}

.groen { color: var(--thema-groen); font-weight: bold; }
.oranje { color: orange; font-weight: bold; }
.rood { color: red; font-weight: bold; }
.grijs { color: #888; font-weight: bold; }
.vertrouwd-link { color: var(--thema-link); font-weight: bold; text-decoration: underline; }

.overzicht {
    margin-bottom: 20px;
    padding: 15px;
    border: 1px solid #ddd;
    background: #f5f5f5;
    display: flex;
    gap: 25px;
    flex-wrap: wrap;
}

.overzicht-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 110px;
}

.overzicht-item strong {
    font-size: 12px;
    font-weight: bold;
    color: var(--thema-uitleg-tekst);
}

.overzicht-item span {
    font-size: 18px;
    font-weight: bold;
}

.acties {
    display: flex;
    gap: 10px;
}

.knop-tekst-compact {
    display: none;
}

.knop {
    display: inline-block;
    padding: 6px 12px;
    background: #333;
    color: white;
    text-decoration: none;
    border-radius: 4px;
    font-size: 13px;
}

.knop:hover:not(:disabled) {
    background: #555;
}

.knop:disabled {
    background: #999;
    cursor: not-allowed;
}

.rij-acties {
    display: flex;
    gap: 6px;
    align-items: center;
    padding-right: 10px;
}

.tabel-scroll-kader {
    /* Laat de tabel zijwaarts scrollen binnen dit kader als de inhoud
       (met name de actieknoppen helemaal rechts) niet volledig past in het
       browservenster - zo valt er nooit meer een knop stilletjes buiten
       beeld zonder dat er een manier is om erbij te komen. */
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.rij-spinner {
    display: inline-block;
    font-size: 16px;
    animation: rij-spinner-draaien 1.2s linear infinite;
}

@keyframes rij-spinner-draaien {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.rij-acties form {
    margin: 0;
    display: inline;
}

.knop-icoon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    background: #000;
    color: #fff;
    border: none;
    border-radius: 4px;
    text-decoration: none;
    font-size: 15px;
    cursor: pointer;
    line-height: 1;
    box-shadow: 0 0 0 1px rgba(0,0,0,0.15);
}

.icoon-tekst-badge {
    display: inline-block;
    font-size: 8.5px;
    font-weight: bold;
    letter-spacing: 0.3px;
    color: #fff;
    border: 1.4px solid #fff;
    border-radius: 3px;
    padding: 1px 3px;
    line-height: 1.1;
}

.knop-icoon:hover {
    background: #333;
}

.knop-icoon:disabled {
    background: #999;
    cursor: not-allowed;
}

.knop-ververs-icoon {
    color: #fff;
    font-size: 18px;
    font-weight: bold;
}

.knop-verwijder-icoon {
    /* Erft de zwarte achtergrond gewoon van .knop-icoon - geen rood meer. */
}

#melding {
    display: none;
    margin-top: 8px;
    padding: 8px 12px;
    border-radius: 4px;
    font-size: 13px;
}

#melding.ok   { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
#melding.fout { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

#voortgang-buiten {
    display: none;
    margin-top: 8px;
    height: 22px;
    background: #e2e6ea;
    border-radius: 4px;
    overflow: hidden;
}

#voortgang-binnen {
    height: 100%;
    width: 0%;
    background: #1f6fa8;
    color: white;
    font-size: 11px;
    font-weight: bold;
    line-height: 22px;
    text-align: center;
    white-space: nowrap;
    transition: width 0.4s ease, background-color 0.4s ease;
}

#voortgang-binnen.klaar {
    background: #2e8b3d;
}

#voortgang-binnen.fout {
    background: #c0392b;
}

header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.titel {
    display: flex;
    align-items: center;
    gap: 12px;
}

.laatste-scan {
    display: inline-block;
    padding: 6px 12px;
    background: #eef1f4;
    border: 1px solid #ccd3da;
    border-radius: 4px;
    font-size: 12px;
    color: #333;
}

.categorie-schakelaar {
    display: flex;
    gap: 8px;
    margin-bottom: 15px;
}
.categorie-tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 6px;
    text-decoration: none;
    color: var(--thema-tekst);
    background: var(--thema-badge-bg);
    border: 2px solid transparent;
    font-size: 14px;
}
.categorie-tab.actief {
    border-color: var(--thema-groen);
    font-weight: bold;
}
.categorie-aantal {
    display: inline-block;
    min-width: 20px;
    padding: 1px 6px;
    border-radius: 10px;
    background: var(--thema-rand);
    font-size: 12px;
    text-align: center;
}

</style>
<?php include 'responsive_stijlen.php'; ?>
</head>
<body>

<header>
    <div class="titel">
        <img src="<?php echo htmlspecialchars(huidigLogoPad(['logo_bestandsnaam' => haalInstelling($pdo, 'logo_bestandsnaam', '')])); ?>" alt="<?php echo htmlspecialchars($programmaNaam); ?>" style="width: 48px; height: 48px; flex-shrink: 0;">
        <h1><?php echo htmlspecialchars($programmaNaam); ?></h1>
        <div class="laatste-scan">Laatste scan uitgevoerd op: <?php echo htmlspecialchars($laatsteVerdachtScanTekst); ?></div>
    </div>
    <div class="acties">
        <button class="knop" id="scan-check-knop" onclick="scanEnCheckSites(this)"><span class="knop-tekst-volledig"><span class="icoon-glyph">🛡️🔍</span> Scan en check sites</span><span class="knop-tekst-compact"><span class="icoon-glyph">🛡️🔍</span></span></button>
        <a class="knop" href="configuratie.php" title="Configuratie"><span class="icoon-glyph">⚙️</span></a>
        <a class="knop" href="site_toevoegen.php" title="Nieuwe site toevoegen"><span class="icoon-glyph">➕</span></a>
        <a class="knop" href="help.php" title="Help"><span class="icoon-glyph">❓</span></a>
        <button type="button" class="knop" id="thema-knop" onclick="wisselThema()" title="Licht/donker wisselen"><span class="icoon-glyph">🌙</span></button>
        <a class="knop" href="logout.php" title="Uitloggen">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle;">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
        </a>
    </div>
</header>

<div class="categorie-schakelaar">
    <a href="?categorie=eigen&amp;sorteer=<?php echo urlencode($sorteerOp); ?>&amp;richting=<?php echo urlencode($richting); ?>" class="categorie-tab<?php echo $categorie === 'eigen' ? ' actief' : ''; ?>">
        🏠 Eigen websites <span class="categorie-aantal"><?php echo $aantalEigen; ?></span>
    </a>
    <a href="?categorie=anderen&amp;sorteer=<?php echo urlencode($sorteerOp); ?>&amp;richting=<?php echo urlencode($richting); ?>" class="categorie-tab<?php echo $categorie === 'anderen' ? ' actief' : ''; ?>">
        👤 Websites van anderen <span class="categorie-aantal"><?php echo $aantalAnderen; ?></span>
    </a>
</div>

<div id="melding"></div>
<div id="scan-waarschuwingen" style="display: none; margin-top: 8px; padding: 10px 14px; border-radius: 4px; background: #fff3cd; color: #665200; border: 1px solid #ffe69c; font-size: 13px; white-space: pre-line;"></div>
<div id="voortgang-buiten">
    <div id="voortgang-binnen">0%</div>
</div>

<?php if (isset($_GET['toegevoegd'])): ?>
<div style="margin-bottom: 15px; padding: 10px 14px; border-radius: 4px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; font-size: 13px;">
    ✅ Site "<?php echo htmlspecialchars($_GET['toegevoegd']); ?>" is toegevoegd.
    <?php if (isset($_GET['ftp_verstuurd'])): ?>
        Het scanscript is meteen automatisch via FTP/SFTP verstuurd. Druk op "Scan en check sites" om 'm meteen te laten controleren.
    <?php elseif (!isset($_GET['ftp_fout'])): ?>
        Druk op "Scan en check sites" om 'm meteen te laten controleren.
    <?php endif; ?>
</div>
<?php if (isset($_GET['ftp_fout'])): ?>
<div style="margin-bottom: 15px; padding: 14px 18px; border-radius: 4px; background: #fff3cd; color: #665200; border: 2px solid #e6a817; font-size: 14px;">
    <strong>⚠️ Let op: het scanscript kon niet automatisch via FTP/SFTP worden verstuurd.</strong><br><br>
    <?php echo htmlspecialchars($_GET['ftp_fout']); ?><br><br>
    De ingevulde FTP-/SFTP-gegevens zijn <strong>wel gewoon opgeslagen</strong> bij deze site, voor een volgende
    poging. Krijg je hier vaker een mislukking bij dezelfde site of dezelfde hostingpartij, dan blokkeert de
    hostingpartij mogelijk uitgaand FTP-/SFTP-verkeer (dit komt steeds vaker voor als beveiligingsmaatregel).
    Zie het hoofdstuk hierover op de <a href="help.php#scanscript-zonder-ftp">helppagina</a> voor uitleg en
    alternatieven - het scanscript kan zich, eenmaal geplaatst, namelijk ook zelf bijwerken zonder FTP.
    Download het bestand voorlopig gewoon handmatig en zet het zelf via je eigen FileZilla op de site (dat werkt
    altijd, want dat gebeurt vanaf jouw eigen computer, niet vanaf deze server).
</div>
<?php endif; ?>
<?php endif; ?>

<?php if (isset($_GET['verwijderd'])): ?>
<div style="margin-bottom: 15px; padding: 10px 14px; border-radius: 4px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; font-size: 13px;">
    🗑️ Site "<?php echo htmlspecialchars($_GET['verwijderd']); ?>" en alle bijbehorende gescande gegevens zijn verwijderd.
    <?php
    $scanbestandStatus = $_GET['scanbestand_status'] ?? '';
    switch ($scanbestandStatus) {
        case 'gelukt':
            echo ' Het scanscript-bestand is ook van de website zelf verwijderd.';
            break;
        case 'deels_mislukt':
            echo ' ⚠️ Let op: niet alle scanscript-bestanden konden van de website zelf worden verwijderd (bijv. door bestandsrechten) - controleer dit zo nodig zelf via FTP.';
            break;
        case 'verbinding_mislukt':
            echo ' ⚠️ Let op: er kon geen FTP-/SFTP-verbinding worden gemaakt met de website, dus het scanscript-bestand staat daar mogelijk nog - verwijder dit zo nodig zelf via FTP.';
            break;
        case 'geen_ftp':
            echo ' Er waren geen FTP-/SFTP-gegevens bekend voor deze site, dus het scanscript-bestand kon niet automatisch worden verwijderd - staat dit nog op de website, verwijder het dan zelf via FTP.';
            break;
    }
    ?>
</div>
<?php endif; ?>

<script>
const WACHTTIJD_SECONDEN = 20;
const HUIDIGE_CATEGORIE = <?php echo json_encode($categorie); ?>;

function ftpWachtwoordKopieren(knop, evt) {
    const wachtwoord = knop.dataset.wachtwoord;
    const gebruikersnaam = knop.dataset.gebruikersnaam;

    if (gebruikersnaam) {
        // De gebruikersnaam zelf bevat ook een teken dat niet betrouwbaar in
        // de link kon worden meegegeven, en staat er dus HELEMAAL niet in -
        // FileZilla blijkt in dat geval niet netjes om een gebruikersnaam te
        // vragen, maar gewoon een (altijd mislukkende) anonieme inlogpoging
        // te doen. De link daarom hier voorkomen, en in plaats daarvan
        // duidelijk maken dat FileZilla handmatig met deze gegevens moet
        // worden geopend (bijv. via het Quickconnect-balkje).
        if (evt) {
            evt.preventDefault();
        }
        prompt('Deze site heeft een gebruikersnaam met een teken dat niet automatisch werkt. Kopieer hieronder (Ctrl+C), open FileZilla zelf, en log handmatig in met deze gebruikersnaam en het wachtwoord (dat is al naar het klembord gekopieerd):', gebruikersnaam);
        if (navigator.clipboard) {
            navigator.clipboard.writeText(wachtwoord).catch(() => {
                prompt('Kon het wachtwoord niet automatisch kopiëren - selecteer en kopieer het hieronder handmatig:', wachtwoord);
            });
        } else {
            prompt('Kopiëren via de knop is hier niet mogelijk - selecteer en kopieer het wachtwoord hieronder handmatig:', wachtwoord);
        }
        return;
    }

    if (navigator.clipboard) {
        navigator.clipboard.writeText(wachtwoord).catch(() => {
            prompt('Kon het wachtwoord niet automatisch kopiëren - selecteer en kopieer het hieronder handmatig:', wachtwoord);
        });
    } else {
        prompt('Kopiëren via de knop is hier niet mogelijk - selecteer en kopieer het wachtwoord hieronder handmatig:', wachtwoord);
    }
    // Geen preventDefault() in dit pad: de link bevat de (veilige)
    // gebruikersnaam gewoon wel, alleen het wachtwoord ontbreekt - FileZilla
    // vraagt daar in de praktijk wél netjes naar (dat wachtwoord staat
    // hierboven al op het klembord).
}

function zetVoortgang(percentage, klaar = false, fout = false) {
    const balkBuiten = document.getElementById('voortgang-buiten');
    const balkBinnen = document.getElementById('voortgang-binnen');

    balkBuiten.style.display = 'block';
    balkBinnen.style.width = percentage + '%';
    balkBinnen.textContent = Math.round(percentage) + '%';
    balkBinnen.classList.toggle('klaar', klaar);
    balkBinnen.classList.toggle('fout', fout);
}

const ENKELE_SITE_WACHTTIJD_SECONDEN = 10;

function toonSpinner(siteId) {
    const spinner = document.getElementById('spinner-' + siteId);
    if (spinner) {
        spinner.style.display = 'inline-block';
    }
}

function verbergSpinner(siteId) {
    const spinner = document.getElementById('spinner-' + siteId);
    if (spinner) {
        spinner.style.display = 'none';
    }
}

function toonAlleSpinners() {
    document.querySelectorAll('.rij-spinner').forEach(s => s.style.display = 'inline-block');
}

function verbergAlleSpinners() {
    document.querySelectorAll('.rij-spinner').forEach(s => s.style.display = 'none');
}

function scanEnkeleSite(siteId, knop) {
    const alleKnoppenIcoon = document.querySelectorAll('.knop-icoon, button.knop');
    alleKnoppenIcoon.forEach(k => k.disabled = true);
    toonSpinner(siteId);

    const melding = document.getElementById('melding');
    melding.className = '';
    melding.style.display = 'block';
    melding.textContent = '⏳ Scan wordt gestart voor deze website...';
    document.getElementById('scan-waarschuwingen').style.display = 'none';
    zetVoortgang(5);

    fetch('start_scan.php?site_id=' + encodeURIComponent(siteId))
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(tekst => {
            if (tekst.includes('⚠️')) {
                melding.className = '';
                melding.style.background = '#fff3cd';
                melding.style.color = '#665200';
                melding.textContent = tekst.replace(/^[^:]+:\s*/, '');
                zetVoortgang(100, false, true);
                alleKnoppenIcoon.forEach(k => k.disabled = false);
                verbergSpinner(siteId);
                return;
            }

            let secondenOver = ENKELE_SITE_WACHTTIJD_SECONDEN;

            melding.textContent = '✅ Scan gestart. Even wachten... (' + secondenOver + ')';
            zetVoortgang(25);

            const teller = setInterval(() => {
                secondenOver--;

                if (secondenOver > 0) {
                    melding.textContent = '✅ Scan gestart. Even wachten... (' + secondenOver + ')';
                } else {
                    clearInterval(teller);
                    melding.textContent = '⏳ Website- en SSL-status controleren...';
                    zetVoortgang(55);

                    fetch('check_sites.php?site_id=' + encodeURIComponent(siteId))
                        .then(r => {
                            if (!r.ok) throw new Error('HTTP ' + r.status);
                            return r.text();
                        })
                        .then(() => {
                            melding.textContent = '⏳ Joomla- en extensieversies ophalen...';
                            zetVoortgang(80);

                            return fetch('haal_versies_op.php?site_id=' + encodeURIComponent(siteId));
                        })
                        .then(r => {
                            if (!r.ok) throw new Error('HTTP ' + r.status);
                            return r.text();
                        })
                        .then(() => {
                            melding.className = 'ok';
                            melding.textContent = '✅ Deze website is opnieuw gescand — pagina wordt herladen...';
                            zetVoortgang(100, true);
                            verbergSpinner(siteId);
                            setTimeout(() => location.reload(), 1200);
                        })
                        .catch(err => {
                            melding.className = 'fout';
                            melding.textContent = '❌ Er ging iets mis tijdens het controleren: ' + err.message;
                            zetVoortgang(100, false, true);
                            verbergSpinner(siteId);
                            alleKnoppenIcoon.forEach(k => k.disabled = false);
                        });
                }
            }, 1000);
        })
        .catch(err => {
            melding.className = 'fout';
            melding.textContent = '❌ Er ging iets mis bij het starten van de scan: ' + err.message;
            zetVoortgang(100, false, true);
            verbergSpinner(siteId);
            alleKnoppenIcoon.forEach(k => k.disabled = false);
        });
}

function scanEnCheckSites(knop) {
    const alleKnoppen = document.querySelectorAll('button.knop');
    alleKnoppen.forEach(k => k.disabled = true);
    toonAlleSpinners();

    const melding = document.getElementById('melding');
    melding.className = '';
    melding.style.display = 'block';
    melding.textContent = '⏳ Scan wordt gestart op alle websites...';
    document.getElementById('scan-waarschuwingen').style.display = 'none';
    zetVoortgang(2);

    fetch('start_scan.php?categorie=' + encodeURIComponent(HUIDIGE_CATEGORIE))
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(tekst => {
            const waarschuwingsregels = tekst.split('\n').filter(regel => regel.includes('⚠️'));
            if (waarschuwingsregels.length > 0) {
                const waarschuwingenDiv = document.getElementById('scan-waarschuwingen');
                waarschuwingenDiv.style.display = 'block';
                waarschuwingenDiv.textContent = '⚠️ Bij ' + waarschuwingsregels.length + ' website(s) kwam het scanverzoek zelf al niet goed aan:\n\n'
                    + waarschuwingsregels.join('\n') + '\n\nDe overige sites worden gewoon verder gecontroleerd.';
            }

            zetVoortgang(10);

            let secondenOver = WACHTTIJD_SECONDEN;
            const percentageBijStartWachten = 10;
            const percentageBijEindeWachten = 55;

            melding.className = '';
            melding.textContent = '✅ Scan gestart op alle websites. Even wachten tot ze klaar zijn... (' + secondenOver + ')';

            const teller = setInterval(() => {
                secondenOver--;

                const voortgang = percentageBijStartWachten
                    + (percentageBijEindeWachten - percentageBijStartWachten)
                    * (WACHTTIJD_SECONDEN - secondenOver) / WACHTTIJD_SECONDEN;
                zetVoortgang(voortgang);

                if (secondenOver > 0) {
                    melding.textContent = '✅ Scan gestart op alle websites. Even wachten tot ze klaar zijn... (' + secondenOver + ')';
                } else {
                    clearInterval(teller);
                    melding.textContent = '⏳ Website- en SSL-status controleren...';
                    zetVoortgang(percentageBijEindeWachten);

                    fetch('check_sites.php?categorie=' + encodeURIComponent(HUIDIGE_CATEGORIE))
                        .then(r => {
                            if (!r.ok) throw new Error('HTTP ' + r.status);
                            return r.text();
                        })
                        .then(() => {
                            melding.textContent = '⏳ Joomla- en extensieversies ophalen...';
                            zetVoortgang(75);

                            return fetch('haal_versies_op.php');
                        })
                        .then(r => {
                            if (!r.ok) throw new Error('HTTP ' + r.status);
                            return r.text();
                        })
                        .then(() => {
                            melding.textContent = '⏳ Extensiebestanden tussen sites vergelijken...';
                            zetVoortgang(85);

                            return fetch('vergelijk_extensie_bestanden.php');
                        })
                        .then(r => {
                            if (!r.ok) throw new Error('HTTP ' + r.status);
                            return r.text();
                        })
                        .then(() => {
                            melding.textContent = '⏳ Notificatie-e-mail controleren...';
                            zetVoortgang(92);

                            return fetch('verstuur_notificatie_email.php');
                        })
                        .then(r => {
                            if (!r.ok) throw new Error('HTTP ' + r.status);
                            return r.text();
                        })
                        .then(() => {
                            melding.className = 'ok';
                            melding.textContent = '✅ Scan en check sites klaar — pagina wordt herladen...';
                            zetVoortgang(100, true);
                            verbergAlleSpinners();
                            setTimeout(() => location.reload(), 1500);
                        })
                        .catch(err => {
                            melding.className = 'fout';
                            melding.textContent = '❌ Er ging iets mis tijdens het controleren: ' + err.message;
                            zetVoortgang(100, false, true);
                            verbergAlleSpinners();
                            alleKnoppen.forEach(k => k.disabled = false);
                        });
                }
            }, 1000);
        })
        .catch(err => {
            melding.className = 'fout';
            melding.textContent = '❌ Er ging iets mis bij het starten van de scan: ' + err.message;
            zetVoortgang(100, false, true);
            verbergAlleSpinners();
            alleKnoppen.forEach(k => k.disabled = false);
        });
}

// Eenmalige meldingen (bijv. "site toegevoegd", "FTP verstuurd") komen uit
// de URL (?toegevoegd=...&ftp_verstuurd=1, enz.) - zonder opschoning zouden
// die bij elke volgende herlading van de pagina (F5, of het automatische
// herladen na een scan) opnieuw verschijnen, omdat de query-parameters dan
// nog steeds in de adresbalk staan. We vegen ze daarom direct na het tonen
// weg uit de adresbalk (zonder de pagina zelf opnieuw te laden).
(function () {
    const eenmaligeParams = ['toegevoegd', 'verwijderd', 'scanbestand_status', 'ftp_verstuurd', 'ftp_fout'];
    const url = new URL(window.location.href);
    let ietsOpgeschoond = false;

    eenmaligeParams.forEach(param => {
        if (url.searchParams.has(param)) {
            url.searchParams.delete(param);
            ietsOpgeschoond = true;
        }
    });

    if (ietsOpgeschoond) {
        window.history.replaceState({}, '', url.pathname + (url.search ? url.search : ''));
    }
})();

</script>

<?php
// Samenvattende tellingen voor de overzichtsbalk bovenaan de pagina - een
// compacte, losse doorloop van alle sites (los van de hoofdlus verderop,
// die per site ook de volledige celtekst opbouwt) om alvast te weten
// hoeveel sites schoon zijn, en hoeveel aandacht nodig hebben voor
// extensies resp. beveiliging. Bewust dezelfde criteria als de hoofdlus:
// alleen bevestigde, rode meldingen tellen mee als "aandacht nodig" - een
// site die nog nooit gescand is, of waarvan de status "onbekend" is,
// wordt in geen van de drie tellingen meegenomen (die is immers niet
// bevestigd schoon, maar ook niet bevestigd problematisch).
$telSchoon = 0;
$telExtensiesAandacht = 0;
$telBeveiligingAandacht = 0;

foreach ($sites as $siteVoorTelling) {
    $isGescand = $siteVoorTelling['verdacht_aantal'] !== null;
    if (!$isGescand) {
        continue;
    }

    $itemsVoorTelling = parseVerdachtDetails($siteVoorTelling['verdacht_details'] ?? '');
    $siteVertrouwdVoorTelling = $alleVertrouwdeHashes[$siteVoorTelling['id']] ?? [];
    $vertrouwdAantalVoorTelling = 0;
    foreach ($itemsVoorTelling as $itemVoorTelling) {
        if (isset($siteVertrouwdVoorTelling[$itemVoorTelling['hash']])) {
            $vertrouwdAantalVoorTelling++;
        }
    }
    $issueBeveiliging = (count($itemsVoorTelling) - $vertrouwdAantalVoorTelling) > 0
        || (int) ($kernAfwijkingenPerSite[$siteVoorTelling['id']] ?? 0) > 0
        || (int) ($bestandAfwijkingenPerSite[$siteVoorTelling['id']] ?? 0) > 0;

    // Extensies: uitsluitend gebaseerd op de volledige scan (site_alle_extensies
    // via $alleDerdePartijSamenvatting) - het oudere, aparte mechanisme
    // (site_extensies/bepaalExtensieStatussen, gevoed door haal_versies_op.php)
    // bleek soms een geïnstalleerde versie te kennen zonder ooit een nieuwste
    // versie te hebben kunnen achterhalen (bijv. bij een site die zelf geen
    // manifest_pad-gebaseerde controle nodig had), wat tot een vals "Deels
    // onbekend" kon leiden terwijl de volledige scan het antwoord allang wist.
    $derdePartijVoorTelling = $alleDerdePartijSamenvatting[$siteVoorTelling['id']] ?? ['totaal' => 0, 'niet_up_to_date' => 0, 'onbekend' => 0, 'up_to_date' => 0];
    $issueExtensies = $derdePartijVoorTelling['niet_up_to_date'] > 0;

    if ($issueBeveiliging) {
        $telBeveiligingAandacht++;
    }
    if ($issueExtensies) {
        $telExtensiesAandacht++;
    }
    if (!$issueBeveiliging && !$issueExtensies) {
        $telSchoon++;
    }
}
?>

<div class="overzicht">
    <div class="overzicht-item">
        <strong>Totaal sites</strong>
        <span><?php echo $totaal; ?></span>
    </div>
    <div class="overzicht-item">
        <strong>Schoon</strong>
        <span class="groen">🟢 <?php echo $telSchoon; ?></span>
    </div>
    <div class="overzicht-item">
        <strong>Aandacht nodig - extensies</strong>
        <span class="<?php echo $telExtensiesAandacht > 0 ? 'rood' : 'groen'; ?>"><?php echo $telExtensiesAandacht > 0 ? '🔴' : '🟢'; ?> <?php echo $telExtensiesAandacht; ?></span>
    </div>
    <div class="overzicht-item">
        <strong>Aandacht nodig - beveiliging</strong>
        <span class="<?php echo $telBeveiligingAandacht > 0 ? 'rood' : 'groen'; ?>"><?php echo $telBeveiligingAandacht > 0 ? '🔴' : '🟢'; ?> <?php echo $telBeveiligingAandacht; ?></span>
    </div>
</div>

<div class="mobiel-sorteren" style="display: flex; gap: 8px; align-items: flex-end;">
    <div style="flex: 1;">
        <label for="mobiel-sorteer-select" style="font-weight: bold; display: block; margin-bottom: 4px;">Sorteren op:</label>
        <select id="mobiel-sorteer-select" onchange="window.location.href = '?categorie=<?php echo urlencode($categorie); ?>&sorteer=' + this.value + '&richting=normaal';" style="width: 100%; padding: 8px;">
            <option value="domein" <?php echo $sorteerOp === 'domein' ? 'selected' : ''; ?>>Domein (alfabetisch)</option>
            <option value="joomla" <?php echo $sorteerOp === 'joomla' ? 'selected' : ''; ?>>Joomla versie</option>
            <option value="extensies" <?php echo $sorteerOp === 'extensies' ? 'selected' : ''; ?>>Extensies (meeste aandacht eerst)</option>
            <option value="beveiliging" <?php echo $sorteerOp === 'beveiliging' ? 'selected' : ''; ?>>Beveiliging (meeste aandacht eerst)</option>
        </select>
    </div>
    <a href="?categorie=<?php echo urlencode($categorie); ?>&amp;sorteer=<?php echo urlencode($sorteerOp); ?>&amp;richting=<?php echo urlencode($richting === 'omgekeerd' ? 'normaal' : 'omgekeerd'); ?>"
       class="knop-icoon" style="padding: 8px 12px;" title="Sorteerrichting omkeren">⇅</a>
</div>

<div class="tabel-scroll-kader">
<table class="responsive-tabel">
<tr>
    <th style="width: 240px;"><?php echo sorteerKopLink('domein', 'Domein', '▲', 'Sorteer alfabetisch op domein', $categorie, $sorteerOp, $richting); ?><?php echo hulpIcoon('site-instellingen', 'Klik op de domeinnaam om rechtstreeks in te loggen bij het Joomla-beheer van deze site (in een nieuw tabblad).'); ?></th>
    <th style="width: 100px;">Website<?php echo hulpIcoon('statussen', 'Online/Offline wordt bepaald door de website zelf op te vragen - een foutcode (403/500/etc.), geen verbinding, of verdachte inhoud in de reactie telt als Offline.'); ?></th>
    <th style="width: 90px;"><?php echo sorteerKopLink('joomla', 'Joomla', '▼', 'Sorteer op Joomla versie', $categorie, $sorteerOp, $richting); ?><?php echo hulpIcoon('statussen', 'De geïnstalleerde Joomla-kernversie, opgehaald via het admin-pad. Rood betekent dat er een nieuwere versie binnen dezelfde hoofdversie beschikbaar is.'); ?></th>
    <th style="width: 140px;">SSL status<?php echo hulpIcoon('statussen', 'Aantal dagen tot het SSL-certificaat verloopt. Wordt oranje/rood zodra dat aantal dagen laag genoeg is om actie te overwegen.'); ?></th>
    <th style="width: 200px;"><?php echo sorteerKopLink('extensies', 'Extensies', '▼', 'Sorteer op meeste aandacht nodig', $categorie, $sorteerOp, $richting); ?><?php echo hulpIcoon('statussen', 'Groen = alle extensies van derden zijn up-to-date. Rood = er is minstens één verouderde extensie gevonden. Grijs = (deels) onbekend, bijvoorbeeld omdat er nog geen update-locatie voor is geregistreerd.'); ?></th>
    <th style="width: 200px;"><?php echo sorteerKopLink('beveiliging', 'Beveiliging', '▼', 'Sorteer op meeste aandacht nodig', $categorie, $sorteerOp, $richting); ?><?php echo hulpIcoon('beveiliging', 'Toont het aantal nieuwe/niet-vertrouwde items dat de laatste scan heeft gevonden. Groen ("Schoon") betekent dat alles wat gevonden is, al eerder als vertrouwd is gemarkeerd.'); ?></th>
    <th style="width: 190px;">Actie<?php echo hulpIcoon('een-site-herscannen', 'Snelle acties per site: opnieuw scannen, site-instellingen, het beveiligingsrapport, het scanscript downloaden, direct openen in je FTP-client, het klantrapport (PDF), en de site verwijderen.'); ?></th>
</tr>

<?php
// Fase 1: voor elke site alle weergavewaarden berekenen (zoals voorheen),
// maar nu verzameld in een array in plaats van meteen te tonen - dat is
// nodig om daarna te kunnen sorteren op "ernst" i.p.v. alleen alfabetisch.
$weergaveRijen = [];

foreach ($sites as $site) {
    $websiteClass  = $site['live_website_class'] ?? '';
    $websiteStatus = $site['live_website_status'] ?? '-';

    $sslStatus = $site['live_ssl_status_tekst'] ?? '-';
    $sslClass  = $site['live_ssl_class'] ?? '';

    // "Ernst"-score voor de beveiligingskolom: het aantal niet-vertrouwde
    // verdachte items. Nog niet gescand telt als de laagste prioriteit
    // (-1) - daar is immers nog geen concreet probleem van bekend.
    $ernstBeveiliging = -1;

    if ($site['verdacht_aantal'] === null) {
        $beveiliging = "<td data-label=\"Beveiliging\">Nog niet gescand</td>";
    } else {
        $items = parseVerdachtDetails($site['verdacht_details'] ?? '');
        $siteVertrouwd = $alleVertrouwdeHashes[$site['id']] ?? [];

        $totaalItems = count($items);
        $vertrouwdAantal = 0;
        foreach ($items as $item) {
            if (isset($siteVertrouwd[$item['hash']])) {
                $vertrouwdAantal++;
            }
        }
        $verdachtWeergave = $totaalItems - $vertrouwdAantal;
        $siteId = (int)$site['id'];

        // Kernbestand-afwijkingen tellen mee in de ernst-score (voor de
        // sortering "meeste aandacht eerst"), en krijgen een eigen regel in
        // de kolom - los van de reguliere verdachte-bestanden-regel(s)
        // hieronder, zodat meteen duidelijk is om welk type vondst het gaat.
        $kernAantal = (int) ($kernAfwijkingenPerSite[$site['id']] ?? 0);
        // Extensie-bestand-afwijkingen (vergeleken met andere sites) tellen
        // sinds kort ook mee - stonden voorheen nergens op deze pagina,
        // ondanks dat het beveiligingsrapport ze wel toonde.
        $bestandAantal = (int) ($bestandAfwijkingenPerSite[$site['id']] ?? 0);
        $ernstBeveiliging = $verdachtWeergave + $kernAantal + $bestandAantal;

        $regels = [];

        if ($totaalItems === 0) {
            $regels[] = "<a class='groen' href='beveiliging.php?id=$siteId'>🟢 Schoon</a>";
        } elseif ($verdachtWeergave === 0) {
            // Alles gevonden is als vertrouwd gemarkeerd -> als veilig tonen.
            $regels[] = "<a class='groen' href='beveiliging.php?id=$siteId&toon_vertrouwd=1'>🟢 Schoon ($vertrouwdAantal vertrouwd)</a>";
        } else {
            $regels[] = "<a class='rood' href='beveiliging.php?id=$siteId'>🔴 $verdachtWeergave verdacht</a>";
            if ($vertrouwdAantal > 0) {
                $regels[] = "<a class='vertrouwd-link' href='beveiliging.php?id=$siteId&toon_vertrouwd=1'>✅ $vertrouwdAantal vertrouwd</a>";
            }
        }

        if ($kernAantal > 0) {
            $kernWoord = $kernAantal === 1 ? 'kernbestand wijkt af' : 'kernbestanden wijken af';
            $regels[] = "<a class='rood' href='beveiliging.php?id=$siteId'>⚠️ $kernAantal $kernWoord van officieel pakket</a>";
        }

        if ($bestandAantal > 0) {
            $bestandWoord = $bestandAantal === 1 ? 'extensiebestand wijkt af' : 'extensiebestanden wijken af';
            $regels[] = "<a class='rood' href='beveiliging.php?id=$siteId'>⚠️ $bestandAantal $bestandWoord van andere sites</a>";
        }

        $kernVertrouwdAantal = (int) ($kernVertrouwdPerSite[$site['id']] ?? 0);
        if ($kernVertrouwdAantal > 0) {
            $kernVertrouwdWoord = $kernVertrouwdAantal === 1 ? 'afwijkend kernbestand vertrouwd' : 'afwijkende kernbestanden vertrouwd';
            $regels[] = "<a class='vertrouwd-link' href='beveiliging.php?id=$siteId'>✅ $kernVertrouwdAantal $kernVertrouwdWoord</a>";
        }

        $beveiliging = "<td data-label=\"Beveiliging\">" . implode('<br>', $regels) . "</td>";
    }

    // Joomla-versie: controleren of dit de nieuwste versie is BINNEN
    // dezelfde hoofdversie (major) - dus 5.4.6 vergelijken met de nieuwste
    // 5.x, niet met een eventueel al bestaande nieuwere hoofdversie (6.x).
    $joomlaHuidig = $site['joomla_versie'] ?? null;
    $joomlaMajor  = bepaalMajorVersie($joomlaHuidig);
    $joomlaNieuwsteBinnenMajor = $joomlaMajor !== null ? ($nieuwsteVersies['joomla_' . $joomlaMajor] ?? null) : null;
    $joomlaStatus = isUpToDate($joomlaHuidig, $joomlaNieuwsteBinnenMajor);

    if ($joomlaHuidig === null || $joomlaHuidig === '') {
        $joomlaCel = '';
    } elseif ($joomlaStatus === null) {
        $joomlaCel = htmlspecialchars($joomlaHuidig);
    } elseif ($joomlaStatus === true) {
        $joomlaCel = htmlspecialchars($joomlaHuidig) . " <span class='groen'>✅</span>";
    } else {
        $joomlaCel = "<span class='rood'>🔴 " . htmlspecialchars($joomlaHuidig) . "</span><br>(nieuw: " . htmlspecialchars($joomlaNieuwsteBinnenMajor) . ")";
    }

    // Sorteerbouwstenen voor de Joomla-kolom - BEWUST geen enkel "ernst"-getal
    // (zoals bij Extensies/Beveiliging hieronder), want hier moeten twee
    // onafhankelijke dingen na elkaar wegen: eerst de HOOFDVERSIE zelf (een
    // site op Joomla 3.x verdient sowieso meer aandacht dan een site op 6.x,
    // ook al is die 3.x-site toevallig de nieuwste binnen zijn eigen major -
    // "up-to-date binnen de major" zegt niets over hoe oud die major zelf
    // is), en pas daarna, BINNEN dezelfde hoofdversie, de vertrouwde
    // rood/onbekend/groen-status en het exacte versienummer. Zie de
    // vergelijkingsfunctie in Fase 2 hieronder voor hoe deze stap voor stap
    // worden toegepast. Ontdekt/gevraagd door Wouter (augustus 2026): met
    // het oude, enkele ernstgetal kregen alle "actueel binnen eigen major"-
    // sites (6.1.3 zowel als een sterk verouderde 3.10.12) exact dezelfde
    // score, dus geen enkele zichtbare herschikking tussen hoofdversies.
    $joomlaMajorGetal = $joomlaMajor !== null ? (int) $joomlaMajor : PHP_INT_MAX;

    // Extensies: uitsluitend gebaseerd op de volledige scan (site_alle_extensies
    // via $alleDerdePartijSamenvatting) - zie de toelichting hierboven bij de
    // samenvattende telling voor waarom het oudere mechanisme (site_extensies)
    // hier bewust niet meer meeweegt.
    $derdePartij = $alleDerdePartijSamenvatting[$site['id']] ?? ['totaal' => 0, 'niet_up_to_date' => 0, 'onbekend' => 0, 'up_to_date' => 0];

    $heeftGeenEnkeleData = $derdePartij['totaal'] === 0;
    $heeftVerouderdeItems = $derdePartij['niet_up_to_date'] > 0;
    $heeftOnbekendeItems = $derdePartij['onbekend'] > 0;

    // "Ernst"-score voor de extensiekolom: de categorie weegt zwaarder dan
    // de aantallen (een "niet up-to-date" site moet altijd boven een
    // "deels onbekend" site staan, ongeacht de exacte aantallen), met de
    // aantallen als tweede sorteercriterium binnen dezelfde categorie.
    //
    // Belangrijk: "verdacht_aantal === null" betekent dat het scanscript op
    // deze site nog nooit succesvol is voltooid (zie ook de Beveiliging-
    // kolom hierboven, die dezelfde vlag gebruikt). Andere gegevens, zoals
    // de Joomla-kernversie en enkele extensies met een geregistreerd
    // manifest-pad (bijv. Admin Tools, Akeeba Backup), worden namelijk via
    // een LOS, van het scanscript onafhankelijk kanaal opgehaald - die
    // kunnen dus best al gevuld zijn terwijl de volledige scan (en dus de
    // volledige extensielijst) nog nooit is gelukt. Zonder deze check zou
    // de kolom dan ten onrechte "Niet up-to-date" of "Deels onbekend" tonen
    // op basis van die toevallige, onvolledige gegevens.
    if ($site['verdacht_aantal'] === null) {
        $extensiesCel = "Nog niet gescand";
        $ernstExtensies = -1;
    } elseif ($heeftGeenEnkeleData) {
        $extensiesCel = "<a class='grijs' href='extensies.php?id=" . (int)$site['id'] . "'>Onbekend</a>";
        $ernstExtensies = 1;
    } elseif ($heeftVerouderdeItems) {
        $extensiesCel = "<a class='rood' href='extensies.php?id=" . (int)$site['id'] . "'>Niet up-to-date</a>";
        $ernstExtensies = 300 + ($derdePartij['niet_up_to_date'] * 10) + $derdePartij['onbekend'];
    } elseif ($heeftOnbekendeItems) {
        // Geen bevestigd-verouderde items, maar ook niet ALLES bevestigd
        // up-to-date - dus niet ten onrechte een groen "Up-to-date" tonen.
        $extensiesCel = "<a class='grijs' href='extensies.php?id=" . (int)$site['id'] . "'>Deels onbekend</a>";
        $ernstExtensies = 100 + $derdePartij['onbekend'];
    } else {
        $extensiesCel = "<a class='groen' href='extensies.php?id=" . (int)$site['id'] . "'>Up-to-date</a>";
        $ernstExtensies = 0;
    }

    $onderdelen = [];
    if ($derdePartij['niet_up_to_date'] > 0) {
        $onderdelen[] = $derdePartij['niet_up_to_date'] . ' verouderd';
    }
    if ($derdePartij['onbekend'] > 0) {
        $onderdelen[] = $derdePartij['onbekend'] . ' onbekend';
    }
    if (!empty($onderdelen)) {
        $derdePartijTekst = implode(', ', $onderdelen);
        $extensiesCel .= "<br><a class='vertrouwd-link' href='extensies.php?id=" . (int)$site['id'] . "'>$derdePartijTekst</a>";
    }
    $adminPadOntsleuteld = trim(ontsleutelWaarde($site['admin_pad'] ?? ''), '/');
    if ($adminPadOntsleuteld === '') {
        $adminPadOntsleuteld = 'administrator';
    }
    $adminUrl = 'https://' . ($site['domein'] ?? '') . '/' . $adminPadOntsleuteld;

    $faviconUrl = !empty($site['favicon_url']) ? $site['favicon_url'] : 'https://www.joomla.org/favicon.ico';

    // Link om de FTP-/SFTP-gegevens direct te openen in een lokaal
    // geïnstalleerde FTP-client (bijv. FileZilla) - zie bepaalFtpClientLink()
    // in instellingen_functies.php (ook gebruikt op beveiliging.php).
    $ftpLink = bepaalFtpClientLink($site);
    $ftpClientUrl = $ftpLink['url'];
    $ftpWachtwoordKopieren = $ftpLink['wachtwoordKopieren'];
    $ftpGebruikersnaamKopieren = $ftpLink['gebruikersnaamKopieren'];

    $weergaveRijen[] = [
        'site' => $site,
        'websiteClass' => $websiteClass,
        'websiteStatus' => $websiteStatus,
        'sslStatus' => $sslStatus,
        'sslClass' => $sslClass,
        'beveiliging' => $beveiliging,
        'joomlaCel' => $joomlaCel,
        'extensiesCel' => $extensiesCel,
        'adminUrl' => $adminUrl,
        'faviconUrl' => $faviconUrl,
        'ftpClientUrl' => $ftpClientUrl,
        'ftpWachtwoordKopieren' => $ftpWachtwoordKopieren,
        'ftpGebruikersnaamKopieren' => $ftpGebruikersnaamKopieren,
        'ernstBeveiliging' => $ernstBeveiliging,
        'ernstExtensies' => $ernstExtensies,
        'joomlaHuidig' => $joomlaHuidig,
        'joomlaMajorGetal' => $joomlaMajorGetal,
        'joomlaStatus' => $joomlaStatus,
    ];
}

// Fase 2: sorteren op basis van de gekozen kolom - bij "joomla", "extensies"
// of "beveiliging" komt de hoogste ernst (de meeste aandacht nodig) bovenaan;
// bij gelijke ernst blijft de domeinnaam als tweede sorteercriterium gelden.
if ($sorteerOp === 'joomla') {
    usort($weergaveRijen, function ($a, $b) {
        // 1. Geen Joomla-versiedata (nog nooit opgehaald) helemaal onderaan -
        //    daar is niets concreets om aandacht aan te geven.
        $aGeenData = $a['joomlaHuidig'] === null || $a['joomlaHuidig'] === '';
        $bGeenData = $b['joomlaHuidig'] === null || $b['joomlaHuidig'] === '';
        if ($aGeenData !== $bGeenData) {
            return $aGeenData ? 1 : -1;
        }
        if ($aGeenData && $bGeenData) {
            return strcasecmp($a['site']['domein'] ?? '', $b['site']['domein'] ?? '');
        }

        // 2. Oudere HOOFDVERSIE eerst, los van de status binnen die major -
        //    een site op Joomla 3.x hoort boven een site op 6.x te staan,
        //    ook als die 3.x-site toevallig zelf al de nieuwste 3.x is.
        $majorVergelijking = $a['joomlaMajorGetal'] <=> $b['joomlaMajorGetal'];
        if ($majorVergelijking !== 0) {
            return $majorVergelijking;
        }

        // 3. Binnen dezelfde hoofdversie: verouderd (rood) vóór onbekend
        //    (grijs) vóór up-to-date (groen).
        $statusVolgorde = static fn($status) => $status === false ? 0 : ($status === null ? 1 : 2);
        $statusVergelijking = $statusVolgorde($a['joomlaStatus']) <=> $statusVolgorde($b['joomlaStatus']);
        if ($statusVergelijking !== 0) {
            return $statusVergelijking;
        }

        // 4. Binnen dezelfde hoofdversie én status: het oudste exacte
        //    versienummer eerst (bv. 6.1.2 vóór 6.1.3).
        $versieVergelijking = version_compare($a['joomlaHuidig'], $b['joomlaHuidig']);
        if ($versieVergelijking !== 0) {
            return $versieVergelijking;
        }

        // 5. Tot slot: alfabetisch op domein.
        return strcasecmp($a['site']['domein'] ?? '', $b['site']['domein'] ?? '');
    });
} elseif ($sorteerOp === 'extensies') {
    usort($weergaveRijen, function ($a, $b) {
        return $b['ernstExtensies'] <=> $a['ernstExtensies'] ?: strcasecmp($a['site']['domein'] ?? '', $b['site']['domein'] ?? '');
    });
} elseif ($sorteerOp === 'beveiliging') {
    usort($weergaveRijen, function ($a, $b) {
        return $b['ernstBeveiliging'] <=> $a['ernstBeveiliging'] ?: strcasecmp($a['site']['domein'] ?? '', $b['site']['domein'] ?? '');
    });
}
// Bij "domein" staat de volgorde al goed, want $sites kwam al ORDER BY domein ASC uit de database.

// Fase 3: desgewenst de hele volgorde omkeren - werkt voor alle vier de
// kolommen tegelijk, ongeacht hoe die volgorde tot stand kwam (SQL bij
// "domein", usort() bij de andere drie), want dit draait simpelweg de
// uiteindelijke array om. Dat keert ook de tiebreaks correct mee om (bv.
// bij gelijke ernst niet meer A-Z maar Z-A), in plaats van alleen de
// hoofdsortering.
if ($richting === 'omgekeerd') {
    $weergaveRijen = array_reverse($weergaveRijen);
}
?>

<?php foreach ($weergaveRijen as $rij): ?>

<?php
    $site = $rij['site'];
    $websiteClass = $rij['websiteClass'];
    $websiteStatus = $rij['websiteStatus'];
    $sslStatus = $rij['sslStatus'];
    $sslClass = $rij['sslClass'];
    $beveiliging = $rij['beveiliging'];
    $joomlaCel = $rij['joomlaCel'];
    $extensiesCel = $rij['extensiesCel'];
    $adminUrl = $rij['adminUrl'];
    $faviconUrl = $rij['faviconUrl'];
    $ftpClientUrl = $rij['ftpClientUrl'];
    $ftpWachtwoordKopieren = $rij['ftpWachtwoordKopieren'];
    $ftpGebruikersnaamKopieren = $rij['ftpGebruikersnaamKopieren'];
?>

<tr>
    <td data-label="Domein">
        <a href="https://<?php echo htmlspecialchars($site['domein'] ?? ''); ?>/" target="_blank" title="Website openen">
            <img src="<?php echo htmlspecialchars($faviconUrl); ?>" alt="" width="28" height="28" style="vertical-align: middle; margin-right: 8px;" onerror="this.onerror=null; this.src='https://www.joomla.org/favicon.ico';">
        </a>
        <a class="domein-link" href="<?php echo htmlspecialchars($adminUrl); ?>" target="_blank" title="Inloggen als admin"><?php echo htmlspecialchars($site['domein'] ?? ''); ?></a>
    </td>
    <td data-label="Website" class="<?php echo $websiteClass; ?>"><?php echo $websiteStatus; ?></td>
    <td data-label="Joomla"><?php echo $joomlaCel; ?></td>
    <td data-label="SSL status" class="<?php echo $sslClass; ?>"><?php echo htmlspecialchars($sslStatus); ?></td>
    <td data-label="Extensies"><?php echo $extensiesCel; ?></td>
    <?php echo $beveiliging; ?>
    <td data-label="">
        <div class="rij-acties">
            <span class="rij-spinner" id="spinner-<?php echo (int) $site['id']; ?>" title="Bezig met scannen..." style="display: none;">⏳</span>
            <button type="button" class="knop-icoon knop-ververs-icoon" onclick="scanEnkeleSite(<?php echo (int) $site['id']; ?>, this)" title="Alleen deze website opnieuw scannen">↻</button>
            <a class="knop-icoon" href="site_instellingen.php?site_id=<?php echo (int) $site['id']; ?>" title="Site-instellingen"><span class="icoon-glyph">⚙️</span></a>
            <a class="knop-icoon" href="<?php echo htmlspecialchars(bepaalSiteUrl($site, bepaalScanBestandsnaam($site))); ?>" target="_blank" rel="noopener" title="Scanscript rechtstreeks openen"><span class="icoon-glyph">📋</span></a>
            <?php if ($ftpClientUrl !== null): ?>
            <a class="knop-icoon" href="<?php echo htmlspecialchars($ftpClientUrl); ?>"
                <?php if ($ftpGebruikersnaamKopieren !== null): ?>
                onclick="ftpWachtwoordKopieren(this, event)" data-wachtwoord="<?php echo htmlspecialchars($ftpWachtwoordKopieren); ?>" data-gebruikersnaam="<?php echo htmlspecialchars($ftpGebruikersnaamKopieren); ?>"
                title="Zowel gebruikersnaam als wachtwoord bevatten een teken dat een kant-en-klare inloglink onbetrouwbaar maakt - klik hier om beide naar het klembord te kopiëren (los na elkaar); FileZilla wordt bewust NIET automatisch geopend, log zelf handmatig in"
                <?php elseif ($ftpWachtwoordKopieren !== null): ?>
                onclick="ftpWachtwoordKopieren(this, event)" data-wachtwoord="<?php echo htmlspecialchars($ftpWachtwoordKopieren); ?>" data-gebruikersnaam=""
                title="Wachtwoord bevat een teken (/, ?, # of %) dat een kant-en-klare inloglink onbetrouwbaar maakt - klik hier om het wachtwoord naar het klembord te kopiëren en FileZilla te openen; plak het wachtwoord daar zelf in het inlogscherm"
                <?php else: ?>
                title="Openen in lokale FTP-client (bijv. FileZilla) - werkt alleen als je besturingssysteem ftp/sftp-links daaraan heeft gekoppeld"
                <?php endif; ?>>
                <span class="icoon-tekst-badge">FTP</span>
                <?php if ($ftpWachtwoordKopieren !== null): ?><span style="font-size: 10px; vertical-align: middle;">📋</span><?php endif; ?>
            </a>
            <?php endif; ?>
            <a class="knop-icoon" href="klantrapport.php?id=<?php echo (int) $site['id']; ?>" title="Klantvriendelijk rapport openen - via de 'Opslaan als PDF'-knop op die pagina zelf te bewaren, geschikt om door te sturen naar de eigenaar van de website"><span class="icoon-tekst-badge">PDF</span></a>
            <form method="post" action="site_verwijderen.php" onsubmit="return confirm('Weet je zeker dat je \'<?php echo htmlspecialchars(addslashes($site['domein'] ?? '')); ?>\' wilt verwijderen? Dit verwijdert ook alle gescande extensiegegevens van deze site, en - als er FTP-/SFTP-gegevens bekend zijn - het scanscript-bestand van de website zelf. Dit kan niet ongedaan worden gemaakt.');">
                <?php echo csrfVeld(); ?>
                <input type="hidden" name="site_id" value="<?php echo (int) $site['id']; ?>">
                <button type="submit" class="knop-icoon knop-verwijder-icoon" title="Site verwijderen"><span class="icoon-glyph">🗑️</span></button>
            </form>
        </div>
    </td>
</tr>

<?php endforeach; ?>
</table>
</div>

<div style="margin-top: 20px; color: #999; font-size: 11px;"><?php echo htmlspecialchars($programmaNaam); ?> v<?php echo htmlspecialchars(MONITOR_VERSIE); ?></div>

<?php include 'terug_naar_boven.php'; ?>
</body>
</html>