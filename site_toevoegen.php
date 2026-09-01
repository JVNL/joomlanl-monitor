<?php
require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: login.php");
    exit;
}
require_once 'config.php';
require_once 'csrf_functies.php';
require_once 'genereer_scanscript_functies.php';
require_once 'sftp_functies.php';
require_once 'instellingen_functies.php';
require_once 'ftp_rechten_functies.php';

$foutmelding   = '';
$succesmelding = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vereistGeldigCsrfToken();

    $domein    = trim($_POST['domein'] ?? '');
    $categorie = ($_POST['categorie'] ?? 'eigen') === 'anderen' ? 'anderen' : 'eigen';
    $adminPad  = trim($_POST['admin_pad'] ?? '');
    $urlSubpad = trim($_POST['url_subpad'] ?? '', "/ \t\n\r\0\x0B");

    // Geen keuze meer (was een aan/uit-vinkje) - staat voor elke nieuwe
    // site gewoon altijd aan, zie ook site_instellingen.php.
    $extraScanPad = 'auto';

    $ftpHost       = trim($_POST['ftp_host'] ?? '');
    $ftpProtocol   = ($_POST['ftp_protocol'] ?? 'ftp') === 'sftp' ? 'sftp' : 'ftp';
    $ftpPoort      = (int) ($_POST['ftp_poort'] ?? ($ftpProtocol === 'sftp' ? 22 : 21));
    $ftpGebruiker  = trim($_POST['ftp_gebruikersnaam'] ?? '');
    $ftpWachtwoord = $_POST['ftp_wachtwoord'] ?? '';
    $ftpPad        = trim($_POST['ftp_pad'] ?? '') ?: '/';
    $ftpSsl        = isset($_POST['ftp_ssl']) ? 1 : 0;

    // Toevallig meegeplakte "https://", "www." of een afsluitende slash eraf halen,
    // zodat het domeinveld consistent blijft met de rest van de tabel.
    $domein = preg_replace('#^https?://#i', '', $domein);
    $domein = preg_replace('#^www\.#i', '', $domein);
    $domein = rtrim($domein, '/');

    if ($adminPad === '') {
        $adminPad = 'administrator';
    }
    $adminPad = ltrim($adminPad, '/');

    if ($domein === '') {
        $foutmelding = 'Vul een domeinnaam in.';
    } else {
        // Altijd een unieke, aan de domeinnaam gekoppelde bestandsnaam
        // genereren (in plaats van de voor elke site identieke
        // standaardnaam "scan-en-check-website.php") - dit staat, net als
        // bij Site-instellingen, bewust vast en is niet meer los in te
        // vullen; wijzigen kan later alleen nog via de "Vervang door
        // nieuwe naam"-knop bij Site-instellingen.
        $scanBestandsnaamInvoer = genereerUniekeScanBestandsnaam($pdo);

        try {
            $stmt = $pdo->prepare("
                INSERT INTO sites
                    (domein, categorie, admin_pad, url_subpad, status, prioriteit, extra_scan_pad, scan_bestandsnaam, ftp_host, ftp_poort, ftp_gebruikersnaam, ftp_wachtwoord, ftp_pad, ftp_ssl, ftp_protocol)
                VALUES (?, ?, ?, ?, '', '', ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $domein,
                $categorie,
                versleutelWaarde($adminPad),
                $urlSubpad !== '' ? $urlSubpad : null,
                $extraScanPad,
                $scanBestandsnaamInvoer !== '' ? $scanBestandsnaamInvoer : null,
                $ftpHost !== '' ? $ftpHost : null,
                $ftpPoort > 0 ? $ftpPoort : ($ftpProtocol === 'sftp' ? 22 : 21),
                $ftpGebruiker !== '' ? $ftpGebruiker : null,
                $ftpWachtwoord !== '' ? versleutelWaarde($ftpWachtwoord) : null,
                $ftpPad,
                $ftpSsl,
                $ftpProtocol,
            ]);

            $nieuweSiteId = (int) $pdo->lastInsertId();

            // Zijn alle FTP-/SFTP-gegevens compleet ingevuld (incl. een
            // daadwerkelijk gevonden/ingevuld pad, niet de kale standaard
            // "/")? Stuur het scanscript dan meteen automatisch, in plaats
            // van de gebruiker terug te sturen naar Site-instellingen om
            // dat daar alsnog met een tweede actie te moeten doen.
            $ftpVolledig = $ftpHost !== '' && $ftpGebruiker !== '' && $ftpWachtwoord !== '' && $ftpPad !== '' && $ftpPad !== '/';
            $ftpResultaatParam = '';

            if ($ftpVolledig) {
                $nieuweSite = [
                    'id' => $nieuweSiteId,
                    'domein' => $domein,
                    'admin_pad' => versleutelWaarde($adminPad),
                    'url_subpad' => $urlSubpad !== '' ? $urlSubpad : null,
                    'extra_scan_pad' => $extraScanPad,
                    'scan_bestandsnaam' => $scanBestandsnaamInvoer !== '' ? $scanBestandsnaamInvoer : null,
                ];
                $scanBestandsnaam = bepaalScanBestandsnaam($nieuweSite);

                try {
                    $scanInhoud = genereerScanScriptInhoud($pdo, $nieuweSite);

                    if ($ftpProtocol === 'sftp') {
                        [$sftp, $ftpFoutmelding] = sftpVerbind($ftpHost, $ftpPoort, $ftpGebruiker, $ftpWachtwoord);
                        if ($sftp === null) {
                            $ftpResultaatParam = '&ftp_fout=' . urlencode($ftpFoutmelding);
                        } else {
                            $remotePad = '/' . trim($ftpPad, '/') . '/' . $scanBestandsnaam;
                            $gelukt = sftpUploadInhoud($sftp, $remotePad, $scanInhoud);
                            if ($gelukt) {
                                registreerVerstuurdScanBestand($pdo, $nieuweSiteId, $scanBestandsnaam);
                                $ftpResultaatParam = '&ftp_verstuurd=1';
                            } else {
                                $doelMap = trim($ftpPad, '/') !== '' ? '/' . trim($ftpPad, '/') : '/';
                                $rechtenDiagnose = diagnoseerMapRechtenSftp($sftp, $doelMap);
                                $melding = 'Uploaden naar "' . $remotePad . '" is mislukt.'
                                    . ($rechtenDiagnose !== null ? ' ' . $rechtenDiagnose : '');
                                $ftpResultaatParam = '&ftp_fout=' . urlencode($melding);
                            }
                        }
                    } else {
                        $conn = $ftpSsl ? @ftp_ssl_connect($ftpHost, $ftpPoort, 10) : @ftp_connect($ftpHost, $ftpPoort, 10);
                        if ($conn === false) {
                            $ftpResultaatParam = '&ftp_fout=' . urlencode("Kon geen FTP-verbinding maken met \"$ftpHost:$ftpPoort\".");
                        } elseif (!@ftp_login($conn, $ftpGebruiker, $ftpWachtwoord)) {
                            ftp_close($conn);
                            $ftpResultaatParam = '&ftp_fout=' . urlencode('FTP-inloggen mislukt (controleer gebruikersnaam/wachtwoord).');
                        } else {
                            ftp_pasv($conn, true);
                            $remotePad = '/' . trim($ftpPad, '/') . '/' . $scanBestandsnaam;

                            $stream = fopen('php://temp', 'r+');
                            fwrite($stream, $scanInhoud);
                            rewind($stream);
                            $gelukt = @ftp_fput($conn, $remotePad, $stream, FTP_BINARY);
                            fclose($stream);

                            if ($gelukt) {
                                registreerVerstuurdScanBestand($pdo, $nieuweSiteId, $scanBestandsnaam);
                                $ftpResultaatParam = '&ftp_verstuurd=1';
                            } else {
                                $doelMap = trim($ftpPad, '/') !== '' ? '/' . trim($ftpPad, '/') : '/';
                                $rechtenDiagnose = diagnoseerMapRechtenFtp($conn, $doelMap);
                                $melding = 'Uploaden naar "' . $remotePad . '" is mislukt.'
                                    . ($rechtenDiagnose !== null ? ' ' . $rechtenDiagnose : '');
                                $ftpResultaatParam = '&ftp_fout=' . urlencode($melding);
                            }

                            ftp_close($conn);
                        }
                    }
                } catch (\Throwable $e) {
                    $ftpResultaatParam = '&ftp_fout=' . urlencode($e->getMessage());
                }
            }

            // Meteen terug naar de monitorpagina met een succesmelding.
            header("Location: index.php?toegevoegd=" . urlencode($domein) . $ftpResultaatParam);
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $foutmelding = "Het domein \"$domein\" staat al in de tabel.";
            } else {
                $foutmelding = 'Toevoegen is mislukt: ' . $e->getMessage();
            }
        }
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
<title>Site toevoegen</title>
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

.melding {
    padding: 10px 14px;
    border-radius: 4px;
    margin-bottom: 15px;
    font-size: 13px;
    max-width: 500px;
}

.melding.fout { background: #f8d7da !important; color: #721c24 !important; border: 1px solid #f5c6cb; }

form {
    max-width: 500px;
    padding: 20px;
    border: 1px solid var(--thema-rand);
    background: var(--thema-kader-bg);
    border-radius: 6px;
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
    padding: 8px 14px;
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
    margin-top: 0;
}

.tabbladen {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
}

.tab-knop {
    display: inline-block;
    padding: 8px 16px;
    background: #e2e6ea;
    color: #333;
    text-decoration: none;
    border-radius: 4px;
    font-size: 13px;
    font-weight: bold;
}

.tab-knop:hover {
    background: #d3d9de;
}

.tab-knop.actief {
    background: #1f6fa8;
    color: white;
}

.knop.toevoegen {
    background: #1f6fa8;
    margin-top: 20px;
}

.knop.toevoegen:hover {
    background: #175a87;
}

.advies {
    max-width: 600px;
    margin-top: 10px;
    margin-bottom: 25px;
    padding: 15px 20px;
    background: #fff3cd !important;
    color: #000 !important;
    border: 1px solid #ffe69c;
    border-radius: 6px;
}

.advies h2 {
    margin: 0 0 10px 0;
    font-size: 14px;
    color: #000 !important;
}

.advies ol {
    margin: 0;
    padding-left: 20px;
}

.advies li {
    margin-bottom: 8px;
    color: #000 !important;
}

.advies li:last-child {
    margin-bottom: 0;
}

.advies code {
    background: #ffe69c !important;
    color: #000 !important;
    padding: 1px 5px;
    border-radius: 3px;
}

.tekst-groen { color: var(--thema-groen); }
.tekst-geel { color: var(--thema-geel); }
.tekst-rood { color: var(--thema-rood); }

.categorie-keuze {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 20px;
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
.tekst-grijs { color: var(--thema-uitleg-tekst); }

.formulier-kop {
    font-size: 16px;
    font-weight: bold;
    margin: 0 0 15px 0;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--thema-rand);
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

.sectiekop {
    font-size: 14px;
    font-weight: bold;
    margin-top: 25px;
    margin-bottom: 10px;
    padding-top: 15px;
    border-top: 1px solid #ddd;
}

</style>
<?php include 'responsive_stijlen.php'; ?>
</head>
<body>

<header>
    <div>
        <h1>➕ Site toevoegen</h1>
        <div class="subtitel">Voeg een nieuwe website toe aan de monitor.</div>
    </div>
    <div style="display: flex; gap: 8px;">
        <button type="button" class="knop" onclick="history.back()" style="padding: 8px 12px;" title="Eén stap terug">←</button>
        <a class="knop" href="index.php">Terug naar monitor</a>
    </div>
</header>

<div class="tabbladen">
    <a class="tab-knop" href="configuratie.php">Algemeen</a>
    <a class="tab-knop actief" href="site_toevoegen.php">Site toevoegen</a>
</div>

<button type="button" class="knop" style="background: #6f42c1; margin-bottom: 10px;" onclick="toonAkeebaAdvies(this)">💡 Klik hier als je Akeeba Admin Tools gebruikt</button>

<div id="akeeba-advies" class="advies" style="display: none;">
    <h2>💡 Pas dit eerst aan als je Akeeba Admin Tools gebruikt</h2>
    <div class="waarschuwing" style="margin-bottom: 12px;">
        ⚠️ Elke site heeft een eigen, uniek gegenereerde scanscript-bestandsnaam (zie hoofdstuk 9 van de
        helppagina) - die ken je dus nog niet vóórdat je deze site hieronder hebt toegevoegd. Doorloop dit
        stappenplan daarom in twee delen: stap 1 t/m 4 (het IP-adres) kun je nu alvast doen, maar kom na het
        toevoegen van de site terug voor stap 5 t/m 9, met de daadwerkelijke bestandsnaam die je bij
        Site-instellingen van deze site te zien krijgt. <strong>Wijzig je die bestandsnaam later ooit</strong>
        (via "Vervang door nieuwe naam", of de migratieknop op de configuratiepagina), dan moet je stap 5 t/m 9
        ook opnieuw doen met de nieuwe naam - anders blokkeert Admin Tools het scanscript alsnog.
    </div>
    <ol>
        <li>Zoek het IPv4- en IPv6-adres op van de monitorwebsite.</li>
        <li>Ga in Admin Tools naar <strong>Web Application Firewall</strong> en open <strong>Configure WAF</strong>.</li>
        <li>Open het tabblad <strong>Exceptions</strong>.</li>
        <li>Maak met het groene plus-teken een nieuwe regel en zet daar het IP-adres in, met als omschrijving bijvoorbeeld <code>Website Monitor</code>. Doe dit zowel voor het IPv4- als het IPv6-adres, als die bekend zijn.</li>
        <li>Ga naar de Admin Tools <strong>.htaccess maker</strong>.</li>
        <li>Scroll naar <strong>Server protection</strong>.</li>
        <li><strong>Backend directories where file type exceptions are allowed</strong> — maak daar een nieuwe regel aan voor <code>administrator</code> en plaats daarachter <code>manifests</code>.</li>
        <li>Daaronder staat <strong>Backend file types allowed in selected directories</strong> — type daar <code>xml</code> en druk op Enter om te bevestigen.</li>
        <li>Scroll verder naar beneden tot <strong>Exceptions from Server Protection</strong> → <strong>Allow direct access to these files</strong>.</li>
        <li>Maak daar een nieuwe regel aan en zet in die regel de <strong>exacte, unieke scanscript-bestandsnaam van deze site</strong> (te vinden bij Site-instellingen, ná het toevoegen) - dus niet zomaar <code>scan-en-check-website.php</code>, tenzij dat toevallig ook echt de naam is die daar staat.</li>
        <li>Sla al deze wijzigingen op door linksboven te klikken op de knop <strong>"Save and Create .htaccess"</strong>.</li>
    </ol>
</div>

<?php if ($foutmelding !== ''): ?>
    <div class="melding fout"><?php echo htmlspecialchars($foutmelding); ?></div>
<?php endif; ?>

<form method="post">
    <?php echo csrfVeld(); ?>

    <div class="formulier-kop">📝 Gegevens van de nieuwe website</div>

    <label>Categorie<?php echo hulpIcoon('sites-toevoegen', 'Bepaalt op de indexpagina in welk overzicht deze site verschijnt, en of hij meetelt in de samenvattingsmail over verouderde extensies/beveiligingsissues - dat laatste geldt alleen voor "Eigen websites".'); ?></label>
    <div class="uitleg">
        "Websites van anderen" tellen niet mee in de samenvattingsmail over verouderde extensies/beveiligingsissues -
        zo blijft die mail overzichtelijk voor de sites die je zelf volledig up-to-date en schoon wil houden.
    </div>
    <div class="categorie-keuze">
        <label class="categorie-optie">
            <input type="radio" name="categorie" value="eigen" <?php echo ($_POST['categorie'] ?? 'eigen') === 'eigen' ? 'checked' : ''; ?>>
            <span>🏠 Eigen website</span>
        </label>
        <label class="categorie-optie">
            <input type="radio" name="categorie" value="anderen" <?php echo ($_POST['categorie'] ?? '') === 'anderen' ? 'checked' : ''; ?>>
            <span>👤 Website van een ander</span>
        </label>
    </div>

    <label for="domein">Domein<?php echo hulpIcoon('sites-toevoegen', 'Staan er meerdere, losse Joomla-installaties op hetzelfde hostingaccount (bijv. eigen submappen)? Vul dan de submap achter het domein in, bijv. voorbeeld.nl/submap - het scanscript herkent dan automatisch in welke map het zelf draait.'); ?></label>
    <div class="uitleg">De domeinnaam van de website, zonder "https://" of "www.", bijv. <code>voorbeeld.nl</code>.</div>
    <input type="text" id="domein" name="domein" required placeholder="bijv. voorbeeld.nl" value="<?php echo htmlspecialchars($_POST['domein'] ?? ''); ?>">

    <label for="admin_pad">Admin-pad</label>
    <div class="uitleg">
        Pad naar de administrator-map, relatief aan het domein. Standaard <code>administrator</code>.
        Heeft de site een geheim inlogwoord, vul dan het volledige pad inclusief dat woord in,
        bijv. <code>administrator/?geheimwoord</code>.
    </div>
    <input type="text" id="admin_pad" name="admin_pad" placeholder="administrator" value="<?php echo htmlspecialchars($_POST['admin_pad'] ?? ''); ?>">

    <label for="url_subpad">URL-submap (alleen als de site niet in de webroot zelf staat)</label>
    <div class="uitleg">
        Staat Joomla niet los op het domein, maar in een submap die WEL rechtstreeks via de domeinnaam bereikbaar
        is (bijv. <code>https://voorbeeld.nl/bieb/</code> in plaats van <code>https://voorbeeld.nl/</code>)? Vul dan
        hier die submap in (bijv. <code>bieb</code>). <strong>Let op, dit is iets anders dan het FTP-pad
        hieronder:</strong> het FTP-pad bepaalt alleen waar het scanscript op de schijf terechtkomt, dit veld
        bepaalt via welke URL de monitor het scanscript daarna kan bereiken - die twee hoeven niet overeen te komen.
        Voor verreweg de meeste sites laat je dit gewoon leeg.
    </div>
    <input type="text" id="url_subpad" name="url_subpad" placeholder="(meestal leeg laten)" value="<?php echo htmlspecialchars($_POST['url_subpad'] ?? ''); ?>">

    <div class="uitleg" style="margin-top: 15px;">
        <strong>Scanscript-bestandsnaam:</strong> wordt automatisch en uniek gegenereerd op basis van de domeinnaam
        (bijv. <code>scan-door-compactwebmonitor-a3f9c2.php</code>) - dat is veiliger dan een vaste naam die op elke site
        hetzelfde is. Deze naam staat na het opslaan vast; je ziet 'm terug bij Site-instellingen, waar je 'm
        desgewenst later nog kan vervangen door een nieuwe, opnieuw automatisch gegenereerde naam.
    </div>

    <div class="sectiekop">Extra scanpad (buiten de website-root)</div>
    <div class="uitleg" style="margin-top: 0;">
        Scant automatisch mee tot aan de accountroot van je hostingpakket, voor zover de hostingpartij dat toestaat -
        hoeveel niveaus dat precies zijn, bepaalt het scanscript zelf, elke keer opnieuw, op basis van eigenaarschap
        (nooit verder dan bij dit hostingaccount hoort). Staat altijd aan, hoeft niet apart ingesteld te worden - je
        ziet na de eerste scan bij Site-instellingen precies wat er gedetecteerd is.
    </div>

    <div class="sectiekop">FTP-gegevens (optioneel)</div>
    <div class="uitleg" style="margin-bottom: 10px;">
        Vul dit meteen in als je die bij de hand hebt, dan kun je straks het scanscript met één druk op de knop
        naar deze site versturen, zonder zelf te hoeven downloaden/uploaden. Kun je het nu niet invullen? Geen
        probleem, dat kan altijd nog later via "Site-instellingen".
    </div>

    <label>Protocol</label>
    <div class="uitleg">
        Kies het protocol dat jouw hostingpartij voor bestandsoverdracht gebruikt. Weet je dit niet zeker? Dat staat
        meestal gewoon bij de FTP/SFTP-gegevens die je van de hostingpartij hebt gekregen.
    </div>
    <div style="display: flex; gap: 20px; margin-bottom: 15px;">
        <label style="display: flex; align-items: center; gap: 6px; font-weight: normal;">
            <input type="radio" name="ftp_protocol" value="ftp" <?php echo (($_POST['ftp_protocol'] ?? 'ftp') === 'ftp') ? 'checked' : ''; ?> onchange="protocolGewijzigd(this)" style="width: auto;">
            FTP
        </label>
        <label style="display: flex; align-items: center; gap: 6px; font-weight: normal;">
            <input type="checkbox" id="ftp_ssl" name="ftp_ssl" value="1" <?php echo isset($_POST['ftp_ssl']) ? 'checked' : ''; ?> style="width: auto;">
            <span>waarvan beveiligd (FTPS)</span>
        </label>
        <label style="display: flex; align-items: center; gap: 6px; font-weight: normal;">
            <input type="radio" name="ftp_protocol" value="sftp" <?php echo (($_POST['ftp_protocol'] ?? '') === 'sftp') ? 'checked' : ''; ?> onchange="protocolGewijzigd(this)" style="width: auto;">
            SFTP
        </label>
    </div>

    <label for="ftp_host">Server</label>
    <div class="uitleg">Bijv. <code>ftp.voorbeeld.nl</code>, <code>ssh.voorbeeld.nl</code>, of een IP-adres.</div>
    <input type="text" id="ftp_host" name="ftp_host" placeholder="ftp.voorbeeld.nl" value="<?php echo htmlspecialchars($_POST['ftp_host'] ?? ''); ?>">

    <div class="ftp-rij">
        <div>
            <label for="ftp_gebruikersnaam">Gebruikersnaam</label>
            <input type="text" id="ftp_gebruikersnaam" name="ftp_gebruikersnaam" value="<?php echo htmlspecialchars($_POST['ftp_gebruikersnaam'] ?? ''); ?>">
        </div>
        <div>
            <label for="ftp_poort">Poort</label>
            <input type="text" id="ftp_poort" name="ftp_poort" value="<?php echo htmlspecialchars($_POST['ftp_poort'] ?? '21'); ?>">
        </div>
    </div>

    <label for="ftp_wachtwoord">Wachtwoord</label>
    <div class="wachtwoord-veld">
        <input type="password" id="ftp_wachtwoord" name="ftp_wachtwoord" value="<?php echo htmlspecialchars($_POST['ftp_wachtwoord'] ?? ''); ?>" autocomplete="new-password">
        <button type="button" class="oogje" onclick="toonWachtwoord('ftp_wachtwoord', this)"><span class="icoon-glyph">👁️</span></button>
    </div>

    <label for="ftp_pad">Pad op de server</label>
    <div class="uitleg">
        De map waar <code>scan-en-check-website.php</code> in geplaatst moet worden (dezelfde map als
        <code>configuration.php</code> van Joomla), bijv. <code>/public_html</code> of gewoon <code>/</code>.
        Weet je dit niet zeker (sommige hostingpartijen geven toegang 2-3 mappen boven <code>public_html</code>)?
        Vul dan hierboven de gegevens in en klik op de zoekknop.
    </div>
    <div style="display: flex; gap: 8px;">
        <input type="text" id="ftp_pad" name="ftp_pad" placeholder="/public_html" value="<?php echo htmlspecialchars($_POST['ftp_pad'] ?? '/'); ?>" style="flex: 1;">
        <button type="button" class="knop" style="background: #17a2b8; white-space: nowrap;" onclick="zoekFtpPad(this)">🔍 Zoek automatisch</button>
    </div>
    <div id="ftp-pad-resultaat" style="display: none; margin-top: 8px; font-size: 12px;"></div>

    <div class="uitleg" style="margin-top: 15px;">
        FTPS gebruikt gewoon <strong>poort 21</strong>. SFTP gebruikt meestal <strong>poort 22</strong> - dat is een
        écht ander protocol (gebaseerd op SSH), dat we nu ook rechtstreeks ondersteunen.
    </div>

    <div class="uitleg" style="margin-top: 20px; padding: 10px 12px; background: #fff3cd !important; border: 1px solid #ffe69c; border-radius: 4px; color: #664d03 !important;">
        ⚠️ Vergeet niet om het scanscript-bestand op deze site te zetten (handmatig via FTP, of automatisch met de
        FTP-knop op de site-instellingenpagina als je de gegevens hierboven hebt ingevuld) — anders kunnen de
        beveiligings- en extensiescans niet draaien. De automatisch gegenereerde bestandsnaam zie je meteen na het
        opslaan terug op de site-instellingenpagina, inclusief een downloadknop met precies die naam erin verwerkt.
    </div>

    <button type="submit" class="knop toevoegen" id="opslaan-knop">Opslaan</button>

</form>

<?php include 'terug_naar_boven.php'; ?>
</body>

<script>
const CSRF_TOKEN = <?php echo json_encode(haalCsrfToken()); ?>;
const SITE_ID = 0;

function huidigProtocol() {
    const gekozen = document.querySelector('input[name="ftp_protocol"]:checked');
    return gekozen ? gekozen.value : 'ftp';
}

function protocolGewijzigd(radio) {
    const poortVeld = document.getElementById('ftp_poort');
    const ftpSslLabel = document.getElementById('ftp_ssl').closest('label');

    if (radio.value === 'sftp') {
        // Alleen de standaardpoort invullen als het veld nog leeg is of nog op
        // de andere standaardwaarde staat - een handmatig ingevulde afwijkende
        // poort blijven we met rust laten.
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

function zoekFtpPad(knop) {
    knop.disabled = true;

    const resultaat = document.getElementById('ftp-pad-resultaat');
    resultaat.style.display = 'block';
    resultaat.className = 'tekst-grijs';
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
    body.append('domein', document.getElementById('domein').value);

    fetch('ftp_detecteer_pad2.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
        .then(r => r.json())
        .then(data => {
            if (data.succes) {
                document.getElementById('ftp_pad').value = data.pad;
                werkOpslaanKnopBij();
                if (data.waarschuwing) {
                    resultaat.className = 'tekst-geel';
                    resultaat.textContent = '⚠️ Gevonden: "' + data.pad + '", maar: ' + data.waarschuwing;
                } else {
                    resultaat.className = 'tekst-groen';
                    resultaat.textContent = '✅ Gevonden: "' + data.pad + '" - is meteen ingevuld hierboven.';
                }
            } else {
                resultaat.className = 'tekst-rood';
                resultaat.textContent = '❌ ' + data.foutmelding;
            }
            knop.disabled = false;
        })
        .catch(err => {
            resultaat.className = 'tekst-rood';
            resultaat.textContent = '❌ Er ging iets mis: ' + err.message;
            knop.disabled = false;
        });
}

function werkOpslaanKnopBij() {
    const knop = document.getElementById('opslaan-knop');
    if (!knop) {
        return;
    }

    const host = document.getElementById('ftp_host').value.trim();
    const gebruikersnaam = document.getElementById('ftp_gebruikersnaam').value.trim();
    const wachtwoord = document.getElementById('ftp_wachtwoord').value.trim();
    const pad = document.getElementById('ftp_pad').value.trim();

    // Alleen "slim" als er ook echt een specifiek pad bekend is (niet de
    // kale standaardwaarde "/") - anders weten we nog niet waar het
    // scanscript precies geplaatst moet worden.
    const ftpCompleet = host !== '' && gebruikersnaam !== '' && wachtwoord !== '' && pad !== '' && pad !== '/';

    knop.textContent = ftpCompleet ? 'Opslaan en FTP-bestand versturen' : 'Opslaan';
}

document.addEventListener('DOMContentLoaded', function () {
    const gekozen = document.querySelector('input[name="ftp_protocol"]:checked');
    if (gekozen) {
        protocolGewijzigd(gekozen);
    }

    ['ftp_host', 'ftp_gebruikersnaam', 'ftp_wachtwoord', 'ftp_pad'].forEach(function (veldId) {
        const veld = document.getElementById(veldId);
        if (veld) {
            veld.addEventListener('input', werkOpslaanKnopBij);
        }
    });

    werkOpslaanKnopBij();
});

function toonAkeebaAdvies(knop) {
    const advies = document.getElementById('akeeba-advies');
    const opengeklapt = advies.style.display === 'block';

    advies.style.display = opengeklapt ? 'none' : 'block';
    knop.textContent = opengeklapt
        ? '💡 Klik hier als je Akeeba Admin Tools gebruikt'
        : '💡 Verberg de Akeeba Admin Tools-instructies';
}

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
</script>

</html>
