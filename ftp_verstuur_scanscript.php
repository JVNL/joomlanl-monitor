<?php
// ftp_verstuur_scanscript.php
// Genereert het scanscript en zet het via FTP rechtstreeks op de site(s)
// neer, zodat je niet meer handmatig hoeft te downloaden/uploaden. Geef
// ?site_id=X mee voor één specifieke site, of laat leeg om het naar ALLE
// sites met ingevulde FTP-gegevens te sturen.

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

vereistGeldigCsrfToken();

require_once 'genereer_scanscript_functies.php';
require_once 'sftp_functies.php';
require_once 'instellingen_functies.php';
require_once 'ftp_rechten_functies.php';

header('Content-Type: text/plain; charset=utf-8');

$siteId = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;

if ($siteId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ?");
    $stmt->execute([$siteId]);
    $rij = $stmt->fetch(PDO::FETCH_ASSOC);
    $sites = $rij ? [$rij] : [];
} else {
    $sites = $pdo->query("
        SELECT * FROM sites
        WHERE ftp_host IS NOT NULL AND ftp_host != ''
        ORDER BY domein ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

if (empty($sites)) {
    echo "Geen site(s) gevonden met ingevulde FTP-gegevens.\n";
    exit;
}

function verstuurNaarSiteViaSftp(PDO $pdo, array $site): string
{
    $domein = $site['domein'];

    $host       = $site['ftp_host'];
    $poort      = !empty($site['ftp_poort']) ? (int) $site['ftp_poort'] : 22;
    $gebruiker  = $site['ftp_gebruikersnaam'] ?? '';
    $wachtwoord = ontsleutelWaarde($site['ftp_wachtwoord'] ?? '');
    $pad        = trim($site['ftp_pad'] ?? '/', '/');

    try {
        $inhoud = genereerScanScriptInhoud($pdo, $site);
    } catch (RuntimeException $e) {
        return "❌ $domein: kon scanscript niet genereren - " . $e->getMessage();
    }

    [$sftp, $foutmelding] = sftpVerbind($host, $poort, $gebruiker, $wachtwoord);
    if ($sftp === null) {
        return "❌ $domein: $foutmelding";
    }

    $bestandsnaam = bepaalScanBestandsnaam($site);
    $remotePad = $pad !== '' ? "/$pad/$bestandsnaam" : "/$bestandsnaam";

    if (!sftpUploadInhoud($sftp, $remotePad, $inhoud)) {
        $doelMap = $pad !== '' ? "/$pad" : '/';
        $rechtenDiagnose = diagnoseerMapRechtenSftp($sftp, $doelMap);

        return "❌ $domein: uploaden naar \"$remotePad\" via SFTP is mislukt."
            . ($rechtenDiagnose !== null ? " $rechtenDiagnose" : ' (controleer het ingestelde pad en de maprechten.)');
    }

    registreerVerstuurdScanBestand($pdo, (int) $site['id'], $bestandsnaam);

    return "✅ $domein: het scanscript is met SFTP op de server van de website geplaatst.";
}

/**
 * Fallback-upload via curl, specifiek voor het geval dat de reguliere
 * ftp_fput() faalt door een verkeerd/onbereikbaar IP-adres in de PASV-
 * respons van de server (een bekend probleem bij sommige Plesk-hosts,
 * waar de "masquerade address" niet goed staat ingesteld). FileZilla
 * corrigeert dit automatisch; PHP's ftp-extensie doet dat niet.
 *
 * CURLOPT_FTP_SKIP_PASV_IP negeert het IP dat de server teruggeeft bij
 * PASV en gebruikt in plaats daarvan gewoon het host-adres waarmee al
 * verbonden is - dat lost precies dit scenario op.
 *
 * Geeft bij een fout een leesbare foutmelding terug (string), of null
 * als het gelukt is.
 */
function ftpUploadViaCurlFallback(
    string $host,
    int $poort,
    string $gebruiker,
    string $wachtwoord,
    string $remotePad,
    string $inhoud,
    bool $gebruikSsl
): ?string {
    if (!function_exists('curl_init')) {
        return 'curl-extensie is niet beschikbaar op deze server.';
    }

    // Bewust altijd "ftp://" (nooit "ftps://") als schema: het "ftps://"-
    // schema laat curl een IMPLICIETE TLS-verbinding verwachten (meteen
    // vanaf de eerste byte, zoals bij poort 990) - bij de gangbare FTPS op
    // poort 21 gebruikt de server echter EXPLICIETE TLS (eerst een gewoon,
    // onversleuteld welkomstbericht, pas daarna een "AUTH TLS"-upgrade).
    // CURLOPT_FTP_SSL hieronder regelt die juiste, expliciete upgrade zelf.
    $url = 'ftp://' . $host . ':' . $poort . $remotePad;

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $inhoud);
    rewind($stream);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL             => $url,
        CURLOPT_USERPWD         => $gebruiker . ':' . $wachtwoord,
        CURLOPT_UPLOAD          => true,
        CURLOPT_INFILE          => $stream,
        CURLOPT_INFILESIZE      => strlen($inhoud),
        CURLOPT_FTP_SKIP_PASV_IP => true,
        CURLOPT_CONNECTTIMEOUT  => 10,
        CURLOPT_TIMEOUT         => 30,
        CURLOPT_FTPSSLAUTH      => CURLFTPAUTH_TLS,
        // Certificaatverificatie uitgeschakeld: dit is een TERUGVAL (wordt
        // alleen geprobeerd als de gewone methode al faalde), naar een
        // server-adres dat de gebruiker zelf heeft ingevuld (geen
        // willekeurige derde partij) - sommige hostingpartijen leveren een
        // onvolledige certificaatketen of missen een actuele CA-bundel,
        // wat verificatie anders altijd zou laten mislukken, ook bij een
        // op zich prima werkende, versleutelde verbinding.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    if ($gebruikSsl) {
        curl_setopt($ch, CURLOPT_FTP_SSL, CURLFTPSSL_ALL);
    }

    $gelukt = curl_exec($ch);
    $curlFoutmelding = $gelukt === false ? curl_error($ch) : null;

    curl_close($ch);

    if ($gelukt === false) {
        // Laatste redmiddel: actieve modus proberen. Bij passieve modus
        // (hierboven) moet de monitor zelf naar een willekeurige, hoge
        // poort op de FTP-server verbinden - blokkeert de hostingpartij
        // van de MONITOR zelf uitgaand verkeer naar zulke poorten (een
        // niet ongebruikelijke beperking), dan mislukt dat altijd. Bij
        // actieve modus verbindt de FTP-server juist terug naar de
        // monitor - een wezenlijk ander mechanisme, dus de moeite waard
        // om te proberen, al is er geen garantie (sommige hostingpartijen
        // blokkeren ook inkomend verkeer op willekeurige poorten).
        rewind($stream);
        $chActief = curl_init();
        curl_setopt_array($chActief, [
            CURLOPT_URL              => $url,
            CURLOPT_USERPWD          => $gebruiker . ':' . $wachtwoord,
            CURLOPT_UPLOAD           => true,
            CURLOPT_INFILE           => $stream,
            CURLOPT_INFILESIZE       => strlen($inhoud),
            CURLOPT_FTPPORT          => '-', // activeert actieve modus (curl kiest zelf een lokale poort)
            CURLOPT_CONNECTTIMEOUT   => 10,
            CURLOPT_TIMEOUT          => 30,
            CURLOPT_FTPSSLAUTH       => CURLFTPAUTH_TLS,
            CURLOPT_SSL_VERIFYPEER   => false,
            CURLOPT_SSL_VERIFYHOST   => false,
        ]);
        if ($gebruikSsl) {
            curl_setopt($chActief, CURLOPT_FTP_SSL, CURLFTPSSL_ALL);
        }

        $geluktActief = curl_exec($chActief);
        $curlFoutmeldingActief = $geluktActief === false ? curl_error($chActief) : null;
        curl_close($chActief);
        fclose($stream);

        if ($geluktActief === false) {
            return 'curl-fallback (PASV-IP genegeerd) is mislukt: ' . $curlFoutmelding
                . '; curl-fallback (actieve modus) is ook mislukt: ' . $curlFoutmeldingActief;
        }

        return null;
    }

    fclose($stream);

    return null;
}

function verstuurNaarSiteViaFtp(PDO $pdo, array $site): string
{
    $domein = $site['domein'];

    if (empty($site['ftp_host'])) {
        return "❌ $domein: geen server ingesteld - overgeslagen.";
    }

    // SFTP is een compleet ander protocol dan FTP/FTPS - apart afgehandeld.
    if (($site['ftp_protocol'] ?? 'ftp') === 'sftp') {
        return verstuurNaarSiteViaSftp($pdo, $site);
    }

    $host       = $site['ftp_host'];
    $poort      = !empty($site['ftp_poort']) ? (int) $site['ftp_poort'] : 21;
    $gebruiker  = $site['ftp_gebruikersnaam'] ?? '';
    $wachtwoord = ontsleutelWaarde($site['ftp_wachtwoord'] ?? '');
    $pad        = trim($site['ftp_pad'] ?? '/', '/');
    $gebruikSsl = !empty($site['ftp_ssl']);

    try {
        $inhoud = genereerScanScriptInhoud($pdo, $site);
    } catch (RuntimeException $e) {
        return "❌ $domein: kon scanscript niet genereren - " . $e->getMessage();
    }

    $conn = $gebruikSsl
        ? @ftp_ssl_connect($host, $poort, 10)
        : @ftp_connect($host, $poort, 10);

    if ($conn === false) {
        return "❌ $domein: kon geen FTP-verbinding maken met $host:$poort.";
    }

    if (!@ftp_login($conn, $gebruiker, $wachtwoord)) {
        ftp_close($conn);
        return "❌ $domein: FTP-inloggen mislukt (controleer gebruikersnaam/wachtwoord).";
    }

    // Passieve modus, want de meeste (gedeelde) hosting werkt daar alleen mee.
    ftp_pasv($conn, true);

    $bestandsnaam = bepaalScanBestandsnaam($site);
    $remotePad = $pad !== '' ? "/$pad/$bestandsnaam" : "/$bestandsnaam";

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $inhoud);
    rewind($stream);

    $gelukt = @ftp_fput($conn, $remotePad, $stream, FTP_BINARY);

    fclose($stream);

    if (!$gelukt) {
        // Kan duiden op een verkeerd/onbereikbaar IP in de PASV-respons
        // van de server (zie ftpUploadViaCurlFallback()) - probeer dat
        // eerst voordat we de rechten-diagnose erbij halen en opgeven.
        $curlFoutmelding = ftpUploadViaCurlFallback(
            $host,
            $poort,
            $gebruiker,
            $wachtwoord,
            $remotePad,
            $inhoud,
            $gebruikSsl
        );

        if ($curlFoutmelding === null) {
            ftp_close($conn);
            registreerVerstuurdScanBestand($pdo, (int) $site['id'], $bestandsnaam);

            return "✅ $domein: het scanscript is met FTP op de server van de website geplaatst "
                . "(via curl-fallback, mogelijk PASV-IP-probleem bij deze host).";
        }

        $doelMap = $pad !== '' ? "/$pad" : '/';
        $rechtenDiagnose = diagnoseerMapRechtenFtp($conn, $doelMap);
        ftp_close($conn);

        return "❌ $domein: uploaden naar \"$remotePad\" is mislukt."
            . ($rechtenDiagnose !== null ? " $rechtenDiagnose" : ' (controleer het ingestelde pad en de maprechten.)')
            . " [$curlFoutmelding]";
    }

    ftp_close($conn);

    registreerVerstuurdScanBestand($pdo, (int) $site['id'], $bestandsnaam);

    return "✅ $domein: het scanscript is met FTP op de server van de website geplaatst.";
}

if ($siteId > 0) {
    // Verzoek voor precies één site (zie verstuurFtpAlleSites() in
    // configuratie.php, dat bewust per site apart een verzoek doet i.p.v.
    // alles in één keer) - dan ook alleen die ene resultaatregel
    // teruggeven, zonder kop-/voettekst.
    echo verstuurNaarSiteViaFtp($pdo, $sites[0]);
    exit;
}

echo "=== FTP-verzending gestart: " . date('Y-m-d H:i:s') . " ===\n\n";

foreach ($sites as $site) {
    echo verstuurNaarSiteViaFtp($pdo, $site) . "\n";
}

echo "\n=== Klaar ===\n";
