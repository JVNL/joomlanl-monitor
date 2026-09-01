<?php
/**
 * instellingen_functies.php
 *
 * Gedeelde hulpfuncties voor de centrale instellingen-tabel, zodat
 * e-mailadres, geheime code, monitor-URL en inloggegevens niet langer
 * hardgecodeerd in losse PHP-bestanden hoeven te staan.
 */

/**
 * Haalt ALLE instellingen in één keer op als [sleutel => waarde].
 */
function haalAlleInstellingen(PDO $pdo): array
{
    $resultaat = [];
    $stmt = $pdo->query("SELECT sleutel, waarde FROM instellingen");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
        $resultaat[$rij['sleutel']] = $rij['waarde'];
    }
    return $resultaat;
}

/**
 * Haalt één instelling op, met een terugvalwaarde als de sleutel niet bestaat.
 */
function haalInstelling(PDO $pdo, string $sleutel, ?string $terugval = null): ?string
{
    $stmt = $pdo->prepare("SELECT waarde FROM instellingen WHERE sleutel = ?");
    $stmt->execute([$sleutel]);
    $waarde = $stmt->fetchColumn();
    return $waarde !== false ? $waarde : $terugval;
}

/**
 * Bepaalt het te tonen logopad: het eigen, geüploade logo als dat bestaat
 * (en het bestand ook echt nog op schijf staat), anders het standaardlogo.
 * $instellingen is de array zoals geretourneerd door haalAlleInstellingen().
 */
function huidigLogoPad(array $instellingen): string
{
    $eigenLogoNaam = $instellingen['logo_bestandsnaam'] ?? '';
    if ($eigenLogoNaam !== '' && is_file(__DIR__ . '/images/' . $eigenLogoNaam)) {
        return 'images/' . $eigenLogoNaam;
    }
    return 'images/logo-weergave.png';
}

/**
 * Bepaalt, op basis van de bestandsnaam van het eigen logo, de bijbehorende
 * favicon-bestandsnamen (zonder pad) - deze worden altijd afgeleid van
 * dezelfde basisnaam als het logo zelf (inclusief tijdstempel), zodat ze
 * bij elke nieuwe upload gegarandeerd een unieke naam krijgen - dat
 * voorkomt dat browsers een oud, gecachet favicon-bestand blijven tonen
 * na het wijzigen van het logo.
 */
function faviconBestandsnamenVoorLogo(string $logoBestandsnaam): array
{
    $basisZonderExtensie = pathinfo($logoBestandsnaam, PATHINFO_FILENAME);
    return [
        '32'    => "favicon-eigen-{$basisZonderExtensie}-32.png",
        '16'    => "favicon-eigen-{$basisZonderExtensie}-16.png",
        'apple' => "favicon-eigen-{$basisZonderExtensie}-apple.png",
        '192'   => "favicon-eigen-{$basisZonderExtensie}-192.png",
        '512'   => "favicon-eigen-{$basisZonderExtensie}-512.png",
    ];
}

/**
 * Bepaalt de te tonen favicon-paden: de eigen, van het logo gegenereerde
 * varianten als die bestaan, anders de standaard favicon-bestanden.
 * Geeft altijd alle drie de sleutels terug: '32', '16', 'apple'.
 */
function huidigeFaviconPaden(array $instellingen): array
{
    $standaard = [
        '32'    => 'images/favicon-32x32.png',
        '16'    => 'images/favicon-16x16.png',
        'apple' => 'images/apple-touch-icon.png',
    ];

    $eigenLogoNaam = $instellingen['logo_bestandsnaam'] ?? '';
    if ($eigenLogoNaam === '') {
        return $standaard;
    }

    $namen = faviconBestandsnamenVoorLogo($eigenLogoNaam);
    $resultaat = [];
    foreach ($standaard as $sleutel => $standaardPad) {
        $eigenPad = 'images/' . $namen[$sleutel];
        $resultaat[$sleutel] = is_file(__DIR__ . '/' . $eigenPad) ? $eigenPad : $standaardPad;
    }
    return $resultaat;
}

/**
 * Bepaalt de te tonen PWA-icoonpaden (voor manifest.php, gebruikt bij
 * "installeren op beginscherm" op Android/Chrome) - dezelfde logica als
 * huidigeFaviconPaden(), maar dan voor de 192×192- en 512×512-varianten.
 */
function huidigePwaIconenPaden(array $instellingen): array
{
    $standaard = [
        '192' => 'images/icon-192.png',
        '512' => 'images/icon-512.png',
    ];

    $eigenLogoNaam = $instellingen['logo_bestandsnaam'] ?? '';
    if ($eigenLogoNaam === '') {
        return $standaard;
    }

    $namen = faviconBestandsnamenVoorLogo($eigenLogoNaam);
    $resultaat = [];
    foreach ($standaard as $sleutel => $standaardPad) {
        $eigenPad = 'images/' . $namen[$sleutel];
        $resultaat[$sleutel] = is_file(__DIR__ . '/' . $eigenPad) ? $eigenPad : $standaardPad;
    }
    return $resultaat;
}

/**
 * Genereert, met de GD-bibliotheek, drie favicon-varianten (32×32, 16×16,
 * en een 180×180 "apple touch icon") op basis van een geüpload logo, en
 * schrijft ze weg naar de images-map. Geeft true terug bij succes, false
 * als GD niet beschikbaar is of het genereren om een andere reden mislukt
 * - in dat laatste geval blijft het logo zelf gewoon werken, alleen worden
 * de favicons dan niet bijgewerkt (de bestaande/standaard favicons blijven
 * dan gewoon zichtbaar).
 */
function genereerFaviconsVanLogo(string $bronPad, string $mimeType, string $logoBestandsnaam): bool
{
    if (!extension_loaded('gd')) {
        return false;
    }

    $bron = null;
    switch ($mimeType) {
        case 'image/png':
            $bron = @imagecreatefrompng($bronPad);
            break;
        case 'image/jpeg':
            $bron = @imagecreatefromjpeg($bronPad);
            break;
        case 'image/webp':
            if (function_exists('imagecreatefromwebp')) {
                $bron = @imagecreatefromwebp($bronPad);
            }
            break;
    }

    if ($bron === false || $bron === null) {
        return false;
    }

    $bronBreedte = imagesx($bron);
    $bronHoogte  = imagesy($bron);

    $namen = faviconBestandsnamenVoorLogo($logoBestandsnaam);
    $formaten = ['32' => 32, '16' => 16, 'apple' => 180, '192' => 192, '512' => 512];

    $alleGelukt = true;
    foreach ($formaten as $sleutel => $doelFormaat) {
        $doelAfbeelding = imagecreatetruecolor($doelFormaat, $doelFormaat);

        // Transparantie behouden (belangrijk bij een PNG-logo met
        // doorzichtige achtergrond) - zonder dit wordt transparant gebied
        // standaard zwart.
        imagealphablending($doelAfbeelding, false);
        imagesavealpha($doelAfbeelding, true);
        $transparant = imagecolorallocatealpha($doelAfbeelding, 0, 0, 0, 127);
        imagefilledrectangle($doelAfbeelding, 0, 0, $doelFormaat, $doelFormaat, $transparant);

        $gelukt = imagecopyresampled(
            $doelAfbeelding, $bron,
            0, 0, 0, 0,
            $doelFormaat, $doelFormaat, $bronBreedte, $bronHoogte
        );

        if ($gelukt) {
            $doelPad = __DIR__ . '/images/' . $namen[$sleutel];
            if (!imagepng($doelAfbeelding, $doelPad)) {
                $alleGelukt = false;
            }
        } else {
            $alleGelukt = false;
        }

        imagedestroy($doelAfbeelding);
    }

    imagedestroy($bron);

    return $alleGelukt;
}

/**
 * Verwijdert alle eigen, van een logo gegenereerde favicon-bestanden -
 * gebruikt bij het uploaden van een nieuw logo (oude favicons opruimen)
 * en bij het terugzetten naar het standaardlogo.
 */
function verwijderEigenFavicons(string $logoBestandsnaam): void
{
    if ($logoBestandsnaam === '') {
        return;
    }
    foreach (faviconBestandsnamenVoorLogo($logoBestandsnaam) as $bestandsnaam) {
        $pad = __DIR__ . '/images/' . $bestandsnaam;
        if (is_file($pad)) {
            @unlink($pad);
        }
    }
}

/**
 * Legt vast dat het scanscript onder deze bestandsnaam naar deze site is
 * verstuurd - gebruikt bij het verwijderen van een site om ALLE ooit
 * gebruikte bestandsnamen (ook oudere, inmiddels gewijzigde namen) te
 * kunnen opruimen, niet alleen de momenteel ingestelde naam.
 */
function registreerVerstuurdScanBestand(PDO $pdo, int $siteId, string $bestandsnaam): void
{
    $stmt = $pdo->prepare("
        INSERT INTO site_scanscript_geschiedenis (site_id, bestandsnaam, laatst_verstuurd)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE laatst_verstuurd = NOW()
    ");
    $stmt->execute([$siteId, $bestandsnaam]);
}

/**
 * Zoals registreerVerstuurdScanBestand() hierboven, maar dan voor het geval
 * de bestandsnaam ook daadwerkelijk WIJZIGT (hernoemen, zie
 * ftp_hernoem_scanscript.php): legt de geschiedenis vast ÉN werkt de
 * daadwerkelijk in gebruik zijnde naam (sites.scan_bestandsnaam) bij -
 * registreerVerstuurdScanBestand() alleen doet dat laatste namelijk niet.
 */
function werkScanBestandsnaamBij(PDO $pdo, int $siteId, string $nieuweBestandsnaam): void
{
    registreerVerstuurdScanBestand($pdo, $siteId, $nieuweBestandsnaam);

    $stmt = $pdo->prepare("UPDATE sites SET scan_bestandsnaam = ? WHERE id = ?");
    $stmt->execute([$nieuweBestandsnaam, $siteId]);
}

/**
 * Bepaalt de bestandsnaam van het scanscript voor een specifieke site: de
 * eigen, aangepaste naam als die is ingesteld (bijv. omdat er op dezelfde
 * site ook nog andere monitorsoftware draait), anders de standaardnaam.
 * Accepteert zowel de volledige site-rij (array) als losstaand de waarde
 * van het veld scan_bestandsnaam zelf (string of null).
 */
/**
 * Genereert een unieke scanscript-bestandsnaam - gebruikt als standaard bij
 * het toevoegen van een nieuwe site (in plaats van de vaste, voor elke site
 * identieke standaardnaam "scan-en-check-website.php"). Twee voordelen: de
 * monitor herkent het bestand meteen als "van zichzelf" zodra het wordt
 * teruggevonden (bijv. bij een root-level-onbekend-item-melding op een
 * andere site), en een niet-voorspelbare bestandsnaam is voor een
 * geautomatiseerde aanvaller lastiger te vinden dan een naam die op elke
 * site identiek is.
 *
 * De naam is gebaseerd op de naam van déze monitor (bijv.
 * "scan-door-compactwebmonitor-a3f9c2.php"), niet op de domeinnaam van de
 * site waar het bestand op komt te staan - dat laatste is namelijk altijd
 * al overduidelijk uit de context (het bestand staat er letterlijk al op),
 * terwijl "welke monitor heeft dit hier neergezet" juist wél nuttige,
 * niet vanzelfsprekende informatie is - bijvoorbeeld bij een site die door
 * meerdere, losse monitor-installaties wordt gevolgd.
 */
/**
 * Normaliseert een monitornaam naar het leesbare deel dat in een
 * scanscript-bestandsnaam terechtkomt (zie genereerUniekeScanBestandsnaam()
 * hieronder) - alleen letters/cijfers, kleine letters, max 30 tekens. Ook
 * bruikbaar om te bepalen of een naamswijziging daadwerkelijk een ANDERE
 * bestandsnaam zou opleveren (puur cosmetische wijzigingen, zoals een
 * andere hoofdletter of leesteken, leveren dezelfde genormaliseerde naam
 * op en zijn dus niet relevant voor een eventuele hernoem-waarschuwing).
 */
function normaliseerMonitorNaamVoorBestand(string $monitorNaam): string
{
    $monitorNaam = trim($monitorNaam);
    if ($monitorNaam === '') {
        $monitorNaam = 'monitor';
    }

    $leesbaarDeel = preg_replace('/[^a-z0-9]/', '', strtolower($monitorNaam));
    if ($leesbaarDeel === '') {
        $leesbaarDeel = 'monitor';
    }

    return substr($leesbaarDeel, 0, 30);
}

function genereerUniekeScanBestandsnaam(PDO $pdo): string
{
    $monitorNaam = (string) haalInstelling($pdo, 'email_afzendernaam', '');
    $leesbaarDeel = normaliseerMonitorNaamVoorBestand($monitorNaam);

    // Willekeurige toevoeging (6 hex-tekens = 24 bits) - dit is het
    // onderdeel dat de bestandsnaam daadwerkelijk niet-voorspelbaar maakt;
    // de monitornaam alleen zou (zeker bij hergebruik op meerdere sites)
    // nog steeds relatief makkelijk te raden zijn.
    $willekeurigDeel = bin2hex(random_bytes(3));

    return "scan-door-{$leesbaarDeel}-{$willekeurigDeel}.php";
}

function bepaalScanBestandsnaam($siteOfWaarde): string
{
    $STANDAARD_BESTANDSNAAM = 'scan-en-check-website.php';

    $waarde = is_array($siteOfWaarde)
        ? ($siteOfWaarde['scan_bestandsnaam'] ?? null)
        : $siteOfWaarde;

    $waarde = trim((string) ($waarde ?? ''));

    return $waarde !== '' ? $waarde : $STANDAARD_BESTANDSNAAM;
}

/**
 * Bouwt de daadwerkelijke, publiek bereikbare URL van een site op, met
 * inachtneming van een eventuele URL-submap (site_toevoegen.php/
 * site_instellingen.php, veld "URL-submap") - nodig voor sites waarbij
 * Joomla niet in de webroot zelf staat, maar in een submap die WEL
 * rechtstreeks via de domeinnaam bereikbaar is (bijv.
 * "https://podia-klassiek.nl/bieb/..."). Dit is iets anders dan het
 * FTP-pad: dat bepaalt alleen waar het bestand op de schijf terechtkomt,
 * niet via welke URL het bereikbaar is - de twee hoeven niet overeen te
 * komen (en deden dat bij podia-klassiek.nl bijvoorbeeld ook niet).
 *
 * Gebruik: bepaalSiteUrl($site) voor de kale domeinroot, of
 * bepaalSiteUrl($site, 'pad/naar/iets.php') voor een specifiek bestand.
 */
function bepaalSiteUrl(array $site, string $pad = ''): string
{
    $domein   = trim($site['domein'] ?? '', '/');
    $submap   = trim($site['url_subpad'] ?? '', '/');
    $pad      = ltrim($pad, '/');

    $delen = array_filter([$domein, $submap, $pad], fn($deel) => $deel !== '');

    return 'https://' . implode('/', $delen);
}

/**
 * Slaat één instelling op (voegt toe of werkt bij).
 */
function slaInstellingOp(PDO $pdo, string $sleutel, string $waarde): void
{
    $stmt = $pdo->prepare("
        INSERT INTO instellingen (sleutel, waarde)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE waarde = VALUES(waarde)
    ");
    $stmt->execute([$sleutel, $waarde]);
}

/**
 * Genereert een klein "?"-icoontje voor inline help: bij een klik
 * verschijnt een pop-up met een korte samenvatting en een link naar de
 * volledige uitleg op de betreffende plek in help.php. Zie ook de CSS
 * (.hulp-icoon/.hulp-popup) en de JS-functie toonHulpPopup(), beide in
 * responsive_stijlen.php (overal al ingeladen).
 *
 * @param string $ankerId     De id van het <section>-element in help.php
 *     waar dit onderwerp wordt behandeld (zonder "#").
 * @param string $samenvatting Korte, feitelijke samenvatting (1-3 zinnen)
 *     die in de pop-up zelf wordt getoond - dit is bewust GEEN duplicaat
 *     van de volledige helptekst, puur een korte context vooraf.
 */
function hulpIcoon(string $ankerId, string $samenvatting): string
{
    static $teller = 0;
    $teller++;

    return '<span class="hulp-icoon" tabindex="0" role="button" aria-label="Hulp"'
        . ' data-popup-id="hulp-' . $teller . '"'
        . ' data-samenvatting="' . htmlspecialchars($samenvatting) . '"'
        . ' data-anker="help.php#' . htmlspecialchars($ankerId) . '"'
        . ' onclick="toonHulpPopup(this)"'
        . ' onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();toonHulpPopup(this);}"'
        . '>?</span>';
}

/**
 * Bouwt een ftp://-/ftpes://-/sftp://-link om de FTP-/SFTP-gegevens van een
 * site direct te openen in een lokaal geïnstalleerde FTP-client (bijv.
 * FileZilla) - werkt alleen als het besturingssysteem dat protocol aan zo'n
 * programma heeft gekoppeld. Gedeeld tussen index.php (Actie-kolom) en
 * beveiliging.php (naast de herscan-knop), zodat deze vrij bewerkelijke
 * logica niet op twee plekken apart onderhouden hoeft te worden.
 *
 * @param array $site rij uit de sites-tabel (moet o.a. ftp_host,
 *     ftp_gebruikersnaam, ftp_wachtwoord, ftp_poort, ftp_pad, ftp_ssl,
 *     ftp_protocol bevatten)
 * @return array{url: ?string, wachtwoordKopieren: ?string, gebruikersnaamKopieren: ?string}
 *     'url' is null als er geen (bruikbare) FTP-gegevens bekend zijn.
 */
function bepaalFtpClientLink(array $site): array
{
    $ftpClientUrl = null;
    $ftpWachtwoordKopieren = null;
    $ftpGebruikersnaamKopieren = null;

    if (empty($site['ftp_host']) || empty($site['ftp_gebruikersnaam'])) {
        return ['url' => null, 'wachtwoordKopieren' => null, 'gebruikersnaamKopieren' => null];
    }

    $ftpSchema = (($site['ftp_protocol'] ?? 'ftp') === 'sftp')
        ? 'sftp'
        // FileZilla herkent "ftpes://" specifiek als expliciete FTP-over-TLS
        // (het "ftp_ssl"-vinkje bij Site-instellingen) - een kale "ftp://"
        // is altijd ONversleuteld en wordt door steeds meer hostingpartijen
        // inmiddels geweigerd zodra TLS verplicht is gesteld.
        : (!empty($site['ftp_ssl']) ? 'ftpes' : 'ftp');
    $ftpStandaardPoort = $ftpSchema === 'sftp' ? 22 : 21;
    $ftpPoortVoorLink = !empty($site['ftp_poort']) ? (int) $site['ftp_poort'] : $ftpStandaardPoort;
    $ftpWachtwoordVoorLink = ontsleutelWaarde($site['ftp_wachtwoord'] ?? '');
    $ftpPadVoorLink = trim($site['ftp_pad'] ?? '/', '/');

    // Firefox blokkeert zelf (net::ERR_UNSAFE_PORT) elke link met een
    // EXPLICIET poortnummer 21 of 22 erin, nog vóórdat het ooit aan een
    // externe FTP-client wordt doorgegeven - ongeacht welk protocol er
    // gekoppeld staat. Bij de standaardpoort laten we 'm daarom gewoon
    // weg (FileZilla vult die dan zelf in); alleen een AFWIJKENDE poort
    // wordt expliciet meegegeven.
    $poortDeel = ($ftpPoortVoorLink !== $ftpStandaardPoort) ? ':' . $ftpPoortVoorLink : '';

    // BELANGRIJK: gebruikersnaam/wachtwoord bewust NIET url-encoderen
    // wanneer ze "veilig" zijn (zie hieronder) - FileZilla decodeert
    // %XX-reeksen namelijk niet terug.
    // Eén categorie tekens is met GEEN van beide aanpakken (wel/niet
    // encoderen) betrouwbaar op te lossen: browsers passen ALTIJD hun
    // eigen, vaste lijst met tekens toe die in dit deel van een URL
    // verplicht ge-percent-encodeerd worden (de "userinfo percent-
    // encode set" uit de WHATWG URL-standaard) - ongeacht wat wij zelf
    // wel/niet coderen. FileZilla decodeert zulke %XX-reeksen niet
    // terug, dus die tekens (en een los "%" zelf, altijd dubbelzinnig)
    // geven een verkeerd wachtwoord bij de server. "/", "?" en "#" zijn
    // daarbovenop nog erger: die breken de linkstructuur zo grondig dat
    // er zelfs helemaal niets gebeurt. Voor die gevallen laten we het
    // wachtwoord bewust buiten de link en kopiëren we het in plaats
    // daarvan naar het klembord.
    //
    // Een "@" in de gebruikersnaam (bijv. Strato-achtige "klantnummer@
    // domein.nl") bleek bij sommige hostingpartijen (Strato) gewoon te
    // werken, maar bij andere (zxcs.nl) stuurt FileZilla 'm toch
    // letterlijk als "%40" mee - dus geen uitzondering, overal dezelfde
    // lijst voor zowel gebruikersnaam als wachtwoord.
    $onveiligeTekensWachtwoord = " \"#<>?`{}/:;=@[\\]^|%";
    $onveiligeTekensGebruikersnaam = $onveiligeTekensWachtwoord;
    $ftpWachtwoordVeiligVoorLink = strpbrk($ftpWachtwoordVoorLink, $onveiligeTekensWachtwoord) === false;
    $ftpGebruikersnaamVeiligVoorLink = strpbrk($site['ftp_gebruikersnaam'], $onveiligeTekensGebruikersnaam) === false;

    if ($ftpWachtwoordVeiligVoorLink && $ftpGebruikersnaamVeiligVoorLink) {
        $ftpClientUrl = $ftpSchema . '://'
            . $site['ftp_gebruikersnaam']
            . ':' . $ftpWachtwoordVoorLink
            . '@' . $site['ftp_host']
            . $poortDeel
            . '/' . $ftpPadVoorLink;
    } else {
        // Terugvaloptie: link zonder wachtwoord (en zonder gebruikersnaam
        // als die zelf ook een onveilig teken bevat) - FileZilla vraagt
        // dan gewoon om de ontbrekende gegevens; wachtwoord (en evt.
        // gebruikersnaam) staan inmiddels al op het klembord/in een
        // kopieerbaar venstertje om te plakken.
        $ftpClientUrl = $ftpSchema . '://'
            . ($ftpGebruikersnaamVeiligVoorLink ? $site['ftp_gebruikersnaam'] . '@' : '')
            . $site['ftp_host']
            . $poortDeel
            . '/' . $ftpPadVoorLink;
        $ftpWachtwoordKopieren = $ftpWachtwoordVoorLink;
        if (!$ftpGebruikersnaamVeiligVoorLink) {
            $ftpGebruikersnaamKopieren = $site['ftp_gebruikersnaam'];
        }
    }

    return [
        'url' => $ftpClientUrl,
        'wachtwoordKopieren' => $ftpWachtwoordKopieren,
        'gebruikersnaamKopieren' => $ftpGebruikersnaamKopieren,
    ];
}
