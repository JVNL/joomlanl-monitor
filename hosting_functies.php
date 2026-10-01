<?php
// hosting_functies.php
//
// Bepaalt voor een gemonitorde site bij welke hostingpartij die staat, op
// welke server die draait en wat het IP-adres daarvan is - volledig op
// basis van openbare, live opvraagbare gegevens (er wordt niets in de
// database opgeslagen, en er hoeft niets op de site zelf te staan):
//
//  1. DNS: het IP-adres van de domeinnaam zelf (A-/AAAA-record), en - als er
//     FTP-/SFTP-gegevens bekend zijn - het IP-adres van de FTP-server.
//  2. Reverse DNS (PTR): de hostnaam die bij dat IP-adres hoort. Bij vrijwel
//     alle hostingpartijen is dat de naam van de server zelf (bijv.
//     "server123.hostingpartij.nl").
//     (Strato gebruikt bijvoorbeeld servernamen als "w82.rzone.de", Vimexx
//     "web0171.zxcs.nl".)
//  3. RIPEstat (stat.ripe.net, openbare en gratis dienst van RIPE NCC, ook
//     voor IP-adressen buiten Europa), twee opzoekingen:
//     a. de IP-toewijzing (inetnum): aan wie dát specifieke blok IP-adressen
//        is toegewezen. Dat is vaak de hostingpartij zelf, ook als die ruimte
//        huurt in het netwerk van een datacenter (bijv. blok "ANTAGONIST" in
//        het netwerk van Previder, of blok "VIMEXX" in het netwerk van
//        Stichting DIGI NL / ZXCS).
//     b. het netwerk (AS-nummer + eigenaar) dat dat IP-adres aankondigt - vaak
//        het datacenter of de moederorganisatie, niet de partij waar de klant
//        het hostingpakket afneemt.
//
// Uit die bronnen wordt de hostingpartij afgeleid, van meest naar minst
// specifiek: een bekende naam in de servernaam, in de IP-toewijzing, in de
// FTP-hostnaam en in de netwerkeigenaar. Een datacenter/cloudplatform (zie
// haalInfrastructuurpartijen()) telt daarbij pas als er niets specifiekers
// gevonden wordt: staat een site in het netwerk van bijv. Previder of
// Leaseweb, dan is de huurder van dat IP-blok de werkelijke hostingpartij.
// Wordt niets herkend, dan wordt de naam uit de IP-toewijzing getoond, en
// anders de netwerkeigenaar. Als laatste redmiddel: de nameservers.
//
// Bijzonder geval: CDN's/proxies zoals Cloudflare. Het IP-adres van de
// domeinnaam is dan van Cloudflare, niet van de echte server. Is er een
// FTP-host bekend, dan wordt de echte server via die FTP-host bepaald; is
// die er niet, dan wordt eerlijk gemeld dat de echte server verborgen is.

/**
 * Bekende hostingpartijen: regex (op kleine letters, tegen hostnamen, de
 * IP-toewijzing en de naam van de netwerkeigenaar) => weergavenaam. Bewust
 * met woordgrenzen of een volledige domeinnaam, zodat een klantdomein als
 * "bytebakkerij.nl" niet ten onrechte als "Byte" wordt herkend.
 *
 * Een technische naam (zoals "zxcs": servernamen webNNNN.zxcs.nl, netwerk
 * "AS-ZXCS Stichting DIGI NL") wordt bewust NIET hard aan een merk
 * gekoppeld, maar als infrastructuurpartij behandeld (zie
 * haalInfrastructuurpartijen()). Welke partij er werkelijk achter zit, blijkt
 * dan per IP-adres uit de IP-toewijzing in RIPE (bijv. "NL-VIMEXX-SHARED" /
 * "VIMEXX"). Zo is het bewijs per site aantoonbaar, en krijgt een andere
 * partij met een eigen IP-blok op hetzelfde platform niet ten onrechte een
 * vast label. Lukt de opzoeking niet, dan staat er eerlijk "ZXCS".
 */
function haalBekendeHostingpartijen(): array
{
    return [
        '/\bantagonist\b/'               => 'Antagonist',
        '/\bvimexx\b/'                   => 'Vimexx',
        '/\bzxcs\b/'                     => 'ZXCS', // infrastructuur, zie haalInfrastructuurpartijen()
        '/\bwned\b|\bwitxl\b/'           => 'Wned', // servers van Wned heten serverNNN.witxl.nl
        '/\bstrato\b|\brzone\.de\b/'     => 'Strato',
        '/\bvevida\b/'                   => 'Vevida',
        '/\bmijnhostingpartner\b/'       => 'MijnHostingPartner',
        '/\btransip\b/'                  => 'TransIP',
        '/\bhostnet\b/'                  => 'Hostnet',
        '/\bmijndomein\b/'               => 'Mijndomein',
        '/\bversio\b/'                   => 'Versio',
        '/\bone\.com\b|\bone-com\b/'     => 'one.com',
        '/\bhostinger\b|\bhstgr\b/'      => 'Hostinger', // servernamen srvNNN.hstgr.io
        '/\byourhosting\b|\byour hosting\b|\bcldin\b/' => 'Yourhosting',
        '/\bneostrada\b/'                => 'Neostrada',
        '/\bargeweb/'                     => 'Argeweb', // ook "argewebhosting.nl"
        '/\bsavvii\b/'                   => 'Savvii',
        '/\bbyte\.nl\b|\bbyte internet\b/' => 'Byte',
        '/\bhypernode\b/'                => 'Hypernode',
        '/\blevel27\b/'                  => 'Level27',
        '/\bcyso\b/'                     => 'Cyso',
        '/\bgreenhost\b/'                => 'Greenhost',
        '/\bwebreus\b/'                  => 'Webreus',
        '/\bcloud86\b/'                  => 'Cloud86',
        '/\bbhosted\b/'                  => 'bHosted',
        '/\bhosting2go\b/'               => 'Hosting2GO',
        '/\bshock ?media\b/'             => 'Shock Media',
        '/\bflexwebhosting\b/'           => 'Flexwebhosting',
        '/\bsohosted\b/'                 => 'SoHosted',
        '/\bnederhost\b/'                => 'Nederhost',
        '/\bprolocation\b/'              => 'Prolocation',
        '/\bpcextreme\b/'                => 'PCextreme',
        '/\bserverius\b/'                => 'Serverius',
        '/\bworldstream\b/'              => 'WorldStream',
        '/\bleaseweb\b/'                 => 'Leaseweb',
        '/\bcombell\b/'                  => 'Combell',
        '/\bionos\b|\b1and1\b|\b1und1\b|\b1&1\b|\bkundenserver\b/' => 'IONOS', // servernamen *.kundenserver.de
        '/\bsiteground\b|\bsgvps\b/'     => 'SiteGround', // servernamen *.sgvps.net
        '/\bgodaddy\b|\bsecureserver\b/' => 'GoDaddy',
        '/\bbluehost\b/'                 => 'Bluehost',
        '/\bovh\b/'                      => 'OVHcloud',
        '/\bhetzner\b|\byour-server\.de\b/' => 'Hetzner',
        '/\bcontabo\b/'                  => 'Contabo',
        '/\bprevider\b/'                 => 'Previder',
        '/\bnl-bit\b|\bbit b\.?v\.?(?:\s|$)/' => 'BIT',
        '/\bdigitalocean\b/'             => 'DigitalOcean',
        '/\blinode\b|\bakamai connected cloud\b/' => 'Linode (Akamai)',
        '/\bvultr\b|\bchoopa\b/'         => 'Vultr',
        '/\bamazon\b|\bamazonaws\b/'     => 'Amazon Web Services',
        '/\bgoogle\b|\bgoogleusercontent\b/' => 'Google Cloud',
        '/\bmicrosoft\b|\bazure\b/'      => 'Microsoft Azure',
        '/\bcloudflare\b/'               => 'Cloudflare',
    ];
}

/**
 * Datacenters en cloudplatforms: partijen die (ook) netwerk, rackruimte of
 * servers verhuren aan andere hostingpartijen. Worden ze herkend, dan is
 * dat vaak niet de partij waar de klant het hostingpakket afneemt - er
 * wordt dan eerst nog verder gezocht naar een specifiekere naam (vooral in
 * de IP-toewijzing). Namen zoals ze in haalBekendeHostingpartijen() staan.
 */
function haalInfrastructuurpartijen(): array
{
    return [
        'ZXCS', 'Previder', 'BIT', 'Serverius', 'WorldStream', 'Leaseweb', 'Hetzner',
        'OVHcloud', 'DigitalOcean', 'Linode (Akamai)', 'Vultr',
        'Amazon Web Services', 'Google Cloud', 'Microsoft Azure',
    ];
}

function isInfrastructuurpartij(?string $naam): bool
{
    return $naam !== null && in_array($naam, haalInfrastructuurpartijen(), true);
}

/**
 * AS-nummers van CDN's/reverse proxies: het IP-adres van de domeinnaam is
 * daar NIET de echte server.
 */
function haalCdnNetwerken(): array
{
    return [
        13335  => 'Cloudflare',
        209242 => 'Cloudflare',
        54113  => 'Fastly',
        20940  => 'Akamai',
        16625  => 'Akamai',
        30148  => 'Sucuri',
        19551  => 'Imperva (Incapsula)',
    ];
}

/**
 * Haalt de kale hostnaam uit het domein-veld van een site (dat veld kan
 * ook een submap bevatten, bijv. "voorbeeld.nl/bieb", of per ongeluk een
 * protocol of poort).
 */
function bepaalKaleHostnaam(string $domein): string
{
    $domein = trim($domein);
    $domein = preg_replace('#^[a-z]+://#i', '', $domein);
    $domein = explode('/', $domein, 2)[0];
    $domein = explode(':', $domein, 2)[0];

    return strtolower(rtrim($domein, '.'));
}

/**
 * Zoekt de IPv4-adressen van een hostnaam op. Een kaal IP-adres wordt
 * gewoon teruggegeven. Valt terug op gethostbynamel() als dns_get_record()
 * op de server is uitgeschakeld.
 */
function zoekIpv4Adressen(string $hostnaam): array
{
    if ($hostnaam === '') {
        return [];
    }
    if (filter_var($hostnaam, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return [$hostnaam];
    }

    $adressen = [];
    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($hostnaam, DNS_A);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $adressen[] = $record['ip'];
                }
            }
        }
    }
    if (empty($adressen)) {
        $terugval = @gethostbynamel($hostnaam);
        if (is_array($terugval)) {
            $adressen = $terugval;
        }
    }

    $adressen = array_values(array_unique($adressen));
    sort($adressen);

    return $adressen;
}

/**
 * Zoekt de IPv6-adressen van een hostnaam op (alleen ter informatie).
 */
function zoekIpv6Adressen(string $hostnaam): array
{
    if ($hostnaam === '' || !function_exists('dns_get_record')) {
        return [];
    }
    if (filter_var($hostnaam, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return [$hostnaam];
    }

    $adressen = [];
    $records = @dns_get_record($hostnaam, DNS_AAAA);
    if (is_array($records)) {
        foreach ($records as $record) {
            if (!empty($record['ipv6'])) {
                $adressen[] = $record['ipv6'];
            }
        }
    }

    return array_values(array_unique($adressen));
}

/**
 * Zoekt de nameservers van een domein op. Staat de site op een subdomein
 * (bijv. "shop.voorbeeld.nl") zonder eigen NS-records, dan wordt het
 * bovenliggende domein geprobeerd.
 */
function zoekNameservers(string $hostnaam): array
{
    if ($hostnaam === '' || !function_exists('dns_get_record') || filter_var($hostnaam, FILTER_VALIDATE_IP)) {
        return [];
    }

    $delen = explode('.', $hostnaam);
    while (count($delen) >= 2) {
        $kandidaat = implode('.', $delen);
        $records = @dns_get_record($kandidaat, DNS_NS);
        if (is_array($records) && !empty($records)) {
            $namen = [];
            foreach ($records as $record) {
                if (!empty($record['target'])) {
                    $namen[] = strtolower($record['target']);
                }
            }
            $namen = array_values(array_unique($namen));
            sort($namen);
            if (!empty($namen)) {
                return $namen;
            }
        }
        array_shift($delen);
    }

    return [];
}

/**
 * Reverse DNS (PTR) van een IP-adres: meestal de naam van de server zelf.
 * Geeft null terug als er geen (bruikbare) PTR-naam is.
 */
function zoekReverseDns(string $ip): ?string
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return null;
    }

    // Eerst rechtstreeks in de DNS kijken (PTR-record). gethostbyaddr() gebruikt de resolver van het systeem, en die
    // kijkt EERST in /etc/hosts van de server waarop de monitor draait. Voor het eigen IP-adres van die server staat
    // daar vaak nog de naam uit de installatie-image (bv. "clean-install.<leverancier>.net") - dan kreeg elke site op
    // dezelfde server als de monitor die naam, in plaats van de echte PTR-naam uit de DNS.
    $naam = null;
    $arpa = maakReverseDnsNaam($ip);
    if ($arpa !== null && function_exists('dns_get_record')) {
        // Classless reverse-delegatie (RFC 2317, bij kleine IP-blokken gebruikelijk) geeft eerst een CNAME en dan
        // pas het PTR-record; de resolver volgt die CNAME zelf, dus het PTR-record staat gewoon in het antwoord.
        $records = @dns_get_record($arpa, DNS_PTR);
        foreach ((array) $records as $record) {
            if (!empty($record['target'])) {
                $naam = $record['target'];
                break;
            }
        }
    }

    // Terugval: dns_get_record() niet beschikbaar of geen antwoord.
    if ($naam === null) {
        $naam = @gethostbyaddr($ip);
    }

    if ($naam === false || $naam === null || $naam === '' || $naam === $ip) {
        return null;
    }

    return strtolower(rtrim($naam, '.'));
}

/**
 * Bouwt de reverse-DNS-naam van een IP-adres: 213.154.231.152 => 152.231.154.213.in-addr.arpa, en voor IPv6
 * de nibble-vorm onder ip6.arpa. null bij een ongeldig adres.
 */
function maakReverseDnsNaam(string $ip): ?string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return implode('.', array_reverse(explode('.', $ip))) . '.in-addr.arpa';
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $binair = @inet_pton($ip);
        if ($binair === false) {
            return null;
        }
        return implode('.', array_reverse(str_split(bin2hex($binair)))) . '.ip6.arpa';
    }

    return null;
}

/**
 * Vraagt bij RIPEstat op welk netwerk (AS-nummer + eigenaar) een IP-adres
 * aankondigt. Resultaten worden binnen hetzelfde verzoek onthouden, zodat
 * hetzelfde IP-adres (website én FTP-server op dezelfde machine) maar één
 * keer wordt opgevraagd.
 *
 * @return array{asn: ?int, eigenaar: ?string, fout: ?string}
 */
function zoekNetwerkEigenaar(string $ip): array
{
    static $geheugen = [];

    if (isset($geheugen[$ip])) {
        return $geheugen[$ip];
    }

    $resultaat = ['asn' => null, 'eigenaar' => null, 'fout' => null];

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $resultaat['fout'] = 'Geen geldig IP-adres';
        return $geheugen[$ip] = $resultaat;
    }
    if (!function_exists('curl_init')) {
        $resultaat['fout'] = 'cURL is niet beschikbaar op deze server';
        return $geheugen[$ip] = $resultaat;
    }

    $url = 'https://stat.ripe.net/data/prefix-overview/data.json?resource=' . rawurlencode($ip)
        . '&sourceapp=mijn-websites-monitor';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CompactWebMonitor/1.0; +https://compactweb.nl)',
    ]);
    $antwoord = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlFout = curl_error($ch);
    curl_close($ch);

    if ($antwoord === false || $httpCode !== 200) {
        $resultaat['fout'] = 'RIPEstat niet bereikbaar (' . ($curlFout !== '' ? $curlFout : 'HTTP ' . $httpCode) . ')';
        return $geheugen[$ip] = $resultaat;
    }

    $data = json_decode($antwoord, true);
    $asns = $data['data']['asns'] ?? [];
    if (!is_array($asns) || empty($asns)) {
        $resultaat['fout'] = 'Geen netwerkgegevens gevonden bij RIPEstat';
        return $geheugen[$ip] = $resultaat;
    }

    $resultaat['asn'] = isset($asns[0]['asn']) ? (int) $asns[0]['asn'] : null;
    $resultaat['eigenaar'] = isset($asns[0]['holder']) ? trim((string) $asns[0]['holder']) : null;

    return $geheugen[$ip] = $resultaat;
}

/**
 * Vraagt bij RIPEstat op aan wie het specifieke blok IP-adressen is
 * toegewezen waar een IP-adres in valt (het inetnum-/inet6num-object in de
 * RIPE-database, of het vergelijkbare NetRange-object bij ARIN e.d.).
 * Dat is vaak specifieker dan de netwerkeigenaar: een hostingpartij die
 * ruimte huurt in het netwerk van een datacenter, staat hier meestal met
 * haar eigen naam (bijv. netname "NL-VIMEXX-SHARED", descr "VIMEXX" binnen
 * het netwerk van Stichting DIGI NL). Resultaten worden binnen hetzelfde
 * verzoek onthouden.
 *
 * @return array{netname: ?string, omschrijving: ?string, organisatie: ?string, fout: ?string}
 */
function zoekIpToewijzing(string $ip): array
{
    static $geheugen = [];

    if (isset($geheugen[$ip])) {
        return $geheugen[$ip];
    }

    $resultaat = ['netname' => null, 'omschrijving' => null, 'organisatie' => null, 'fout' => null];

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $resultaat['fout'] = 'Geen geldig IP-adres';
        return $geheugen[$ip] = $resultaat;
    }
    if (!function_exists('curl_init')) {
        $resultaat['fout'] = 'cURL is niet beschikbaar op deze server';
        return $geheugen[$ip] = $resultaat;
    }

    $url = 'https://stat.ripe.net/data/whois/data.json?resource=' . rawurlencode($ip)
        . '&sourceapp=mijn-websites-monitor';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CompactWebMonitor/1.0; +https://compactweb.nl)',
    ]);
    $antwoord = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($antwoord === false || $httpCode !== 200) {
        $resultaat['fout'] = 'IP-toewijzing niet opgehaald';
        return $geheugen[$ip] = $resultaat;
    }

    $data = json_decode($antwoord, true);
    $resultaat = verwerkIpToewijzing(is_array($data) ? ($data['data']['records'] ?? []) : []);

    return $geheugen[$ip] = $resultaat;
}

/**
 * Haalt uit de whois-records van RIPEstat het object van de IP-toewijzing
 * (het eerste record met een inetnum-/inet6num-/NetRange-sleutel - RIPEstat
 * zet het meest specifieke blok vooraan) de netname, omschrijving en
 * organisatienaam. Los van zoekIpToewijzing(), zodat het zonder
 * netwerkverbinding te testen is.
 *
 * @param array $records data.records uit het antwoord: een lijst van
 *                       records, elk een lijst van {key, value}
 * @return array{netname: ?string, omschrijving: ?string, organisatie: ?string, fout: ?string}
 */
function verwerkIpToewijzing($records): array
{
    $resultaat = ['netname' => null, 'omschrijving' => null, 'organisatie' => null, 'fout' => null];

    if (!is_array($records) || empty($records)) {
        $resultaat['fout'] = 'Geen IP-toewijzing gevonden';
        return $resultaat;
    }

    // Voor de zekerheid ook een platte lijst {key, value} accepteren: dan
    // begint elk nieuw object bij een inetnum-/NetRange-sleutel.
    if (isset($records[0]['key'])) {
        $gesplitst = [];
        foreach ($records as $regel) {
            $sleutel = strtolower((string) ($regel['key'] ?? ''));
            if (empty($gesplitst) || in_array($sleutel, ['inetnum', 'inet6num', 'netrange', 'cidr'], true) && !empty(end($gesplitst))) {
                $gesplitst[] = [];
            }
            $gesplitst[count($gesplitst) - 1][] = $regel;
        }
        $records = $gesplitst;
    }

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        $velden = [];
        foreach ($record as $regel) {
            if (!is_array($regel) || !isset($regel['key'], $regel['value'])) {
                continue;
            }
            $sleutel = strtolower(trim((string) $regel['key']));
            $waarde = trim((string) $regel['value']);
            if ($waarde !== '' && !isset($velden[$sleutel])) {
                $velden[$sleutel] = $waarde; // eerste waarde per sleutel (eerste descr-regel)
            }
        }
        if (!isset($velden['inetnum']) && !isset($velden['inet6num']) && !isset($velden['netrange'])) {
            continue;
        }

        $resultaat['netname'] = $velden['netname'] ?? null;
        $resultaat['omschrijving'] = $velden['descr'] ?? null;
        // org-name (RIPE, als het organisatieobject is meegestuurd), OrgName /
        // Customer (ARIN), owner (LACNIC/APNIC-varianten). Het RIPE-veld "org"
        // is alleen een verwijzing (ORG-XXX-RIPE) en telt dus niet mee.
        foreach (['org-name', 'orgname', 'customer', 'owner'] as $sleutel) {
            if (isset($velden[$sleutel])) {
                $resultaat['organisatie'] = $velden[$sleutel];
                break;
            }
        }
        return $resultaat;
    }

    $resultaat['fout'] = 'Geen IP-toewijzing gevonden';
    return $resultaat;
}

/**
 * Maakt van een IP-toewijzing één leesbare naam: bij voorkeur de
 * organisatienaam, anders de (eerste regel van de) omschrijving, anders de
 * netname. Omschrijvingen die niets over de partij zeggen ("Customer
 * network", "Static IP pool", een adres) worden overgeslagen.
 */
function maakIpToewijzingLeesbaar(array $toewijzing): ?string
{
    $nietszeggend = '/customer|klant|static|dynamic|\bpool\b|infrastructure|aggregat|assigned|allocated|reserved|\bnetwork for\b|\bstraat\b|\bstrasse\b|\bstreet\b|^\d|postbus|p\.?o\.? box/i';

    foreach (['organisatie', 'omschrijving'] as $veld) {
        $waarde = trim((string) ($toewijzing[$veld] ?? ''));
        if ($waarde !== '' && preg_match('/[a-z]{2}/i', $waarde) && strlen($waarde) <= 80 && !preg_match($nietszeggend, $waarde)) {
            return $waarde;
        }
    }

    $netname = trim((string) ($toewijzing['netname'] ?? ''));
    if ($netname !== '' && preg_match('/[a-z]{2}/i', $netname) && !preg_match($nietszeggend, $netname)) {
        return $netname;
    }

    return null;
}

/**
 * Maakt de ruwe netwerkeigenaar-naam van RIPEstat leesbaarder. Die heeft
 * vaak de vorm "HANDLE Bedrijfsnaam, LAND" of "HANDLE - Bedrijfsnaam, LAND"
 * (bijv. "CLDIN-NL Your Hosting B.V." of "CLOUDFLARENET - Cloudflare, Inc., US").
 */
function maakNetwerkEigenaarLeesbaar(?string $eigenaar): ?string
{
    if ($eigenaar === null || $eigenaar === '') {
        return null;
    }

    $naam = trim($eigenaar);

    // Landcode aan het eind weghalen (", NL").
    $naam = preg_replace('/,\s*[A-Z]{2}$/', '', $naam);

    // Voorloop-handle in hoofdletters weghalen, maar alleen als er daarna
    // nog een "echte" naam (met kleine letters) overblijft.
    if (preg_match('/^[A-Z0-9][A-Z0-9_-]*(?:\s+-\s+|\s+)(.+)$/', $naam, $m) && preg_match('/[a-z]/', $m[1])) {
        $naam = $m[1];
    }

    return trim($naam, " ,-");
}

/**
 * Zoekt een bekende hostingpartij in een stuk tekst (hostnaam of naam van
 * de netwerkeigenaar). Geeft null terug als er niets herkend wordt.
 */
function herkenHostingpartij(?string $tekst): ?string
{
    if ($tekst === null || $tekst === '') {
        return null;
    }

    $tekst = strtolower($tekst);
    foreach (haalBekendeHostingpartijen() as $patroon => $naam) {
        if (preg_match($patroon, $tekst)) {
            return $naam;
        }
    }

    return null;
}

/**
 * Hoort een hostnaam bij het domein van de site zelf (bijv. "ftp.voorbeeld.nl"
 * bij "voorbeeld.nl")? Zo'n naam zegt niets over de hostingpartij en wordt
 * daarom niet gebruikt om die te herkennen.
 */
function isHostnaamVanSiteZelf(string $hostnaam, string $siteHost): bool
{
    $hostnaam = strtolower($hostnaam);
    $basis = preg_replace('/^www\./', '', strtolower($siteHost));

    return $basis !== '' && ($hostnaam === $basis || substr($hostnaam, -strlen('.' . $basis)) === '.' . $basis);
}

/**
 * Noemt een naam (uit de IP-toewijzing) de site zelf? Bijv. "VOORBEELD-NET"
 * bij voorbeeld.nl: dan heeft de eigenaar van de site een eigen IP-blok, en
 * zegt die naam niets over de hostingpartij.
 */
function noemtSiteZelf(string $naam, string $siteHost): bool
{
    $basis = preg_replace('/^www\./', '', strtolower($siteHost));
    $label = explode('.', $basis)[0] ?? '';
    // Korte labels ("ab.nl") geven te veel toevallige treffers.
    if (strlen($label) < 4) {
        return false;
    }
    $naamPlat = preg_replace('/[^a-z0-9]/', '', strtolower($naam));
    $labelPlat = preg_replace('/[^a-z0-9]/', '', $label);

    return $labelPlat !== '' && strpos($naamPlat, $labelPlat) !== false;
}

/**
 * Hoofdfunctie: verzamelt alle hostinggegevens van één site.
 *
 * @param array $site rij uit de sites-tabel (domein, ftp_host)
 * @return array zie de opbouw van $resultaat hieronder
 */
function bepaalHostingInfo(array $site): array
{
    $siteHost = bepaalKaleHostnaam($site['domein'] ?? '');
    $ftpHost  = bepaalKaleHostnaam($site['ftp_host'] ?? '');

    $resultaat = [
        'domein'          => $site['domein'] ?? '',
        'hostingpartij'   => null,
        'herkend_via'     => null,
        'server_naam'     => null,
        'server_ip'       => null,
        'website_ips'     => [],
        'website_ipv6'    => [],
        'ftp_host'        => $ftpHost !== '' ? $ftpHost : null,
        'ftp_ips'         => [],
        'netwerk_asn'     => null,
        'netwerk_eigenaar'=> null,
        'ip_toewijzing'   => null,
        'nameservers'     => [],
        'cdn'             => null,
        'opmerkingen'     => [],
    ];

    if ($siteHost === '') {
        $resultaat['opmerkingen'][] = 'Geen domeinnaam bekend.';
        return $resultaat;
    }

    // 1. DNS van de website zelf. Staat de kale domeinnaam niet in de DNS,
    //    dan ook de www-variant proberen (en andersom).
    $websiteIps = zoekIpv4Adressen($siteHost);
    if (empty($websiteIps)) {
        $alternatief = strpos($siteHost, 'www.') === 0 ? substr($siteHost, 4) : 'www.' . $siteHost;
        $websiteIps = zoekIpv4Adressen($alternatief);
    }
    $resultaat['website_ips']  = $websiteIps;
    $resultaat['website_ipv6'] = zoekIpv6Adressen($siteHost);
    $resultaat['nameservers']  = zoekNameservers($siteHost);

    // 2. DNS van de FTP-server (als die bekend is).
    $ftpIps = $ftpHost !== '' ? zoekIpv4Adressen($ftpHost) : [];
    $resultaat['ftp_ips'] = $ftpIps;
    if ($ftpHost !== '' && empty($ftpIps)) {
        $resultaat['opmerkingen'][] = 'FTP-server "' . $ftpHost . '" bestaat niet (meer) in de DNS.';
    }

    if (empty($websiteIps) && empty($ftpIps)) {
        $resultaat['opmerkingen'][] = 'De domeinnaam verwijst in de DNS niet naar een IP-adres.';
        return $resultaat;
    }

    // 3. Draait de website achter een CDN/proxy?
    $websiteNetwerk = !empty($websiteIps) ? zoekNetwerkEigenaar($websiteIps[0]) : null;
    $cdnNetwerken = haalCdnNetwerken();
    if ($websiteNetwerk !== null && $websiteNetwerk['asn'] !== null && isset($cdnNetwerken[$websiteNetwerk['asn']])) {
        $resultaat['cdn'] = $cdnNetwerken[$websiteNetwerk['asn']];
    }

    // 4. Welk IP-adres is "de server"? Normaal het IP van de website; achter
    //    een CDN het IP van de FTP-server (als bekend), want het IP van de
    //    website is dan van het CDN.
    $serverIp = null;
    if ($resultaat['cdn'] !== null) {
        if (!empty($ftpIps)) {
            $serverIp = $ftpIps[0];
            $resultaat['opmerkingen'][] = 'Website draait achter ' . $resultaat['cdn'] . ' - de echte server is bepaald via de FTP-server.';
        } else {
            $resultaat['opmerkingen'][] = 'Website draait achter ' . $resultaat['cdn'] . ', waardoor de echte server verborgen is. Vul de FTP-gegevens in om de echte server te kunnen bepalen.';
        }
    } elseif (!empty($websiteIps)) {
        $serverIp = $websiteIps[0];
    } else {
        $serverIp = $ftpIps[0];
        $resultaat['opmerkingen'][] = 'De domeinnaam verwijst niet naar een IP-adres - server bepaald via de FTP-server.';
    }

    if (count($websiteIps) > 1 && $resultaat['cdn'] === null) {
        $resultaat['opmerkingen'][] = 'De domeinnaam verwijst naar ' . count($websiteIps) . ' IP-adressen (' . implode(', ', $websiteIps) . ').';
    }

    // Wijkt de FTP-server af van de webserver? Kan kloppen (aparte
    // FTP-machine), maar kan ook betekenen dat de site verhuisd is en de
    // FTP-gegevens nog naar de oude hostingpartij wijzen.
    if ($resultaat['cdn'] === null && !empty($websiteIps) && !empty($ftpIps) && empty(array_intersect($websiteIps, $ftpIps))) {
        $resultaat['opmerkingen'][] = 'De FTP-server (' . implode(', ', $ftpIps) . ') is een ander IP-adres dan de website - controleer of de FTP-gegevens nog bij de huidige hostingpartij horen.';
    }

    $resultaat['server_ip'] = $serverIp;

    // 5. Servernaam en netwerkeigenaar van dat IP-adres.
    $serverNetwerk = ['asn' => null, 'eigenaar' => null, 'fout' => null];
    if ($serverIp !== null) {
        $resultaat['server_naam'] = zoekReverseDns($serverIp);
        $serverNetwerk = zoekNetwerkEigenaar($serverIp);
        $resultaat['netwerk_asn'] = $serverNetwerk['asn'];
        $resultaat['netwerk_eigenaar'] = $serverNetwerk['eigenaar'];
        if ($serverNetwerk['fout'] !== null) {
            $resultaat['opmerkingen'][] = $serverNetwerk['fout'] . '.';
        }
        // Aan wie is dit specifieke IP-blok toegewezen? Een mislukte
        // opzoeking is geen probleem (dan telt alleen de netwerkeigenaar),
        // en wordt dus ook niet als opmerking getoond.
        $toewijzing = zoekIpToewijzing($serverIp);
        if ($toewijzing['fout'] === null) {
            $delen = array_values(array_unique(array_filter([
                $toewijzing['organisatie'], $toewijzing['omschrijving'], $toewijzing['netname'],
            ])));
            $resultaat['ip_toewijzing'] = !empty($delen) ? implode(' / ', $delen) : null;
        }
    } elseif ($websiteNetwerk !== null) {
        // Alleen het CDN is zichtbaar - toon dat als netwerk.
        $resultaat['netwerk_asn'] = $websiteNetwerk['asn'];
        $resultaat['netwerk_eigenaar'] = $websiteNetwerk['eigenaar'];
    }

    // 6. Hostingpartij afleiden - van meest naar minst specifiek.
    $kandidaten = [];
    if ($resultaat['server_naam'] !== null) {
        $kandidaten[] = [$resultaat['server_naam'], 'servernaam'];
    }
    if ($resultaat['ip_toewijzing'] !== null) {
        $kandidaten[] = [$resultaat['ip_toewijzing'], 'IP-toewijzing (RIPE)'];
    }
    // De naam van de FTP-server telt alleen mee als die ook echt naar de
    // server van de website wijst - anders zou een verhuisde site met nog
    // oude FTP-gegevens (bijv. ftp.oudehoster.nl) de oude partij krijgen.
    if ($ftpHost !== '' && !isHostnaamVanSiteZelf($ftpHost, $siteHost) && in_array($serverIp, $ftpIps, true)) {
        $kandidaten[] = [$ftpHost, 'FTP-server'];
    }
    if ($serverIp !== null && $resultaat['netwerk_eigenaar'] !== null) {
        $kandidaten[] = [$resultaat['netwerk_eigenaar'], 'netwerkeigenaar (RIPE)'];
    }

    // Een datacenter/cloudplatform wordt alleen onthouden als terugval: de
    // partij die daar ruimte huurt (meestal te zien in de IP-toewijzing) is
    // de werkelijke hostingpartij.
    $infraTerugval = null;
    foreach ($kandidaten as [$tekst, $bron]) {
        $naam = herkenHostingpartij($tekst);
        // Een CDN is nooit de hostingpartij zelf.
        if ($naam === null || $naam === $resultaat['cdn']) {
            continue;
        }
        if (isInfrastructuurpartij($naam)) {
            if ($infraTerugval === null) {
                $infraTerugval = [$naam, $bron];
            }
            continue;
        }
        $resultaat['hostingpartij'] = $naam;
        $resultaat['herkend_via'] = $bron;
        break;
    }

    // Geen bekende hostingpartij? Dan de naam uit de IP-toewijzing, als die
    // iets zegt: die is specifieker dan de netwerkeigenaar (bij een blok
    // dat een hostingpartij huurt bij een datacenter, is dat de huurder).
    // Niet als die naam de site zelf is (eigen server met eigen IP-blok) of
    // gewoon weer het datacenter.
    if ($resultaat['hostingpartij'] === null && $serverIp !== null) {
        $toewijzingNaam = isset($toewijzing) && $toewijzing['fout'] === null ? maakIpToewijzingLeesbaar($toewijzing) : null;
        if ($toewijzingNaam !== null
            && !noemtSiteZelf($toewijzingNaam, $siteHost)
            && ($infraTerugval === null || herkenHostingpartij($toewijzingNaam) !== $infraTerugval[0])) {
            $resultaat['hostingpartij'] = $toewijzingNaam;
            $resultaat['herkend_via'] = 'IP-toewijzing (RIPE)';
        }
    }

    if ($resultaat['hostingpartij'] === null && $infraTerugval !== null) {
        [$resultaat['hostingpartij'], $resultaat['herkend_via']] = $infraTerugval;
    }

    if ($resultaat['hostingpartij'] === null && $serverIp !== null && $resultaat['netwerk_eigenaar'] !== null) {
        $resultaat['hostingpartij'] = maakNetwerkEigenaarLeesbaar($resultaat['netwerk_eigenaar']);
        $resultaat['herkend_via'] = 'netwerkeigenaar (RIPE)';
    }

    if ($resultaat['hostingpartij'] === null) {
        $viaNameservers = herkenHostingpartij(implode(' ', $resultaat['nameservers']));
        if ($viaNameservers !== null && $viaNameservers !== $resultaat['cdn']) {
            $resultaat['hostingpartij'] = $viaNameservers;
            $resultaat['herkend_via'] = 'nameservers (onzeker: DNS en hosting kunnen bij verschillende partijen liggen)';
        }
    }

    return $resultaat;
}
