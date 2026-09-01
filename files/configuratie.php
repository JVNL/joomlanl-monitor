<?php
require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: login.php");
    exit;
}
require_once 'config.php';
require_once 'versie.php';
require_once 'instellingen_functies.php';
require_once 'csrf_functies.php';

$foutmelding   = '';
$succesmelding = '';
$toonScanScriptWaarschuwing = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vereistGeldigCsrfToken();
    $actie = $_POST['actie'] ?? '';

    // ------------------------------------------------------------------
    // E-mailinstellingen: welke soorten meldingen wel/niet in de
    // notificatiemail worden opgenomen.
    // ------------------------------------------------------------------
    if ($actie === 'email_instellingen_opslaan') {
        $afzenderNaam = trim($_POST['email_afzendernaam'] ?? '');

        $websiteAan = isset($_POST['email_website_status_enabled']) ? '1' : '0';

        $toegestaneCriteria = ['geen_verbinding', 'http_fout', 'verdacht'];
        $gekozenCriteria    = array_intersect((array) ($_POST['email_website_criteria'] ?? []), $toegestaneCriteria);
        $criteriaWaarde     = implode(',', $gekozenCriteria);

        $joomlaAan      = isset($_POST['email_joomla_enabled']) ? '1' : '0';
        $extensiesAan   = isset($_POST['email_extensies_enabled']) ? '1' : '0';
        $sslAan         = isset($_POST['email_ssl_enabled']) ? '1' : '0';
        $beveiligingAan = isset($_POST['email_beveiliging_enabled']) ? '1' : '0';
        $alleenBijCron  = isset($_POST['email_alleen_bij_cron']) ? '1' : '0';

        slaInstellingOp($pdo, 'email_afzendernaam', $afzenderNaam);
        slaInstellingOp($pdo, 'email_website_status_enabled', $websiteAan);
        slaInstellingOp($pdo, 'email_website_criteria', $criteriaWaarde);
        slaInstellingOp($pdo, 'email_joomla_enabled', $joomlaAan);
        slaInstellingOp($pdo, 'email_extensies_enabled', $extensiesAan);
        slaInstellingOp($pdo, 'email_ssl_enabled', $sslAan);
        slaInstellingOp($pdo, 'email_beveiliging_enabled', $beveiligingAan);
        slaInstellingOp($pdo, 'email_alleen_bij_cron', $alleenBijCron);

        $succesmelding = 'E-mailinstellingen opgeslagen.';
    }

    // ------------------------------------------------------------------
    // Algemene instellingen (e-mail, geheime code, monitor-URL, login).
    // ------------------------------------------------------------------
    if ($actie === 'instellingen_opslaan') {
        $email        = trim($_POST['notificatie_email'] ?? '');
        $geheimeCode  = trim($_POST['geheime_code'] ?? '');
        $cronCode     = trim($_POST['cron_geheime_code'] ?? '');
        $monitorUrl   = rtrim(trim($_POST['monitor_basis_url'] ?? ''), '/');
        $loginGebruik = trim($_POST['login_gebruikersnaam'] ?? '');
        $loginWachtwoord = $_POST['login_wachtwoord'] ?? '';

        if ($email === '' || $geheimeCode === '' || $cronCode === '' || $monitorUrl === '' || $loginGebruik === '' || $loginWachtwoord === '') {
            $foutmelding = 'Vul alle velden in.';
        } else {
            // Oude waarden ophalen VOORDAT we overschrijven, om te bepalen of
            // er iets is gewijzigd dat een nieuw scanscript op de sites vereist.
            $oudeGeheimeCode = ontsleutelWaarde(haalInstelling($pdo, 'geheime_code', ''));
            $oudeMonitorUrl  = haalInstelling($pdo, 'monitor_basis_url', '');
            $oudeCronCode    = ontsleutelWaarde(haalInstelling($pdo, 'cron_geheime_code', ''));

            $scanScriptGewijzigd = ($geheimeCode !== $oudeGeheimeCode) || ($monitorUrl !== $oudeMonitorUrl) || ($cronCode !== $oudeCronCode);

            slaInstellingOp($pdo, 'notificatie_email', $email);
            slaInstellingOp($pdo, 'geheime_code', versleutelWaarde($geheimeCode));
            slaInstellingOp($pdo, 'cron_geheime_code', versleutelWaarde($cronCode));
            slaInstellingOp($pdo, 'monitor_basis_url', $monitorUrl);
            slaInstellingOp($pdo, 'login_gebruikersnaam', $loginGebruik);
            slaInstellingOp($pdo, 'login_wachtwoord', versleutelWaarde($loginWachtwoord));

            $succesmelding = 'Instellingen opgeslagen.';

            if ($scanScriptGewijzigd) {
                $toonScanScriptWaarschuwing = true;
            }
        }
    }

    // ------------------------------------------------------------------
    // Databasegegevens: eerst testen, pas dan config.php overschrijven.
    // ------------------------------------------------------------------
    if ($actie === 'db_opslaan') {
        $nieuweHost = trim($_POST['db_host'] ?? '');
        $nieuweName = trim($_POST['db_name'] ?? '');
        $nieuweUser = trim($_POST['db_user'] ?? '');
        $nieuwePass = $_POST['db_pass'] ?? '';

        if ($nieuweHost === '' || $nieuweName === '' || $nieuweUser === '' || $nieuwePass === '') {
            $foutmelding = 'Vul host, databasenaam, gebruikersnaam en wachtwoord in.';
        } else {
            try {
                $testPdo = new PDO(
                    "mysql:host={$nieuweHost};dbname={$nieuweName};charset=utf8mb4",
                    $nieuweUser,
                    $nieuwePass,
                    [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                unset($testPdo);

                $nieuweInhoud = "<?php\n"
                    . "require_once __DIR__ . '/versleuteling_functies.php';\n\n"
                    . '$db_host = ' . var_export($nieuweHost, true) . ";\n"
                    . '$db_name = ' . var_export($nieuweName, true) . ";\n"
                    . '$db_user = ' . var_export($nieuweUser, true) . ";\n"
                    . '$db_pass_versleuteld = ' . var_export(versleutelWaarde($nieuwePass), true) . ";\n"
                    . "\$db_pass = ontsleutelWaarde(\$db_pass_versleuteld);\n"
                    . "try {\n"
                    . "    \$pdo = new PDO(\n"
                    . "        \"mysql:host=\$db_host;dbname=\$db_name;charset=utf8mb4\",\n"
                    . "        \$db_user,\n"
                    . "        \$db_pass\n"
                    . "    );\n"
                    . "    \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);\n"
                    . "} catch (PDOException \$e) {\n"
                    . "    die(\"PDO FOUT: \" . \$e->getMessage());\n"
                    . "}\n"
                    . "?>\n";

                if (file_put_contents(__DIR__ . '/config.php', $nieuweInhoud) !== false) {
                    $succesmelding = 'Databasegegevens getest en opgeslagen.';
                    $db_host = $nieuweHost;
                    $db_name = $nieuweName;
                    $db_user = $nieuweUser;
                    $db_pass = $nieuwePass;
                } else {
                    $foutmelding = 'De verbinding werkte, maar config.php kon niet worden weggeschreven (controleer bestandsrechten).';
                }
            } catch (PDOException $e) {
                $foutmelding = 'Verbinden met deze gegevens is mislukt - er is niets gewijzigd: ' . $e->getMessage();
            }
        }
    }
}

$instellingen = haalAlleInstellingen($pdo);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include 'favicon_tags.php'; ?>
<title>Configuratie</title>
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

h2 {
    font-size: 15px;
    margin: 0 0 5px 0;
}

.subtitel {
    color: #555;
    margin-bottom: 20px;
}

.blok {
    max-width: 550px;
    margin-bottom: 25px;
    padding: 20px;
    border: 1px solid #ddd;
    background: #f5f5f5;
    border-radius: 6px;
}

.blok-uitleg {
    color: #666;
    font-size: 12px;
    margin-bottom: 15px;
}

.optie {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin-top: 15px;
}

.optie:first-of-type {
    margin-top: 0;
}

.optie input[type="checkbox"] {
    margin-top: 3px;
}

.optie-tekst strong {
    display: block;
}

.optie-tekst .uitleg {
    margin: 2px 0 0 0;
}

.subopties {
    margin: 8px 0 0 26px;
    padding: 10px 12px;
    background: #eef1f4;
    border-radius: 4px;
}

.subopties label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: normal;
    margin-top: 6px;
    font-size: 12px;
}

.subopties label:first-of-type {
    margin-top: 0;
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

input[type="text"],
input[type="password"] {
    width: 100%;
    padding: 8px;
    box-sizing: border-box;
    font-size: 13px;
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

.acties {
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-width: 300px;
}

</style>
<?php include 'responsive_stijlen.php'; ?>
</head>
<body>

<header>
    <div>
        <h1>⚙️ Configuratie</h1>
        <div class="subtitel">Beheerinstellingen voor de monitor.</div>
    </div>
    <a class="knop" href="index.php">← Terug naar overzicht</a>
</header>

<div class="tabbladen">
    <a class="tab-knop actief" href="configuratie.php">Algemeen</a>
    <a class="tab-knop" href="site_toevoegen.php">Site toevoegen</a>
</div>

<?php if ($succesmelding !== ''): ?>
    <div class="melding ok"><?php echo htmlspecialchars($succesmelding); ?></div>
<?php endif; ?>
<?php if ($foutmelding !== ''): ?>
    <div class="melding fout"><?php echo htmlspecialchars($foutmelding); ?></div>
<?php endif; ?>

<?php if ($toonScanScriptWaarschuwing): ?>
<div class="melding" style="background: #fff3cd; color: #664d03; border: 1px solid #ffe69c; padding: 15px 18px;">
    <strong>⚠️ Belangrijk: het scanscript moet opnieuw naar alle sites gestuurd worden.</strong>
    <div style="margin: 8px 0 12px 0;">
        Je hebt zojuist de geheime code, de cron-beveiligingscode of het monitor-pad gewijzigd. Deze waarden staan
        verwerkt in <code>scan-en-check-website.php</code> op elke site - zolang dat niet is bijgewerkt, blijven de
        oude scanscripts op de sites de <strong>oude</strong> waarden gebruiken, en werkt de koppeling niet meer.
    </div>
    <button type="button" class="knop" style="background: #6f42c1;" onclick="verstuurFtpAlleSites(this, 'ftp-resultaat-boven')">🚀 Verstuur nu automatisch naar alle sites met FTP-gegevens</button>
    <div style="margin-top: 10px; font-size: 12px;">
        Heeft een site nog geen FTP-gegevens ingevuld? Download dan bij "Site-scanscript" hieronder een nieuwe versie,
        en zet die zelf via FTP op die site(s).
    </div>
    <div id="ftp-resultaat-boven" style="display: none; margin-top: 12px; padding: 10px 14px; border-radius: 4px; font-size: 12px; white-space: pre-wrap; font-family: monospace; background: #eef1f4; border: 1px solid #ddd;"></div>
</div>
<?php endif; ?>

<div class="blok">
    <h2>E-mailinstellingen</h2>
    <div class="blok-uitleg">
        Bepaal hier welke soorten meldingen worden opgenomen in de notificatiemail. Die mail wordt automatisch
        verstuurd als laatste stap na "Scan en check sites" (ook via de cronjob), en alleen als er ook
        daadwerkelijk iets te melden is.
    </div>
    <form method="post">
        <input type="hidden" name="actie" value="email_instellingen_opslaan">
        <?php echo csrfVeld(); ?>

        <label for="email_afzendernaam">Naam van de monitor / afzendernaam voor e-mail</label>
        <div class="uitleg">
            Deze naam wordt op twee plekken gebruikt: als afzendernaam in de mailclient van de ontvanger van
            notificatiemails (i.p.v. kaal het e-mailadres), én als titel van dit programma zelf (linksboven op de
            overzichtspagina en in het browsertabblad). Laat leeg voor de standaardnaam "Mijn Websites Monitor".
        </div>
        <input type="text" id="email_afzendernaam" name="email_afzendernaam" placeholder="Mijn Websites Monitor" value="<?php echo htmlspecialchars($instellingen['email_afzendernaam'] ?? ''); ?>" style="margin-bottom: 20px;">


        <div class="optie">
            <input type="checkbox" id="email_website_status_enabled" name="email_website_status_enabled" value="1" <?php echo (($instellingen['email_website_status_enabled'] ?? '0') === '1') ? 'checked' : ''; ?>>
            <div class="optie-tekst">
                <strong>Website status (online/offline)</strong>
                <div class="uitleg">Meldt in de mail als een website tegen één van de onderstaande situaties aanloopt.</div>
                <div class="subopties">
                    <?php
                    $huidigeCriteria = explode(',', $instellingen['email_website_criteria'] ?? '');
                    $criteriaOpties = [
                        'geen_verbinding' => 'Geen verbinding mogelijk (offline)',
                        'http_fout'       => 'HTTP-foutcode (403, 500, enz.)',
                        'verdacht'        => 'Verdachte inhoud gevonden',
                    ];
                    foreach ($criteriaOpties as $waarde => $label):
                    ?>
                    <label>
                        <input type="checkbox" name="email_website_criteria[]" value="<?php echo $waarde; ?>" <?php echo in_array($waarde, $huidigeCriteria, true) ? 'checked' : ''; ?>>
                        <?php echo htmlspecialchars($label); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="optie">
            <input type="checkbox" id="email_joomla_enabled" name="email_joomla_enabled" value="1" <?php echo (($instellingen['email_joomla_enabled'] ?? '0') === '1') ? 'checked' : ''; ?>>
            <div class="optie-tekst">
                <strong>Joomla-versie</strong>
                <div class="uitleg">Meldt als er een nieuwere Joomla-versie beschikbaar is dan wat er geïnstalleerd is, inclusief het versienummer.</div>
            </div>
        </div>

        <div class="optie">
            <input type="checkbox" id="email_extensies_enabled" name="email_extensies_enabled" value="1" <?php echo (($instellingen['email_extensies_enabled'] ?? '0') === '1') ? 'checked' : ''; ?>>
            <div class="optie-tekst">
                <strong>Extensies</strong>
                <div class="uitleg">Meldt als er extensies niet up-to-date zijn, met het aantal verouderde extensies.</div>
            </div>
        </div>

        <div class="optie">
            <input type="checkbox" id="email_ssl_enabled" name="email_ssl_enabled" value="1" <?php echo (($instellingen['email_ssl_enabled'] ?? '0') === '1') ? 'checked' : ''; ?>>
            <div class="optie-tekst">
                <strong>SSL-status</strong>
                <div class="uitleg">Meldt alleen als een SSL-certificaat daadwerkelijk verlopen is (niet bij "bijna verlopen").</div>
            </div>
        </div>

        <div class="optie">
            <input type="checkbox" id="email_beveiliging_enabled" name="email_beveiliging_enabled" value="1" <?php echo (($instellingen['email_beveiliging_enabled'] ?? '0') === '1') ? 'checked' : ''; ?>>
            <div class="optie-tekst">
                <strong>Beveiliging</strong>
                <div class="uitleg">Meldt het aantal verdachte (niet-vertrouwde) bestanden - als vertrouwd gemarkeerde items tellen niet mee.</div>
            </div>
        </div>

        <div class="optie" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;">
            <input type="checkbox" id="email_alleen_bij_cron" name="email_alleen_bij_cron" value="1" <?php echo (($instellingen['email_alleen_bij_cron'] ?? '0') === '1') ? 'checked' : ''; ?>>
            <div class="optie-tekst">
                <strong>Alleen e-mail versturen bij een cronjob</strong>
                <div class="uitleg">Als dit aan staat, wordt er nooit een e-mail verstuurd na een handmatige druk op "Scan en check sites" (je ziet het resultaat dan toch al meteen op het scherm) - alleen als de scan via de cronjob is gestart.</div>
            </div>
        </div>

        <button type="submit" class="knop opslaan">Opslaan</button>
    </form>
</div>

<div class="blok">
    <h2>Algemene instellingen</h2>
    <div class="blok-uitleg">Deze waarden worden centraal opgeslagen en door de scripts uitgelezen in plaats van hardgecodeerd te staan.</div>
    <form method="post">
        <input type="hidden" name="actie" value="instellingen_opslaan">
        <?php echo csrfVeld(); ?>

        <label for="notificatie_email">E-mailadres voor notificaties</label>
        <div class="uitleg">Hierheen wordt een waarschuwing gestuurd als er sites op "rood" staan.</div>
        <input type="text" id="notificatie_email" name="notificatie_email" required value="<?php echo htmlspecialchars($instellingen['notificatie_email'] ?? ''); ?>">

        <label for="geheime_code">Geheime code</label>
        <div class="uitleg">Moet exact overeenkomen met de code in <code>scan-en-check-website.php</code> op elke site. Download hieronder een nieuwe versie na wijziging.</div>
        <input type="text" id="geheime_code" name="geheime_code" required value="<?php echo htmlspecialchars(ontsleutelWaarde($instellingen['geheime_code'] ?? '')); ?>">

        <label for="cron_geheime_code">Cron-beveiligingscode</label>
        <div class="uitleg">
            Beveiligt <code>check_sites.php</code>, <code>start_scan.php</code>, <code>haal_versies_op.php</code> en
            <code>cron_alles_scannen.php</code> tegen willekeurige bezoekers - deze bestanden checken namelijk geen
            login (dat kan niet, want een cronjob heeft geen sessie). Wijzig je deze code, werk dan ook het
            commando in je cronjob bij (zie de helppagina).
        </div>
        <input type="text" id="cron_geheime_code" name="cron_geheime_code" required value="<?php echo htmlspecialchars(ontsleutelWaarde($instellingen['cron_geheime_code'] ?? '')); ?>">

        <label for="monitor_basis_url">Pad naar de monitorsite</label>
        <div class="uitleg">De basis-URL van deze monitor, zonder afsluitende slash, bijv. <code>https://compactweb.nl/00-beheer</code>.</div>
        <input type="text" id="monitor_basis_url" name="monitor_basis_url" required value="<?php echo htmlspecialchars($instellingen['monitor_basis_url'] ?? ''); ?>">

        <label for="login_gebruikersnaam">Inlognaam monitor</label>
        <div class="uitleg">De gebruikersnaam waarmee je zelf op deze monitor inlogt.</div>
        <input type="text" id="login_gebruikersnaam" name="login_gebruikersnaam" required value="<?php echo htmlspecialchars($instellingen['login_gebruikersnaam'] ?? ''); ?>">

        <label for="login_wachtwoord">Inlogwachtwoord monitor</label>
        <div class="uitleg">Klik op het oogje om het huidige wachtwoord te zien, of pas het direct aan.</div>
        <div class="wachtwoord-veld">
            <input type="password" id="login_wachtwoord" name="login_wachtwoord" value="<?php echo htmlspecialchars(ontsleutelWaarde($instellingen['login_wachtwoord'] ?? '')); ?>" autocomplete="new-password">
            <button type="button" class="oogje" onclick="toonWachtwoord('login_wachtwoord', this)"><span class="icoon-glyph">👁️</span></button>
        </div>

        <button type="submit" class="knop opslaan">Opslaan</button>
    </form>
</div>

<div class="blok">
    <h2>Database-gegevens</h2>
    <div class="blok-uitleg">
        Deze gegevens worden vóór het opslaan eerst getest - lukt de verbinding niet, dan wordt er niets gewijzigd,
        zodat je jezelf niet per ongeluk kan buitensluiten.
    </div>
    <form method="post">
        <input type="hidden" name="actie" value="db_opslaan">
        <?php echo csrfVeld(); ?>

        <label for="db_host">Host</label>
        <input type="text" id="db_host" name="db_host" required value="<?php echo htmlspecialchars($db_host); ?>">

        <label for="db_name">Databasenaam</label>
        <input type="text" id="db_name" name="db_name" required value="<?php echo htmlspecialchars($db_name); ?>">

        <label for="db_user">Gebruikersnaam</label>
        <input type="text" id="db_user" name="db_user" required value="<?php echo htmlspecialchars($db_user); ?>">

        <label for="db_pass">Wachtwoord</label>
        <div class="uitleg">Klik op het oogje om het huidige wachtwoord te zien, of pas het direct aan.</div>
        <div class="wachtwoord-veld">
            <input type="password" id="db_pass" name="db_pass" value="<?php echo htmlspecialchars($db_pass); ?>" autocomplete="new-password">
            <button type="button" class="oogje" onclick="toonWachtwoord('db_pass', this)"><span class="icoon-glyph">👁️</span></button>
        </div>

        <button type="submit" class="knop opslaan">Opslaan</button>
    </form>
</div>

<div class="blok">
    <h2>Site-scanscript</h2>
    <div class="blok-uitleg">
        <code>scan-en-check-website.php</code> draait op elke afzonderlijke site en kan daardoor niet live uit deze
        database lezen. Download hier een kant-en-klare versie met de huidige geheime code en het huidige
        monitor-pad erin verwerkt, en zet die op elke site (via FTP).
    </div>
    <a class="knop download" href="download_scan_script.php">⬇️ Download scan-en-check-website.php</a>

    <div class="blok-uitleg" style="margin-top: 20px;">
        Heb je bij één of meer sites (via "⚙️ Site-instellingen") FTP-gegevens ingevuld? Dan kun je het scanscript
        ook in één keer naar al die sites tegelijk versturen, zonder los te downloaden/uploaden.
    </div>
    <button type="button" class="knop" style="background: #6f42c1;" onclick="verstuurFtpAlleSites(this)">🚀 Verstuur scanscript via FTP naar alle sites met FTP-gegevens</button>
    <div id="ftp-resultaat" style="display: none; margin-top: 12px; padding: 10px 14px; border-radius: 4px; font-size: 12px; white-space: pre-wrap; font-family: monospace; background: #eef1f4; border: 1px solid #ddd;"></div>
</div>

<div class="blok">
    <h2>🗄️ Back-up maken</h2>
    <div class="blok-uitleg">
        Download voor de zekerheid een kopie van de monitor - los van de hostingpartij om. Dit levert twee losse
        bestanden op: alle PHP-broncode (als .zip) en alle database-tabellen inclusief hun inhoud (als .sql-bestand,
        rechtstreeks te importeren in bijv. phpMyAdmin bij een herstel).
    </div>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <form method="post" action="maak_backup.php">
            <?php echo csrfVeld(); ?>
            <input type="hidden" name="type" value="php">
            <button type="submit" class="knop download">⬇️ Download alle PHP-bestanden (.zip)</button>
        </form>
        <form method="post" action="maak_backup.php">
            <?php echo csrfVeld(); ?>
            <input type="hidden" name="type" value="database">
            <button type="submit" class="knop download">⬇️ Download database-backup (.sql)</button>
        </form>
    </div>
    <div class="blok-uitleg" style="margin-top: 15px;">
        Let op: het PHP-bestand bevat ook <code>config.php</code> en <code>geheime_sleutel.php</code>, dus inclusief
        je databasewachtwoord en versleutelingssleutel - bewaar deze back-up daarom net zo zorgvuldig als je andere
        wachtwoorden.
    </div>
</div>

<div class="blok">
    <h2>📦 Installatie- en updatepakket</h2>
    <div class="blok-uitleg" style="margin-bottom: 10px;">
        Huidige versie: <strong>v<?php echo htmlspecialchars(MONITOR_VERSIE); ?></strong>
        (<a href="toon_changelog.php" target="_blank">wijzigingslogboek bekijken</a>)
    </div>
    <?php if (is_readable(__DIR__ . '/maak_installatiepakket.php') && is_readable(__DIR__ . '/maak_updatepakket.php') && is_readable(__DIR__ . '/pakket_voorbereiden.php')): ?>
    <div class="blok-uitleg">
        Wil je deze monitor met iemand anders delen, of bij iemand die 'm al gebruikt een update installeren? Stel
        hieronder het juiste pakket samen - beide bevatten <strong>geen</strong> persoonlijke gegevens (geen
        wachtwoorden, geen sites, geen eigen instellingen). Deze mogelijkheid zelf wordt bewust niet meegestuurd in
        zo'n pakket - alleen jij kan dus nieuwe installatie-/updatepakketten maken.
    </div>
    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px;">
        <form method="post" action="pakket_voorbereiden.php">
            <?php echo csrfVeld(); ?>
            <input type="hidden" name="pakket_type" value="installatie">
            <button type="submit" class="knop download">⬇️ Nieuw installatiepakket (voor iemand die nog begint)</button>
        </form>
        <form method="post" action="pakket_voorbereiden.php">
            <?php echo csrfVeld(); ?>
            <input type="hidden" name="pakket_type" value="update">
            <button type="submit" class="knop" style="background: #6f42c1;">⬇️ Updatepakket (voor bestaande gebruikers)</button>
        </form>
    </div>
    <div class="blok-uitleg" style="margin-top: 15px;">
        Het installatiepakket bevat een installatiewizard die de database automatisch inricht. Het updatepakket
        bevat alleen de broncode - de ontvanger hoeft die alleen te uploaden; de database werkt zichzelf daarna
        automatisch bij, zonder handmatige SQL-import.
    </div>
    <?php endif; ?>
</div>

<?php include 'terug_naar_boven.php'; ?>
</body>

<script>
const CSRF_TOKEN = <?php echo json_encode(haalCsrfToken()); ?>;

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

function verstuurFtpAlleSites(knop, resultaatId) {
    knop.disabled = true;

    const resultaat = document.getElementById(resultaatId || 'ftp-resultaat');
    resultaat.style.display = 'block';
    resultaat.textContent = '⏳ Bezig met versturen via FTP naar alle sites met FTP-gegevens...';

    const body = new URLSearchParams();
    body.append('csrf_token', CSRF_TOKEN);

    fetch('ftp_verstuur_scanscript.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
        .then(r => r.text())
        .then(tekst => {
            resultaat.textContent = tekst;
            knop.disabled = false;
        })
        .catch(err => {
            resultaat.textContent = '❌ Er ging iets mis: ' + err.message;
            knop.disabled = false;
        });
}
</script>

</html>