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
require_once 'catalogus_github_sync.php';

$foutmelding   = '';
$succesmelding = '';
$toonScanScriptWaarschuwing = false;
$toonNaamWaarschuwing = false;
$nieuweMonitorNaamVoorWeergave = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vereistGeldigCsrfToken();
    $actie = $_POST['actie'] ?? '';

    // ------------------------------------------------------------------
    // E-mailinstellingen: welke soorten meldingen wel/niet in de
    // notificatiemail worden opgenomen.
    // ------------------------------------------------------------------
    if ($actie === 'email_instellingen_opslaan') {
        $afzenderNaam = trim($_POST['email_afzendernaam'] ?? '');

        // Vóór het overschrijven bepalen of de monitornaam, ZOALS DIE IN
        // EEN BESTANDSNAAM TERECHTKOMT (zie normaliseerMonitorNaamVoorBestand()
        // in instellingen_functies.php), daadwerkelijk verandert - alleen
        // dan is de hieronder getoonde waarschuwing relevant. Puur
        // cosmetische wijzigingen (andere hoofdletter, leesteken) leveren
        // dezelfde genormaliseerde naam op en worden dus terecht NIET als
        // wijziging gezien. Een wijziging hier verandert overigens niets
        // aan bestaande scanscripts (die blijven, ongewijzigd, gewoon
        // werken onder hun huidige naam) - vandaar dat de waarschuwing
        // hieronder nadrukkelijk optioneel is, niet "moet meteen".
        $oudeAfzenderNaam = (string) haalInstelling($pdo, 'email_afzendernaam', '');
        $naamVoorBestandGewijzigd = normaliseerMonitorNaamVoorBestand($oudeAfzenderNaam) !== normaliseerMonitorNaamVoorBestand($afzenderNaam);

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

        if ($naamVoorBestandGewijzigd) {
            $toonNaamWaarschuwing = true;
            $nieuweMonitorNaamVoorWeergave = $afzenderNaam !== '' ? $afzenderNaam : 'Mijn Websites Monitor';
        }

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
        } elseif (!preg_match('/^[A-Za-z0-9_-]+$/', $geheimeCode)) {
            $foutmelding = 'De geheime code mag alleen letters, cijfers, streepjes en underscores bevatten - andere tekens (zoals %, &, spaties) kunnen problemen geven in de URL waarin deze code wordt meegestuurd.';
        } elseif (strlen($geheimeCode) < 20) {
            $foutmelding = 'De geheime code moet minimaal 20 tekens lang zijn - deze code beveiligt niet alleen het insturen van '
                . 'scanresultaten, maar sinds kort ook het (zelf-)bijwerken van het scanscript op elke site. Gebruik bij voorkeur '
                . 'de automatisch gegenereerde waarde van bij de installatie, of laat gerust een langere, willekeurige reeks '
                . 'genereren via een wachtwoordmanager.';
        } elseif (!preg_match('/^[A-Za-z0-9_-]+$/', $cronCode)) {
            $foutmelding = 'De cron-beveiligingscode mag alleen letters, cijfers, streepjes en underscores bevatten. '
                . 'Andere tekens - met name "%" - hebben een speciale betekenis in een crontab-regel (een "%" wordt daar '
                . 'gezien als regeleinde, tenzij je het handmatig escaped met "\\%") en kunnen de cronjob laten mislukken.';
        } elseif (strlen($cronCode) < 12) {
            $foutmelding = 'De cron-beveiligingscode moet minimaal 12 tekens lang zijn, voor voldoende beveiliging.';
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
    // Eigen logo uploaden (toont in de koptekst van de overzichtspagina).
    // ------------------------------------------------------------------
    if ($actie === 'logo_opslaan') {
        $LOGO_MIN_AFMETING = 128;   // pixels, breedte én hoogte
        $LOGO_MAX_AFMETING = 1024;  // pixels, breedte én hoogte
        $LOGO_MAX_BESTANDSGROOTTE = 2 * 1024 * 1024; // 2 MB
        $LOGO_TOEGESTANE_MIME = [
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];

        if (empty($_FILES['logo_bestand']) || $_FILES['logo_bestand']['error'] === UPLOAD_ERR_NO_FILE) {
            $foutmelding = 'Kies eerst een bestand om te uploaden.';
        } elseif ($_FILES['logo_bestand']['error'] !== UPLOAD_ERR_OK) {
            $foutmelding = 'Het uploaden is mislukt (foutcode ' . $_FILES['logo_bestand']['error'] . '). Probeer het opnieuw.';
        } elseif ($_FILES['logo_bestand']['size'] > $LOGO_MAX_BESTANDSGROOTTE) {
            $foutmelding = 'Dit bestand is groter dan de maximale 2 MB. Comprimeer de afbeelding en probeer het opnieuw.';
        } else {
            $tijdelijkPad = $_FILES['logo_bestand']['tmp_name'];
            $afmetingen   = @getimagesize($tijdelijkPad);

            if ($afmetingen === false) {
                $foutmelding = 'Dit is geen geldig afbeeldingsbestand (probeer een .png, .jpg of .webp).';
            } else {
                [$breedte, $hoogte, $imageType] = $afmetingen;
                $mimeType = $afmetingen['mime'] ?? '';

                if (!isset($LOGO_TOEGESTANE_MIME[$mimeType])) {
                    $foutmelding = 'Alleen .png, .jpg of .webp wordt ondersteund voor het logo.';
                } elseif ($breedte < $LOGO_MIN_AFMETING || $hoogte < $LOGO_MIN_AFMETING) {
                    $foutmelding = "De afbeelding is te klein ({$breedte}×{$hoogte} pixels) - minimaal {$LOGO_MIN_AFMETING}×{$LOGO_MIN_AFMETING} pixels vereist.";
                } elseif ($breedte > $LOGO_MAX_AFMETING || $hoogte > $LOGO_MAX_AFMETING) {
                    $foutmelding = "De afbeelding is te groot ({$breedte}×{$hoogte} pixels) - maximaal {$LOGO_MAX_AFMETING}×{$LOGO_MAX_AFMETING} pixels toegestaan.";
                } elseif (abs($breedte - $hoogte) > 0.1 * max($breedte, $hoogte)) {
                    // Bewust een ruime marge (10%) i.p.v. een keiharde 1:1-eis -
                    // een klein beetje afwijkend van vierkant is nog prima
                    // leesbaar op zowel desktop als mobiel, ruim daarbuiten
                    // (bijv. een brede rechthoekige bannerafbeelding) oogt
                    // een logo op deze plek al snel scheef of uitgerekt.
                    $foutmelding = "De afbeelding moet (ongeveer) vierkant zijn - deze is {$breedte}×{$hoogte} pixels.";
                } else {
                    // Oud, eventueel eerder geüpload logo + bijbehorende
                    // eigen favicons opruimen vóórdat het nieuwe bestand
                    // wordt weggeschreven.
                    $oudeLogoNaam = haalInstelling($pdo, 'logo_bestandsnaam', '');
                    if ($oudeLogoNaam !== '' && is_file(__DIR__ . '/images/' . $oudeLogoNaam)) {
                        @unlink(__DIR__ . '/images/' . $oudeLogoNaam);
                    }
                    verwijderEigenFavicons($oudeLogoNaam);

                    $nieuweBestandsnaam = 'logo-aangepast-' . time() . '.' . $LOGO_TOEGESTANE_MIME[$mimeType];
                    $doelPad = __DIR__ . '/images/' . $nieuweBestandsnaam;

                    if (move_uploaded_file($tijdelijkPad, $doelPad)) {
                        slaInstellingOp($pdo, 'logo_bestandsnaam', $nieuweBestandsnaam);

                        $faviconsGelukt = genereerFaviconsVanLogo($doelPad, $mimeType, $nieuweBestandsnaam);

                        $succesmelding = $faviconsGelukt
                            ? 'Logo geüpload en direct actief - het favicon in het browsertabblad en op mobiele apparaten is meteen mee bijgewerkt.'
                            : 'Logo geüpload en direct actief. Het favicon kon dit keer niet automatisch worden bijgewerkt '
                                . '(de GD-afbeeldingsbibliotheek is mogelijk niet beschikbaar op deze server) - het browsertabblad '
                                . 'en installatie-icoon blijven daardoor voorlopig het standaardlogo tonen.';
                    } else {
                        $foutmelding = 'Het opslaan van het bestand op de server is mislukt.';
                    }
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Eigen logo verwijderen: terug naar het standaardlogo.
    // ------------------------------------------------------------------
    if ($actie === 'logo_verwijderen') {
        $huidigeLogoNaam = haalInstelling($pdo, 'logo_bestandsnaam', '');
        if ($huidigeLogoNaam !== '' && is_file(__DIR__ . '/images/' . $huidigeLogoNaam)) {
            @unlink(__DIR__ . '/images/' . $huidigeLogoNaam);
        }
        verwijderEigenFavicons($huidigeLogoNaam);
        slaInstellingOp($pdo, 'logo_bestandsnaam', '');
        $succesmelding = 'Logo (en het bijbehorende favicon) teruggezet naar het standaardlogo.';
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

    // ------------------------------------------------------------------
    // Github-synchronisatie van de extensie-catalogus (update-feed-URL's
    // delen tussen losse installaties, zie extensie_beheer.php).
    // ------------------------------------------------------------------
    if ($actie === 'github_instellingen_opslaan') {
        $nieuwToken   = trim($_POST['catalogus_github_token'] ?? '');
        $tokenBehouden = ($_POST['catalogus_github_token_behouden'] ?? '') === '1';

        // Een leeg token-veld bij het opslaan betekent NIET automatisch
        // "token verwijderen" - anders zou dit veld bij elke opslag van
        // deze sectie opnieuw ingevuld moeten worden. Alleen als het
        // veld zichtbaar is aangepast (zie JS: "token_behouden" wordt op
        // "0" gezet zodra iemand in het veld typt) wordt de nieuwe
        // waarde (ook als die leeg is, om het token juist te wissen) opgeslagen.
        if (!$tokenBehouden) {
            slaInstellingOp($pdo, 'catalogus_github_token', versleutelWaarde($nieuwToken));
        }

        $succesmelding = 'Github-token opgeslagen.';
    }

    // Handmatig een keer pushen/importeren via een los knopje (los van de
    // AJAX-acties op extensie_beheer.php, voor het geval iemand liever
    // alles op deze pagina afhandelt).
    if ($actie === 'github_nu_pushen') {
        $eigenNaam = trim(haalInstelling($pdo, 'login_gebruikersnaam', ''));
        $resultaat = pushCatalogusNaarGithub($pdo, $eigenNaam);
        if ($resultaat['succes']) {
            $succesmelding = 'Catalogus succesvol naar Github gepusht.';
        } else {
            $foutmelding = $resultaat['foutmelding'];
        }
    }
}

$instellingen = haalAlleInstellingen($pdo);

// Voor de "Verstuur scanscript via FTP naar alle sites"-knoppen: per site
// apart versturen (zie verstuurFtpAlleSites() in de JS hieronder) i.p.v.
// alles in één groot verzoek, dat bij veel sites tegen de gateway-timeout
// van de server aanliep (HTTP 504) - en bovendien niet liet zien welke
// specifieke site vastliep.
$ftpSitesStmt = $pdo->query("
    SELECT id, domein FROM sites
    WHERE ftp_host IS NOT NULL AND ftp_host != ''
    ORDER BY domein ASC
");
$ftpSites = $ftpSitesStmt->fetchAll(PDO::FETCH_ASSOC);

// Voor de migratieknop: hoeveel sites (mét FTP-gegevens) hebben nog GEEN
// eigen, unieke scanscript-naam DIE OOK HET HUIDIGE NAAMGEVINGSPATROON
// VOLGT ("scan-door-<monitornaam>-xxxxxx.php")? Is dat er geen één meer,
// dan is er niets meer te migreren, en kan de knop vervangen worden door
// een simpele bevestiging in plaats van 'm altijd maar te blijven tonen.
// Bewust ook sites mét een al ingevulde scan_bestandsnaam meegenomen die
// niet met "scan-door-" begint - dat zijn sites met een naam volgens een
// eerder, inmiddels vervangen naamgevingspatroon (bijv. nog gebaseerd op
// de domeinnaam in plaats van de monitornaam), die dus ook een keer opnieuw
// gemigreerd moeten worden. Let op: dit is bewust een APARTE lijst van
// $ftpSites hierboven - die bevat namelijk ALLE FTP-sites (voor het
// opnieuw versturen van een bestaand scanscript), terwijl de migratie
// alleen de sites moet raken die nog niet (correct) gemigreerd zijn
// (anders zou een al gemigreerde site zomaar een nieuwe, andere
// willekeurige naam krijgen, zonder dat daar enige noodzaak toe is).
$nogTeMigrerenStmt = $pdo->query("
    SELECT id, domein FROM sites
    WHERE ftp_host IS NOT NULL AND ftp_host != ''
      AND (scan_bestandsnaam IS NULL OR scan_bestandsnaam = '' OR scan_bestandsnaam NOT LIKE 'scan-door-%')
    ORDER BY domein ASC
");
$nogTeMigrerenSites = $nogTeMigrerenStmt->fetchAll(PDO::FETCH_ASSOC);
$aantalNogTeMigreren = count($nogTeMigrerenSites);

// Voor het "Admin Tools: informatie voor .htaccess-maker"-blok: sites
// herkennen die Admin Tools gebruiken doen we automatisch, op basis van
// wat de laatste scan al aan geïnstalleerde extensies heeft gevonden (in
// plaats van dat je dit ergens los zou moeten aanvinken) - dus zowel het
// pakket zelf (pkg_admintools) als losse onderdelen ervan (bijv. het
// systeem-plugin), aan de hand van het element of de naam.
$adminToolsSitesStmt = $pdo->query("
    SELECT DISTINCT s.id, s.domein, s.admin_pad, s.url_subpad, s.favicon_url, s.scan_bestandsnaam
    FROM sites s
    INNER JOIN site_alle_extensies e ON e.site_id = s.id
    WHERE LOWER(e.element) LIKE '%admintools%' OR LOWER(e.naam) LIKE '%admin tools%'
    ORDER BY s.domein ASC
");
$adminToolsSites = $adminToolsSitesStmt->fetchAll(PDO::FETCH_ASSOC);
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
    background: var(--thema-zebra);
    border: 1px solid var(--thema-rand);
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

.ftp-resultaat-blok {
    background: var(--thema-zebra);
    border: 1px solid var(--thema-rand);
    color: var(--thema-tekst);
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
    <div style="display: flex; gap: 8px;">
        <button type="button" class="knop" onclick="history.back()" style="padding: 8px 12px;" title="Eén stap terug">←</button>
        <a class="knop" href="index.php">Terug naar monitor</a>
    </div>
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
<div class="melding" style="background: #fff3cd !important; color: #664d03 !important; border: 1px solid #ffe69c; padding: 15px 18px;">
    <strong>⚠️ Belangrijk: het scanscript moet opnieuw naar alle sites gestuurd worden.</strong>
    <div style="margin: 8px 0 12px 0;">
        Je hebt zojuist de geheime code, de cron-beveiligingscode of het monitor-pad gewijzigd. Deze waarden staan
        verwerkt in <code>scan-en-check-website.php</code> op elke site - zolang dat niet is bijgewerkt, blijven de
        oude scanscripts op de sites de <strong>oude</strong> waarden gebruiken, en werkt de koppeling niet meer.
    </div>
    <button type="button" class="knop" style="background: #6f42c1;" onclick="verstuurFtpAlleSites(this, 'ftp-resultaat-boven')">🚀 Verstuur nu automatisch naar alle sites met FTP-gegevens</button>
    <div style="margin-top: 8px; font-size: 12px;">
        ⏳ Dit kan, afhankelijk van het aantal sites, enige tijd duren - elke site wordt namelijk één voor één
        via een eigen FTP-verbinding bijgewerkt. Er lijkt in de tussentijd niets te gebeuren, maar de knop is
        gewoon bezig; wacht het resultaatoverzicht hieronder rustig af.
    </div>
    <div style="margin-top: 10px; font-size: 12px;">
        Heeft een site nog geen FTP-gegevens ingevuld? Ga dan naar "⚙️ Site-instellingen" van die site, en download
        daar het scanscript met de bij die site horende, unieke bestandsnaam - zet dat zelf via FTP op de site.
    </div>
    <div id="ftp-resultaat-boven" class="ftp-resultaat-blok" style="display: none; margin-top: 12px; padding: 10px 14px; border-radius: 4px; font-size: 12px; white-space: pre-wrap; font-family: monospace;"></div>
</div>
<?php endif; ?>

<?php if ($toonNaamWaarschuwing): ?>
<div class="melding" style="background: #eef1f4 !important; color: #333 !important; border: 1px solid #dde2e7; padding: 15px 18px;">
    <strong>💡 Optioneel: scanscript-bestandsnamen bijwerken naar "<?php echo htmlspecialchars($nieuweMonitorNaamVoorWeergave); ?>".</strong>
    <div style="margin: 8px 0 12px 0;">
        Je hebt de monitornaam gewijzigd. De scanscripts die al op je sites staan, blijven <strong>gewoon werken</strong>
        onder hun huidige bestandsnaam - dit is dus geen storing en vereist geen actie. Wil je dat de bestandsnamen
        er voortaan ook naar de nieuwe naam verwijzen (bijv. <code>scan-door-<?php echo htmlspecialchars(preg_replace('/[^a-z0-9]/', '', strtolower($nieuweMonitorNaamVoorWeergave))); ?>-a1b2c3.php</code>),
        dan kan dat met de knop hieronder - dat genereert per site een nieuwe naam, zet die op de server, en verwijdert
        automatisch het bestand met de oude naam.
    </div>
    <div class="waarschuwing" style="margin-bottom: 12px;">
        ⚠️ Gebruik je bij (een van) je sites Akeeba Admin Tools' <strong>".htaccess Maker"</strong> met de instelling
        die PHP-uitvoering beperkt tot een handmatig opgegeven lijst bestandsnamen? Dan moet je na het hernoemen de
        <strong>nieuwe</strong> bestandsnaam ook daar zelf nog aan toevoegen (in Joomla zelf, niet via deze monitor) -
        anders blokkeert Admin Tools het zojuist hernoemde scanscript alsnog. Twijfel je of dit van toepassing is?
        Laat het dan liever gewoon bij de huidige bestandsnamen.
    </div>
    <button type="button" class="knop" style="background: #6f42c1;" onclick="verstuurFtpAlleSites(this, 'ftp-resultaat-naam', 'ftp_hernoem_scanscript.php')">🔤 Bestandsnamen nu bijwerken op alle sites met FTP-gegevens</button>
    <div style="margin-top: 8px; font-size: 12px;">
        ⏳ Dit kan, afhankelijk van het aantal sites, enige tijd duren - elke site wordt namelijk één voor één
        via een eigen FTP-verbinding bijgewerkt.
    </div>
    <div id="ftp-resultaat-naam" class="ftp-resultaat-blok" style="display: none; margin-top: 12px; padding: 10px 14px; border-radius: 4px; font-size: 12px; white-space: pre-wrap; font-family: monospace;"></div>
</div>
<?php endif; ?>

<div class="blok">
    <h2>E-mailinstellingen<?php echo hulpIcoon('configuratie', 'Instellingen voor de meldingsmails bij nieuwe scanresultaten (afzendernaam/-adres, ontvangers, en wanneer een e-mail verstuurd wordt).'); ?></h2>
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
            Deze naam wordt op drie plekken gebruikt: als afzendernaam in de mailclient van de ontvanger van
            notificatiemails (i.p.v. kaal het e-mailadres), als titel van dit programma zelf (linksboven op de
            overzichtspagina en in het browsertabblad), en als leesbaar voorvoegsel in de bestandsnaam die een
            nieuw scanscript krijgt (bijv. <code>scan-door-mijnwebsitesmonitor-a1b2c3.php</code>) - dat laatste
            geldt overigens alleen voor scanscripts die ná deze wijziging voor het eerst worden aangemaakt/hernoemd,
            niet voor scanscripts die al op je sites staan. Laat leeg voor de standaardnaam "Mijn Websites Monitor".
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
    <h2>Algemene instellingen<?php echo hulpIcoon('configuratie', 'De geheime code (voor de koppeling tussen scanscript en monitor) en het monitor-pad - beide worden automatisch in elk scanscript verwerkt.'); ?></h2>
    <div class="blok-uitleg">Deze waarden worden centraal opgeslagen en door de scripts uitgelezen in plaats van hardgecodeerd te staan.</div>
    <form method="post">
        <input type="hidden" name="actie" value="instellingen_opslaan">
        <?php echo csrfVeld(); ?>

        <label for="notificatie_email">E-mailadres voor notificaties</label>
        <div class="uitleg">Hierheen wordt een waarschuwing gestuurd als er sites op "rood" staan.</div>
        <input type="text" id="notificatie_email" name="notificatie_email" required value="<?php echo htmlspecialchars($instellingen['notificatie_email'] ?? ''); ?>">

        <label for="geheime_code">Geheime code</label>
        <div class="uitleg">Moet exact overeenkomen met de code in <code>scan-en-check-website.php</code> op elke site. Download hieronder een nieuwe versie na wijziging. Alleen letters, cijfers, streepjes en underscores, minimaal 20 tekens - deze code beveiligt ook het (zelf-)bijwerken van het scanscript, dus hoe willekeuriger/langer, hoe beter.</div>
        <input type="text" id="geheime_code" name="geheime_code" required pattern="[A-Za-z0-9_-]{20,}" title="Alleen letters, cijfers, streepjes en underscores, minimaal 20 tekens" value="<?php echo htmlspecialchars(ontsleutelWaarde($instellingen['geheime_code'] ?? '')); ?>">

        <label for="cron_geheime_code">Cron-beveiligingscode</label>
        <div class="uitleg">
            Beveiligt <code>check_sites.php</code>, <code>start_scan.php</code>, <code>haal_versies_op.php</code> en
            <code>cron_alles_scannen.php</code> tegen willekeurige bezoekers - deze bestanden checken namelijk geen
            login (dat kan niet, want een cronjob heeft geen sessie). Wijzig je deze code, werk dan ook het
            commando in je cronjob bij (zie de helppagina). Alleen letters, cijfers, streepjes en underscores,
            minimaal 12 tekens - met name een "%" geeft problemen in een crontab-regel (wordt daar gezien als regeleinde).
        </div>
        <input type="text" id="cron_geheime_code" name="cron_geheime_code" required pattern="[A-Za-z0-9_-]{12,}" title="Alleen letters, cijfers, streepjes en underscores, minimaal 12 tekens" value="<?php echo htmlspecialchars(ontsleutelWaarde($instellingen['cron_geheime_code'] ?? '')); ?>">


        <label for="monitor_basis_url">Pad naar de monitorsite</label>
        <div class="uitleg">De basis-URL van deze monitor, zonder afsluitende slash, bijv. <code>https://voorbeeld.nl/mapnaam</code>.</div>
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
    <h2>🖼️ Logo<?php echo hulpIcoon('configuratie', 'Eigen logo uploaden, te zien op de monitorpagina en het inlogscherm in plaats van het standaardlogo.'); ?></h2>
    <div class="blok-uitleg">
        Vervang het standaardlogo (linksboven op de overzichtspagina) door je eigen logo. Dit werkt het best met
        een <strong>vierkante</strong> afbeelding, zodat 'ie er zowel op een groot scherm als op een mobiele
        weergave goed uitziet - een logo dat duidelijk breder of hoger is dan vierkant oogt al snel uitgerekt op
        deze kleine plek.
    </div>
    <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px; padding: 12px 15px; background: var(--thema-zebra); border: 1px solid var(--thema-rand); border-radius: 6px;">
        <img src="<?php echo htmlspecialchars(huidigLogoPad($instellingen)); ?>" alt="Huidig logo" style="width: 48px; height: 48px; flex-shrink: 0; border-radius: 4px;">
        <div style="font-size: 12px; color: var(--thema-uitleg-tekst);">
            <?php if (!empty($instellingen['logo_bestandsnaam'])): ?>
                Huidig eigen logo (wordt automatisch op 48×48 pixels getoond, ongeacht de geüploade afmeting).
            <?php else: ?>
                Er is nog geen eigen logo geüpload - dit is het standaardlogo.
            <?php endif; ?>
        </div>
    </div>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="actie" value="logo_opslaan">
        <?php echo csrfVeld(); ?>

        <label for="logo_bestand">Nieuw logo uploaden</label>
        <div class="uitleg">
            Eisen: <strong>.png, .jpg of .webp</strong>, (ongeveer) <strong>vierkant</strong>, minimaal
            <strong>128×128</strong> en maximaal <strong>1024×1024 pixels</strong>, bestand max. 2 MB. Een
            vierkante PNG met een doorzichtige achtergrond geeft meestal het beste resultaat.
        </div>
        <input type="file" id="logo_bestand" name="logo_bestand" accept=".png,.jpg,.jpeg,.webp" required>

        <div class="knop-rij" style="margin-top: 15px; display: flex; gap: 10px;">
            <button type="submit" class="knop opslaan">Uploaden</button>
        </div>
    </form>
    <?php if (!empty($instellingen['logo_bestandsnaam'])): ?>
        <form method="post" style="margin-top: 10px;" onsubmit="return confirm('Terugzetten naar het standaardlogo?');">
            <input type="hidden" name="actie" value="logo_verwijderen">
            <?php echo csrfVeld(); ?>
            <button type="submit" class="knop" style="background: #666;">Terugzetten naar standaardlogo</button>
        </form>
    <?php endif; ?>
</div>

<div class="blok">
    <h2>Database-gegevens<?php echo hulpIcoon('configuratie', 'Alleen-lezen overzicht van de databaseverbinding van de monitor zelf, puur ter referentie (bijv. bij het maken van een back-up).'); ?></h2>
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
    <h2>🔄 Github-synchronisatie catalogus<?php echo hulpIcoon('configuratie', 'Deelt de update-feed-URL\'s uit "Extensies beheren" automatisch met andere installaties via een gedeelde catalogus op Github. Werkt direct, zonder instellingen - alleen wie zelf mag bijdragen (schrijfrechten) hoeft hier een eigen token in te vullen.'); ?></h2>
    <div class="blok-uitleg">
        Deze installatie leest automatisch mee met een gedeelde catalogus van update-feed-URL's op Github (alleen de
        sleutel, het label, het manifestpad en de update-feed-URL van elke extensie - <strong>nooit</strong> site- of
        klantgegevens). Bij "Extensies beheren" verschijnt vanzelf een melding zodra daar iets nieuws of gewijzigds
        in staat. Hier hoef je verder niets voor in te stellen.
    </div>

    <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; margin-top: 10px;">
        <input type="checkbox" id="catalogus_github_beheerder_schakelaar" style="width: auto;" onchange="document.getElementById('catalogus_github_beheerder_blok').style.display = this.checked ? 'block' : 'none';">
        <span>Ik ben beheerder met schrijfrechten op deze repository</span>
    </label>
    <div class="uitleg" style="margin-top: 4px; margin-bottom: 10px;">
        Alleen aanvinken als je zelf ook wijzigingen mag <em>terugsturen</em> naar de gedeelde catalogus - voor
        gewoon gebruik (lezen) is dit niet nodig, dat werkt namelijk al vanzelf.
    </div>

    <div id="catalogus_github_beheerder_blok" style="display: none;">
        <form method="post">
            <input type="hidden" name="actie" value="github_instellingen_opslaan">
            <input type="hidden" name="catalogus_github_token_behouden" id="catalogus_github_token_behouden" value="1">
            <?php echo csrfVeld(); ?>

            <label for="catalogus_github_token">Github-token</label>
            <div class="uitleg">
                Een "fine-grained" personal access token met "Contents: Read and write" op de gedeelde catalogus-repo.
            </div>
            <div class="wachtwoord-veld">
                <input type="password" id="catalogus_github_token" name="catalogus_github_token"
                    value="<?php echo haalInstelling($pdo, 'catalogus_github_token', '') !== '' ? '••••••••••••••••' : ''; ?>"
                    autocomplete="new-password"
                    oninput="document.getElementById('catalogus_github_token_behouden').value = '0';">
                <button type="button" class="oogje" onclick="toonWachtwoord('catalogus_github_token', this)"><span class="icoon-glyph">👁️</span></button>
            </div>

            <button type="submit" class="knop opslaan">Opslaan</button>
        </form>

        <?php if (haalInstelling($pdo, 'catalogus_github_token', '') !== ''): ?>
        <form method="post" style="margin-top: 10px;">
            <input type="hidden" name="actie" value="github_nu_pushen">
            <?php echo csrfVeld(); ?>
            <button type="submit" class="knop" style="background: #24292e;">⬆️ Nu handmatig pushen naar Github</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="blok">
    <h2>Site-scanscript <?php echo hulpIcoon('sites-toevoegen', 'Elke site krijgt een eigen, unieke scanscript-bestandsnaam. Hier kun je het scanscript bij bestaande sites bulksgewijs naar deze site sturen, of migreren naar het nieuwste naamgevingspatroon.'); ?></h2>
    <div class="blok-uitleg">
        Elke site heeft tegenwoordig een eigen, uniek gegenereerde scanscript-bestandsnaam (in plaats van bij elke
        site dezelfde, voorspelbare standaardnaam) - dat is veiliger, en zorgt ervoor dat de monitor een teruggevonden
        bestand altijd herkent als van zichzelf. Een kant-en-klare download mét de juiste, bij die site horende
        bestandsnaam vind je daarom niet hier, maar bij "⚙️ Site-instellingen" van de site zelf.
    </div>

    <div class="blok-uitleg" style="margin-top: 20px;">
        Heb je bij één of meer sites (via "⚙️ Site-instellingen") FTP-gegevens ingevuld? Dan kun je het scanscript
        ook in één keer naar al die sites tegelijk versturen, zonder los te downloaden/uploaden. Dit kan,
        afhankelijk van het aantal sites, wel enige tijd duren - elke site wordt namelijk één voor één via een
        eigen FTP-verbinding bijgewerkt. Lijkt het alsof er niets gebeurt: gewoon even geduld, het resultaat
        verschijnt hieronder zodra alles klaar is.
    </div>
    <button type="button" class="knop" style="background: #6f42c1;" onclick="verstuurFtpAlleSites(this)">🚀 Verstuur scanscript via FTP naar alle sites met FTP-gegevens</button>
    <div id="ftp-resultaat" class="ftp-resultaat-blok" style="display: none; margin-top: 12px; padding: 10px 14px; border-radius: 4px; font-size: 12px; white-space: pre-wrap; font-family: monospace;"></div>

    <div class="blok-uitleg" style="margin-top: 20px;">
        <strong>Eenmalige migratie voor bestaande sites:</strong> sites die vóór deze functie zijn toegevoegd,
        gebruiken mogelijk nog de oude, voor elke site identieke standaardnaam. Met de knop hieronder krijgt elke
        site (met bekende FTP-/SFTP-gegevens) in één keer een eigen, uniek gegenereerde naam - het nieuwe bestand
        wordt geplaatst, en het oude bestand wordt daarna automatisch opgeruimd. Sites zonder FTP-gegevens worden
        overgeslagen (die kun je daarna alsnog los bijwerken via de "Vervang door nieuwe naam"-knop bij
        Site-instellingen, zodra de FTP-gegevens zijn ingevuld).
    </div>
    <div class="waarschuwing" style="margin-bottom: 12px;">
        ⚠️ Gebruikt een van deze sites <strong>Akeeba Admin Tools</strong> (of een vergelijkbare firewall met een
        bestandsnaam-uitzonderingslijst)? Dan blokkeert die firewall het scanscript na deze migratie, totdat je de
        uitzondering op die site zelf bijwerkt naar de nieuwe naam - zie hoofdstuk 3 van de helppagina.
    </div>
    <?php if ($aantalNogTeMigreren > 0): ?>
        <button type="button" class="knop" style="background: #17a2b8;" onclick="migreerAlleScanscripts(this)">🔐 Migreer alle sites naar unieke scanscript-namen (<?php echo $aantalNogTeMigreren; ?> te gaan)</button>
        <div id="migratie-resultaat" style="display: none; margin-top: 12px; padding: 10px 14px; border-radius: 4px; font-size: 12px; white-space: pre-wrap; font-family: monospace;"></div>
    <?php else: ?>
        <div class="uitleg" style="padding: 10px 14px; background: var(--thema-vertrouwd-bg); color: var(--thema-vertrouwd-tekst); border-radius: 4px; border: 1px solid var(--thema-vertrouwd-tekst);">
            ✅ Alle sites met bekende FTP-/SFTP-gegevens gebruiken al een eigen, unieke scanscript-naam - hier is
            momenteel niets meer te migreren.
        </div>
    <?php endif; ?>
</div>

<div class="blok">
    <h2>🛡️ Admin Tools: informatie voor .htaccess-maker <?php echo hulpIcoon('site-instellingen', 'Admin Tools blokkeert het scanscript standaard, tenzij de exacte bestandsnaam als uitzondering wordt toegevoegd in de .htaccess-maker. Dit overzicht toont die naam per site.'); ?></h2>
    <div class="blok-uitleg">
        Gebruikt een site Akeeba Admin Tools, dan moet de unieke scanscript-bestandsnaam van die site worden
        toegevoegd aan de "Allow direct access to these files"-lijst in de .htaccess-maker (zie hoofdstuk 3 van de
        helppagina) - anders blokkeert de firewall het scanscript. Klik op de knop hieronder voor een overzicht van
        alle sites waar Admin Tools op is aangetroffen, met daarbij de exacte bestandsnaam die je per site in dat
        scherm moet invullen.
    </div>
    <button type="button" class="knop" style="background: #8e6d1f;" onclick="toonAdminToolsOverzicht(this)">🛡️ Toon Admin Tools-overzicht (<?php echo count($adminToolsSites); ?> site(s))</button>

    <div id="admintools-overzicht" style="display: none; margin-top: 15px;">
        <?php if (empty($adminToolsSites)): ?>
            <div class="uitleg">Geen sites gevonden waarbij Admin Tools is aangetroffen bij de laatste scan.</div>
        <?php else: ?>
            <table class="responsive-tabel">
                <tr>
                    <th style="width: 40px;"></th>
                    <th>Domein</th>
                    <th>Scanscript-bestandsnaam</th>
                    <th style="width: 40px;"></th>
                </tr>
                <?php foreach ($adminToolsSites as $atSite): ?>
                    <?php
                    $atAdminPad = trim(ontsleutelWaarde($atSite['admin_pad'] ?? ''), '/');
                    $atAdminUrl = bepaalSiteUrl($atSite, $atAdminPad);
                    $atFaviconUrl = !empty($atSite['favicon_url']) ? $atSite['favicon_url'] : 'https://www.joomla.org/favicon.ico';
                    $atBestandsnaam = bepaalScanBestandsnaam($atSite);
                    ?>
                    <tr>
                        <td data-label="">
                            <img src="<?php echo htmlspecialchars($atFaviconUrl); ?>" alt="" width="24" height="24" style="vertical-align: middle;" onerror="this.onerror=null; this.src='https://www.joomla.org/favicon.ico';">
                        </td>
                        <td data-label="Domein">
                            <a class="domein-link" href="<?php echo htmlspecialchars($atAdminUrl); ?>" target="_blank" title="Inloggen als admin"><?php echo htmlspecialchars($atSite['domein'] ?? ''); ?></a>
                        </td>
                        <td data-label="Scanscript-bestandsnaam"><code><?php echo htmlspecialchars($atBestandsnaam); ?></code></td>
                        <td data-label="">
                            <button type="button" class="knop" style="padding: 4px 8px; font-size: 11px;" data-bestandsnaam="<?php echo htmlspecialchars($atBestandsnaam); ?>" onclick="kopieerBestandsnaam(this)">📋</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</div>

<div class="blok">
    <h2>🗄️ Back-up maken<?php echo hulpIcoon('backups-installatie', 'Downloadt een volledige back-up van de monitor-database als .sql-bestand - handig voor een grote wijziging of update.'); ?></h2>
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

<?php if (is_readable(__DIR__ . '/maak_installatiepakket.php') && is_readable(__DIR__ . '/maak_updatepakket.php') && is_readable(__DIR__ . '/pakket_voorbereiden.php')): ?>
<div class="blok">
    <h2>📦 Installatie- en updatepakket<?php echo hulpIcoon('backups-installatie', 'Stelt een kant-en-klaar .zip-bestand samen om de monitor-software zelf te installeren of bij te werken naar de huidige versie.'); ?></h2>
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
</div>
<?php endif; ?>

<div class="blok" style="text-align: center;">
    <div class="blok-uitleg" style="margin-bottom: 10px;">
        Huidige versie: <strong>v<?php echo htmlspecialchars(MONITOR_VERSIE); ?></strong>
    </div>
    <a href="toon_changelog.php" target="_blank" class="knop">📜 Wijzigingsgeschiedenis</a>
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

const FTP_SITES = <?php echo json_encode($ftpSites); ?>;
const NOG_TE_MIGREREN_SITES = <?php echo json_encode($nogTeMigrerenSites); ?>;

function verstuurFtpAlleSites(knop, resultaatId, endpoint) {
    knop.disabled = true;
    endpoint = endpoint || 'ftp_verstuur_scanscript.php';

    const resultaat = document.getElementById(resultaatId || 'ftp-resultaat');
    resultaat.style.display = 'block';

    if (FTP_SITES.length === 0) {
        resultaat.textContent = 'Geen site(s) gevonden met ingevulde FTP-gegevens.';
        knop.disabled = false;
        return;
    }

    // Bewust per site een APART verzoek (in plaats van alles in één keer),
    // en het resultaat na elke site meteen bijwerken: zo loopt dit nooit
    // tegen de gateway-timeout van de server aan (elk los verzoek blijft
    // ruim binnen de tijd), en zie je precies bij welke site het eventueel
    // misgaat, in plaats van één blinde melding voor de hele lijst.
    const regels = FTP_SITES.map(site => `⏳ ${site.domein} - wachten...`);
    resultaat.textContent = regels.join('\n');

    let index = 0;

    function volgende() {
        if (index >= FTP_SITES.length) {
            knop.disabled = false;
            return;
        }

        const site = FTP_SITES[index];
        regels[index] = `⏳ ${site.domein} - bezig...`;
        resultaat.textContent = regels.join('\n');

        const body = new URLSearchParams();
        body.append('csrf_token', CSRF_TOKEN);
        body.append('site_id', site.id);

        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(r => r.text())
            .then(tekst => {
                regels[index] = tekst.trim() || `❓ ${site.domein} - geen antwoord ontvangen`;
            })
            .catch(err => {
                regels[index] = `❌ ${site.domein}: Er ging iets mis - ` + err.message;
            })
            .finally(() => {
                resultaat.textContent = regels.join('\n');
                index++;
                volgende();
            });
    }

    volgende();
}

function kopieerBestandsnaam(knop) {
    const tekst = knop.dataset.bestandsnaam;

    if (!navigator.clipboard) {
        prompt('Kopiëren via de knop is hier niet mogelijk - selecteer en kopieer de bestandsnaam hieronder handmatig:', tekst);
        return;
    }

    navigator.clipboard.writeText(tekst).then(() => {
        const origineel = knop.textContent;
        knop.textContent = '✅';
        knop.disabled = true;
        setTimeout(() => {
            knop.textContent = origineel;
            knop.disabled = false;
        }, 1500);
    }).catch(() => {
        prompt('Kopiëren is niet gelukt - selecteer en kopieer de bestandsnaam hieronder handmatig:', tekst);
    });
}

function toonAdminToolsOverzicht(knop) {
    const overzicht = document.getElementById('admintools-overzicht');
    const zichtbaar = overzicht.style.display !== 'none';
    overzicht.style.display = zichtbaar ? 'none' : 'block';
    if (!zichtbaar) {
        overzicht.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

function migreerAlleScanscripts(knop) {
    knop.disabled = true;

    const resultaat = document.getElementById('migratie-resultaat');
    resultaat.style.display = 'block';

    if (NOG_TE_MIGREREN_SITES.length === 0) {
        resultaat.textContent = 'Geen site(s) gevonden die nog gemigreerd moeten worden.';
        knop.disabled = false;
        return;
    }

    if (!confirm('Bij ' + NOG_TE_MIGREREN_SITES.length + ' site(s) met FTP-gegevens wordt het scanscript vervangen door een nieuwe, unieke naam. '
        + 'Het nieuwe bestand wordt geplaatst en het oude bestand wordt automatisch opgeruimd.\n\n'
        + 'Let op: gebruikt een van deze sites Akeeba Admin Tools (of een vergelijkbare firewall met een '
        + 'bestandsnaam-uitzondering)? Werk die uitzondering na afloop per site bij naar de nieuwe naam, anders '
        + 'blokkeert de firewall het scanscript op die site(s). Doorgaan?')) {
        knop.disabled = false;
        return;
    }

    const regels = NOG_TE_MIGREREN_SITES.map(site => `⏳ ${site.domein} - wachten...`);
    resultaat.textContent = regels.join('\n');

    let index = 0;

    function volgende() {
        if (index >= NOG_TE_MIGREREN_SITES.length) {
            knop.disabled = false;
            return;
        }

        const site = NOG_TE_MIGREREN_SITES[index];
        regels[index] = `⏳ ${site.domein} - bezig...`;
        resultaat.textContent = regels.join('\n');

        const body = new URLSearchParams();
        body.append('csrf_token', CSRF_TOKEN);
        body.append('site_id', site.id);

        fetch('scanscript_vervangen.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(r => r.json())
            .then(data => {
                regels[index] = (data.succes ? '✅ ' : '❌ ') + data.melding;
            })
            .catch(err => {
                regels[index] = `❌ ${site.domein}: Er ging iets mis - ` + err.message;
            })
            .finally(() => {
                resultaat.textContent = regels.join('\n');
                index++;
                volgende();
            });
    }

    volgende();
}
</script>

</html>