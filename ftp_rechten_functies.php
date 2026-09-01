<?php
// ftp_rechten_functies.php
//
// Diagnose en (optioneel, op expliciet verzoek) herstel van maprechten bij
// gewone FTP-verbindingen (de SFTP-tegenhanger hiervan staat in
// sftp_functies.php). Een map heeft het uitvoer-recht (x) nodig voor de
// eigenaar om er daadwerkelijk bestanden in te kunnen schrijven - zonder
// dat kan een upload mislukken, ook al lijkt "schrijfrecht" op de map zelf
// in orde.

/**
 * Haalt de Unix-rechtenstring (bijv. "drwxr-xr-x") op van een specifieke
 * map, door de OUDERmap te doorzoeken op een regel die bij deze mapnaam
 * hoort. Geeft null terug als dit niet kon worden bepaald (bijv. omdat de
 * FTP-server een ander formaat gebruikt dan de gangbare Unix-lijststijl).
 */
function haalMapRechtenViaFtp($conn, string $volledigPad): ?string
{
    $volledigPad = rtrim($volledigPad, '/');
    $ouderPad = dirname($volledigPad);
    if ($ouderPad === '' || $ouderPad === '.') {
        $ouderPad = '/';
    }
    $naam = basename($volledigPad);

    $regels = @ftp_rawlist($conn, $ouderPad);
    if ($regels === false) {
        return null;
    }

    foreach ($regels as $regel) {
        // Gangbare Unix-lijststijl, bijv.:
        // "drwxr-xr-x  2 user group  4096 Jan  1 12:00 mapnaam"
        if (preg_match('/^([\-dl][rwxsStT-]{9})\s+\d+\s+\S+\s+\S+\s+\d+\s+\S+\s+\d+\s+[\d:]+\s+(.+)$/', trim($regel), $match)) {
            if (trim($match[2]) === $naam) {
                return $match[1];
            }
        }
    }

    return null;
}

/**
 * Controleert of een map (via een gewone FTP-verbinding) het uitvoer-recht
 * voor de eigenaar heeft. Geeft bij een probleem een leesbare toelichting
 * terug, anders null (geen probleem gevonden, of de rechten konden niet
 * worden bepaald - bijv. omdat de server geen Unix-stijl lijst teruggeeft).
 */
function diagnoseerMapRechtenFtp($conn, string $pad): ?string
{
    $rechtenString = haalMapRechtenViaFtp($conn, $pad);
    if ($rechtenString === null) {
        return null;
    }

    // Positie 3 (0-geïndexeerd) is het uitvoer-recht van de eigenaar.
    $eigenaarUitvoer = ($rechtenString[3] ?? '-') !== '-';

    if (!$eigenaarUitvoer) {
        $octaal = unixRechtenStringNaarOctaal($rechtenString);
        return "De map \"$pad\" mist het uitvoer-recht (x) voor de eigenaar (huidige rechten: $rechtenString"
            . ($octaal !== null ? " / $octaal" : '') . ") - daardoor kan er geen bestand in geschreven worden, "
            . "ook al lijkt \"schrijven\" op zich toegestaan.";
    }

    return null;
}

/**
 * Zet de rechten van een map naar 755 (rwxr-xr-x) via het FTP-commando
 * SITE CHMOD - een gangbare, veilige standaardwaarde voor een website-map.
 * Niet elke hostingpartij ondersteunt dit commando via FTP; geeft in dat
 * geval gewoon false terug. Wordt alleen op expliciet verzoek van de
 * gebruiker aangeroepen (nooit automatisch/stilzwijgend), zie
 * herstel_maprechten.php.
 */
function herstelMapRechtenFtp($conn, string $pad): bool
{
    return (bool) @ftp_chmod($conn, 0755, $pad);
}

/**
 * Verwijdert een map en alle inhoud daarvan (recursief) via een gewone
 * FTP-verbinding - FTP's eigen ftp_rmdir() werkt alleen op een lege map,
 * dus eerst moet alle inhoud (bestanden én submappen) apart worden
 * opgeruimd. Gebruikt om de "_scan_beheer"-map (quarantaine/geblokkeerd/
 * prullenbak) op te ruimen bij het verwijderen van een site.
 *
 * Geeft true terug als de map na afloop niet meer bestaat (inclusief het
 * geval dat de map al niet bestond - dat telt ook als geslaagd), anders
 * false.
 */
function verwijderMapRecursiefFtp($conn, string $pad): bool
{
    $lijst = @ftp_nlist($conn, $pad);
    if ($lijst === false) {
        // Map bestaat niet (of kon niet worden gelezen) - niets te doen.
        return true;
    }

    foreach ($lijst as $item) {
        $naam = basename($item);
        if ($naam === '' || $naam === '.' || $naam === '..') {
            continue;
        }
        $volledigPad = rtrim($pad, '/') . '/' . $naam;

        // ftp_nlist() geeft geen bestand/map-onderscheid mee - eerst
        // gewoon proberen te verwijderen als bestand; lukt dat niet, dan
        // is het vermoedelijk een submap, en gaan we er recursief in.
        if (!@ftp_delete($conn, $volledigPad)) {
            verwijderMapRecursiefFtp($conn, $volledigPad);
        }
    }

    return (bool) @ftp_rmdir($conn, $pad);
}

/**
 * Zet een Unix-rechtenstring (bijv. "drwxr-xr-x") om naar een octale
 * weergave (bijv. "755") - puur ter informatie in de foutmelding, niet
 * functioneel gebruikt.
 */
function unixRechtenStringNaarOctaal(string $rechtenString): ?string
{
    if (strlen($rechtenString) < 10) {
        return null;
    }

    $bits = substr($rechtenString, 1); // het eerste teken (bestandstype) overslaan
    $octaal = '';
    for ($groep = 0; $groep < 3; $groep++) {
        $deel = substr($bits, $groep * 3, 3);
        $waarde = 0;
        if (($deel[0] ?? '-') !== '-') $waarde += 4;
        if (($deel[1] ?? '-') !== '-') $waarde += 2;
        if (in_array($deel[2] ?? '-', ['x', 's', 't'], true)) $waarde += 1;
        $octaal .= (string) $waarde;
    }

    return $octaal;
}
