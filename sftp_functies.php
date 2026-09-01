<?php
// sftp_functies.php
//
// SFTP-tegenhanger van de bestaande FTP-functionaliteit. SFTP is een
// compleet ander protocol dan FTP/FTPS (gebaseerd op SSH, niet op FTP) -
// PHP's ingebouwde ftp_*-functies kunnen dit niet aan. Deze functies
// gebruiken daarom de phpseclib-library (zie phpseclib_autoload.php),
// een pure-PHP SSH/SFTP-implementatie die zonder speciale serverextensies
// werkt, dus ook op gewone gedeelde hosting.

require_once __DIR__ . '/phpseclib_autoload.php';

use phpseclib3\Net\SFTP;

/**
 * Controleert of een map het uitvoer-recht (x) voor de eigenaar heeft -
 * zonder dat kan een FTP/SFTP-gebruiker weliswaar "schrijfrecht" op de map
 * hebben, maar er toch niet daadwerkelijk in kunnen schrijven (een map
 * vereist het x-recht om erin te kunnen "binnengaan"/bestanden aan te
 * maken). Geeft bij een probleem een leesbare toelichting terug, anders
 * null (geen probleem gevonden, of de rechten konden niet worden bepaald).
 */
function diagnoseerMapRechtenSftp(SFTP $sftp, string $pad): ?string
{
    $stat = @$sftp->stat($pad);
    if ($stat === false || !isset($stat['permissions'])) {
        return null;
    }

    $mode = $stat['permissions'] & 0777;
    $eigenaarUitvoer = ($mode & 0100) !== 0;

    if (!$eigenaarUitvoer) {
        $octaal = sprintf('%o', $mode);
        return "De map \"$pad\" mist het uitvoer-recht (x) voor de eigenaar (huidige rechten: $octaal) - "
            . "daardoor kan er geen bestand in geschreven worden, ook al lijkt \"schrijven\" op zich toegestaan.";
    }

    return null;
}

/**
 * Zet de rechten van een map naar 755 (rwxr-xr-x) - een gangbare, veilige
 * standaardwaarde voor een website-map. Wordt alleen op expliciet verzoek
 * van de gebruiker aangeroepen (nooit automatisch/stilzwijgend), zie
 * herstel_maprechten.php.
 */
function herstelMapRechtenSftp(SFTP $sftp, string $pad): bool
{
    return (bool) @$sftp->chmod(0755, $pad);
}

/**
 * Maakt een SFTP-verbinding en logt in. Geeft het SFTP-object terug bij
 * succes, of null + foutmelding bij falen.
 *
 * @return array{0: ?SFTP, 1: ?string} [$sftp, $foutmelding]
 */
function sftpVerbind(string $host, int $poort, string $gebruikersnaam, string $wachtwoord, int $timeoutSeconden = 15): array
{
    try {
        $sftp = new SFTP($host, $poort, $timeoutSeconden);
    } catch (\Throwable $e) {
        return [null, 'Kon geen SFTP-verbinding maken: ' . $e->getMessage()];
    }

    try {
        $ingelogd = $sftp->login($gebruikersnaam, $wachtwoord);
    } catch (\Throwable $e) {
        return [null, 'SFTP-inloggen mislukt: ' . $e->getMessage()];
    }

    if (!$ingelogd) {
        return [null, 'SFTP-inloggen mislukt (controleer gebruikersnaam/wachtwoord).'];
    }

    return [$sftp, null];
}

/**
 * Verwijdert een bestand via SFTP. Geeft true terug bij succes, of als het
 * bestand toch al niet (meer) bestond (dat is voor het aanroepende doel -
 * "zorg dat dit bestand er niet meer is" - functioneel hetzelfde resultaat).
 */
function sftpVerwijderBestand(SFTP $sftp, string $remotePad): bool
{
    if (!$sftp->file_exists($remotePad)) {
        return true;
    }

    return (bool) @$sftp->delete($remotePad);
}

/**
 * Uploadt tekstinhoud (bijv. het gegenereerde scanscript) naar een pad op
 * de SFTP-server. Maakt ontbrekende tussenliggende mappen automatisch aan,
 * net als de bestaande FTP-variant.
 */
function sftpUploadInhoud(SFTP $sftp, string $remotePad, string $inhoud): bool
{
    $map = dirname($remotePad);
    if ($map !== '.' && $map !== '/' && !$sftp->file_exists($map)) {
        $sftp->mkdir($map, -1, true); // recursief aanmaken
    }

    return $sftp->put($remotePad, $inhoud);
}

/**
 * Zoekt recursief (tot een beperkte diepte) naar configuration.php via SFTP -
 * exact dezelfde aanpak als zoekConfigurationPhp() in ftp_detecteer_pad.php
 * (inclusief het overslaan van mappen die op een ánder klantdomein lijken
 * als $doelDomein is meegegeven), maar dan met phpseclib's SFTP-aanroepen
 * in plaats van ftp_rawlist().
 */
function sftpZoekConfigurationPhp(SFTP $sftp, string $pad, int $diepte, int &$bezochteMappen, string $doelDomein = '', string $doelSubmap = '', int $maxDiepte = 7, int $maxBezochteMappen = 600): ?string
{
    if ($diepte > $maxDiepte || $bezochteMappen > $maxBezochteMappen) {
        return null;
    }
    $bezochteMappen++;

    $lijst = $sftp->rawlist($pad);
    if ($lijst === false) {
        return null;
    }

    foreach ($lijst as $naam => $info) {
        if (strtolower($naam) === 'configuration.php' && ($info['type'] ?? null) === 1 /* NET_SFTP_TYPE_REGULAR */) {
            return $pad;
        }
    }

    $overslaan = ['cache', 'logs', 'log', 'tmp', 'cgi-bin', '.well-known', 'mail', 'ssl', 'backup',
                  'backups', 'node_modules', '.git', 'etc', 'proc', 'dev', '.trash', 'error_log'];

    $submapNamen = [];
    foreach ($lijst as $naam => $info) {
        if ($naam === '.' || $naam === '..') {
            continue;
        }
        if (($info['type'] ?? null) !== 2 /* NET_SFTP_TYPE_DIRECTORY */) {
            continue;
        }
        if (in_array(strtolower($naam), $overslaan, true)) {
            continue;
        }
        if ($doelDomein !== '' && sftpLijktOpDomeinNaam($naam) && !sftpIsZelfdeDomein($naam, $doelDomein)) {
            continue; // duidelijk een ánder klantdomein op hetzelfde FTP-account
        }

        $submapNamen[] = $naam;
    }

    // Staat de submap die bij déze site hoort ertussen (bijv. "cuppen" als
    // er ook een sibling-installatie "clabbers" naast staat)? Probeer die
    // dan EERST - anders zou een depth-first zoektocht zomaar de
    // configuration.php van de verkeerde, naastgelegen site-submap vinden.
    if ($doelSubmap !== '') {
        usort($submapNamen, function (string $a, string $b) use ($doelSubmap): int {
            $aMatch = strtolower($a) === strtolower($doelSubmap) ? 0 : 1;
            $bMatch = strtolower($b) === strtolower($doelSubmap) ? 0 : 1;

            return $aMatch <=> $bMatch;
        });
    }

    foreach ($submapNamen as $naam) {
        $subPad = rtrim($pad, '/') . '/' . $naam;
        $gevonden = sftpZoekConfigurationPhp($sftp, $subPad, $diepte + 1, $bezochteMappen, $doelDomein, $doelSubmap, $maxDiepte, $maxBezochteMappen);
        if ($gevonden !== null) {
            return $gevonden;
        }
    }

    return null;
}

/**
 * SFTP-tegenhanger van zoekMapMetExacteNaam() in ftp_detecteer_pad.php -
 * lokaliseert eerst gericht de map met de exacte domeinnaam, ongeacht waar
 * die in de mappenboom zit, zodat de daadwerkelijke configuration.php-zoektocht
 * daarna beperkt kan blijven tot alleen díe map (zie uitleg daar).
 */
function sftpZoekMapMetExacteNaam(SFTP $sftp, string $pad, string $doelDomein, int $diepte, int &$bezochteMappen, int $maxDiepte = 7, int $maxBezochteMappen = 600): ?string
{
    if ($diepte > $maxDiepte || $bezochteMappen > $maxBezochteMappen) {
        return null;
    }
    $bezochteMappen++;

    $overslaan = ['cache', 'logs', 'log', 'tmp', 'cgi-bin', '.well-known', 'mail', 'ssl',
                  'node_modules', '.git', 'etc', 'proc', 'dev', '.trash', 'error_log'];

    $lijst = $sftp->rawlist($pad);
    if ($lijst === false) {
        return null;
    }

    $submappen = [];
    foreach ($lijst as $naam => $info) {
        if ($naam === '.' || $naam === '..') {
            continue;
        }
        if (($info['type'] ?? null) !== 2 /* NET_SFTP_TYPE_DIRECTORY */) {
            continue;
        }
        if (in_array(strtolower($naam), $overslaan, true)) {
            continue;
        }
        $submappen[] = $naam;
    }

    foreach ($submappen as $naam) {
        if (sftpIsZelfdeDomein($naam, $doelDomein)) {
            return rtrim($pad, '/') . '/' . $naam;
        }
    }

    foreach ($submappen as $naam) {
        $subPad = rtrim($pad, '/') . '/' . $naam;
        $gevonden = sftpZoekMapMetExacteNaam($sftp, $subPad, $doelDomein, $diepte + 1, $bezochteMappen, $maxDiepte, $maxBezochteMappen);
        if ($gevonden !== null) {
            return $gevonden;
        }
    }

    return null;
}

/**
 * SFTP-tegenhanger van lijktOpDomeinSubmap() in ftp_detecteer_pad.php.
 */
function sftpLijktOpDomeinSubmap(string $mapNaam, string $doelDomein): bool
{
    $mapNaam = strtolower(trim($mapNaam, '/'));
    if (strlen($mapNaam) < 3) {
        return false;
    }

    $domeinDelen = explode('.', strtolower($doelDomein));
    $domeinHoofddeel = $domeinDelen[0] ?? '';
    if ($domeinHoofddeel === '') {
        return false;
    }

    return strpos($domeinHoofddeel, $mapNaam) === 0 || strpos($mapNaam, $domeinHoofddeel) === 0;
}

/**
 * SFTP-tegenhanger van zoekMapMetGelijkendeNaam() in ftp_detecteer_pad.php -
 * vindt een submap die los overeenkomt met het domein (bijv. een addon-
 * domein in een submap met een kortere, eigen naam dan de volledige
 * domeinnaam), als tussenstap tussen de exacte zoektocht en de brede
 * terugval.
 */
function sftpZoekMapMetGelijkendeNaam(SFTP $sftp, string $pad, string $doelDomein, int $diepte, int &$bezochteMappen, int $maxDiepte = 7, int $maxBezochteMappen = 600): ?string
{
    if ($diepte > $maxDiepte || $bezochteMappen > $maxBezochteMappen) {
        return null;
    }
    $bezochteMappen++;

    $overslaan = ['cache', 'logs', 'log', 'tmp', 'cgi-bin', '.well-known', 'mail', 'ssl',
                  'node_modules', '.git', 'etc', 'proc', 'dev', '.trash', 'error_log',
                  'administrator', 'components', 'modules', 'plugins', 'templates',
                  'libraries', 'media', 'includes', 'language', 'layouts', 'images', 'api'];

    $lijst = $sftp->rawlist($pad);
    if ($lijst === false) {
        return null;
    }

    $submappen = [];
    foreach ($lijst as $naam => $info) {
        if ($naam === '.' || $naam === '..') {
            continue;
        }
        if (($info['type'] ?? null) !== 2 /* NET_SFTP_TYPE_DIRECTORY */) {
            continue;
        }
        if (in_array(strtolower($naam), $overslaan, true)) {
            continue;
        }
        $submappen[] = $naam;
    }

    foreach ($submappen as $naam) {
        if (sftpLijktOpDomeinSubmap($naam, $doelDomein)) {
            return rtrim($pad, '/') . '/' . $naam;
        }
    }

    foreach ($submappen as $naam) {
        $subPad = rtrim($pad, '/') . '/' . $naam;
        $gevonden = sftpZoekMapMetGelijkendeNaam($sftp, $subPad, $doelDomein, $diepte + 1, $bezochteMappen, $maxDiepte, $maxBezochteMappen);
        if ($gevonden !== null) {
            return $gevonden;
        }
    }

    return null;
}

/**
 * Herkent of een mapnaam er zelf uitziet als een domeinnaam
 * (bijv. "voorbeeld.nl", "sub.voorbeeld.co.uk").
 */
function sftpLijktOpDomeinNaam(string $naam): bool
{
    return (bool) preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+\.[a-z]{2,}$/i', $naam);
}

/**
 * Vergelijkt twee domeinnamen ongevoelig voor hoofdletters en een eventueel
 * "www."-voorvoegsel.
 */
function sftpIsZelfdeDomein(string $naamA, string $naamB): bool
{
    $normaliseer = function (string $naam): string {
        $naam = strtolower(trim($naam, '/'));
        return preg_replace('/^www\./', '', $naam);
    };

    return $normaliseer($naamA) !== '' && $normaliseer($naamA) === $normaliseer($naamB);
}
