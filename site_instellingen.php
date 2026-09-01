<?php
require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: login.php");
    exit;
}
// Zelfde reden als bij de andere scangegevens-pagina's.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once 'config.php';
require_once 'csrf_functies.php';
require_once 'instellingen_functies.php';

$foutmelding   = '';
$succesmelding = '';

$siteId = isset($_GET['site_id']) ? (int) $_GET['site_id'] : 0;

$stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ?");
$stmt->execute([$siteId]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$site) {
    die("Site niet gevonden.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vereistGeldigCsrfToken();
    $actie = $_POST['actie'] ?? '';

    if ($actie === 'opslaan_categorie') {
        $categorie = ($_POST['categorie'] ?? 'eigen') === 'anderen' ? 'anderen' : 'eigen';

        $stmt = $pdo->prepare("UPDATE sites SET categorie = ? WHERE id = ?");
        $stmt->execute([$categorie, $siteId]);

        $site['categorie'] = $categorie;
        $succesmelding = 'Categorie opgeslagen.';
    } elseif ($actie === 'opslaan_adminpad') {
        $adminPad = trim($_POST['admin_pad'] ?? '');
        if ($adminPad === '') {
            $adminPad = 'administrator';
        }
        $adminPad = ltrim($adminPad, '/');

        $stmt = $pdo->prepare("UPDATE sites SET admin_pad = ? WHERE id = ?");
        $stmt->execute([versleutelWaarde($adminPad), $siteId]);

        $site['admin_pad'] = versleutelWaarde($adminPad);
        $succesmelding = 'Admin-pad opgeslagen.';
    } elseif ($actie === 'opslaan_urlsubpad') {
        $urlSubpad = trim($_POST['url_subpad'] ?? '', "/ \t\n\r\0\x0B");

        $stmt = $pdo->prepare("UPDATE sites SET url_subpad = ? WHERE id = ?");
        $stmt->execute([$urlSubpad !== '' ? $urlSubpad : null, $siteId]);

        $site['url_subpad'] = $urlSubpad !== '' ? $urlSubpad : null;
        $succesmelding = 'URL-submap opgeslagen.';
    } elseif ($actie === 'opslaan_scanpad') {
        // Geen keuze meer (was een aan/uit-vinkje) - de automatische
        // detectie op basis van eigenaarschap zorgt er toch al voor dat dit
        // nooit verder gaat dan bij het hostingaccount hoort, dus staat dit
        // voor elke site gewoon altijd aan.
        $extraScanPad = 'auto';

        $negerenRuw = trim($_POST['extra_scan_pad_negeren'] ?? '');
        // Opslaan als simpele, kommagescheiden tekst - genoeg voor dit doel
        // en meteen weer leesbaar/bewerkbaar in het invoerveld hieronder.
        $negerenNetjes = $negerenRuw !== ''
            ? implode(',', array_filter(array_map('trim', explode(',', $negerenRuw))))
            : null;

        $stmt = $pdo->prepare("UPDATE sites SET extra_scan_pad = ?, extra_scan_pad_negeren = ? WHERE id = ?");
        $stmt->execute([$extraScanPad, $negerenNetjes, $siteId]);

        $site['extra_scan_pad'] = $extraScanPad;
        $site['extra_scan_pad_negeren'] = $negerenNetjes;

        $succesmelding = !empty($site['ftp_host'])
            ? 'Opgeslagen. Druk helemaal onderaan op de knop om het bijgewerkte scanscript automatisch via FTP naar deze site te versturen.'
            : 'Opgeslagen. Download hieronder het bijgewerkte scanscript en zet dat handmatig via FTP op deze site.';
    } elseif ($actie === 'opslaan_ftp') {
        $ftpHost       = trim($_POST['ftp_host'] ?? '');
        $ftpProtocol   = ($_POST['ftp_protocol'] ?? 'ftp') === 'sftp' ? 'sftp' : 'ftp';
        $ftpPoort      = (int) ($_POST['ftp_poort'] ?? ($ftpProtocol === 'sftp' ? 22 : 21));
        $ftpGebruiker  = trim($_POST['ftp_gebruikersnaam'] ?? '');
        $ftpWachtwoord = $_POST['ftp_wachtwoord'] ?? '';
        $ftpPad        = trim($_POST['ftp_pad'] ?? '') ?: '/';
        $ftpSsl        = isset($_POST['ftp_ssl']) ? 1 : 0;

        $stmt = $pdo->prepare("
            UPDATE sites
            SET ftp_host = ?, ftp_poort = ?, ftp_gebruikersnaam = ?, ftp_wachtwoord = ?, ftp_pad = ?, ftp_ssl = ?, ftp_protocol = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $ftpHost !== '' ? $ftpHost : null,
            $ftpPoort > 0 ? $ftpPoort : ($ftpProtocol === 'sftp' ? 22 : 21),
            $ftpGebruiker !== '' ? $ftpGebruiker : null,
            $ftpWachtwoord !== '' ? versleutelWaarde($ftpWachtwoord) : null,
            $ftpPad,
            $ftpSsl,
            $ftpProtocol,
            $siteId,
        ]);

        $site['ftp_host'] = $ftpHost;
        $site['ftp_poort'] = $ftpPoort;
        $site['ftp_gebruikersnaam'] = $ftpGebruiker;
        $site['ftp_wachtwoord'] = $ftpWachtwoord !== '' ? versleutelWaarde($ftpWachtwoord) : null;
        $site['ftp_pad'] = $ftpPad;
        $site['ftp_ssl'] = $ftpSsl;
        $site['ftp_protocol'] = $ftpProtocol;

        $succesmelding = $ftpHost !== ''
            ? 'FTP-gegevens opgeslagen. Druk helemaal onderaan op de knop om het scanscript automatisch te versturen.'
            : 'FTP-gegevens opgeslagen. Download hieronder het scanscript en zet dat handmatig op deze site.';
    }
}
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
<title>Site-instellingen - <?php echo htmlspecialchars($site['domein']); ?></title>
<style>

body {
    font-family: Arial, sans-serif;
    margin: 20px;
    font-size: 13px;
}

header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 5px;
}

h1 {
    margin: 0 0 5px 0;
}

.subtitel {
    color: #555;
    margin-bottom: 20px;
}

.domein-titel {
    font-size: 18px;
    font-weight: bold;
    color: #222;
    margin-bottom: 20px;
}

.melding {
    max-width: 550px;
    padding: 10px 14px;
    border-radius: 4px;
    margin-bottom: 15px;
    font-size: 13px;
}

.melding.ok   { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.melding.fout { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

.blok {
    max-width: 550px;
    margin-bottom: 25px;
    padding: 20px;
    border: 1px solid #ddd;
    background: #f5f5f5;
    border-radius: 6px;
}

.blok h2 {
    font-size: 15px;
    margin: 0 0 10px 0;
}

label {
    display: block;
    font-weight: bold;
    margin-top: 15px;
}

label:first-of-type {
    margin-top: 0;
}

.uitleg {
    font-weight: normal;
    color: #666;
    font-size: 12px;
    margin: 3px 0 6px 0;
}

input[type="text"], input[type="password"] {
    width: 100%;
    padding: 8px;
    box-sizing: border-box;
    font-size: 13px;
}

.knop {
    display: inline-block;
    padding: 10px 15px;
    background: #333;
    color: white;
    text-decoration: none;
    border-radius: 4px;
    border: none;
    font-size: 13px;
    cursor: pointer;
}

.knop:hover {
    background: #555;
}

header .knop {
    padding: 8px 14px;
}

.knop.opslaan {
    background: #1f6fa8;
    margin-top: 20px;
}

.knop.opslaan:hover {
    background: #175a87;
}

.knop.download {
    background: #2e8b3d;
}

.knop.download:hover {
    background: #24702f;
}

.knop.ftp {
    background: #6f42c1;
    margin-top: 20px;
}

.knop.ftp:hover {
    background: #59339a;
}

.wachtwoord-veld {
    position: relative;
}

.wachtwoord-veld input {
    padding-right: 40px;
}

.wachtwoord-veld .oogje {
    position: absolute;
    right: 4px;
    top: 50%;
    transform: translateY(-50%);
    background: #000;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 15px;
    padding: 6px 8px;
}

.ftp-rij {
    display: flex;
    gap: 12px;
}

.ftp-rij > div {
    flex: 1;
}

.categorie-keuze {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 15px;
}
label.categorie-optie {
    width: auto;
    box-sizing: border-box;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 16px;
    border: 2px solid var(--thema-rand);
    border-radius: 6px;
    cursor: pointer;
    font-weight: normal;
    margin-top: 0;
    transition: border-color 0.15s;
}
.categorie-optie:has(input:checked) {
    border-color: var(--thema-groen);
}
.categorie-optie input[type="radio"] {
    width: auto;
    margin: 0;
}
</style>
<?php include 'responsive_stijlen.php'; ?>
</head>
<body>

<header>
    <div>
        <h1>⚙️ Site-instellingen</h1>
        <div class="domein-titel"><?php echo htmlspecialchars($site['domein']); ?></div>
    </div>
    <div style="display: flex; gap: 8px;">
        <button type="button" class="knop" onclick="history.back()" style="padding: 8px 12px;" title="Eén stap terug">←</button>
        <a class="knop" href="index.php?categorie=<?php echo htmlspecialchars($site['categorie'] ?? 'eigen'); ?>">Terug naar monitor</a>
    </div>
</header>

<?php if ($succesmelding !== ''): ?>
    <div id="melding-server" class="melding ok"><?php echo htmlspecialchars($succesmelding); ?></div>
<?php endif; ?>
<?php if ($foutmelding !== ''): ?>
    <div id="melding-server" class="melding fout"><?php echo htmlspecialchars($foutmelding); ?></div>
<?php endif; ?>

<div id="ftp-melding-boven" class="melding" style="display: none;"></div>

<div class="blok">
    <h2>Categorie<?php echo hulpIcoon('site-instellingen', 'Bepaalt op de indexpagina in welk overzicht deze site verschijnt, en of hij meetelt in de samenvattingsmail over verouderde extensies/beveiligingsissues - dat laatste geldt alleen voor "Eigen websites".'); ?></h2>
    <div class="uitleg" style="margin-bottom: 15px;">
        "Websites van anderen" tellen niet mee in de samenvattingsmail over verouderde extensies/beveiligingsissues -
        zo blijft die mail overzichtelijk voor de sites die je zelf volledig up-to-date en schoon wil houden.
    </div>
    <form method="post">
        <?php echo csrfVeld(); ?>
        <input type="hidden" name="actie" value="opslaan_categorie">
        <div class="categorie-keuze">
            <label class="categorie-optie">
                <input type="radio" name="categorie" value="eigen" <?php echo ($site['categorie'] ?? 'eigen') === 'eigen' ? 'checked' : ''; ?>>
                <span>🏠 Eigen website</span>
            </label>
            <label class="categorie-optie">
                <input type="radio" name="categorie" value="anderen" <?php echo ($site['categorie'] ?? 'eigen') === 'anderen' ? 'checked' : ''; ?>>
                <span>👤 Website van een ander</span>
            </label>
        </div>
        <button type="submit" class="knop opslaan">Opslaan</button>
    </form>
</div>

<div class="blok">
    <h2>Admin-inlogpad</h2>
    <div class="uitleg" style="margin-bottom: 15px;">
        Meestal gewoon <code>administrator</code>. Heeft deze site een beveiligingsplugin die het adminpad
        verbergt achter een geheim woord (bijv. Akeeba Admin Tools), vul dan de volledige variant in, zoals
        <code>administrator/index.php?geheimwoord</code> of <code>administrator/?geheimwoord</code>. Dit veld wordt
        onder meer gebruikt om de Joomla-kernversie te kunnen bepalen - staat dit fout, dan blijft dat veld in het
        overzicht leeg.
    </div>
    <form method="post">
        <?php echo csrfVeld(); ?>
        <input type="hidden" name="actie" value="opslaan_adminpad">

        <label for="admin_pad">Admin-pad</label>
        <input type="text" id="admin_pad" name="admin_pad" placeholder="administrator" value="<?php echo htmlspecialchars(ontsleutelWaarde($site['admin_pad'] ?? '')); ?>">

        <button type="submit" class="knop opslaan">Opslaan</button>
    </form>
</div>

<div class="blok">
    <h2>URL-submap<?php echo hulpIcoon('site-instellingen', 'Alleen invullen als Joomla niet in de webroot zelf staat, maar in een submap die WEL rechtstreeks via de domeinnaam bereikbaar is - anders dan het FTP-pad hierboven, dat alleen bepaalt waar het bestand op de schijf terechtkomt.'); ?></h2>
    <div class="uitleg" style="margin-bottom: 15px;">
        Staat Joomla niet los op het domein, maar in een submap die WEL rechtstreeks via de domeinnaam bereikbaar is
        (bijv. <code>https://<?php echo htmlspecialchars($site['domein'] ?? 'voorbeeld.nl'); ?>/bieb/</code> in
        plaats van <code>https://<?php echo htmlspecialchars($site['domein'] ?? 'voorbeeld.nl'); ?>/</code>)? Vul
        dan hier die submap in. <strong>Let op, dit is iets anders dan het FTP-pad hieronder:</strong> dat bepaalt
        alleen waar het scanscript op de schijf terechtkomt, dit veld bepaalt via welke URL de monitor het
        scanscript daarna kan bereiken om te scannen - die twee hoeven niet overeen te komen. Voor verreweg de
        meeste sites laat je dit gewoon leeg.
    </div>
    <form method="post">
        <?php echo csrfVeld(); ?>
        <input type="hidden" name="actie" value="opslaan_urlsubpad">

        <label for="url_subpad">URL-submap</label>
        <input type="text" id="url_subpad" name="url_subpad" placeholder="(meestal leeg laten)" value="<?php echo htmlspecialchars($site['url_subpad'] ?? ''); ?>">

        <button type="submit" class="knop opslaan">Opslaan</button>
    </form>
</div>

<div class="blok">
    <h2>FTP-gegevens (optioneel)<?php echo hulpIcoon('site-instellingen', 'Vul dit in om het scanscript automatisch naar deze site te kunnen versturen, zonder zelf te hoeven downloaden/uploaden via een los FTP-programma.'); ?></h2>
    <div class="uitleg" style="margin-bottom: 15px;">
        Vul dit in om het scanscript met één druk op de knop rechtstreeks naar deze site te versturen, zonder zelf
        te hoeven downloaden/uploaden.
    </div>
    <form method="post">
        <?php echo csrfVeld(); ?>
        <input type="hidden" name="actie" value="opslaan_ftp">

        <label>Protocol</label>
        <div class="uitleg">
            Kies het protocol dat jouw hostingpartij voor bestandsoverdracht gebruikt.
        </div>
        <div style="display: flex; gap: 20px; margin-bottom: 15px;">
            <label style="display: flex; align-items: center; gap: 6px; font-weight: normal;">
                <input type="radio" name="ftp_protocol" value="ftp" <?php echo (($site['ftp_protocol'] ?? 'ftp') === 'ftp') ? 'checked' : ''; ?> onchange="protocolGewijzigd(this)" style="width: auto;">
                FTP
            </label>
            <label style="display: flex; align-items: center; gap: 6px; font-weight: normal;">
                <input type="checkbox" id="ftp_ssl" name="ftp_ssl" value="1" <?php echo !empty($site['ftp_ssl']) ? 'checked' : ''; ?> style="width: auto;">
                <span>waarvan beveiligd (FTPS)</span>
            </label>
            <label style="display: flex; align-items: center; gap: 6px; font-weight: normal;">
                <input type="radio" name="ftp_protocol" value="sftp" <?php echo (($site['ftp_protocol'] ?? '') === 'sftp') ? 'checked' : ''; ?> onchange="protocolGewijzigd(this)" style="width: auto;">
                SFTP
            </label>
        </div>

        <label for="ftp_host">Server</label>
        <div class="uitleg">Bijv. <code>ftp.<?php echo htmlspecialchars($site['domein']); ?></code>, <code>ssh.<?php echo htmlspecialchars($site['domein']); ?></code>, of een IP-adres.</div>
        <input type="text" id="ftp_host" name="ftp_host" placeholder="ftp.voorbeeld.nl" value="<?php echo htmlspecialchars($site['ftp_host'] ?? ''); ?>">

        <div class="ftp-rij">
            <div>
                <label for="ftp_gebruikersnaam">Gebruikersnaam</label>
                <input type="text" id="ftp_gebruikersnaam" name="ftp_gebruikersnaam" value="<?php echo htmlspecialchars($site['ftp_gebruikersnaam'] ?? ''); ?>">
            </div>
            <div>
                <label for="ftp_poort">Poort</label>
                <input type="text" id="ftp_poort" name="ftp_poort" value="<?php echo htmlspecialchars((string) ($site['ftp_poort'] ?? 21)); ?>">
            </div>
        </div>

        <label for="ftp_wachtwoord">Wachtwoord</label>
        <div class="uitleg">Klik op het oogje om het huidige wachtwoord te zien, of pas het direct aan.</div>
        <div class="wachtwoord-veld">
            <input type="password" id="ftp_wachtwoord" name="ftp_wachtwoord" value="<?php echo htmlspecialchars(ontsleutelWaarde($site['ftp_wachtwoord'] ?? '')); ?>" autocomplete="new-password">
            <button type="button" class="oogje" onclick="toonWachtwoord('ftp_wachtwoord', this)"><span class="icoon-glyph">👁️</span></button>
        </div>

        <label for="ftp_pad">Pad op de server</label>
        <div class="uitleg">
            De map waar <code><?php echo htmlspecialchars(bepaalScanBestandsnaam($site)); ?></code> in geplaatst moet worden (dezelfde map als
            <code>configuration.php</code> van Joomla), bijv. <code>/public_html</code> of gewoon <code>/</code>.
            Weet je dit niet zeker (sommige hostingpartijen geven toegang 2-3 mappen boven <code>public_html</code>)?
            Vul dan hierboven alvast de gegevens in, en klik op de zoekknop.
        </div>
        <div style="display: flex; gap: 8px;">
            <input type="text" id="ftp_pad" name="ftp_pad" placeholder="/public_html" value="<?php echo htmlspecialchars($site['ftp_pad'] ?? '/'); ?>" style="flex: 1;">
            <button type="button" class="knop" style="background: #17a2b8; white-space: nowrap;" onclick="zoekFtpPad(this)">🔍 Zoek automatisch</button>
        </div>
        <div id="ftp-pad-resultaat" style="display: none; margin-top: 8px; font-size: 12px;"></div>

        <div class="uitleg" style="margin-top: 15px;">
            FTPS gebruikt gewoon <strong>poort 21</strong>. SFTP gebruikt meestal <strong>poort 22</strong> - dat is
            een écht ander protocol (gebaseerd op SSH), dat we nu ook rechtstreeks ondersteunen.
        </div>

        <button type="submit" class="knop opslaan">Opslaan</button>
    </form>
</div>

<div class="blok">
    <h2>Extra scanpad (buiten de website-root)<?php echo hulpIcoon('site-instellingen', 'Scant automatisch mee tot aan de accountroot van je hostingpakket, voor zover de hostingpartij dat toestaat - hoeveel niveaus dat precies zijn, bepaalt het scanscript zelf, elke keer opnieuw, op basis van eigenaarschap.'); ?></h2>

    <div class="info-balk" style="background: var(--thema-badge-bg); border: 1px solid var(--thema-rand); border-radius: 4px; padding: 10px 14px; margin-bottom: 15px; font-size: 13px;">
        <?php if (!empty($site['extra_scan_pad_gedetecteerd'])): ?>
            📍 Bij de laatste scan gedetecteerd: <code><?php echo htmlspecialchars($site['extra_scan_pad_gedetecteerd']); ?></code>
        <?php else: ?>
            📍 Nog niet bekend - dit verschijnt hier automatisch na de eerstvolgende scan.
        <?php endif; ?>
        <div style="margin-top: 6px; color: var(--thema-uitleg-tekst);">
            Dit staat altijd aan en hoeft niet apart ingesteld te worden: het scanscript bepaalt zelf, bij elke scan
            opnieuw, hoe ver het omhoog kijkt - nooit verder dan bij dit hostingaccount hoort (herkend via het
            eigenaarschap van elke map). Werkt alleen als de hostingpartij dit toestaat (geen
            <code>open_basedir</code>-restrictie) - lukt dat niet, dan meldt de scan dat gewoon netjes, zonder te
            crashen.
        </div>
    </div>

    <form method="post">
        <?php echo csrfVeld(); ?>
        <input type="hidden" name="actie" value="opslaan_scanpad">

        <label for="extra_scan_pad_negeren">Nog extra (sub)mapnamen overslaan (optioneel, kommagescheiden)</label>
        <input type="text" name="extra_scan_pad_negeren" id="extra_scan_pad_negeren"
            value="<?php echo htmlspecialchars($site['extra_scan_pad_negeren'] ?? ''); ?>"
            placeholder="bijv. Akeeba-Backup, of een eigen back-upmap">
        <div class="uitleg" style="margin-top: 8px;">
            Herkenbare hostingpartij-systeemmappen (bijv. <code>Maildir</code>, <code>.cagefs</code>, <code>.pki</code>,
            <code>.php</code>) worden al automatisch overgeslagen - daar hoef je hier dus niets voor in te vullen.
            Ook <code>domains</code> wordt automatisch overgeslagen, zodat je andere sites (die toch al apart en
            volledig via hun eigen monitor-item worden gescand) niet nog een keer meegescand worden. Dit veld is
            alleen nog voor mappen die je zelf, specifiek bij deze site, ook nog wilt overslaan.
        </div>

        <button type="submit" class="knop opslaan">Opslaan</button>
    </form>
</div>

<div class="blok">
    <h2>Scanscript-bestandsnaam</h2>
    <div class="uitleg" style="margin-bottom: 15px;">
        Elke site heeft een eigen, uniek gegenereerde scanscript-bestandsnaam (in plaats van bij elke site dezelfde,
        voorspelbare standaardnaam) - dat is veiliger, en zorgt ervoor dat de monitor het bestand altijd herkent als
        van zichzelf. Deze naam staat om die reden bewust <strong>vast</strong> en is niet los te bewerken; wil je 'm
        toch vervangen (bijv. omdat er op deze site nu ook andere monitorsoftware onder dezelfde naam draait), gebruik
        dan de knop hieronder - die genereert een nieuwe, unieke naam, plaatst die op de site, en ruimt het oude
        bestand automatisch op. Hiervoor zijn wel FTP-/SFTP-gegevens nodig (zie hierboven).
    </div>
    <div class="waarschuwing" style="margin-bottom: 15px;">
        ⚠️ Gebruikt deze site <strong>Akeeba Admin Tools</strong> (of een vergelijkbare firewall-plugin met een
        bestandsnaam-uitzonderingslijst)? Vervang je de naam hieronder, dan moet je de bestaande uitzondering voor
        de <em>oude</em> naam vervangen door een nieuwe voor de naam die je hierna te zien krijgt - anders blokkeert
        de firewall het scanscript alsnog, ook al staat het correct op de site. Zie hoofdstuk 3 van de helppagina
        voor het volledige stappenplan.
    </div>

    <label>Huidige bestandsnaam</label>
    <input type="text" readonly value="<?php echo htmlspecialchars(bepaalScanBestandsnaam($site)); ?>" style="background: var(--thema-zebra); cursor: not-allowed;">

    <button type="button" class="knop" style="background: #6f42c1; margin-top: 12px;" onclick="vervangScanscript(this)">🔄 Vervang door nieuwe, unieke naam</button>
    <div id="vervang-resultaat" style="display: none; margin-top: 10px; padding: 8px 12px; border-radius: 4px; font-size: 12px;"></div>

    <?php if (!empty($site['scan_bestandsnaam'])): ?>
        <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid var(--thema-rand);">
            <div class="uitleg" style="margin-bottom: 10px;">
                Staat er, van vóór een eerdere naamswijziging, mogelijk nog een overbodige kopie onder de
                <strong>oude standaardnaam</strong> (<code>scan-en-check-website.php</code>) op deze site? De knop
                hierboven ruimt dit normaliter al automatisch op - deze controle is puur nog een extra check, bijv.
                voor het geval er destijds geen FTP-gegevens bekend waren.
            </div>
            <button type="button" class="knop" style="background: #17a2b8;" onclick="controleerOudBestand(this)">🔍 Controleer of het oude bestand nog bestaat</button>
            <div id="oud-bestand-resultaat" style="display: none; margin-top: 10px; padding: 8px 12px; border-radius: 4px; font-size: 12px;"></div>
        </div>
    <?php endif; ?>
</div>

<div class="blok">
    <h2>Scanscript voor deze site</h2>
    <div class="uitleg" style="margin-bottom: 15px;">
        Download hier handmatig een kant-en-klaar scanscript (met het ingestelde extra scanpad, de actuele geheime
        code en het monitor-pad al erin verwerkt) om zelf via FTP op
        <strong><?php echo htmlspecialchars($site['domein']); ?></strong> te zetten - handig als automatisch
        versturen (hieronder) om wat voor reden dan ook niet lukt, bijvoorbeeld doordat de hostingpartij van deze
        site uitgaand FTP-verkeer blokkeert.
    </div>
    <a class="knop download" href="download_scan_script.php?site_id=<?php echo (int) $siteId; ?>">⬇️ Download <?php echo htmlspecialchars(bepaalScanBestandsnaam($site)); ?> voor deze site</a>

    <?php if (!empty($site['ftp_host'])): ?>
        <div class="uitleg" style="margin-top: 20px; margin-bottom: 15px;">
            Er zijn ook FTP-gegevens ingevuld hierboven, dus je kunt het scanscript in plaats daarvan ook met één
            druk op de knop rechtstreeks naar <strong><?php echo htmlspecialchars($site['domein']); ?></strong>
            laten versturen.
        </div>
        <button type="button" class="knop ftp" onclick="verstuurFtp(this)">🚀 Verstuur scanscript nu via FTP naar deze site</button>
        <div id="ftp-resultaat" style="display: none; margin-top: 12px; padding: 10px 14px; border-radius: 4px; font-size: 12px; white-space: pre-wrap; font-family: monospace; background: #eef1f4; border: 1px solid #ddd;"></div>
        <button type="button" id="herstel-rechten-knop" class="knop" style="display: none; margin-top: 10px; background: #e67e22;" onclick="herstelMapRechten(this)">🔧 Probeer maprechten automatisch te herstellen (naar 755)</button>
        <div id="herstel-rechten-resultaat" style="display: none; margin-top: 8px; font-size: 12px;"></div>
    <?php else: ?>
        <div class="uitleg" style="margin-top: 20px;">
            Vul je hierboven ook FTP-gegevens in, dan verschijnt hier voortaan ook een knop om dit met één druk
            automatisch te laten gebeuren, naast de downloadknop hierboven.
        </div>
    <?php endif; ?>
</div>

<?php include 'terug_naar_boven.php'; ?>
</body>

<script>
const CSRF_TOKEN = <?php echo json_encode(haalCsrfToken()); ?>;
const SITE_ID = <?php echo (int) $siteId; ?>;
const SITE_DOMEIN = <?php echo json_encode($site['domein'] ?? ''); ?>;

function toonWachtwoord(veldId, knop) {
    const veld = document.getElementById(veldId);
    if (veld.type === 'password') {
        veld.type = 'text';
        knop.innerHTML = '<span class="icoon-glyph">🙈</span>';
    } else {
        veld.type = 'password';
        knop.innerHTML = '<span class="icoon-glyph">👁️</span>';
    }
}

function huidigProtocol() {
    const gekozen = document.querySelector('input[name="ftp_protocol"]:checked');
    return gekozen ? gekozen.value : 'ftp';
}

function protocolGewijzigd(radio) {
    const poortVeld = document.getElementById('ftp_poort');
    const ftpSslLabel = document.getElementById('ftp_ssl').closest('label');

    if (radio.value === 'sftp') {
        if (poortVeld.value === '' || poortVeld.value === '21') {
            poortVeld.value = '22';
        }
        document.getElementById('ftp_ssl').checked = false;
        document.getElementById('ftp_ssl').disabled = true;
        ftpSslLabel.style.opacity = '0.4';
    } else {
        if (poortVeld.value === '' || poortVeld.value === '22') {
            poortVeld.value = '21';
        }
        document.getElementById('ftp_ssl').disabled = false;
        ftpSslLabel.style.opacity = '1';
    }
}

function controleerOudBestand(knop) {
    knop.disabled = true;

    const resultaat = document.getElementById('oud-bestand-resultaat');
    resultaat.style.display = 'block';
    resultaat.style.background = '#eef1f4';
    resultaat.style.color = 'var(--thema-tekst)';
    resultaat.textContent = '⏳ Bezig met controleren...';

    const body = new URLSearchParams();
    body.append('csrf_token', CSRF_TOKEN);
    body.append('site_id', SITE_ID);

    fetch('controleer_oud_scanbestand.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
        .then(r => r.json())
        .then(data => {
            knop.disabled = false;

            if (!data.succes) {
                resultaat.style.background = '#f8d7da';
                resultaat.style.color = 'var(--thema-rood)';
                resultaat.textContent = '❌ ' + data.foutmelding;
                return;
            }

            if (data.gevonden === true) {
                resultaat.style.background = '#fff3cd';
                resultaat.style.color = 'var(--thema-geel)';
                resultaat.textContent = '⚠️ ' + data.melding;
            } else if (data.gevonden === false) {
                resultaat.style.background = '#d4edda';
                resultaat.style.color = 'var(--thema-groen)';
                resultaat.textContent = '✅ ' + data.melding;
            } else {
                resultaat.style.background = '#eef1f4';
                resultaat.style.color = 'var(--thema-tekst)';
                resultaat.textContent = 'ℹ️ ' + data.melding;
            }
        })
        .catch(err => {
            knop.disabled = false;
            resultaat.style.background = '#f8d7da';
            resultaat.style.color = 'var(--thema-rood)';
            resultaat.textContent = '❌ Er ging iets mis: ' + err.message;
        });
}

document.addEventListener('DOMContentLoaded', function () {
    const gekozen = document.querySelector('input[name="ftp_protocol"]:checked');
    if (gekozen) {
        protocolGewijzigd(gekozen);
    }
});

function zoekFtpPad(knop) {
    knop.disabled = true;

    const resultaat = document.getElementById('ftp-pad-resultaat');
    resultaat.style.display = 'block';
    resultaat.style.color = 'var(--thema-uitleg-tekst)';
    resultaat.textContent = '⏳ Bezig met zoeken naar configuration.php (kan even duren)...';

    const body = new URLSearchParams();
    body.append('csrf_token', CSRF_TOKEN);
    body.append('site_id', SITE_ID);
    body.append('ftp_protocol', huidigProtocol());
    body.append('ftp_host', document.getElementById('ftp_host').value);
    body.append('ftp_poort', document.getElementById('ftp_poort').value);
    body.append('ftp_gebruikersnaam', document.getElementById('ftp_gebruikersnaam').value);
    body.append('ftp_wachtwoord', document.getElementById('ftp_wachtwoord').value);
    body.append('ftp_ssl', document.getElementById('ftp_ssl').checked ? '1' : '0');
    body.append('domein', SITE_DOMEIN);

    fetch('ftp_detecteer_pad2.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
        .then(r => r.json())
        .then(data => {
            if (data.succes) {
                document.getElementById('ftp_pad').value = data.pad;
                if (data.waarschuwing) {
                    resultaat.style.color = 'var(--thema-geel)';
                    resultaat.textContent = '⚠️ Gevonden: "' + data.pad + '", maar: ' + data.waarschuwing;
                } else {
                    resultaat.style.color = 'var(--thema-groen)';
                    resultaat.textContent = '✅ Gevonden: "' + data.pad + '" - druk op "Opslaan" om dit te bevestigen.';
                }
            } else {
                resultaat.style.color = 'var(--thema-rood)';
                resultaat.textContent = '❌ ' + data.foutmelding;
            }
            knop.disabled = false;
        })
        .catch(err => {
            resultaat.style.color = 'var(--thema-rood)';
            resultaat.textContent = '❌ Er ging iets mis: ' + err.message;
            knop.disabled = false;
        });
}

function verstuurFtp(knop) {
    knop.disabled = true;

    const meldingServer = document.getElementById('melding-server');
    if (meldingServer) {
        meldingServer.style.display = 'none';
    }

    const meldingBoven = document.getElementById('ftp-melding-boven');
    meldingBoven.className = 'melding';
    meldingBoven.style.display = 'block';
    meldingBoven.textContent = '⏳ Bezig met versturen via FTP...';
    window.scrollTo({ top: 0, behavior: 'smooth' });

    const resultaat = document.getElementById('ftp-resultaat');
    resultaat.style.display = 'block';
    resultaat.textContent = '⏳ Bezig met versturen via FTP...';

    const body = new URLSearchParams();
    body.append('csrf_token', CSRF_TOKEN);
    body.append('site_id', SITE_ID);

    fetch('ftp_verstuur_scanscript.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
        .then(r => r.text())
        .then(tekst => {
            resultaat.textContent = tekst;

            const herstelKnop = document.getElementById('herstel-rechten-knop');

            if (tekst.includes('❌')) {
                meldingBoven.className = 'melding fout';
                meldingBoven.textContent = '❌ Het versturen via FTP is niet gelukt - zie de details hieronder.';

                if (tekst.includes('mist het uitvoer-recht')) {
                    herstelKnop.style.display = 'inline-block';
                } else {
                    herstelKnop.style.display = 'none';
                }
            } else {
                meldingBoven.className = 'melding ok';
                meldingBoven.textContent = '✅ Het scanscript is met FTP op de website geplaatst.';
                herstelKnop.style.display = 'none';
            }

            knop.disabled = false;
        })
        .catch(err => {
            resultaat.textContent = '❌ Er ging iets mis: ' + err.message;
            meldingBoven.className = 'melding fout';
            meldingBoven.textContent = '❌ Er ging iets mis: ' + err.message;
            knop.disabled = false;
        });
}

function vervangScanscript(knop) {
    if (!confirm('Het scanscript vervangen door een nieuwe, unieke naam? Dit plaatst direct een nieuw bestand op de site en verwijdert het oude bestand automatisch (via de ingestelde FTP-/SFTP-gegevens).\n\nLet op: gebruikt deze site Akeeba Admin Tools (of een vergelijkbare firewall met een bestandsnaam-uitzondering)? Werk die uitzondering dan ook bij naar de nieuwe naam, anders blokkeert de firewall het scanscript.')) {
        return;
    }

    knop.disabled = true;

    const resultaat = document.getElementById('vervang-resultaat');
    resultaat.style.display = 'block';
    resultaat.style.background = '#eef1f4';
    resultaat.style.color = 'var(--thema-tekst)';
    resultaat.textContent = '⏳ Nieuw scanscript wordt gegenereerd en geplaatst...';

    const body = new URLSearchParams();
    body.append('csrf_token', CSRF_TOKEN);
    body.append('site_id', SITE_ID);

    fetch('scanscript_vervangen.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
        .then(r => r.json())
        .then(data => {
            knop.disabled = false;

            if (data.succes) {
                resultaat.style.background = '#d4edda';
                resultaat.style.color = 'var(--thema-groen)';
                resultaat.textContent = '✅ ' + data.melding + ' - de pagina wordt ververst...';
                setTimeout(() => location.reload(), 1500);
            } else {
                resultaat.style.background = '#f8d7da';
                resultaat.style.color = 'var(--thema-rood)';
                resultaat.textContent = '❌ ' + data.melding;
            }
        })
        .catch(err => {
            knop.disabled = false;
            resultaat.style.background = '#f8d7da';
            resultaat.style.color = 'var(--thema-rood)';
            resultaat.textContent = '❌ Er ging iets mis: ' + err.message;
        });
}

function herstelMapRechten(knop) {
    knop.disabled = true;

    const resultaat = document.getElementById('herstel-rechten-resultaat');
    resultaat.style.display = 'block';
    resultaat.style.background = '#eef1f4';
    resultaat.style.color = 'var(--thema-tekst)';
    resultaat.textContent = '⏳ Bezig met aanpassen van de maprechten...';

    const body = new URLSearchParams();
    body.append('csrf_token', CSRF_TOKEN);
    body.append('site_id', SITE_ID);

    fetch('herstel_maprechten.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
        .then(r => r.json())
        .then(data => {
            knop.disabled = false;

            if (data.succes) {
                resultaat.style.background = '#d4edda';
                resultaat.style.color = 'var(--thema-groen)';
                resultaat.textContent = '✅ ' + data.melding;
                knop.style.display = 'none';
            } else {
                resultaat.style.background = '#f8d7da';
                resultaat.style.color = 'var(--thema-rood)';
                resultaat.textContent = '❌ ' + data.foutmelding;
            }
        })
        .catch(err => {
            knop.disabled = false;
            resultaat.style.background = '#f8d7da';
            resultaat.style.color = 'var(--thema-rood)';
            resultaat.textContent = '❌ Er ging iets mis: ' + err.message;
        });
}
</script>

</html>
