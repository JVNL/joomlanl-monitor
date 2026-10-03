<?php
// feed_terugval_functies.php
//
// Centraal ophalen van update-feeds door de monitor (sinds 1.29).
//
// Achtergrond: het scanscript haalt de nieuwste versie van elke extensie op
// via de update-locatie die Joomla zelf heeft geregistreerd
// (#__update_sites), vanaf de server van de site. Sommige update-servers
// blokkeren verzoeken van websites - bekend voorbeeld: de botbeveiliging
// van SiteGround (Anti-Bot AI / SG-Captcha) bij Balbooa. Die slaat al aan
// als tientallen sites in een "alles scannen"-ronde vlak na elkaar dezelfde
// feed opvragen, en blokkeert daarna ook de monitor zelf.
//
// Aanpak:
//   1. Een site die een captchapagina of HTTP 403/429 krijgt, meldt die
//      update-server aan de monitor (feed_geblokkeerde_hosts in het
//      scanresultaat). Ook een blokkade die de monitor zelf krijgt, telt.
//      Zo'n server komt in `feed_centrale_hosts` - zonder vaste lijst, de
//      monitor leert dit zelf.
//   2. Bij het starten van een scan geeft de monitor die servers mee
//      (?cf=...). Het scanscript vraagt feeds van die servers dan NIET meer
//      zelf op, maar meldt alleen de feed-URL.
//   3. Per feed mag hooguit eens per 12 uur ÉÉN site (bij toerbeurt) de
//      feed toch zelf ophalen; die stuurt de inhoud mee naar de monitor,
//      die hem daarna voor alle sites gebruikt (sinds 1.29 - de
//      monitorserver zelf bleek door zo'n update-server hard geblokkeerd
//      te kunnen worden, HTTP 403). Alleen als er geen site aan de beurt
//      is geweest, probeert de monitor het zelf (na een blokkade pas weer
//      na 3 dagen). Alle toewijzingen worden atomair "geclaimd", zodat er
//      nooit meerdere verzoeken tegelijk naar dezelfde feed gaan.
//   4. De laatst succesvol opgehaalde feed blijft bewaard: een tijdelijke
//      blokkade maakt een extensie dus niet meer "Onbekend". Wanneer die
//      voor het laatst lukte, staat op het extensieoverzicht.
//
// Er komt niets in de extensiecatalogus en er gaat niets naar Github.
//
// Wordt gebruikt door ontvang_scan.php (direct bij het ontvangen van een
// scan, alleen voor die site), haal_versies_op.php (vangnet voor alle
// sites), start_scan.php / index.php (welke servers het scanscript moet
// overslaan) en extensies.php (overzicht). Vereist versie_vergelijk_functies.php
// (isJoomlaKernExtensie() e.d.) voor isKandidaatVoorFeedTerugval().

// Na een geslaagde ophaalactie: pas na zoveel minuten opnieuw ophalen.
const FEED_TERUGVAL_INTERVAL_OK_MINUTEN = 720;

// Na een blokkade (captcha/botbeveiliging, HTTP 403/429) van de MONITOR zelf:
// pas na zoveel minuten opnieuw proberen (3 dagen) - herhaald aankloppen
// houdt zo'n blok in stand, en een harde 403 voor de monitorserver verdwijnt
// in de praktijk niet binnen een dag. Intussen loopt het ophalen via één
// site per keer (zie FEED_PROEF_INTERVAL_MINUTEN).
const FEED_TERUGVAL_INTERVAL_BLOK_MINUTEN = 4320;

// Sinds 1.29: per feed mag hooguit eens per zoveel minuten ÉÉN site (bij
// toerbeurt) de feed zelf ophalen en de inhoud aan de monitor doorgeven. Een
// site is voor de update-server een gewone Joomla-site die zijn eigen
// update-feed opvraagt - precies waar die feed voor bedoeld is - maar dan
// één verzoek per 12 uur in plaats van tientallen vlak na elkaar.
const FEED_PROEF_INTERVAL_MINUTEN = 720;

// Sinds 1.29: mislukte de poging via een site, dan mag al na zoveel minuten
// een site op een ANDERE server het proberen (een captcha bij de ene
// hostingpartij zegt niets over de andere). Zijn alle servers geweest, dan
// geldt weer de gewone wachttijd hierboven.
const FEED_PROEF_INTERVAL_MISLUKT_MINUTEN = 60;

// Na een andere fout (time-out, 404, 500, ...): pas na zoveel minuten opnieuw.
const FEED_TERUGVAL_INTERVAL_FOUT_MINUTEN = 60;

// Zo lang blijft een update-server "centraal" na de laatste gemelde
// blokkade. Daarna probeert een site het weer zelf; blokkeert de server dan
// nog steeds, dan komt hij vanzelf weer op de lijst.
const FEED_CENTRAAL_GELDIG_DAGEN = 60;

// Maximale wachttijd per (parallelle) ophaalronde, in seconden.
const FEED_TERUGVAL_TIMEOUT_SECONDEN = 12;

/**
 * Maakt de tabellen aan / werkt ze bij als dat nog niet gebeurd is. Staat
 * ook als migratiestappen 19 en 20 in auto_migratie.php, maar wordt hier
 * bewust nogmaals (idempotent) gedaan: niet elke installatie draait de
 * automatische migratie bij het opstarten.
 */
function zorgVoorFeedTerugvalTabel(PDO $pdo): void
{
    static $gecontroleerd = false;
    if ($gecontroleerd) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `feed_terugval_cache` (
            `url_hash` char(64) NOT NULL,
            `feed_url` text NOT NULL,
            `inhoud` mediumtext NULL,
            `fout` varchar(500) NULL,
            `opgehaald_op` datetime NOT NULL,
            PRIMARY KEY (`url_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $bestaandeKolommen = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `feed_terugval_cache`")->fetchAll(PDO::FETCH_ASSOC) as $kolom) {
        $bestaandeKolommen[$kolom['Field']] = true;
    }
    if (!isset($bestaandeKolommen['host'])) {
        $pdo->exec("ALTER TABLE `feed_terugval_cache` ADD COLUMN `host` varchar(253) NULL AFTER `feed_url`");
    }
    if (!isset($bestaandeKolommen['geblokkeerd'])) {
        $pdo->exec("ALTER TABLE `feed_terugval_cache` ADD COLUMN `geblokkeerd` tinyint(1) NOT NULL DEFAULT 0 AFTER `fout`");
        // Blokkades uit 1.29 (HTTP 403/429, captcha) als zodanig markeren, zodat
        // ook daarvoor meteen de lange wachttijd geldt.
        $pdo->exec("UPDATE `feed_terugval_cache` SET `geblokkeerd` = 1 WHERE `fout` LIKE 'HTTP 403%' OR `fout` LIKE 'HTTP 429%' OR `fout` LIKE '%geblokkeerd%'");
    }
    if (!isset($bestaandeKolommen['laatst_gelukt_op'])) {
        $pdo->exec("ALTER TABLE `feed_terugval_cache` ADD COLUMN `laatst_gelukt_op` datetime NULL AFTER `opgehaald_op`");
        // Rijen uit 1.29 met inhoud: die zijn ooit gelukt.
        $pdo->exec("UPDATE `feed_terugval_cache` SET `laatst_gelukt_op` = `opgehaald_op` WHERE `inhoud` IS NOT NULL AND `inhoud` != ''");
    }

    if (!isset($bestaandeKolommen['proef_op'])) {
        $pdo->exec("ALTER TABLE `feed_terugval_cache` ADD COLUMN `proef_op` datetime NULL AFTER `laatst_gelukt_op`");
        $pdo->exec("ALTER TABLE `feed_terugval_cache` ADD COLUMN `proef_site_id` int(11) NULL AFTER `proef_op`");
        $pdo->exec("ALTER TABLE `feed_terugval_cache` ADD COLUMN `proef_uitkomst` varchar(255) NULL AFTER `proef_site_id`");
    }

    if (!isset($bestaandeKolommen['proef_ip'])) {
        $pdo->exec("ALTER TABLE `feed_terugval_cache` ADD COLUMN `proef_ip` varchar(45) NULL AFTER `proef_site_id`");
        $pdo->exec("ALTER TABLE `feed_terugval_cache` ADD COLUMN `proef_mislukte_ips` text NULL AFTER `proef_uitkomst`");
        $pdo->exec("ALTER TABLE `feed_terugval_cache` ADD COLUMN `handmatige_versie` varchar(40) NULL AFTER `proef_mislukte_ips`");
        $pdo->exec("ALTER TABLE `feed_terugval_cache` ADD COLUMN `handmatig_op` datetime NULL AFTER `handmatige_versie`");
    }

    // Eenmalig bij de overgang naar 1.29: het scanscript vraagt feeds
    // voortaan op zoals Joomla's eigen updatecontrole dat doet (zie
    // feedCurlOpties() in scan_template.php). Eerdere mislukte pogingen via
    // sites zeggen daardoor niets meer - wachttijden en de lijst van servers
    // waar het mislukte worden gewist, zodat elke site meteen weer aan de
    // beurt kan komen.
    try {
        $markering = $pdo->prepare("INSERT IGNORE INTO instellingen (sleutel, waarde) VALUES ('feed_proef_reset_1_29_4', '1')");
        $markering->execute();
        if ($markering->rowCount() === 1) {
            $pdo->exec("
                UPDATE `feed_terugval_cache`
                SET proef_op = NULL, proef_site_id = NULL, proef_ip = NULL, proef_uitkomst = NULL, proef_mislukte_ips = ''
            ");
        }
    } catch (\Throwable $e) {
        // geen instellingen-tabel of geen unieke sleutel: dan niet resetten
    }

    // Het uitgaande IP-adres per site (zoals de monitor het ziet bij het
    // ontvangen van een scan) - om na een mislukte poging bij voorkeur een
    // site op een andere server aan de beurt te laten.
    // Sinds 1.29: per site en update-server of het ophalen (als proefsite)
    // lukte of mislukte - sites waar het eerder lukte krijgen voorrang, sites
    // waar het onlangs mislukte worden zo veel mogelijk overgeslagen.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `feed_site_resultaten` (
            `site_id` int(11) NOT NULL,
            `host` varchar(190) NOT NULL,
            `laatst_gelukt_op` datetime NULL,
            `laatst_mislukt_op` datetime NULL,
            PRIMARY KEY (`site_id`, `host`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `feed_site_ips` (
            `site_id` int(11) NOT NULL,
            `ip` varchar(45) NOT NULL,
            `gezien_op` datetime NOT NULL,
            PRIMARY KEY (`site_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `feed_centrale_hosts` (
            `host` varchar(253) NOT NULL,
            `laatst_geblokkeerd_op` datetime NOT NULL,
            `gemeld_door` varchar(255) NULL,
            PRIMARY KEY (`host`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Blokkades die de monitor zelf al eerder kreeg (bijv. HTTP 403 in de
    // cache uit 1.29): die servers meteen als centraal aanmerken.
    $vorigeBlokkades = $pdo->query("
        SELECT feed_url, opgehaald_op FROM feed_terugval_cache
        WHERE fout LIKE 'HTTP 403%' OR fout LIKE 'HTTP 429%' OR fout LIKE '%geblokkeerd%'
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($vorigeBlokkades as $rij) {
        registreerCentraleFeedHost($pdo, feedHostVanUrl($rij['feed_url']), 'monitor', $rij['opgehaald_op']);
    }

    $gecontroleerd = true;
}

/**
 * Hostnaam van een feed-URL, in kleine letters en zonder "www.".
 */
function feedHostVanUrl(string $url): string
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return (string) preg_replace('/^www\./', '', $host);
}

/**
 * Meldt een update-server als "blokkeert websites": feeds van deze server
 * worden voortaan centraal door de monitor opgehaald.
 */
function registreerCentraleFeedHost(PDO $pdo, string $host, string $gemeldDoor, ?string $tijdstip = null): void
{
    $host = strtolower(trim($host));
    $host = (string) preg_replace('/^www\./', '', $host);
    if ($host === '' || !preg_match('/^[a-z0-9.-]{1,253}$/', $host)) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO feed_centrale_hosts (host, laatst_geblokkeerd_op, gemeld_door)
        VALUES (?, COALESCE(?, NOW()), ?)
        ON DUPLICATE KEY UPDATE
            gemeld_door = IF(VALUES(laatst_geblokkeerd_op) >= laatst_geblokkeerd_op, VALUES(gemeld_door), gemeld_door),
            laatst_geblokkeerd_op = GREATEST(laatst_geblokkeerd_op, VALUES(laatst_geblokkeerd_op))
    ");
    $stmt->execute([$host, $tijdstip, substr($gemeldDoor, 0, 255)]);
}

/**
 * Update-servers waarvan feeds centraal worden opgehaald (en die het
 * scanscript dus moet overslaan).
 *
 * @return string[]
 */
function haalCentraleFeedHostLijst(PDO $pdo): array
{
    try {
        zorgVoorFeedTerugvalTabel($pdo);
        $stmt = $pdo->prepare("
            SELECT host FROM feed_centrale_hosts
            WHERE laatst_geblokkeerd_op > NOW() - INTERVAL ? DAY
            ORDER BY host
        ");
        $stmt->execute([FEED_CENTRAAL_GELDIG_DAGEN]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        return []; // nooit het starten van een scan laten mislukken hierdoor
    }
}

/**
 * Queryparameter voor de scan-URL ("&cf=host1,host2"), of '' als er geen
 * centrale servers zijn.
 */
function centraleFeedHostsQueryParameter(PDO $pdo): string
{
    $hosts = haalCentraleFeedHostLijst($pdo);
    return empty($hosts) ? '' : '&cf=' . rawurlencode(implode(',', $hosts));
}

/**
 * Herkent een pagina van een botbeveiliging die in plaats van de echte
 * feed wordt teruggegeven (SiteGround SG-Captcha, Cloudflare-challenge,
 * Imunify360/Sucuri-achtige tussenpagina's). Geeft een korte omschrijving
 * terug, of null als het geen herkende botbeveiliging is.
 */
function herkenBotBeveiligingsPagina(string $inhoud): ?string
{
    $begin = substr($inhoud, 0, 4000);

    if (stripos($begin, '/.well-known/sgcaptcha') !== false) {
        return 'botbeveiliging van SiteGround (SG-Captcha)';
    }
    if (stripos($begin, 'challenge-platform') !== false || stripos($begin, 'cf-chl') !== false
        || stripos($begin, '<title>Just a moment...</title>') !== false
    ) {
        return 'botbeveiliging van Cloudflare';
    }
    if (stripos($begin, 'imunify360') !== false || stripos($begin, 'bot-protection') !== false) {
        return 'botbeveiliging van de hostingpartij (Imunify360)';
    }
    if (stripos($begin, 'sucuri') !== false && stripos($begin, 'firewall') !== false) {
        return 'Sucuri-firewall';
    }

    return null;
}

/**
 * Bepaalt de hoogste STABIELE versie uit een update-feed. Zelfde logica als
 * haalHoogsteStabieleVersieUitXml() in scan_template.php (element-filter
 * voor gedeelde verzamelfeeds, voorkeur voor dezelfde hoofdversie als de
 * geïnstalleerde versie), zodat de monitor exact dezelfde uitkomst geeft
 * als een geslaagde ophaalactie vanaf de site zelf. De kernversiegrens voor
 * taalbestanden ontbreekt bewust: taalbestanden doen hier niet aan mee
 * (zie isKandidaatVoorFeedTerugval()).
 */
function bepaalNieuwsteVersieUitFeedInhoud(string $xmlInhoud, ?string $huidigeVersie, ?string $element): ?string
{
    if ($element !== null && $element !== '') {
        if (preg_match('/<extension\b[^>]*\belement="' . preg_quote($element, '/') . '"[^>]*>/i', $xmlInhoud, $tagMatch)) {
            if (preg_match('/\bversion="([0-9][0-9.]*(?:-[a-zA-Z0-9]+)?)"/', $tagMatch[0], $versionMatch)) {
                return $versionMatch[1];
            }
        }
    }

    // De XML-declaratie zelf (de kopregel met xml version="1.0") bevat ook een
    // version-attribuut - zonder dit weg te halen telde "1.0" mee als
    // kandidaat, en won die bij een geïnstalleerde 1.x-versie zelfs via de
    // voorkeur voor dezelfde hoofdversie hieronder (sinds 1.29).
    $xmlInhoud = preg_replace('/<\?xml\b[^>]*\?>/i', '', $xmlInhoud);

    $versies = [];
    if (preg_match_all('/version="([0-9][0-9.]*(?:-[a-zA-Z0-9]+)?)"/', $xmlInhoud, $m1)) {
        $versies = array_merge($versies, $m1[1]);
    }
    if (preg_match_all('/<version>\s*([^<\s][^<]*)\s*<\/version>/i', $xmlInhoud, $m2)) {
        $versies = array_merge($versies, $m2[1]);
    }

    $stabiel = array_values(array_filter($versies, function ($v) {
        return !preg_match('/-(dev|alpha|beta|rc)/i', $v);
    }));

    if (empty($stabiel)) {
        return null;
    }

    usort($stabiel, function ($a, $b) {
        return version_compare($b, $a);
    });

    if ($huidigeVersie !== null && $huidigeVersie !== '') {
        $huidigeMajor = strtok((string) $huidigeVersie, '.');
        if ($huidigeMajor !== false && $huidigeMajor !== '') {
            foreach ($stabiel as $v) {
                if (strtok($v, '.') === $huidigeMajor) {
                    return $v;
                }
            }
        }
    }

    return $stabiel[0];
}

/**
 * Of een extensieregel (uit het scanresultaat óf uit site_alle_extensies -
 * zelfde kolomnamen) in aanmerking komt: een update-feed bekend, maar geen
 * nieuwste versie gevonden. Uitgezonderd zijn precies de gevallen waarin
 * het scanscript BEWUST geen nieuwste versie ophaalt:
 *   - Joomla-kernonderdelen (die volgen we via de Joomla-versie zelf);
 *   - onderdelen van een RSJoomla!-pakket (houden hun installatieversie);
 *   - taalbestanden (versie gebonden aan de kernversie van de site);
 *   - wat sowieso buiten het extensieoverzicht blijft.
 */
function isKandidaatVoorFeedTerugval(array $extensie): bool
{
    $feedUrl = trim((string) ($extensie['update_feed_url'] ?? ''));
    if ($feedUrl === '' || !preg_match('#^https?://#i', $feedUrl)) {
        return false;
    }
    if (!empty($extensie['nieuwste_versie'])) {
        return false;
    }
    if (isJoomlaKernExtensie($extensie) || isUitgeslotenVanExtensieoverzicht($extensie)) {
        return false;
    }

    $auteur = (string) ($extensie['auteur'] ?? '');
    if ((int) ($extensie['package_id'] ?? 0) > 0 && stripos($auteur, 'rsjoomla') !== false) {
        return false;
    }

    $type = strtolower((string) ($extensie['type'] ?? ''));
    if ($type === 'language' || stripos((string) ($extensie['naam'] ?? ''), 'language pack') !== false) {
        return false;
    }

    return true;
}

/**
 * Haalt meerdere feeds tegelijk op vanaf de monitorserver. Zelfde
 * browserachtige identiteit als haal_versies_op.php. Geeft
 * [url => [inhoud|null, fout|null, geblokkeerd (bool)]] terug.
 */
function haalFeedsOpVoorTerugval(array $urls, int $timeoutSeconden = FEED_TERUGVAL_TIMEOUT_SECONDEN): array
{
    $resultaten = [];
    if (empty($urls)) {
        return $resultaten;
    }

    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    $multiHandle = curl_multi_init();
    $handles     = [];

    foreach (array_values(array_unique($urls)) as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
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
        $handles[$url] = $ch;
    }

    $actief = null;
    do {
        $status = curl_multi_exec($multiHandle, $actief);
        if ($actief) {
            curl_multi_select($multiHandle, 1.0);
        }
    } while ($actief && $status === CURLM_OK);

    foreach ($handles as $url => $ch) {
        $inhoud    = curl_multi_getcontent($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Elke 2xx-status telt als geslaagd (sommige servers, waaronder die
        // van Balbooa, geven bijv. 202 Accepted met geldige inhoud).
        if ($inhoud === false || $inhoud === null || $inhoud === '' || $httpCode < 200 || $httpCode >= 300) {
            $fout = $curlErrno !== 0 ? "cURL-fout ($curlErrno): $curlError" : "HTTP $httpCode";
            $resultaten[$url] = [null, $fout, in_array($httpCode, [403, 429], true)];
        } else {
            $botBeveiliging = herkenBotBeveiligingsPagina($inhoud);
            $resultaten[$url] = $botBeveiliging !== null
                ? [null, "geblokkeerd door $botBeveiliging", true]
                : [$inhoud, null, false];
        }

        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }

    curl_multi_close($multiHandle);

    return $resultaten;
}

/**
 * Geeft per feed-URL de laatst bekende goede inhoud terug, en haalt een
 * feed alleen opnieuw op als de wachttijd sinds de vorige poging voorbij
 * is. Gelijktijdige processen (parallelle scans) "claimen" een ophaalactie
 * met een atomaire UPDATE/INSERT: alleen het proces dat de claim krijgt,
 * benadert de update-server.
 *
 * Resultaat: [url => [
 *     'inhoud'           => laatst succesvol opgehaalde inhoud (of null),
 *     'laatst_gelukt_op' => datum/tijd daarvan (of null),
 *     'fout'             => fout van de laatste poging (null = gelukt),
 *     'vers'             => true als in deze aanroep vers (en met succes) opgehaald,
 * ]]
 */
function haalFeedInhoudMetCache(PDO $pdo, array $urls, bool $magOphalen = true): array
{
    zorgVoorFeedTerugvalTabel($pdo);

    $urls = array_values(array_unique(array_filter($urls)));
    $resultaat = [];
    if (empty($urls)) {
        return $resultaat;
    }

    // Loopt het ophalen van een feed via een site (proef_op korter dan een
    // dag geleden, zie bepaalProefFeedsVoorSites()), dan blijft de monitor
    // zelf van de update-server af.
    //
    // Claim voor een bestaande rij: alleen als de wachttijd (afhankelijk van
    // de uitkomst van de vorige poging) voorbij is. opgehaald_op wordt
    // meteen op NOW() gezet, zodat een gelijktijdig proces de claim niet
    // ook krijgt.
    $claimStmt = $pdo->prepare("
        UPDATE feed_terugval_cache
        SET opgehaald_op = NOW()
        WHERE url_hash = ?
          AND opgehaald_op <= NOW() - INTERVAL (
                CASE
                    WHEN fout IS NULL THEN ?
                    WHEN geblokkeerd = 1 THEN ?
                    ELSE ?
                END
              ) MINUTE
          AND (proef_op IS NULL OR proef_op <= NOW() - INTERVAL 1440 MINUTE)
    ");
    // Claim voor een nieuwe rij.
    $nieuwStmt = $pdo->prepare("
        INSERT IGNORE INTO feed_terugval_cache (url_hash, feed_url, host, inhoud, fout, geblokkeerd, opgehaald_op)
        VALUES (?, ?, ?, NULL, 'wordt op dit moment door een andere scan opgehaald', 0, NOW())
    ");

    $nogOphalen = [];
    foreach ($magOphalen ? $urls : [] as $url) {
        $hash = hash('sha256', $url);
        $nieuwStmt->execute([$hash, $url, feedHostVanUrl($url)]);
        if ($nieuwStmt->rowCount() === 1) {
            $nogOphalen[] = $url;
            continue;
        }
        $claimStmt->execute([
            $hash,
            FEED_TERUGVAL_INTERVAL_OK_MINUTEN,
            FEED_TERUGVAL_INTERVAL_BLOK_MINUTEN,
            FEED_TERUGVAL_INTERVAL_FOUT_MINUTEN,
        ]);
        if ($claimStmt->rowCount() === 1) {
            $nogOphalen[] = $url;
        }
    }

    if (!empty($nogOphalen)) {
        $opgehaald = haalFeedsOpVoorTerugval($nogOphalen);

        // Gelukt: nieuwe inhoud + tijdstip. Mislukt: de vorige goede inhoud
        // blijft juist staan (laatst bekende versie), alleen de fout wordt
        // bijgewerkt.
        $gelukStmt = $pdo->prepare("
            UPDATE feed_terugval_cache
            SET inhoud = ?, fout = NULL, geblokkeerd = 0, host = ?, opgehaald_op = NOW(), laatst_gelukt_op = NOW()
            WHERE url_hash = ?
        ");
        $foutStmt = $pdo->prepare("
            UPDATE feed_terugval_cache
            SET fout = ?, geblokkeerd = ?, host = ?, opgehaald_op = NOW()
            WHERE url_hash = ?
        ");

        foreach ($nogOphalen as $url) {
            [$inhoud, $fout, $geblokkeerd] = $opgehaald[$url] ?? [null, 'niet opgehaald', false];
            $hash = hash('sha256', $url);
            $host = feedHostVanUrl($url);

            // Uitzonderlijk grote antwoorden niet bewaren (een update-feed
            // is normaal hooguit enkele tientallen kB).
            if ($inhoud !== null && strlen($inhoud) <= 2 * 1024 * 1024) {
                $gelukStmt->execute([$inhoud, $host, $hash]);
            } else {
                $foutStmt->execute([
                    substr((string) ($fout ?? 'antwoord te groot'), 0, 500),
                    $geblokkeerd ? 1 : 0,
                    $host,
                    $hash,
                ]);
                if ($geblokkeerd) {
                    registreerCentraleFeedHost($pdo, $host, 'monitor');
                }
            }
        }
    }

    // Eindstand per URL uit de tabel lezen (ook voor URL's die een ander
    // proces of een eerdere ronde al had opgehaald).
    $leesStmt = $pdo->prepare("SELECT inhoud, fout, laatst_gelukt_op, handmatige_versie, handmatig_op FROM feed_terugval_cache WHERE url_hash = ?");
    foreach ($urls as $url) {
        $leesStmt->execute([hash('sha256', $url)]);
        $rij = $leesStmt->fetch(PDO::FETCH_ASSOC) ?: ['inhoud' => null, 'fout' => 'niet gevonden', 'laatst_gelukt_op' => null, 'handmatige_versie' => null, 'handmatig_op' => null];
        $resultaat[$url] = [
            'inhoud'           => ($rij['inhoud'] !== null && $rij['inhoud'] !== '') ? $rij['inhoud'] : null,
            'laatst_gelukt_op' => $rij['laatst_gelukt_op'],
            'fout'             => $rij['fout'],
            'vers'             => in_array($url, $nogOphalen, true) && $rij['fout'] === null,
            'handmatige_versie' => ($rij['handmatige_versie'] !== null && $rij['handmatige_versie'] !== '') ? $rij['handmatige_versie'] : null,
            'handmatig_op'     => $rij['handmatig_op'],
        ];
    }

    return $resultaat;
}

/**
 * Vult in een lijst extensieregels (scanresultaat of site_alle_extensies)
 * de ontbrekende nieuwste_versie aan vanuit de centraal opgehaalde feeds.
 * Past de regels zelf aan (by reference) en geeft een verslag terug:
 *   ['gelukt'  => [ ['naam', 'versie', 'feed', 'index', 'laatst_gelukt_op', 'vers', 'fout'], ... ],
 *    'mislukt' => [ ['naam', 'reden', 'feed', 'index'], ... ]]
 * Bij 'gelukt' kan 'fout' gevuld zijn: dan is de laatste poging mislukt en
 * is de laatst bekende versie gebruikt.
 */
function vulNieuwsteVersiesAanViaMonitor(PDO $pdo, array &$extensies, bool $magOphalen = true): array
{
    $verslag = ['gelukt' => [], 'mislukt' => []];

    $kandidaten = [];
    foreach ($extensies as $index => $extensie) {
        if (is_array($extensie) && isKandidaatVoorFeedTerugval($extensie)) {
            $kandidaten[$index] = trim($extensie['update_feed_url']);
        }
    }

    if (empty($kandidaten)) {
        return $verslag;
    }

    $feeds = haalFeedInhoudMetCache($pdo, array_values($kandidaten), $magOphalen);

    foreach ($kandidaten as $index => $feedUrl) {
        $feed = ($feeds[$feedUrl] ?? []) + ['inhoud' => null, 'fout' => 'niet opgehaald', 'laatst_gelukt_op' => null, 'vers' => false, 'handmatige_versie' => null, 'handmatig_op' => null];
        $naam = (string) ($extensies[$index]['naam'] ?? $feedUrl);

        // Sommige extensies staan bij verschillende sites met een net andere
        // feed-URL geregistreerd (bijv. .../updates/forms/... en
        // .../updates/baforms/... voor hetzelfde pakket). Is van déze URL nog
        // nooit iets binnengekomen, dan volstaat een al bewaarde feed van
        // dezelfde update-server die aantoonbaar over hetzelfde element gaat.
        $element = isset($extensies[$index]['element']) ? trim((string) $extensies[$index]['element']) : '';
        if ($feed['inhoud'] === null && $element !== '') {
            $verwant = zoekVerwanteFeedInhoud($pdo, $feedUrl, $element);
            if ($verwant !== null) {
                $feed['inhoud'] = $verwant['inhoud'];
                $feed['laatst_gelukt_op'] = $verwant['laatst_gelukt_op'];
                $feed['vers'] = false;
                $feed['fout'] = null;
            }
        }

        $versie = null;
        $fout = $feed['fout'];
        if ($feed['inhoud'] !== null) {
            $versie = bepaalNieuwsteVersieUitFeedInhoud(
                $feed['inhoud'],
                isset($extensies[$index]['versie']) ? (string) $extensies[$index]['versie'] : null,
                isset($extensies[$index]['element']) ? (string) $extensies[$index]['element'] : null
            );
            if ($versie === null) {
                $fout = 'feed opgehaald, maar geen (stabiel) versienummer gevonden';
            }
        }

        // Handmatig ingevulde versie (zie slaHandmatigeFeedVersieOp()): geldt
        // als er nog nooit een feed is binnengekomen, of als hij recenter is
        // ingevuld dan de laatst opgehaalde feed.
        $handmatig = false;
        if ($feed['handmatige_versie'] !== null
            && ($versie === null || (string) $feed['handmatig_op'] > (string) $feed['laatst_gelukt_op'])
        ) {
            $versie = $feed['handmatige_versie'];
            $handmatig = true;
            $feed['laatst_gelukt_op'] = $feed['handmatig_op'];
            $feed['vers'] = false;
        }

        if ($versie !== null) {
            $extensies[$index]['nieuwste_versie'] = $versie;
            $verslag['gelukt'][] = [
                'handmatig'        => $handmatig,
                'naam'             => $naam,
                'versie'           => $versie,
                'feed'             => $feedUrl,
                'index'            => $index,
                'laatst_gelukt_op' => $feed['laatst_gelukt_op'],
                'vers'             => $feed['vers'],
                'fout'             => $feed['fout'],
            ];
        } else {
            $verslag['mislukt'][] = ['naam' => $naam, 'reden' => (string) $fout, 'feed' => $feedUrl, 'index' => $index];
        }
    }

    return $verslag;
}

/**
 * Zoekt een bewaarde feed van dezelfde update-server waarin hetzelfde
 * Joomla-element voorkomt (<element>pkg_x</element> of element="pkg_x",
 * hoofdletterongevoelig). Geeft ['inhoud', 'laatst_gelukt_op'] of null.
 */
function zoekVerwanteFeedInhoud(PDO $pdo, string $feedUrl, string $element): ?array
{
    $stmt = $pdo->prepare("
        SELECT inhoud, laatst_gelukt_op FROM feed_terugval_cache
        WHERE host = ? AND url_hash != ? AND inhoud IS NOT NULL AND inhoud != ''
        ORDER BY laatst_gelukt_op DESC
    ");
    $stmt->execute([feedHostVanUrl($feedUrl), hash('sha256', $feedUrl)]);

    $q = preg_quote($element, '/');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
        if (preg_match('/<element>\s*' . $q . '\s*<\/element>/i', $rij['inhoud'])
            || preg_match('/\belement="' . $q . '"/i', $rij['inhoud'])
        ) {
            return $rij;
        }
    }

    return null;
}

/**
 * Overzicht voor extensies.php: per centraal opgehaalde update-server de
 * feeds, met wanneer die voor het laatst lukten.
 *
 * @return array [host => ['laatst_geblokkeerd_op' => .., 'feeds' => [ [feed_url, laatst_gelukt_op, fout, opgehaald_op], ... ]]]
 */
function haalCentraleFeedOverzicht(PDO $pdo): array
{
    $overzicht = [];
    try {
        zorgVoorFeedTerugvalTabel($pdo);
        $stmt = $pdo->prepare("
            SELECT host, laatst_geblokkeerd_op FROM feed_centrale_hosts
            WHERE laatst_geblokkeerd_op > NOW() - INTERVAL ? DAY
            ORDER BY host
        ");
        $stmt->execute([FEED_CENTRAAL_GELDIG_DAGEN]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
            $overzicht[$rij['host']] = ['laatst_geblokkeerd_op' => $rij['laatst_geblokkeerd_op'], 'feeds' => []];
        }
        if (empty($overzicht)) {
            return [];
        }
        $feedStmt = $pdo->query("SELECT feed_url, host, laatst_gelukt_op, fout, opgehaald_op, proef_op, proef_uitkomst, handmatige_versie, handmatig_op FROM feed_terugval_cache ORDER BY feed_url");
        foreach ($feedStmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
            $host = $rij['host'] ?: feedHostVanUrl($rij['feed_url']);
            if (isset($overzicht[$host])) {
                $overzicht[$host]['feeds'][] = $rij;
            }
        }
    } catch (\Throwable $e) {
        return [];
    }
    return $overzicht;
}

/**
 * Korte code waarmee een feed-URL in de scan-aanroep wordt aangeduid
 * (?cfp=code1,code2): de eerste 12 tekens van de sha256 van de URL. Het
 * scanscript berekent dezelfde code over zijn eigen feed-URL's.
 */
function proefCodeVoorFeedUrl(string $url): string
{
    return substr(hash('sha256', trim($url)), 0, 12);
}

/**
 * Bepaalt (sinds 1.29) welke site welke centraal opgehaalde feed deze
 * ronde ZELF mag ophalen. Per feed-URL hooguit één site per
 * FEED_PROEF_INTERVAL_MINUTEN, bij toerbeurt (de eerstvolgende site na de
 * site die de vorige keer aan de beurt was). De toewijzing wordt atomair
 * geclaimd, zodat gelijktijdige scanverzoeken nooit twee sites dezelfde
 * feed laten ophalen.
 *
 * @param string[] $domeinen domeinen van de sites die nu gescand worden
 * @return array [domein => [proefcode, ...]]
 */
function bepaalProefFeedsVoorSites(PDO $pdo, array $domeinen): array
{
    $toewijzing = [];
    try {
        $centraleHosts = haalCentraleFeedHostLijst($pdo);
        $domeinen = array_values(array_unique(array_filter($domeinen)));
        if (empty($centraleHosts) || empty($domeinen)) {
            return [];
        }

        $plaatsen = implode(',', array_fill(0, count($domeinen), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT s.id AS site_id, s.domein, sae.update_feed_url
            FROM site_alle_extensies sae
            JOIN sites s ON s.id = sae.site_id
            WHERE s.domein IN ($plaatsen)
              AND sae.update_feed_url IS NOT NULL AND sae.update_feed_url != ''
            ORDER BY s.id
        ");
        $stmt->execute($domeinen);

        // [feed-URL => [site_id => domein]] voor feeds van centrale servers.
        $sitesPerFeed = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
            $url  = trim($rij['update_feed_url']);
            $host = feedHostVanUrl($url);
            foreach ($centraleHosts as $centraal) {
                if ($host === $centraal || substr($host, -strlen('.' . $centraal)) === '.' . $centraal) {
                    $sitesPerFeed[$url][(int) $rij['site_id']] = $rij['domein'];
                    break;
                }
            }
        }

        $nieuwStmt = $pdo->prepare("
            INSERT IGNORE INTO feed_terugval_cache (url_hash, feed_url, host, inhoud, fout, geblokkeerd, opgehaald_op)
            VALUES (?, ?, ?, NULL, 'nog niet opgehaald', 0, NOW())
        ");
        $vorigeStmt = $pdo->prepare("
            SELECT proef_site_id, proef_ip, proef_uitkomst, proef_mislukte_ips
            FROM feed_terugval_cache WHERE url_hash = ?
        ");
        $claimStmt = $pdo->prepare("
            UPDATE feed_terugval_cache
            SET proef_op = NOW(), proef_site_id = ?, proef_ip = ?, proef_uitkomst = 'gestart', proef_mislukte_ips = ?
            WHERE url_hash = ?
              AND (proef_op IS NULL OR proef_op <= NOW() - INTERVAL ? MINUTE)
        ");

        // Laatst geziene uitgaande IP-adressen per site.
        $ipPerSite = [];
        foreach ($pdo->query("SELECT site_id, ip FROM feed_site_ips")->fetchAll(PDO::FETCH_ASSOC) as $rij) {
            $ipPerSite[(int) $rij['site_id']] = $rij['ip'];
        }

        // Kiest de eerstvolgende site (toerbeurt) uit $kandidaten: de eerste
        // met een hoger id dan de vorige; is die er niet, dan weer de eerste.
        $kiesVolgende = function (array $kandidaten, int $vorigeSiteId): int {
            ksort($kandidaten);
            foreach ($kandidaten as $siteId => $_) {
                if ($siteId > $vorigeSiteId) {
                    return $siteId;
                }
            }
            return (int) array_key_first($kandidaten);
        };

        // Ervaring per site en update-server (sinds 1.29): "bewezen" = het
        // lukte daar eerder en is sindsdien niet mislukt; "slecht" = het
        // mislukte daar in de afgelopen 7 dagen zonder later succes.
        $bewezenPerHost = [];
        $slechtPerHost  = [];
        $ervaringStmt = $pdo->query("
            SELECT site_id, host,
                   (laatst_gelukt_op IS NOT NULL AND (laatst_mislukt_op IS NULL OR laatst_gelukt_op >= laatst_mislukt_op)) AS bewezen,
                   (laatst_mislukt_op IS NOT NULL AND laatst_mislukt_op > NOW() - INTERVAL 7 DAY
                        AND (laatst_gelukt_op IS NULL OR laatst_gelukt_op < laatst_mislukt_op)) AS slecht
            FROM feed_site_resultaten
        ");
        foreach ($ervaringStmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
            if ($rij['bewezen']) {
                $bewezenPerHost[$rij['host']][(int) $rij['site_id']] = true;
            }
            if ($rij['slecht']) {
                $slechtPerHost[$rij['host']][(int) $rij['site_id']] = true;
            }
        }

        // Beperkt een lijst kandidaten tot de beste keus: liefst sites waar
        // het eerder lukte, anders sites waar het niet onlangs mislukte,
        // anders gewoon allemaal.
        $besteKandidaten = function (array $kandidaten, string $host) use ($bewezenPerHost, $slechtPerHost): array {
            $bewezen = array_intersect_key($kandidaten, $bewezenPerHost[$host] ?? []);
            if (!empty($bewezen)) {
                return $bewezen;
            }
            $nietSlecht = array_diff_key($kandidaten, $slechtPerHost[$host] ?? []);
            return !empty($nietSlecht) ? $nietSlecht : $kandidaten;
        };

        foreach ($sitesPerFeed as $url => $sites) {
            $hash = hash('sha256', $url);
            $feedHost = feedHostVanUrl($url);
            $nieuwStmt->execute([$hash, $url, $feedHost]);

            $vorigeStmt->execute([$hash]);
            $vorige = $vorigeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $vorigeSiteId  = (int) ($vorige['proef_site_id'] ?? 0);
            $vorigeUitkomst = (string) ($vorige['proef_uitkomst'] ?? '');
            $vorigeGelukt  = strpos($vorigeUitkomst, 'gelukt via') === 0;

            // Servers (IP-adressen) waar het sinds de laatste geslaagde poging
            // al mislukte. Een poging waarvan nooit een resultaat terugkwam
            // ("gestart") telt ook als mislukt.
            $mislukteIps = array_values(array_filter(explode(',', (string) ($vorige['proef_mislukte_ips'] ?? ''))));
            if ($vorigeUitkomst === 'gestart' && !empty($vorige['proef_ip'])) {
                $mislukteIps[] = $vorige['proef_ip'];
            }
            $mislukteIps = array_values(array_unique($mislukteIps));

            if ($vorigeGelukt || $vorigeUitkomst === '') {
                // Gewone ronde: 12 uur na de vorige, volgende site in de toerbeurt.
                $interval = FEED_PROEF_INTERVAL_MINUTEN;
                $gekozenSiteId = $kiesVolgende($besteKandidaten($sites, $feedHost), $vorigeSiteId);
                $mislukteIps = [];
            } else {
                // Vorige poging mislukt: bij voorkeur een site op een server
                // waar het nog niet geprobeerd is - die mag al na een uur.
                $nogNietGeprobeerd = [];
                foreach ($sites as $siteId => $domein) {
                    $ip = $ipPerSite[$siteId] ?? null;
                    if ($siteId !== $vorigeSiteId && ($ip === null || !in_array($ip, $mislukteIps, true))) {
                        $nogNietGeprobeerd[$siteId] = $domein;
                    }
                }
                if (!empty($nogNietGeprobeerd)) {
                    $interval = FEED_PROEF_INTERVAL_MISLUKT_MINUTEN;
                    $gekozenSiteId = $kiesVolgende($besteKandidaten($nogNietGeprobeerd, $feedHost), $vorigeSiteId);
                } else {
                    // Alle servers zijn geweest: nieuwe ronde, na de gewone wachttijd.
                    $interval = FEED_PROEF_INTERVAL_MINUTEN;
                    $gekozenSiteId = $kiesVolgende($besteKandidaten($sites, $feedHost), $vorigeSiteId);
                    $mislukteIps = [];
                }
            }

            $claimStmt->execute([
                $gekozenSiteId,
                $ipPerSite[$gekozenSiteId] ?? null,
                implode(',', $mislukteIps),
                $hash,
                $interval,
            ]);
            if ($claimStmt->rowCount() === 1) {
                $toewijzing[$sites[$gekozenSiteId]][] = proefCodeVoorFeedUrl($url);
            }
        }
    } catch (\Throwable $e) {
        return []; // nooit het starten van een scan laten mislukken hierdoor
    }

    return $toewijzing;
}

/**
 * "Probeer nu via deze site" (sinds 1.29): wijst alle centraal opgehaalde
 * feeds van één site direct aan die site toe, zonder de wachttijd af te
 * wachten. Bedoeld voor een bewuste, handmatige actie op het
 * extensieoverzicht - bijv. voor een site waarvan je weet dat Joomla zelf de
 * feed daar wel kan openen.
 *
 * @return array [domein => [proefcode, ...]]
 */
function forceerProefFeedsVoorSite(PDO $pdo, string $domein): array
{
    $codes = [];
    try {
        $centraleHosts = haalCentraleFeedHostLijst($pdo);
        if (empty($centraleHosts)) {
            return [];
        }

        $stmt = $pdo->prepare("
            SELECT DISTINCT s.id AS site_id, sae.update_feed_url
            FROM site_alle_extensies sae
            JOIN sites s ON s.id = sae.site_id
            WHERE s.domein = ? AND sae.update_feed_url IS NOT NULL AND sae.update_feed_url != ''
        ");
        $stmt->execute([$domein]);

        $nieuwStmt = $pdo->prepare("
            INSERT IGNORE INTO feed_terugval_cache (url_hash, feed_url, host, inhoud, fout, geblokkeerd, opgehaald_op)
            VALUES (?, ?, ?, NULL, 'nog niet opgehaald', 0, NOW())
        ");
        $claimStmt = $pdo->prepare("
            UPDATE feed_terugval_cache
            SET proef_op = NOW(), proef_site_id = ?, proef_uitkomst = 'gestart',
                proef_ip = (SELECT ip FROM feed_site_ips WHERE site_id = ?)
            WHERE url_hash = ?
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
            $url  = trim($rij['update_feed_url']);
            $host = feedHostVanUrl($url);
            $isCentraal = false;
            foreach ($centraleHosts as $centraal) {
                if ($host === $centraal || substr($host, -strlen('.' . $centraal)) === '.' . $centraal) {
                    $isCentraal = true;
                    break;
                }
            }
            if (!$isCentraal) {
                continue;
            }
            $hash = hash('sha256', $url);
            $nieuwStmt->execute([$hash, $url, $host]);
            $claimStmt->execute([(int) $rij['site_id'], (int) $rij['site_id'], $hash]);
            $codes[] = proefCodeVoorFeedUrl($url);
        }
    } catch (\Throwable $e) {
        return [];
    }

    return empty($codes) ? [] : [$domein => array_values(array_unique($codes))];
}

/**
 * Verwerkt feed-inhoud die een site (als "proefsite", zie
 * bepaalProefFeedsVoorSites()) zelf heeft opgehaald en met het scanresultaat
 * meestuurt: is het een echte feed met een versienummer, dan wordt hij in de
 * cache gezet als geslaagde ophaalactie - en geldt hij daarmee voor ALLE
 * sites met dezelfde feed. Geeft de feed-URL's terug die zijn overgenomen.
 *
 * @param array $feeds [feed-URL => inhoud]
 * @return string[]
 */
function verwerkProefFeedInhoud(PDO $pdo, array $feeds, string $domein, int $siteId = 0): array
{
    zorgVoorFeedTerugvalTabel($pdo);
    $overgenomen = [];
    $geluktStmt = $pdo->prepare("
        INSERT INTO feed_site_resultaten (site_id, host, laatst_gelukt_op) VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE laatst_gelukt_op = NOW()
    ");

    $stmt = $pdo->prepare("
        INSERT INTO feed_terugval_cache (url_hash, feed_url, host, inhoud, fout, geblokkeerd, opgehaald_op, laatst_gelukt_op, proef_uitkomst)
        VALUES (?, ?, ?, ?, NULL, 0, NOW(), NOW(), ?)
        ON DUPLICATE KEY UPDATE inhoud = VALUES(inhoud), fout = NULL, geblokkeerd = 0, host = VALUES(host),
                                opgehaald_op = NOW(), laatst_gelukt_op = NOW(), proef_uitkomst = VALUES(proef_uitkomst),
                                proef_mislukte_ips = ''
    ");

    foreach (array_slice($feeds, 0, 10, true) as $url => $inhoud) {
        $url = trim((string) $url);
        if (!is_string($inhoud) || $inhoud === '' || strlen($inhoud) > 2 * 1024 * 1024 || !preg_match('#^https?://#i', $url)) {
            continue;
        }
        if (herkenBotBeveiligingsPagina($inhoud) !== null || bepaalNieuwsteVersieUitFeedInhoud($inhoud, null, null) === null) {
            continue;
        }
        $stmt->execute([hash('sha256', $url), $url, feedHostVanUrl($url), $inhoud, substr('gelukt via ' . $domein, 0, 255)]);
        if ($siteId > 0) {
            $geluktStmt->execute([$siteId, substr(feedHostVanUrl($url), 0, 190)]);
        }
        $overgenomen[] = $url;
    }

    return $overgenomen;
}

/**
 * Legt vast dat de proefsite een feed NIET kon ophalen (bijv. geblokkeerd), zodat
 * dat op het extensieoverzicht te zien is. De volgende poging is dan voor de
 * volgende site in de toerbeurt, na FEED_PROEF_INTERVAL_MINUTEN.
 */
function registreerMislukteProef(PDO $pdo, int $siteId, string $domein, ?string $ip = null): void
{
    zorgVoorFeedTerugvalTabel($pdo);

    // Per update-server vastleggen dat het via deze site mislukte.
    $hostsStmt = $pdo->prepare("SELECT DISTINCT host, feed_url FROM feed_terugval_cache WHERE proef_site_id = ? AND proef_uitkomst = 'gestart'");
    $hostsStmt->execute([$siteId]);
    $misluktStmt = $pdo->prepare("
        INSERT INTO feed_site_resultaten (site_id, host, laatst_mislukt_op) VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE laatst_mislukt_op = NOW()
    ");
    foreach ($hostsStmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
        $host = $rij['host'] ?: feedHostVanUrl($rij['feed_url']);
        if ($host !== '') {
            $misluktStmt->execute([$siteId, substr($host, 0, 190)]);
        }
    }

    // Het IP-adres waarmee de site zich nu bij de monitor meldde (of anders
    // het adres dat bij de toewijzing bekend was) komt op de lijst van
    // servers waar het mislukte.
    $stmt = $pdo->prepare("
        UPDATE feed_terugval_cache
        SET proef_uitkomst = ?,
            proef_mislukte_ips = TRIM(BOTH ',' FROM CONCAT(COALESCE(proef_mislukte_ips, ''), ',', COALESCE(?, proef_ip, '')))
        WHERE proef_site_id = ? AND proef_uitkomst = 'gestart'
    ");
    $stmt->execute([substr('niet gelukt via ' . $domein, 0, 255), $ip, $siteId]);
}

/**
 * Onthoudt het uitgaande IP-adres van een site (zoals de monitor het ziet
 * bij het ontvangen van een scan).
 */
function registreerSiteIp(PDO $pdo, int $siteId, ?string $ip): void
{
    if ($siteId <= 0 || $ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return;
    }
    zorgVoorFeedTerugvalTabel($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO feed_site_ips (site_id, ip, gezien_op) VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE ip = VALUES(ip), gezien_op = NOW()
    ");
    $stmt->execute([$siteId, $ip]);
}

/**
 * Slaat een handmatig ingevulde nieuwste versie op voor een feed (sinds
 * 1.29) - voor update-servers die alle geautomatiseerde verzoeken
 * weigeren, maar waarvan de beheerder de feed in zijn eigen browser wel kan
 * openen. Een lege versie wist de handmatige waarde.
 *
 * Geldt meteen ook voor andere feed-URL's van dezelfde update-server die bij
 * hetzelfde Joomla-element horen (bijv. .../updates/forms/... en
 * .../updates/baforms/...), en wordt direct doorgevoerd in de opgeslagen
 * extensielijsten van alle sites. Geeft de bijgewerkte feed-URL's terug.
 *
 * @return string[]
 */
function slaHandmatigeFeedVersieOp(PDO $pdo, string $feedUrl, ?string $versie): array
{
    zorgVoorFeedTerugvalTabel($pdo);
    $feedUrl = trim($feedUrl);
    $versie  = $versie !== null ? trim($versie) : '';
    $host    = feedHostVanUrl($feedUrl);

    // Elementen die deze feed gebruiken, en via die elementen de verwante
    // feed-URL's op dezelfde update-server.
    $stmt = $pdo->prepare("SELECT DISTINCT LOWER(element) FROM site_alle_extensies WHERE update_feed_url = ? AND package_id = 0");
    $stmt->execute([$feedUrl]);
    $elementen = array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN)));

    $urls = [$feedUrl];
    if (!empty($elementen)) {
        $plaatsen = implode(',', array_fill(0, count($elementen), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT update_feed_url FROM site_alle_extensies
            WHERE LOWER(element) IN ($plaatsen) AND package_id = 0
              AND update_feed_url IS NOT NULL AND update_feed_url != ''
        ");
        $stmt->execute($elementen);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $andereUrl) {
            if (feedHostVanUrl($andereUrl) === $host) {
                $urls[] = trim($andereUrl);
            }
        }
    }
    $urls = array_values(array_unique($urls));

    $opslaanStmt = $pdo->prepare("
        INSERT INTO feed_terugval_cache (url_hash, feed_url, host, inhoud, fout, geblokkeerd, opgehaald_op, handmatige_versie, handmatig_op)
        VALUES (?, ?, ?, NULL, 'nog niet opgehaald', 0, NOW(), ?, ?)
        ON DUPLICATE KEY UPDATE handmatige_versie = VALUES(handmatige_versie), handmatig_op = VALUES(handmatig_op)
    ");
    $nuDb = $versie !== '' ? $pdo->query("SELECT NOW()")->fetchColumn() : null;
    foreach ($urls as $url) {
        $opslaanStmt->execute([hash('sha256', $url), $url, feedHostVanUrl($url), $versie !== '' ? $versie : null, $nuDb]);
    }

    // Direct doorvoeren in de opgeslagen extensielijsten (alle sites).
    $plaatsen = implode(',', array_fill(0, count($urls), '?'));
    $rijenStmt = $pdo->prepare("
        SELECT site_id, extension_id, naam, type, element, versie, update_feed_url, auteur, package_id
        FROM site_alle_extensies
        WHERE update_feed_url IN ($plaatsen)
    ");
    $rijenStmt->execute($urls);
    $rijen = $rijenStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rijen as &$rij) {
        $rij['nieuwste_versie'] = null; // opnieuw laten bepalen
    }
    unset($rij);

    vulNieuwsteVersiesAanViaMonitor($pdo, $rijen, false);

    $updateStmt = $pdo->prepare("UPDATE site_alle_extensies SET nieuwste_versie = ? WHERE site_id = ? AND extension_id = ?");
    foreach ($rijen as $rij) {
        if (isKandidaatVoorFeedTerugval(['nieuwste_versie' => null] + $rij)) {
            $updateStmt->execute([$rij['nieuwste_versie'], $rij['site_id'], $rij['extension_id']]);
        }
    }

    return $urls;
}
