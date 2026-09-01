<?php
require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: login.php");
    exit;
}
// Deze pagina toont per definitie actuele scangegevens - een browser (of
// een cache-laag ertussen) mag 'm dus nooit bewaren en later hergebruiken.
// Zonder dit kon een eerder bezochte, inmiddels verouderde weergave van
// deze pagina blijven "hangen", ook na een nieuwe scan met correcte
// gegevens (zie ook dezelfde headers op index.php).
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once 'config.php';
require_once 'versie_vergelijk_functies.php';
require_once 'csrf_functies.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Snel-negeren rechtstreeks vanaf deze pagina (in plaats van eerst naar
// "Extensietabel beheren" te moeten) - een upsert, zodat dit ook werkt als
// deze extensie nog helemaal geen rij in de catalogus heeft.
//
// Werkt met EEN LIJST sleutels (sleutels[]), niet met precies één: een
// getoonde rij kan meerdere onderliggende catalogus-sleutels bundelen
// (bijv. "com_bergkerkagenda" = component_com_bergkerkagenda +
// module_mod_bergkerk_agenda). isGegroepeerdProductVolledigGenegeerd()
// verbergt de rij pas als ECHT elk onderdeel genegeerd is, dus moet hier
// ook echt elk onderdeel genegeerd worden - anders blijft de rij na het
// klikken op "Negeren" alsnog zichtbaar. 'sleutel' (enkelvoud) blijft als
// terugval werken voor eventuele oudere, nog niet ververste pagina's.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['actie'] ?? '') === 'negeer_snel') {
    vereistGeldigCsrfToken();

    $sleutels = $_POST['sleutels'] ?? ($_POST['sleutel'] ?? null);
    $sleutels = is_array($sleutels) ? $sleutels : [$sleutels];
    $sleutels = array_unique(array_filter(array_map(fn($s) => strtolower(trim((string) $s)), $sleutels)));
    $label    = trim($_POST['label'] ?? '');

    $stmt = $pdo->prepare("
        INSERT INTO extensie_catalogus (sleutel, label, manifest_pad, update_feed_url, genegeerd, genegeerd_op)
        VALUES (?, ?, NULL, NULL, 1, NOW())
        ON DUPLICATE KEY UPDATE genegeerd = 1, genegeerd_op = NOW()
    ");
    foreach ($sleutels as $sleutel) {
        $stmt->execute([$sleutel, $label !== '' ? $label : $sleutel]);
    }

    header("Location: extensies.php?id=" . $id . "&genegeerd=1");
    exit;
}

// Snelle tegenhanger van hierboven: een genegeerde extensie direct vanaf
// deze pagina weer herstellen, zonder om te hoeven naar "Extensietabel
// beheren". Alleen zinvol te bereiken als de genegeerde extensies ook
// daadwerkelijk getoond worden (zie $toonGenegeerd hieronder), maar de
// actie zelf staat hier los van - een POST hiernaartoe werkt sowieso.
// Zelfde sleutels[]-aanpak als hierboven, om consistent alle onderdelen
// van een gebundelde rij tegelijk te herstellen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['actie'] ?? '') === 'herstel_snel') {
    vereistGeldigCsrfToken();

    $sleutels = $_POST['sleutels'] ?? ($_POST['sleutel'] ?? null);
    $sleutels = is_array($sleutels) ? $sleutels : [$sleutels];
    $sleutels = array_unique(array_filter(array_map(fn($s) => strtolower(trim((string) $s)), $sleutels)));

    $stmt = $pdo->prepare("UPDATE extensie_catalogus SET genegeerd = 0, genegeerd_op = NULL WHERE sleutel = ?");
    foreach ($sleutels as $sleutel) {
        $stmt->execute([$sleutel]);
    }

    header("Location: extensies.php?id=" . $id . "&toon_genegeerd=1&hersteld=1");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ?");
$stmt->execute([$id]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$site) {
    die("Site niet gevonden.");
}

$domein           = $site['domein'];
$toonGenegeerd    = isset($_GET['toon_genegeerd']) && $_GET['toon_genegeerd'] == '1';
$derdePartijLijst = haalDerdePartijExtensies($pdo, $id, $toonGenegeerd);

// Admin-inlogurl: domein + het RUWE admin_pad-veld (inclusief eventueel
// geheim woord), want dit is bedoeld om echt in te loggen - in
// tegenstelling tot het gestripte pad dat voor manifest-bestanden wordt
// gebruikt in haal_versies_op.php.
$adminPadOntsleuteld = $site['admin_pad'] ? ontsleutelWaarde($site['admin_pad']) : '';
if ($adminPadOntsleuteld === '') {
    $adminPadOntsleuteld = 'administrator';
}
$adminUrl = 'https://' . $domein . '/' . trim($adminPadOntsleuteld, '/');
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
<title>Extensies - <?php echo htmlspecialchars($domein); ?></title>
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
    color: var(--thema-tekst);
    margin-bottom: 20px;
}

.acties-boven {
    margin-bottom: 20px;
}

.groen { color: green; font-weight: bold; }
.rood  { color: red; font-weight: bold; }
.grijs { color: #888; font-weight: bold; }

table {
    border-collapse: collapse;
    width: 100%;
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
}

tr:nth-child(even) td {
    background: #fafafa;
}

.status-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: bold;
    color: white;
    white-space: nowrap;
}

.status-rood   { background: #c0392b; }
.status-groen  { background: #2e8b3d; }
.status-grijs  { background: #888; }

.knop {
    display: inline-block;
    padding: 8px 14px;
    background: #333;
    color: white;
    text-decoration: none;
    border-radius: 4px;
    font-size: 13px;
    white-space: nowrap;
}

.knop:hover {
    background: #555;
}

.knop.admin {
    background: #1f6fa8;
}

.knop.admin:hover {
    background: #175a87;
}

.knop.knop-geel {
    background: #f4c542;
    color: #111;
}

.knop.knop-geel:hover {
    background: #e0b32e;
}

.knop-negeren-klein {
    display: inline-block;
    padding: 5px 10px;
    background: #767676;
    color: white;
    border: none;
    border-radius: 4px;
    font-size: 12px;
    cursor: pointer;
    white-space: nowrap;
}

.knop-negeren-klein:hover {
    background: #5e5e5e;
}

.knop-herstellen-klein {
    background: #2e8b3d;
}

.knop-herstellen-klein:hover {
    background: #24702f;
}

tr.genegeerd-rij td {
    background: var(--thema-genegeerd-bg) !important;
    color: var(--thema-genegeerd-tekst);
}

.badge-genegeerd {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 10px;
    font-weight: bold;
    background: var(--thema-genegeerd-bg);
    color: var(--thema-genegeerd-tekst);
}

.uitleg-statussen {
    margin-bottom: 15px;
    padding: 12px 15px;
    border: 1px solid var(--thema-rand);
    background: var(--thema-zebra);
    border-radius: 6px;
}

.uitleg-statussen div {
    margin-bottom: 6px;
}

.uitleg-statussen div:last-child {
    margin-bottom: 0;
}

</style>
<?php include 'responsive_stijlen.php'; ?>
</head>
<body>

<header>
    <div>
        <h1>🧩 Extensieoverzicht</h1>
        <div class="domein-titel"><?php echo htmlspecialchars($domein); ?></div>
    </div>
    <div style="display: flex; gap: 8px;">
        <button type="button" class="knop" onclick="history.back()" style="padding: 8px 12px;" title="Eén stap terug">←</button>
        <a class="knop" href="index.php?categorie=<?php echo htmlspecialchars($site['categorie'] ?? 'eigen'); ?>">Terug naar monitor</a>
    </div>
</header>

<div class="acties-boven">
    <button type="button" class="knop knop-geel" onclick="herscanDezeSite(this)">🔄 Herscan alleen deze website</button>
    <a class="knop admin" href="<?php echo htmlspecialchars($adminUrl); ?>" target="_blank" rel="noopener">🔑 Inloggen als admin</a>
    <a class="knop" href="extensie_beheer.php">🧩 Extensietabel beheren</a>
    <?php if ($toonGenegeerd): ?>
        <a class="knop" href="extensies.php?id=<?php echo (int) $id; ?>">Verberg genegeerde extensies</a>
    <?php else: ?>
        <a class="knop" href="extensies.php?id=<?php echo (int) $id; ?>&amp;toon_genegeerd=1">Toon ook genegeerde extensies</a>
    <?php endif; ?>
</div>

<div id="melding" style="display: none; margin-bottom: 15px; padding: 8px 12px; border-radius: 4px; font-size: 13px;"></div>

<?php if (isset($_GET['genegeerd'])): ?>
<div style="margin-bottom: 15px; padding: 10px 14px; border-radius: 4px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; font-size: 13px;">
    ✅ Extensie genegeerd - komt niet meer terug in het overzicht, op geen enkele site die 'm gebruikt. Terug te draaien via "Extensietabel beheren", of via de knop "Toon ook genegeerde extensies" hierboven.
</div>
<?php endif; ?>

<?php if (isset($_GET['hersteld'])): ?>
<div style="margin-bottom: 15px; padding: 10px 14px; border-radius: 4px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; font-size: 13px;">
    ✅ Extensie hersteld - komt weer gewoon terug in het overzicht, op alle sites die 'm gebruiken.
</div>
<?php endif; ?>

<h2 style="margin-bottom: 5px;">Volledige extensielijst (van derden)</h2>
<div class="subtitel">
    Automatisch gedetecteerd rechtstreeks uit de database van de site (Joomla-kernonderdelen zijn eruit gefilterd).
    De nieuwste versie wordt - waar bekend - rechtstreeks door de site zelf opgehaald via Joomla's eigen geregistreerde update-locatie per extensie.
</div>

<div class="uitleg-statussen">
    <div><span class="status-badge status-groen">Up-to-date</span> — de geïnstalleerde versie is gelijk aan of nieuwer dan de nieuwste bekende versie.</div>
    <div><span class="status-badge status-rood">Niet up-to-date</span> — er is een nieuwere versie beschikbaar dan wat er nu geïnstalleerd is.</div>
    <div><span class="status-badge status-grijs">Onbekend</span> — er kon geen nieuwste versie worden bepaald, bijvoorbeeld omdat deze extensie geen update-locatie heeft geregistreerd in Joomla, of omdat het ophalen daarvan (tijdelijk) mislukte.</div>
</div>

<table class="responsive-tabel">
<tr>
    <th style="width: 200px;">Naam</th>
    <th style="width: 90px;">Type</th>
    <th style="width: 110px;">Versie</th>
    <th style="width: 110px;">Nieuwste versie</th>
    <th style="width: 130px;">Status</th>
    <th style="width: 70px;">Actief</th>
    <th>Auteur</th>
    <th style="width: 90px;">Actie</th>
</tr>
<?php if (empty($derdePartijLijst)): ?>
<tr>
    <td colspan="8">
        Nog geen gegevens. Draai <code>scan-en-check-website.php</code> op deze site (via de knop "Scan en check sites" op de monitorpagina)
        om de volledige extensielijst op te halen.
    </td>
</tr>
<?php endif; ?>
<?php foreach ($derdePartijLijst as $extensie): ?>
<?php
    if ($extensie['status'] === false) {
        $statusHtml = "<span class='status-badge status-rood'>Niet up-to-date</span>";
    } elseif ($extensie['status'] === true) {
        $statusHtml = "<span class='status-badge status-groen'>Up-to-date</span>";
    } else {
        $statusHtml = "<span class='status-badge status-grijs'>Onbekend</span>";
    }
    $sleutelVoorNegeren = sleutelVoorGegroepeerdProduct($extensie);
    // Een getoonde rij kan meerdere onderliggende catalogus-sleutels
    // bundelen (bijv. "com_bergkerkagenda" = component_com_bergkerkagenda +
    // module_mod_bergkerk_agenda, samengevoegd omdat ze dezelfde auteur en
    // herkomst-token delen). isGegroepeerdProductVolledigGenegeerd()
    // verbergt de hele rij pas als ECHT elk onderdeel genegeerd is - dus
    // moet de negeerknop hieronder ook ECHT elk onderdeel negeren, anders
    // blijft de rij zichtbaar terwijl de melding wél "genegeerd" toont.
    $alleSleutelsVanGroep = !empty($extensie['sleutels']) ? $extensie['sleutels'] : [$sleutelVoorNegeren];
    $isGenegeerd = !empty($extensie['genegeerd']);
?>
<tr<?php echo $isGenegeerd ? ' class="genegeerd-rij"' : ''; ?>>
    <td data-label="Naam"><?php echo htmlspecialchars($extensie['naam'] ?? '-'); ?><?php if ($isGenegeerd): ?><br><span class="badge-genegeerd">genegeerd</span><?php endif; ?></td>
    <td data-label="Type"><?php echo htmlspecialchars($extensie['type'] ?? '-'); ?></td>
    <td data-label="Versie"><?php echo htmlspecialchars($extensie['versie'] ?? '-'); ?></td>
    <td data-label="Nieuwste versie"><?php echo htmlspecialchars($extensie['nieuwste_versie'] ?? '-'); ?></td>
    <td data-label="Status"><?php echo $statusHtml; ?></td>
    <td data-label="Actief"><?php echo !empty($extensie['enabled']) ? '✅' : '⬜'; ?></td>
    <td data-label="Auteur"><?php echo htmlspecialchars($extensie['auteur'] ?? '-'); ?></td>
    <td data-label="Actie">
        <?php if ($isGenegeerd): ?>
        <form method="post" onsubmit="return confirm('Deze extensie herstellen? Komt dan weer gewoon terug in het overzicht, op alle sites die \'m gebruiken.');">
            <?php echo csrfVeld(); ?>
            <input type="hidden" name="actie" value="herstel_snel">
            <?php foreach ($alleSleutelsVanGroep as $sleutelInGroep): ?>
            <input type="hidden" name="sleutels[]" value="<?php echo htmlspecialchars($sleutelInGroep); ?>">
            <?php endforeach; ?>
            <button type="submit" class="knop-negeren-klein knop-herstellen-klein">Herstel</button>
        </form>
        <?php else: ?>
        <form method="post" onsubmit="return confirm('Deze extensie negeren? Verschijnt dan niet meer in dit overzicht, op geen enkele site die 'm gebruikt.');">
            <?php echo csrfVeld(); ?>
            <input type="hidden" name="actie" value="negeer_snel">
            <?php foreach ($alleSleutelsVanGroep as $sleutelInGroep): ?>
            <input type="hidden" name="sleutels[]" value="<?php echo htmlspecialchars($sleutelInGroep); ?>">
            <?php endforeach; ?>
            <input type="hidden" name="label" value="<?php echo htmlspecialchars($extensie['naam'] ?? $sleutelVoorNegeren); ?>">
            <button type="submit" class="knop-negeren-klein">Negeren</button>
        </form>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</table>

<script>
const SITE_ID_HERSCAN = <?php echo (int) $id; ?>;

function herscanDezeSite(knop) {
    knop.disabled = true;

    const melding = document.getElementById('melding');
    melding.className = '';
    melding.style.display = 'block';
    melding.style.background = '#eef1f4';
    melding.style.color = '#333';
    melding.textContent = '⏳ Scan wordt gestart voor deze website...';

    fetch('start_scan.php?site_id=' + SITE_ID_HERSCAN)
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(tekst => {
            if (tekst.includes('⚠️')) {
                melding.style.background = '#fff3cd';
                melding.style.color = '#665200';
                melding.textContent = tekst.replace(/^[^:]+:\s*/, '');
                knop.disabled = false;
                return;
            }

            let secondenOver = 10;
            melding.textContent = '✅ Scan gestart. Even wachten... (' + secondenOver + ')';

            const teller = setInterval(() => {
                secondenOver--;

                if (secondenOver > 0) {
                    melding.textContent = '✅ Scan gestart. Even wachten... (' + secondenOver + ')';
                } else {
                    clearInterval(teller);
                    melding.textContent = '⏳ Website- en SSL-status controleren...';

                    fetch('check_sites.php?site_id=' + SITE_ID_HERSCAN)
                        .then(r => {
                            if (!r.ok) throw new Error('HTTP ' + r.status);
                            return r.text();
                        })
                        .then(() => {
                            melding.textContent = '⏳ Joomla- en extensieversies ophalen...';
                            return fetch('haal_versies_op.php?site_id=' + SITE_ID_HERSCAN);
                        })
                        .then(r => {
                            if (!r.ok) throw new Error('HTTP ' + r.status);
                            return r.text();
                        })
                        .then(() => {
                            melding.style.background = '#d4edda';
                            melding.style.color = '#155724';
                            melding.textContent = '✅ Deze website is opnieuw gescand — pagina wordt herladen...';
                            setTimeout(() => location.reload(), 1200);
                        })
                        .catch(err => {
                            melding.style.background = '#f8d7da';
                            melding.style.color = '#721c24';
                            melding.textContent = '❌ Er ging iets mis: ' + err.message;
                            knop.disabled = false;
                        });
                }
            }, 1000);
        })
        .catch(err => {
            melding.style.background = '#f8d7da';
            melding.style.color = '#721c24';
            melding.textContent = '❌ Er ging iets mis bij het starten van de scan: ' + err.message;
            knop.disabled = false;
        });
}
</script>

<?php include 'terug_naar_boven.php'; ?>
</body>
</html>
