<?php
// kern_bestand_actie.php
//
// Verwerkt de twee acties vanaf bekijk_kern_afwijking.php nadat een
// beheerder een kernbestand-afwijking handmatig heeft beoordeeld:
//   - vertrouw:  markeert deze EXACTE afwijking (site + pad + hash) als
//                vertrouwd, en verwijdert 'm uit het actieve rapport.
//   - vervang:   haalt de VOLLEDIGE, officiële bestandsinhoud op en stuurt
//                die naar de site zelf, die eerst een herstelbare backup
//                maakt en het bestand dan overschrijft (zie de actie
//                'herstel_kernbestand' in scan_template.php).

require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['succes' => false, 'foutmelding' => 'Niet ingelogd.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

require_once 'config.php';
require_once 'csrf_functies.php';
require_once 'instellingen_functies.php';
require_once 'kern_integriteit_functies.php';

header('Content-Type: application/json; charset=utf-8');

if (!csrfTokenGeldig()) {
    http_response_code(403);
    echo json_encode(['succes' => false, 'foutmelding' => 'Ongeldige aanvraag (CSRF-token klopt niet). Ververs de pagina.']);
    exit;
}

// Kan bij "vervang" een download van het volledige officiële pakket vergen.
@set_time_limit(120);
@ini_set('memory_limit', '512M');

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$actie = $_POST['actie'] ?? '';

if ($id <= 0 || !in_array($actie, ['vertrouw', 'vervang'], true)) {
    echo json_encode(['succes' => false, 'foutmelding' => 'Ongeldige aanvraag.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM kern_bestand_afwijkingen WHERE id = ?");
$stmt->execute([$id]);
$afwijking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$afwijking) {
    echo json_encode(['succes' => false, 'foutmelding' => 'Deze afwijking bestaat niet (meer) - mogelijk is de vergelijking inmiddels opnieuw gedraaid.']);
    exit;
}

$siteStmt = $pdo->prepare("SELECT id, domein, scan_bestandsnaam, url_subpad FROM sites WHERE id = ?");
$siteStmt->execute([$afwijking['site_id']]);
$site = $siteStmt->fetch(PDO::FETCH_ASSOC);

if (!$site) {
    echo json_encode(['succes' => false, 'foutmelding' => 'De bijbehorende site bestaat niet meer.']);
    exit;
}

// ----------------------------------------------------------------------
// Actie: vertrouwen/negeren
// ----------------------------------------------------------------------
if ($actie === 'vertrouw') {
    $insertStmt = $pdo->prepare("
        INSERT INTO kern_vertrouwd (site_id, kernversie, relatief_pad, hash)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE toegevoegd_op = NOW()
    ");
    $insertStmt->execute([
        $afwijking['site_id'],
        $afwijking['kernversie'],
        $afwijking['relatief_pad'],
        $afwijking['eigen_hash'],
    ]);

    // BEWUST de rij in kern_bestand_afwijkingen NIET verwijderen: vertrouwen
    // is een weergave-keuze (verdwijnt uit het actieve overzicht op
    // beveiliging.php, blijft zichtbaar onder "Toon ook vertrouwde items"),
    // geen definitieve actie - anders zou deze melding nergens meer
    // bereikbaar zijn om 'm later alsnog te laten vervangen.
    echo json_encode(['succes' => true, 'melding' => 'Als vertrouwd gemarkeerd - staat voortaan onder "Toon ook vertrouwde items" op het beveiligingsrapport, tenzij dit bestand later opnieuw wijzigt.']);
    exit;
}

// ----------------------------------------------------------------------
// Actie: automatisch vervangen door het officiële bestand
// ----------------------------------------------------------------------
$officieel = haalOfficieelBestandInhoud($afwijking['kernversie'], $afwijking['relatief_pad'], true);
if (!$officieel['ok']) {
    echo json_encode(['succes' => false, 'foutmelding' => "Kon het officiële bestand niet ophalen: {$officieel['foutmelding']}"]);
    exit;
}

$geheimeCode = ontsleutelWaarde(haalInstelling($pdo, 'geheime_code', ''));
$scanBestandsnaam = bepaalScanBestandsnaam($site);
$url = bepaalSiteUrl($site, $scanBestandsnaam);

$body = [
    'geheime_code' => $geheimeCode,
    'actie' => 'herstel_kernbestand',
    'pad' => $afwijking['relatief_pad'],
    'inhoud_base64' => base64_encode($officieel['inhoud']),
];

/**
 * Zelfde curl-aanroep als in site_beheer_actie.php - bewust hier
 * gedupliceerd i.p.v. die functie te hergebruiken, om dit bestand niet
 * afhankelijk te maken van de interne opzet van dat andere bestand.
 */
function verstuurKernBeheerVerzoek(string $url, array $body): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_POSTREDIR => 7,
    ]);
    $antwoord = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlFout = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['antwoord' => $antwoord, 'curl_errno' => $curlErrno, 'curl_fout' => $curlFout, 'http_code' => $httpCode];
}

$poging = verstuurKernBeheerVerzoek($url, $body);

if ($poging['curl_errno'] !== 0) {
    echo json_encode(['succes' => false, 'foutmelding' => "Kon de site niet bereiken: {$poging['curl_fout']}"]);
    exit;
}

$resultaat = json_decode((string) $poging['antwoord'], true);

// Bij een lopend zelf-bijwerkmoment op de site (de actie "herstel_kernbestand"
// is nieuw, dus een site die zijn scanscript nog niet had bijgewerkt kende
// 'm nog niet) voert scan_template.php de actie INTERN al een keer uit via
// zijn eigen herhaalaanroep, en plakt het resultaat daarvan (gewoon geldig
// JSON) achter een platte-tekst voortgangsverslag. Die actie is dan dus al
// gelukt - het JSON-staartje eruit halen voorkomt dat we 'm hieronder nog
// een keer (dus DUBBEL) zouden uitvoeren, wat bij een kopiërende/
// overschrijvende actie als "vervangen" - anders dan bij de verplaatsende
// acties elders (quarantaine/blokkeer/verwijder, die bij een tweede poging
// gewoon niets meer vinden en stil falen) - zichtbaar tot een dubbele
// backup-regel leidde.
if (!is_array($resultaat)) {
    $laatsteAccolade = strrpos((string) $poging['antwoord'], '{');
    if ($laatsteAccolade !== false) {
        $mogelijkeJson = json_decode(substr((string) $poging['antwoord'], $laatsteAccolade), true);
        if (is_array($mogelijkeJson)) {
            $resultaat = $mogelijkeJson;
        }
    }
}

// Alleen als het JSON-staartje er ook niet uit te halen was (zeldzaam):
// als allerlaatste redmiddel alsnog een nieuwe aanroep, met het geaccepteerde
// risico dat de actie daardoor mogelijk een tweede keer wordt uitgevoerd.
if (!is_array($resultaat) && stripos((string) $poging['antwoord'], 'ZELF-BIJWERKEN') !== false) {
    $herhaaldePoging = verstuurKernBeheerVerzoek($url, $body);
    if ($herhaaldePoging['curl_errno'] === 0) {
        $herhaaldResultaat = json_decode((string) $herhaaldePoging['antwoord'], true);
        if (is_array($herhaaldResultaat)) {
            $poging = $herhaaldePoging;
            $resultaat = $herhaaldResultaat;
        }
    }
}

if (!is_array($resultaat)) {
    $ruweRespons = trim((string) $poging['antwoord']);
    $ruweRespons = strip_tags($ruweRespons);
    $ruweRespons = preg_replace('/\s+/', ' ', $ruweRespons);
    $fragment = $ruweRespons !== '' ? substr($ruweRespons, 0, 200) : '(lege respons)';
    echo json_encode([
        'succes' => false,
        'foutmelding' => "Onverwacht antwoord van de site (HTTP {$poging['http_code']}). Ontvangen inhoud: \"$fragment\"",
    ]);
    exit;
}

if (empty($resultaat['succes'])) {
    echo json_encode(['succes' => false, 'foutmelding' => $resultaat['foutmelding'] ?? 'Vervangen is mislukt.']);
    exit;
}

// Geslaagd: het bestand op de site is nu gelijk aan het officiële pakket -
// de melding kan uit het actieve rapport.
$verwijderStmt = $pdo->prepare("DELETE FROM kern_bestand_afwijkingen WHERE id = ?");
$verwijderStmt->execute([$id]);

// Eventuele eerdere vertrouwd-markering(en) voor dit pad op deze site
// opruimen: die sloegen op de OUDE, inmiddels vervangen inhoud. Zonder dit
// zou de "✅ X vertrouwd"-teller op index.php dit als opgelost beschouwde
// bestand voor altijd blijven meetellen, ook al bestaat de onderliggende
// afwijking niet meer.
$opschoonStmt = $pdo->prepare("DELETE FROM kern_vertrouwd WHERE site_id = ? AND relatief_pad = ?");
$opschoonStmt->execute([$afwijking['site_id'], $afwijking['relatief_pad']]);

echo json_encode(['succes' => true, 'melding' => $resultaat['melding'] ?? 'Bestand vervangen door de officiële versie.']);
