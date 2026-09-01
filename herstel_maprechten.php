<?php
// herstel_maprechten.php
//
// Zet, op expliciet verzoek van de gebruiker (nooit automatisch), de
// rechten van de ingestelde FTP-doelmap naar 755 (rwxr-xr-x) - een
// gangbare, veilige standaardwaarde. Wordt aangeboden op de
// site-instellingenpagina, maar alleen ZICHTBAAR nadat een FTP-upload is
// mislukt met een gedetecteerd maprechten-probleem (zie
// ftp_verstuur_scanscript.php).

require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: login.php");
    exit;
}
require_once 'config.php';
require_once 'csrf_functies.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['succes' => false, 'foutmelding' => 'Ongeldige aanvraag.']);
    exit;
}

vereistGeldigCsrfToken();

require_once 'versleuteling_functies.php';
require_once 'sftp_functies.php';
require_once 'ftp_rechten_functies.php';

$siteId = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;

if ($siteId <= 0) {
    echo json_encode(['succes' => false, 'foutmelding' => 'Ongeldige site.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ?");
$stmt->execute([$siteId]);
$site = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$site) {
    echo json_encode(['succes' => false, 'foutmelding' => 'Site niet gevonden.']);
    exit;
}

if (empty($site['ftp_host'])) {
    echo json_encode(['succes' => false, 'foutmelding' => 'Geen FTP-/SFTP-gegevens bekend voor deze site.']);
    exit;
}

$host       = $site['ftp_host'];
$gebruiker  = $site['ftp_gebruikersnaam'] ?? '';
$wachtwoord = ontsleutelWaarde($site['ftp_wachtwoord'] ?? '');
$pad        = trim($site['ftp_pad'] ?? '/', '/');
$doelMap    = $pad !== '' ? "/$pad" : '/';
$protocol   = ($site['ftp_protocol'] ?? 'ftp') === 'sftp' ? 'sftp' : 'ftp';

if ($protocol === 'sftp') {
    $poort = !empty($site['ftp_poort']) ? (int) $site['ftp_poort'] : 22;

    [$sftp, $foutmelding] = sftpVerbind($host, $poort, $gebruiker, $wachtwoord);
    if ($sftp === null) {
        echo json_encode(['succes' => false, 'foutmelding' => $foutmelding]);
        exit;
    }

    if (herstelMapRechtenSftp($sftp, $doelMap)) {
        echo json_encode(['succes' => true, 'melding' => "Maprechten van \"$doelMap\" zijn gezet naar 755. Probeer het scanscript nu opnieuw te versturen."]);
    } else {
        echo json_encode(['succes' => false, 'foutmelding' => "Kon de rechten van \"$doelMap\" niet aanpassen - mogelijk staat dit niet toe via de gebruikte inloggegevens, of ondersteunt de server dit niet."]);
    }
    exit;
}

$poort      = !empty($site['ftp_poort']) ? (int) $site['ftp_poort'] : 21;
$gebruikSsl = !empty($site['ftp_ssl']);

$conn = $gebruikSsl
    ? @ftp_ssl_connect($host, $poort, 10)
    : @ftp_connect($host, $poort, 10);

if ($conn === false) {
    echo json_encode(['succes' => false, 'foutmelding' => "Kon geen FTP-verbinding maken met \"$host:$poort\"."]);
    exit;
}

if (!@ftp_login($conn, $gebruiker, $wachtwoord)) {
    ftp_close($conn);
    echo json_encode(['succes' => false, 'foutmelding' => 'FTP-inloggen mislukt (controleer gebruikersnaam/wachtwoord).']);
    exit;
}

if (herstelMapRechtenFtp($conn, $doelMap)) {
    ftp_close($conn);
    echo json_encode(['succes' => true, 'melding' => "Maprechten van \"$doelMap\" zijn gezet naar 755. Probeer het scanscript nu opnieuw te versturen."]);
} else {
    ftp_close($conn);
    echo json_encode(['succes' => false, 'foutmelding' => "Kon de rechten van \"$doelMap\" niet aanpassen via FTP - niet elke hostingpartij ondersteunt het \"SITE CHMOD\"-commando. Pas de rechten dan handmatig aan via FileZilla (rechtermuisknop op de map \u2192 Bestandsrechten \u2192 755)."]);
}
