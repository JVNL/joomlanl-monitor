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
require_once 'versie_vergelijk_functies.php';
require_once 'catalogus_github_sync.php';

$foutmelding   = '';
$succesmelding = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    vereistGeldigCsrfToken();
    $actie = $_POST['actie'] ?? '';

    if ($actie === 'toevoegen') {
        $sleutel        = strtolower(trim($_POST['sleutel'] ?? ''));
        $label          = trim($_POST['label'] ?? '');
        $manifestPad    = trim($_POST['manifest_pad'] ?? '', "/ \t\n\r\0\x0B");
        $manifestPad    = $manifestPad === '' ? null : $manifestPad;
        $updateFeedUrl  = trim($_POST['update_feed_url'] ?? '');
        $updateFeedUrl  = $updateFeedUrl === '' ? null : $updateFeedUrl;

        if ($sleutel === '' || $label === '') {
            $foutmelding = 'Vul in elk geval de sleutel en het label in.';
        } elseif (!preg_match('/^[a-z0-9_]+$/', $sleutel)) {
            $foutmelding = 'De sleutel mag alleen kleine letters, cijfers en underscores (_) bevatten, zonder spaties.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO extensie_catalogus (sleutel, label, manifest_pad, update_feed_url)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$sleutel, $label, $manifestPad, $updateFeedUrl]);
                $succesmelding = "Extensie \"$label\" is toegevoegd aan de catalogus. Druk op \"Scan en check sites\" op de monitorpagina om 'm meteen te laten controleren.";
            } catch (PDOException $e) {
                $foutmelding = 'Toevoegen is mislukt. Bestaat de sleutel "' . htmlspecialchars($sleutel) . '" al?';
            }
        }
    } elseif ($actie === 'bijwerken' || $actie === 'bijwerken_zonder_github') {
        $sleutel       = $_POST['sleutel'] ?? '';
        $updateFeedUrl = trim($_POST['update_feed_url'] ?? '');
        $updateFeedUrl = $updateFeedUrl === '' ? null : $updateFeedUrl;

        if ($sleutel !== '') {
            // feed_lokaal wordt hier meteen op de juiste waarde gezet, niet
            // pas ná een geslaagde push: "0" betekent puur "niet bewust
            // uitgesloten van Github", en bepaalt zo of deze rij de
            // eerstvolgende keer (door wie dan ook getriggerd) wél of niet
            // meegaat in lokaleCatalogusAlsArray().
            $feedLokaal = $actie === 'bijwerken_zonder_github' ? 1 : 0;
            $stmt = $pdo->prepare("UPDATE extensie_catalogus SET update_feed_url = ?, feed_lokaal = ? WHERE sleutel = ?");
            $stmt->execute([$updateFeedUrl, $feedLokaal, $sleutel]);

            if ($updateFeedUrl === null) {
                // Geen feed-URL meer om te controleren - dus ook de eerder
                // opgehaalde "nieuwste versie" voor deze sleutel opruimen.
                // Zonder dit zou een verouderde/foutieve waarde van vóór
                // het legen van dit veld gewoon blijven staan (hij wordt
                // immers nooit meer ververst als er geen feed meer is om
                // te bevragen), en zo per ongeluk blijven meetellen bij de
                // status van elke site met deze extensie.
                $pdo->prepare("DELETE FROM nieuwste_versies WHERE naam = ?")->execute([$sleutel]);
                $succesmelding = 'Update-feed-URL verwijderd, en de eerder opgehaalde "nieuwste versie" voor deze extensie is meteen opgeruimd - de status valt nu terug naar "Onbekend".';
            } elseif ($actie === 'bijwerken_zonder_github') {
                // Bewust NIET meesturen naar de auto-push hieronder: bedoeld
                // voor een lokale uitzondering (bijv. een site-specifieke
                // feed die een externe blokkade omzeilt, terwijl de
                // gedeelde/Github-versie van deze sleutel bij andere
                // installaties prima blijft werken en dus niet overschreven
                // hoeft te worden).
                $succesmelding = 'Update-feed-URL lokaal opgeslagen (niet naar Github gesynchroniseerd). Druk op "Scan en check sites" op de monitorpagina om de nieuwste versie meteen op te halen.';
            } else {
                $succesmelding = 'Update-feed-URL opgeslagen. Druk op "Scan en check sites" op de monitorpagina om de nieuwste versie meteen op te halen. Deze feed geldt vanaf nu voor elke site die dezelfde extensie heeft.';
            }
        }
    } elseif ($actie === 'negeren') {
        $sleutel = $_POST['sleutel'] ?? '';
        if ($sleutel !== '') {
            $stmt = $pdo->prepare("UPDATE extensie_catalogus SET genegeerd = 1, genegeerd_op = NOW() WHERE sleutel = ?");
            $stmt->execute([$sleutel]);
            $succesmelding = 'Extensie genegeerd - komt niet meer terug in het overzicht, ook niet na een nieuwe scan.';
        }
    } elseif ($actie === 'herstellen') {
        $sleutel = $_POST['sleutel'] ?? '';
        if ($sleutel !== '') {
            $stmt = $pdo->prepare("UPDATE extensie_catalogus SET genegeerd = 0, genegeerd_op = NULL WHERE sleutel = ?");
            $stmt->execute([$sleutel]);
            $succesmelding = 'Extensie hersteld - staat weer gewoon in het overzicht.';
        }
    } elseif ($actie === 'wissel_negeer_laatste_versiedeel') {
        $sleutel = strtolower(trim($_POST['sleutel'] ?? ''));
        // Alleen nodig voor het (zeldzame) geval dat deze extensie nog
        // helemaal geen rij in de catalogus heeft (bijv. vanuit de sectie
        // "Overige extensies" hieronder) - bij een bestaande rij wordt dit
        // simpelweg genegeerd en blijft het huidige label gewoon staan.
        $label = trim($_POST['label'] ?? $sleutel);

        if ($sleutel !== '') {
            $stmt = $pdo->prepare("
                INSERT INTO extensie_catalogus (sleutel, label, manifest_pad, update_feed_url, genegeerd, negeer_laatste_versiedeel)
                VALUES (?, ?, NULL, NULL, 0, 1)
                ON DUPLICATE KEY UPDATE negeer_laatste_versiedeel = NOT negeer_laatste_versiedeel
            ");
            $stmt->execute([$sleutel, $label]);
            $succesmelding = 'Instelling bijgewerkt - bekijk de kolom "Status" van deze extensie na de eerstvolgende scan.';
        }
    } elseif ($actie === 'verwijderen') {
        $sleutel = $_POST['sleutel'] ?? '';

        if ($sleutel !== '') {
            $stmt = $pdo->prepare("DELETE FROM extensie_catalogus WHERE sleutel = ?");
            $stmt->execute([$sleutel]);

            $stmt = $pdo->prepare("DELETE FROM site_extensies WHERE sleutel = ?");
            $stmt->execute([$sleutel]);

            $stmt = $pdo->prepare("DELETE FROM nieuwste_versies WHERE naam = ?");
            $stmt->execute([$sleutel]);

            $succesmelding = 'Extensie definitief verwijderd uit de catalogus. Let op: als de extensie nog op een site staat zonder automatische versie, kan een volgende scan deze weer aanmaken - gebruik anders "Negeren".';
        }
    } elseif ($actie === 'negeer_nieuw') {
        $sleutel = strtolower(trim($_POST['sleutel'] ?? ''));
        $label   = trim($_POST['label'] ?? '');

        if ($sleutel !== '' && $label !== '') {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO extensie_catalogus (sleutel, label, manifest_pad, update_feed_url, genegeerd, genegeerd_op)
                    VALUES (?, ?, NULL, NULL, 1, NOW())
                ");
                $stmt->execute([$sleutel, $label]);
                $succesmelding = "\"$label\" genegeerd - komt niet meer terug in het overzicht.";
            } catch (PDOException $e) {
                $foutmelding = 'Negeren is mislukt (bestaat de sleutel al?).';
            }
        }
    } elseif ($actie === 'bulk_negeer_ruis') {
        $alle = $pdo->query("SELECT sleutel, label FROM extensie_catalogus WHERE genegeerd = 0")->fetchAll(PDO::FETCH_ASSOC);
        $negeerStmt = $pdo->prepare("UPDATE extensie_catalogus SET genegeerd = 1, genegeerd_op = NOW() WHERE sleutel = ?");
        $aantal = 0;

        foreach ($alle as $rij) {
            $isLibrary = strpos($rij['sleutel'], 'library_') === 0;
            $isTaal = preg_match('/[a-z]{2}[-_][a-z]{2}\b/i', $rij['sleutel'])
                || preg_match('/[a-z]{2}[-_][a-z]{2}\b/i', $rij['label']);

            if ($isLibrary || $isTaal) {
                $negeerStmt->execute([$rij['sleutel']]);
                $aantal++;
            }
        }

        $succesmelding = "$aantal extensie(s) (libraries/taalbestanden) in één keer genegeerd.";
    }

    // ------------------------------------------------------------------
    // Elke wijziging die de gedeelde feed-URL's raakt, automatisch naar
    // Github pushen (als daar een repo + token voor is ingesteld) - zodat
    // een collega met een eigen, losse installatie hem via de melding
    // hieronder kan overnemen. Mislukt de push (bijv. geen internet, of
    // een verlopen token), dan blijft de lokale wijziging gewoon staan;
    // er verschijnt alleen een extra waarschuwing bij de succesmelding.
    // ------------------------------------------------------------------
    if (in_array($actie, ['toevoegen', 'bijwerken', 'verwijderen'], true) && $foutmelding === '' && $succesmelding !== '') {
        $githubInstellingen = catalogusGithubInstellingen($pdo);
        if (catalogusGithubIsIngesteld($githubInstellingen) && $githubInstellingen['token'] !== '') {
            $eigenNaam     = trim(haalInstelling($pdo, 'login_gebruikersnaam', ''));
            $pushResultaat = pushCatalogusNaarGithub($pdo, $eigenNaam);
            if ($pushResultaat['succes']) {
                $succesmelding .= ' Ook meteen naar Github gepusht.';
            } else {
                $succesmelding .= ' ⚠️ Pushen naar Github is niet gelukt: ' . $pushResultaat['foutmelding'];
            }
        }
    }
}

// ----------------------------------------------------------------------
// Site-kiezer: bepaalt op welke site de "zonder update-feed"-lijst
// hieronder gefilterd wordt. De "gedeelde" lijst (mét update-feed-URL)
// blijft altijd voor alle sites gelijk zichtbaar, ongeacht deze keuze.
// ----------------------------------------------------------------------
$alleSites = $pdo->query("SELECT id, domein FROM sites ORDER BY domein ASC")->fetchAll(PDO::FETCH_ASSOC);
$geselecteerdeSiteId = isset($_GET['site_id']) ? (int) $_GET['site_id'] : 0;

// Bepaalt of de "Opslaan met/zonder Github Sync"-keuze getoond wordt bij het
// update-feed-veld hieronder. Zonder ingevoerde token kan er toch niet naar
// Github gepusht worden (zie de auto-push hierboven, die precies dezelfde
// token-check gebruikt) - dan is de keuze zelf zinloos en verwarrend, en
// volstaat de simpele, originele "Opslaan"-knop.
$githubTokenIngesteld = catalogusGithubInstellingen($pdo)['token'] !== '';

$sleutelsOpGeselecteerdeSite = null; // null = geen filter (alle sites)
$nieuwsteVersieAlBekendOpDezeSite = []; // sleutel => true als déze site al een automatische versie heeft
if ($geselecteerdeSiteId > 0) {
    $sleutelsOpGeselecteerdeSite = [];
    // Let op: hier BEWUST dezelfde drie uitsluitingsfuncties toepassen als
    // het echte extensieoverzicht (haalDerdePartijExtensies() in
    // versie_vergelijk_functies.php) - anders komt een pakketonderdeel dat
    // op DEZE site keurig aan zijn package_id gekoppeld is (en dus nooit
    // los een eigen feed nodig heeft) toch in deze "zonder feed"-lijst
    // terecht, puur omdat dezelfde sleutel elders in extensie_catalogus
    // staat (die tabel is gedeeld over alle sites - zie ontvang_scan.php).
    // Zonder deze filter leek het bijv. of "com_jce" en losse AcyMailing-
    // subplugins hier hun eigen feed nodig hadden, terwijl ze op
    // joomlanl.nl gewoon al correct bij hun package horen en nooit los
    // getoond worden.
    $stmt = $pdo->prepare("
        SELECT type, element, folder, naam, auteur, package_id, enabled, nieuwste_versie
        FROM site_alle_extensies
        WHERE site_id = ?
    ");
    $stmt->execute([$geselecteerdeSiteId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
        if (isJoomlaKernExtensie($rij) || isOnderdeelVanPakket($rij) || isUitgeslotenVanExtensieoverzicht($rij)) {
            continue;
        }
        $sleutel = maakExtensieSleutel($rij['type'] ?? '', $rij['element'] ?? '');
        $sleutelsOpGeselecteerdeSite[$sleutel] = true;

        // Sommige gedeelde catalogus-sleutels blijven bewust bestaan omdat
        // een ANDERE site ze nog nodig heeft (zie de opschoonlogica in
        // ontvang_scan.php) - voor déze site is de sleutel dan feitelijk
        // al opgelost. Zonder deze markering lijkt zo'n rij hier identiek
        // aan een sleutel die echt nog een feed-URL nodig heeft.
        if (!empty($rij['nieuwste_versie'])) {
            $nieuwsteVersieAlBekendOpDezeSite[$sleutel] = true;
        }
    }
}

$toonGenegeerd = isset($_GET['toon_genegeerd']) && $_GET['toon_genegeerd'] == '1';
$genegeerdFilter = $toonGenegeerd ? '' : 'WHERE genegeerd = 0';

// Gedeelde lijst: extensies MET een update-feed-URL - geldt voor alle sites,
// dus altijd volledig getoond, ongeacht de sitekeuze hierboven.
$metFeed = $pdo->query("
    SELECT sleutel, label, manifest_pad, update_feed_url, feed_lokaal, genegeerd, genegeerd_op, negeer_laatste_versiedeel
    FROM extensie_catalogus
    $genegeerdFilter " . ($genegeerdFilter ? 'AND' : 'WHERE') . " update_feed_url IS NOT NULL AND update_feed_url != ''
    ORDER BY genegeerd ASC, label ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Per-site lijst: extensies ZONDER update-feed-URL, gefilterd op de
// geselecteerde site (indien gekozen).
$zonderFeedRuw = $pdo->query("
    SELECT sleutel, label, manifest_pad, update_feed_url, feed_lokaal, genegeerd, genegeerd_op, negeer_laatste_versiedeel
    FROM extensie_catalogus
    $genegeerdFilter " . ($genegeerdFilter ? 'AND' : 'WHERE') . " (update_feed_url IS NULL OR update_feed_url = '')
    ORDER BY genegeerd ASC, label ASC
")->fetchAll(PDO::FETCH_ASSOC);

if ($sleutelsOpGeselecteerdeSite === null) {
    $zonderFeed = $zonderFeedRuw;
} else {
    $zonderFeed = array_values(array_filter(
        $zonderFeedRuw,
        fn($rij) => isset($sleutelsOpGeselecteerdeSite[$rij['sleutel']])
    ));
}

$aantalGenegeerd = (int) $pdo->query("SELECT COUNT(*) FROM extensie_catalogus WHERE genegeerd = 1")->fetchColumn();

// Extensies die al automatisch een nieuwste versie hebben (dus nooit
// automatisch aan de catalogus zijn toegevoegd) maar nog niet negeerbaar
// zijn, omdat er nog geen rij voor bestaat.
$reedsOpgelost = haalReedsOpgelosteExtensiesZonderCatalogusRij($pdo, $geselecteerdeSiteId > 0 ? $geselecteerdeSiteId : null);

function bouwUrl(int $siteId, bool $toonGenegeerd): string
{
    $params = [];
    if ($siteId > 0) {
        $params['site_id'] = $siteId;
    }
    if ($toonGenegeerd) {
        $params['toon_genegeerd'] = 1;
    }
    return 'extensie_beheer.php' . (!empty($params) ? '?' . http_build_query($params) : '');
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
<title>Extensietabel beheren</title>
<style>

body {
    font-family: Arial, sans-serif;
    margin: 20px;
    font-size: 13px;
}

h1 {
    margin-bottom: 5px;
}

h2 {
    font-size: 15px;
    margin: 0 0 5px 0;
}

.subtitel {
    color: #555;
    margin-bottom: 20px;
}

table {
    border-collapse: collapse;
    width: 100%;
    margin-bottom: 15px;
}

th {
    background: #333;
    color: white;
    padding: 8px 6px;
    text-align: left;
    font-size: 12px;
}

td {
    border: 1px solid #ddd;
    padding: 7px;
    font-size: 12px;
    vertical-align: top;
    word-break: break-word;
}

tr:nth-child(even) td {
    background: #fafafa;
}

tr.genegeerd-rij td {
    background: var(--thema-genegeerd-bg) !important;
    color: var(--thema-genegeerd-tekst);
}

tr.genegeerd-rij .badge-automatisch {
    background: var(--thema-genegeerd-bg);
    color: var(--thema-genegeerd-tekst);
}

.melding {
    padding: 10px 14px;
    border-radius: 4px;
    margin-bottom: 15px;
    font-size: 13px;
}

.melding.ok   { background: #d4edda !important; color: #155724 !important; border: 1px solid #c3e6cb; }
.melding.fout { background: #f8d7da !important; color: #721c24 !important; border: 1px solid #f5c6cb; }

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

.knop.toevoegen {
    background: #1f6fa8;
}

.knop.toevoegen:hover {
    background: #175a87;
}

.knop.negeren {
    background: #767676;
    padding: 5px 10px;
    font-size: 12px;
}

.knop.negeren:hover {
    background: #5e5e5e;
}

.knop.negeer-versiedeel {
    background: #767676;
    padding: 5px 10px;
    font-size: 12px;
}

.knop.negeer-versiedeel:hover {
    background: #5e5e5e;
}

.knop.verwijderen {
    background: #c0392b;
    padding: 5px 10px;
    font-size: 12px;
}

.knop.verwijderen:hover {
    background: #96281e;
}

.knop.herstellen {
    background: #2e8b3d;
    padding: 5px 10px;
    font-size: 12px;
}

.knop.herstellen:hover {
    background: #24702f;
}

.knop.opslaan {
    background: #2e8b3d;
    padding: 6px 10px;
    font-size: 12px;
    white-space: nowrap;
    align-self: flex-start;
}

.knop.opslaan:hover {
    background: #24702f;
}

.feed-form {
    display: flex;
    gap: 6px;
    align-items: flex-start;
}

.feed-form textarea[name="update_feed_url"] {
    flex: 1;
    max-width: 320px;
    min-width: 0;
    padding: 6px;
    font-size: 12px;
    font-family: inherit;
    resize: vertical;
    overflow-wrap: break-word;
}

.acties-rij {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    max-width: 220px;
}

.acties-rij form {
    flex: 1 1 auto;
    min-width: 0;
    max-width: 100%;
}

.acties-rij .knop {
    width: 100%;
    white-space: normal;
    word-break: break-word;
    text-align: center;
    box-sizing: border-box;
}

.badge-automatisch {
    display: inline-block;
    margin-top: 3px;
    padding: 2px 6px;
    border-radius: 3px;
    background: var(--thema-badge-bg);
    color: var(--thema-badge-tekst);
    font-size: 10px;
}

.badge-gedeeld {
    display: inline-block;
    margin-top: 3px;
    padding: 2px 6px;
    border-radius: 3px;
    background: var(--thema-vertrouwd-bg);
    color: var(--thema-vertrouwd-tekst);
    font-size: 10px;
}

header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 5px;
}

header h1 {
    margin: 0 0 5px 0;
}

header .knop {
    margin-top: 0;
}

.acties-boven {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
    flex-wrap: wrap;
    align-items: center;
}

.site-kiezer {
    margin-bottom: 25px;
    padding: 15px 20px;
    background: var(--thema-zebra);
    border: 1px solid var(--thema-rand);
    border-radius: 6px;
}

.site-kiezer label {
    font-weight: bold;
    margin-right: 10px;
}

.site-kiezer select {
    padding: 6px 10px;
    font-size: 13px;
}

.blok-uitleg {
    color: #666;
    font-size: 12px;
    margin-bottom: 10px;
}

#toevoegformulier {
    display: none;
    margin-bottom: 25px;
    padding: 20px;
    border: 1px solid var(--thema-rand);
    background: var(--thema-kader-bg);
    border-radius: 6px;
    max-width: 600px;
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

input[type="text"],
input[type="url"] {
    width: 100%;
    padding: 8px;
    box-sizing: border-box;
    font-size: 13px;
}

.knop-rij {
    margin-top: 20px;
    display: flex;
    gap: 10px;
}

</style>
<?php include 'responsive_stijlen.php'; ?>
</head>
<body>

<header>
    <div>
        <h1>🧩 Extensietabel beheren</h1>
        <div class="subtitel">Beheer hier welke extensies het systeem herkent, en vul update-feed-URL's in voor extensies waarvoor geen automatische versie gevonden kon worden.</div>
    </div>
    <div style="display: flex; gap: 8px;">
        <button type="button" class="knop" onclick="history.back()" style="padding: 8px 12px;" title="Eén stap terug">←</button>
        <a class="knop" href="index.php">Terug naar monitor</a>
    </div>
</header>

<?php if ($succesmelding !== ''): ?>
    <div class="melding ok"><?php echo htmlspecialchars($succesmelding); ?></div>
<?php endif; ?>
<?php if ($foutmelding !== ''): ?>
    <div class="melding fout"><?php echo htmlspecialchars($foutmelding); ?></div>
<?php endif; ?>

<div id="github-catalogus-banner" style="display: none;"></div>

<div class="site-kiezer">
    <form method="get" style="display: flex; align-items: center; gap: 10px;">
        <label for="site_id">Website:</label>
        <select name="site_id" id="site_id" onchange="this.form.submit()">
            <option value="0">— Alle sites (ongefilterd) —</option>
            <?php foreach ($alleSites as $site): ?>
                <option value="<?php echo (int) $site['id']; ?>" <?php echo $geselecteerdeSiteId === (int) $site['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($site['domein']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($toonGenegeerd): ?><input type="hidden" name="toon_genegeerd" value="1"><?php endif; ?>
    </form>
</div>

<div class="acties-boven">
    <button type="button" class="knop toevoegen" onclick="toonFormulier()">➕ Nieuwe extensie toevoegen</button>

    <form method="post" onsubmit="return confirm('Alle libraries en taalbestanden in één keer negeren?');" style="display: inline;">
        <?php echo csrfVeld(); ?>
        <input type="hidden" name="actie" value="bulk_negeer_ruis">
        <button type="submit" class="knop negeren">🧹 Negeer alle libraries/taalbestanden</button>
    </form>

    <?php if ($toonGenegeerd): ?>
        <a class="knop" href="<?php echo htmlspecialchars(bouwUrl($geselecteerdeSiteId, false)); ?>">Verberg genegeerde extensies</a>
    <?php else: ?>
        <a class="knop" href="<?php echo htmlspecialchars(bouwUrl($geselecteerdeSiteId, true)); ?>">Toon ook genegeerde extensies (<?php echo $aantalGenegeerd; ?>)</a>
    <?php endif; ?>
</div>

<div class="blok" style="margin-bottom: 20px;">
    <h2 style="margin-top: 0;">📦 Update-feed-URL uit lokaal installatiepakket halen</h2>
    <div class="blok-uitleg">
        Heb je het installatiebestand (.zip) van een extensie op je eigen pc staan? Selecteer 'm hieronder - de
        monitor leest het manifest binnen het pakket uit op zoek naar de update-feed-URL, en probeert die
        automatisch in het juiste veld hieronder te zetten. Het geüploade bestand wordt direct na het uitlezen
        weer van de server verwijderd.
    </div>
    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 10px;">
        <input type="file" id="pakket_bestand" accept=".zip">
        <button type="button" class="knop" onclick="zoekFeedInPakket(this)">🔍 Zoeken in pakket</button>
    </div>
    <div id="pakket-resultaat" style="display: none; margin-top: 10px; padding: 8px 12px; border-radius: 4px; font-size: 13px;"></div>
</div>

<div id="toevoegformulier">
    <form method="post">
        <?php echo csrfVeld(); ?>
        <input type="hidden" name="actie" value="toevoegen">

        <label for="sleutel">Sleutel</label>
        <div class="uitleg">Een korte technische code voor deze extensie, bijv. <code>sef</code> of <code>rsform</code>. Alleen kleine letters, cijfers en underscores (_), geen spaties. Wordt intern gebruikt om de extensie te herkennen.</div>
        <input type="text" id="sleutel" name="sleutel" required pattern="[a-z0-9_]+" placeholder="bijv. sef">

        <label for="label">Label</label>
        <div class="uitleg">De naam zoals die getoond wordt in de overzichten, bijv. "SEF Advance" of "RSForm Pro".</div>
        <input type="text" id="label" name="label" required placeholder="bijv. SEF Advance">

        <label for="manifest_pad">Manifest-pad <span style="font-weight: normal;">(optioneel)</span></label>
        <div class="uitleg">
            Alleen nodig als je wil dat het systeem deze extensie ook via een manifest-bestand probeert te detecteren (naast de automatische detectie via <code>scan-en-check-website.php</code>). Pad relatief aan de <code>administrator</code>-map, meestal één van deze twee vormen:<br>
            &bull; <code>manifests/packages/pkg_&lt;naam&gt;.xml</code> (bij een package-extensie)<br>
            &bull; <code>components/com_&lt;naam&gt;/&lt;naam&gt;.xml</code> (bij een los component)<br>
            Laat dit leeg als je alleen een update-feed-URL wil koppelen aan een al automatisch gedetecteerde extensie.
        </div>
        <input type="text" id="manifest_pad" name="manifest_pad" placeholder="bijv. manifests/packages/pkg_sef.xml">

        <label for="update_feed_url">Update-feed-URL <span style="font-weight: normal;">(optioneel)</span></label>
        <div class="uitleg">Als deze extensie een publieke update-XML-feed heeft, kan die hier ingevuld worden - dan wordt automatisch bijgehouden of de geïnstalleerde versie up-to-date is. Laat dit leeg als je geen publieke feed kent; de extensie wordt dan gewoon gedetecteerd, maar de status blijft "Onbekend" in plaats van te gokken.</div>
        <input type="url" id="update_feed_url" name="update_feed_url" placeholder="bijv. https://voorbeeld.nl/updates/pkg_sef.xml">

        <div class="knop-rij">
            <button type="submit" class="knop toevoegen">Toevoegen</button>
            <button type="button" class="knop" onclick="verbergFormulier()">Annuleren</button>
        </div>
    </form>
</div>

<h2>🌐 Gedeelde extensies met update-feed (alle sites)</h2>
<div class="blok-uitleg">
    Deze extensies hebben al een update-feed-URL en gelden daarmee automatisch voor ELKE site die dezelfde extensie
    heeft geïnstalleerd - deze lijst is dus altijd volledig, ongeacht de sitekeuze hierboven.
</div>

<table class="responsive-tabel">
<tr>
    <th style="width: 130px;">Sleutel</th>
    <th style="width: 180px;">Label</th>
    <th style="width: 160px;">Manifest-pad</th>
    <th>Update-feed-URL</th>
    <th style="width: 140px;">Actie</th>
</tr>
<?php if (empty($metFeed)): ?>
<tr>
    <td colspan="5"><em>Nog geen extensies met een update-feed-URL.</em></td>
</tr>
<?php endif; ?>
<?php foreach ($metFeed as $extensie): ?>
<?php $isGenegeerd = !empty($extensie['genegeerd']); ?>
<tr class="<?php echo $isGenegeerd ? 'genegeerd-rij' : ''; ?>" data-sleutel="<?php echo htmlspecialchars($extensie['sleutel']); ?>">
    <td data-label="Sleutel"><code><?php echo htmlspecialchars($extensie['sleutel']); ?></code></td>
    <td data-label="Label">
        <?php echo htmlspecialchars($extensie['label']); ?>
        <?php $isFeedLokaal = (int) ($extensie['feed_lokaal'] ?? 0) === 1; ?>
        <?php if ($isFeedLokaal): ?>
            <br><span class="badge-automatisch" style="background: var(--thema-geel); color: var(--thema-bg);">💻 lokaal (niet op Github)</span>
        <?php else: ?>
            <br><span class="badge-gedeeld">☁️ gedeeld via Github</span>
        <?php endif; ?>
        <?php if ($isGenegeerd): ?><br><span class="badge-automatisch">genegeerd<?php echo $extensie['genegeerd_op'] ? ' op ' . htmlspecialchars(date('d-m-Y H:i', strtotime($extensie['genegeerd_op']))) : ''; ?></span><?php endif; ?>
    </td>
    <td data-label="Manifest-pad"><?php echo $extensie['manifest_pad'] ? htmlspecialchars($extensie['manifest_pad']) : '<em>geen</em>'; ?></td>
    <td data-label="Update-feed-URL">
        <form method="post" class="feed-form">
            <?php echo csrfVeld(); ?>
            <input type="hidden" name="sleutel" value="<?php echo htmlspecialchars($extensie['sleutel']); ?>">
            <textarea name="update_feed_url" rows="2" placeholder="https://voorbeeld.nl/updates/pkg_x.xml"><?php echo htmlspecialchars($extensie['update_feed_url'] ?? ''); ?></textarea>
            <?php if ($githubTokenIngesteld): ?>
            <div style="display: flex; flex-direction: column; gap: 4px;">
                <button type="submit" name="actie" value="bijwerken" class="knop opslaan">Opslaan met GitHub Sync</button>
                <button type="submit" name="actie" value="bijwerken_zonder_github" class="knop opslaan" style="background: #767676;" title="Voor een lokale uitzondering die alleen op deze installatie hoeft te gelden - andere installaties (bijv. Astrid's) blijven ongemoeid.">Opslaan zonder GitHub Sync</button>
            </div>
            <?php else: ?>
            <button type="submit" name="actie" value="bijwerken" class="knop opslaan">Opslaan</button>
            <?php endif; ?>
        </form>
    </td>
    <td data-label="Actie">
        <div class="acties-rij">
        <?php if ($isGenegeerd): ?>
            <form method="post">
                <?php echo csrfVeld(); ?>
                <input type="hidden" name="actie" value="herstellen">
                <input type="hidden" name="sleutel" value="<?php echo htmlspecialchars($extensie['sleutel']); ?>">
                <button type="submit" class="knop herstellen">Niet meer negeren</button>
            </form>
        <?php else: ?>
            <form method="post">
                <?php echo csrfVeld(); ?>
                <input type="hidden" name="actie" value="negeren">
                <input type="hidden" name="sleutel" value="<?php echo htmlspecialchars($extensie['sleutel']); ?>">
                <button type="submit" class="knop negeren">Negeren</button>
            </form>
        <?php endif; ?>
        <?php $isNegeerLaatsteDeel = !empty($extensie['negeer_laatste_versiedeel']); ?>
        <form method="post" title="Bijv. voor taalbestanden met een eigen build-nummer (6.1.2.1) achter de kernversie - dan telt alleen 6.1.2 mee bij het bepalen van 'up-to-date'.">
            <?php echo csrfVeld(); ?>
            <input type="hidden" name="actie" value="wissel_negeer_laatste_versiedeel">
            <input type="hidden" name="sleutel" value="<?php echo htmlspecialchars($extensie['sleutel']); ?>">
            <button type="submit" class="knop <?php echo $isNegeerLaatsteDeel ? 'herstellen' : 'negeer-versiedeel'; ?>"><?php echo $isNegeerLaatsteDeel ? 'Alleen x.xx.y meetellen' : 'Alleen x.xx.y negeren'; ?></button>
        </form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</table>

<h2>
    📄 Extensies zonder update-feed
    <?php if ($geselecteerdeSiteId > 0): ?>
        <?php foreach ($alleSites as $s): if ((int)$s['id'] === $geselecteerdeSiteId): ?>
            — <?php echo htmlspecialchars($s['domein']); ?>
        <?php endif; endforeach; ?>
    <?php endif; ?>
</h2>
<div class="blok-uitleg">
    <?php if ($geselecteerdeSiteId > 0): ?>
        Alleen de extensies die op de gekozen website zijn gedetecteerd. Vul een update-feed-URL in om een extensie
        naar de gedeelde lijst hierboven te verplaatsen (geldt dan meteen voor alle sites met diezelfde extensie).
    <?php else: ?>
        Kies hierboven een specifieke website om alleen de extensies van die site te zien. Nu getoond: alle sites samen (ongefilterd).
    <?php endif; ?>
</div>

<table class="responsive-tabel">
<tr>
    <th style="width: 130px;">Sleutel</th>
    <th style="width: 180px;">Label</th>
    <th style="width: 160px;">Manifest-pad</th>
    <th>Update-feed-URL</th>
    <th style="width: 140px;">Actie</th>
</tr>
<?php if (empty($zonderFeed)): ?>
<tr>
    <td colspan="5">
        <?php echo $geselecteerdeSiteId > 0
            ? 'Geen (nog niet opgeloste) extensies gevonden voor deze site.'
            : 'Nog geen extensies zonder update-feed in de catalogus.'; ?>
    </td>
</tr>
<?php endif; ?>
<?php foreach ($zonderFeed as $extensie): ?>
<?php $isGenegeerd = !empty($extensie['genegeerd']); ?>
<tr class="<?php echo $isGenegeerd ? 'genegeerd-rij' : ''; ?>" data-sleutel="<?php echo htmlspecialchars($extensie['sleutel']); ?>">
    <td data-label="Sleutel"><code><?php echo htmlspecialchars($extensie['sleutel']); ?></code></td>
    <td data-label="Label">
        <?php echo htmlspecialchars($extensie['label']); ?>
        <?php if (empty($extensie['manifest_pad'])): ?>
            <br><span class="badge-automatisch">automatisch gedetecteerd</span>
        <?php endif; ?>
        <?php if ($isGenegeerd): ?>
            <br><span class="badge-automatisch">genegeerd<?php echo $extensie['genegeerd_op'] ? ' op ' . htmlspecialchars(date('d-m-Y H:i', strtotime($extensie['genegeerd_op']))) : ''; ?></span>
        <?php endif; ?>
        <?php if (!empty($nieuwsteVersieAlBekendOpDezeSite[$extensie['sleutel']])): ?>
            <br><span class="badge-automatisch" title="Deze sleutel staat nog in de lijst omdat minstens één andere site 'm nog nodig heeft - zie de opschoonlogica in ontvang_scan.php.">al automatisch opgelost voor déze site (nog benodigd voor 1+ andere site(s))</span>
        <?php endif; ?>
    </td>
    <td data-label="Manifest-pad"><?php echo $extensie['manifest_pad'] ? htmlspecialchars($extensie['manifest_pad']) : '<em>geen</em>'; ?></td>
    <td data-label="Update-feed-URL">
        <form method="post" class="feed-form">
            <?php echo csrfVeld(); ?>
            <input type="hidden" name="sleutel" value="<?php echo htmlspecialchars($extensie['sleutel']); ?>">
            <textarea name="update_feed_url" rows="2" placeholder="https://voorbeeld.nl/updates/pkg_x.xml"><?php echo htmlspecialchars($extensie['update_feed_url'] ?? ''); ?></textarea>
            <?php if ($githubTokenIngesteld): ?>
            <div style="display: flex; flex-direction: column; gap: 4px;">
                <button type="submit" name="actie" value="bijwerken" class="knop opslaan">Opslaan met GitHub Sync</button>
                <button type="submit" name="actie" value="bijwerken_zonder_github" class="knop opslaan" style="background: #767676;" title="Voor een lokale uitzondering die alleen op deze installatie hoeft te gelden - andere installaties (bijv. Astrid's) blijven ongemoeid.">Opslaan zonder GitHub Sync</button>
            </div>
            <?php else: ?>
            <button type="submit" name="actie" value="bijwerken" class="knop opslaan">Opslaan</button>
            <?php endif; ?>
        </form>
    </td>
    <td data-label="Actie">
        <div class="acties-rij">
        <?php if ($isGenegeerd): ?>
            <form method="post">
                <?php echo csrfVeld(); ?>
                <input type="hidden" name="actie" value="herstellen">
                <input type="hidden" name="sleutel" value="<?php echo htmlspecialchars($extensie['sleutel']); ?>">
                <button type="submit" class="knop herstellen">Niet meer negeren</button>
            </form>
            <form method="post" onsubmit="return confirm('Definitief verwijderen? Als de extensie nog op een site staat, kan een scan deze weer aanmaken.');">
                <?php echo csrfVeld(); ?>
                <input type="hidden" name="actie" value="verwijderen">
                <input type="hidden" name="sleutel" value="<?php echo htmlspecialchars($extensie['sleutel']); ?>">
                <button type="submit" class="knop verwijderen">Verwijderen</button>
            </form>
        <?php else: ?>
            <form method="post">
                <?php echo csrfVeld(); ?>
                <input type="hidden" name="actie" value="negeren">
                <input type="hidden" name="sleutel" value="<?php echo htmlspecialchars($extensie['sleutel']); ?>">
                <button type="submit" class="knop negeren">Negeren</button>
            </form>
        <?php endif; ?>
        <?php $isNegeerLaatsteDeel = !empty($extensie['negeer_laatste_versiedeel']); ?>
        <form method="post" title="Bijv. voor taalbestanden met een eigen build-nummer (6.1.2.1) achter de kernversie - dan telt alleen 6.1.2 mee bij het bepalen van 'up-to-date'.">
            <?php echo csrfVeld(); ?>
            <input type="hidden" name="actie" value="wissel_negeer_laatste_versiedeel">
            <input type="hidden" name="sleutel" value="<?php echo htmlspecialchars($extensie['sleutel']); ?>">
            <button type="submit" class="knop <?php echo $isNegeerLaatsteDeel ? 'herstellen' : 'negeer-versiedeel'; ?>"><?php echo $isNegeerLaatsteDeel ? 'Alleen x.xx.y meetellen' : 'Alleen x.xx.y negeren'; ?></button>
        </form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</table>

<h2>✅ Overige extensies (werken al automatisch, optioneel te negeren)</h2>
<div class="blok-uitleg">
    Deze extensies hebben al automatisch een nieuwste versie (via Joomla's eigen update-registratie op de site
    zelf), en staan daarom nooit in de tabellen hierboven - er is simpelweg niets aan te vullen. Wil je zo'n
    extensie toch liever niet in het extensieoverzicht zien (bijv. een taalpakket waar je niet in geïnteresseerd
    bent), dan kun je 'm hier alsnog negeren.
    <?php if ($geselecteerdeSiteId === 0): ?>
        Nu getoond: unieke extensies over alle sites samen - kies hierboven een specifieke site voor een preciezere lijst.
    <?php endif; ?>
</div>

<table class="responsive-tabel">
<tr>
    <th style="width: 130px;">Sleutel</th>
    <th style="width: 250px;">Label</th>
    <th>Huidige status</th>
    <th style="width: 220px;">Actie</th>
</tr>
<?php if (empty($reedsOpgelost)): ?>
<tr>
    <td colspan="4"><em>Niets gevonden - alles wat al automatisch werkt staat momenteel ook al in de catalogus, of er is nog niets gescand.</em></td>
</tr>
<?php endif; ?>
<?php foreach ($reedsOpgelost as $groep): ?>
<tr>
    <td data-label="Sleutel"><code><?php echo htmlspecialchars($groep['sleutel']); ?></code></td>
    <td data-label="Label"><?php echo htmlspecialchars($groep['naam']); ?> <span class="badge-gedeeld">automatisch opgelost</span></td>
    <td data-label="Huidige status"><?php echo htmlspecialchars($groep['versie'] ?? '-'); ?> → nieuwste <?php echo htmlspecialchars($groep['nieuwste_versie'] ?? '-'); ?></td>
    <td data-label="Actie">
        <div class="acties-rij">
        <form method="post" onsubmit="return confirm('Deze extensie negeren? Komt dan niet meer terug in het overzicht.');">
            <?php echo csrfVeld(); ?>
            <input type="hidden" name="actie" value="negeer_nieuw">
            <input type="hidden" name="sleutel" value="<?php echo htmlspecialchars($groep['sleutel']); ?>">
            <input type="hidden" name="label" value="<?php echo htmlspecialchars($groep['naam']); ?>">
            <button type="submit" class="knop negeren">Negeren</button>
        </form>
        <form method="post" title="Bijv. voor taalbestanden met een eigen build-nummer (6.1.2.1) achter de kernversie - dan telt alleen 6.1.2 mee bij het bepalen van 'up-to-date'.">
            <?php echo csrfVeld(); ?>
            <input type="hidden" name="actie" value="wissel_negeer_laatste_versiedeel">
            <input type="hidden" name="sleutel" value="<?php echo htmlspecialchars($groep['sleutel']); ?>">
            <input type="hidden" name="label" value="<?php echo htmlspecialchars($groep['naam']); ?>">
            <button type="submit" class="knop negeer-versiedeel">Alleen x.xx.y negeren</button>
        </form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</table>

<script>
const CSRF_TOKEN = <?php echo json_encode(haalCsrfToken()); ?>;

// ----------------------------------------------------------------------
// Github-synchronisatie: bij het laden van de pagina controleren of er
// nieuwe/gewijzigde update-feed-URL's op Github staan t.o.v. deze lokale
// installatie, en als dat zo is een banner tonen met een importknop.
// Alleen actief als er bij Configuratie een Github-repo is ingesteld -
// anders geeft de AJAX-aanroep gewoon een lege lijst terug (geen fout).
// ----------------------------------------------------------------------
function escapeHtmlGithubBanner(tekst) {
    const div = document.createElement('div');
    div.textContent = tekst == null ? '' : String(tekst);
    return div.innerHTML;
}

function controleerGithubCatalogus() {
    const banner = document.getElementById('github-catalogus-banner');

    fetch('catalogus_github_ajax.php?actie=controleer')
        .then(r => r.json())
        .then(data => {
            if (!data.succes) {
                if (data.foutmelding) {
                    banner.style.display = 'block';
                    banner.innerHTML = '<div class="melding fout">⚠️ Ophalen van de Github-catalogus is niet gelukt: '
                        + escapeHtmlGithubBanner(data.foutmelding) + '</div>';
                }
                return; // geen repo ingesteld, of een fout - niets te melden
            }

            const totaal = data.nieuw.length + data.gewijzigd.length;
            if (totaal === 0) {
                return; // niets nieuws, banner blijft verborgen
            }

            let regels = '';
            data.nieuw.forEach(item => {
                regels += '<label style="display: block; margin: 4px 0;">'
                    + '<input type="checkbox" class="github-item-checkbox" value="' + escapeHtmlGithubBanner(item.sleutel) + '" checked> '
                    + '<strong>' + escapeHtmlGithubBanner(item.label || item.sleutel) + '</strong> (nieuw) — '
                    + '<code>' + escapeHtmlGithubBanner(item.update_feed_url || '') + '</code></label>';
            });
            data.gewijzigd.forEach(item => {
                regels += '<label style="display: block; margin: 4px 0;">'
                    + '<input type="checkbox" class="github-item-checkbox" value="' + escapeHtmlGithubBanner(item.sleutel) + '"> '
                    + '<strong>' + escapeHtmlGithubBanner(item.label) + '</strong> (gewijzigd) — '
                    + 'nu: <code>' + escapeHtmlGithubBanner(item.lokale_url) + '</code> → '
                    + 'Github: <code>' + escapeHtmlGithubBanner(item.github_url) + '</code></label>';
            });

            const wie = data.bijgewerkt_door ? ' door ' + escapeHtmlGithubBanner(data.bijgewerkt_door) : '';

            banner.style.display = 'block';
            banner.innerHTML = '<div class="melding" style="background: #fff3cd; color: #665200; border: 1px solid #ffe69c;">'
                + '<strong>🔄 Er staan ' + totaal + ' update-feed-URL(s) op Github' + wie + ' die je nog niet lokaal hebt.</strong>'
                + ' Vink aan wat je wil overnemen (nieuwe items staan al aangevinkt, gewijzigde bewust niet):'
                + '<div style="margin: 10px 0;">' + regels + '</div>'
                + '<button type="button" class="knop opslaan" onclick="importeerGeselecteerdeGithubItems(this)">⬇️ Geselecteerde items importeren</button>'
                + ' <span id="github-import-resultaat"></span>'
                + '</div>';
        })
        .catch(() => {
            // Stil falen - een verbindingsprobleem hier mag de rest van de pagina niet verstoren.
        });
}

function importeerGeselecteerdeGithubItems(knop) {
    const sleutels = Array.from(document.querySelectorAll('.github-item-checkbox:checked')).map(el => el.value);
    const resultaat = document.getElementById('github-import-resultaat');

    if (sleutels.length === 0) {
        resultaat.textContent = 'Selecteer eerst minstens één item.';
        return;
    }

    knop.disabled = true;
    resultaat.textContent = '⏳ Bezig met importeren...';

    const body = new FormData();
    body.append('csrf_token', CSRF_TOKEN);
    body.append('actie', 'importeer');
    sleutels.forEach(sleutel => body.append('sleutels[]', sleutel));

    fetch('catalogus_github_ajax.php', { method: 'POST', body: body })
        .then(r => r.json())
        .then(data => {
            knop.disabled = false;
            if (!data.succes) {
                resultaat.textContent = '❌ ' + data.foutmelding;
                return;
            }
            resultaat.textContent = '✅ ' + data.aantal + ' item(s) geïmporteerd - de pagina wordt herladen...';
            setTimeout(() => window.location.reload(), 1200);
        })
        .catch(err => {
            knop.disabled = false;
            resultaat.textContent = '❌ Er ging iets mis: ' + err.message;
        });
}

controleerGithubCatalogus();

function toonFormulier() {
    document.getElementById('toevoegformulier').style.display = 'block';
}
function verbergFormulier() {
    document.getElementById('toevoegformulier').style.display = 'none';
}

function escapeHtmlVoorResultaat(tekst) {
    const div = document.createElement('div');
    div.textContent = tekst == null ? '' : String(tekst);
    return div.innerHTML;
}

function kopieerFeedUrl(knop, url) {
    if (!navigator.clipboard) {
        // Clipboard-API is niet beschikbaar (bijv. de pagina draait over
        // gewoon HTTP i.p.v. HTTPS, waar browsers dit bewust blokkeren) -
        // dan in elk geval de URL zichtbaar tonen om handmatig te kopiëren.
        prompt('Kopiëren via de knop is hier niet mogelijk - selecteer en kopieer de URL hieronder handmatig:', url);
        return;
    }
    navigator.clipboard.writeText(url).then(() => {
        const origineleTekst = knop.textContent;
        knop.textContent = '✅ Gekopieerd!';
        knop.disabled = true;
        setTimeout(() => {
            knop.textContent = origineleTekst;
            knop.disabled = false;
        }, 1500);
    }).catch(() => {
        prompt('Kopiëren is niet gelukt - selecteer en kopieer de URL hieronder handmatig:', url);
    });
}

function zoekFeedInPakket(knop) {
    const bestandVeld = document.getElementById('pakket_bestand');
    const resultaat = document.getElementById('pakket-resultaat');

    if (!bestandVeld.files || bestandVeld.files.length === 0) {
        resultaat.style.display = 'block';
        resultaat.style.background = '#f8d7da';
        resultaat.style.color = '#721c24';
        resultaat.textContent = '❌ Kies eerst een .zip-installatiepakket.';
        return;
    }

    knop.disabled = true;
    resultaat.style.display = 'block';
    resultaat.style.background = '#eef1f4';
    resultaat.style.color = '#333';
    resultaat.textContent = '⏳ Pakket wordt geüpload en uitgelezen...';

    const body = new FormData();
    body.append('csrf_token', CSRF_TOKEN);
    body.append('pakket', bestandVeld.files[0]);

    fetch('haal_feed_uit_pakket.php', {
        method: 'POST',
        body: body
    })
        .then(r => r.json())
        .then(data => {
            knop.disabled = false;

            if (!data.succes) {
                resultaat.style.background = '#f8d7da';
                resultaat.style.color = '#721c24';
                resultaat.textContent = '❌ ' + data.foutmelding;
                return;
            }

            // Proberen automatisch in de juiste rij te zetten, op basis van
            // de op dezelfde manier opgebouwde sleutel als de rest van het
            // systeem gebruikt.
            let veldGevonden = null;
            if (data.sleutel) {
                const rij = document.querySelector('tr[data-sleutel="' + CSS.escape(data.sleutel) + '"]');
                if (rij) {
                    veldGevonden = rij.querySelector('textarea[name="update_feed_url"]');
                }
            }

            // Kopieerknop hoort bij BEIDE gevallen, ook als het veld al
            // automatisch is ingevuld - scheelt een handmatige, foutgevoelige
            // selectie/kopieerpoging (bijv. per ongeluk de "l" van ".xml"
            // niet meenemen) als de URL ergens anders ook nog nodig is.
            const kopieerKnopHtml = '<button type="button" class="knop opslaan" style="margin-left: 8px;" '
                + 'data-feed-url="' + escapeHtmlVoorResultaat(data.feed_url) + '" '
                + 'onclick="kopieerFeedUrl(this, this.dataset.feedUrl)">📋 Kopieer URL</button>';

            if (veldGevonden) {
                veldGevonden.value = data.feed_url;
                veldGevonden.style.background = '#fff3cd';
                veldGevonden.scrollIntoView({ behavior: 'smooth', block: 'center' });
                veldGevonden.focus();

                resultaat.style.background = '#d4edda';
                resultaat.style.color = '#155724';
                resultaat.innerHTML = '✅ Gevonden: "' + escapeHtmlVoorResultaat(data.feed_url) + '" - is hieronder al ingevuld bij "'
                    + escapeHtmlVoorResultaat(data.naam) + '". Druk op "Opslaan" bij die rij om te bevestigen.'
                    + kopieerKnopHtml;
            } else {
                resultaat.style.background = '#fff3cd';
                resultaat.style.color = '#665200';
                resultaat.innerHTML = '✅ Gevonden: "' + escapeHtmlVoorResultaat(data.feed_url) + '" (extensie: ' + escapeHtmlVoorResultaat(data.naam)
                    + ') - maar deze extensie staat nog niet in onderstaande lijst, dus kon niet automatisch worden'
                    + ' ingevuld. Kopieer de URL naar de juiste rij met de knop hieronder, of voeg de extensie eerst toe/wacht'
                    + ' tot deze bij een scan wordt gedetecteerd.'
                    + kopieerKnopHtml;
            }
        })
        .catch(err => {
            knop.disabled = false;
            resultaat.style.background = '#f8d7da';
            resultaat.style.color = '#721c24';
            resultaat.textContent = '❌ Er ging iets mis: ' + err.message;
        });
}
</script>

<?php include 'terug_naar_boven.php'; ?>
</body>
</html>
