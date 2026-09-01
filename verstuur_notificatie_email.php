<?php
// verstuur_notificatie_email.php
//
// Stelt op basis van de e-mailinstellingen (Configuratie > E-mail) één
// gecombineerde notificatiemail samen over alle sites, en verstuurt die
// alleen als er ook daadwerkelijk iets te melden is. Wordt als laatste stap
// aangeroepen ná "Scan en check sites" (zowel de knop op de monitorpagina
// als de cronjob), zodat alle gegevens (website/SSL/Joomla/extensies/
// beveiliging) vers zijn.

error_reporting(E_ALL);
ini_set('display_errors', 1);
// Stelt de samenvatting op basis van ALLE sites tegelijk samen - zelfde
// reden als bij de vorige twee stappen om de tijds-/geheugenlimiet te
// verruimen.
@set_time_limit(120);
@ini_set('memory_limit', '256M');

require_once 'config.php';
require_once 'endpoint_beveiliging.php';
require_once 'instellingen_functies.php';
require_once 'verdacht_functies.php';
require_once 'versie_vergelijk_functies.php';

function instellingIsAan(?string $waarde): bool
{
    return $waarde === '1';
}

$instellingen = haalAlleInstellingen($pdo);

$alleenBijCron = instellingIsAan($instellingen['email_alleen_bij_cron'] ?? '0');

if ($alleenBijCron && !$EINDPUNT_VIA_CRON) {
    echo "E-mail overgeslagen: instelling 'alleen bij cronjob' staat aan, en dit is handmatig via de monitorpagina gestart.\n";
    exit;
}

$websiteAan      = instellingIsAan($instellingen['email_website_status_enabled'] ?? '0');
$websiteCriteria = array_filter(explode(',', $instellingen['email_website_criteria'] ?? ''));
$joomlaAan       = instellingIsAan($instellingen['email_joomla_enabled'] ?? '0');
$extensiesAan    = instellingIsAan($instellingen['email_extensies_enabled'] ?? '0');
$sslAan          = instellingIsAan($instellingen['email_ssl_enabled'] ?? '0');
$beveiligingAan  = instellingIsAan($instellingen['email_beveiliging_enabled'] ?? '0');

if (!$websiteAan && !$joomlaAan && !$extensiesAan && !$sslAan && !$beveiligingAan) {
    echo "E-mailnotificaties staan volledig uit in de instellingen - er is niets verstuurd.\n";
    exit;
}

// "Websites van anderen" tellen bewust niet mee in deze mail - die is
// bedoeld om te laten zien welke EIGEN sites aandacht nodig hebben, en
// zou anders onnodig rommelig worden door sites die je expliciet niet
// per se up-to-date/schoon hoeft te houden.
$sites = $pdo->query("SELECT * FROM sites WHERE categorie = 'eigen' ORDER BY domein ASC")->fetchAll(PDO::FETCH_ASSOC);

$catalogus                   = haalExtensieCatalogus($pdo);
$nieuwsteVersies              = haalNieuwsteVersies($pdo);
$alleGeinstalleerdeExtensies  = haalAlleGeinstalleerdeExtensies($pdo);
$alleDerdePartijSamenvatting  = haalAlleDerdePartijSamenvatting($pdo);
$alleVertrouwdeHashes         = haalAlleVertrouwdeHashes($pdo);

$meldingenPerSite = [];

foreach ($sites as $site) {
    $regels = [];

    // 1. Website status (online/offline)
    if ($websiteAan) {
        $class  = $site['live_website_class'] ?? '';
        $status = $site['live_website_status'] ?? '';

        $criteriumType = null;
        if ($class === 'rood' && strpos($status, 'HTTP') === false) {
            $criteriumType = 'geen_verbinding';
        } elseif ($class === 'rood' && strpos($status, 'HTTP') !== false) {
            $criteriumType = 'http_fout';
        } elseif ($class === 'oranje') {
            $criteriumType = 'verdacht';
        }

        if ($criteriumType !== null && in_array($criteriumType, $websiteCriteria, true)) {
            $regels[] = "Website: $status";
        }
    }

    // 2. Joomla-versie
    if ($joomlaAan) {
        $huidig   = $site['joomla_versie'] ?? null;
        $major    = bepaalMajorVersie($huidig);
        $nieuwste = $major !== null ? ($nieuwsteVersies['joomla_' . $major] ?? null) : null;

        if (isUpToDate($huidig, $nieuwste) === false) {
            $regels[] = "Joomla: $huidig → nieuwste $nieuwste beschikbaar";
        }
    }

    // 3. Extensies (catalogus + volledige derde-partij-lijst samen)
    if ($extensiesAan) {
        $geinstalleerd = $alleGeinstalleerdeExtensies[$site['id']] ?? [];
        $statussen     = bepaalExtensieStatussen($geinstalleerd, $catalogus, $nieuwsteVersies);
        $catalogusNietUpToDate = count($statussen['niet_up_to_date']);

        $derdePartij = $alleDerdePartijSamenvatting[$site['id']] ?? ['totaal' => 0, 'niet_up_to_date' => 0];

        $totaalNietUpToDate = $catalogusNietUpToDate + $derdePartij['niet_up_to_date'];

        if ($totaalNietUpToDate > 0) {
            $regels[] = "Extensies - Niet up-to-date: $totaalNietUpToDate";
        }
    }

    // 4. SSL-status (alleen verlopen certificaten)
    if ($sslAan) {
        if (($site['live_ssl_class'] ?? '') === 'ssl-rood') {
            $regels[] = "SSL: certificaat verlopen";
        }
    }

    // 5. Beveiliging (verdachte, niet-vertrouwde bestanden)
    if ($beveiligingAan) {
        $items         = parseVerdachtDetails($site['verdacht_details'] ?? '');
        $vertrouwd     = $alleVertrouwdeHashes[$site['id']] ?? [];
        $nietVertrouwd = 0;

        foreach ($items as $item) {
            if (!isset($vertrouwd[$item['hash']])) {
                $nietVertrouwd++;
            }
        }

        if ($nietVertrouwd > 0) {
            $regels[] = "Beveiliging - Verdachte bestand(en): $nietVertrouwd";
        }
    }

    if (!empty($regels)) {
        $meldingenPerSite[$site['domein']] = $regels;
    }
}

if (empty($meldingenPerSite)) {
    echo "Geen meldingen op basis van de huidige instellingen - er is geen e-mail verstuurd.\n";
    exit;
}

$naar       = haalInstelling($pdo, 'notificatie_email', '');
$monitorUrl = rtrim(haalInstelling($pdo, 'monitor_basis_url', ''), '/');

if ($naar === '') {
    echo "Waarschuwing: geen notificatie-e-mailadres ingesteld bij Configuratie - er is niets verstuurd.\n";
    exit;
}

$aantalSites = count($meldingenPerSite);
$onderwerp   = "⚠️ $aantalSites site(s) met een melding in monitor";

/**
 * Bouwt, net als op de monitorpagina zelf, de adminpad-URL op basis van het
 * (versleutelde) admin_pad-veld van de site - met "administrator" als
 * terugval als er niets is ingevuld.
 */
function bouwAdminUrl(array $site): string
{
    $adminPad = trim(ontsleutelWaarde($site['admin_pad'] ?? ''), '/');
    if ($adminPad === '') {
        $adminPad = 'administrator';
    }
    return bepaalSiteUrl($site, $adminPad);
}

// $sites is al eerder in dit script opgehaald (met SELECT *), dus favicon_url
// en admin_pad staan er al in - alleen even koppelen op domeinnaam.
$sitesPerDomein = [];
foreach ($sites as $site) {
    $sitesPerDomein[$site['domein']] = $site;
}

$regelsHtml = '';
foreach ($meldingenPerSite as $domein => $regels) {
    $site = $sitesPerDomein[$domein] ?? ['domein' => $domein];
    $faviconUrl = !empty($site['favicon_url']) ? $site['favicon_url'] : 'https://www.joomla.org/favicon.ico';
    $adminUrl   = bouwAdminUrl($site);
    $websiteUrl = bepaalSiteUrl($site) . '/';

    $puntenHtml = '';
    foreach ($regels as $regel) {
        $puntenHtml .= '<li style="margin: 0 0 4px 0; color: #333333;">' . htmlspecialchars($regel) . '</li>';
    }

    $regelsHtml .= '
    <tr>
        <td style="padding: 14px 0; border-bottom: 1px solid #e5e5e5;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td style="vertical-align: middle; padding-right: 8px;">
                        <a href="' . htmlspecialchars($websiteUrl) . '" style="text-decoration: none;">
                            <img src="' . htmlspecialchars($faviconUrl) . '" width="20" height="20" alt="" style="display: block; border: 0; border-radius: 3px;">
                        </a>
                    </td>
                    <td style="vertical-align: middle;">
                        <a href="' . htmlspecialchars($adminUrl) . '" style="color: #1f6fa8; font-weight: bold; font-size: 15px; text-decoration: none;">' . htmlspecialchars($domein) . '</a>
                    </td>
                </tr>
            </table>
            <ul style="margin: 8px 0 0 0; padding-left: 20px; font-size: 13px;">' . $puntenHtml . '</ul>
        </td>
    </tr>';
}

$inhoud = '<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin: 0; padding: 0; background: #f5f5f5; font-family: Arial, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background: #f5f5f5; padding: 20px 0;">
<tr>
<td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="background: #ffffff; border-radius: 8px; overflow: hidden;">
        <tr>
            <td style="background: #333333; color: #ffffff; padding: 16px 24px; font-size: 16px; font-weight: bold;">
                ⚠️ ' . $aantalSites . ' site(s) met een melding
            </td>
        </tr>
        <tr>
            <td style="padding: 0 24px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $regelsHtml . '
                </table>
            </td>
        </tr>
        <tr>
            <td style="padding: 20px 24px; font-size: 12px; color: #888888;">
                <a href="' . htmlspecialchars($monitorUrl) . '/index.php" style="color: #1f6fa8;">Bekijk de volledige monitor</a>
            </td>
        </tr>
    </table>
</td>
</tr>
</table>
</body>
</html>';

$afzenderNaam = trim($instellingen['email_afzendernaam'] ?? '');
if ($afzenderNaam === '') {
    $afzenderNaam = 'Mijn Websites Monitor';
}

$headers = "Content-Type: text/html; charset=utf-8\r\n";
// Niet-ASCII-tekens (bijv. accenten) moeten volgens de mailstandaard
// gecodeerd worden in de header - mb_encode_mimeheader doet dat, en
// laat platte ASCII-namen gewoon ongewijzigd.
$afzenderNaamGecodeerd = mb_encode_mimeheader($afzenderNaam, 'UTF-8', 'B');
$headers .= "From: $afzenderNaamGecodeerd <$naar>\r\n";

mail($naar, $onderwerp, $inhoud, $headers);

echo "OK: e-mail verstuurd naar $naar voor $aantalSites site(s).\n";
