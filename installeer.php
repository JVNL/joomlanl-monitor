<?php
// installeer.php
//
// Dit bestand hoort ALLEEN bij een gedownload installatiepakket - het maakt
// geen deel uit van de normale, dagelijkse werking van de monitor. Na een
// geslaagde installatie vergrendelt dit script zichzelf automatisch (schrijft
// een slotbestand weg), en weigert daarna nog te draaien. Verwijder dit
// bestand voor de zekerheid sowieso zelf ook nog handmatig na installatie -
// zie LEES_DIT_EERST.txt.

error_reporting(E_ALL);
ini_set('display_errors', 1);

$slotBestand = __DIR__ . '/installatie.voltooid';

/**
 * PDO/MySQL-foutmeldingen zijn altijd in het Engels (die komen rechtstreeks
 * van de databaseserver zelf, niet van onze eigen code) - deze functie
 * herkent de meest voorkomende gevallen en zet er een duidelijke,
 * Nederlandse uitleg bovenop. De originele, technische foutmelding blijft
 * er gewoon bij staan (handig bij het vragen om hulp).
 */
function vertaalDatabaseFoutmelding(PDOException $e): string
{
    $code = $e->getCode();
    $bericht = $e->getMessage();

    if ($code === 1045 || stripos($bericht, 'Access denied for user') !== false) {
        $uitleg = 'De combinatie van gebruikersnaam en wachtwoord klopt niet, of deze gebruiker heeft geen '
            . 'rechten op deze database. Controleer de gegevens die je van je hostingpartij hebt gekregen '
            . '(let op hoofdletters - deze zijn hoofdlettergevoelig).';
    } elseif ($code === 1049 || stripos($bericht, 'Unknown database') !== false) {
        $uitleg = 'Deze database bestaat niet. Heb je die al aangemaakt bij je hostingpartij? De naam moet '
            . 'exact overeenkomen (ook qua hoofdletters).';
    } elseif ($code === 2002 || stripos($bericht, 'No such file or directory') !== false || stripos($bericht, 'Connection refused') !== false) {
        $uitleg = 'Kon geen verbinding maken met de databaseserver op dit adres. Klopt het ingevulde server-adres? '
            . 'Dit is meestal gewoon "localhost", maar sommige hostingpartijen gebruiken hier iets anders voor.';
    } elseif (stripos($bericht, "Name or service not known") !== false || stripos($bericht, 'getaddrinfo') !== false) {
        $uitleg = 'Het ingevulde server-adres kon niet worden gevonden - controleer of dit klopt.';
    } else {
        $uitleg = 'Controleer de ingevulde servernaam, databasenaam, gebruikersnaam en wachtwoord.';
    }

    return $uitleg . " (technische foutmelding: $bericht)";
}

if (file_exists($slotBestand)) {
    // Expliciet verzoek (knop op de succespagina) om dit bestand nu zelf op
    // te ruimen - alleen mogelijk NA een geslaagde installatie (dus als het
    // slotbestand al bestaat), nooit ervoor. PHP kan het eigen, op dit
    // moment nog uitvoerende bestand gewoon verwijderen; dat is normaal
    // gedrag op zowel Linux als Windows-servers.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['actie'] ?? '') === 'verwijder_zelf') {
        if (@unlink(__FILE__)) {
            echo <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>installeer.php verwijderd</title>
<style>
body { font-family: Arial, sans-serif; margin: 0; padding: 40px 20px; background: #f5f5f5; }
.kader { max-width: 480px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; }
.melding { padding: 14px; border-radius: 4px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; font-size: 15px; }
.uitleg { color: #666; font-size: 13px; margin-top: 15px; }
a.knop { display: inline-block; margin-top: 15px; padding: 10px 18px; background: #1f6fa8; color: #fff; border-radius: 4px; text-decoration: none; font-size: 14px; }
a.knop:hover { background: #175a87; }
</style>
</head>
<body>
<div class="kader">
    <div class="melding">✅ installeer.php is verwijderd. Je kan nu inloggen op de monitor.</div>
    <p class="uitleg">Je wordt over <span id="teller">5</span> seconden automatisch doorgestuurd...</p>
    <a class="knop" href="login.php">Nu direct naar de inlogpagina →</a>
</div>
<script>
let secondenOver = 5;
const tellerEl = document.getElementById('teller');
setInterval(() => {
    secondenOver--;
    if (secondenOver >= 0) {
        tellerEl.textContent = secondenOver;
    }
    if (secondenOver <= 0) {
        window.location.href = 'login.php';
    }
}, 1000);
</script>
</body>
</html>
HTML;
            exit;
        }
        die('❌ Verwijderen is niet gelukt (mogelijk ontbreken de benodigde bestandsrechten) - '
            . 'verwijder installeer.php dan handmatig via FTP.');
    }

    die('Deze installatie is al eerder voltooid. Verwijder dit bestand (installeer.php) van de server. '
        . 'Wil je echt opnieuw installeren (bijv. op een schone database), verwijder dan eerst het bestand '
        . '"installatie.voltooid" en laad deze pagina opnieuw.');
}

$fout = '';
$succes = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbNaam = trim($_POST['db_naam'] ?? '');
    $dbGebruiker = trim($_POST['db_gebruiker'] ?? '');
    $dbWachtwoord = $_POST['db_wachtwoord'] ?? '';

    $monitorUrl = rtrim(trim($_POST['monitor_url'] ?? ''), '/');
    $notificatieEmail = trim($_POST['notificatie_email'] ?? '');
    $loginGebruiker = trim($_POST['login_gebruikersnaam'] ?? '');
    $loginWachtwoord = $_POST['login_wachtwoord'] ?? '';

    if (!isset($_POST['bevestig_gelezen']) || !isset($_POST['bevestig_database'])) {
        $fout = 'Vink beide selectievakjes bovenaan aan voordat je verdergaat.';
    } elseif ($dbNaam === '' || $dbGebruiker === '' || $monitorUrl === '' || $notificatieEmail === ''
        || $loginGebruiker === '' || $loginWachtwoord === '') {
        $fout = 'Vul alle velden in.';
    } elseif (strlen($loginWachtwoord) < 8) {
        $fout = 'Het wachtwoord voor de monitor moet minimaal 8 tekens lang zijn.';
    } else {
        try {
            $testPdo = new PDO(
                "mysql:host=$dbHost;dbname=$dbNaam;charset=utf8mb4",
                $dbGebruiker,
                $dbWachtwoord
            );
            $testPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            $fout = 'Kon niet verbinden met de database: ' . vertaalDatabaseFoutmelding($e);
        }

        if ($fout === '') {
            try {
                // 1. Verse, unieke versleutelingssleutel genereren - NOOIT
                //    een sleutel van een andere installatie hergebruiken.
                $nieuweSleutel = bin2hex(random_bytes(32));
                $geheimeSleutelInhoud = "<?php\n"
                    . "/**\n"
                    . " * geheime_sleutel.php\n"
                    . " *\n"
                    . " * Automatisch gegenereerd tijdens de installatie op {$monitorUrl}.\n"
                    . " * Bevat de sleutel voor de omkeerbare versleuteling van gevoelige\n"
                    . " * velden. Deel dit bestand met NIEMAND en zet het niet in een\n"
                    . " * (publieke) git-repository.\n"
                    . " */\n\n"
                    . "define('VERSLEUTEL_SLEUTEL', '{$nieuweSleutel}');\n";
                file_put_contents(__DIR__ . '/geheime_sleutel.php', $geheimeSleutelInhoud);

                require_once __DIR__ . '/versleuteling_functies.php';

                // 2. config.php wegschrijven met de zojuist ingevoerde,
                //    versleutelde database-gegevens.
                $dbWachtwoordVersleuteld = versleutelWaarde($dbWachtwoord);
                $dbHostEsc = addslashes($dbHost);
                $dbNaamEsc = addslashes($dbNaam);
                $dbGebruikerEsc = addslashes($dbGebruiker);

                $configInhoud = "<?php\n"
                    . "require_once __DIR__ . '/versleuteling_functies.php';\n"
                    . "require_once __DIR__ . '/auto_migratie.php';\n\n"
                    . "\$db_host = \"{$dbHostEsc}\";\n"
                    . "\$db_name = \"{$dbNaamEsc}\";\n"
                    . "\$db_user = \"{$dbGebruikerEsc}\";\n"
                    . "\$db_pass_versleuteld = \"{$dbWachtwoordVersleuteld}\";\n"
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
                    . "}\n\n"
                    . "try {\n"
                    . "    voerAutoMigratieUit(\$pdo);\n"
                    . "} catch (\\Throwable \$e) {\n"
                    . "    die(\"FOUT bij het automatisch bijwerken van de database: \" . \$e->getMessage());\n"
                    . "}\n";
                file_put_contents(__DIR__ . '/config.php', $configInhoud);

                // 3. Schema aanmaken via dezelfde migratiefunctie.
                require_once __DIR__ . '/auto_migratie.php';
                voerAutoMigratieUit($testPdo);

                // 4. Instellingen invullen: eigen gekozen waarden + automatisch
                //    gegenereerde geheime codes (nooit door de installateur zelf
                //    te hoeven verzinnen).
                $geheimeCode = bin2hex(random_bytes(16));
                $cronGeheimeCode = bin2hex(random_bytes(12));

                $instellingen = [
                    'notificatie_email'    => $notificatieEmail,
                    'geheime_code'         => versleutelWaarde($geheimeCode),
                    'cron_geheime_code'    => versleutelWaarde($cronGeheimeCode),
                    'monitor_basis_url'    => $monitorUrl,
                    'login_gebruikersnaam' => $loginGebruiker,
                    'login_wachtwoord'     => versleutelWaarde($loginWachtwoord),
                    'gegevens_versleuteld' => '1',
                ];
                $stmt = $testPdo->prepare("
                    INSERT INTO instellingen (sleutel, waarde) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE waarde = VALUES(waarde)
                ");
                foreach ($instellingen as $sleutel => $waarde) {
                    $stmt->execute([$sleutel, $waarde]);
                }

                // 5. Meegeleverde extensiecatalogus (extensies met een reeds
                //    bekende update-feed-URL) inladen, als dat bestand aanwezig is.
                $catalogusPad = __DIR__ . '/extensie_catalogus_seed.php';
                if (is_readable($catalogusPad)) {
                    $seedData = include $catalogusPad;
                    if (is_array($seedData)) {
                        $catStmt = $testPdo->prepare("
                            INSERT INTO extensie_catalogus (sleutel, label, manifest_pad, update_feed_url, genegeerd)
                            VALUES (?, ?, NULL, ?, 0)
                            ON DUPLICATE KEY UPDATE update_feed_url = VALUES(update_feed_url)
                        ");
                        foreach ($seedData as $rij) {
                            $catStmt->execute([$rij['sleutel'], $rij['label'], $rij['update_feed_url']]);
                        }
                    }
                }

                // 6. Zichzelf vergrendelen.
                file_put_contents($slotBestand, 'Installatie voltooid op ' . date('Y-m-d H:i:s') . "\n");

                $succes = true;
            } catch (\Throwable $e) {
                $foutmeldingTekst = ($e instanceof PDOException) ? vertaalDatabaseFoutmelding($e) : $e->getMessage();
                $fout = 'Er ging iets mis tijdens de installatie: ' . $foutmeldingTekst;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<title>Mijn Websites Monitor - Installatie</title>
<style>
body { font-family: Arial, sans-serif; margin: 0; padding: 40px 20px; background: #f5f5f5; }
.kader { max-width: 550px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
h1 { margin-top: 0; font-size: 20px; }
label { display: block; font-weight: bold; margin-top: 15px; }
label:first-of-type { margin-top: 0; }
.uitleg { font-weight: normal; color: #666; font-size: 12px; margin: 3px 0 6px 0; }
input[type="text"], input[type="password"], input[type="email"] { width: 100%; padding: 8px; box-sizing: border-box; font-size: 13px; }
.knop { display: inline-block; margin-top: 20px; padding: 10px 18px; background: #1f6fa8; color: #fff; border: none; border-radius: 4px; font-size: 14px; cursor: pointer; }
.knop:hover { background: #175a87; }
.melding { padding: 12px 16px; border-radius: 4px; margin-bottom: 20px; font-size: 13px; }
.melding.fout { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.melding.ok { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.sectiekop { font-weight: bold; margin: 25px 0 5px 0; padding-bottom: 5px; border-bottom: 2px solid #ddd; }
.sectiekop:first-of-type { margin-top: 0; }
code { background: #eee; padding: 1px 5px; border-radius: 3px; }
.checkbox-nadruk { width: 20px !important; height: 20px !important; accent-color: #e67e22; border: 3px solid #e67e22 !important; border-radius: 3px; cursor: pointer; }
#rest-van-formulier.vergrendeld { opacity: 0.5; }
#rest-van-formulier.vergrendeld input { background: #f0f0f0; cursor: not-allowed; }
</style>
</head>
<body>
<div class="kader">
    <h1>⚙️ Mijn Websites Monitor - Installatie</h1>

    <?php if ($succes): ?>
        <div class="melding ok">
            ✅ Installatie geslaagd! Je kan nu inloggen op <code><?php echo htmlspecialchars($monitorUrl); ?>/index.php</code>
            met de zojuist ingevoerde gebruikersnaam en wachtwoord.
            <br><br>
            <strong>Verwijder nu, voor de veiligheid, dit bestand (installeer.php) van de server.</strong>
            Dat kan met de knop hieronder, of later gewoon zelf via FTP.
        </div>
        <form method="post" onsubmit="return confirm('installeer.php nu verwijderen? Dit kan niet ongedaan worden gemaakt (maar is ook geen probleem - de installatie zelf blijft gewoon werken).');">
            <input type="hidden" name="actie" value="verwijder_zelf">
            <button type="submit" class="knop" style="background: #c0392b;">🗑️ Verwijder installeer.php nu</button>
        </form>
        <p style="margin-top: 15px;"><a href="<?php echo htmlspecialchars($monitorUrl); ?>/index.php">→ Naar de monitor</a></p>
    <?php else: ?>
        <?php if ($fout !== ''): ?>
            <div class="melding fout">❌ <?php echo htmlspecialchars($fout); ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="uitleg" style="margin-bottom: 20px;">
                <p style="margin-top: 0;">Voordat je hieronder de gegevens invult, twee korte controlevragen - ook als je
                <code>LEES_DIT_EERST.txt</code> nog niet hebt gelezen, weet je zo zeker dat alles klaarstaat:</p>
                <label style="display: flex; align-items: flex-start; gap: 10px; font-weight: normal; margin-top: 10px;">
                    <input type="checkbox" id="bevestig_gelezen" name="bevestig_gelezen" value="1" required class="checkbox-nadruk" onchange="controleerVoorwaardenVinkjes()">
                    <span>Ik heb <code>LEES_DIT_EERST.txt</code> (meegeleverd in dit pakket) gelezen, of weet in elk geval al hoe deze installatie werkt.</span>
                </label>
                <label style="display: flex; align-items: flex-start; gap: 10px; font-weight: normal; margin-top: 10px;">
                    <input type="checkbox" id="bevestig_database" name="bevestig_database" value="1" required class="checkbox-nadruk" onchange="controleerVoorwaardenVinkjes()">
                    <span>Ik heb bij mijn hostingpartij al een <strong>lege MySQL/MariaDB-database</strong> aangemaakt (met een gebruiker die daar rechten op heeft), en heb de databasenaam, gebruikersnaam en wachtwoord hiervan bij de hand.</span>
                </label>
            </div>

            <div id="rest-van-formulier" class="vergrendeld">
                <div class="sectiekop">Database-gegevens</div>
                <label for="db_host">Server</label>
                <div class="uitleg">Meestal gewoon <code>localhost</code>, tenzij je hostingpartij iets anders opgeeft.</div>
                <input type="text" id="db_host" name="db_host" value="<?php echo htmlspecialchars($_POST['db_host'] ?? 'localhost'); ?>">

                <label for="db_naam">Databasenaam</label>
                <input type="text" id="db_naam" name="db_naam" value="<?php echo htmlspecialchars($_POST['db_naam'] ?? ''); ?>">

                <label for="db_gebruiker">Gebruikersnaam</label>
                <input type="text" id="db_gebruiker" name="db_gebruiker" value="<?php echo htmlspecialchars($_POST['db_gebruiker'] ?? ''); ?>">

                <label for="db_wachtwoord">Wachtwoord</label>
                <input type="password" id="db_wachtwoord" name="db_wachtwoord" autocomplete="new-password">

                <div class="sectiekop">Monitor-instellingen</div>
                <label for="monitor_url">Monitor-URL</label>
                <div class="uitleg">De volledige URL naar de map waarin dit bestand nu staat, bijv. <code>https://voorbeeld.nl/mapnaam</code> (zonder afsluitende slash).</div>
                <input type="text" id="monitor_url" name="monitor_url" value="<?php echo htmlspecialchars($_POST['monitor_url'] ?? ''); ?>">

                <label for="notificatie_email">E-mailadres voor notificaties</label>
                <input type="email" id="notificatie_email" name="notificatie_email" value="<?php echo htmlspecialchars($_POST['notificatie_email'] ?? ''); ?>">

                <div class="sectiekop">Inloggegevens voor de monitor zelf</div>
                <label for="login_gebruikersnaam">Gebruikersnaam</label>
                <input type="text" id="login_gebruikersnaam" name="login_gebruikersnaam" value="<?php echo htmlspecialchars($_POST['login_gebruikersnaam'] ?? ''); ?>">

                <label for="login_wachtwoord">Wachtwoord</label>
                <div class="uitleg">Minimaal 8 tekens.</div>
                <input type="password" id="login_wachtwoord" name="login_wachtwoord" autocomplete="new-password">
            </div>

            <button type="submit" class="knop">Installeren</button>
        </form>
    <?php endif; ?>
</div>
<script>
const restVanFormulier = document.getElementById('rest-van-formulier');

if (restVanFormulier) {
    function controleerVoorwaardenVinkjes() {
        const beideAangevinkt = document.getElementById('bevestig_gelezen').checked
            && document.getElementById('bevestig_database').checked;
        restVanFormulier.classList.toggle('vergrendeld', !beideAangevinkt);
    }

    function controleerVoorwaardenVeld(event) {
        const beideAangevinkt = document.getElementById('bevestig_gelezen').checked
            && document.getElementById('bevestig_database').checked;
        if (!beideAangevinkt) {
            event.target.blur();
            alert('Vink eerst de twee selectievakjes hierboven aan, voordat je deze gegevens invult.');
        }
    }

    restVanFormulier.addEventListener('focusin', controleerVoorwaardenVeld);
}
</script>
</body>
</html>
