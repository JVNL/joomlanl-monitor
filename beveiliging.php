<?php
require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: login.php");
    exit;
}
// Zelfde reden als bij extensies.php/index.php/klantrapport.php: dit
// rapport toont per definitie actuele scangegevens.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once 'config.php';
require_once 'csrf_functies.php';
require_once 'verdacht_functies.php';
require_once 'instellingen_functies.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ?");
$stmt->execute([$id]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$site) {
    die("Site niet gevonden.");
}

$domein      = $site['domein'];
$laatsteScan = $site['verdacht_laatste_scan'];

$alleItems       = parseVerdachtDetails($site['verdacht_details'] ?? '');
$vertrouwdHashes = haalVertrouwdeHashes($pdo, $id);

$totaalAantal    = count($alleItems);
$vertrouwdAantal = 0;
foreach ($alleItems as $item) {
    if (isset($vertrouwdHashes[$item['hash']])) {
        $vertrouwdAantal++;
    }
}
$nieuwAantal = $totaalAantal - $vertrouwdAantal;

// Extensiebestanden die afwijken van de "meerderheidsversie" op andere
// sites met dezelfde extensie + versie (zie vergelijk_extensie_bestanden.php).
$afwijkingenStmt = $pdo->prepare("
    SELECT * FROM extensie_bestand_afwijkingen
    WHERE site_id = ?
    ORDER BY groep_sleutel, relatief_pad
");
$afwijkingenStmt->execute([$id]);
$bestandAfwijkingen = $afwijkingenStmt->fetchAll(PDO::FETCH_ASSOC);

// Vertrouwde (handmatig als "geen probleem" beoordeelde) extensie-bestand-
// afwijkingen van het actieve overzicht scheiden - zelfde soort onderscheid
// als bij kern_vertrouwd hierboven/hieronder.
$extensieVertrouwdStmt = $pdo->prepare("SELECT relatief_pad, hash FROM extensie_bestand_vertrouwd WHERE site_id = ?");
$extensieVertrouwdStmt->execute([$id]);
$extensieVertrouwdSleutels = [];
foreach ($extensieVertrouwdStmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
    $extensieVertrouwdSleutels[$v['relatief_pad'] . '|' . $v['hash']] = true;
}
foreach ($bestandAfwijkingen as &$afwijkingRij) {
    $afwijkingRij['is_vertrouwd'] = isset($extensieVertrouwdSleutels[$afwijkingRij['relatief_pad'] . '|' . $afwijkingRij['eigen_hash']]);
}
unset($afwijkingRij);

// Voor elke afwijking concreet opzoeken WELKE andere site(s) dit bestand
// ook hebben (gegroepeerd per hash, met domeinnaam) - zodat je niet
// "vergelijk zelf via FTP met een andere site" hoeft te lezen zonder te
// weten welke, maar direct kunt zien (en met één klik bekijken) bij welke
// site de andere versie staat. Alleen mogelijk voor extensiebestanden
// (extensie_bestand_hashes bewaart per site een hash per bestand) - niet
// voor kernbestand-afwijkingen, die tegen het officiële pakket vergelijken.
$anderesSitesStmt = $pdo->prepare("
    SELECT h.site_id, h.hash, s.domein
    FROM extensie_bestand_hashes h
    INNER JOIN sites s ON s.id = h.site_id
    WHERE h.groep_sleutel = ? AND h.relatief_pad = ? AND h.site_id != ?
    ORDER BY s.domein
");
foreach ($bestandAfwijkingen as &$afwijkingRij) {
    $anderesSitesStmt->execute([$afwijkingRij['groep_sleutel'], $afwijkingRij['relatief_pad'], $id]);
    $perHash = [];
    foreach ($anderesSitesStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $perHash[$r['hash']][] = $r;
    }
    // Andere sites met PRECIES dezelfde inhoud als hier (kan voorkomen bij
    // een gelijke stand met 3+ varianten, of als deze afwijking eigenlijk
    // een oude scan betreft).
    $afwijkingRij['zelfde_versie_sites'] = $perHash[$afwijkingRij['eigen_hash']] ?? [];
    unset($perHash[$afwijkingRij['eigen_hash']]);
    // De rest: sites met een andere versie dan hier, gegroepeerd per hash.
    $afwijkingRij['andere_versie_groepen'] = $perHash;
}
unset($afwijkingRij);

$bestandAfwijkingenActief = array_values(array_filter($bestandAfwijkingen, fn($r) => !$r['is_vertrouwd']));
$bestandAfwijkingenVertrouwd = array_values(array_filter($bestandAfwijkingen, fn($r) => $r['is_vertrouwd']));

/**
 * Rendert een compacte, klikbare lijst van sitedomeinen die (via
 * beheerBekijk() met een afwijkend site_id) direct de inhoud van datzelfde
 * bestand bij die andere site tonen - zodat je niet zelf via FTP hoeft te
 * gaan zoeken welke site je moet checken. Bij veel sites in één groep
 * wordt de lijst afgekapt, met een simpel getal erachter (geen losse link
 * per site nodig als er toch al 15+ sites hetzelfde hebben).
 */
function renderAndereSitesLijst(array $sites, string $viewerIdPrefix, int $max = 5): string
{
    if (empty($sites)) {
        return '<span style="color: var(--thema-uitleg-tekst);">(geen)</span>';
    }
    // Deze functie levert altijd links op voor het RECHTER paneel van het
    // bijbehorende renderTweeVeldenViewer()-paar (id eindigend op "-b") -
    // de aanroepers geven bewust alleen de prefix door (dezelfde prefix
    // als bij renderTweeVeldenViewer()), niet de volledige id, om te
    // voorkomen dat de twee ooit uit elkaar gaan lopen.
    $viewerId = $viewerIdPrefix . '-b';
    $links = [];
    foreach (array_slice($sites, 0, $max) as $s) {
        $domeinJs = htmlspecialchars(addslashes($s['domein']));
        $links[] = '<a href="#" onclick="event.preventDefault(); beheerBekijk(this, \'' . $viewerId . '\', '
            . (int) $s['site_id'] . ", '{$domeinJs}');\" style=\"white-space: nowrap;\">🔍 " . htmlspecialchars($s['domein']) . '</a>';
    }
    $html = implode(', ', $links);
    $rest = count($sites) - $max;
    if ($rest > 0) {
        $html .= ' <span style="color: var(--thema-uitleg-tekst);">en ' . $rest . ' meer</span>';
    }
    return $html;
}

/**
 * Groepeert per-bestand afwijkingen binnen dezelfde extensie+versie die
 * een IDENTIEKE site-verdeling laten zien (dezelfde andere site(s) hebben
 * hier exact dezelfde inhoud als déze site) tot één samengevoegde rij, in
 * plaats van elk bestand apart te tonen.
 *
 * Reden: als een hele extensie in bulk van de "meerderheidsversie"
 * afwijkt (bijv. een andere sub-editie/build met toevallig hetzelfde
 * versienummer), levert dat tientallen losse rijen op met steeds
 * dezelfde site-groepering - dat verdrinkt de werkelijk interessante
 * uitzondering: een los bestand met een AFWIJKENDE site-groepering
 * t.o.v. de rest van dezelfde extensie, wat wél op een individuele
 * aanpassing (bijv. een backdoor) kan wijzen. Zo'n uitzondering krijgt
 * hierdoor automatisch een eigen signatuur en blijft dus gewoon los
 * zichtbaar, terwijl de bulk wordt samengevoegd.
 *
 * Bewust gebaseerd op de feitelijke site-verdeling (structureel), niet op
 * bestandsnaam/-pad (signatuur) - zelfde soort redenering als de
 * behavior-based aanpak in scan_template.php.
 *
 * @param array $afwijkingen Rijen met o.a. 'groep_sleutel' en 'zelfde_versie_sites'.
 * @param int $drempel Minimum aantal bestanden met dezelfde site-verdeling
 *                      om als "bulk" te worden samengevoegd i.p.v. los getoond.
 * @return array ['los' => rijen zoals voorheen, 'clusters' => samengevoegde groepen]
 */
function groepeerBulkAfwijkingen(array $afwijkingen, int $drempel = 5): array
{
    $perSignatuur = [];
    foreach ($afwijkingen as $afwijking) {
        $domeinen = array_column($afwijking['zelfde_versie_sites'], 'domein');
        sort($domeinen);
        $signatuur = $afwijking['groep_sleutel'] . '||' . implode('|', $domeinen);

        if (!isset($perSignatuur[$signatuur])) {
            $perSignatuur[$signatuur] = [
                'groep_sleutel' => $afwijking['groep_sleutel'],
                'domeinen'      => $domeinen,
                'items'         => [],
            ];
        }
        $perSignatuur[$signatuur]['items'][] = $afwijking;
    }

    $los = [];
    $clusters = [];
    foreach ($perSignatuur as $groep) {
        if (count($groep['items']) >= $drempel) {
            $clusters[] = [
                'groep_sleutel'   => $groep['groep_sleutel'],
                'signatuur_label' => $groep['domeinen'] === []
                    ? 'wijkt bij géén enkele andere site op dezelfde manier af'
                    : 'zelfde inhoud als: ' . implode(', ', $groep['domeinen']),
                'items'           => $groep['items'],
            ];
        } else {
            foreach ($groep['items'] as $item) {
                $los[] = $item;
            }
        }
    }

    return ['los' => $los, 'clusters' => $clusters];
}

/**
 * Rendert één rij van de "afwijkende bestanden"-tabel. Geëxtraheerd uit de
 * eerdere inline foreach-lussen zodat dezelfde rij-opmaak zowel los in de
 * hoofdtabel als genest in een samengevoegde bulk-cluster (zie
 * groepeerBulkAfwijkingen()) gebruikt kan worden, zonder duplicatie.
 */
function renderAfwijkingRij(array $afwijking, string $viewerIdPrefix, bool $isVertrouwd): string
{
    $sleutelWeergave = $afwijking['groep_sleutel'];
    if (preg_match('/^kern_joomla_(\d+)_(\d+)_(\d+)$/', $sleutelWeergave, $m)) {
        $sleutelWeergave = "Joomla-kern {$m[1]}.{$m[2]}.{$m[3]}";
    }

    ob_start();
    ?>
<tr<?php echo $isVertrouwd ? ' class="vertrouwd-rij"' : ''; ?> data-pad="<?php echo htmlspecialchars($afwijking['relatief_pad']); ?>">
    <td data-label="Extensie + versie"><code><?php echo htmlspecialchars($sleutelWeergave); ?></code></td>
    <td data-label="Bestand"><?php echo htmlspecialchars($afwijking['relatief_pad']); ?></td>
    <td data-label="Verhouding">
        <?php if ($afwijking['eenduidige_meerderheid']): ?>
            deze site wijkt af<br>
            <span style="color: var(--thema-uitleg-tekst); font-size: 11px;">
                <?php echo (int) $afwijking['aantal_sites_meerderheid']; ?> van de <?php echo (int) $afwijking['aantal_sites_totaal']; ?> sites zijn gelijk
            </span>
        <?php else: ?>
            geen duidelijke meerderheid<br>
            <span style="color: var(--thema-uitleg-tekst); font-size: 11px;">
                <?php echo (int) $afwijking['aantal_sites_totaal']; ?> sites zijn onderling verdeeld
            </span>
        <?php endif; ?>
    </td>
    <td data-label="Zelfde/andere versie bij" style="font-size: 12px;">
        <div style="margin-bottom: 6px;">
            <strong>Zelfde versie:</strong><br>
            <?php echo renderAndereSitesLijst($afwijking['zelfde_versie_sites'], $viewerIdPrefix); ?>
        </div>
        <?php foreach ($afwijking['andere_versie_groepen'] as $hashGroep => $sitesInGroep): ?>
        <div style="margin-bottom: 6px;">
            <strong>Andere versie (<?php echo count($sitesInGroep); ?>x):</strong><br>
            <?php echo renderAndereSitesLijst($sitesInGroep, $viewerIdPrefix); ?>
        </div>
        <?php endforeach; ?>
    </td>
    <td data-label="Actie">
        <div style="display: flex; flex-direction: column; gap: 4px;">
            <button
                type="button"
                class="btn-vertrouwen extensie-vertrouwen-knop"
                style="padding: 5px 10px; font-size: 11px; border: none; border-radius: 3px; color: white; cursor: pointer;"
                data-groep-sleutel="<?php echo htmlspecialchars($afwijking['groep_sleutel']); ?>"
                data-hash="<?php echo htmlspecialchars($afwijking['eigen_hash']); ?>"
                data-vertrouwd="<?php echo $isVertrouwd ? '1' : '0'; ?>"
                onclick="wisselExtensieVertrouwen(this)"
            ><?php echo $isVertrouwd ? '↩️ Niet meer vertrouwen' : '✅ Vertrouwen'; ?></button>
            <button type="button" class="btn-bekijk" style="padding: 5px 10px; font-size: 11px; border: none; border-radius: 3px; color: white; cursor: pointer;" onclick="beheerBekijk(this, '<?php echo htmlspecialchars($viewerIdPrefix); ?>-a', SITE_ID, 'deze site')">👁️ Bekijk (deze site)</button>
        </div>
    </td>
</tr>
    <?php
    return ob_get_clean();
}

/**
 * Rendert één samengevoegde bulk-cluster: een klapbare (<details>) regel
 * met een korte samenvatting, en de losse bestanden erin (via
 * renderAfwijkingRij()) - zo blijft alles alsnog controleerbaar, zonder
 * dat de pagina standaard tientallen bijna-identieke rijen toont.
 *
 * Nog niet vertrouwde clusters (de "actieve" sectie) staan standaard
 * OPEN: dat is nieuwe/openstaande informatie waar iemand die voor het
 * eerst op deze pagina kijkt niet zou weten dat er een pijltje aan het
 * begin van de regel staat om 'm uit te klappen, en dus zou kunnen
 * missen dat er nog iets te doen is. Al-vertrouwde clusters (in de
 * aparte "vertrouwd"-sectie onderaan) blijven dichtgeklapt - daar hoeft
 * niets meer mee te gebeuren.
 */
function renderBulkClusterRij(array $cluster, string $viewerIdPrefix, bool $isVertrouwd): void
{
    $sleutelWeergave = $cluster['groep_sleutel'];
    if (preg_match('/^kern_joomla_(\d+)_(\d+)_(\d+)$/', $sleutelWeergave, $m)) {
        $sleutelWeergave = "Joomla-kern {$m[1]}.{$m[2]}.{$m[3]}";
    }

    // Compacte lijst van {pad, hash} voor de "vertrouw alle N"-knop
    // hieronder - scheelt bij grote clusters het één-voor-één aanklikken
    // van de losse Vertrouwen-knoppen in de tabel. Zie vertrouwCluster()
    // (JavaScript, onderaan deze pagina) voor de verwerking.
    $itemsVoorJs = array_map(
        fn($item) => ['pad' => $item['relatief_pad'], 'hash' => $item['eigen_hash']],
        $cluster['items']
    );
    $itemsJson = htmlspecialchars(json_encode($itemsVoorJs), ENT_QUOTES, 'UTF-8');
    $aantal = count($cluster['items']);
    ?>
<tr>
    <td colspan="5" style="padding: 0;">
        <?php
        // Alleen de nog NIET vertrouwde clusters standaard openklappen - dat
        // is de nieuwe/openstaande informatie waar iemand actie op moet
        // nemen. Al-vertrouwde clusters (in de aparte "vertrouwd"-sectie
        // onderaan) blijven dichtgeklapt, want daar hoeft niets meer mee te
        // gebeuren; die openklappen zou alleen maar ruis toevoegen.
        ?>
        <details style="padding: 10px 12px;"<?php echo $isVertrouwd ? '' : ' open'; ?>>
            <summary style="cursor: pointer; padding: 4px 0;">
                <code><?php echo htmlspecialchars($sleutelWeergave); ?></code> —
                <strong><?php echo $aantal; ?> bestanden</strong> wijken op dezelfde manier af
                (<?php echo htmlspecialchars($cluster['signatuur_label']); ?>)
                <span style="color: var(--thema-uitleg-tekst); font-size: 11px;">
                    — waarschijnlijk een andere sub-versie/build van deze extensie, geen los verdachte bestanden.
                    Klik om de individuele bestanden te bekijken.
                </span>
            </summary>
            <table class="responsive-tabel" style="margin-top: 10px;">
                <tr>
                    <th style="width: 20%;">Extensie + versie</th>
                    <th style="width: 22%;">Bestand</th>
                    <th style="width: 15%;">Verhouding</th>
                    <th style="width: 23%;">Zelfde/andere versie bij</th>
                    <th style="width: 150px;">
                        Actie
                        <div style="margin-top: 6px; display: flex; flex-direction: column; gap: 4px;">
                            <button
                                type="button"
                                class="btn-vertrouwen extensie-cluster-vertrouwen-knop"
                                style="padding: 5px 10px; font-size: 11px; font-weight: normal; border: none; border-radius: 3px; color: white; cursor: pointer;"
                                data-groep-sleutel="<?php echo htmlspecialchars($cluster['groep_sleutel']); ?>"
                                data-items="<?php echo $itemsJson; ?>"
                                data-actie="<?php echo $isVertrouwd ? 'ontvertrouw' : 'vertrouw'; ?>"
                                onclick="vertrouwCluster(this)"
                            ><?php echo $isVertrouwd
                                ? '↩️ Niet meer vertrouwen (alle ' . $aantal . ')'
                                : '✅ Vertrouw alle ' . $aantal; ?></button>
                            <span class="cluster-vertrouwen-status" style="font-size: 11px; color: var(--thema-uitleg-tekst); font-weight: normal;"></span>
                        </div>
                    </th>
                </tr>
                <?php foreach ($cluster['items'] as $afwijking) {
                    echo renderAfwijkingRij($afwijking, $viewerIdPrefix, $isVertrouwd);
                } ?>
            </table>
        </details>
    </td>
</tr>
    <?php
}

/**
 * Twee viewer-panelen naast elkaar (op smalle schermen onder elkaar) - een
 * links voor "deze site" en een rechts voor een aangeklikte andere site,
 * zodat je ze ECHT naast elkaar kunt vergelijken in plaats van dat de ene
 * de andere overschrijft. $idPrefix moet uniek zijn per tabel (actief vs.
 * vertrouwd), anders raken de twee tabellen elkaars viewer kwijt.
 */
function renderTweeVeldenViewer(string $idPrefix): string
{
    return '<div style="display: flex; gap: 12px; flex-wrap: wrap; margin-top: 10px;">'
        . '<div style="flex: 1 1 320px; min-width: 280px;" id="' . $idPrefix . '-a"></div>'
        . '<div style="flex: 1 1 320px; min-width: 280px;" id="' . $idPrefix . '-b"></div>'
        . '</div>';
}

// Kernbestanden die afwijken van het OFFICIËLE, ongewijzigde Joomla-pakket
// (zie kern_integriteit_functies.php / vergelijk_kern_bestanden.php) - een
// aanvulling op de vergelijking hierboven, die alleen iets kan zeggen als
// dezelfde Joomla-kernversie op minimaal 2 sites voorkomt.
$kernAfwijkingenStmt = $pdo->prepare("
    SELECT * FROM kern_bestand_afwijkingen
    WHERE site_id = ?
    ORDER BY relatief_pad
");
$kernAfwijkingenStmt->execute([$id]);
$kernBestandAfwijkingen = $kernAfwijkingenStmt->fetchAll(PDO::FETCH_ASSOC);

$toonAlles = isset($_GET['toon_vertrouwd']) && $_GET['toon_vertrouwd'] == '1';

// Vertrouwde (handmatig als "geen probleem" beoordeelde) kernbestand-
// afwijkingen van het actieve overzicht scheiden - zelfde soort onderscheid
// als bij de gewone verdachte items hierboven ($vertrouwdHashes), maar met
// een eigen tabel omdat de sleutel hier anders is opgebouwd (pad + hash,
// geen losse itemnaam). Blijven wél gewoon bereikbaar via "Toon ook
// vertrouwde items", en dus ook nog aan te klikken om alsnog te vervangen.
$kernVertrouwdStmt = $pdo->prepare("SELECT relatief_pad, hash FROM kern_vertrouwd WHERE site_id = ?");
$kernVertrouwdStmt->execute([$id]);
$kernVertrouwdSleutels = [];
foreach ($kernVertrouwdStmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
    $kernVertrouwdSleutels[$v['relatief_pad'] . '|' . $v['hash']] = true;
}
foreach ($kernBestandAfwijkingen as &$kernAfwijkingRij) {
    $kernAfwijkingRij['is_vertrouwd'] = isset($kernVertrouwdSleutels[$kernAfwijkingRij['relatief_pad'] . '|' . $kernAfwijkingRij['eigen_hash']]);
}
unset($kernAfwijkingRij);
$kernBestandAfwijkingenActief = array_values(array_filter($kernBestandAfwijkingen, fn($r) => !$r['is_vertrouwd']));
$kernBestandAfwijkingenVertrouwd = array_values(array_filter($kernBestandAfwijkingen, fn($r) => $r['is_vertrouwd']));

// Welke items tonen we in de tabel?
if ($toonAlles) {
    $teTonenItems = $alleItems;
} else {
    $teTonenItems = array_values(array_filter(
        $alleItems,
        fn($item) => !isset($vertrouwdHashes[$item['hash']])
    ));
}

// Eerst groeperen per type (in een logische volgorde van "ernst"), en
// binnen elk type het hoogste risico eerst - dat is wat de meeste aandacht
// nodig heeft. Zonder deze groepering stonden alle soorten (backdoor,
// .htaccess, onbekende map/bestand, database) door elkaar heen.
$typeVolgorde = ['backdoor' => 0, 'htaccess' => 1, 'database' => 2, 'bestand' => 3, 'map' => 4, 'cluster' => 5];
usort($teTonenItems, function ($a, $b) use ($typeVolgorde) {
    $typeA = $typeVolgorde[strtolower($a['type'] ?? '')] ?? 99;
    $typeB = $typeVolgorde[strtolower($b['type'] ?? '')] ?? 99;
    if ($typeA !== $typeB) {
        return $typeA <=> $typeB;
    }
    return ($b['risico'] ?? 50) <=> ($a['risico'] ?? 50);
});

// Welke typen komen daadwerkelijk voor in deze lijst? Gebruikt voor het
// filter-keuzemenu bovenaan de tabel.
$aanwezigeTypen = [];
foreach ($teTonenItems as $item) {
    $aanwezigeTypen[strtolower($item['type'] ?? '')] = true;
}
$aanwezigeTypen = array_keys($aanwezigeTypen);
usort($aanwezigeTypen, fn($a, $b) => ($typeVolgorde[$a] ?? 99) <=> ($typeVolgorde[$b] ?? 99));

function typeKlasse($type)
{
    $type = strtolower($type);
    if ($type === 'backdoor' || $type === 'database') {
        return 'type-rood';
    }
    return 'type-oranje';
}

function risicoBadgeHtml(int $risico): string
{
    [$label, $klasse] = risicoLabel($risico);
    return "<span class='badge risico $klasse'>$label · $risico</span>";
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
<title>Beveiliging - <?php echo htmlspecialchars($domein); ?></title>
<style>

body {
    font-family: Arial, sans-serif;
    margin: 20px;
    font-size: 13px;
}

h1 {
    margin-bottom: 5px;
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

.overzicht {
    margin-bottom: 20px;
    padding: 15px;
    border: 1px solid #ddd;
    background: #f5f5f5;
    display: flex;
    gap: 30px;
    align-items: center;
    flex-wrap: wrap;
}

.overzicht div strong {
    display: block;
    margin-bottom: 3px;
}

.groen  { color: var(--thema-groen); font-weight: bold; }
.rood   { color: var(--thema-rood); font-weight: bold; }
.blauw  { color: var(--thema-link); font-weight: bold; }

header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 5px;
}

header h1 {
    margin: 0 0 5px 0;
}

.acties-boven {
    margin-bottom: 15px;
}

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
    padding: 6px;
    font-size: 12px;
    vertical-align: top;
    word-break: break-word;
}

tr:nth-child(even) td {
    background: #fafafa;
}

tr.vertrouwd-rij td {
    background: var(--thema-vertrouwd-bg) !important;
    color: var(--thema-vertrouwd-tekst);
}

.type-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: bold;
    color: white;
    white-space: nowrap;
}

.type-rood   { background: #c0392b; }
.type-oranje { background: #e67e22; }
.type-groen  { background: #1e7e34; }

.knop {
    display: inline-block;
    margin-top: 20px;
    padding: 8px 14px;
    background: #333;
    color: white;
    text-decoration: none;
    border-radius: 4px;
    border: none;
    font-size: 13px;
    cursor: pointer;
}

header .knop {
    margin-top: 0;
}

.knop:hover {
    background: #555;
}

.knop.secundair {
    background: #1f6fa8;
    margin-top: 0;
}

.knop.secundair:hover {
    background: #175a87;
}

.knop.knop-geel {
    background: #f4c542;
    color: #111;
    margin-top: 0;
}

.knop.knop-geel:hover {
    background: #e0b32e;
}

.leeg {
    padding: 20px;
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
    border-radius: 4px;
}

#voortgang-buiten {
    display: none;
    margin-bottom: 15px;
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

.vinkje-kolom {
    width: 70px;
    text-align: center;
}

.vertrouwd-checkbox {
    width: 22px;
    height: 22px;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background: var(--thema-invoer-bg);
    border: 2px solid var(--thema-tekst);
    border-radius: 3px;
    cursor: pointer;
    position: relative;
    margin: 0;
    flex-shrink: 0;
}

.vertrouwd-checkbox:checked::after {
    content: '';
    position: absolute;
    left: 6px;
    top: 2px;
    width: 5px;
    height: 10px;
    border: solid var(--thema-tekst);
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

.badge.risico {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: bold;
    color: white;
    white-space: nowrap;
}

.risico-zeerhoog { background: #7b241c; }
.risico-hoog     { background: #c0392b; }
.risico-middel   { background: #e67e22; }
.risico-laag     { background: #7f8c8d; }

.beheeracties-cel {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 110px;
}

.beheeracties-cel button {
    display: block;
    width: 100%;
    padding: 4px 6px;
    font-size: 11px;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    color: white;
    text-align: left;
}

.beheeracties-cel button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-bekijk       { background: #1f6fa8; }
.btn-rechten      { background: #17a2b8; }
.btn-quarantaine  { background: #8e6d1f; }
.btn-blokkeer     { background: #6f42c1; }
.btn-verwijder    { background: #c0392b; }
.btn-vertrouwen   { background: #1f8a4c; }

.viewer {
    margin: 15px 0;
    background: #1e293b;
    color: #a5f3fc;
    border-radius: 6px;
    overflow: hidden;
    /* Duidelijk, ook in donkere modus goed zichtbaar kader - anders is
       niet meteen te zien waar deze weergave (met zijn eigen, altijd
       donkere kleurenschema) bij hoort, zeker nu hij op meerdere plekken
       op de pagina kan verschijnen. */
    border: 2px solid var(--thema-link);
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.35);
}

.viewer .kop {
    background: #0f172a;
    color: #e2e8f0;
    padding: 8px 12px;
    font-size: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.viewer pre {
    margin: 0;
    padding: 12px;
    max-height: 400px;
    overflow: auto;
    font-size: 12px;
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-all;
}

.beheer-sectie {
    margin-top: 15px;
}

.beheer-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-bottom: 6px;
    font-size: 12px;
    flex-wrap: wrap;
}

.beheer-item .pad {
    font-family: monospace;
    word-break: break-all;
}

.beheer-item .acties {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
}

.beheer-item .acties button {
    padding: 4px 10px;
    font-size: 11px;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    color: white;
}

.btn-herstel    { background: #1f8a4c; }
.btn-definitief { background: #c0392b; }

</style>
<?php include 'responsive_stijlen.php'; ?>
</head>
<body>

<header>
    <div>
        <h1>🛡️ Beveiligingsrapport</h1>
        <div class="domein-titel"><?php echo htmlspecialchars($domein); ?></div>
    </div>
    <div style="display: flex; gap: 8px;">
        <button type="button" class="knop" onclick="history.back()" style="padding: 8px 12px;" title="Eén stap terug">←</button>
        <a class="knop" href="index.php?categorie=<?php echo htmlspecialchars($site['categorie'] ?? 'eigen'); ?>">Terug naar monitor</a>
    </div>
</header>

<?php $ftpLink = bepaalFtpClientLink($site); ?>
<div class="acties-boven" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
    <button type="button" class="knop knop-geel" onclick="herscanDezeSite(this)">🔄 Herscan alleen deze website</button>
    <?php if ($ftpLink['url'] !== null): ?>
    <a class="knop secundair" href="<?php echo htmlspecialchars($ftpLink['url']); ?>"
        <?php if ($ftpLink['gebruikersnaamKopieren'] !== null): ?>
        onclick="ftpWachtwoordKopieren(this, event)" data-wachtwoord="<?php echo htmlspecialchars($ftpLink['wachtwoordKopieren']); ?>" data-gebruikersnaam="<?php echo htmlspecialchars($ftpLink['gebruikersnaamKopieren']); ?>"
        title="Zowel gebruikersnaam als wachtwoord bevatten een teken dat een kant-en-klare inloglink onbetrouwbaar maakt - klik hier om beide naar het klembord te kopiëren (los na elkaar); FileZilla wordt bewust NIET automatisch geopend, log zelf handmatig in"
        <?php elseif ($ftpLink['wachtwoordKopieren'] !== null): ?>
        onclick="ftpWachtwoordKopieren(this, event)" data-wachtwoord="<?php echo htmlspecialchars($ftpLink['wachtwoordKopieren']); ?>" data-gebruikersnaam=""
        title="Wachtwoord bevat een teken (/, ?, # of %) dat een kant-en-klare inloglink onbetrouwbaar maakt - klik hier om het wachtwoord naar het klembord te kopiëren en FileZilla te openen; plak het wachtwoord daar zelf in het inlogscherm"
        <?php else: ?>
        title="Openen in lokale FTP-client (bijv. FileZilla) - werkt alleen als je besturingssysteem ftp/sftp-links daaraan heeft gekoppeld"
        <?php endif; ?>>
        🖥️ Open in FTP-client<?php echo $ftpLink['wachtwoordKopieren'] !== null ? ' 📋' : ''; ?>
    </a>
    <?php endif; ?>
    <a class="knop secundair" href="klantrapport.php?id=<?php echo (int) $id; ?>" title="Klantvriendelijk rapport openen - via de 'Opslaan als PDF'-knop op die pagina zelf te bewaren, geschikt om door te sturen naar de eigenaar van de website">📄 Klantrapport openen</a>
</div>
<div id="melding" style="display: none; margin-bottom: 15px; padding: 8px 12px; border-radius: 4px; font-size: 13px;"></div>
<div id="voortgang-buiten">
    <div id="voortgang-binnen">0%</div>
</div>

<div class="overzicht">
    <div>
        <strong>Nieuw / niet-vertrouwd</strong>
        <span id="teller-nieuw">
        <?php if ($totaalAantal === 0): ?>
            <span class="groen">🟢 0 - Schoon</span>
        <?php elseif ($nieuwAantal === 0): ?>
            <span class="groen">🟢 0</span>
        <?php else: ?>
            <span class="rood">🔴 <?php echo $nieuwAantal; ?></span>
        <?php endif; ?>
        </span>
    </div>
    <div>
        <strong>Vertrouwd</strong>
        <span class="blauw">✅ <span id="teller-vertrouwd"><?php echo $vertrouwdAantal; ?></span></span>
    </div>
    <div>
        <strong>Laatste scan</strong>
        <?php echo $laatsteScan ? htmlspecialchars(date('d-m-Y H:i', strtotime($laatsteScan))) : '-'; ?>
    </div>
</div>

<?php
$superUsers = [];
if (!empty($site['super_users_json'])) {
    $gedecodeerd = json_decode($site['super_users_json'], true);
    if (is_array($gedecodeerd)) {
        $superUsers = $gedecodeerd;
    }
}
?>
<?php if (!empty($site['super_users_fout'])): ?>
<div class="blok" style="margin-bottom: 15px;">
    <h2>👤 Super Users</h2>
    <div class="uitleg">Kon niet worden opgehaald: <?php echo htmlspecialchars($site['super_users_fout']); ?></div>
</div>
<?php elseif (!empty($superUsers)): ?>
<div class="blok" style="margin-bottom: 15px;">
    <h2>👤 Super Users (<?php echo count($superUsers); ?>)<?php echo hulpIcoon('beveiliging', 'Compleet overzicht van alle beheerdersaccounts, rechtstreeks uit de database van de site zelf - controleer of je elke naam/gebruikersnaam herkent. Dit ziet ook accounts die (nog) geen bekend aanvallerspatroon gebruiken en dus niet automatisch als \'verdachte Super User\' worden gemeld.'); ?></h2>
    <table class="responsive-tabel" style="width: 100%; border-collapse: collapse;">
        <tr style="text-align: left; border-bottom: 1px solid var(--thema-rand, #ddd);">
            <th data-label="" style="padding: 6px 8px;">Naam</th>
            <th data-label="" style="padding: 6px 8px;">Gebruikersnaam</th>
            <th data-label="" style="padding: 6px 8px;">E-mail</th>
            <th data-label="" style="padding: 6px 8px;">Aangemaakt</th>
            <th data-label="" style="padding: 6px 8px;">Laatst ingelogd</th>
            <th data-label="" style="padding: 6px 8px;">Status</th>
        </tr>
        <?php foreach ($superUsers as $beheerder): ?>
        <tr style="border-bottom: 1px solid var(--thema-rand, #eee);">
            <td data-label="Naam" style="padding: 6px 8px;"><?php echo htmlspecialchars($beheerder['name'] ?? '-'); ?></td>
            <td data-label="Gebruikersnaam" style="padding: 6px 8px;"><?php echo htmlspecialchars($beheerder['username'] ?? '-'); ?></td>
            <td data-label="E-mail" style="padding: 6px 8px;"><?php echo htmlspecialchars($beheerder['email'] ?? '-'); ?></td>
            <td data-label="Aangemaakt" style="padding: 6px 8px;"><?php echo htmlspecialchars($beheerder['registerDate'] ?? '-'); ?></td>
            <td data-label="Laatst ingelogd" style="padding: 6px 8px;"><?php echo htmlspecialchars($beheerder['lastvisitDate'] ?: 'nooit ingelogd'); ?></td>
            <td data-label="Status" style="padding: 6px 8px;">
                <?php if ((int) ($beheerder['block'] ?? 0) === 1): ?>
                    <span style="color: var(--thema-uitleg-tekst);">⛔ geblokkeerd</span>
                <?php else: ?>
                    <span class="groen">actief</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

<div class="acties-boven" id="acties-boven-blok" style="<?php echo ($vertrouwdAantal > 0 || !empty($kernBestandAfwijkingenVertrouwd) || !empty($bestandAfwijkingenVertrouwd)) ? '' : 'display: none;'; ?>">
    <?php if ($toonAlles): ?>
        <a class="knop secundair" href="beveiliging.php?id=<?php echo $id; ?>">Verberg vertrouwde items</a>
    <?php else: ?>
        <a class="knop secundair" href="beveiliging.php?id=<?php echo $id; ?>&toon_vertrouwd=1">Toon ook de vertrouwde items</a>
    <?php endif; ?>
</div>

<?php
// Onvoorwaardelijk (dus buiten de if/elseif/else hieronder) geplaatst: deze
// viewer wordt niet alleen gebruikt door de "Bekijk"-knoppen in de
// verdachte-items-tabel (die er soms niet is, bijv. als alles al vertrouwd
// is), maar sinds kort ook door de "Bekijk"-knop in de "Afwijkende
// bestanden"-tabel verderop - die staat er altijd, ongeacht of er
// verdachte items te tonen zijn. Stond deze div eerder alleen in de
// "else"-tak hieronder, dan gaf een klik op die laatste knop een JS-fout
// ("viewer is null") zodra er geen nieuwe verdachte items waren.
?>
<div id="bekijk-viewer"></div>

<?php if ($totaalAantal === 0): ?>

    <div class="leeg">
        🟢 Er zijn geen verdachte items gevonden bij de laatste scan.
    </div>

<?php elseif (empty($teTonenItems)): ?>

    <div class="leeg">
        🟢 Geen nieuwe verdachte items. Alle <?php echo $vertrouwdAantal; ?> gevonden item(s) zijn gemarkeerd als vertrouwd.
        Klik op "Toon ook de vertrouwde items" hierboven om ze alsnog te bekijken.
    </div>

<?php else: ?>

<?php if (count($aanwezigeTypen) > 1): ?>
<div style="margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
    <label for="type-filter" style="font-weight: bold; margin: 0;">Filter op type:</label>
    <select id="type-filter" onchange="filterOpType(this.value)" style="padding: 6px 10px; font-size: 13px;">
        <option value="">— Alle typen (<?php echo count($teTonenItems); ?>) —</option>
        <?php foreach ($aanwezigeTypen as $type): ?>
        <?php $aantalVanType = count(array_filter($teTonenItems, fn($item) => strtolower($item['type']) === $type)); ?>
        <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars(ucfirst($type)); ?> (<?php echo $aantalVanType; ?>)</option>
        <?php endforeach; ?>
    </select>
    <span id="type-filter-leeg" style="display: none; color: var(--thema-uitleg-tekst); font-size: 13px;">Geen items van dit type.</span>
</div>
<?php endif; ?>

<div id="bulk-balk" style="display: none; position: sticky; top: 0; z-index: 100; margin-bottom: 12px; padding: 10px 14px; border-radius: 6px; background: var(--thema-kader-bg); border: 1px solid var(--thema-rand); box-shadow: 0 2px 8px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
    <strong id="bulk-teller">0 geselecteerd</strong>
    <button type="button" class="btn-vertrouwen" style="padding: 5px 10px; font-size: 11px; border: none; border-radius: 3px; color: white; cursor: pointer;" onclick="bulkVertrouwen()">✅ Vertrouwen</button>
    <button type="button" class="btn-bekijk" style="padding: 5px 10px; font-size: 11px; border: none; border-radius: 3px; color: white; cursor: pointer;" onclick="bulkBekijken()">👁️ Bekijk</button>
    <button type="button" class="btn-rechten" style="padding: 5px 10px; font-size: 11px; border: none; border-radius: 3px; color: white; cursor: pointer;" onclick="bulkRechtenHerstellen()">🔧 Rechten herstellen</button>
    <button type="button" class="btn-quarantaine" style="padding: 5px 10px; font-size: 11px; border: none; border-radius: 3px; color: white; cursor: pointer;" onclick="bulkActie('quarantaine', 'de geselecteerde items in quarantaine plaatsen? Herstelbaar via de beheersectie hieronder.')">📦 Quarantaine</button>
    <button type="button" class="btn-blokkeer" style="padding: 5px 10px; font-size: 11px; border: none; border-radius: 3px; color: white; cursor: pointer;" onclick="bulkActie('blokkeer', 'de geselecteerde items blokkeren? Blijven op hun plek maar worden onuitvoerbaar. Herstelbaar.')">🚫 Blokkeer</button>
    <button type="button" class="btn-verwijder" style="padding: 5px 10px; font-size: 11px; border: none; border-radius: 3px; color: white; cursor: pointer;" onclick="bulkActie('verwijder', 'de geselecteerde items naar de prullenbak verplaatsen? Worden na 7 dagen automatisch definitief verwijderd.')">🗑️ Verwijder</button>
    <span id="bulk-voortgang" style="font-size: 12px; color: var(--thema-uitleg-tekst);"></span>
</div>

<table class="responsive-tabel">
<tr>
    <th style="width: 36px; text-align: center;"><input type="checkbox" id="bulk-alles" class="vertrouwd-checkbox" onclick="bulkAllesSelecteren(this)" title="Alles (de)selecteren"></th>
    <th style="width: 90px;">Risico</th>
    <th style="width: 100px;">Type</th>
    <th style="width: 25%;">Naam / pad</th>
    <th style="width: 110px;">Gewijzigd</th>
    <th>Reden</th>
    <th style="width: 130px;">Beheer</th>
</tr>
<?php foreach ($teTonenItems as $item): ?>
<?php $isVertrouwd = isset($vertrouwdHashes[$item['hash']]); ?>
<tr class="<?php echo $isVertrouwd ? 'vertrouwd-rij' : ''; ?>" data-pad="<?php echo htmlspecialchars($item['naam']); ?>" data-rij-type="<?php echo htmlspecialchars(strtolower($item['type'])); ?>">
    <td data-label="" class="vinkje-kolom">
        <input
            type="checkbox"
            class="vertrouwd-checkbox bulk-checkbox"
            data-hash="<?php echo htmlspecialchars($item['hash']); ?>"
            data-naam="<?php echo htmlspecialchars($item['naam']); ?>"
            data-type="<?php echo htmlspecialchars(strtolower($item['type'])); ?>"
            onclick="bulkKnoppenBijwerken()"
        >
    </td>
    <td data-label="Risico"><?php echo risicoBadgeHtml($item['risico'] ?? 50); ?></td>
    <td data-label="Type"><span class="type-badge <?php echo typeKlasse($item['type']); ?>"><?php echo htmlspecialchars($item['type']); ?></span></td>
    <td data-label="Naam / pad"><?php echo htmlspecialchars($item['naam']); ?></td>
    <td data-label="Gewijzigd"><?php echo htmlspecialchars($item['gewijzigd']); ?></td>
    <td data-label="Reden"><?php echo htmlspecialchars($item['reden']); ?></td>
    <td data-label="Beheer">
        <div class="beheeracties-cel">
            <button
                type="button"
                class="btn-vertrouwen vertrouwen-knop"
                data-hash="<?php echo htmlspecialchars($item['hash']); ?>"
                data-naam="<?php echo htmlspecialchars($item['naam']); ?>"
                data-vertrouwd="<?php echo $isVertrouwd ? '1' : '0'; ?>"
                onclick="wisselVertrouwen(this)"
            ><?php echo $isVertrouwd ? '↩️ Niet meer vertrouwen' : '✅ Vertrouwen'; ?></button>
            <?php if (strtolower($item['type']) !== 'database' && strtolower($item['type']) !== 'cluster'): ?>
            <button type="button" class="btn-bekijk" onclick="beheerBekijk(this)">👁️ Bekijk</button>
            <button type="button" class="btn-rechten" onclick="beheerRechtenHerstellen(this)">🔧 Rechten herstellen</button>
            <button type="button" class="btn-quarantaine" onclick="beheerActie(this, 'quarantaine', 'In quarantaine plaatsen? Herstelbaar via de beheersectie hieronder.')">📦 Quarantaine</button>
            <button type="button" class="btn-blokkeer" onclick="beheerActie(this, 'blokkeer', 'Blokkeren? Blijft op zijn plek maar wordt onuitvoerbaar. Herstelbaar.')">🚫 Blokkeer</button>
            <button type="button" class="btn-verwijder" onclick="beheerActie(this, 'verwijder', 'Naar de prullenbak verplaatsen? Wordt na 7 dagen automatisch definitief verwijderd.')">🗑️ Verwijder</button>
            <?php elseif (strtolower($item['type']) === 'cluster'): ?>
            <div style="font-size: 11px; color: var(--thema-uitleg-tekst);">Verzamelmelding over meerdere bestanden - niet als één geheel te verwijderen. Bekijk en verwerk de afzonderlijke bestanden handmatig via FTP (zie "Reden").</div>
            <?php else: ?>
            <div style="font-size: 11px; color: var(--thema-uitleg-tekst);">Database-bevinding - los op via Joomla Beheerder (zie "Reden").</div>
            <?php endif; ?>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</table>

<?php endif; ?>

<div class="beheer-sectie">
    <h2>🗄️ Beheer (quarantaine, geblokkeerd, prullenbak)</h2>
    <div class="subtitel" style="margin-bottom: 10px;">
        Alles wat via de knoppen hierboven in quarantaine, geblokkeerd of naar de prullenbak is gezet, staat hier -
        met de mogelijkheid om het weer terug te zetten. Prullenbak-items worden na 7 dagen automatisch definitief
        verwijderd.
    </div>
    <div id="beheer-lijst">⏳ Laden...</div>
</div>

<?php if (!empty($bestandAfwijkingenActief)): ?>
<h2 style="margin-top: 35px;">🔍 Afwijkende bestanden (vergeleken met andere sites)</h2>
<div class="subtitel" style="margin-bottom: 15px;">
    Deze bestanden horen bij een extensie + versie (of bij dezelfde Joomla-kernversie) die ook op andere
    gemonitorde sites voorkomt, maar wijken op déze site af van de inhoud die bij de meeste van die andere sites
    is aangetroffen.
    <br><br>
    Mogelijke verklaringen: het bestand is hier aangepast (bijv. door een backdoor), het is een onschuldige,
    handmatige aanpassing die je zelf ooit hebt gedaan, of het is een bestand dat de extensie zelf bewust per site
    uniek genereert (bijv. een geheime sleutel).
    <br><br>
    <strong>👁️ Bekijk (deze site)</strong> toont de huidige inhoud van het bestand op déze site, in het linker
    paneel hieronder. Bij "Zelfde versie bij" / "Andere versie bij" in de tabel staan de andere site(s) die dit
    bestand hebben, elk met een eigen 🔍-link - klik daarop om de inhoud van díe site ernaast in het rechter
    paneel te laden, zodat je de twee écht naast elkaar kunt vergelijken zonder zelf via FTP te hoeven zoeken.
    <br>
    <strong>✅ Vertrouwen</strong> markeert een afwijking als onschuldig - telt dan niet meer mee als waarschuwing
    (ook niet op de monitor-overzichtspagina), tenzij het bestand hier later opnieuw verandert.
    <br><br>
    Staat er <strong>"geen duidelijke meerderheid"</strong>? Dan zijn de sites met dit bestand onderling verdeeld
    (bijv. 1 tegen 1, of 2 tegen 2) - er is dan geen versie die vaker voorkomt dan de andere(n), dus is het niet
    per se déze site die de afwijkende/foute is. Gebruik de 🔍-links om de andere versie(s) te bekijken en zelf te
    bepalen welke correct is.
    <br><br>
    Wijken van dezelfde extensie veel bestanden tegelijk op precies dezelfde manier af? Dan worden die
    samengevoegd tot één rij (bijv. "12 bestanden wijken op dezelfde manier af", standaard al uitgeklapt) - dat
    wijst meestal op een andere sub-versie/build van die extensie, niet op losse verdachte bestanden. Gebruik de
    knop <strong>"Vertrouw alle N bestanden"</strong> bovenaan die rij om ze in één keer allemaal te vertrouwen,
    in plaats van elk bestand los aan te klikken - klap de rij daarna dicht met het pijltje ervoor als je 'm niet
    meer nodig hebt. Een los bestand dat NIET in zo'n samengevoegde rij zit, wijkt op een eigen, afwijkende manier
    af binnen diezelfde extensie - dat verdient extra aandacht.
</div>
<table class="responsive-tabel">
<tr>
    <th style="width: 20%;">Extensie + versie</th>
    <th style="width: 22%;">Bestand</th>
    <th style="width: 15%;">Verhouding</th>
    <th style="width: 23%;">Zelfde/andere versie bij</th>
    <th style="width: 150px;">Actie</th>
</tr>
<?php
$gegroepeerdActief = groepeerBulkAfwijkingen($bestandAfwijkingenActief);
foreach ($gegroepeerdActief['clusters'] as $cluster) {
    renderBulkClusterRij($cluster, 'bekijk-viewer-afwijkingen-actief', false);
}
foreach ($gegroepeerdActief['los'] as $afwijking) {
    echo renderAfwijkingRij($afwijking, 'bekijk-viewer-afwijkingen-actief', false);
}
?>
</table>
<?php echo renderTweeVeldenViewer('bekijk-viewer-afwijkingen-actief'); ?>
<?php endif; ?>

<?php if ($toonAlles && !empty($bestandAfwijkingenVertrouwd)): ?>
<h2 style="margin-top: 35px;">🔍✅ Afwijkende bestanden - vertrouwd</h2>
<div class="subtitel" style="margin-bottom: 15px;">
    Deze afwijkingen zijn eerder handmatig bekeken en als vertrouwd gemarkeerd - ze tellen niet meer mee als
    waarschuwing (ook niet op de monitor-overzichtspagina), maar blijven hier zichtbaar.
</div>
<table class="responsive-tabel">
<tr>
    <th style="width: 20%;">Extensie + versie</th>
    <th style="width: 22%;">Bestand</th>
    <th style="width: 15%;">Verhouding</th>
    <th style="width: 23%;">Zelfde/andere versie bij</th>
    <th style="width: 150px;">Actie</th>
</tr>
<?php
$gegroepeerdVertrouwd = groepeerBulkAfwijkingen($bestandAfwijkingenVertrouwd);
foreach ($gegroepeerdVertrouwd['clusters'] as $cluster) {
    renderBulkClusterRij($cluster, 'bekijk-viewer-afwijkingen-vertrouwd', true);
}
foreach ($gegroepeerdVertrouwd['los'] as $afwijking) {
    echo renderAfwijkingRij($afwijking, 'bekijk-viewer-afwijkingen-vertrouwd', true);
}
?>
</table>
<?php echo renderTweeVeldenViewer('bekijk-viewer-afwijkingen-vertrouwd'); ?>
<?php endif; ?>

<?php if (!empty($kernBestandAfwijkingenActief)): ?>
<h2 style="margin-top: 35px;">🛡️ Kernbestanden vs. officieel Joomla-pakket</h2>
<div class="subtitel" style="margin-bottom: 15px;">
    Deze Joomla-kernbestanden wijken af van het officiële, ongewijzigde pakket van downloads.joomla.org - een
    directe vergelijking, los van wat andere gemonitorde sites hebben (werkt dus ook als deze Joomla-kernversie
    verder nergens anders bij jou voorkomt). Alleen bestanden die daadwerkelijk gehasht zijn tijdens de scan én
    aantoonbaar afwijken worden hier getoond - de kern wordt niet gegarandeerd voor 100% meegescand, dus een
    bestand hier niet zien betekent niet automatisch dat het onaangetast is. Controleer elk gemeld bestand
    handmatig via FTP voordat je actie onderneemt - een enkele, bewuste eigen aanpassing aan een kernbestand kan
    hier ook als afwijking verschijnen.
</div>
<table class="responsive-tabel">
<tr>
    <th style="width: 20%;">Joomla-kernversie</th>
    <th>Bestand</th>
    <th style="width: 15%;">Status</th>
    <th style="width: 15%;">Actie</th>
</tr>
<?php foreach ($kernBestandAfwijkingenActief as $kernAfwijking): ?>
<tr>
    <td data-label="Joomla-kernversie"><code><?php echo htmlspecialchars($kernAfwijking['kernversie']); ?></code></td>
    <td data-label="Bestand"><?php echo htmlspecialchars($kernAfwijking['relatief_pad']); ?></td>
    <td data-label="Status">
        <?php if ($kernAfwijking['status'] === 'ontbreekt'): ?>
            <span class="badge type-rood">ontbreekt</span>
        <?php else: ?>
            <span class="badge type-oranje">gewijzigd</span>
        <?php endif; ?>
    </td>
    <td data-label="Actie">
        <a href="bekijk_kern_afwijking.php?id=<?php echo (int) $kernAfwijking['id']; ?>">🔍 Bekijk verschil</a>
    </td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<?php if ($toonAlles && !empty($kernBestandAfwijkingenVertrouwd)): ?>
<h2 style="margin-top: 35px;">🛡️✅ Kernbestanden - vertrouwd</h2>
<div class="subtitel" style="margin-bottom: 15px;">
    Deze afwijkingen zijn eerder handmatig bekeken en als vertrouwd gemarkeerd - ze tellen niet meer mee als
    waarschuwing, maar blijven hier zichtbaar en zijn nog steeds aan te klikken, bijvoorbeeld om alsnog te
    vervangen door de officiële versie.
</div>
<table class="responsive-tabel">
<tr>
    <th style="width: 20%;">Joomla-kernversie</th>
    <th>Bestand</th>
    <th style="width: 15%;">Status</th>
    <th style="width: 15%;">Actie</th>
</tr>
<?php foreach ($kernBestandAfwijkingenVertrouwd as $kernAfwijking): ?>
<tr class="vertrouwd-rij">
    <td data-label="Joomla-kernversie"><code><?php echo htmlspecialchars($kernAfwijking['kernversie']); ?></code></td>
    <td data-label="Bestand"><?php echo htmlspecialchars($kernAfwijking['relatief_pad']); ?></td>
    <td data-label="Status"><span class="badge type-groen">✅ vertrouwd</span></td>
    <td data-label="Actie">
        <a href="bekijk_kern_afwijking.php?id=<?php echo (int) $kernAfwijking['id']; ?>">🔍 Bekijk verschil</a>
    </td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>


<script>
const SITE_ID    = <?php echo (int)$id; ?>;
const TOON_ALLES = <?php echo $toonAlles ? 'true' : 'false'; ?>;
const TOTAAL     = <?php echo (int)$totaalAantal; ?>;
const CSRF_TOKEN = <?php echo json_encode(haalCsrfToken()); ?>;

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

let huidigNieuw     = <?php echo (int)$nieuwAantal; ?>;
let huidigVertrouwd = <?php echo (int)$vertrouwdAantal; ?>;

function zetVoortgang(percentage, klaar = false, fout = false) {
    const balkBuiten = document.getElementById('voortgang-buiten');
    const balkBinnen = document.getElementById('voortgang-binnen');

    balkBuiten.style.display = 'block';
    balkBinnen.style.width = percentage + '%';
    balkBinnen.textContent = Math.round(percentage) + '%';
    balkBinnen.classList.toggle('klaar', klaar);
    balkBinnen.classList.toggle('fout', fout);
}

function herscanDezeSite(knop) {
    knop.disabled = true;

    const melding = document.getElementById('melding');
    melding.className = '';
    melding.style.display = 'block';
    melding.style.background = '#eef1f4';
    melding.style.color = '#333';
    melding.textContent = '⏳ Scan wordt gestart voor deze website...';
    zetVoortgang(5);

    fetch('start_scan.php?site_id=' + SITE_ID)
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(tekst => {
            if (tekst.includes('⚠️')) {
                // Het scanverzoek zelf kwam al niet goed aan (bijv. een
                // .htaccess-bestand dat het onderschept) - dan heeft
                // doorgaan naar de wachttijd/hercontrole geen zin.
                melding.style.background = '#fff3cd';
                melding.style.color = '#665200';
                melding.textContent = tekst.replace(/^[^:]+:\s*/, '');
                zetVoortgang(5, false, true);
                knop.disabled = false;
                return;
            }

            const WACHTTIJD_SECONDEN = 10;
            const percentageBijStartWachten = 10;
            const percentageBijEindeWachten = 55;
            let secondenOver = WACHTTIJD_SECONDEN;

            melding.textContent = '✅ Scan gestart. Even wachten... (' + secondenOver + ')';
            zetVoortgang(percentageBijStartWachten);

            const teller = setInterval(() => {
                secondenOver--;

                const voortgang = percentageBijStartWachten
                    + (percentageBijEindeWachten - percentageBijStartWachten)
                    * (WACHTTIJD_SECONDEN - secondenOver) / WACHTTIJD_SECONDEN;
                zetVoortgang(voortgang);

                if (secondenOver > 0) {
                    melding.textContent = '✅ Scan gestart. Even wachten... (' + secondenOver + ')';
                } else {
                    clearInterval(teller);
                    melding.textContent = '⏳ Website- en SSL-status controleren...';
                    zetVoortgang(percentageBijEindeWachten);

                    fetch('check_sites.php?site_id=' + SITE_ID)
                        .then(r => {
                            if (!r.ok) throw new Error('HTTP ' + r.status);
                            return r.text();
                        })
                        .then(() => {
                            melding.textContent = '⏳ Joomla- en extensieversies ophalen...';
                            zetVoortgang(80);
                            return fetch('haal_versies_op.php?site_id=' + SITE_ID);
                        })
                        .then(r => {
                            if (!r.ok) throw new Error('HTTP ' + r.status);
                            return r.text();
                        })
                        .then(() => {
                            melding.style.background = '#d4edda';
                            melding.style.color = '#155724';
                            melding.textContent = '✅ Deze website is opnieuw gescand — pagina wordt herladen...';
                            zetVoortgang(100, true);
                            setTimeout(() => location.reload(), 1200);
                        })
                        .catch(err => {
                            melding.style.background = '#f8d7da';
                            melding.style.color = '#721c24';
                            melding.textContent = '❌ Er ging iets mis: ' + err.message;
                            zetVoortgang(percentageBijEindeWachten, false, true);
                            knop.disabled = false;
                        });
                }
            }, 1000);
        })
        .catch(err => {
            melding.style.background = '#f8d7da';
            melding.style.color = '#721c24';
            melding.textContent = '❌ Er ging iets mis bij het starten van de scan: ' + err.message;
            zetVoortgang(5, false, true);
            knop.disabled = false;
        });
}

function updateTellers() {
    const nieuwEl     = document.getElementById('teller-nieuw');
    const vertrouwdEl = document.getElementById('teller-vertrouwd');
    const actiesBlok  = document.getElementById('acties-boven-blok');

    vertrouwdEl.textContent = huidigVertrouwd;

    if (TOTAAL === 0) {
        nieuwEl.innerHTML = '<span class="groen">🟢 0 - Schoon</span>';
    } else if (huidigNieuw === 0) {
        nieuwEl.innerHTML = '<span class="groen">🟢 0</span>';
    } else {
        nieuwEl.innerHTML = '<span class="rood">🔴 ' + huidigNieuw + '</span>';
    }

    if (actiesBlok) {
        actiesBlok.style.display = huidigVertrouwd > 0 ? '' : 'none';
    }
}

// ------------------------------------------------------------------
// Beheeracties: bekijken, quarantaine/blokkeer/verwijder per vondst,
// en de beheersectie (herstel/definitief verwijderen/prullenbak legen).
// ------------------------------------------------------------------

function beheerFetch(actie, extraVelden, knop, siteId = SITE_ID) {
    const body = new URLSearchParams();
    body.append('csrf_token', CSRF_TOKEN);
    body.append('site_id', siteId);
    body.append('actie', actie);
    for (const [k, v] of Object.entries(extraVelden)) {
        body.append(k, v);
    }

    if (knop) {
        knop.disabled = true;
    }

    return fetch('site_beheer_actie.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
        .then(r => r.json())
        .finally(() => {
            if (knop) {
                knop.disabled = false;
            }
        });
}

// Houdt per panelenpaar (bijv. "bekijk-viewer-afwijkingen-actief") bij wat
// er op dit moment in kant "a" en "b" geladen is - nodig om te weten
// wanneer allebei een bestand hebben (dan pas heeft een regel-diff zin) en
// om bij het wijzigen van één kant de vergelijking opnieuw te berekenen.
const viewerParenInhoud = {};

/**
 * Rendert een array van {tekst, type}-regels (type: 'gelijk' of 'anders')
 * als genummerde regels, in de stijl van Notepad++ - een smalle, grijze
 * regelnummer-kolom links, zodat je bij het scrollen door twee naast
 * elkaar staande bestanden in de buurt van hetzelfde regelnummer kunt
 * blijven kijken om verschillen te vinden. Wordt gebruikt voor zowel de
 * gewone (niet-vergeleken) weergave als de diff-gekleurde weergave.
 */
function renderGenummerdeRegels(regels) {
    return regels.map((r, idx) => {
        const achtergrond = r.type === 'anders' ? 'background: rgba(255, 99, 71, 0.35);' : '';
        const tekst = escapeHtml(r.tekst);
        return '<div style="display: flex; ' + achtergrond + '">'
            + '<span style="flex: 0 0 44px; text-align: right; padding-right: 10px; margin-right: 8px; color: #64748b; border-right: 1px solid #334155; user-select: none;">' + (idx + 1) + '</span>'
            + '<span style="flex: 1; white-space: pre-wrap; word-break: break-all;">' + (tekst === '' ? '&nbsp;' : tekst) + '</span>'
            + '</div>';
    }).join('');
}

function beheerBekijk(knop, viewerId = 'bekijk-viewer', siteId = SITE_ID, siteLabel = '') {
    const rij = knop.closest('tr');
    const pad = rij.dataset.pad;
    const viewer = document.getElementById(viewerId);

    viewer.innerHTML = '<div class="viewer"><div class="kop"><strong>⏳ Laden...</strong></div></div>';
    viewer.scrollIntoView({ behavior: 'smooth', block: 'center' });

    beheerFetch('bekijk', { pad: pad }, knop, siteId).then(data => {
        if (!data.succes) {
            viewer.innerHTML = '<div class="viewer"><div class="kop"><strong>❌ ' + escapeHtml(data.foutmelding) + '</strong></div></div>';
            return;
        }
        const metaTekst = data.type === 'bestand'
            ? (data.grootte.toLocaleString() + ' bytes' + (data.afgekapt ? ' (eerste 64 KB getoond)' : ''))
            : 'mapinhoud';
        // Bij een andere site dan de huidige (siteId !== SITE_ID) er
        // duidelijk bij vermelden BIJ WELKE site dit is opgehaald - anders
        // is bij twee viewers na elkaar niet meer te zien welke van welke
        // site was.
        const bronTekst = siteLabel ? (' - ' + escapeHtml(siteLabel)) : (siteId != SITE_ID ? ' - andere site' : '');
        const regelsVoorWeergave = data.type === 'bestand'
            ? data.inhoud.split('\n').map(t => ({ tekst: t, type: 'gelijk' }))
            : null;
        viewer.innerHTML = '<div class="viewer">'
            + '<div class="kop"><strong>👁️ ' + escapeHtml(pad) + bronTekst + '</strong><span>' + metaTekst + '</span></div>'
            + '<pre>' + (regelsVoorWeergave ? renderGenummerdeRegels(regelsVoorWeergave) : escapeHtml(data.inhoud)) + '</pre>'
            + '</div>';

        // Bijhouden voor de naast-elkaar-vergelijking: alleen relevant voor
        // de linker/rechter panelen van een vergelijkingspaar (id eindigt
        // op "-a"/"-b"), niet voor de losse viewer bij de gewone
        // verdachte-items-tabel bovenaan.
        if (viewerId.endsWith('-a') || viewerId.endsWith('-b')) {
            const paarPrefix = viewerId.slice(0, -2);
            const kant = viewerId.slice(-1);
            if (!viewerParenInhoud[paarPrefix]) {
                viewerParenInhoud[paarPrefix] = {};
            }
            viewerParenInhoud[paarPrefix][kant] = { pad: pad, inhoud: data.inhoud, type: data.type };
            pasRegelDiffToe(paarPrefix);
        }
    }).catch(err => {
        viewer.innerHTML = '<div class="viewer"><div class="kop"><strong>❌ Er ging iets mis: ' + escapeHtml(err.message) + '</strong></div></div>';
    });
}

/**
 * Eenvoudige, regel-gebaseerde diff via de klassieke LCS-aanpak (Longest
 * Common Subsequence): geeft voor beide bestanden een array met per regel
 * een type ('gelijk' of 'anders') terug. Wordt gebruikt om in de twee
 * naast-elkaar-panelen de afwijkende regels een kleur te geven.
 */
function eenvoudigeRegelDiff(regelsA, regelsB) {
    const n = regelsA.length;
    const m = regelsB.length;

    // Bij erg grote bestanden zou de volledige n×m-tabel te veel geheugen/
    // tijd kosten voor een simpele klik-en-vergelijk-actie in de browser -
    // dan liever geen diff-kleuring dan een vastlopende pagina.
    if (n * m > 4000000) {
        return null;
    }

    const dp = new Array(n + 1);
    for (let i = 0; i <= n; i++) {
        dp[i] = new Uint32Array(m + 1);
    }
    for (let i = n - 1; i >= 0; i--) {
        for (let j = m - 1; j >= 0; j--) {
            dp[i][j] = regelsA[i] === regelsB[j]
                ? dp[i + 1][j + 1] + 1
                : Math.max(dp[i + 1][j], dp[i][j + 1]);
        }
    }

    const uitA = [];
    const uitB = [];
    let i = 0, j = 0;
    while (i < n && j < m) {
        if (regelsA[i] === regelsB[j]) {
            uitA.push({ tekst: regelsA[i], type: 'gelijk' });
            uitB.push({ tekst: regelsB[j], type: 'gelijk' });
            i++; j++;
        } else if (dp[i + 1][j] >= dp[i][j + 1]) {
            uitA.push({ tekst: regelsA[i], type: 'anders' });
            i++;
        } else {
            uitB.push({ tekst: regelsB[j], type: 'anders' });
            j++;
        }
    }
    while (i < n) { uitA.push({ tekst: regelsA[i], type: 'anders' }); i++; }
    while (j < m) { uitB.push({ tekst: regelsB[j], type: 'anders' }); j++; }

    return { a: uitA, b: uitB };
}

/**
 * Past, als beide kanten van een panelenpaar hetzelfde bestand (zelfde pad)
 * geladen hebben, een regel-diff toe: afwijkende regels krijgen een
 * gekleurde achtergrond in beide panelen. Wordt na elke geslaagde
 * beheerBekijk()-lading opnieuw aangeroepen, dus werkt ook bij het
 * doorwisselen naar een andere site in het rechterpaneel.
 */
function pasRegelDiffToe(paarPrefix) {
    const paar = viewerParenInhoud[paarPrefix];
    if (!paar || !paar.a || !paar.b) {
        return; // nog niet allebei geladen
    }
    if (paar.a.type !== 'bestand' || paar.b.type !== 'bestand') {
        return; // een van beide is een mapinhoud - regel-diff heeft dan geen zin
    }
    if (paar.a.pad !== paar.b.pad) {
        return; // (kan gebeuren als de twee panelen toevallig bij verschillende rijen horen) - geen zinnige vergelijking dan
    }

    const diff = eenvoudigeRegelDiff(paar.a.inhoud.split('\n'), paar.b.inhoud.split('\n'));

    [['a', diff ? diff.a : null], ['b', diff ? diff.b : null]].forEach(([kant, regels]) => {
        const paneel = document.getElementById(paarPrefix + '-' + kant);
        if (!paneel) {
            return;
        }
        const pre = paneel.querySelector('.viewer pre');
        const kop = paneel.querySelector('.viewer .kop');
        if (!pre) {
            return;
        }
        if (regels === null) {
            // Te groot voor een diff - gewone (niet-gekleurde, wel al genummerde) weergave laten staan.
            return;
        }
        pre.innerHTML = renderGenummerdeRegels(regels);
        if (kop) {
            const infoSpan = kop.querySelector('span');
            if (infoSpan && !infoSpan.dataset.diffLegenda) {
                infoSpan.textContent += ' · 🎨 afwijkende regels gemarkeerd';
                infoSpan.dataset.diffLegenda = '1';
            }
        }
    });
}

function beheerRechtenHerstellen(knop) {
    const rij = knop.closest('tr');
    const pad = rij.dataset.pad;

    beheerFetch('rechten_herstellen', { pad: pad }, knop).then(data => {
        if (!data.succes) {
            alert('❌ ' + data.foutmelding);
            return;
        }
        alert('✅ ' + data.melding);
    }).catch(err => {
        alert('❌ Er ging iets mis: ' + err.message);
    });
}

function beheerActie(knop, actie, bevestigTekst) {
    if (!confirm(bevestigTekst)) {
        return;
    }
    const rij = knop.closest('tr');
    const pad = rij.dataset.pad;

    beheerFetch(actie, { pad: pad }, knop).then(data => {
        if (!data.succes) {
            alert('❌ ' + data.foutmelding);
            return;
        }
        rij.style.transition = 'opacity 0.3s';
        rij.style.opacity = '0';
        setTimeout(() => {
            rij.remove();
            huidigNieuw = Math.max(0, huidigNieuw - 1);
            updateTellers();
        }, 300);
        laadBeheerLijst();
    }).catch(err => {
        alert('❌ Er ging iets mis: ' + err.message);
    });
}

function escapeHtml(tekst) {
    const div = document.createElement('div');
    div.textContent = tekst == null ? '' : String(tekst);
    return div.innerHTML;
}

// ------------------------------------------------------------------
// Bulkselectie en bulkacties: hetzelfde vertrouwen/bekijken/quarantaine/
// blokkeren/verwijderen als de losse knoppen per rij, maar dan in één
// keer toegepast op alle aangevinkte items - scheelt bij veel vondsten
// het één-voor-één aanklikken.
// ------------------------------------------------------------------

function filterOpType(gekozenType) {
    const rijen = document.querySelectorAll('table.responsive-tabel tr[data-rij-type]');
    let zichtbaarAantal = 0;

    rijen.forEach(rij => {
        const toon = gekozenType === '' || rij.dataset.rijType === gekozenType;
        rij.style.display = toon ? '' : 'none';

        if (!toon) {
            // Een verborgen rij mag niet stiekem meetellen bij een
            // bulkactie - dus deselecteren als 'ie uit beeld verdwijnt.
            const checkbox = rij.querySelector('.bulk-checkbox');
            if (checkbox && checkbox.checked) {
                checkbox.checked = false;
            }
        } else {
            zichtbaarAantal++;
        }
    });

    document.getElementById('type-filter-leeg').style.display = zichtbaarAantal === 0 ? 'inline' : 'none';
    bulkKnoppenBijwerken();
}

function bulkCheckboxes() {
    return Array.from(document.querySelectorAll('.bulk-checkbox'));
}

function bulkGeselecteerd() {
    return bulkCheckboxes().filter(cb => cb.checked);
}

function bulkAllesSelecteren(alles) {
    bulkCheckboxes().forEach(cb => {
        const rij = cb.closest('tr');
        if (rij.style.display !== 'none') {
            cb.checked = alles.checked;
        }
    });
    bulkKnoppenBijwerken();
}

function bulkKnoppenBijwerken() {
    const aantal = bulkGeselecteerd().length;
    const balk = document.getElementById('bulk-balk');
    const teller = document.getElementById('bulk-teller');

    balk.style.display = aantal > 0 ? 'flex' : 'none';
    teller.textContent = aantal + ' geselecteerd';
}

function bulkVoortgangTonen(huidig, totaal) {
    document.getElementById('bulk-voortgang').textContent = totaal > 0 ? `(${huidig}/${totaal} verwerkt)` : '';
}

function bulkVertrouwen() {
    const checkboxes = bulkGeselecteerd();
    if (checkboxes.length === 0) {
        return;
    }
    if (!confirm(checkboxes.length + ' geselecteerde item(s) vertrouwen?')) {
        return;
    }

    let huidig = 0;
    bulkVoortgangTonen(0, checkboxes.length);

    const volgende = () => {
        if (huidig >= checkboxes.length) {
            bulkVoortgangTonen(0, 0);
            return;
        }
        const cb = checkboxes[huidig];
        const hash = cb.dataset.hash;
        const naam = cb.dataset.naam;

        fetch('markeer_vertrouwd.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'site_id=' + encodeURIComponent(SITE_ID)
                + '&hash=' + encodeURIComponent(hash)
                + '&naam=' + encodeURIComponent(naam)
                + '&actie=vertrouw'
                + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
        })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    const rij = cb.closest('tr');
                    rij.classList.add('vertrouwd-rij');
                    const knop = rij.querySelector('.vertrouwen-knop');
                    if (knop) {
                        knop.dataset.vertrouwd = '1';
                        knop.textContent = '↩️ Niet meer vertrouwen';
                    }
                    huidigVertrouwd++;
                    huidigNieuw = Math.max(0, huidigNieuw - 1);
                }
            })
            .finally(() => {
                huidig++;
                bulkVoortgangTonen(huidig, checkboxes.length);
                volgende();
            });
    };
    volgende();

    setTimeout(() => {
        updateTellers();
        bulkKnoppenBijwerken();
    }, checkboxes.length * 250 + 300);
}

function bulkBekijken() {
    const checkboxes = bulkGeselecteerd().filter(cb => cb.dataset.type !== 'database');
    if (checkboxes.length === 0) {
        alert('Geen van de geselecteerde items kan bekeken worden (database-bevindingen hebben geen bestandsinhoud).');
        return;
    }

    const viewer = document.getElementById('bekijk-viewer');
    viewer.innerHTML = '<div class="viewer"><div class="kop"><strong>⏳ ' + checkboxes.length + ' item(s) laden...</strong></div></div>';
    viewer.scrollIntoView({ behavior: 'smooth', block: 'start' });

    Promise.all(checkboxes.map(cb => {
        const pad = cb.closest('tr').dataset.pad;
        const body = new URLSearchParams();
        body.append('csrf_token', CSRF_TOKEN);
        body.append('site_id', SITE_ID);
        body.append('actie', 'bekijk');
        body.append('pad', pad);

        return fetch('site_beheer_actie.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(r => r.json())
            .then(data => ({ pad, data }))
            .catch(err => ({ pad, data: { succes: false, foutmelding: err.message } }));
    })).then(resultaten => {
        viewer.innerHTML = resultaten.map(({ pad, data }) => {
            if (!data.succes) {
                return '<div class="viewer" style="margin-bottom: 10px;"><div class="kop"><strong>❌ ' + escapeHtml(pad) + '</strong><span>' + escapeHtml(data.foutmelding) + '</span></div></div>';
            }
            const metaTekst = data.type === 'bestand'
                ? (data.grootte.toLocaleString() + ' bytes' + (data.afgekapt ? ' (eerste 64 KB getoond)' : ''))
                : 'mapinhoud';
            return '<div class="viewer" style="margin-bottom: 10px;">'
                + '<div class="kop"><strong>👁️ ' + escapeHtml(pad) + '</strong><span>' + metaTekst + '</span></div>'
                + '<pre>' + escapeHtml(data.inhoud) + '</pre>'
                + '</div>';
        }).join('');
    });
}

function bulkActie(actie, bevestigTekst) {
    const checkboxes = bulkGeselecteerd().filter(cb => cb.dataset.type !== 'database' && cb.dataset.type !== 'cluster');
    if (checkboxes.length === 0) {
        alert('Geen van de geselecteerde items ondersteunt deze actie (database-bevindingen los je op via Joomla Beheerder, verzamelmeldingen zijn niet als één geheel te verwijderen).');
        return;
    }
    if (!confirm('Weet je zeker dat je ' + bevestigTekst)) {
        return;
    }

    let huidig = 0;
    let aantalMislukt = 0;
    bulkVoortgangTonen(0, checkboxes.length);

    const volgende = () => {
        if (huidig >= checkboxes.length) {
            bulkVoortgangTonen(0, 0);
            if (aantalMislukt > 0) {
                alert(aantalMislukt + ' van de ' + checkboxes.length + ' item(s) konden niet worden verwerkt.');
            }
            laadBeheerLijst();
            bulkKnoppenBijwerken();
            return;
        }
        const cb = checkboxes[huidig];
        const rij = cb.closest('tr');
        const pad = rij.dataset.pad;

        const body = new URLSearchParams();
        body.append('csrf_token', CSRF_TOKEN);
        body.append('site_id', SITE_ID);
        body.append('actie', actie);
        body.append('pad', pad);

        fetch('site_beheer_actie.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(r => r.json())
            .then(data => {
                if (data.succes) {
                    rij.style.transition = 'opacity 0.3s';
                    rij.style.opacity = '0';
                    setTimeout(() => rij.remove(), 300);
                    huidigNieuw = Math.max(0, huidigNieuw - 1);
                } else {
                    aantalMislukt++;
                }
            })
            .catch(() => { aantalMislukt++; })
            .finally(() => {
                huidig++;
                bulkVoortgangTonen(huidig, checkboxes.length);
                updateTellers();
                volgende();
            });
    };
    volgende();
}

function bulkRechtenHerstellen() {
    // "map" (mappen) en "cluster" (verzamelmeldingen) uitsluiten - 644 geldt
    // alleen voor losse bestanden.
    const checkboxes = bulkGeselecteerd().filter(cb => cb.dataset.type !== 'database' && cb.dataset.type !== 'map' && cb.dataset.type !== 'cluster');
    if (checkboxes.length === 0) {
        alert('Geen van de geselecteerde items ondersteunt deze actie (geldt alleen voor losse bestanden, niet voor mappen, verzamelmeldingen of database-bevindingen).');
        return;
    }
    if (!confirm('Rechten van ' + checkboxes.length + ' geselecteerd(e) item(s) herstellen naar het gebruikelijke (644 voor bestanden, 755 voor mappen)?')) {
        return;
    }

    let huidig = 0;
    let aantalMislukt = 0;
    bulkVoortgangTonen(0, checkboxes.length);

    const volgende = () => {
        if (huidig >= checkboxes.length) {
            bulkVoortgangTonen(0, 0);
            alert('Klaar: ' + (checkboxes.length - aantalMislukt) + ' van de ' + checkboxes.length + ' bestand(en) verwerkt.'
                + (aantalMislukt > 0 ? ' ' + aantalMislukt + ' mislukt(e) actie(s) - controleer de eigenaar van die bestanden.' : ''));
            return;
        }
        const cb = checkboxes[huidig];
        const pad = cb.closest('tr').dataset.pad;

        const body = new URLSearchParams();
        body.append('csrf_token', CSRF_TOKEN);
        body.append('site_id', SITE_ID);
        body.append('actie', 'rechten_herstellen');
        body.append('pad', pad);

        fetch('site_beheer_actie.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(r => r.json())
            .then(data => {
                if (!data.succes) {
                    aantalMislukt++;
                }
            })
            .catch(() => { aantalMislukt++; })
            .finally(() => {
                huidig++;
                bulkVoortgangTonen(huidig, checkboxes.length);
                volgende();
            });
    };
    volgende();
}

function beheerRegelHtml(entry) {
    const labels = { quarantaine: '📦 Quarantaine', geblokkeerd: '🚫 Geblokkeerd', prullenbak: '🗑️ Prullenbak', kernbestand_backup: '🛡️ Kernbestand-backup' };
    const label = labels[entry.actie] || entry.actie;
    let vervalTekst = '';
    if (entry.actie === 'prullenbak' && entry.verloopt) {
        const dagenOver = Math.max(0, Math.ceil((entry.verloopt * 1000 - Date.now()) / 86400000));
        vervalTekst = ' · nog ' + dagenOver + ' dag(en) tot definitieve verwijdering';
    }
    return '<div class="beheer-item" data-id="' + escapeHtml(entry.id) + '">'
        + '<div><strong>' + label + '</strong> <span class="pad">' + escapeHtml(entry.origineel_rel) + '</span>'
        + '<div style="color:var(--thema-uitleg-tekst);font-size:11px;">' + escapeHtml(entry.tijd) + vervalTekst + '</div></div>'
        + '<div class="acties">'
        + '<button class="btn-herstel" onclick="beheerHerstel(this, \'' + entry.id + '\')">↩️ Herstel</button>'
        + '<button class="btn-definitief" onclick="beheerDefinitief(this, \'' + entry.id + '\')">❌ Definitief verwijderen</button>'
        + '</div></div>';
}

function laadBeheerLijst() {
    const container = document.getElementById('beheer-lijst');

    beheerFetch('status', {}).then(data => {
        if (!data.succes) {
            container.innerHTML = '<div class="leeg" style="background:#f8d7da;color:#721c24;">❌ ' + escapeHtml(data.foutmelding) + '</div>';
            return;
        }
        if (!data.manifest || data.manifest.length === 0) {
            container.innerHTML = '<div class="leeg">🟢 Niets in quarantaine, geblokkeerd of in de prullenbak.</div>';
            return;
        }

        const prullenbakItems = data.manifest.filter(e => e.actie === 'prullenbak');
        let html = data.manifest.map(beheerRegelHtml).join('');

        if (prullenbakItems.length > 0) {
            html += '<button type="button" class="knop" style="background:#c0392b;margin-top:10px;" onclick="beheerPrullenbakLegen(this)">🗑️ Prullenbak nu al legen (' + prullenbakItems.length + ')</button>';
        }

        container.innerHTML = html;
    }).catch(err => {
        container.innerHTML = '<div class="leeg" style="background:#f8d7da;color:#721c24;">❌ Kon de status niet ophalen: ' + escapeHtml(err.message) + '</div>';
    });
}

function beheerHerstel(knop, id) {
    beheerFetch('herstel', { id: id }, knop).then(data => {
        if (!data.succes) {
            alert('❌ ' + data.foutmelding);
            return;
        }
        alert('✅ ' + data.melding);
        laadBeheerLijst();
    }).catch(err => alert('❌ Er ging iets mis: ' + err.message));
}

function beheerDefinitief(knop, id) {
    if (!confirm('Definitief verwijderen? Dit kan niet meer ongedaan gemaakt worden.')) {
        return;
    }
    beheerFetch('definitief', { id: id }, knop).then(data => {
        if (!data.succes) {
            alert('❌ ' + data.foutmelding);
            return;
        }
        laadBeheerLijst();
    }).catch(err => alert('❌ Er ging iets mis: ' + err.message));
}

function beheerPrullenbakLegen(knop) {
    if (!confirm('Prullenbak nu al helemaal legen? Alle items worden DEFINITIEF verwijderd.')) {
        return;
    }
    beheerFetch('prullenbak_legen', {}, knop).then(data => {
        if (!data.succes) {
            alert('❌ ' + data.foutmelding);
            return;
        }
        laadBeheerLijst();
    }).catch(err => alert('❌ Er ging iets mis: ' + err.message));
}

laadBeheerLijst();

function wisselVertrouwen(knop) {
    const rij = knop.closest('tr');
    const hash = knop.dataset.hash;
    const naam = knop.dataset.naam;
    const nuVertrouwd = knop.dataset.vertrouwd === '1';
    const actie = nuVertrouwd ? 'ontvertrouw' : 'vertrouw';

    knop.disabled = true;

    fetch('markeer_vertrouwd.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'site_id=' + encodeURIComponent(SITE_ID)
            + '&hash=' + encodeURIComponent(hash)
            + '&naam=' + encodeURIComponent(naam)
            + '&actie=' + actie
            + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(r => r.json())
    .then(data => {
        knop.disabled = false;

        if (!data.ok) {
            alert('Opslaan is mislukt, probeer het opnieuw.');
            return;
        }

        const wordtVertrouwd = !nuVertrouwd;

        // Tellers direct bijwerken.
        if (wordtVertrouwd) {
            huidigVertrouwd++;
            huidigNieuw--;
        } else {
            huidigVertrouwd--;
            huidigNieuw++;
        }
        updateTellers();

        if (!TOON_ALLES && wordtVertrouwd) {
            // In de standaardweergave (alleen nieuwe items) verdwijnt
            // een net vertrouwd item direct uit de lijst.
            rij.style.transition = 'opacity 0.3s';
            rij.style.opacity = '0';
            setTimeout(() => {
                rij.remove();

                // Als er geen rijen meer over zijn, toon een netjes bericht.
                const tabel = document.querySelector('table');
                if (tabel && tabel.querySelectorAll('tbody tr, tr').length <= 1) {
                    const melding = document.createElement('div');
                    melding.className = 'leeg';
                    melding.textContent = '🟢 Geen nieuwe verdachte items. Alle ' + huidigVertrouwd + ' gevonden item(s) zijn gemarkeerd als vertrouwd.';
                    tabel.replaceWith(melding);
                }
            }, 300);
        } else {
            rij.classList.toggle('vertrouwd-rij', wordtVertrouwd);
            knop.dataset.vertrouwd = wordtVertrouwd ? '1' : '0';
            knop.textContent = wordtVertrouwd ? '↩️ Niet meer vertrouwen' : '✅ Vertrouwen';
        }
    })
    .catch(() => {
        knop.disabled = false;
        alert('Opslaan is mislukt, controleer de verbinding.');
    });
}

function wisselExtensieVertrouwen(knop) {
    const rij = knop.closest('tr');
    const pad = rij.dataset.pad;
    const groepSleutel = knop.dataset.groepSleutel;
    const hash = knop.dataset.hash;
    const nuVertrouwd = knop.dataset.vertrouwd === '1';
    const actie = nuVertrouwd ? 'ontvertrouw' : 'vertrouw';

    knop.disabled = true;

    fetch('extensie_bestand_actie.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'site_id=' + encodeURIComponent(SITE_ID)
            + '&groep_sleutel=' + encodeURIComponent(groepSleutel)
            + '&relatief_pad=' + encodeURIComponent(pad)
            + '&hash=' + encodeURIComponent(hash)
            + '&actie=' + actie
            + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
    })
    .then(r => r.json())
    .then(data => {
        knop.disabled = false;

        if (!data.ok) {
            alert('Opslaan is mislukt, probeer het opnieuw.');
            return;
        }

        // Pagina verversen in plaats van alleen de rij te laten
        // verdwijnen: dat zorgt er meteen voor dat de twee vergelijkings-
        // viewers ook weg zijn (die tonen anders nog steeds de inhoud van
        // een bestand dat niet meer als "actief" geselecteerd is, wat
        // verwarrend oogt), én dat de rij meteen in de juiste tabel
        // (actief/vertrouwd) terechtkomt zonder de eerdere fade-out-hack.
        location.reload();
    })
    .catch(() => {
        knop.disabled = false;
        alert('Opslaan is mislukt, controleer de verbinding.');
    });
}

/**
 * Vertrouwt (of ontvertrouwt) in één keer alle bestanden van een
 * samengevoegde bulk-cluster (zie groepeerBulkAfwijkingen() /
 * renderBulkClusterRij() in beveiliging.php) - scheelt bij tientallen
 * bestanden per cluster het één-voor-één aanklikken van de losse
 * "Vertrouwen"-knoppen. Verwerkt de bestanden na elkaar (niet
 * parallel), naar hetzelfde patroon als bulkVertrouwen()/bulkActie()
 * hierboven, en herlaadt de pagina pas als alles gelukt is - zelfde
 * reden als bij wisselExtensieVertrouwen(): de vergelijkingsviewers en
 * de juiste tabel/telling moeten kloppen.
 */
function vertrouwCluster(knop) {
    const items = JSON.parse(knop.dataset.items);
    const groepSleutel = knop.dataset.groepSleutel;
    const actie = knop.dataset.actie;
    const statusSpan = knop.parentElement.querySelector('.cluster-vertrouwen-status');

    if (items.length === 0) {
        return;
    }

    const bevestiging = actie === 'vertrouw'
        ? items.length + ' bestanden in deze groep in één keer vertrouwen?'
        : items.length + ' bestanden in deze groep niet meer vertrouwen?';
    if (!confirm(bevestiging)) {
        return;
    }

    knop.disabled = true;
    let huidig = 0;
    let aantalMislukt = 0;

    const volgende = () => {
        if (huidig >= items.length) {
            if (aantalMislukt > 0) {
                alert(aantalMislukt + ' van de ' + items.length + ' bestand(en) konden niet worden bijgewerkt. Probeer het eventueel per bestand opnieuw.');
                knop.disabled = false;
                if (statusSpan) {
                    statusSpan.textContent = '';
                }
                return;
            }
            // Alles gelukt: pas hier herladen (zelfde reden als bij de
            // losse knop) in plaats van na elk los bestand.
            location.reload();
            return;
        }

        const item = items[huidig];
        if (statusSpan) {
            statusSpan.textContent = '(' + (huidig + 1) + '/' + items.length + ' verwerkt)';
        }

        fetch('extensie_bestand_actie.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'site_id=' + encodeURIComponent(SITE_ID)
                + '&groep_sleutel=' + encodeURIComponent(groepSleutel)
                + '&relatief_pad=' + encodeURIComponent(item.pad)
                + '&hash=' + encodeURIComponent(item.hash)
                + '&actie=' + actie
                + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
        })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) {
                    aantalMislukt++;
                }
            })
            .catch(() => { aantalMislukt++; })
            .finally(() => {
                huidig++;
                volgende();
            });
    };
    volgende();
}
</script>

<?php include 'terug_naar_boven.php'; ?>
</body>
</html>
