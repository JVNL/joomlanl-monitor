<?php
require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: login.php");
    exit;
}
// Toont live opgehaalde gegevens - nooit uit een cache laten komen (zie ook
// index.php en de "Geleerde lessen" over verouderde pagina's).
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once 'config.php';
require_once 'versie.php';
require_once 'instellingen_functies.php';

// hosting_overzicht.php
//
// Overzicht per site: bij welke hostingpartij de site staat, op welke server
// die draait en wat het IP-adres daarvan is. Bereikbaar via de knop
// "Hostingoverzicht" in de samenvattingsbalk van index.php. De gegevens
// worden bij het openen van deze pagina live opgezocht (DNS, reverse DNS en
// RIPEstat - zie hosting_functies.php), per site via hosting_info.php, en
// niet in de database bewaard: zo zijn ze altijd actueel, ook direct na een
// verhuizing naar een andere hostingpartij.

$programmaNaam = trim(haalInstelling($pdo, 'email_afzendernaam', '')) ?: 'Mijn Websites Monitor';

$categorie = ($_GET['categorie'] ?? 'eigen') === 'anderen' ? 'anderen' : 'eigen';

$stmt = $pdo->prepare("SELECT id, domein, ftp_host, favicon_url FROM sites WHERE categorie = ? ORDER BY domein ASC");
$stmt->execute([$categorie]);
$sites = $stmt->fetchAll(PDO::FETCH_ASSOC);

$aantalPerCategorie = $pdo->query("SELECT categorie, COUNT(*) AS aantal FROM sites GROUP BY categorie")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$aantalEigen   = (int) ($aantalPerCategorie['eigen'] ?? 0);
$aantalAnderen = (int) ($aantalPerCategorie['anderen'] ?? 0);
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
<title>Hostingoverzicht - <?php echo htmlspecialchars($programmaNaam); ?></title>
<style>

body {
    margin: 20px;
    font-size: 13px;
    font-family: Arial, sans-serif;
}

header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    gap: 10px;
    flex-wrap: wrap;
}

h1 {
    margin: 0 0 5px 0;
}

.knop {
    display: inline-block;
    padding: 8px 14px;
    background: #333;
    color: white;
    text-decoration: none;
    border: none;
    border-radius: 4px;
    font-size: 13px;
    white-space: nowrap;
    cursor: pointer;
}

.knop:hover:not(:disabled) {
    background: #555;
}

.knop:disabled {
    background: #999;
    cursor: not-allowed;
}

.categorie-schakelaar {
    display: flex;
    gap: 8px;
    margin-bottom: 15px;
    flex-wrap: wrap;
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

.uitleg {
    margin-bottom: 15px;
    padding: 12px 15px;
    border: 1px solid var(--thema-rand);
    background: var(--thema-zebra);
    border-radius: 6px;
    line-height: 1.5;
}

.werkbalk {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 10px;
}

#voortgang-tekst {
    color: var(--thema-uitleg-tekst);
}

.samenvatting-hosting {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}

.hosting-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 12px;
    background: var(--thema-badge-bg);
    color: var(--thema-tekst);
    border: 1px solid var(--thema-rand);
    font-size: 14px;
    cursor: pointer;
}

.hosting-chip.actief {
    border-color: var(--thema-groen);
    font-weight: bold;
}

.hosting-chip .aantal {
    font-weight: bold;
}

table {
    border-collapse: collapse;
    width: 100%;
}

th {
    background: var(--thema-kop-bg);
    color: var(--thema-kop-tekst);
    padding: 6px 4px;
    text-align: left;
    font-size: 12px;
    cursor: pointer;
    user-select: none;
    white-space: nowrap;
}

th .pijl {
    opacity: 0.8;
}

/* Filterpijltje in de kolomkop (Server-kolom) + het uitklapmenu. */
.filter-knop {
    display: inline-block;
    margin-left: 4px;
    padding: 0 5px;
    border-radius: 3px;
    border: 1px solid transparent;
    cursor: pointer;
    font-size: 12px;
}

.filter-knop:hover {
    border-color: var(--thema-kop-tekst);
}

.filter-knop.actief {
    background: #f4c542;
    color: #111;
}

.filter-menu {
    display: none;
    position: absolute;
    z-index: 1000;
    min-width: 260px;
    max-width: 420px;
    padding: 10px;
    background: var(--thema-kader-bg);
    color: var(--thema-tekst);
    border: 1px solid var(--thema-rand);
    border-radius: 6px;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.25);
    font-weight: normal;
    cursor: default;
    white-space: normal;
}

.filter-menu input[type="text"] {
    width: 100%;
    box-sizing: border-box;
    padding: 5px 7px;
    margin-bottom: 8px;
}

.filter-lijst {
    max-height: 300px;
    overflow-y: auto;
    margin-bottom: 8px;
}

.filter-lijst label {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 2px 0;
    font-weight: normal;
    cursor: pointer;
}

.filter-lijst .aantal {
    margin-left: auto;
    color: var(--thema-uitleg-tekst);
}

.filter-menu-knoppen {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.filter-menu-knoppen .knop {
    padding: 5px 10px;
}

td {
    border: 1px solid var(--thema-rand);
    padding: 5px 4px;
    font-size: 12px;
    vertical-align: top;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

/* Zelfde lettergrootte als de monitorpagina (14px, zie de uniforme
   lettergrootte in responsive_stijlen.php) - bijkomende regels vallen op
   door een gedempte kleur, niet door een kleiner lettertype. */
.klein {
    color: var(--thema-uitleg-tekst);
}

.onbekend {
    color: var(--thema-uitleg-tekst);
    font-style: italic;
}

.fout-tekst {
    color: var(--thema-rood);
}

.waarschuwing-tekst {
    color: var(--thema-geel);
}

.cdn-badge {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: bold;
    background: var(--thema-badge-bg);
    color: var(--thema-badge-tekst);
    border: 1px solid var(--thema-rand);
    margin-left: 4px;
}

.bezig {
    display: inline-block;
    animation: bezig-draaien 1.2s linear infinite;
}

@keyframes bezig-draaien {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

</style>
<?php include 'responsive_stijlen.php'; ?>
</head>
<body>

<header>
    <div>
        <h1>🖥️ Hostingoverzicht</h1>
        <div class="klein">Bij welke hostingpartij, op welke server en op welk IP-adres elke website draait.</div>
    </div>
    <div style="display: flex; gap: 8px;">
        <button type="button" class="knop" onclick="history.back()" style="padding: 8px 12px;" title="Eén stap terug">←</button>
        <a class="knop" href="index.php?categorie=<?php echo urlencode($categorie); ?>">Terug naar monitor</a>
    </div>
</header>

<div class="categorie-schakelaar">
    <a href="?categorie=eigen" class="categorie-tab<?php echo $categorie === 'eigen' ? ' actief' : ''; ?>">
        🏠 Eigen websites <span class="categorie-aantal"><?php echo $aantalEigen; ?></span>
    </a>
    <a href="?categorie=anderen" class="categorie-tab<?php echo $categorie === 'anderen' ? ' actief' : ''; ?>">
        👤 Websites van anderen <span class="categorie-aantal"><?php echo $aantalAnderen; ?></span>
    </a>
</div>

<div class="uitleg">
    De gegevens worden bij het openen van deze pagina <strong>live opgezocht</strong> en niet bewaard: het IP-adres via de
    DNS van de domeinnaam (en van de FTP-server, als die is ingevuld), de servernaam via de <em>reverse DNS</em> van dat
    IP-adres, en de hostingpartij via die servernaam, de partij aan wie het IP-blok is toegewezen, de FTP-server of de
    eigenaar van het netwerk (openbare gegevens van RIPE). Draait een website achter Cloudflare of een vergelijkbare
    dienst, dan is het IP-adres van de domeinnaam niet dat van de echte server - die wordt dan via de FTP-server bepaald.
    <?php echo hulpIcoon('hostingoverzicht', 'Hoe de hostingpartij, server en het IP-adres worden bepaald, en wat je kunt doen als iets onbekend blijft.'); ?>
</div>

<?php if (empty($sites)): ?>
    <p>Er zijn nog geen sites in deze categorie.</p>
<?php else: ?>

<div class="werkbalk">
    <button type="button" class="knop" id="ververs-knop" onclick="haalAllesOp()">↻ Opnieuw ophalen</button>
    <button type="button" class="knop" id="csv-knop" onclick="downloadCsv()" disabled>⬇️ CSV downloaden</button>
    <span id="voortgang-tekst"></span>
</div>

<div class="samenvatting-hosting" id="samenvatting-hosting"></div>

<div class="tabel-scroll-kader" style="overflow-x: auto;">
<table class="responsive-tabel" id="hosting-tabel">
<thead>
<tr>
    <th style="width: 22%;" data-sorteer="domein">Domein <span class="pijl">▲</span><?php echo hulpIcoon('hosting-domein', 'Het domein zoals bij de site ingesteld. Het icoontje opent de website, de naam gaat naar de site-instellingen (o.a. de FTP-gegevens).'); ?></th>
    <th style="width: 16%;" data-sorteer="hostingpartij">Hostingpartij <span class="pijl"></span><?php echo hulpIcoon('hosting-hostingpartij', 'De partij waar de website draait - herkend aan de servernaam, aan wie het IP-blok is toegewezen, de FTP-server of de eigenaar van het netwerk (RIPE). Beweeg over de naam om te zien waaraan die herkend is.'); ?></th>
    <th style="width: 22%;" data-sorteer="server">Server <span class="pijl"></span><span class="filter-knop" id="filter-knop-server" title="Filteren op server" onclick="openServerFilter(event)">▾</span><?php echo hulpIcoon('hosting-server', 'De naam van de server waar de website op draait (reverse DNS van het IP-adres), met daaronder het netwerk (AS-nummer). Met het pijltje ▾ filter je op één of meer servers.'); ?></th>
    <th style="width: 14%;" data-sorteer="ip">IP-adres <span class="pijl"></span><?php echo hulpIcoon('hosting-ip', 'Het IP-adres van de server. Achter Cloudflare e.d. is dat het IP-adres van de FTP-server; het IP-adres van de domeinnaam staat er dan klein onder.'); ?></th>
    <th style="width: 26%;" data-sorteer="toelichting">Toelichting <span class="pijl"></span><?php echo hulpIcoon('hosting-toelichting', 'Bijzonderheden per site, zoals Cloudflare, een FTP-server die naar een andere machine wijst, of een opzoeking die mislukte. Daaronder de FTP-server en de nameservers.'); ?></th>
</tr>
</thead>
<tbody>
<?php foreach ($sites as $site):
    $faviconUrl = !empty($site['favicon_url']) ? $site['favicon_url'] : 'https://www.joomla.org/favicon.ico';
?>
<tr data-site-id="<?php echo (int) $site['id']; ?>" data-domein="<?php echo htmlspecialchars(strtolower($site['domein'] ?? '')); ?>">
    <td data-label="Domein">
        <a href="https://<?php echo htmlspecialchars($site['domein'] ?? ''); ?>/" target="_blank" rel="noopener" title="Website openen">
            <img src="<?php echo htmlspecialchars($faviconUrl); ?>" alt="" width="20" height="20" style="vertical-align: middle; margin-right: 6px;" onerror="this.onerror=null; this.src='https://www.joomla.org/favicon.ico';">
        </a>
        <a class="domein-link" href="site_instellingen.php?site_id=<?php echo (int) $site['id']; ?>" title="Site-instellingen (o.a. FTP-gegevens)"><?php echo htmlspecialchars($site['domein'] ?? ''); ?></a>
    </td>
    <td data-label="Hostingpartij" class="cel-hosting"><span class="bezig">⏳</span></td>
    <td data-label="Server" class="cel-server"></td>
    <td data-label="IP-adres" class="cel-ip"></td>
    <td data-label="Toelichting" class="cel-toelichting"></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="filter-menu" id="filter-menu-server" onclick="event.stopPropagation()">
    <input type="text" id="filter-zoek-server" placeholder="Zoek server..." oninput="vulServerFilterLijst()">
    <div class="filter-lijst" id="filter-lijst-server"></div>
    <div class="filter-menu-knoppen">
        <button type="button" class="knop" onclick="zetAlleServerVinkjes(true)">Alles</button>
        <button type="button" class="knop" onclick="zetAlleServerVinkjes(false)">Niets</button>
        <button type="button" class="knop" onclick="pasServerFilterToe()">Toepassen</button>
        <button type="button" class="knop" onclick="wisServerFilter()">Filter wissen</button>
    </div>
</div>

<?php endif; ?>

<script>
// Maximaal zoveel sites tegelijk opvragen - genoeg om snel klaar te zijn,
// zonder de eigen server (of RIPEstat) met tientallen verzoeken tegelijk
// te bestoken.
const GELIJKTIJDIG = 4;

// Opgehaalde gegevens per site-id, voor sorteren, filteren en de CSV.
const gegevens = {};
let actieveFilter = null;
// Server-filter via het pijltje in de kolomkop: null = geen filter, anders
// een Set met de servernamen die getoond moeten worden. Werkt samen met het
// hostingpartij-filter (de chips boven de tabel): een rij moet aan beide
// voldoen.
let serverFilter = null;
let sorteerKolom = 'domein';
let sorteerOplopend = true;

function escapeHtml(tekst) {
    return String(tekst ?? '').replace(/[&<>"']/g, t => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[t]));
}

function alleRijen() {
    return Array.from(document.querySelectorAll('#hosting-tabel tbody tr'));
}

function vulRij(rij, info) {
    const celHosting = rij.querySelector('.cel-hosting');
    const celServer = rij.querySelector('.cel-server');
    const celIp = rij.querySelector('.cel-ip');
    const celToelichting = rij.querySelector('.cel-toelichting');

    if (!info.ok) {
        celHosting.innerHTML = '<span class="fout-tekst">Fout</span>';
        celServer.innerHTML = '';
        celIp.innerHTML = '';
        celToelichting.innerHTML = '<span class="fout-tekst">' + escapeHtml(info.fout || 'Onbekende fout') + '</span>';
        return;
    }

    // Hostingpartij
    if (info.hostingpartij) {
        const titel = 'Herkend via ' + (info.herkend_via || '?')
            + (info.ip_toewijzing ? '\nIP-blok toegewezen aan: ' + info.ip_toewijzing : '')
            + (info.netwerk_eigenaar ? '\nNetwerk: AS' + info.netwerk_asn + ' ' + info.netwerk_eigenaar : '');
        celHosting.innerHTML = '<strong title="' + escapeHtml(titel) + '">' + escapeHtml(info.hostingpartij) + '</strong>'
            + (info.cdn ? '<span class="cdn-badge" title="Website draait achter ' + escapeHtml(info.cdn) + '">via ' + escapeHtml(info.cdn) + '</span>' : '');
    } else if (info.cdn) {
        celHosting.innerHTML = '<span class="onbekend">Verborgen</span><span class="cdn-badge">achter ' + escapeHtml(info.cdn) + '</span>';
    } else {
        celHosting.innerHTML = '<span class="onbekend">Onbekend</span>';
    }

    // Server
    if (info.server_naam) {
        celServer.innerHTML = '<span>' + escapeHtml(info.server_naam) + '</span>';
    } else if (info.server_ip) {
        celServer.innerHTML = '<span class="onbekend" title="Voor dit IP-adres is geen reverse DNS (PTR-record) ingesteld">Geen servernaam bekend</span>';
    } else {
        celServer.innerHTML = '<span class="onbekend">-</span>';
    }
    if (info.netwerk_asn) {
        celServer.innerHTML += '<div class="klein" title="' + escapeHtml(info.netwerk_eigenaar || '') + '">Netwerk: AS' + escapeHtml(info.netwerk_asn) + '</div>';
    }

    // IP-adres
    let ipHtml = '';
    if (info.server_ip) {
        ipHtml = '<span>' + escapeHtml(info.server_ip) + '</span>';
    } else {
        ipHtml = '<span class="onbekend">-</span>';
    }
    if (info.cdn && info.website_ips.length) {
        ipHtml += '<div class="klein">Website (' + escapeHtml(info.cdn) + '): <span>' + escapeHtml(info.website_ips.join(', ')) + '</span></div>';
    }
    if (!info.cdn && info.ftp_ips.length && info.website_ips.length && !info.ftp_ips.some(ip => info.website_ips.includes(ip))) {
        ipHtml += '<div class="klein waarschuwing-tekst">FTP: <span>' + escapeHtml(info.ftp_ips.join(', ')) + '</span></div>';
    }
    if (info.website_ipv6.length) {
        ipHtml += '<div class="klein" title="IPv6-adres(sen) van de domeinnaam">IPv6: <span>' + escapeHtml(info.website_ipv6.join(', ')) + '</span></div>';
    }
    celIp.innerHTML = ipHtml;

    // Toelichting
    let toelichting = '';
    if (info.opmerkingen.length) {
        toelichting += info.opmerkingen.map(o => '<div>' + escapeHtml(o) + '</div>').join('');
    }
    if (info.ftp_host) {
        toelichting += '<div class="klein">FTP-server: <span>' + escapeHtml(info.ftp_host) + '</span></div>';
    } else {
        toelichting += '<div class="klein">Geen FTP-gegevens ingevuld.</div>';
    }
    if (info.nameservers.length) {
        toelichting += '<div class="klein">Nameservers: <span>' + escapeHtml(info.nameservers.join(', ')) + '</span></div>';
    }
    celToelichting.innerHTML = toelichting;
}

function haalSiteOp(rij) {
    const siteId = rij.dataset.siteId;
    rij.querySelector('.cel-hosting').innerHTML = '<span class="bezig">⏳</span>';
    rij.querySelector('.cel-server').innerHTML = '';
    rij.querySelector('.cel-ip').innerHTML = '';
    rij.querySelector('.cel-toelichting').innerHTML = '';

    return fetch('hosting_info.php?site_id=' + encodeURIComponent(siteId), { cache: 'no-store' })
        .then(r => r.json().catch(() => ({ ok: false, fout: 'Ongeldig antwoord van de server (HTTP ' + r.status + ')' })))
        .catch(err => ({ ok: false, fout: 'Verbinding mislukt: ' + err.message }))
        .then(info => {
            gegevens[siteId] = info;
            vulRij(rij, info);
        });
}

function haalAllesOp() {
    const rijen = alleRijen();
    const knop = document.getElementById('ververs-knop');
    const csvKnop = document.getElementById('csv-knop');
    const voortgang = document.getElementById('voortgang-tekst');

    knop.disabled = true;
    csvKnop.disabled = true;
    actieveFilter = null;
    serverFilter = null;
    sluitServerFilter();
    document.getElementById('filter-knop-server').classList.remove('actief');
    document.getElementById('samenvatting-hosting').innerHTML = '';
    rijen.forEach(r => r.style.display = '');

    let klaar = 0;
    let volgende = 0;
    voortgang.textContent = 'Bezig met ophalen... 0 van ' + rijen.length;

    return new Promise(resolve => {
        function start() {
            if (volgende >= rijen.length) {
                return;
            }
            const rij = rijen[volgende++];
            haalSiteOp(rij).then(() => {
                klaar++;
                voortgang.textContent = 'Bezig met ophalen... ' + klaar + ' van ' + rijen.length;
                if (klaar === rijen.length) {
                    resolve();
                } else {
                    start();
                }
            });
        }
        for (let i = 0; i < Math.min(GELIJKTIJDIG, rijen.length); i++) {
            start();
        }
    }).then(() => {
        const nu = new Date();
        voortgang.textContent = '✅ Opgehaald op ' + nu.toLocaleDateString('nl-NL', { day: '2-digit', month: '2-digit', year: 'numeric' })
            + ' om ' + nu.toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' });
        knop.disabled = false;
        csvKnop.disabled = false;
        toonSamenvatting();
        sorteer(sorteerKolom, sorteerOplopend);
    });
}

function hostingNaam(info) {
    if (!info || !info.ok) {
        return 'Fout';
    }
    if (info.hostingpartij) {
        return info.hostingpartij;
    }
    return info.cdn ? 'Verborgen (' + info.cdn + ')' : 'Onbekend';
}

// Kleine telling per hostingpartij boven de tabel - klikbaar als filter.
function toonSamenvatting() {
    const tellingen = {};
    alleRijen().forEach(rij => {
        const naam = hostingNaam(gegevens[rij.dataset.siteId]);
        tellingen[naam] = (tellingen[naam] || 0) + 1;
    });

    const namen = Object.keys(tellingen).sort((a, b) => tellingen[b] - tellingen[a] || a.localeCompare(b, 'nl'));
    const kader = document.getElementById('samenvatting-hosting');
    kader.innerHTML = '';
    namen.forEach(naam => {
        const chip = document.createElement('span');
        chip.className = 'hosting-chip';
        chip.title = 'Klik om alleen de sites bij ' + naam + ' te tonen (nogmaals klikken toont alles weer)';
        chip.innerHTML = escapeHtml(naam) + ' <span class="aantal">' + tellingen[naam] + '</span>';
        chip.onclick = () => filterOp(naam, chip);
        kader.appendChild(chip);
    });
}

function filterOp(naam, chip) {
    actieveFilter = actieveFilter === naam ? null : naam;
    document.querySelectorAll('.hosting-chip').forEach(c => c.classList.remove('actief'));
    if (actieveFilter !== null) {
        chip.classList.add('actief');
    }
    pasFiltersToe();
}

const GEEN_SERVERNAAM = '(geen servernaam)';

function serverNaam(info) {
    return (info && info.ok && info.server_naam) ? info.server_naam : GEEN_SERVERNAAM;
}

// Toont/verbergt de rijen op basis van beide filters samen.
function pasFiltersToe() {
    alleRijen().forEach(rij => {
        const info = gegevens[rij.dataset.siteId];
        const pastHosting = actieveFilter === null || hostingNaam(info) === actieveFilter;
        const pastServer = serverFilter === null || serverFilter.has(serverNaam(info));
        rij.style.display = (pastHosting && pastServer) ? '' : 'none';
    });
    document.getElementById('filter-knop-server').classList.toggle('actief', serverFilter !== null);
}

// Het uitklapmenu onthoudt de vinkjes tijdens het openstaan (ook als de
// lijst door het zoekveld wordt ingekort), en past ze pas toe bij
// "Toepassen".
let serverVinkjes = new Set();

function openServerFilter(evt) {
    evt.stopPropagation();
    const menu = document.getElementById('filter-menu-server');
    if (menu.style.display === 'block') {
        sluitServerFilter();
        return;
    }

    const alleServers = Array.from(new Set(alleRijen().map(r => serverNaam(gegevens[r.dataset.siteId]))));
    serverVinkjes = serverFilter === null ? new Set(alleServers) : new Set(serverFilter);
    document.getElementById('filter-zoek-server').value = '';
    vulServerFilterLijst();

    const kop = evt.currentTarget.closest('th').getBoundingClientRect();
    menu.style.display = 'block';
    menu.style.top = (window.scrollY + kop.bottom + 2) + 'px';
    const links = Math.min(window.scrollX + kop.left, window.scrollX + document.documentElement.clientWidth - menu.offsetWidth - 10);
    menu.style.left = Math.max(10, links) + 'px';
    document.getElementById('filter-zoek-server').focus();
}

function sluitServerFilter() {
    const menu = document.getElementById('filter-menu-server');
    if (menu) {
        menu.style.display = 'none';
    }
}

function vulServerFilterLijst() {
    const zoek = document.getElementById('filter-zoek-server').value.trim().toLowerCase();
    const tellingen = {};
    alleRijen().forEach(r => {
        const naam = serverNaam(gegevens[r.dataset.siteId]);
        tellingen[naam] = (tellingen[naam] || 0) + 1;
    });
    const namen = Object.keys(tellingen)
        .filter(n => zoek === '' || n.toLowerCase().includes(zoek))
        .sort((a, b) => (a === GEEN_SERVERNAAM) - (b === GEEN_SERVERNAAM) || a.localeCompare(b, 'nl'));

    const lijst = document.getElementById('filter-lijst-server');
    lijst.innerHTML = '';
    if (namen.length === 0) {
        lijst.innerHTML = '<div class="klein">Geen servers gevonden.</div>';
        return;
    }
    namen.forEach(naam => {
        const label = document.createElement('label');
        const vinkje = document.createElement('input');
        vinkje.type = 'checkbox';
        vinkje.checked = serverVinkjes.has(naam);
        vinkje.onchange = () => vinkje.checked ? serverVinkjes.add(naam) : serverVinkjes.delete(naam);
        label.appendChild(vinkje);
        label.insertAdjacentHTML('beforeend', '<span>' + escapeHtml(naam) + '</span><span class="aantal">' + tellingen[naam] + '</span>');
        lijst.appendChild(label);
    });
}

function zetAlleServerVinkjes(aan) {
    document.querySelectorAll('#filter-lijst-server input[type="checkbox"]').forEach(v => {
        v.checked = aan;
        v.onchange();
    });
}

function pasServerFilterToe() {
    const alleServers = new Set(alleRijen().map(r => serverNaam(gegevens[r.dataset.siteId])));
    const allesAangevinkt = Array.from(alleServers).every(n => serverVinkjes.has(n));
    serverFilter = allesAangevinkt ? null : new Set(serverVinkjes);
    sluitServerFilter();
    pasFiltersToe();
}

function wisServerFilter() {
    serverFilter = null;
    sluitServerFilter();
    pasFiltersToe();
}

document.addEventListener('click', sluitServerFilter);
document.addEventListener('keydown', e => { if (e.key === 'Escape') sluitServerFilter(); });

function sorteerWaarde(rij, kolom) {
    const info = gegevens[rij.dataset.siteId];
    switch (kolom) {
        case 'hostingpartij':
            return hostingNaam(info).toLowerCase();
        case 'server':
            return (info && info.server_naam) || '￿';
        case 'ip':
            // IPv4 numeriek sorteerbaar maken (10.0.0.2 vóór 10.0.0.10).
            if (info && info.server_ip && /^\d+\.\d+\.\d+\.\d+$/.test(info.server_ip)) {
                return info.server_ip.split('.').map(d => d.padStart(3, '0')).join('.');
            }
            return '￿';
        case 'toelichting':
            return (info && info.opmerkingen && info.opmerkingen.length) ? '0' + info.opmerkingen.join(' ') : '1';
        default:
            return rij.dataset.domein;
    }
}

function sorteer(kolom, oplopend) {
    sorteerKolom = kolom;
    sorteerOplopend = oplopend;
    const tbody = document.querySelector('#hosting-tabel tbody');
    const rijen = alleRijen();
    rijen.sort((a, b) => {
        const vergelijking = sorteerWaarde(a, kolom).localeCompare(sorteerWaarde(b, kolom), 'nl')
            || a.dataset.domein.localeCompare(b.dataset.domein, 'nl');
        return oplopend ? vergelijking : -vergelijking;
    });
    rijen.forEach(r => tbody.appendChild(r));

    document.querySelectorAll('#hosting-tabel th').forEach(th => {
        th.querySelector('.pijl').textContent = th.dataset.sorteer === kolom ? (oplopend ? '▲' : '▼') : '';
    });
}

function downloadCsv() {
    const kop = ['Domein', 'Hostingpartij', 'Herkend via', 'Server', 'IP-adres', 'Website-IP', 'FTP-server', 'FTP-IP', 'IP-toewijzing', 'Netwerk (AS)', 'Netwerkeigenaar', 'CDN', 'Nameservers', 'Toelichting'];
    const regels = [kop];
    alleRijen().forEach(rij => {
        const info = gegevens[rij.dataset.siteId] || {};
        regels.push([
            rij.dataset.domein,
            hostingNaam(info),
            info.herkend_via || '',
            info.server_naam || '',
            info.server_ip || '',
            (info.website_ips || []).join(' '),
            info.ftp_host || '',
            (info.ftp_ips || []).join(' '),
            info.ip_toewijzing || '',
            info.netwerk_asn ? 'AS' + info.netwerk_asn : '',
            info.netwerk_eigenaar || '',
            info.cdn || '',
            (info.nameservers || []).join(' '),
            (info.opmerkingen || []).join(' ') || (info.ok === false ? info.fout : ''),
        ]);
    });

    // Puntkomma als scheidingsteken en een BOM vooraan: dan opent Excel
    // (Nederlandse instellingen) het bestand meteen correct in kolommen.
    const csv = '﻿' + regels.map(r => r.map(v => '"' + String(v ?? '').replace(/"/g, '""') + '"').join(';')).join('\r\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'hostingoverzicht-<?php echo $categorie; ?>-' + new Date().toISOString().slice(0, 10) + '.csv';
    document.body.appendChild(link);
    link.click();
    setTimeout(() => { URL.revokeObjectURL(link.href); link.remove(); }, 1000);
}

document.querySelectorAll('#hosting-tabel th').forEach(th => {
    th.title = 'Klik om te sorteren';
    th.addEventListener('click', evt => {
        // Een klik op het hulp-vraagtekentje of het filterpijltje is geen
        // sorteeropdracht.
        if (evt.target.closest('.hulp-icoon, .filter-knop, .hulp-popup')) {
            return;
        }
        const kolom = th.dataset.sorteer;
        sorteer(kolom, kolom === sorteerKolom ? !sorteerOplopend : true);
    });
});

if (document.getElementById('hosting-tabel')) {
    haalAllesOp();
}
</script>

<div style="margin-top: 20px; color: #999; font-size: 11px;"><?php echo htmlspecialchars($programmaNaam); ?> v<?php echo htmlspecialchars(MONITOR_VERSIE); ?></div>

<?php include 'terug_naar_boven.php'; ?>
</body>
</html>
