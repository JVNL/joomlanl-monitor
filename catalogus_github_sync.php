<?php
/**
 * catalogus_github_sync.php
 *
 * Synchroniseert de tabel `extensie_catalogus` (update-feed-URL's per
 * extensie) met een gedeeld JSON-bestand in een Github-repo, zodat
 * meerdere LOSSE installaties (elk met hun eigen database) dezelfde
 * catalogus kunnen delen zonder een gedeelde database.
 *
 * Lezen kan altijd (ook zonder token, op een publieke repo - Github
 * staat dat toe, met een lagere rate-limit). Schrijven (pushen) kan
 * alleen met een token dat "Contents: Read and write" heeft op de
 * betreffende repo.
 *
 * Bewust GEEN automatische overschrijving bij het importeren: nieuwe of
 * gewijzigde items worden pas na een bewuste klik overgenomen (zie
 * vergelijkCatalogusMetGithub() / importeerUitGithub()), zodat een
 * foutieve URL van de ander niet zomaar een eigen werkende URL
 * overschrijft.
 */

require_once __DIR__ . '/instellingen_functies.php';
require_once __DIR__ . '/versleuteling_functies.php';

/**
 * Haalt de Github-synchronisatie-instellingen op. 'token' is al ontsleuteld.
 */
// Vaste, gedeelde repo voor de catalogus - niet per installatie instelbaar
// (voorkomt tikfouten, en de gemiddelde gebruiker heeft hier toch niets
// aan te wijzigen). Alleen het token is nog per installatie eigen, omdat
// dat per definitie persoonlijk/geheim is en nooit gedeeld wordt.
const CATALOGUS_GITHUB_REPO   = 'JVNL/joomlanl-monitor-catalogus';
const CATALOGUS_GITHUB_BRANCH = 'main';
const CATALOGUS_GITHUB_PAD    = 'catalogus.json';

function catalogusGithubInstellingen(PDO $pdo): array
{
    return [
        'repo'   => CATALOGUS_GITHUB_REPO,
        'token'  => ontsleutelWaarde(haalInstelling($pdo, 'catalogus_github_token', '')),
        'pad'    => CATALOGUS_GITHUB_PAD,
        'branch' => CATALOGUS_GITHUB_BRANCH,
    ];
}

function catalogusGithubIsIngesteld(array $instellingen): bool
{
    return $instellingen['repo'] !== '' && strpos($instellingen['repo'], '/') !== false;
}

function catalogusGithubContentsUrl(array $instellingen): string
{
    return 'https://api.github.com/repos/' . $instellingen['repo'] . '/contents/' . $instellingen['pad']
        . '?ref=' . rawurlencode($instellingen['branch']);
}

/**
 * Kleine cURL-wrapper voor de Github API. Geeft altijd een array terug
 * met 'status' (HTTP-code, 0 bij een verbindingsfout), 'data' (het
 * gedecodeerde JSON-antwoord, of null) en 'fout' (alleen gevuld bij een
 * verbindingsfout, NIET bij een normale HTTP-foutstatus zoals 404).
 */
function catalogusGithubAanvraag(string $methode, string $url, ?array $body, string $token): array
{
    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: MijnWebsitesMonitor',
        'X-Github-Api-Version: 2022-11-28',
    ];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $methode);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $antwoord   = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlFout   = curl_error($ch);
    curl_close($ch);

    if ($antwoord === false) {
        return ['status' => 0, 'data' => null, 'fout' => $curlFout ?: 'Onbekende verbindingsfout.'];
    }

    return ['status' => $statusCode, 'data' => json_decode($antwoord, true), 'fout' => null];
}

/**
 * Haalt het huidige catalogus.json-bestand van Github op.
 * 'gevonden' => false + 'fout' => null betekent: bestand bestaat nog
 * niet (prima bij de allereerste push).
 */
function haalCatalogusBestandOpVanGithub(array $instellingen): array
{
    $leeg = ['gevonden' => false, 'sha' => null, 'items' => [], 'bijgewerkt_op' => null, 'bijgewerkt_door' => null, 'fout' => null];

    if (!catalogusGithubIsIngesteld($instellingen)) {
        $leeg['fout'] = 'Er is nog geen Github-repo ingesteld bij Configuratie.';
        return $leeg;
    }

    $resultaat = catalogusGithubAanvraag('GET', catalogusGithubContentsUrl($instellingen), null, $instellingen['token']);

    if ($resultaat['fout'] !== null) {
        $leeg['fout'] = 'Verbinden met Github is mislukt: ' . $resultaat['fout'];
        return $leeg;
    }
    if ($resultaat['status'] === 404) {
        return $leeg; // bestand bestaat nog niet - de eerste push maakt het aan
    }
    if ($resultaat['status'] !== 200 || !isset($resultaat['data']['content'])) {
        $melding = $resultaat['data']['message'] ?? ('onbekende fout (HTTP ' . $resultaat['status'] . ')');
        $leeg['fout'] = 'Github gaf een onverwachte fout terug: ' . $melding;
        return $leeg;
    }

    $ruweInhoud = base64_decode(str_replace("\n", '', $resultaat['data']['content']));
    $json       = json_decode($ruweInhoud, true);

    if (!is_array($json) || !isset($json['extensies']) || !is_array($json['extensies'])) {
        $leeg['gevonden'] = true;
        $leeg['sha']      = $resultaat['data']['sha'] ?? null;
        $leeg['fout']     = 'Het bestand op Github kon niet worden gelezen (onverwachte inhoud).';
        return $leeg;
    }

    return [
        'gevonden'        => true,
        'sha'             => $resultaat['data']['sha'] ?? null,
        'items'           => $json['extensies'],
        'bijgewerkt_op'   => $json['bijgewerkt_op'] ?? null,
        'bijgewerkt_door' => $json['bijgewerkt_door'] ?? null,
        'fout'            => null,
    ];
}

/**
 * De lokale catalogus, in exact de vorm die ook naar Github gaat. Alleen
 * rijen MET een ingevulde update-feed-URL worden gedeeld - een sleutel
 * zonder feed-URL heeft niets te synchroniseren. "Genegeerd" en "alleen
 * x.xx.y negeren" zijn bewust NIET meegenomen: dat zijn per-installatie-
 * voorkeuren, geen eigenschap van de feed zelf.
 */
function lokaleCatalogusAlsArray(PDO $pdo): array
{
    // Rijen met feed_lokaal = 1 (via "Opslaan zonder GitHub Sync") worden
    // BEWUST hier uitgesloten: dit is de enige plek waar de lokale
    // catalogus wordt omgezet naar wat er naar Github gepusht wordt, dus
    // hier moet de uitsluiting gegarandeerd gelden - ongeacht via welke
    // andere rij een push wordt getriggerd (een push stuurt altijd de
    // VOLLEDIGE catalogus in één keer mee, zie pushCatalogusNaarGithub()).
    $stmt = $pdo->query("
        SELECT sleutel, label, manifest_pad, update_feed_url
        FROM extensie_catalogus
        WHERE update_feed_url IS NOT NULL AND update_feed_url != ''
          AND (feed_lokaal IS NULL OR feed_lokaal = 0)
        ORDER BY sleutel ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Pusht de lokale catalogus naar Github (maakt het bestand aan bij de
 * eerste keer, werkt het anders bij). $eigenNaam is puur informatief
 * (komt in het bestand te staan als "wie heeft dit voor het laatst
 * bijgewerkt") en mag leeg zijn.
 */
function pushCatalogusNaarGithub(PDO $pdo, string $eigenNaam = ''): array
{
    $instellingen = catalogusGithubInstellingen($pdo);

    if (!catalogusGithubIsIngesteld($instellingen)) {
        return ['succes' => false, 'foutmelding' => 'Er is nog geen Github-repo ingesteld bij Configuratie.'];
    }
    if ($instellingen['token'] === '') {
        return ['succes' => false, 'foutmelding' => 'Er is geen Github-token ingesteld bij Configuratie - zonder token kan er niet geschreven worden.'];
    }

    $huidig = haalCatalogusBestandOpVanGithub($instellingen);
    if ($huidig['fout'] !== null) {
        return ['succes' => false, 'foutmelding' => $huidig['fout']];
    }

    $inhoud = [
        'bijgewerkt_op'   => gmdate('Y-m-d\TH:i:s\Z'),
        'bijgewerkt_door' => $eigenNaam,
        'extensies'       => lokaleCatalogusAlsArray($pdo),
    ];
    $json = json_encode($inhoud, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $body = [
        'message' => 'Catalogus bijgewerkt via Mijn Websites Monitor' . ($eigenNaam !== '' ? " (door {$eigenNaam})" : ''),
        'content' => base64_encode($json),
        'branch'  => $instellingen['branch'],
    ];
    if ($huidig['gevonden'] && $huidig['sha']) {
        $body['sha'] = $huidig['sha'];
    }

    $resultaat = catalogusGithubAanvraag('PUT', catalogusGithubContentsUrl($instellingen), $body, $instellingen['token']);

    if ($resultaat['fout'] !== null) {
        return ['succes' => false, 'foutmelding' => 'Verbinden met Github is mislukt: ' . $resultaat['fout']];
    }
    if (!in_array($resultaat['status'], [200, 201], true)) {
        $melding = $resultaat['data']['message'] ?? ('onbekende fout (HTTP ' . $resultaat['status'] . ')');
        return ['succes' => false, 'foutmelding' => 'Github gaf een fout terug: ' . $melding];
    }

    return ['succes' => true, 'foutmelding' => null];
}

/**
 * Vergelijkt de Github-catalogus met de lokale catalogus. Geeft de
 * items terug die lokaal ontbreken ('nieuw') en items waarvan de
 * update-feed-URL op Github afwijkt van de lokale waarde ('gewijzigd') -
 * beide bewust ONGEIMPORTEERD, dat gebeurt pas via importeerUitGithub().
 */
function vergelijkCatalogusMetGithub(PDO $pdo): array
{
    $leeg = ['fout' => null, 'nieuw' => [], 'gewijzigd' => [], 'bijgewerkt_door' => null, 'bijgewerkt_op' => null];

    $instellingen = catalogusGithubInstellingen($pdo);
    if (!catalogusGithubIsIngesteld($instellingen)) {
        return $leeg;
    }

    $remote = haalCatalogusBestandOpVanGithub($instellingen);
    if ($remote['fout'] !== null) {
        $leeg['fout'] = $remote['fout'];
        return $leeg;
    }

    $lokaalPerSleutel  = [];
    $bewustLokaleSleutels = [];
    foreach ($pdo->query("SELECT sleutel, update_feed_url, feed_lokaal FROM extensie_catalogus")->fetchAll(PDO::FETCH_ASSOC) as $rij) {
        if ((int) ($rij['feed_lokaal'] ?? 0) === 1) {
            // Bewust lokaal - nooit meenemen in de Github-vergelijking
            // (dus ook nooit per ongeluk als "nieuw" of "gewijzigd" te
            // importeren/overschrijven), ongeacht of Github toevallig ook
            // een waarde voor deze sleutel heeft.
            $bewustLokaleSleutels[$rij['sleutel']] = true;
            continue;
        }
        $lokaalPerSleutel[$rij['sleutel']] = $rij['update_feed_url'];
    }

    $nieuw     = [];
    $gewijzigd = [];
    foreach ($remote['items'] as $item) {
        $sleutel = $item['sleutel'] ?? '';
        if ($sleutel === '' || isset($bewustLokaleSleutels[$sleutel])) {
            continue;
        }

        if (!array_key_exists($sleutel, $lokaalPerSleutel)) {
            $nieuw[] = $item;
        } elseif ((string) $lokaalPerSleutel[$sleutel] !== (string) ($item['update_feed_url'] ?? '')) {
            $gewijzigd[] = [
                'sleutel'    => $sleutel,
                'label'      => $item['label'] ?? $sleutel,
                'lokale_url' => (string) $lokaalPerSleutel[$sleutel],
                'github_url' => (string) ($item['update_feed_url'] ?? ''),
            ];
        }
    }

    return [
        'fout'            => null,
        'nieuw'           => $nieuw,
        'gewijzigd'       => $gewijzigd,
        'bijgewerkt_door' => $remote['bijgewerkt_door'],
        'bijgewerkt_op'   => $remote['bijgewerkt_op'],
    ];
}

/**
 * Importeert de opgegeven sleutels (uit 'nieuw' en/of 'gewijzigd') vanuit
 * Github naar de lokale catalogus. Geeft het aantal geimporteerde items terug.
 */
function importeerUitGithub(PDO $pdo, array $sleutelsOmTeImporteren): int
{
    if (empty($sleutelsOmTeImporteren)) {
        return 0;
    }

    $instellingen = catalogusGithubInstellingen($pdo);
    $remote       = haalCatalogusBestandOpVanGithub($instellingen);
    if ($remote['fout'] !== null) {
        return 0;
    }

    $perSleutel = [];
    foreach ($remote['items'] as $item) {
        if (isset($item['sleutel']) && $item['sleutel'] !== '') {
            $perSleutel[$item['sleutel']] = $item;
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO extensie_catalogus (sleutel, label, manifest_pad, update_feed_url, feed_lokaal)
        VALUES (?, ?, ?, ?, 0)
        ON DUPLICATE KEY UPDATE
            label = VALUES(label),
            manifest_pad = VALUES(manifest_pad),
            update_feed_url = VALUES(update_feed_url),
            feed_lokaal = 0
    ");

    $aantal = 0;
    foreach ($sleutelsOmTeImporteren as $sleutel) {
        if (!isset($perSleutel[$sleutel])) {
            continue;
        }

        // Vangnet, ook al zou de banner deze sleutel al niet meer moeten
        // aanbieden (zie vergelijkCatalogusMetGithub()): een bewust-lokale
        // rij mag hier nooit overschreven worden, ongeacht via welk pad
        // deze functie wordt aangeroepen.
        $huidigeFeedLokaalStmt = $pdo->prepare("SELECT feed_lokaal FROM extensie_catalogus WHERE sleutel = ?");
        $huidigeFeedLokaalStmt->execute([$sleutel]);
        if ((int) $huidigeFeedLokaalStmt->fetchColumn() === 1) {
            continue;
        }

        $item = $perSleutel[$sleutel];
        $stmt->execute([
            $sleutel,
            $item['label'] ?? $sleutel,
            $item['manifest_pad'] ?? null,
            $item['update_feed_url'] ?? null,
        ]);
        $aantal++;
    }

    // Na een import is de eerder opgehaalde "nieuwste versie" voor deze
    // sleutels mogelijk verouderd (afkomstig van de oude/andere feed-URL) -
    // opruimen zodat de volgende scan gegarandeerd met de nieuwe URL controleert.
    if ($aantal > 0) {
        $verwijderStmt = $pdo->prepare("DELETE FROM nieuwste_versies WHERE naam = ?");
        foreach ($sleutelsOmTeImporteren as $sleutel) {
            if (isset($perSleutel[$sleutel])) {
                $verwijderStmt->execute([$sleutel]);
            }
        }
    }

    return $aantal;
}
