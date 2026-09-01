<?php
// Dit script controleert alle sites en slaat de uitkomst op in de database.
// Dit bestand wordt NIET door een bezoeker geopend, maar automatisch
// op de achtergrond gedraaid (via een cronjob).
//
// BELANGRIJK: alle sites worden PARALLEL gecontroleerd (via curl_multi),
// niet na elkaar. Met langzaam reagerende hostingpartijen (bijv. Strato,
// Antagonist) kan elke afzonderlijke check al een paar seconden duren -
// na elkaar afgehandeld loopt de totale tijd bij veel sites dan op tot
// boven de time-outgrens van de server/proxy (HTTP 504). Het
// SSL-certificaat wordt hierbij in hetzelfde verzoek meegenomen (via
// CURLOPT_CERTINFO), zodat er ook geen los, blokkerend SSL-verzoek meer
// nodig is naast de HTTP-check.

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'endpoint_beveiliging.php';
require_once 'instellingen_functies.php';

// We doen net alsof we een gewone browser zijn (Chrome), zodat
// firewalls/security-plugins ons niet als "bot" blokkeren.
const CHECK_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
    . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

/**
 * Bepaalt het favicon-adres uit de al opgehaalde HTML van de homepage (dus
 * zonder extra netwerkverzoek): via de <link rel="icon">/<link rel="shortcut
 * icon">-tag, en anders via het gebruikelijke standaardpad /favicon.ico.
 *
 * Bewust op de MONITOR uitgevoerd (hier, met de al opgehaalde HTML) in
 * plaats van dat de site dit bij zichzelf probeert op te halen: zo'n
 * "self-loopback"-verzoek (een server die zijn eigen publieke domeinnaam
 * benadert) wordt door nogal wat hostingpartijen geblokkeerd/vertraagd,
 * wat er eerder toe leidde dat vrijwel alle sites op de terugval (het
 * Joomla-icoon) uitkwamen, ook als ze prima een eigen favicon hadden.
 */
function bepaalFaviconUrl(?string $html, array $site): string
{
    $standaardPad = bepaalSiteUrl($site, 'favicon.ico');

    if ($html === null || $html === false || $html === '') {
        return $standaardPad;
    }

    if (!preg_match('/<link[^>]+rel=["\'](?:shortcut )?icon["\'][^>]*>/i', $html, $tagMatch)) {
        return $standaardPad;
    }

    if (!preg_match('/href=["\']([^"\']+)["\']/i', $tagMatch[0], $hrefMatch)) {
        return $standaardPad;
    }

    $favicon = trim($hrefMatch[1]);
    if ($favicon === '') {
        return $standaardPad;
    }

    // Relatieve URL's omzetten naar absolute URL's.
    if (strpos($favicon, '//') === 0) {
        return "https:$favicon";
    }
    if (preg_match('#^https?://#i', $favicon)) {
        return $favicon;
    }
    // Een pad dat met een ENKELE "/" begint, is root-relatief ten opzichte
    // van het KALE domein - dat is de gewone, standaard betekenis van zo'n
    // pad in HTML/browsers, ongeacht in welke (sub)map de pagina zelf staat.
    // Bij een site met een URL-submap genereert Joomla zo'n root-relatief
    // pad zelf al MÉT de submap erin verwerkt (bijv.
    // "/clabbers/templates/mijnsjabloon/favicon.ico" voor een site op
    // "https://domein.nl/clabbers/"). Zou dit hier alsnog via
    // bepaalSiteUrl() lopen (die de URL-submap er altijd los nog eens voor
    // plakt), dan komt die submap er dubbel in te staan (bijv.
    // ".../clabbers/clabbers/...") - een niet-bestaande URL, dus een 404,
    // dus viel de favicon-detectie terug op het standaard Joomla-icoontje.
    // Concreet aangetroffen bij familiedebest.nl/clabbers/ (augustus 2026).
    if (strpos($favicon, '/') === 0) {
        $domein = trim($site['domein'] ?? '', '/');
        return 'https://' . $domein . '/' . ltrim($favicon, '/');
    }
    // Een pad ZONDER leidende "/" is wél relatief t.o.v. de site zelf
    // (dus inclusief een eventuele URL-submap) - bepaalSiteUrl() plakt
    // domein + URL-submap + dit pad correct aan elkaar.
    return bepaalSiteUrl($site, $favicon);
}

/**
 * Bepaalt de website-status op basis van de al opgehaalde HTML/foutcode
 * (de curl-aanroep zelf gebeurt parallel, elders).
 */
function bepaalWebsiteStatus(?string $html, int $curlErrno, int $httpCode): array
{
    // Alleen als cURL echt geen verbinding kon maken (geen reactie van de
    // server, DNS-fout, timeout) markeren we de site als offline.
    if ($curlErrno !== 0 || $httpCode === 0) {
        return [
            'status' => '🔴 Offline',
            'class' => 'rood'
        ];
    }

    if ($html !== null && $html !== false && $html !== '') {
        $verdachteWoorden = ['casino', 'betting', 'viagra', 'cialis', 'crypto', 'poker'];

        foreach ($verdachteWoorden as $woord) {
            $patroon = '/\b' . preg_quote($woord, '/') . '\b/i';
            if (preg_match($patroon, $html)) {
                return [
                    'status' => '🟠 Verdacht',
                    'class' => 'oranje'
                ];
            }
        }
    }

    // Alleen een schone HTTP 200 telt als "Online" - zonder technische details.
    if ($httpCode == 200) {
        return [
            'status' => '🟢 Online',
            'class' => 'groen'
        ];
    }

    // Alles anders (403, 500, andere foutcodes) is een probleem: tonen als
    // Offline, met de HTTP-code erbij zodat duidelijk is wat er mis is.
    return [
        'status' => "🔴 Offline (HTTP $httpCode)",
        'class' => 'rood'
    ];
}

/**
 * Bepaalt de SSL-verloopstatus uit de certificaatinfo die curl (via
 * CURLOPT_CERTINFO) al heeft meegeleverd bij de HTTP-check zelf - dus
 * zonder een los, tweede verzoek naar de site te hoeven doen.
 */
function bepaalSslStatus(array $certInfo): array
{
    $verloopTekst = $certInfo[0]['Expire date'] ?? null;

    if ($verloopTekst === null) {
        return [null, '-', ''];
    }

    $verloopTijd = strtotime($verloopTekst);
    if ($verloopTijd === false) {
        return [null, '-', ''];
    }

    $sslDatum = date('Y-m-d', $verloopTijd);
    $dagen = floor(($verloopTijd - time()) / 86400);

    if ($dagen < 0) {
        return [$sslDatum, "🔴 VERLOPEN", "ssl-rood"];
    } elseif ($dagen <= 30) {
        return [$sslDatum, "🟠 Let op ($dagen dagen)", "ssl-oranje"];
    } else {
        return [$sslDatum, "🟢 OK ($dagen dagen)", "ssl-groen"];
    }
}

/**
 * Controleert alle meegegeven sites tegelijk (parallel) op website-status
 * én SSL-certificaat, en geeft per site-id de uitkomst terug.
 *
 * @param array $sites elk met minimaal 'id' en 'domein'
 * @return array<int, array{websiteInfo: array, sslDatum: ?string, sslStatus: string, sslClass: string}>
 */
function controleerSitesParallel(array $sites): array
{
    $multiHandle = curl_multi_init();
    $handles = [];

    foreach ($sites as $site) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => bepaalSiteUrl($site),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CERTINFO => true,
            CURLOPT_USERAGENT => CHECK_USER_AGENT,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: nl-NL,nl;q=0.9,en;q=0.8',
            ],
        ]);
        curl_multi_add_handle($multiHandle, $ch);
        $handles[$site['id']] = $ch;
    }

    // Alle verzoeken tegelijk laten lopen totdat ze allemaal klaar zijn.
    $actief = null;
    do {
        $status = curl_multi_exec($multiHandle, $actief);
        if ($actief) {
            curl_multi_select($multiHandle, 1.0);
        }
    } while ($actief && $status === CURLM_OK);

    $resultaten = [];
    foreach ($sites as $site) {
        $ch = $handles[$site['id']];

        $html = curl_multi_getcontent($ch);
        $curlErrno = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $certInfo = curl_getinfo($ch, CURLINFO_CERTINFO) ?: [];

        $websiteInfo = bepaalWebsiteStatus($html, $curlErrno, $httpCode);
        [$sslDatum, $sslStatus, $sslClass] = bepaalSslStatus($certInfo);
        $faviconUrl = bepaalFaviconUrl($html, $site);

        $resultaten[$site['id']] = [
            'websiteInfo' => $websiteInfo,
            'sslDatum' => $sslDatum,
            'sslStatus' => $sslStatus,
            'sslClass' => $sslClass,
            'faviconUrl' => $faviconUrl,
        ];

        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }

    curl_multi_close($multiHandle);

    return $resultaten;
}

// Alle sites ophalen, of - als ?site_id= is meegegeven - alleen die ene site.
$siteId = isset($_GET['site_id']) ? (int) $_GET['site_id'] : 0;
// Zelfde redenering als in start_scan.php: alleen filteren als er
// expliciet een categorie is meegegeven (vanaf de indexpagina) - zonder
// parameter (bijv. de cronjob) blijven alle sites gewoon meegenomen.
$categorie = isset($_GET['categorie']) && $_GET['categorie'] === 'anderen' ? 'anderen' : (isset($_GET['categorie']) ? 'eigen' : null);

if ($siteId > 0) {
    $stmt = $pdo->prepare("SELECT id, domein, url_subpad FROM sites WHERE id = ?");
    $stmt->execute([$siteId]);
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($categorie !== null) {
    $sql = "SELECT id, domein, url_subpad FROM sites WHERE categorie = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$categorie]);
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sql = "SELECT id, domein, url_subpad FROM sites";
    $resultaat = $pdo->query($sql);
    $sites = $resultaat->fetchAll(PDO::FETCH_ASSOC);
}

$updateStmt = $pdo->prepare("
    UPDATE sites
    SET live_website_status = ?,
        live_website_class = ?,
        live_ssl_verloopt = ?,
        live_ssl_status_tekst = ?,
        live_ssl_class = ?,
        laatste_check = NOW(),
        favicon_url = ?
    WHERE id = ?
");

$resultaten = controleerSitesParallel($sites);

$aantalGecontroleerd = 0;

foreach ($sites as $site) {
    $r = $resultaten[$site['id']];

    $updateStmt->execute([
        $r['websiteInfo']['status'],
        $r['websiteInfo']['class'],
        $r['sslDatum'],
        $r['sslStatus'],
        $r['sslClass'],
        $r['faviconUrl'],
        $site['id'],
    ]);

    $aantalGecontroleerd++;
}

// Let op: het versturen van notificatie-e-mails gebeurt niet meer hier,
// maar in het aparte script verstuur_notificatie_email.php - dat combineert
// website/SSL/Joomla/extensies/beveiliging op basis van de e-mailinstellingen
// (Configuratie > E-mail), en wordt als laatste stap aangeroepen na
// "Scan en check sites".

echo date('Y-m-d H:i:s') . " - $aantalGecontroleerd sites gecontroleerd.\n";
