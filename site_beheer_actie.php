<?php
// site_beheer_actie.php
//
// Stuurt een beheeractie (bekijk/quarantaine/blokkeer/verwijder/herstel/
// definitief/prullenbak_legen/status) door naar scan-en-check-website.php
// op de site zelf, en verwerkt het antwoord. Wordt aangeroepen vanuit
// beveiliging.php, met de site_id + geheime code van de monitor als
// authenticatie naar de gebruiker toe - de site zelf wordt vervolgens
// aangesproken met de (versleuteld opgeslagen) geheime scancode, exact
// zoals bij een normale scan.
//
// Bij een geslaagde quarantaine/blokkeer/verwijder-actie wordt de
// betreffende vondst meteen uit de opgeslagen scanresultaten van deze site
// verwijderd, zodat het beveiligingsrapport direct klopt - zonder op de
// volgende volledige scan te hoeven wachten.

require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: login.php");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}
require_once 'config.php';
require_once 'csrf_functies.php';
require_once 'instellingen_functies.php';
require_once 'verdacht_functies.php';

vereistGeldigCsrfToken();

header('Content-Type: application/json; charset=utf-8');

$siteId = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;
$actie  = $_POST['actie'] ?? '';
$toegestaneActies = ['status', 'bekijk', 'quarantaine', 'blokkeer', 'verwijder', 'herstel', 'definitief', 'prullenbak_legen'];

if ($siteId <= 0 || !in_array($actie, $toegestaneActies, true)) {
    echo json_encode(['succes' => false, 'foutmelding' => 'Ongeldige aanvraag.']);
    exit;
}

$stmt = $pdo->prepare("SELECT domein, scan_bestandsnaam, url_subpad FROM sites WHERE id = ?");
$stmt->execute([$siteId]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$site) {
    echo json_encode(['succes' => false, 'foutmelding' => 'Site niet gevonden.']);
    exit;
}

$geheimeCode = ontsleutelWaarde(haalInstelling($pdo, 'geheime_code', ''));

$body = [
    'geheime_code' => $geheimeCode,
    'actie' => $actie,
];
// Alleen de relevante velden meesturen, afhankelijk van de actie.
if (in_array($actie, ['bekijk', 'quarantaine', 'blokkeer', 'verwijder'], true)) {
    $body['pad'] = $_POST['pad'] ?? '';
}
if (in_array($actie, ['herstel', 'definitief'], true)) {
    $body['id'] = $_POST['id'] ?? '';
}

$scanBestandsnaam = bepaalScanBestandsnaam($site);
$url = bepaalSiteUrl($site, $scanBestandsnaam);

/**
 * Stuurt het beheerverzoek naar het scanscript op de site en geeft de
 * ruwe respons + curl-metadata terug (geen JSON-decodering hier, dat
 * gebeurt door de aanroeper).
 */
function verstuurBeheerVerzoek(string $url, array $body): array
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
        // Zonder deze drie volgt curl een 301/302-omleiding (bijv. http naar
        // https, of een www/non-www-omleiding) helemaal niet - het beheer-
        // verzoek kwam dan nooit bij het echte scanscript aan, maar bij de
        // omleidingspagina zelf ("301 Moved Permanently..."), wat precies de
        // verwarrende "Onverwacht antwoord (HTTP 301)"-melding veroorzaakte.
        // CURLOPT_POSTREDIR is daarbij net zo belangrijk als FOLLOWLOCATION
        // zelf: zonder die optie zet curl een POST bij een omleiding
        // standaard stilzwijgend om in een kale GET, waardoor de geheime
        // code en de actie (bv. "quarantaine") alsnog verloren zouden gaan,
        // ook al wordt de omleiding dan wél gevolgd. Waarde 7 = de omleiding
        // blijft een POST bij alle drie de omleidingscodes waarbij dat kan
        // spelen (301, 302 en 303).
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

$poging = verstuurBeheerVerzoek($url, $body);

if ($poging['curl_errno'] !== 0) {
    echo json_encode(['succes' => false, 'foutmelding' => "Kon de site niet bereiken: {$poging['curl_fout']}"]);
    exit;
}

$resultaat = json_decode($poging['antwoord'], true);

// Stille herhaalpoging bij een lopend zelf-bijwerkmoment op de site: als
// het scanscript daar de meegestuurde actie nog niet kende (bv. een net
// uitgerolde, nieuwe actie), werkt het zichzelf eerst bij en
// stuurt het als platte tekst (met "ZELF-BIJWERKEN" erin) een verslag van
// dát proces terug, in plaats van het JSON-antwoord dat wij verwachten -
// de herhaalde aanroep die het scanscript intern al deed, gebruikte
// namelijk nog de op dat moment lopende (oude) code, die de oorspronkelijke
// POST-gegevens niet kon doorgeven (zie de toelichting in scan_template.php
// zelf, bij ZELF-BIJWERKEN).
//
// WIJ, hier in site_beheer_actie.php, hoeven zelf nergens op bij te werken
// - dus kunnen we het exacte verzoek gewoon nog één keer overdoen. Die
// tweede aanroep raakt gegarandeerd de inmiddels bijgewerkte code op de
// site, en levert dus meteen het juiste JSON-antwoord - zonder dat de
// gebruiker eerst een verwarrende foutmelding te zien krijgt en de knop
// zelf nog een keer moet indrukken.
if (!is_array($resultaat) && stripos((string) $poging['antwoord'], 'ZELF-BIJWERKEN') !== false) {
    $herhaaldePoging = verstuurBeheerVerzoek($url, $body);
    if ($herhaaldePoging['curl_errno'] === 0) {
        $herhaaldResultaat = json_decode($herhaaldePoging['antwoord'], true);
        if (is_array($herhaaldResultaat)) {
            $poging = $herhaaldePoging;
            $resultaat = $herhaaldResultaat;
        }
    }
}

$antwoord = $poging['antwoord'];
$httpCode = $poging['http_code'];

if (!is_array($resultaat)) {
    $ruweRespons = trim((string) $antwoord);
    $ruweRespons = strip_tags($ruweRespons); // HTML-opmaak van een eventuele foutpagina (WAF/Apache) weghalen, alleen de leesbare tekst overhouden
    $ruweRespons = preg_replace('/\s+/', ' ', $ruweRespons);
    $fragment = $ruweRespons !== '' ? substr($ruweRespons, 0, 200) : '(lege respons)';

    // "Request forbidden by administrative rules" is de vaste, herkenbare
    // tekst van mod_security - een firewall die hostingpartijen zelf op
    // serverniveau instellen. Dat verzoek wordt dan al geblokkeerd vóórdat
    // het bij Joomla of ons eigen scanscript aankomt - geen instelling die
    // wij vanuit de monitor kunnen omzeilen, dus hier krijgt de gebruiker
    // meteen gerichte uitleg i.p.v. de generieke "onverwacht antwoord"-tekst.
    if ($httpCode === 403 && stripos($ruweRespons, 'administrative rules') !== false) {
        echo json_encode([
            'succes' => false,
            'foutmelding' => 'Deze actie werd geblokkeerd door mod_security - een firewall die de hostingpartij zelf op '
                . 'serverniveau heeft ingesteld, los van deze monitor of van Joomla. Dit is geen instelling die we '
                . 'vanuit hier kunnen omzeilen. Neem contact op met de hostingpartij van deze site en vraag of ze een '
                . "uitzondering kunnen maken voor POST-verzoeken naar {$scanBestandsnaam}. Tot die tijd kun je "
                . 'dit bestand gewoon handmatig via FTP verwijderen.',
        ]);
        exit;
    }

    echo json_encode([
        'succes' => false,
        'foutmelding' => "Onverwacht antwoord van de site (HTTP $httpCode). Staat {$scanBestandsnaam} daar nog in de meest recente versie? "
            . "Ontvangen inhoud: \"$fragment\"",
    ]);
    exit;
}

// Bij een geslaagde quarantaine/blokkeer/verwijder-actie: de vondst meteen
// uit de opgeslagen scanresultaten van deze site verwijderen, zodat het
// beveiligingsrapport direct klopt.
if (!empty($resultaat['succes']) && in_array($actie, ['quarantaine', 'blokkeer', 'verwijder'], true) && !empty($resultaat['naam'])) {
    verwijderVondstUitOpslag($pdo, $siteId, $resultaat['naam']);
}

echo json_encode($resultaat);
