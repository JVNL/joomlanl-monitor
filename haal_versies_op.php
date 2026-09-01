<?php
/**
 * haal_versies_op.php
 *
 * Haalt de Joomla-versie op via het manifest.xml-bestand van elke site,
 * en detecteert daarnaast welke extensies uit de "extensie_catalogus"
 * (tabel) op elke site aanwezig zijn, met welke versie.
 *
 * BEPERKING: dit kan alleen extensies detecteren die in extensie_catalogus
 * staan (sleutel + manifest-pad + optioneel een update-feed). Er bestaat
 * geen manier om zonder in te loggen een volledige, willekeurige lijst van
 * "alles wat geïnstalleerd is" op te halen. Voeg een rij toe aan
 * extensie_catalogus om een extra extensie te laten meedetecteren.
 *
 * Vereisten in de `sites`-tabel:
 *   - domein          : domeinnaam (bijv. yndi.nl)
 *   - admin_pad       : pad naar administrator-map (standaard: administrator)
 *                       Afwijkend bijv. bij sites met een secret word.
 *                       NULL of leeg = sla manifest-check over voor deze site.
 *
 * Gedraaid als achtergrondtaak (handmatig of via cronjob).
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
// Verwerkt alle sites in één keer (Joomla-manifest + extensiecatalogus per
// site) - bij een groeiend aantal sites kan dit de standaard tijds-/
// geheugenlimiet van de hostingpartij benaderen, met een kale HTTP 500 tot
// gevolg in plaats van een nette afronding.
@set_time_limit(180);
@ini_set('memory_limit', '256M');

require_once 'config.php';
require_once 'endpoint_beveiliging.php';
require_once 'versie_vergelijk_functies.php';
require_once 'instellingen_functies.php';

// ─────────────────────────────────────────────────────────────────────────────
// Joomla-manifest (core) - los van de extensiecatalogus, want dit is de CMS
// zelf, geen extensie.
// ─────────────────────────────────────────────────────────────────────────────
const JOOMLA_MANIFEST_PAD      = 'manifests/files/joomla.xml';
const JOOMLA_UPDATE_FEED       = 'https://update.joomla.org/core/list.xml';
// De downloadpagina wordt dynamisch bepaald (per hoofdversie), zie Fase 1
// hieronder - dus geen vaste "joomla6"-constante meer nodig.

// ─────────────────────────────────────────────────────────────────────────────
// Hulpfunctie: haal een URL op via cURL en geef zowel de inhoud als (bij
// falen) de reden terug, zodat fouten in het logbestand zichtbaar worden
// in plaats van stilletjes "onveranderd" te tonen.
// ─────────────────────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────────────
// Haalt meerdere URL's TEGELIJK op (curl_multi), i.p.v. na elkaar. Bij veel
// geconfigureerde update-feeds en/of veel sites kan de gewone, sequentiële
// aanpak (één voor één, elk met een eigen timeout tot 25 seconden) samen
// zo lang duren dat de webserver/proxy vóór deze pagina de verbinding met
// een HTTP 504 afbreekt, ruim vóórdat PHP zelf klaar is. Door alle
// aanvragen gelijktijdig te versturen duurt de totale wachttijd ongeveer
// zo lang als de traagste ENKELE aanvraag, in plaats van de SOM van alle
// aanvragen samen.
//
// $urls: associatieve array [sleutel => url]. Geeft een even grote array
// terug: [sleutel => [inhoud|null, foutmelding|null]] - exact hetzelfde
// resultaatformaat als haalUrlMetDiagnose(), per sleutel.
// ─────────────────────────────────────────────────────────────────────────────
function haalUrlsParallelMetDiagnose(array $urls, int $timeoutSeconden = 15): array
{
    if (empty($urls)) {
        return [];
    }

    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    $multiHandle = curl_multi_init();
    $handles     = [];

    foreach ($urls as $sleutel => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeoutSeconden,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => $userAgent,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2TLS,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: nl-NL,nl;q=0.9,en-US;q=0.8,en;q=0.7',
                'Upgrade-Insecure-Requests: 1',
            ],
        ]);
        curl_multi_add_handle($multiHandle, $ch);
        $handles[$sleutel] = $ch;
    }

    $actief = null;
    do {
        $status = curl_multi_exec($multiHandle, $actief);
        if ($actief) {
            curl_multi_select($multiHandle, 1.0);
        }
    } while ($actief && $status === CURLM_OK);

    $resultaten = [];
    foreach ($handles as $sleutel => $ch) {
        $inhoud    = curl_multi_getcontent($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($inhoud === false || $inhoud === '' || $httpCode < 200 || $httpCode >= 300) {
            $fout = $curlErrno !== 0
                ? "cURL-fout ($curlErrno): $curlError"
                : "HTTP $httpCode";
            $resultaten[$sleutel] = [null, $fout];
        } else {
            $resultaten[$sleutel] = [$inhoud, null];
        }

        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }

    curl_multi_close($multiHandle);

    return $resultaten;
}

function haalUrlMetDiagnose(string $url, int $timeoutSeconden = 10): array
{
    // Zelfde "browser-achtige" identiteit als check_sites.php, zodat
    // beveiligingsplugins dit verkeer niet als bot/scanner herkennen. Naast
    // de User-Agent-tekst zelf proberen we ook HTTP/2 en de overige
    // headers die een echte Chrome standaard meestuurt te benaderen, omdat
    // sommige anti-bot-systemen (bijv. SiteGround's Anti-Bot AI) daar ook
    // op letten - een goede User-Agent alleen is dan niet genoeg.
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeoutSeconden,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => $userAgent,
        CURLOPT_ENCODING       => '', // laat curl gzip/deflate/br automatisch decomprimeren
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2TLS, // moderne browsers gebruiken vrijwel altijd HTTP/2
        CURLOPT_COOKIEJAR      => '', // cookies in het geheugen bijhouden i.p.v. een bestand
        CURLOPT_COOKIEFILE     => '', // en ook weer meesturen bij eventuele redirects
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: nl-NL,nl;q=0.9,en-US;q=0.8,en;q=0.7',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-User: ?1',
            'Upgrade-Insecure-Requests: 1',
            'Sec-Ch-Ua: "Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"',
            'Sec-Ch-Ua-Mobile: ?0',
            'Sec-Ch-Ua-Platform: "Windows"',
        ],
    ]);

    $inhoud    = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Sommige servers (bijv. Balbooa's update-feeds) geven om onduidelijke
    // redenen een andere 2xx-status terug dan 200 (bijv. 202 Accepted),
    // terwijl de geldige inhoud gewoon al in de respons zit. Elke 2xx-status
    // accepteren we daarom als geslaagd, niet alleen precies 200.
    if ($inhoud === false || $httpCode < 200 || $httpCode >= 300) {
        $fout = $curlErrno !== 0
            ? "cURL-fout ($curlErrno): $curlError"
            : "HTTP $httpCode";
        return [null, $fout];
    }

    return [$inhoud, null];
}

// ─────────────────────────────────────────────────────────────────────────────
// Hulpfunctie: haal een URL op via cURL en geef de inhoud terug.
// Geeft null terug bij een HTTP-fout of verbindingsprobleem.
// ─────────────────────────────────────────────────────────────────────────────
function haalUrl(string $url): ?string
{
    [$inhoud, ] = haalUrlMetDiagnose($url);
    return $inhoud;
}

// ─────────────────────────────────────────────────────────────────────────────
// Hulpfunctie: verzamel ALLE ruwe versienummers uit een update-feed, inclusief
// eventuele detailsurl-deelbestanden (zie uitleg hierboven). In tegenstelling
// tot haalHoogsteStabieleVersieVanFeed geeft deze functie de volledige lijst
// terug, zodat we per hoofdversie (major) kunnen groeperen - nodig voor
// Joomla, waar meerdere hoofdversies tegelijk actief onderhouden worden.
// ─────────────────────────────────────────────────────────────────────────────
function haalAlleVersiesVanFeed(string $url, int $timeoutSeconden = 25): array
{
    [$inhoud, $fout] = haalUrlMetDiagnose($url, $timeoutSeconden);

    if ($inhoud === null) {
        return [[], $fout];
    }

    $ruweVersies = [];

    if (preg_match_all('/version="([0-9][0-9.]*(?:-[a-zA-Z0-9]+)?)"/', $inhoud, $m1)) {
        $ruweVersies = array_merge($ruweVersies, $m1[1]);
    }
    if (preg_match_all('/<version>\s*([^<\s][^<]*)\s*<\/version>/i', $inhoud, $m2)) {
        $ruweVersies = array_merge($ruweVersies, $m2[1]);
    }

    if (preg_match_all('/detailsurl="([^"]+)"/', $inhoud, $m3) && !empty($m3[1])) {
        foreach (array_unique($m3[1]) as $detailUrl) {
            [$detailXml, ] = haalUrlMetDiagnose($detailUrl, $timeoutSeconden);
            if ($detailXml !== null) {
                if (preg_match_all('/version="([0-9][0-9.]*(?:-[a-zA-Z0-9]+)?)"/', $detailXml, $dm1)) {
                    $ruweVersies = array_merge($ruweVersies, $dm1[1]);
                }
                if (preg_match_all('/<version>\s*([^<\s][^<]*)\s*<\/version>/i', $detailXml, $dm2)) {
                    $ruweVersies = array_merge($ruweVersies, $dm2[1]);
                }
            }
        }
    }

    if (empty($ruweVersies)) {
        $fragment = substr(preg_replace('/\s+/', ' ', trim($inhoud)), 0, 300);
        return [[], "geen versienummer gevonden in feed of onderliggende deelbestanden - eerste 300 tekens van de respons: \"$fragment\""];
    }

    return [$ruweVersies, null];
}

// ─────────────────────────────────────────────────────────────────────────────
// Hulpfunctie: bepaal de hoogste stabiele versie van een update-feed (voor
// producten met één enkele versielijn, zoals JCE/Akeeba/Admin Tools).
// Volgt ook eventuele detailsurl-deelbestanden, voor het geval de feed een
// collectie is (net als bij Joomla).
// ─────────────────────────────────────────────────────────────────────────────
function haalHoogsteStabieleVersieVanFeed(string $url, int $timeoutSeconden = 25): array
{
    [$ruweVersies, $fout] = haalAlleVersiesVanFeed($url, $timeoutSeconden);

    if (empty($ruweVersies)) {
        return [null, $fout];
    }

    $stabiel = array_values(array_filter($ruweVersies, function ($v) {
        return !preg_match('/-(dev|alpha|beta|rc)/i', $v);
    }));

    if (empty($stabiel)) {
        return [null, 'geen stabiele versie gevonden'];
    }

    usort($stabiel, function ($a, $b) {
        return version_compare($b, $a);
    });

    return [$stabiel[0], null];
}

// ─────────────────────────────────────────────────────────────────────────────
// Hulpfunctie: haal het hoogste vermelde versienummer van de officiële
// Joomla-downloadpagina (bijv. "6.1.2 release · Released on ..."). Dit is
// een aanvullende bron naast de update-feed, voor het geval die laatste
// nog niet is bijgewerkt na een verse release.
// ─────────────────────────────────────────────────────────────────────────────
function haalHoogsteVersieVanDownloadsPagina(string $url, int $timeoutSeconden = 20): array
{
    [$html, $fout] = haalUrlMetDiagnose($url, $timeoutSeconden);

    if ($html === null) {
        return [null, $fout];
    }

    if (!preg_match_all('/(\d+\.\d+\.\d+)\s*release/i', $html, $m) || empty($m[1])) {
        return [null, 'geen versienummer gevonden op de downloadpagina'];
    }

    $versies = array_unique($m[1]);
    usort($versies, function ($a, $b) {
        return version_compare($b, $a);
    });

    return [$versies[0], null];
}

// ─────────────────────────────────────────────────────────────────────────────
// Hulpfunctie: lees de eerste <version> uit een XML-string.
// Geeft null terug als de XML ongeldig is of geen <version> bevat.
// ─────────────────────────────────────────────────────────────────────────────
function leesVersieUitXml(string $xmlInhoud): ?string
{
    $vorigeInternErrors = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlInhoud);
    libxml_clear_errors();
    libxml_use_internal_errors($vorigeInternErrors);

    if ($xml === false) {
        return null;
    }

    $versie = (string) ($xml->version ?? '');

    return $versie !== '' ? trim($versie) : null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Fase 1: nieuwste beschikbare versies ophalen - ÉÉN keer per run (niet per
// site), voor Joomla-core en voor elke catalogus-extensie die een
// update_feed_url heeft. Extensies zonder feed (zoals YOOtheme) blijven
// ongemoeid; die moeten handmatig in nieuwste_versies bijgewerkt worden.
// ─────────────────────────────────────────────────────────────────────────────
$catalogus = haalExtensieCatalogus($pdo);

$upsertNieuwsteStmt = $pdo->prepare("
    INSERT INTO nieuwste_versies (naam, versie, opgehaald_op)
    VALUES (?, ?, NOW())
    ON DUPLICATE KEY UPDATE versie = COALESCE(VALUES(versie), versie), opgehaald_op = NOW()
");

$nieuwsteLog = [];
@file_put_contents(__DIR__ . '/feed_debug.log', '=== Run gestart: ' . date('Y-m-d H:i:s') . " ===\n");

// ─────────────────────────────────────────────────────────────────────────────
// Joomla-core: PER HOOFDVERSIE (major) de nieuwste stabiele versie bepalen
// en opslaan als "joomla_4", "joomla_5", "joomla_6", enz. Zo wordt een site
// op Joomla 5.x vergeleken met de nieuwste 5.x-versie, niet met de hoogste
// hoofdversie die op dat moment bestaat (dat zou een major-upgrade zijn,
// geen gewone update). Dit werkt vanzelf mee met toekomstige hoofdversies
// (7, 8, ...), zonder dat daar iets voor aangepast hoeft te worden.
// ─────────────────────────────────────────────────────────────────────────────
[$ruweJoomlaVersies, $joomlaFout] = haalAlleVersiesVanFeed(JOOMLA_UPDATE_FEED, 25);
$joomlaPerMajor = groepeerVersiesPerMajor($ruweJoomlaVersies);

$joomlaBronnen = [];
$joomlaBronnen[] = !empty($joomlaPerMajor)
    ? 'update-feed: ' . implode(', ', array_map(fn($m, $v) => "$m.x=$v", array_keys($joomlaPerMajor), $joomlaPerMajor))
    : "update-feed: NIET opgehaald ($joomlaFout)";

// Extra controle: de downloadpagina van elke hoofdversie die DAADWERKELIJK
// bij de gemonitorde sites in gebruik is, wordt vaak sneller bijgewerkt dan
// de update-feed zelf (die kan een compleet nieuwe hoofdversie, zoals hier
// Joomla 6.x, soms nog helemaal missen - niet alleen een patch-versie
// erachter). Dit is betrouwbaarder dan gokken op "de hoogste major die de
// feed toevallig al kent", en past vanzelf mee zodra sites naar een nieuwe
// hoofdversie (7, 8, ...) upgraden.
$majorsInGebruik = $pdo->query("
    SELECT DISTINCT SUBSTRING_INDEX(joomla_versie, '.', 1) AS major
    FROM sites
    WHERE joomla_versie IS NOT NULL AND joomla_versie != ''
")->fetchAll(PDO::FETCH_COLUMN);

// Ook de hoofdversies die de feed zelf al kent erbij, voor het geval een
// site (nog) geen joomla_versie heeft opgeslagen.
$teControlerenMajors = array_unique(array_filter(
    array_merge($majorsInGebruik, array_keys($joomlaPerMajor)),
    fn($m) => $m !== null && $m !== ''
));

foreach ($teControlerenMajors as $major) {
    $downloadUrl = "https://downloads.joomla.org/cms/joomla{$major}/";
    [$versieDownloads, $foutDownloads] = haalHoogsteVersieVanDownloadsPagina($downloadUrl);

    if ($versieDownloads !== null) {
        $joomlaBronnen[] = "downloadpagina ($downloadUrl): $versieDownloads";
        $majorVanDownload = bepaalMajorVersie($versieDownloads);
        if ($majorVanDownload !== null
            && (!isset($joomlaPerMajor[$majorVanDownload]) || version_compare($versieDownloads, $joomlaPerMajor[$majorVanDownload], '>'))
        ) {
            $joomlaPerMajor[$majorVanDownload] = $versieDownloads;
        }
    } else {
        $joomlaBronnen[] = "downloadpagina ($downloadUrl): NIET opgehaald ($foutDownloads)";
    }
}

foreach ($joomlaPerMajor as $major => $versie) {
    $upsertNieuwsteStmt->execute(["joomla_$major", $versie]);
}

if (!empty($joomlaPerMajor)) {
    $tekstdelen = [];
    foreach ($joomlaPerMajor as $major => $versie) {
        $tekstdelen[] = "joomla_$major=$versie";
    }
    $nieuwsteLog[] = implode(', ', $tekstdelen) . ' (' . implode(' | ', $joomlaBronnen) . ')';
} else {
    $nieuwsteLog[] = 'joomla: NIET bepaald (' . implode(' | ', $joomlaBronnen) . ')';
}

// ─────────────────────────────────────────────────────────────────────────────
// Overige feeds (catalogus-extensies met een update_feed_url): één enkele
// versielijn per product, dus de gewone hoogste-versie-aanpak.
// ─────────────────────────────────────────────────────────────────────────────
$feeds = [];
foreach ($catalogus as $sleutel => $info) {
    if (!empty($info['update_feed_url'])) {
        $feeds[$sleutel] = $info['update_feed_url'];
    }
}

// Ronde 1: alle hoofdfeeds tegelijk ophalen.
$ruweFeedResultaten = haalUrlsParallelMetDiagnose($feeds, 15);

// Sommige feeds verwijzen (via detailsurl="...") door naar losse
// deelbestanden - bijv. Joomla's eigen feed, die per hoofdversie een apart
// bestand heeft. Die deel-URL's kunnen we pas weten NA het ophalen van de
// hoofdfeed, dus dat gebeurt in een tweede, eveneens parallelle ronde.
$detailUrlsPerSleutel = [];
foreach ($ruweFeedResultaten as $sleutel => [$inhoud, $fout]) {
    if ($inhoud === null) {
        continue;
    }
    if (preg_match_all('/detailsurl="([^"]+)"/', $inhoud, $m) && !empty($m[1])) {
        $detailUrlsPerSleutel[$sleutel] = array_unique($m[1]);
    }
}

$alleDetailUrls = [];
foreach ($detailUrlsPerSleutel as $sleutel => $urls) {
    foreach ($urls as $i => $detailUrl) {
        $alleDetailUrls["{$sleutel}__{$i}"] = $detailUrl;
    }
}
$detailResultaten = empty($alleDetailUrls) ? [] : haalUrlsParallelMetDiagnose($alleDetailUrls, 15);

foreach ($feeds as $sleutel => $feedUrl) {
    [$inhoud, $fout] = $ruweFeedResultaten[$sleutel];
    $ruweVersies = [];

    if ($inhoud !== null) {
        if (preg_match_all('/version="([0-9][0-9.]*(?:-[a-zA-Z0-9]+)?)"/', $inhoud, $m1)) {
            $ruweVersies = array_merge($ruweVersies, $m1[1]);
        }
        if (preg_match_all('/<version>\s*([^<\s][^<]*)\s*<\/version>/i', $inhoud, $m2)) {
            $ruweVersies = array_merge($ruweVersies, $m2[1]);
        }
        foreach ($detailUrlsPerSleutel[$sleutel] ?? [] as $i => $detailUrl) {
            [$detailXml, ] = $detailResultaten["{$sleutel}__{$i}"] ?? [null, null];
            if ($detailXml === null) {
                continue;
            }
            if (preg_match_all('/version="([0-9][0-9.]*(?:-[a-zA-Z0-9]+)?)"/', $detailXml, $dm1)) {
                $ruweVersies = array_merge($ruweVersies, $dm1[1]);
            }
            if (preg_match_all('/<version>\s*([^<\s][^<]*)\s*<\/version>/i', $detailXml, $dm2)) {
                $ruweVersies = array_merge($ruweVersies, $dm2[1]);
            }
        }
    }

    if (empty($ruweVersies)) {
        $fragment = $inhoud !== null ? substr(preg_replace('/\s+/', ' ', trim($inhoud)), 0, 300) : '';
        $versie = null;
        $foutmelding = $inhoud === null
            ? $fout
            : "geen versienummer gevonden in feed of onderliggende deelbestanden - eerste 300 tekens van de respons: \"$fragment\"";
    } else {
        $stabiel = array_values(array_filter($ruweVersies, function ($v) {
            return !preg_match('/-(dev|alpha|beta|rc)/i', $v);
        }));
        if (empty($stabiel)) {
            $versie = null;
            $foutmelding = 'geen stabiele versie gevonden';
        } else {
            usort($stabiel, function ($a, $b) {
                return version_compare($b, $a);
            });
            $versie = $stabiel[0];
            $foutmelding = null;
        }
    }

    $upsertNieuwsteStmt->execute([$sleutel, $versie]);

    if ($versie !== null) {
        $nieuwsteLog[] = "$sleutel: $versie";
    } else {
        $nieuwsteLog[] = "$sleutel: NIET opgehaald - $foutmelding (feed: $feedUrl)";
    }

    $regel = '[' . date('Y-m-d H:i:s') . "] $sleutel => " . ($versie ?? 'NULL') . ' | ' . ($foutmelding ?? '-') . "\n";
    @file_put_contents(__DIR__ . '/feed_debug.log', $regel, FILE_APPEND | LOCK_EX);
}

// ─────────────────────────────────────────────────────────────────────────────
// Fase 2: per site de Joomla-versie + alle catalogus-extensies proberen te
// detecteren via hun manifest-bestand. Met ?site_id= beperkt tot die ene site.
// ─────────────────────────────────────────────────────────────────────────────
$siteIdFilter = isset($_GET['site_id']) ? (int) $_GET['site_id'] : 0;

if ($siteIdFilter > 0) {
    $stmt = $pdo->prepare("SELECT id, domein, admin_pad, url_subpad FROM sites WHERE id = ? AND admin_pad IS NOT NULL AND admin_pad != ''");
    $stmt->execute([$siteIdFilter]);
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sql       = "SELECT id, domein, admin_pad, url_subpad FROM sites WHERE admin_pad IS NOT NULL AND admin_pad != ''";
    $resultaat = $pdo->query($sql);
    $sites     = $resultaat->fetchAll(PDO::FETCH_ASSOC);
}

$joomlaUpdateStmt = $pdo->prepare("UPDATE sites SET joomla_versie = COALESCE(?, joomla_versie) WHERE id = ?");

@file_put_contents(__DIR__ . '/joomla_debug.log', '=== Run gestart: ' . date('Y-m-d H:i:s') . " ===\n");

$extensieUpsertStmt = $pdo->prepare("
    INSERT INTO site_extensies (site_id, sleutel, versie, laatst_gecontroleerd)
    VALUES (?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE versie = VALUES(versie), laatst_gecontroleerd = NOW()
");

$log = [];

// Eerst per site de basis-URL bepalen, en alle benodigde manifest-URL's
// (Joomla-kern + elke catalogus-entry mét manifest_pad) verzamelen - pas
// daarna in één keer parallel ophalen, in plaats van per site en per
// manifest apart en na elkaar (zie ook de toelichting bij
// haalUrlsParallelMetDiagnose() hierboven).
$basisUrlPerSite = [];
$alleManifestUrls = [];

foreach ($sites as $site) {
    $id = $site['id'];

    $adminPad = trim(ontsleutelWaarde($site['admin_pad']), '/');
    $adminPad = strtok($adminPad, '?');
    $adminPad = preg_replace('#/?index\.php$#i', '', $adminPad);
    $adminPad = trim($adminPad, '/');

    if ($adminPad === '') {
        $adminPad = 'administrator';
    }

    $baseUrl = bepaalSiteUrl($site, $adminPad);
    $basisUrlPerSite[$id] = $baseUrl;

    $alleManifestUrls["joomla__{$id}"] = "$baseUrl/" . JOOMLA_MANIFEST_PAD;

    foreach ($catalogus as $sleutel => $info) {
        if (empty($info['manifest_pad'])) {
            continue;
        }
        $alleManifestUrls["ext__{$id}__{$sleutel}"] = $baseUrl . '/' . ltrim($info['manifest_pad'], '/');
    }
}

$manifestResultaten = haalUrlsParallelMetDiagnose($alleManifestUrls, 15);

foreach ($sites as $site) {
    $domein = $site['domein'];
    $id     = $site['id'];
    $baseUrl = $basisUrlPerSite[$id];

    [$joomlaXml, $joomlaFout] = $manifestResultaten["joomla__{$id}"] ?? [null, null];
    $joomlaVersie = ($joomlaXml !== null) ? leesVersieUitXml($joomlaXml) : null;
    $joomlaUpdateStmt->execute([$joomlaVersie, $id]);

    if ($joomlaVersie !== null) {
        $regel = "$domein: Joomla=$joomlaVersie";
    } elseif ($joomlaXml !== null) {
        $regel = "$domein: Joomla=(onveranderd) - manifest opgehaald maar geen versienummer erin gevonden";
    } else {
        $regel = "$domein: Joomla=(onveranderd) - manifest NIET opgehaald: $joomlaFout (url: $baseUrl/" . JOOMLA_MANIFEST_PAD . ")";
    }

    $gevonden = [];
    foreach ($catalogus as $sleutel => $info) {
        if (empty($info['manifest_pad'])) {
            continue;
        }

        [$xmlInhoud, ] = $manifestResultaten["ext__{$id}__{$sleutel}"] ?? [null, null];
        $versie = ($xmlInhoud !== null) ? leesVersieUitXml($xmlInhoud) : null;

        if ($versie !== null) {
            $extensieUpsertStmt->execute([$id, $sleutel, $versie]);
            $gevonden[] = "{$info['label']}=$versie";
        }
    }

    $regel .= empty($gevonden) ? ' | Extensies: geen gevonden' : ' | Extensies: ' . implode(', ', $gevonden);
    $log[]  = $regel;

    @file_put_contents(__DIR__ . '/joomla_debug.log', '[' . date('Y-m-d H:i:s') . "] $regel\n", FILE_APPEND | LOCK_EX);
}

echo implode("\n", $log);
echo "\n\nNieuwste beschikbare versies:\n" . implode("\n", $nieuwsteLog) . "\n";
echo "(let op: extensies zonder update_feed_url in de catalogus, zoals yootheme, worden niet automatisch opgehaald)\n";
echo "\n" . date('Y-m-d H:i:s') . " - klaar.\n";
