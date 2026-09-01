<?php
// scan-en-check-website.php (voorheen scan_verdacht_v11.php)
// Focus: Backdoors + root-level onbekende mappen + volledige extensielijst
// Uitbreiding t.o.v. v4: dynamische functie-aanroepen, upload-backdoors,
// variabele functies, create_function()
// Uitbreiding t.o.v. v7: verdubbelde mapnamen (models/models, views/views)
// en verdachte .htaccess-bestanden (buiten root, of met zelfbeschermende
// FilesMatch/Require all denied-payload)
// Uitbreiding t.o.v. v10: leest ook de volledige, actuele extensielijst
// (naam/type/versie) rechtstreeks uit de eigen Joomla-database van de site
// (via configuration.php), zodat de monitor niet meer afhankelijk is van
// een handmatig bijgehouden lijst met "bekende" extensies.
// Uitbreiding t.o.v. v11: leest ook Joomla's eigen geregistreerde
// update-locaties (#__update_sites) uit, en haalt daarmee voor elke
// extensie van derden direct zelf de nieuwste beschikbare versie op -
// dus automatische update-controle voor de VOLLEDIGE extensielijst,
// niet alleen voor de handmatige catalogus.
// Alles wat dieper in de structuur zit = niet relevant

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Sommige (vooral goedkopere) hostingpakketten hanteren een vrij krappe
// standaard geheugen- en tijdslimiet (bijv. 128M / 30s) - bij een site met
// veel bestanden/extensies kan de volledige scan (alle bestanden
// doorzoeken + extensiebestanden hashen + resultaat versturen) daar net
// overheen gaan, met een kale HTTP 500 tot gevolg. We proberen dit hier
// zelf te verruimen; lukt dat niet (sommige hostingpartijen blokkeren dit
// bewust), dan wordt dat stilletjes genegeerd met @ - de scan draait dan
// gewoon door binnen de bestaande limiet, zoals voorheen.
@ini_set('memory_limit', '256M');
@set_time_limit(120);

// KRITIEK: expliciet platte tekst, geen HTML. Zonder dit interpreteert de
// browser deze pagina standaard als HTML - en als de ruwe inhoud van een
// opgehaalde update-feed toevallig een <meta http-equiv="refresh">-tag
// bevat (bijv. Balbooa's SiteGround-captchapagina), voert de browser die
// zomaar uit en springt de pagina zelf onmiddellijk weg. Met een expliciete
// text/plain-header wordt zulke ingesloten HTML altijd als kale tekst
// getoond, nooit uitgevoerd.
header('Content-Type: text/plain; charset=utf-8');

$geheimeCode = '__GEHEIME_CODE__';
// realpath() erbij: __DIR__ alleen volstaat niet als er ergens in het pad
// (bijv. /home/gebruikersnaam zelf) een symlink zit - dan zou __DIR__ een
// andere, niet-kanonieke tekstvorm geven dan de kanonieke vorm die
// realpath() verderop consequent gebruikt (o.a. bij de automatische
// detectie van het extra scanpad), met als gevolg dat een simpele
// tekstvergelijking tussen de twee (bijv. "is dit pad een submap van dat
// pad") ten onrechte niet zou matchen ondanks dat het feitelijk dezelfde
// map is.
$startMap = realpath(__DIR__) ?: __DIR__;

// VINGERAFDRUK - COMPACTWEB_MONITOR_SCANSCRIPT_9f3c7a1e2b4d - deze exacte
// regel staat, ongewijzigd, in ELK scanscript dat ooit uit dit sjabloon is
// gegenereerd (ongeacht bestandsnaam, monitor-installatie of geheime code).
// Wordt gebruikt om zulke scanscripts betrouwbaar als "eigen/vertrouwd" te
// herkennen op basis van INHOUD in plaats van bestandsnaam - nodig zodra
// dezelfde site door meerdere monitor-installaties wordt beheerd (elk met
// een eigen, willekeurig gegenereerde bestandsnaam), of na een handmatige
// FTP-herupload met een nieuw volgnummer. Zie isEigenScanScriptInhoud().
function isEigenScanScriptInhoud($inhoud)
{
    return strpos((string) $inhoud, 'COMPACTWEB_MONITOR_SCANSCRIPT_9f3c7a1e2b4d') !== false;
}

// ----------------------------------------------------------------------
// Onzichtbare/verhullende Unicode-tekens - gebruikt om een kwaadaardige
// bestandsnaam te verhullen (bijv. lijkt in een bestandsmanager op
// "foto.jpg", bevat in werkelijkheid een onzichtbaar teken vóór ".php").
// Zero-width space/non-joiner/joiner, BOM, en de RTL-override (kan de
// LEESVOLGORDE van een naam omdraaien, bijv. "gnp.exe" tonen terwijl het
// bestand echt "exe.png" heet) zijn de meest gebruikte varianten.
// ----------------------------------------------------------------------
function bevatOnzichtbareUnicodeTekens($tekst)
{
    return (bool) preg_match('/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{202E}\x{2060}]/u', (string) $tekst);
}

// ----------------------------------------------------------------------
// 0-byte bestand met een SHA-1-hash-achtige naam (evt. met een korte
// willekeurige toevoeging erachter, bijv. "da39a3ee5e6b4b0d3255bfef95601
// 890afd80709BGDtRy") - een bekend restant van geautomatiseerde exploit-
// scanner-/probe-tools. Zulke tools testen op grote schaal of een map
// schrijfbaar is door er een leeg testbestand met een pseudo-willekeurige
// naam neer te zetten en te checken of het via HTTP zichtbaar wordt,
// vaak met "da39a3ee5e6b4b0d3255bfef95601890afd80709" zelf (de bekende
// SHA-1-hash van een lege string) als vaste basis. Het bestand is op
// zichzelf onschuldig (0 bytes, kan geen code bevatten), maar het is wel
// bewijs dat een geautomatiseerde tool ooit schrijftoegang tot die map
// heeft gehad - de moeite van het weten waard, vandaar een lage,
// informatieve melding in plaats van stilzwijgend negeren.
// ----------------------------------------------------------------------
function isVermoedelijkExploitScannerRestant($bestandsnaam, $bestandsgrootte)
{
    if ($bestandsgrootte !== 0) {
        return false;
    }
    return (bool) preg_match('/^[0-9a-f]{40}[a-zA-Z0-9]{0,12}$/', $bestandsnaam);
}

// Google Search Console-verificatiebestanden: altijd dit vaste patroon,
// komt op bijna elke site voor, nooit een bedreiging.
function isGoogleVerificatie($naam)
{
    return (bool) preg_match('/^google[a-f0-9]{10,}\.html$/i', $naam);
}

// mySites.guru (een vergelijkbare externe monitoringdienst) laat op sites
// die zij controleren checksumbestanden achter met dit patroon, om
// kernbestanden te kunnen controleren - onschuldig, geen malware.
function isMyJoomlaChecksum($naam)
{
    return (bool) preg_match('/^\.myjoomla\..+\.md5$/i', $naam);
}

// ----------------------------------------------------------------------
// Cloaking-detectie: een schoon Joomla-kernbestand (index.php,
// administrator/index.php) checkt nooit zelf de user-agent op
// zoekmachine-bots, en haalt nooit losse, externe inhoud op. De combinatie
// van BEIDE soorten patronen in hetzelfde kernbestand is een sterk signaal
// voor een cloaking-aanval: andere (vaak spam-/malware-)inhoud tonen aan
// Googlebot/Bingbot dan aan gewone bezoekers, zodat de eigenaar het zelf
// niet snel opmerkt. Losse patronen komen soms ook legitiem voor (bijv.
// file_get_contents voor iets onschuldigs) - vandaar dat alleen de
// COMBINATIE hier gemeld wordt, niet elk patroon apart.
// ----------------------------------------------------------------------
function detecteerCloakingInKernbestand($inhoud)
{
    $botDetectiePatronen = ['HTTP_USER_AGENT', 'Googlebot', 'googlebot', 'bingbot'];
    $externCodePatronen = ['file_get_contents(', 'fopen(', 'curl_exec(', 'curl_init(', 'readfile('];

    $gevondenBot = [];
    foreach ($botDetectiePatronen as $patroon) {
        if (stripos($inhoud, $patroon) !== false) {
            $gevondenBot[] = $patroon;
        }
    }

    if (empty($gevondenBot)) {
        return null;
    }

    $gevondenExtern = [];
    foreach ($externCodePatronen as $patroon) {
        if (stripos($inhoud, $patroon) !== false) {
            $gevondenExtern[] = $patroon;
        }
    }

    if (empty($gevondenExtern)) {
        return null;
    }

    return 'CLOAKING-VERDACHT: bot-detectie (' . implode(', ', $gevondenBot)
        . ') + extern-content-ophalen (' . implode(', ', $gevondenExtern) . ') in hetzelfde kernbestand - '
        . 'kenmerkend voor een aanval die andere inhoud toont aan zoekmachines dan aan bezoekers';
}

// ----------------------------------------------------------------------
// Massaal-hernoemen-detectie: sommige aanvallen voegen aan bijna alle
// bestanden/mappen in de webroot hetzelfde vreemde achtervoegsel toe
// (bijv. "bestand.php__113576e"), waardoor Joomla niet meer kan starten.
// Eén zo'n bestand is toeval of een eigen back-upnaam; VIJF OF MEER met
// exact hetzelfde achtervoegsel is vrijwel zeker geen toeval.
// ----------------------------------------------------------------------
function vindMassaleHernoeming($startMap, &$rootUnknown)
{
    $suffixTellingen = [];
    $voorbeeldPerSuffix = [];
    $maxTeControlerenItems = 20000; // veiligheidsgrens tegen te lange scans
    $geteld = 0;

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($startMap, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
    } catch (Exception $e) {
        return;
    }

    foreach ($iterator as $itemInfo) {
        $geteld++;
        if ($geteld > $maxTeControlerenItems) {
            break;
        }

        $pad = $itemInfo->getPathname();
        if (preg_match('#[/\\\\](cache|logs?|__MACOSX|tmp)[/\\\\]#i', $pad)) {
            continue;
        }

        $naam = $itemInfo->getFilename();

        if (preg_match('/__([a-z0-9]{6,12})(\.[a-z0-9]{1,6})?$/i', $naam, $match)) {
            $suffix = strtolower($match[1]);
            if (!isset($suffixTellingen[$suffix])) {
                $suffixTellingen[$suffix] = 0;
                $voorbeeldPerSuffix[$suffix] = $naam;
            }
            $suffixTellingen[$suffix]++;
        }
    }

    foreach ($suffixTellingen as $suffix => $aantal) {
        if ($aantal >= 5) {
            $rootUnknown[] = [
                // BEWUST 'cluster', niet 'bestand' of 'map': dit is een
                // VERZAMELMELDING over meerdere bestanden tegelijk, geen
                // enkel, op zichzelf staand item. Er bestaat geen zinnig
                // "verwijder dit ene ding" voor een verzamelmelding - zie
                // de toelichting bij vindMassaleUpload() hieronder voor het
                // waarom (een eerdere versie hiervan liet de UI, via een
                // schuivende parsing-fout, per ongeluk een volledige map
                // aanwijzen als doelwit van Quarantaine/Blokkeer/Verwijder).
                'type' => 'cluster',
                // GEEN haakjes in 'naam': verdacht_details wordt weggeschreven
                // als platte tekst in het formaat "[type] naam (gewijzigd) -
                // reden [risico=N]" en later weer teruggeparsed met een regex
                // die op het EERSTE/LAATSTE haakjespaar let (parseVerdachtDetails()
                // in verdacht_functies.php). Haakjes binnen 'naam' zelf laten
                // die regex het verkeerde stuk als 'naam' teruggeven.
                'naam' => "__{$suffix} - achtervoegsel, {$aantal}x aangetroffen",
                'pad' => $startMap,
                'risico' => 95,
                'gewijzigd' => date('Y-m-d H:i'),
                'reden_override' => "MASSAAL HERNOEMEN GEDETECTEERD: {$aantal} bestanden/mappen met hetzelfde verdachte "
                    . "achtervoegsel '__{$suffix}' (bijv. '{$voorbeeldPerSuffix[$suffix]}') - kenmerkend voor een "
                    . "hernoemaanval die de hele website onbereikbaar maakt. Controleer dit met voorrang.",
            ];
        }
    }
}

// ----------------------------------------------------------------------
// Massale-upload-detectie: een geautomatiseerde uploadtool plaatst vaak
// dezelfde payload onder tientallen verschillende bestandsnamen ÉN
// extensies (bv. up_1031.pHp, up_1936.php, up_1946.Pht, up_6089.PhP5,
// ... - allemaal exact 933 bytes) om te testen welke extensie de server
// daadwerkelijk als PHP uitvoert. vindMassaleHernoeming() hierboven let
// juist op een GEDEELD naam-achtervoegsel - dit patroon heeft daarentegen
// bewust overal verschillende namen, dus dat mist dit volledig. Hier wordt
// in plaats daarvan gegroepeerd op bestandsgrootte per map: vijf of meer
// verschillend genoemde, kleine bestanden met exact dezelfde grootte in
// dezelfde map is vrijwel nooit toeval. Echt aangetroffen (augustus 2026,
// images/- en tmp-map: clusters van 933/514/1.106 bytes).
//
// BELANGRIJK, n.a.v. een valse-positieven-golf bij een eerste, te brede
// versie van deze functie: bewust NIET de hele website doorzoeken. Een
// gemiddelde Joomla-site zit vol met legitieme clusters van gelijk-grote
// bestanden - vendor-libraries (bv. phpseclib's EC-curve-bestanden,
// php-tuf's fixture-sleutels), complete taalpakketten (tientallen
// .ini-bestanden van vergelijkbare grootte) en sjabloon-iconensets
// (vlaggetjes, smileys) leveren allemaal dezelfde signatuur op als een
// echte uploadgolf, puur omdat ze uit veel kleine, onderling vergelijkbare
// bestanden bestaan. Alleen de twee mappen waar de kwetsbaarheid ook
// daadwerkelijk vandaan komt - images/ en tmp/ op het topniveau van de
// site - worden daarom doorzocht, net als bij scanNietPhpBestandOpVerstopteCode()
// hierboven.
// ----------------------------------------------------------------------
function vindMassaleUpload($startMap, &$rootUnknown)
{
    $gevoeligeUploadmapNamen = ['images', 'tmp'];
    $maxGrootteVoorClusterCheck = 50000; // legitieme, toevallig identieke bestanden boven deze grootte zijn zeldzaam; payloads waren enkele honderden tot ~1.100 bytes
    $maxTeControlerenItems = 20000; // veiligheidsgrens tegen te lange scans
    $geteld = 0;

    $groottePerMap = []; // [map => [grootte => [bestandsnamen...]]]

    foreach ($gevoeligeUploadmapNamen as $mapNaam) {
        $volledigeUploadmap = $startMap . '/' . $mapNaam;
        if (!is_dir($volledigeUploadmap)) {
            continue;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($volledigeUploadmap, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
        } catch (Exception $e) {
            continue;
        }

        foreach ($iterator as $itemInfo) {
            $geteld++;
            if ($geteld > $maxTeControlerenItems) {
                break 2;
            }

            if (!$itemInfo->isFile()) {
                continue;
            }

            $pad = $itemInfo->getPathname();
            // Behalve de generieke cache/log-mappen ook de bekende, vaste
            // mapnamen die JCH Optimize (een veelgebruikte, legitieme
            // Joomla-snelheidsextensie) zelf aanmaakt voor back-ups en
            // geconverteerde afbeeldingen:
            //  - "jch_optimize_backup_images": back-ups van originele
            //    afbeeldingen vóór optimalisatie (bv. hetzelfde logo meerdere
            //    keren opgeslagen voor verschillende templateposities/
            //    schermbreedtes - vandaar bijna identieke bestandsgroottes);
            //  - "jch-optimize/ng": WebP-("next-gen"-)conversies;
            //  - "jch-optimize/rs/<breedte>": responsive, op vaste breedtes
            //    verkleinde kopieën.
            // Al dit soort automatisch gegenereerde content van dezelfde
            // bronafbeeldingen clustert van nature in bestandsgrootte - geen
            // enkele relatie met een geautomatiseerde uploadtool. Alleen
            // deze CLUSTERdetectie wordt hier overgeslagen; de gewone
            // inhoudscontrole (dubbele-extensietruc/verstopte PHP-code)
            // blijft in deze mappen gewoon actief. Ontdekt bij een echte,
            // legitieme site (augustus 2026).
            if (preg_match('#[/\\\\](cache|logs?|__MACOSX|_scan_beheer|jch_optimize_backup_images|jch-optimize)[/\\\\]#i', $pad)) {
                continue;
            }

            $grootte = $itemInfo->getSize();
            if ($grootte === 0 || $grootte > $maxGrootteVoorClusterCheck) {
                continue;
            }

            $map = dirname($pad);
            $groottePerMap[$map][$grootte][] = $itemInfo->getFilename();
        }
    }

    foreach ($groottePerMap as $map => $groottes) {
        foreach ($groottes as $grootte => $namen) {
            if (count($namen) < 5) {
                continue;
            }

            // Meerdere VERSCHILLENDE extensies binnen hetzelfde cluster is het
            // sterkste signaal (precies het extensie-test-patroon); allemaal
            // dezelfde extensie kán ook een legitiem sjabloon-iconenset e.d.
            // zijn, dus dat melden we lichter en met een andere toonzetting.
            $extensies = [];
            foreach ($namen as $naam) {
                $extensies[strtolower(pathinfo($naam, PATHINFO_EXTENSION))] = true;
            }

            // Zijn ALLE aanwezige extensies in dit cluster sowieso nooit als
            // PHP uit te voeren (audio/video/documentformaten)? Dan slaan we
            // deze melding helemaal over, ongeacht of dat er één of meerdere
            // verschillende zijn. Het hele idee achter deze detectie is een
            // geautomatiseerde tool die dezelfde payload onder meerdere
            // extensies test om te zien welke de server als PHP uitvoert
            // (isPhpUitvoerbareExtensie()) - een cluster van bv. zes gelijk-
            // grote .mid-bestanden (of een combinatie van .mid+.wav van
            // hetzelfde stuk) kan dat per definitie nooit zijn, want geen
            // van die extensies wordt door enige reguliere serverconfiguratie
            // ooit als PHP aangemerkt. Concreet aangetroffen bij een muziek-
            // oefensite: zes koorstemmen (Soprano1/2, Tenore, Contralto, ...)
            // van hetzelfde werk, allemaal .mid, toevallig (vrijwel) even
            // groot omdat ze uit dezelfde notatiesoftware komen - een
            // volkomen onschuldige, verklaarbare situatie die toch "handmatig
            // controleren via FTP" opleverde. Afbeeldingsextensies (jpg/png/
            // gif/bmp/svg/etc.) blijven bewust WEL gemeld: dat zijn precies
            // de formaten die bij een dubbele-extensietruc/polyglot-bestand
            // worden misbruikt (zie isXmlAchtigeProcessingInstructie() en
            // scanNietPhpBestandOpVerstopteCode() hierboven), dus daar is
            // menselijke controle nog steeds zinvol.
            $nooitUitvoerbareExtensies = [
                // audio
                'mid', 'midi', 'wav', 'mp3', 'ogg', 'oga', 'flac', 'm4a', 'aac', 'wma',
                // video
                'mp4', 'mov', 'avi', 'webm', 'mkv', 'wmv', 'm4v',
                // documenten
                'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'csv',
            ];
            $alleExtensiesVeilig = true;
            foreach (array_keys($extensies) as $ext) {
                if ($ext === '' || !in_array($ext, $nooitUitvoerbareExtensies, true)) {
                    $alleExtensiesVeilig = false;
                    break;
                }
            }
            if ($alleExtensiesVeilig) {
                continue; // geen enkel reëel risico, deze melding overslaan
            }

            $relatieveMap = str_replace($startMap, '', $map);
            if ($relatieveMap === '') {
                $relatieveMap = '/';
            }
            $voorbeeld = implode(', ', array_slice($namen, 0, 5));

            // Meest recente werkelijke bestandsdatum binnen dit cluster, om
            // te TONEN in de "Gewijzigd"-kolom - puur informatief. Dit is
            // bewust losgekoppeld van de "vertrouwen"-hash zelf (zie
            // verdacht_functies.php: 'cluster' telt de datum daar toch al
            // niet mee), maar het scanmoment zelf tonen als "Gewijzigd" was
            // sowieso misleidend - de bestanden zelf kunnen al veel ouder
            // zijn (bv. boekomslagen van maanden terug).
            $meestRecenteWijziging = 0;
            foreach ($namen as $bestandsnaamInCluster) {
                $mtijd = @filemtime($map . DIRECTORY_SEPARATOR . $bestandsnaamInCluster);
                if ($mtijd !== false && $mtijd > $meestRecenteWijziging) {
                    $meestRecenteWijziging = $mtijd;
                }
            }
            $clusterGewijzigd = $meestRecenteWijziging > 0 ? date('Y-m-d H:i', $meestRecenteWijziging) : date('Y-m-d H:i');

            // BEWUST 'type' => 'cluster', niet 'map': dit is een VERZAMEL-
            // melding over meerdere afzonderlijke bestanden in een map die
            // verder gewoon legitieme content bevat (bijv. "images/" met
            // ook duizenden echte foto's) - niet "deze hele map is verdacht".
            // Een eerdere versie gebruikte 'map' hier, met 'naam' als
            // "/images (18 bestanden, ...)" - dat leidde tot een ernstige
            // fout: verdacht_details wordt weggeschreven en teruggeparsed
            // als platte tekst in het formaat "[type] naam (gewijzigd) -
            // reden [risico=N]" (zie parseVerdachtDetails() in
            // verdacht_functies.php). Met haakjes AL in 'naam' zelf, greep
            // die regex het verkeerde haakjespaar en leverde als
            // teruggeparste 'naam' alleen het kale "/images" op - een ECHT
            // BESTAAND pad. De Verwijder/Quarantaine/Blokkeer-knoppen sturen
            // precies die teruggeparste 'naam' als doelwit mee, dus een
            // klik op "Verwijder" verwijderde daardoor de HELE map, inclusief
            // alle legitieme bestanden erin, in plaats van alleen de 18
            // gemelde bestanden. Ontdekt en gemeld door Wouter (augustus 2026).
            //
            // Twee onafhankelijke vangnetten tegelijk:
            //  1. 'type' => 'cluster' laat beveiliging.php de destructieve
            //     knoppen (Quarantaine/Blokkeer/Verwijder) helemaal niet
            //     tonen voor dit soort verzamelmeldingen - net als nu al
            //     gebeurt bij 'database'-bevindingen.
            //  2. 'naam' bevat bewust GEEN haakjes meer, zodat zelfs zonder
            //     wijziging 1 (bv. bij hergebruik van deze data elders) de
            //     round-trip-parsing niet meer op een kort, wél bestaand pad
            //     kan uitkomen.
            if (count($extensies) >= 3) {
                $rootUnknown[] = [
                    'type' => 'cluster',
                    'naam' => $relatieveMap . ' - cluster van ' . count($namen) . " bestanden, elk exact {$grootte} bytes",
                    'pad' => $map,
                    'risico' => 90,
                    'gewijzigd' => $clusterGewijzigd,
                    'reden_override' => 'MASSALE UPLOAD GEDETECTEERD: ' . count($namen) . ' verschillend genoemde bestanden ('
                        . count($extensies) . ' verschillende extensies) in "' . $relatieveMap . '" met exact dezelfde bestandsgrootte '
                        . "({$grootte} bytes), bijv. {$voorbeeld} - kenmerkend voor een geautomatiseerde uploadtool die dezelfde payload "
                        . 'onder meerdere extensies test om te zien welke de server als PHP uitvoert. Bekijk en verwijder de afzonderlijke '
                        . 'bestanden handmatig via FTP - deze melding zelf is niet los te verwijderen, want de map bevat ook legitieme content.',
                ];
            } else {
                $rootUnknown[] = [
                    'type' => 'cluster',
                    'naam' => $relatieveMap . ' - cluster van ' . count($namen) . " bestanden, elk exact {$grootte} bytes",
                    'pad' => $map,
                    'risico' => 45,
                    'gewijzigd' => $clusterGewijzigd,
                    'reden_override' => 'Cluster van ' . count($namen) . ' verschillend genoemde bestanden in "' . $relatieveMap
                        . '" met exact dezelfde bestandsgrootte (' . $grootte . " bytes), bijv. {$voorbeeld} - kan toeval zijn "
                        . '(bv. een sjabloon-iconenset), maar is ook een bekend patroon bij bulk-upload. Handmatig controleren via FTP.',
                ];
            }
        }
    }
}

// ============================================================================
// BEHEERSYSTEEM: quarantaine/blokkeer/verwijder/herstel/bekijk
// ============================================================================
// Maakt het mogelijk om, vanaf de monitor (beveiliging.php), rechtstreeks
// actie te ondernemen op een gevonden verdacht bestand - zonder FTP nodig
// te hebben. Alles gebeurt herstelbaar: quarantaine en "verwijderen"
// verplaatsen het item alleen maar (naar een afgeschermde map, met
// chmod 0400), "blokkeren" hernoemt het ter plekke. Pas na een vaste
// bewaartermijn wordt een naar de prullenbak verplaatst item definitief
// verwijderd.
//
// Alles wat dit systeem aanmaakt staat in ÉÉN eigen map (_scan_beheer/),
// die via .htaccess volledig van het web is afgeschermd.

$beheerMap        = $startMap . '/_scan_beheer';
$quarantaineMap   = $beheerMap . '/quarantaine';
$prullenbakMap    = $beheerMap . '/prullenbak';
$beheerManifest   = $beheerMap . '/acties.json';
$prullenbakDagen  = 7;

function zorgBeheerMap(string $beheerMap, string $quarantaineMap, string $prullenbakMap): void
{
    foreach ([$beheerMap, $quarantaineMap, $prullenbakMap] as $map) {
        if (!is_dir($map)) {
            @mkdir($map, 0755, true);
        }
    }

    $ht = $beheerMap . '/.htaccess';
    $htInhoud = "# Afgeschermd - alleen toegankelijk via het scanscript zelf\n"
        . "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
    if (!file_exists($ht) || file_get_contents($ht) !== $htInhoud) {
        @file_put_contents($ht, $htInhoud);
    }

    foreach ([$beheerMap, $quarantaineMap, $prullenbakMap] as $map) {
        if (!file_exists($map . '/index.html')) {
            @file_put_contents($map . '/index.html', '');
        }
    }
}

/**
 * Controleert of een opgegeven (relatief) pad daadwerkelijk binnen de
 * website-root valt, en niet dit scanscript zelf is - voorkomt dat een
 * kwaadwillende via een handig geconstrueerd pad buiten de site zou
 * kunnen komen ("directory traversal").
 *
 * @return string|false
 */
function veiligPad(string $pad, string $startMap)
{
    // Basisveiligheid vooraf: geen "..", zodat we hieronder ook zonder een
    // geslaagde realpath() van het eindbestand zelf nooit buiten $startMap
    // kunnen belanden.
    $genormaliseerd = str_replace('\\', '/', ltrim($pad, '/'));
    if ($genormaliseerd === '' || strpos($genormaliseerd, '..') !== false) {
        return false;
    }

    $volledigPad = $startMap . '/' . $genormaliseerd;

    // PHP's eigen realpath-cache kan hier een verouderd "bestaat niet"-
    // resultaat vasthouden (standaard tot 120 seconden) - met name lastig
    // bij bestanden die net (via FTP) zijn geplaatst, vlak vóór het eerste
    // gebruik van deze pagina. clearstatcache() met een specifiek pad is
    // een goedkope operatie (in tegenstelling tot zonder argumenten, wat
    // de HELE cache zou legen) en voorkomt dit soort valse meldingen.
    clearstatcache(true, $volledigPad);

    $rootReal = realpath($startMap);
    if ($rootReal === false) {
        return false;
    }

    $real = realpath($volledigPad);

    if ($real === false) {
        // Op sommige hostingomgevingen geeft een losse stat/realpath-check
        // op dit exacte bestand incidenteel ten onrechte "niet gevonden"
        // terug (bijv. door een cachelaag van het onderliggende
        // bestandssysteem, los van PHP's eigen realpath-cache), terwijl
        // een gewone mapinventarisatie (scandir) - precies wat de scan
        // zelf gebruikt om dit item te vinden - het bestand wél gewoon
        // toont. Als terugvaloptie vertrouwen we daarom op scandir() van
        // de (betrouwbaar op te lossen) bovenliggende map, in plaats van
        // meteen "bestaat niet" te concluderen.
        $delen = explode('/', $genormaliseerd);
        $bestandsnaam = array_pop($delen);
        $submapPad = $rootReal . ($delen ? '/' . implode('/', $delen) : '');
        clearstatcache(true, $submapPad);
        $submapReal = realpath($submapPad);

        if ($submapReal === false
            || ($submapReal !== $rootReal && strpos($submapReal, $rootReal . DIRECTORY_SEPARATOR) !== 0)
        ) {
            return false; // de map zelf is ook niet op te lossen - dan bestaat het echt niet
        }

        $items = @scandir($submapReal) ?: [];
        if (in_array($bestandsnaam, $items, true)) {
            $echteNaam = $bestandsnaam;
        } else {
            // Bestandsnamen met een spatie voor/achteraan (bijv. per ongeluk
            // zo via FTP geplaatst) komen er, door de platte-tekst-opslag
            // van gevonden items ([type] naam (datum) - reden), vertrimd
            // uit te zien in het beveiligingsrapport - de spatie zelf is
            // dan niet meer zichtbaar/beschikbaar. Daarom hier ook een
            // match op de getrimde naam proberen, en zo ja: de écht
            // bestaande (ongetrimde) bestandsnaam gebruiken.
            $echteNaam = null;
            foreach ($items as $i) {
                if (trim($i) === $bestandsnaam) {
                    $echteNaam = $i;
                    break;
                }
            }
            if ($echteNaam === null) {
                return false; // scandir bevestigt ook niet: dan bestaat het echt niet
            }
        }

        $real = $submapReal . '/' . $echteNaam;
    } elseif ($real !== $rootReal && strpos($real, $rootReal . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }

    $eigenBestand = realpath(__FILE__);
    if ($eigenBestand !== false && $real === $eigenBestand) {
        return false; // nooit dit scanscript zelf
    }
    return $real;
}

/**
 * Zoals veiligPad(), maar staat - uitsluitend voor read-only gebruik bij
 * de "bekijk"-actie - ook paden toe die binnen het ingestelde EXTRA
 * scanpad vallen (bijv. een awstats-map naast public_html), ook al
 * liggen die buiten de eigenlijke website-root. Wijzigende acties
 * (quarantaine/blokkeren/verwijderen) gebruiken bewust de striktere
 * veiligPad() hierboven, niet deze ruimere variant.
 *
 * @return string|false
 */
function veiligPadRuim(string $pad, string $startMap, ?string $extraRoot)
{
    $direct = veiligPad($pad, $startMap);
    if ($direct !== false) {
        return $direct;
    }

    if ($extraRoot === null) {
        return false; // geen extra scanpad (auto-detectie vond niets, of staat uit)
    }

    // $pad kan hier zowel een pad relatief aan $startMap zijn, als een
    // kant-en-klaar absoluut pad (zoals opgeslagen bij vondsten die de
    // scan binnen het extra scanpad zelf heeft gevonden) - allebei
    // proberen.
    foreach ([$startMap . '/' . ltrim($pad, '/'), $pad] as $kandidaat) {
        clearstatcache(true, $kandidaat);
        $real = @realpath($kandidaat);
        if ($real === false) {
            continue;
        }
        if ($real === $extraRoot || strpos($real, $extraRoot . DIRECTORY_SEPARATOR) === 0) {
            if ($real === realpath(__FILE__)) {
                return false; // nooit dit scanscript zelf
            }
            return $real;
        }
    }

    return false;
}

function beheerVerwijderRecursief(string $pad): bool
{
    if (is_file($pad) || is_link($pad)) {
        @chmod($pad, 0644);
        return @unlink($pad);
    }
    if (!is_dir($pad)) {
        return false;
    }
    @chmod($pad, 0755);
    $items = @scandir($pad);
    if ($items === false) {
        return false;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        beheerVerwijderRecursief($pad . '/' . $item);
    }
    return @rmdir($pad);
}

// Leegt de inhoud van tmp/ direct en DEFINITIEF (geen prullenbak) - dat is
// bewust zo gekozen: tmp/ is Joomla's eigen tijdelijke schrijfmap, en de
// inhoud ervan wordt door Joomla zelf al als wegwerpbaar behandeld. Wordt
// sinds 1.16 automatisch, stil, bij het begin van elke volledige scan
// aangeroepen (zie de aanroep vlak vóór "=== JOOMLA BACKDOOR-SCAN") - niet
// langer als losse, handmatige actie vanuit de monitor (die knop is met
// deze automatisering weer verwijderd: hij loste hetzelfde probleem op,
// maar vereiste dat iemand er wél aan dacht om hem te gebruiken).
//
// KRITIEK VEILIGHEIDSPUNT: het doelpad is hier ALTIJD hardcoded naar exact
// "$startMap/tmp" - er wordt nergens een pad van buitenaf (POST-gegevens
// e.d.) overgenomen voor deze functie. Dat is een directe les uit een
// eerder gevonden bug (augustus 2026): een destructieve actie die op basis
// van cliëntgegevens een pad bepaalde, kon door een omweg in de opslag-/
// parsing-laag op een ander, onbedoeld pad uitkomen. Bij deze functie is
// dat scenario volledig uitgesloten, simpelweg omdat er geen input voor
// het doelpad bestaat.
function legeTmpMapAutomatisch(string $startMap): array
{
    $tmpMap = $startMap . '/tmp';
    $tmpMapReal = @realpath($tmpMap);
    $rootReal = @realpath($startMap);

    // Extra controle, ook al is het pad hierboven al hardcoded: de
    // uiteindelijke, opgeloste locatie moet daadwerkelijk een map zijn en
    // daadwerkelijk (rechtstreeks) binnen de website-root vallen.
    if ($tmpMapReal === false || $rootReal === false || !is_dir($tmpMapReal)
        || $tmpMapReal !== $rootReal . DIRECTORY_SEPARATOR . 'tmp') {
        return ['succes' => false, 'foutmelding' => 'tmp-map niet gevonden op de verwachte locatie (' . $startMap . '/tmp).'];
    }

    $items = @scandir($tmpMapReal);
    if ($items === false) {
        return ['succes' => false, 'foutmelding' => 'Kon de inhoud van de tmp-map niet lezen (mogelijk een rechtenprobleem).'];
    }

    $aantalVerwijderd = 0;
    $aantalMislukt = 0;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if (beheerVerwijderRecursief($tmpMapReal . '/' . $item)) {
            $aantalVerwijderd++;
        } else {
            $aantalMislukt++;
        }
    }

    return ['succes' => true, 'aantal_verwijderd' => $aantalVerwijderd, 'aantal_mislukt' => $aantalMislukt];
}

function maakOnbegaanbaar(string $pad): void
{
    if (is_file($pad)) {
        @chmod($pad, 0400);
        return;
    }
    if (is_dir($pad)) {
        @chmod($pad, 0700);
        $items = @scandir($pad);
        if ($items) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                maakOnbegaanbaar($pad . '/' . $item);
            }
        }
    }
}

function maakWeerBegaanbaar(string $pad): void
{
    if (is_file($pad)) {
        @chmod($pad, 0644);
        return;
    }
    if (is_dir($pad)) {
        @chmod($pad, 0755);
        $items = @scandir($pad);
        if ($items) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                maakWeerBegaanbaar($pad . '/' . $item);
            }
        }
    }
}

function beheerLaadJson(string $bestand): array
{
    if (!file_exists($bestand)) {
        return [];
    }
    $data = json_decode(file_get_contents($bestand), true);
    return is_array($data) ? $data : [];
}

function beheerBewaarJson(string $bestand, array $data): void
{
    file_put_contents($bestand, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function beheerNieuwId(): string
{
    return date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 6);
}

/**
 * Ruimt verlopen prullenbak-items definitief op - draait bij elke aanroep
 * van dit scanscript (zowel een volledige scan als een beheeractie).
 */
function ruimVerlopenPrullenbakOp(string $beheerManifest): void
{
    $manifest = beheerLaadJson($beheerManifest);
    $gewijzigd = false;
    $nu = time();

    foreach ($manifest as $i => $entry) {
        if (($entry['actie'] ?? '') === 'prullenbak' && !empty($entry['verloopt']) && $entry['verloopt'] <= $nu) {
            if (!empty($entry['opslag']) && file_exists($entry['opslag'])) {
                beheerVerwijderRecursief($entry['opslag']);
            }
            unset($manifest[$i]);
            $gewijzigd = true;
        }
    }

    if ($gewijzigd) {
        beheerBewaarJson($beheerManifest, array_values($manifest));
    }
}

zorgBeheerMap($beheerMap, $quarantaineMap, $prullenbakMap);
ruimVerlopenPrullenbakOp($beheerManifest);

// ----------------------------------------------------------------------
// Extra scanpad (buiten de website-root) - automatische detectie op basis
// van eigenaarschap, in plaats van een handmatig gekozen aantal niveaus.
// Vroeg gedaan (vóór de beheeracties hieronder), zodat zowel de "bekijk"-
// beheeractie als de volledige scan verderop hetzelfde, al-berekende
// resultaat kunnen hergebruiken - dit hoeft dus maar één keer per
// scanmoment te gebeuren.
//
// Werkwijze: vanaf de website-root telkens één map omhoog, zolang die map
// nog dezelfde eigenaar heeft als de website zelf. Zodra de eigenaar
// verandert (bijv. de gedeelde serverhoofdmap, die meestal van root is in
// plaats van het hostingaccount), is de grens van het hostingaccount
// bereikt en wordt daar gestopt - dat is dan precies de accountroot, en
// werkt zo bij elke hostingpartij, zonder specifieke mapnamen te hoeven
// kennen. Een expliciet maximum (6 niveaus) is puur een noodrem tegen
// zeer uitzonderlijke mapstructuren, niet iets dat in de praktijk bereikt
// zou moeten worden.
// ----------------------------------------------------------------------
// Niet strikt op de letterlijke tekst 'auto' controleren, maar op "niet
// leeg": zo blijven ook nog niet-opnieuw-opgeslagen sites, die van vóór
// deze automatische aanpak nog een ouder soort waarde hebben (zoals '..'
// of '../../..' uit eerdere versies van dit scanpad-systeem), gewoon
// werken zonder dat er ergens opnieuw op "Opslaan" gedrukt hoeft te worden.
$extraScanIngeschakeld = ('__EXTRA_SCAN_PAD__' !== '');
$extraScanRootAbsoluut = null;
$extraScanNiveauGebruikt = 0;

if ($extraScanIngeschakeld) {
    $eigenaarWebsite = @fileowner($startMap);
    $huidigPad = $startMap;

    for ($niveau = 1; $niveau <= 6; $niveau++) {
        $volgendPad = @realpath($huidigPad . '/..');
        if ($volgendPad === false || $volgendPad === $huidigPad || !is_readable($volgendPad)) {
            break; // bestandssysteem-root bereikt, of niet (verder) leesbaar
        }

        $eigenaarVolgend = @fileowner($volgendPad);
        if ($eigenaarWebsite !== false && $eigenaarVolgend !== false && $eigenaarVolgend !== $eigenaarWebsite) {
            break; // andere eigenaar: dit hoort niet meer bij dit hostingaccount
        }

        $huidigPad = $volgendPad;
        $extraScanRootAbsoluut = $volgendPad;
        $extraScanNiveauGebruikt = $niveau;
    }
}

// ----------------------------------------------------------------------
// Beheeracties afhandelen - apart van de normale volledige scan. Wordt
// aangeroepen vanaf de monitor (beveiliging.php) om direct actie te
// ondernemen op een specifiek gevonden bestand, of om de huidige
// quarantaine/blokkeer/prullenbak-status op te vragen.
// ----------------------------------------------------------------------
$beheerActies = ['status', 'bekijk', 'quarantaine', 'blokkeer', 'verwijder', 'herstel', 'definitief', 'prullenbak_legen', 'rechten_herstellen', 'herstel_kernbestand'];

if (isset($_POST['actie']) && in_array($_POST['actie'], $beheerActies, true)) {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_POST['geheime_code']) || !hash_equals($geheimeCode, (string) $_POST['geheime_code'])) {
        http_response_code(403);
        echo json_encode(['succes' => false, 'foutmelding' => 'Ongeldige geheime code.']);
        exit;
    }

    $actie = $_POST['actie'];
    $manifest = beheerLaadJson($beheerManifest);

    // ---- STATUS: huidige quarantaine/blokkeer/prullenbak-lijst ----
    if ($actie === 'status') {
        echo json_encode(['succes' => true, 'manifest' => array_values($manifest), 'prullenbak_dagen' => $prullenbakDagen]);
        exit;
    }

    // ---- BEKIJK: bestand/map read-only tonen ----
    if ($actie === 'bekijk') {
        $pad = veiligPadRuim($_POST['pad'] ?? '', $startMap, $extraScanRootAbsoluut);
        if ($pad === false) {
            echo json_encode(['succes' => false, 'foutmelding' => 'Dit bestand/deze map bestaat niet meer op deze locatie (of het pad klopt niet) - waarschijnlijk al verwijderd, hernoemd of verplaatst sinds de laatste scan. Herscan de site (knop bovenaan) om de lijst bij te werken.']);
            exit;
        }
        if (is_dir($pad)) {
            $items = @scandir($pad) ?: [];
            $lijst = [];
            foreach ($items as $i) {
                if ($i === '.' || $i === '..') {
                    continue;
                }
                $p = $pad . '/' . $i;
                $lijst[] = (is_dir($p) ? '[map]  ' : '[file] ') . $i
                    . (is_file($p) ? '  (' . number_format(filesize($p)) . ' bytes, ' . date('Y-m-d H:i', filemtime($p)) . ')' : '');
            }
            // JSON_INVALID_UTF8_SUBSTITUTE: bestandsnamen kunnen in theorie
            // ongeldige UTF-8-bytes bevatten - zonder deze vlag geeft
            // json_encode() dan stilzwijgend false terug, en dus een lege
            // (maar wel HTTP 200-)respons. Zie de toelichting hieronder bij
            // de bestandsinhoud-variant voor het concrete, aangetroffen geval.
            echo json_encode(['succes' => true, 'type' => 'map', 'inhoud' => $lijst ? implode("\n", $lijst) : '(lege map)'], JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $max = 65536;
        $inhoud = @file_get_contents($pad, false, null, 0, $max);
        $grootte = filesize($pad);
        // JSON_INVALID_UTF8_SUBSTITUTE (i.p.v. de standaard, strikte UTF-8-
        // validatie): zonder deze vlag geeft json_encode() gewoon `false`
        // terug zodra de bestandsinhoud niet-geldige UTF-8-bytes bevat - en
        // dus een LEGE respons met HTTP 200, in plaats van een foutmelding
        // of de daadwerkelijke inhoud. Trof precies dit bij het bekijken van
        // een GIF/PHP-polyglot-bestand (een geldige, binaire GIF89a-header
        // gevolgd door PHP-code) - de binaire header-bytes zijn geen geldige
        // UTF-8-tekst. Met deze vlag worden ongeldige bytes vervangen door
        // het standaard vervangingsteken (U+FFFD) i.p.v. de hele respons te
        // laten mislukken; de PHP-payload zelf (gewoon leesbare tekst) blijft
        // gewoon zichtbaar in de "Bekijk"-weergave.
        echo json_encode([
            'succes' => true,
            'type' => 'bestand',
            'inhoud' => ($inhoud === false) ? '(kon bestand niet lezen)' : $inhoud,
            'afgekapt' => $grootte > $max,
            'grootte' => $grootte,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    // ---- KERNBESTAND HERSTELLEN NAAR OFFICIËLE INHOUD ----
    // Aangeroepen vanaf bekijk_kern_afwijking.php (via kern_bestand_actie.php
    // op de monitor) nadat een beheerder handmatig heeft bekeken wat het
    // verschil met het officiële Joomla-pakket precies inhoudt, en heeft
    // gekozen voor "automatisch vervangen". De monitor stuurt de volledige,
    // officiële bestandsinhoud (base64-gecodeerd, om het POST-veld veilig
    // door te geven) mee - dit script downloadt zelf niets, om te
    // voorkomen dat een gecompromitteerde site zelf bepaalt wat "officieel"
    // is.
    if ($actie === 'herstel_kernbestand') {
        $pad = veiligPad($_POST['pad'] ?? '', $startMap);
        if ($pad === false) {
            echo json_encode(['succes' => false, 'foutmelding' => 'Dit bestand bestaat niet meer op deze locatie (of het pad klopt niet). Herscan de site en probeer opnieuw.']);
            exit;
        }
        if (!is_file($pad)) {
            echo json_encode(['succes' => false, 'foutmelding' => 'Dit pad is geen bestand.']);
            exit;
        }

        $inhoudBase64 = $_POST['inhoud_base64'] ?? '';
        $nieuweInhoud = base64_decode($inhoudBase64, true);
        if ($inhoudBase64 === '' || $nieuweInhoud === false || $nieuweInhoud === '') {
            echo json_encode(['succes' => false, 'foutmelding' => 'Geen (geldige) bestandsinhoud meegestuurd - er is niets aangepast.']);
            exit;
        }

        $relatief = str_replace(realpath($startMap), '', $pad);

        // Eerst een herstelbare backup van de HUIDIGE inhoud wegzetten in de
        // bestaande quarantainemap (zelfde "herstel"-mechanisme als bij
        // andere vondsten) - pas dáárna het bestand overschrijven. Bij een
        // mislukte backup wordt er niets overschreven; liever een mislukte
        // actie dan een onherstelbare wijziging.
        $id = beheerNieuwId();
        $backupDoel = $quarantaineMap . '/' . $id . '__' . basename($pad);
        if (!@copy($pad, $backupDoel)) {
            echo json_encode(['succes' => false, 'foutmelding' => "Kon geen backup maken van $relatief vóór het vervangen (bestandsrechten?) - er is niets aangepast."]);
            exit;
        }
        maakOnbegaanbaar($backupDoel);
        $manifest[] = [
            'id' => $id,
            'actie' => 'kernbestand_backup',
            'type' => 'bestand',
            'origineel' => $pad, 'origineel_rel' => $relatief, 'opslag' => $backupDoel,
            'tijd' => date('Y-m-d H:i:s'),
        ];
        beheerBewaarJson($beheerManifest, $manifest);

        $geschreven = @file_put_contents($pad, $nieuweInhoud);
        if ($geschreven === false) {
            echo json_encode(['succes' => false, 'foutmelding' => "Backup van $relatief is gelukt, maar terugschrijven van de officiële inhoud is mislukt (bestandsrechten?). De originele inhoud staat nog gewoon op zijn plek."]);
            exit;
        }

        echo json_encode([
            'succes' => true,
            'melding' => "$relatief is vervangen door de officiële inhoud. De vorige versie is herstelbaar bewaard (zie het beheeroverzicht van deze site).",
            'naam' => $relatief,
        ]);
        exit;
    }

    // ---- QUARANTAINE / BLOKKEER / VERWIJDER (naar prullenbak) ----
    if (in_array($actie, ['quarantaine', 'blokkeer', 'verwijder'], true)) {
        $pad = veiligPad($_POST['pad'] ?? '', $startMap);
        $beheerReal = realpath($beheerMap);

        if ($pad === false) {
            // Onderscheid maken: valt dit pad legitiem binnen het (ruimere)
            // extra scanpad, maar simpelweg buiten de strikte website-
            // root? Dan een duidelijke, andere melding dan bij een echt
            // ongeldig/onbestaand pad - wijzigende acties blijven hier
            // bewust beperkt tot de website-root zelf.
            $valtBinnenExtraScanpad = veiligPadRuim($_POST['pad'] ?? '', $startMap, $extraScanRootAbsoluut) !== false;
            $foutmelding = $valtBinnenExtraScanpad
                ? 'Dit kan om veiligheidsredenen niet, gebruik daarvoor handmatig FTP.'
                : 'Dit bestand/deze map bestaat niet meer op deze locatie (of het pad klopt niet) - waarschijnlijk al verwijderd, hernoemd of verplaatst sinds de laatste scan. Herscan de site (knop bovenaan) om de lijst bij te werken.';
            echo json_encode(['succes' => false, 'foutmelding' => $foutmelding]);
            exit;
        }
        if ($beheerReal && strpos($pad, $beheerReal) === 0) {
            echo json_encode(['succes' => false, 'foutmelding' => 'Dit item staat al in de beheermap.']);
            exit;
        }

        $relatief = str_replace(realpath($startMap), '', $pad);
        $id = beheerNieuwId();
        $type = is_dir($pad) ? 'map' : 'bestand';

        if ($actie === 'blokkeer') {
            $doel = $pad . '.BLOCKED_' . $id;
            if (@rename($pad, $doel)) {
                maakOnbegaanbaar($doel);
                $manifest[] = [
                    'id' => $id, 'actie' => 'geblokkeerd', 'type' => $type,
                    'origineel' => $pad, 'origineel_rel' => $relatief, 'opslag' => $doel,
                    'tijd' => date('Y-m-d H:i:s'),
                ];
                beheerBewaarJson($beheerManifest, $manifest);
                echo json_encode(['succes' => true, 'melding' => "Geblokkeerd (herstelbaar): $relatief", 'naam' => $relatief]);
            } else {
                echo json_encode(['succes' => false, 'foutmelding' => "Blokkeren mislukt voor $relatief (bestandsrechten?)."]);
            }
            exit;
        }

        // quarantaine of verwijder (-> prullenbak)
        $doelMap = ($actie === 'quarantaine') ? $quarantaineMap : $prullenbakMap;
        $doel = $doelMap . '/' . $id . '__' . basename($pad);
        if (@rename($pad, $doel)) {
            maakOnbegaanbaar($doel);
            $entry = [
                'id' => $id,
                'actie' => ($actie === 'quarantaine') ? 'quarantaine' : 'prullenbak',
                'type' => $type,
                'origineel' => $pad, 'origineel_rel' => $relatief, 'opslag' => $doel,
                'tijd' => date('Y-m-d H:i:s'),
            ];
            if ($actie === 'verwijder') {
                $entry['verloopt'] = time() + ($prullenbakDagen * 86400);
            }
            $manifest[] = $entry;
            beheerBewaarJson($beheerManifest, $manifest);

            $label = ($actie === 'quarantaine')
                ? "In quarantaine geplaatst (herstelbaar): $relatief"
                : "Naar prullenbak verplaatst ($prullenbakDagen dagen herstelbaar): $relatief";
            echo json_encode(['succes' => true, 'melding' => $label, 'naam' => $relatief]);
        } else {
            echo json_encode(['succes' => false, 'foutmelding' => "Verplaatsen mislukt voor $relatief (bestandsrechten?)."]);
        }
        exit;
    }

    // ---- RECHTEN HERSTELLEN (naar het gebruikelijke 644 voor bestanden,
    // 755 voor mappen) ----
    if ($actie === 'rechten_herstellen') {
        $pad = veiligPad($_POST['pad'] ?? '', $startMap);

        if ($pad === false) {
            $valtBinnenExtraScanpad = veiligPadRuim($_POST['pad'] ?? '', $startMap, $extraScanRootAbsoluut) !== false;
            $foutmelding = $valtBinnenExtraScanpad
                ? 'Dit kan om veiligheidsredenen niet, gebruik daarvoor handmatig FTP.'
                : 'Dit bestand/deze map bestaat niet meer op deze locatie (of het pad klopt niet) - waarschijnlijk al verwijderd, hernoemd of verplaatst sinds de laatste scan. Herscan de site (knop bovenaan) om de lijst bij te werken.';
            echo json_encode(['succes' => false, 'foutmelding' => $foutmelding]);
            exit;
        }

        $isMap = is_dir($pad);
        $doelRechten = $isMap ? 0755 : 0644;
        $doelOctaalTekst = $isMap ? '755' : '644';

        $relatief = str_replace(realpath($startMap), '', $pad);
        $huidigeRechten = @fileperms($pad);

        if ($huidigeRechten === false) {
            echo json_encode(['succes' => false, 'foutmelding' => "Kon de huidige bestandsrechten van $relatief niet bepalen."]);
            exit;
        }

        $huidigOctaal = substr(sprintf('%o', $huidigeRechten), -3);

        if ($huidigOctaal === $doelOctaalTekst) {
            echo json_encode(['succes' => true, 'melding' => "$relatief stond al op $doelOctaalTekst - niets te doen.", 'naam' => $relatief]);
            exit;
        }

        if (@chmod($pad, $doelRechten)) {
            echo json_encode(['succes' => true, 'melding' => "Rechten van $relatief hersteld: $huidigOctaal → $doelOctaalTekst.", 'naam' => $relatief]);
        } else {
            echo json_encode(['succes' => false, 'foutmelding' => "Aanpassen van de rechten van $relatief (huidig: $huidigOctaal) is mislukt - vermoedelijk is dit " . ($isMap ? 'deze map' : 'dit bestand') . " niet eigendom van dit PHP-proces."]);
        }
        exit;
    }

    // ---- HERSTEL (op manifest-id) ----
    if ($actie === 'herstel') {
        $zoekId = $_POST['id'] ?? '';
        foreach ($manifest as $i => $entry) {
            if ($entry['id'] !== $zoekId) {
                continue;
            }
            if (empty($entry['opslag']) || !file_exists($entry['opslag'])) {
                unset($manifest[$i]);
                beheerBewaarJson($beheerManifest, array_values($manifest));
                echo json_encode(['succes' => false, 'foutmelding' => 'Opgeslagen item niet gevonden, manifest opgeschoond.']);
                exit;
            }

            if (($entry['actie'] ?? '') === 'kernbestand_backup') {
                maakWeerBegaanbaar($entry['opslag']);
                $backupInhoud = @file_get_contents($entry['opslag']);
                if ($backupInhoud === false) {
                    maakOnbegaanbaar($entry['opslag']);
                    echo json_encode(['succes' => false, 'foutmelding' => 'Kon de backup niet lezen.']);
                    exit;
                }
                if (@file_put_contents($entry['origineel'], $backupInhoud) === false) {
                    maakOnbegaanbaar($entry['opslag']);
                    echo json_encode(['succes' => false, 'foutmelding' => 'Terugschrijven van de backup is mislukt (bestandsrechten?).']);
                    exit;
                }
                @unlink($entry['opslag']);
                unset($manifest[$i]);
                beheerBewaarJson($beheerManifest, array_values($manifest));
                echo json_encode(['succes' => true, 'melding' => 'Hersteld: ' . $entry['origineel_rel'] . ' (de eerder vervangen inhoud staat er weer op.)']);
                exit;
            }

            if (file_exists($entry['origineel'])) {
                echo json_encode(['succes' => false, 'foutmelding' => 'Er staat al iets op de oorspronkelijke plek: ' . $entry['origineel_rel']]);
                exit;
            }
            $ouderMap = dirname($entry['origineel']);
            if (!is_dir($ouderMap)) {
                @mkdir($ouderMap, 0755, true);
            }
            maakWeerBegaanbaar($entry['opslag']);
            if (@rename($entry['opslag'], $entry['origineel'])) {
                maakWeerBegaanbaar($entry['origineel']);
                unset($manifest[$i]);
                beheerBewaarJson($beheerManifest, array_values($manifest));
                echo json_encode(['succes' => true, 'melding' => 'Hersteld: ' . $entry['origineel_rel'] . ' (staat nu weer actief op de site!)']);
            } else {
                maakOnbegaanbaar($entry['opslag']);
                echo json_encode(['succes' => false, 'foutmelding' => 'Herstellen mislukt (bestandsrechten?).']);
            }
            exit;
        }
        echo json_encode(['succes' => false, 'foutmelding' => 'Onbekend item.']);
        exit;
    }

    // ---- DEFINITIEF VERWIJDEREN (op manifest-id) ----
    if ($actie === 'definitief') {
        $zoekId = $_POST['id'] ?? '';
        foreach ($manifest as $i => $entry) {
            if ($entry['id'] !== $zoekId) {
                continue;
            }
            if (!empty($entry['opslag']) && file_exists($entry['opslag'])) {
                beheerVerwijderRecursief($entry['opslag']);
            }
            unset($manifest[$i]);
            beheerBewaarJson($beheerManifest, array_values($manifest));
            echo json_encode(['succes' => true, 'melding' => 'Definitief verwijderd: ' . $entry['origineel_rel']]);
            exit;
        }
        echo json_encode(['succes' => false, 'foutmelding' => 'Onbekend item.']);
        exit;
    }

    // ---- PRULLENBAK LEGEN ----
    if ($actie === 'prullenbak_legen') {
        $aantal = 0;
        foreach ($manifest as $i => $entry) {
            if (($entry['actie'] ?? '') !== 'prullenbak') {
                continue;
            }
            if (!empty($entry['opslag']) && file_exists($entry['opslag'])) {
                beheerVerwijderRecursief($entry['opslag']);
            }
            unset($manifest[$i]);
            $aantal++;
        }
        beheerBewaarJson($beheerManifest, array_values($manifest));
        echo json_encode(['succes' => true, 'melding' => "Prullenbak geleegd ($aantal item(en) definitief verwijderd)."]);
        exit;
    }

}

// Mappen op ROOT LEVEL die vertrouwd zijn
$vertrouwdeRootMappen = [
    'administrator',
    'api',
    'bin',
    'cache',
    'cgi-bin',
    'cli',
    'components',
    'files',
    'images',
    'includes',
    'language',
    'layouts',
    'libraries',
    'media',
    'modules',
    'plugins',
    'templates',
    'tmp',
    '_scan_beheer',
    'private_html', // standaardmap bij Vimexx (en vergelijkbare hostingpartijen), geen Joomla-onderdeel maar ook geen probleem
    'log', // bevat o.a. joomla-scheduler.php (Joomla's ingebouwde takenplanner) - legitiem, geen Joomla-kernonderdeel maar ook geen probleem
    'phocadownload', // standaard uploadmap van de Phoca Download-extensie (com_phocadownload), niet in de website-root zelf maar er wel naast
    'phocadownloadpap', // vergelijkbare, door Phoca Download aangemaakte map
    'phocacartattachment', // standaardmap van de Phoca Cart-extensie (com_phocacart, webshop), voor bijlagen
    'phocacartdownload', // standaardmap van Phoca Cart voor downloadbare productbestanden
    'phocacartdownloadpublic', // vergelijkbare, door Phoca Cart aangemaakte map voor publiek toegankelijke downloads
    'phocamapskml', // standaardmap van de Phoca Maps-extensie (com_phocamaps) voor KML-bestanden
    '.well-known', // standaard webmap voor domeinvalidatie (bijv. Let's Encrypt/ACME), hoort bij vrijwel elke moderne website
];

// Vertrouwde bestanden op root level
$vertrouwdeRootBestanden = [
    'index.php',
    'index.html',
    'configuration.php',
    'htaccess.txt',
    '.htaccess',
    '.htaccess.admintools',
    'robots.txt',
    'robots.txt.dist',
    'web.config',
    'web.config.txt',
    'LICENSE.txt',
    'README.txt',
    'README.md',
    'logo.png',
    'favicon.ico',
    '.user.ini',
];

// Scan-scripts zelf
$ignoreerBestanden = [
    'scan_verdacht.php',
    'scan_verdacht_v2.php',
    'scan_verdacht_v3.php',
    'scan_verdacht_v4.php',
    'scan_verdacht_v5.php',
    'scan_verdacht_v6.php',
    'scan_verdacht_v7.php',
    'scan_verdacht_v10.php',
    'scan_verdacht_v11.php',
    'scan-en-check-website.php',
    'scan_template.php',
    basename(__FILE__), // dekt ook een eventuele eigen, aangepaste bestandsnaam
];

$backdoorVondsten = [];
$rootLevelUnknown = [];
$htaccessVondsten = [];
$mogelijkLegitiem = [];

// ============================================================================
// BACKDOOR-DETECTIE
// ============================================================================

/**
 * Schat het risico (0-100) van een vondst in op basis van de reden-tekst -
 * wordt rechtstreeks in de scanuitvoer/payload meegegeven ("[risico=N]"),
 * zodat de monitor dit niet zelf hoeft te herberekenen en er een badge bij
 * kan tonen. Zie ook de identieke kopie in verdacht_functies.php (die dient
 * als terugval voor scanresultaten van vóór deze functionaliteit).
 */
function bepaalRisico(string $reden): int
{
    $mapping = [
        'ZEKER BACKDOOR'           => 100,
        'PURE BACKDOOR'            => 100,
        'ONE-LINER BACKDOOR'       => 95,
        'payload extraction'       => 85,
        'HIDDEN ENTRY POINT'       => 85,
        'UPLOAD BACKDOOR'          => 80,
        'LOADER PATROON'           => 80,
        'OBFUSCATED BACKDOOR'      => 90,
        'BACKDOOR PATROON'         => 90,
        'OBFUSCATED FUNCTION CALL' => 70,
        'KRITIEK'                  => 75,
        'Verdubbelde mapnaam'      => 65,
        'VERDACHT'                 => 55,
    ];

    foreach ($mapping as $sleutel => $score) {
        if (stripos($reden, $sleutel) !== false) {
            return $score;
        }
    }

    return 50;
}

/**
 * Joomla's echte kernbestanden (index.php, administrator/index.php,
 * api/index.php, includes/app.php) voeren NOOIT code uit vóórdat de
 * _JEXEC-bootstrap is gedefinieerd. Staat daar toch iets, dan is dat een
 * zeer sterk signaal van een "prepended payload" - het exacte patroon dat
 * bij echte Joomla-hacks wordt gebruikt (bv. code die zichzelf vóór de
 * originele bestandsinhoud plakt). Dit is, in tegenstelling tot de meeste
 * andere patronen hierboven, geen kans-inschatting maar een vrijwel
 * valse-positief-vrije test: een schoon kernbestand heeft hier simpelweg
 * nooit iets staan.
 */
/**
 * Leest configuration.php op precies dezelfde manier als Joomla zelf dat
 * doet (via de klasse JConfig), en zet op basis daarvan een eigen,
 * alleen-lezen databaseverbinding op. Wordt gebruikt voor de database-
 * gebaseerde controles hieronder (verdachte Super Users, ontmaskerings-
 * teksten in templatestijlen) - geen aparte inloggegevens nodig, en er
 * wordt nergens naar de database geschreven.
 */
function verbindMetJoomlaDatabase(string $startMap): ?array
{
    $configPad = $startMap . '/configuration.php';
    if (!is_readable($configPad)) {
        return null;
    }

    try {
        if (!class_exists('JConfig')) {
            require $configPad;
        }
        if (!class_exists('JConfig')) {
            return null;
        }
        $cfg = new JConfig();

        $host = $cfg->host;
        $poort = null;
        if (strpos($host, ':') !== false && substr_count($host, ':') === 1) {
            [$host, $poort] = explode(':', $host, 2);
        }

        $dsn = "mysql:host={$host}" . ($poort ? ";port={$poort}" : '') . ";dbname={$cfg->db};charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg->user, $cfg->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);

        return ['pdo' => $pdo, 'prefix' => $cfg->dbprefix];
    } catch (\Throwable $e) {
        return null; // db-gebaseerde checks worden dan gewoon overgeslagen, de rest van de scan gaat door
    }
}

/**
 * Zoekt naar Super Users met een gebruikersnaam of e-maildomein dat
 * overeenkomt met bekende aanvallerspatronen - een veelgebruikte manier
 * voor een aanvaller om, na een eerste inbraak, zichzelf een permanente
 * eigen "achterdeur"-account te geven los van de gehackte bestanden.
 */
function scanRogueSuperUsers(?array $dbInfo, array &$vondsten): void
{
    if ($dbInfo === null) {
        return;
    }

    try {
        $pdo = $dbInfo['pdo'];
        $prefix = $dbInfo['prefix'];

        $stmt = $pdo->query(
            "SELECT u.id, u.name, u.username, u.email, u.registerDate
             FROM {$prefix}users u
             INNER JOIN {$prefix}user_usergroup_map m ON m.user_id = u.id
             WHERE m.group_id IN (
                 SELECT id FROM {$prefix}usergroups WHERE title IN ('Super Users', 'Super User')
             )"
        );

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
            $reden = null;
            if (stripos($rij['email'], 'secure.local') !== false) {
                $reden = 'e-maildomein "secure.local" (bekend aanvaller-kenmerk)';
            } elseif (preg_match('/webmanager\d+|codex|sppb/i', $rij['username'])) {
                $reden = 'gebruikersnaam komt overeen met een bekend aanvallerspatroon';
            }

            if ($reden !== null) {
                $vondsten[] = [
                    'naam' => 'Super User: ' . $rij['username'] . ' (' . $rij['email'] . ')',
                    'reden' => "VERDACHTE SUPER USER - {$reden}. Geregistreerd op {$rij['registerDate']}. Controleer via Joomla Beheerder → Gebruikers → Beheren, en verwijder het account als het niet van jou is.",
                    'risico' => 95,
                    'gewijzigd' => $rij['registerDate'],
                ];
            }
        }
    } catch (\Throwable $e) {
        // #__users/#__user_usergroup_map ontbreekt of query mislukte - niet fataal, gewoon overslaan
    }
}

/**
 * Zoekt naar ontmaskeringsteksten ("Hacked by", "Owned by", enz.) in de
 * parameters van templatestijlen - een teken dat een aanvaller de site
 * (deels) heeft gedefaced, ook als de rest van de site verder nog normaal
 * werkt.
 */
function scanTemplateDefacement(?array $dbInfo, array &$vondsten): void
{
    if ($dbInfo === null) {
        return;
    }

    try {
        $pdo = $dbInfo['pdo'];
        $prefix = $dbInfo['prefix'];

        $stmt = $pdo->query("SELECT id, template, title, params FROM {$prefix}template_styles");
        $patronen = [
            '/Hacked\s+by/i', '/Owned\s+by/i', '/Pwned\s+by/i', '/Defaced\s+by/i',
            '/H4cked/i', '/0wned/i', '/w4s\s+here/i', '/was\s+here/i',
            '/greetz\s+to/i', '/shell\s+by/i', '/r00ted/i',
        ];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
            foreach ($patronen as $patroon) {
                if (preg_match($patroon, (string) $rij['params'], $m)) {
                    $vondsten[] = [
                        'naam' => 'Templatestijl: ' . $rij['title'] . ' (' . $rij['template'] . ')',
                        'reden' => "MOGELIJKE DEFACEMENT - tekst \"{$m[0]}\" gevonden in de parameters van deze templatestijl. Controleer en herstel handmatig via Joomla Beheerder → Websites → Templates → Stijlen.",
                        'risico' => 90,
                        'gewijzigd' => date('Y-m-d H:i'),
                    ];
                    break;
                }
            }
        }
    } catch (\Throwable $e) {
        // #__template_styles ontbreekt of query mislukte - niet fataal
    }
}

function checkKernIntegriteit(string $inhoud): ?string
{
    $openTagPos = stripos($inhoud, '<?php');
    if ($openTagPos === false) {
        return null;
    }

    $bootstrapPatroon = '/(?:\\\\?\bdefine\s*\(\s*[\'"]_JEXEC[\'"]\s*,\s*1\s*\)|\bconst\s+_JEXEC\s*=\s*1)\s*;/i';
    if (!preg_match($bootstrapPatroon, $inhoud, $m, PREG_OFFSET_CAPTURE)) {
        return null; // geen bootstrap-regel gevonden - waarschijnlijk geen (recent) kernbestand, niets te controleren
    }

    $prefix = substr($inhoud, $openTagPos + 5, $m[0][1] - ($openTagPos + 5));

    $verdachtePatronen = [
        'stream-wrapper-verwijzing (zip://, phar://, enz.)' => '/(zip|phar|compress\.zlib|compress\.bzip2|data):\/\//i',
        'numerieke byte-array (chr()-decodering)' => '/array\s*\(\s*(\d{2,3}\s*,\s*){6,}\d{2,3}\s*\)/i',
        'eval()' => '/\beval\s*\(/i',
        'base64_decode()' => '/base64_decode\s*\(/i',
        'assert()' => '/\bassert\s*\(/i',
        'shell-/procesuitvoeringsfunctie' => '/\b(system|exec|shell_exec|passthru|proc_open)\s*\(/i',
    ];

    foreach ($verdachtePatronen as $label => $patroon) {
        if (preg_match($patroon, $prefix)) {
            $voorbeeld = trim(preg_replace('/\s+/', ' ', $prefix));
            $voorbeeld = strlen($voorbeeld) > 160 ? substr($voorbeeld, 0, 160) . '…' : $voorbeeld;
            return "KERNBESTAND GECOMPROMITTEERD - code wordt uitgevoerd vóór Joomla's _JEXEC-bootstrap ({$label}). Dit betekent dat de site NU actief is besmet, bij elke paginaweergave. Fragment: {$voorbeeld}";
        }
    }

    return null;
}

function scanPhpVoorBackdoors($bestandpad, &$vondsten, &$mogelijkLegitiem, $ignoreerBestanden = [])
{
    $bestandsnaam = basename($bestandpad);
    if (in_array($bestandsnaam, $ignoreerBestanden)) {
        return;
    }

    if (!is_readable($bestandpad)) {
        return;
    }

    $inhoud = file_get_contents($bestandpad);
    if ($inhoud === false || strlen($inhoud) === 0) {
        return;
    }

    // Elk scanscript uit dit sjabloon bevat (in zijn eigen broncode, als
    // voorbeeldtekst binnen de backdoor-detectiepatronen hieronder) letterlijk
    // rijtjes als "eval(eval(base64_decode" - dat is precies waar deze
    // patronen zelf op zoeken. Zonder deze uitzondering zou elk scanscript
    // dat (nog) niet exact onder zijn eigen huidige bestandsnaam draait -
    // bijv. het scanscript van een tweede monitor-installatie voor dezelfde
    // site, of een net herupload bestand met een nieuw volgnummer - zichzelf
    // dus ten onrechte als "ZEKER BACKDOOR" aanmerken.
    if (isEigenScanScriptInhoud($inhoud)) {
        return;
    }

    $reden = null;
    $verdacht = false;

    // PATROON 1: Dubbele <?php tags
    if (preg_match('/<\?php\s*<\?php\s*(eval|base64|gzinflate)/i', $inhoud)) {
        $reden = 'Dubbele <?php tags gevolgd door eval/base64 - BACKDOOR PATROON';
        $verdacht = true;
    }

    // PATROON 2: eval(eval(base64_decode(...)))
    if (!$verdacht && preg_match('/eval\s*\(\s*eval\s*\(\s*base64_decode/i', $inhoud)) {
        $reden = 'eval(eval(base64_decode)) - dubbel gelaagde obfuscatie, ZEKER BACKDOOR';
        $verdacht = true;
    }

    // PATROON 3: <?php direct gevolgd door eval(base64_decode
    if (!$verdacht && preg_match('/<\?php\s{0,10}eval\s*\(\s*base64_decode/i', $inhoud)) {
        $reden = '<?php direct naar eval(base64_decode) - BACKDOOR PATROON';
        $verdacht = true;
    }

    // PATROON 4: preg_match op data:image/png + eval
    if (!$verdacht && preg_match('/preg_match\s*\([^)]*data:image.*\)/is', $inhoud) &&
        preg_match('/eval\s*\(/i', $inhoud)) {
        if (preg_match('/preg_match.*data:image.*eval|eval.*preg_match.*data:image/is', $inhoud)) {
            $reden = 'preg_match(data:image/png) + eval - payload extraction, BACKDOOR PATROON';
            $verdacht = true;
        }
    }

    // PATROON 5: Nummergegeven mappen met index.php met eval
    if (!$verdacht && basename(dirname($bestandpad)) && preg_match('/^\d+$/', basename(dirname($bestandpad)))) {
        if (preg_match('/eval\s*\(/i', $inhoud)) {
            $reden = 'index.php in nummergegeven map met eval() - HIDDEN ENTRY POINT';
            $verdacht = true;
        }
    }

    // PATROON 6: Zeer korte PHP-bestand dat alleen eval(base64_decode bevat
    if (!$verdacht && strlen($inhoud) < 300 && preg_match('/^<\?php\s*eval\s*\(\s*base64_decode/i', $inhoud)) {
        if (preg_match_all('/\n/', $inhoud) < 5) {
            $reden = 'Extreem kort bestand met direct eval(base64_decode) - PURE BACKDOOR';
            $verdacht = true;
        }
    }

    // PATROON 7: Combinatie: strrev + base64_decode + eval
    if (!$verdacht && preg_match('/strrev\s*\([\'"]/', $inhoud) &&
        preg_match('/base64_decode/i', $inhoud) &&
        preg_match('/eval\s*\(/i', $inhoud)) {
        if (strlen($inhoud) < 1000) {
            $reden = 'strrev() + base64_decode + eval in korte routine - OBFUSCATED BACKDOOR';
            $verdacht = true;
        }
    }

    // PATROON 8: <?php direct naar $foo = base64_decode met eval
    if (!$verdacht && preg_match('/<\?php\s{0,20}\$\w+\s*=\s*base64_decode.*eval/is', $inhoud)) {
        $reden = '<?php $var=base64_decode...eval - ONE-LINER BACKDOOR';
        $verdacht = true;
    }

    // ------------------------------------------------------------------
    // NIEUWE PATRONEN (v5, patroon 11 aangescherpt in v6)
    // ------------------------------------------------------------------

    // PATROON 9: Dynamische array-functieaanroep ($p[7](), $arr[$i]()) i.c.m. superglobal
    // Vangt het "image.18.php"-cookie-loader patroon: functienamen zitten
    // niet als tekst in het bestand, maar worden runtime opgebouwd/aangeroepen.
    if (!$verdacht && preg_match('/\$\w+\s*\[\s*[\'"]?\w*[\'"]?\s*\]\s*\(/', $inhoud)) {
        // Let op: ook detecteren als de superglobal eerst aan een losse
        // variabele wordt toegekend (bv. $c = $_COOKIE; ... $c[11] ...),
        // vandaar geen verplichte '[' direct na de superglobal-naam.
        if (preg_match('/\$_(COOKIE|REQUEST|POST|GET|SERVER)\b/i', $inhoud)) {
            // extra check: include/require/move_uploaded_file/fwrite/fopen in de buurt
            // verhoogt de kans dat dit een loader is i.p.v. legitiem array-gebruik
            if (preg_match('/\b(include|require|include_once|require_once|fwrite|fopen|move_uploaded_file)\b/i', $inhoud)) {
                $reden = 'Dynamische array/variabele functie-aanroep i.c.m. superglobal en include/fwrite/fopen - COOKIE/REQUEST LOADER PATROON';
                $verdacht = true;
            }
        }
    }

    // PATROON 10: move_uploaded_file() achter een custom request-trigger,
    // buiten de bekende Joomla media-manager context.
    // Vangt het "github.50.php"-upload-backdoor patroon.
    if (!$verdacht && preg_match('/move_uploaded_file\s*\(/i', $inhoud)) {
        if (preg_match('/isset\s*\(\s*\$_(REQUEST|GET|POST)\s*\[\s*[\'"]\w+[\'"]\s*\]\s*\)/i', $inhoud)) {
            // Joomla's eigen media manager/upload handlers zitten in
            // components/com_media of administrator/components/com_media;
            // buiten die paden is een losse move_uploaded_file() achter
            // een willekeurige request-trigger sterk verdacht.
            //
            // Uitzondering: onze EIGEN monitorsoftware heeft zelf ook een
            // (legitieme) logo-upload-functie die move_uploaded_file()
            // gebruikt. Als de monitor toevallig binnen het scanbereik van
            // een gemonitorde site staat (bijv. op hetzelfde
            // hostingaccount), zou de scan anders zichzelf als verdacht
            // aanmerken. Bewust NIET op mapnaam gecontroleerd (een
            // aanvaller zou zijn eigen backdoor net zo goed "monitor" of
            // "00-beheer" kunnen noemen) - wel op een functienaam die
            // uniek is voor onze eigen codebase en in een echte Joomla-
            // site nooit zou voorkomen.
            $isEigenMonitorBestand = strpos($inhoud, 'genereerFaviconsVanLogo') !== false
                || strpos($inhoud, 'bepaalScanBestandsnaam') !== false;

            // Joomla's eigen media manager/upload handlers zitten in
            // components/com_media of administrator/components/com_media,
            // maar UITSLUITEND in een klein aantal bekende, vaste submappen
            // (src/tmpl/views/etc. - afhankelijk van de Joomla-versie).
            //
            // BEWUST NIET "overal waar 'com_media' in het pad voorkomt"
            // uitsluiten: een aanvaller kan zijn eigen backdoor net zo goed
            // diep WEGSTOPPEN onder com_media zelf, in een eigen, willekeurig
            // genoemde submap (bv. "com_media/oglzj1c/ekcfvyd/1cfblku/") -
            // dat pad bevat immers ook "com_media", maar hoort er functioneel
            // niets mee te maken. Concreet aangetroffen (augustus 2026): een
            // upload-backdoor precies zo weggestopt, die zonder deze
            // aanscherping volledig gemist zou zijn als 'ie toevallig geen
            // ander patroon (bv. PATROON 11) had geraakt.
            $zitInEchteComMediaKernmap = (bool) preg_match(
                '#[/\\\\]com_media[/\\\\](src|tmpl|views|controllers|models|helpers|layouts|forms|language|table|field|fields|service|presets)[/\\\\]#i',
                $bestandpad
            );

            if (!$isEigenMonitorBestand && !$zitInEchteComMediaKernmap) {
                $reden = 'move_uploaded_file() achter custom request-parameter, buiten de bekende com_media-kernmappen - UPLOAD BACKDOOR PATROON';
                $verdacht = true;
            }
        }
    }

    // PATROON 11: Variabele functie via samengestelde string, bv. $f = 'ev'.'al'; $f(...)
    // of variabele-variabele aanroep $$x(...)
    // LET OP: $$var() komt legitiem voor in templating/compiler-libraries
    // (bv. Blade-compilers zoals fof40/View/Compiler/Blade.php), dus alleen
    // flaggen als er ook onvertrouwde input (superglobal) of eval/base64
    // in hetzelfde bestand voorkomt - anders te veel false positives.
    //
    // Twee-of-meer stringdelen (niet alleen exact twee): een aanvaller kan
    // een functienaam net zo makkelijk in drie of meer stukken knippen
    // (bv. "as"."se"."rt" i.p.v. "ass"."ert") - de eerdere, striktere regex
    // (precies twee delen, direct gevolgd door een puntkomma) miste dat.
    // Aangescherpt n.a.v. een concreet aangetroffen GIF/PHP-polyglot-
    // webshell ("sys"."tem"), die met de oude regex overigens al wél werd
    // gevangen (dat was exact twee delen) - deze verruiming dekt daarnaast
    // ook varianten met meer stukken.
    if (!$verdacht && preg_match('/\$\w+\s*=\s*(?:[\'"]\w*[\'"]\s*\.\s*){1,}[\'"]\w*[\'"]\s*;/', $inhoud)) {
        if (preg_match('/\$\$?\w+\s*\(/', $inhoud)) {
            if (preg_match('/\$_(COOKIE|REQUEST|POST|GET|SERVER)\b/i', $inhoud) || preg_match('/\b(eval|base64_decode)\s*\(/i', $inhoud)) {
                $reden = 'Samengestelde functienaam via string-concatenatie (twee of meer delen) + dynamische aanroep, i.c.m. superglobal/eval - OBFUSCATED FUNCTION CALL';
                $verdacht = true;
            }
        }
    }
    if (!$verdacht && preg_match('/\$\$\w+\s*\(/', $inhoud)) {
        if (preg_match('/\$_(COOKIE|REQUEST|POST|GET|SERVER)\b/i', $inhoud) || preg_match('/\b(eval|base64_decode)\s*\(/i', $inhoud)) {
            $reden = 'Variabele-variabele functie-aanroep ($$var()) i.c.m. superglobal/eval - OBFUSCATED FUNCTION CALL';
            $verdacht = true;
        }
    }

    // PATROON 12: create_function() - verouderd, maar nog steeds als eval-vervanger misbruikt.
    // LET OP: oudere legitieme libraries (bv. GeSHi, de syntax-highlighter
    // die Joomla's content-plugin 'geshi' gebruikt) gebruiken create_function()
    // simpelweg als callback-mechanisme uit een tijd vóór closures - dat is op
    // zichzelf geen backdoor. Alleen flaggen bij onvertrouwde input in
    // hetzelfde bestand, of bij een verdacht klein bestand (grote libraries
    // zoals geshi.php zijn typisch tientallen tot honderden KB's groot).
    if (!$verdacht && preg_match('/create_function\s*\(/i', $inhoud)) {
        $isKleinBestand = strlen($inhoud) < 5000;
        $heeftSuperglobal = preg_match('/\$_(COOKIE|REQUEST|POST|GET|SERVER)\b/i', $inhoud);
        if ($isKleinBestand || $heeftSuperglobal) {
            $reden = 'create_function() gebruikt i.c.m. superglobal-input of in klein bestand - vaak misbruikt als eval()-vervanger, VERDACHT';
            $verdacht = true;
        }
    }

    // PATROON 13: Verdubbelde mapnaam (bv. models/models, views/views, helpers/helpers)
    // Puur structureel patroon - kijkt niet naar de inhoud van het bestand,
    // want deze backdoors zijn vaak zwaar geobfusceerd en missen soms alle
    // bovenstaande tekstuele signalen. Een schone Joomla-core of -extensie
    // heeft NOOIT een submap met exact dezelfde naam als zijn oudermap.
    //
    // BELANGRIJKE NUANCE: dit patroon komt ook legitiem voor bij third-party
    // extensies/libraries die hun EIGEN naam als mapnaam herhalen (Composer-
    // stijl: vendor/phpmailer/phpmailer, vendor/simplepie/simplepie, maar ook
    // components/com_kunena/.../kunena/kunena, plugins/content/geshi/geshi).
    // De ECHTE aanvaller op indiemonumentdeventer.nl gebruikte juist generieke
    // Joomla MVC-structuurnamen (models, views, helpers, controllers, tables,
    // categories, search, icons, error, less, common) omdat die overal
    // voorkomen en niet opvallen - een extensienaam als "kunena" of "geshi"
    // deed hij niet na. Alleen bij die generieke namen is dit een KRITIEKE
    // melding; bij een eigen extensienaam is het slechts ter info.
    if (strpos(str_replace('\\', '/', $bestandpad), '/vendor/') === false) {
        $ouderMap = dirname($bestandpad);
        $grootouderMap = dirname($ouderMap);
        $ouderMapNaam = basename($ouderMap);

        if ($ouderMapNaam !== '' && $ouderMapNaam !== '.' && $ouderMapNaam === basename($grootouderMap)) {
            $genericeJoomlaMapnamen = [
                'models', 'model', 'views', 'view', 'helpers', 'helper',
                'controllers', 'controller', 'tables', 'table', 'layouts', 'layout',
                'elements', 'element', 'fields', 'field', 'less', 'common', 'error',
                'errors', 'icons', 'icon', 'categories', 'category', 'commentimga',
                'search', 'includes', 'include', 'src', 'lib', 'core', 'utils',
                'util', 'assets', 'asset', 'json', 'xml',
            ];

            if (in_array(strtolower($ouderMapNaam), $genericeJoomlaMapnamen, true)) {
                if (!$verdacht) {
                    $reden = 'Verdubbelde mapnaam (map "' . $ouderMapNaam . '" zit direct in een map die ook "' . $ouderMapNaam . '" heet) - '
                        . 'komt in een schone Joomla-installatie nooit voor, typisch patroon voor automatisch geplaatste backdoors (bv. models/models, views/views)';
                    $verdacht = true;
                }
            } else {
                // Waarschijnlijk een extensie/library die zijn eigen naam herhaalt
                // (Kunena, GeSHi, etc.) - alleen ter info, niet als kritiek melden,
                // tenzij er sowieso al een ander (tekstueel) signaal is afgegaan.
                if ($verdacht) {
                    $reden .= ' [+ verdubbelde mapnaam "' . $ouderMapNaam . '/' . $ouderMapNaam . '" - waarschijnlijk bijkomstig, maar wel opvallend]';
                } else {
                    $mogelijkLegitiem[] = [
                        'naam' => str_replace(__DIR__, '', $bestandpad),
                        'reden' => 'Verdubbelde mapnaam "' . $ouderMapNaam . '/' . $ouderMapNaam . '" - dit patroon komt legitiem voor bij extensies/libraries '
                            . 'die hun eigen naam herhalen (bv. Kunena, GeSHi, PHPMailer). Alleen verdacht als de datum afwijkt van je overige, bekend-schone '
                            . 'bestanden van dezelfde extensie, of als er los daarvan andere signalen zijn.',
                        'gewijzigd' => date('Y-m-d H:i', filemtime($bestandpad)),
                        'grootte' => strlen($inhoud),
                    ];
                }
            }
        }
    }

    // PATROON 14: Payload geladen via een stream-wrapper-truc (zip://, phar://,
    // compress.zlib://, compress.bzip2://, data://) i.c.m. require/include.
    // Een bekende ontwijkingstechniek om simpele signatuurscanners te omzeilen:
    // de payload zit "verstopt" in een ander bestandsformaat (bv. een zip- of
    // afbeeldingsbestand) en wordt via de stream-wrapper alsnog als PHP geladen.
    //
    // Vereist een ECHT openingshaakje direct na require/require_once/include/
    // include_once (dus een daadwerkelijke functieaanroep, geen los voorkomen
    // van het woord "require" in gewone tekst/commentaar), en de stream-
    // wrapper-tekst moet vlak bij dat haakje staan (binnen 80 tekens) - dus
    // ook echt het argument van die aanroep zijn, niet toevallig iets
    // verderop in de code (bijv. een losse strpos()-vergelijking).
    if (!$verdacht && preg_match('/(?:require|include)(?:_once)?\s*\(\s*.{0,80}?(zip|phar|compress\.zlib|compress\.bzip2|data):\/\//is', $inhoud)) {
        $reden = 'Payload geladen via stream-wrapper-truc (zip://, phar://, compress.zlib:// of data://) i.c.m. require/include - ontwijkingstechniek, BACKDOOR PATROON';
        $verdacht = true;
    }

    // PATROON 15: Numerieke byte-array + chr()-decodering.
    // Een obfuscatietechniek waarbij tekens niet als leesbare string, maar als
    // reeks losse ASCII-getallen worden opgeslagen en pas runtime via chr()
    // worden samengevoegd - bedoeld om tekstuele signatuurscanners te omzeilen.
    if (!$verdacht && preg_match('/\$\w+\s*=\s*array\s*\(\s*(\d{2,3}\s*,\s*){6,}\d{2,3}\s*\)\s*;.{0,300}?chr\s*\(\s*\$\w+\[\$?\w+\]\s*\)/is', $inhoud)) {
        $reden = 'Numerieke byte-array + chr()-decodering - obfuscatietechniek om tekstscanners te omzeilen, BACKDOOR PATROON';
        $verdacht = true;
    }

    // PATROON 16: chr()-gebaseerde tekenreeksobfuscatie van een gevoelige
    // functienaam (bv. "bas"."e6".chr(52)."_"."de"."cod".chr(101), wat
    // runtime "base64_decode" oplevert) i.c.m. eval() en superglobal-input.
    // Ontdekt bij een echte, actief werkende webshell (augustus 2026,
    // vermomd als "baforms_helper.php") die alle bestaande 15 patronen
    // omzeilde: geen enkele gevaarlijke functienaam (eval/base64_decode)
    // komt daar ooit als leesbare tekst voor, dus geen van de tekstuele
    // patronen hierboven sloeg aan - alleen de bestandsgrootte-cluster-
    // detectie (vindMassaleUpload()) ving 'm toevallig op, en dan nog met
    // een laag vertrouwen.
    //
    // Drie of meer chr()-aanroepen die zelf onderdeel zijn van een
    // stringconcatenatie is op zichzelf al zeldzaam in legitieme code (een
    // enkele chr(13).chr(10) voor regeleindes komt weleens voor, drie-of-
    // meer-in-context vrijwel nooit) - alleen samen met eval() ÉN
    // superglobal-input in hetzelfde bestand is de combinatie specifiek
    // genoeg om laag-risico valse-positieven te vermijden.
    if (!$verdacht) {
        $aantalChrInConcatenatie = preg_match_all('/(?:[\'"][^\'"]*[\'"]\s*\.\s*)?chr\s*\(\s*[\'"]?\d{1,3}[\'"]?\s*\)\s*\.?/i', $inhoud);
        if ($aantalChrInConcatenatie >= 3
            && preg_match('/\beval\s*\(/i', $inhoud)
            && preg_match('/\$_(POST|GET|REQUEST|COOKIE|SESSION)\b/i', $inhoud)) {
            $reden = "chr()-gebaseerde tekenreeksobfuscatie van een gevoelige functienaam ({$aantalChrInConcatenatie}x) i.c.m. eval() en "
                . 'superglobal-input - OBFUSCATED BACKDOOR (geavanceerde webshell-vermomming, omzeilt tekstuele signatuurscanners)';
            $verdacht = true;
        }
    }

    if ($verdacht) {
        $vondsten[] = [
            'naam' => str_replace(__DIR__, '', $bestandpad),
            'reden' => $reden,
            'risico' => bepaalRisico($reden),
            'bestandspad' => $bestandpad,
            'gewijzigd' => date('Y-m-d H:i', filemtime($bestandpad)),
            'grootte' => strlen($inhoud),
        ];
    }
}

// ============================================================================
// VERDACHTE .HTACCESS-BESTANDEN
// ============================================================================
//
// Symptomen die we hier specifiek herkennen (gezien op deze site):
//   1. Een .htaccess buiten de root - een schone Joomla-installatie plaatst
//      er normaal maar één, in de root van de site.
//   2. Een FilesMatch-blok met "Require all denied" op een lijst
//      (php/phtml/.suspected/etc.) - dit is precies de zelfbeschermings-
//      truc die we op deze site in vrijwel elke map aantroffen: het
//      voorkomt dat scanners of de eigenaar de eigen backdoor-bestanden
//      via een directe request kunnen aanroepen of laten uitvoeren.
/**
 * Bekende, automatisch door extensies gegenereerde .htaccess-bestanden die
 * legitiem scriptuitvoering blokkeren in hun eigen upload-/afbeeldingenmap
 * (via <FilesMatch>) - een goede beveiligingsmaatregel op zich, maar die
 * anders ten onrechte als "meer dan een simpel deny-blok" gemeld wordt.
 * Herkenning gebeurt op een specifieke, herkenbare tekstsignatuur in de
 * inhoud zelf (niet zomaar op de aanwezigheid van FilesMatch alleen, want
 * dát kan net zo goed door een echte backdoor gebruikt worden).
 */
function isBekendGoedaardigeHtaccess(string $inhoud): bool
{
    $bekendeSignaturen = [
        'generated automatically by iCagenda',
        'automatically generated by Admin Tools',
    ];

    foreach ($bekendeSignaturen as $signatuur) {
        if (stripos($inhoud, $signatuur) !== false) {
            return true;
        }
    }

    return false;
}

function scanHtaccessVoorMalware($bestandpad, &$vondsten, $startMap)
{
    if (!is_readable($bestandpad)) {
        return;
    }

    $inhoud = file_get_contents($bestandpad);
    if ($inhoud === false) {
        return;
    }

    if (isBekendGoedaardigeHtaccess($inhoud)) {
        return;
    }

    // AWStats is een losse, door de hostingpartij zelf geplaatste tool voor
    // bezoekersstatistieken - geen onderdeel van de Joomla-site zelf, en
    // dus geen extensie die wij hoeven te bewaken. Uitgesloten op mapnaam
    // (niet op inhoud, want AWStats' eigen .htaccess varieert per versie).
    $mapNaamKleinLetters = strtolower(basename(dirname($bestandpad)));
    if ($mapNaamKleinLetters === 'awstats') {
        return;
    }

    // Akeeba Backup (zowel de oudere "com_akeeba" als de nieuwere
    // "com_akeebabackup"-naamgeving) plaatst standaard een eigen
    // .htaccess in zijn backup-map, die vaker dan een kaal deny-blok
    // bevat (bijv. een FilesMatch-blok om alle bestandstypes te blokkeren,
    // niet alleen php) - een bekend, vast patroon van de extensie zelf,
    // geen aanwijzing voor iets verdachts. Uitgesloten op pad, niet op
    // inhoud (die kan per Akeeba-versie verschillen).
    $genormaliseerdPadKleinLetters = strtolower(str_replace('\\', '/', $bestandpad));
    if (preg_match('#/com_akeeba(backup)?/backup/\.htaccess$#', $genormaliseerdPadKleinLetters)) {
        return;
    }

    $redenen = [];
    $kritiek = false;

    // Het echte gevaar: FilesMatch + een deny-regel op php/phtml/.suspected-
    // achtige extensies - dit is de zelfbeschermingstruc die we op deze site
    // massaal aantroffen. Dit alleen is voldoende voor een KRITIEKE melding.
    // Zowel de nieuwere Apache 2.4-schrijfwijze ("Require all denied") als
    // de oudere, nog altijd veelgebruikte Apache 2.2-schrijfwijze ("Order
    // allow,deny" + "Deny from all") worden herkend - een echt aangetroffen
    // exemplaar bleek de oudere schrijfwijze te gebruiken, dus alleen op de
    // nieuwere controleren zou dit soort gevallen missen.
    $heeftDenyRegel = preg_match('/Require\s+all\s+denied/i', $inhoud)
        || (preg_match('/Deny\s+from\s+all/i', $inhoud) && preg_match('/Order\s+allow\s*,\s*deny/i', $inhoud));

    if (preg_match('/FilesMatch/i', $inhoud) && $heeftDenyRegel) {
        if (preg_match('/\.\s*\(.*\b(php\d?|phtml|suspected|phar)\b/i', $inhoud) || stripos($inhoud, 'suspected') !== false) {
            $redenen[] = 'FilesMatch + deny-regel (Require all denied, of Order/Deny from all) op php/phtml/.suspected-achtige extensies - '
                . 'typische zelfbescherming van een backdoor (blokkeert scanners/direct aanroepen van de eigen malware-bestanden)';
            $kritiek = true;
        }
    }

    // Een RewriteRule die alles naar index.php stuurt, buiten de root, is ook
    // een sterk signaal (dat was het cloaking-mechanisme op deze site).
    if (preg_match('/RewriteRule\s+\.\s+index\.php/i', $inhoud)) {
        $redenen[] = 'RewriteRule die alle requests naar index.php stuurt (cloaking-patroon) in een .htaccess buiten de root';
        $kritiek = true;
    }

    // Locatie buiten de root is op zichzelf GEEN bewijs van malware - veel
    // Joomla-extensies (bv. Akeeba backup, Composer-vendor-mappen) plaatsen
    // bewust een klein defensief .htaccess-je ("deny from all") in hun eigen
    // map om directory-browsing te blokkeren. Alleen melden als aparte,
    // lichte waarschuwing zodat je het kan verifiëren, niet als kritiek.
    if (!$kritiek) {
        $genormaliseerdeRoot = rtrim(str_replace('\\', '/', $startMap), '/');
        $genormaliseerdeMap = rtrim(str_replace('\\', '/', dirname($bestandpad)), '/');
        $isKleinDefensiefBlok = strlen($inhoud) < 400 && !preg_match('/RewriteRule|RewriteCond|FilesMatch/i', $inhoud);
        if ($genormaliseerdeMap !== $genormaliseerdeRoot && !$isKleinDefensiefBlok) {
            $redenen[] = 'Ongebruikelijke .htaccess buiten de root (in "' . str_replace($startMap, '', dirname($bestandpad)) . '") '
                . 'met meer dan een simpel deny-blok - handmatig controleren';
        }
    }

    if (!empty($redenen)) {
        $redenTekst = ($kritiek ? '[KRITIEK] ' : '[TER INFO] ') . implode(' | ', $redenen);
        $vondsten[] = [
            'naam' => str_replace($startMap, '', $bestandpad),
            'reden' => $redenTekst,
            'risico' => $kritiek ? 75 : 30,
            'bestandspad' => $bestandpad,
            'gewijzigd' => date('Y-m-d H:i', filemtime($bestandpad)),
            'grootte' => strlen($inhoud),
        ];
    }
}

/**
 * Herkent of een aangetroffen "<?" op een gegeven positie het begin is van
 * een onschuldige, XML-/SGML-achtige "processing instruction" (bv. "<?xml
 * version=...? >", "<?xpacket begin=...? >", "<?adobe-xap-filters esc=...? >")
 * in plaats van een echte PHP-openingstag.
 *
 * Principe i.p.v. een losse, steeds groeiende woordenlijst: een generieke
 * processing instruction heeft de vorm "<?naam ...? >" met een naam van
 * kleine letters/cijfers/koppeltekens, en bevat NERGENS een PHP-variabele-
 * sigil ($) - dat laatste is precies wat écht PHP-code (vrijwel) altijd wél
 * heeft. "<?php" en "<?=" zijn hierop een uitzondering: die worden ALTIJD
 * als echte PHP-tag behandeld, ongeacht wat erna komt, want die twee vormen
 * hebben geen andere legitieme betekenis dan "hier begint PHP-code".
 *
 * Ontdekt nadat een eerdere versie, die alleen "xml" en "xpacket" met naam
 * uitsloot, alsnog aansloeg op een heel gewone, originele foto: die bleek
 * behalve de twee "xpacket"-varianten ook nog een "<?adobe-xap-filters
 * esc=...? >" te bevatten - onderdeel van dezelfde, standaard Adobe XMP-
 * metadata-wrapper, maar met een andere naam dan de al uitgesloten twee.
 */
function isXmlAchtigeProcessingInstructie(string $inhoud, int $tagPositie): bool
{
    $eindePositie = strpos($inhoud, '?>', $tagPositie);
    $venster = $eindePositie !== false
        ? substr($inhoud, $tagPositie, $eindePositie - $tagPositie + 2)
        : substr($inhoud, $tagPositie, 500);

    // "<?php" en "<?=" zijn ALTIJD een echte PHP-tag, nooit een processing
    // instruction - deze twee vormen hebben immers geen andere betekenis.
    if (preg_match('/^<\?(php\b|=)/i', $venster)) {
        return false;
    }

    // Generieke XML-/SGML-achtige processing instruction: "<?" direct
    // gevolgd door een naam van kleine letters/cijfers/koppeltekens, zonder
    // dat er ergens in het venster (tot en met de afsluitende "? >") een
    // PHP-variabele-sigil ($) voorkomt.
    return (bool) (preg_match('/^<\?[a-z][a-z0-9-]*(\s|\?>)/i', $venster) && strpos($venster, '$') === false);
}

/**
 * Lichte, EXTENSIE-ONAFHANKELIJKE inhoudscontrole - alleen toegepast binnen
 * bekende uploadmappen (images/tmp/media, zie $gevoeligeUploadmapNamen
 * hieronder). De gewone extensie-gebaseerde controle (isPhpUitvoerbareExtensie())
 * leest een bestand nooit als de extensie niet overeenkomt - dat mist twee
 * bekende trucs die specifiek op uploadmappen gericht zijn:
 *   - de dubbele-extensietruc, bv. "foto.php.gif" (eindigt op .gif, dus
 *     genegeerd door de gewone check, terwijl de inhoud echt PHP is);
 *   - een bestand zonder enige extensie (bv. "sourcerer_php_<hash>",
 *     bewust vermomd als een naam die bij een bekende, legitieme extensie
 *     past - RegularLabs' Sourcerer in dit geval).
 * Allebei daadwerkelijk aangetroffen (augustus 2026, tmp/-map).
 *
 * Bewust een lage bestandsgrootte-grens: een echte foto/video in een
 * uploadmap is vrijwel altijd groter dan dit, terwijl de aangetroffen
 * verstopte payloads stuk voor stuk enkele honderden tot ~1.100 bytes
 * waren. Zonder deze grens zou elk groot mediabestand in de uploadmap
 * volledig ingelezen moeten worden, wat de scan onnodig zwaar maakt voor
 * vrijwel zekere valse positieven.
 */
function scanNietPhpBestandOpVerstopteCode($bestandpad, &$backdoorVondsten, &$mogelijkLegitiem, $ignoreerBestanden, $startMap)
{
    if (!is_readable($bestandpad)) {
        return;
    }

    // Bekende, legitieme code-/opmaak-/documentextensies overslaan: deze
    // horen weliswaar niet per se in images/tmp thuis, maar bevatten in de
    // praktijk vaker onschuldig de tekens "<?php" (bv. documentatie,
    // voorbeeldcode in een syntax-highlighter, of simpelweg tekst erover)
    // dan een daadwerkelijk verstopte backdoor - dat leverde bij een eerste
    // versie van deze check al een valse-positief op. De focus blijft
    // bewust op bestanden zonder extensie en op afbeeldings-/document-
    // achtige extensies, want dát is precies waarmee een backdoor zich in
    // een uploadmap vermomt.
    $overgeslagenExtensies = ['js', 'css', 'json', 'md', 'less', 'scss', 'map', 'sql', 'yml', 'yaml'];
    $extensie = strtolower(pathinfo($bestandpad, PATHINFO_EXTENSION));
    if ($extensie !== '' && in_array($extensie, $overgeslagenExtensies, true)) {
        return;
    }

    $grootte = @filesize($bestandpad);
    if ($grootte === false || $grootte === 0 || $grootte > 60000) {
        return;
    }

    $inhoud = @file_get_contents($bestandpad);
    if ($inhoud === false || $inhoud === '') {
        return;
    }

    // Zoek naar élke vorm van een PHP-openingstag: "<?php", de korte
    // echo-tag "<?=", én de kale korte tag "<?" (bv. "<? $a=...;"). Die
    // laatste twee werden eerder bewust NIET meegenomen (een reeks van
    // 2-3 bytes komt in binaire data simpelweg te vaak toevallig voor),
    // maar de leesbaarheidscontrole hieronder (het venster ná de tag moet
    // er ook daadwerkelijk als broncode uitzien) vangt dat toevalsrisico
    // inmiddels al af - en de kale korte tag is precies de vorm die bij
    // een concreet aangetroffen GIF/PHP-polyglot-webshell werd gebruikt
    // (GIF89a-header direct gevolgd door "<? $a=..."), specifiek om
    // detectie op de langere "<?php" te omzeilen.
    //
    // GENERIEKE uitzondering voor XML-/SGML-achtige processing instructions
    // (bv. "<?xml version=...? >", "<?xpacket begin=...? >", "<?adobe-xap-
    // filters esc=...? >") i.p.v. een steeds groeiende, losse woordenlijst.
    // Eén enkele, echte foto bleek bij nader onderzoek zomaar DRIE van dit
    // soort "<?"-voorkomens te bevatten (de volledige Adobe XMP-metadata-
    // wrapper: "<?xpacket begin=...? >", "<?adobe-xap-filters esc=...? >" én
    // "<?xpacket end=...? >") - een eerdere versie die alleen "xml" en
    // "xpacket" met naam uitsloot, miste de tweede en bleef daardoor toch
    // nog onterecht aanslaan. Elk toekomstig, nog onbekend PI-achtig
    // voorschriftje (welke naam dan ook) wordt nu automatisch net zo
    // herkend, zie isXmlAchtigeProcessingInstructie() hieronder.
    //
    // "<?php" en "<?=" worden daarbij ALTIJD als echte PHP-tag behandeld,
    // ongeacht wat erna komt.
    if (!preg_match_all('/<\?/', $inhoud, $alleTagsMatch, PREG_OFFSET_CAPTURE)) {
        return; // geen PHP-openingstag aangetroffen, verder geen actie nodig
    }
    $tagPositie = false;
    foreach ($alleTagsMatch[0] as $tagVondst) {
        $kandidaatPositie = $tagVondst[1];
        if (isXmlAchtigeProcessingInstructie($inhoud, $kandidaatPositie)) {
            continue; // legitieme XML-/XMP-achtige processing instruction, overslaan
        }
        $tagPositie = $kandidaatPositie;
        break;
    }
    if ($tagPositie === false) {
        return; // alle aangetroffen "<?"-voorkomens waren legitieme processing instructions
    }

    // Vervolgcontrole: ziet het stuk NA de tag er ook daadwerkelijk als
    // leesbare (PHP-)broncode uit, of is dit gewoon toevallige binaire
    // troep die toevallig met "<?php" begint? Echte broncode bestaat
    // vrijwel volledig uit leesbare ASCII-tekens/witruimte; binaire
    // beelddata ernaast is dat typisch niet. Bewust ruim genomen venster
    // (300 bytes) zodat ook een korte payload gevolgd door verdere
    // (evt. binaire) troep verderop in het bestand niet ten onrechte wordt
    // afgekeurd.
    $venster = substr($inhoud, $tagPositie, 300);
    $aantalLeesbaar = preg_match_all('/[\x09\x0A\x0D\x20-\x7E]/', $venster);
    $leesbareVerhouding = strlen($venster) > 0 ? $aantalLeesbaar / strlen($venster) : 0;
    if ($leesbareVerhouding < 0.90) {
        return; // ziet eruit als toevallige binaire ruis, geen echte broncode
    }

    // Extra scrutiny, alléén voor de ambigue KALE korte tag (dus niet voor
    // "<?php"/"<?=" - die twee zijn zo specifiek dat verdere twijfel niet
    // nodig is): het percentage-leesbaar-criterium hierboven is getest tegen
    // GECOMPRIMEERDE beeldformaten (JPEG) en werkt daar goed, maar een
    // ONGECOMPRIMEERD formaat zoals BMP slaat ruwe pixelbytes rechtstreeks
    // op - bij een lichtgekleurde afbeelding (bv. een boekomslag met een
    // lichte achtergrond) kan zo'n venster toevallig ruim boven de 90%-
    // grens uitkomen zonder ook maar enigszins op code te lijken. Concreet
    // aangetroffen bij een echt, onschuldig BMP-bestand (augustus 2026).
    // Daarom hier aanvullend eisen dat het venster ook daadwerkelijk iets
    // bevat dat op PHP-syntax lijkt: een "$variabele" of een bekende
    // gevaarlijke functienaam - iets wat toevallige ruis, in tegenstelling
    // tot puur toevallig veel leesbare tekens, vrijwel nooit oplevert.
    $isKaleKorteTag = !preg_match('/^<\?(php\b|=)/i', $venster);
    if ($isKaleKorteTag) {
        $lijktOpPhpSyntax = preg_match('/\$[a-zA-Z_]\w*/', $venster)
            || preg_match('/\b(eval|assert|system|exec|shell_exec|passthru|popen|proc_open|base64_decode|base64_encode|create_function|gzinflate|gzuncompress|str_rot13)\s*\(/i', $venster);
        if (!$lijktOpPhpSyntax) {
            return; // toevallig veel leesbare tekens, maar nergens iets dat op PHP lijkt
        }
    }

    // Wél een PHP-openingstag: alsnog door dezelfde patroonherkenning halen
    // als een normaal .php-bestand (kan alsnog een concrete backdoor-vondst
    // toevoegen aan $backdoorVondsten).
    $aantalVondstenVoor = count($backdoorVondsten);
    scanPhpVoorBackdoors($bestandpad, $backdoorVondsten, $mogelijkLegitiem, $ignoreerBestanden);

    // Ook als geen van de specifieke patronen raakte: een PHP-openingstag in
    // een bestand met een niet-PHP(-achtige) extensie, in een uploadmap, is
    // op zichzelf al reden genoeg voor een melding - dit is precies de
    // dubbele-extensietruc/naamvermomming zelf, los van wat de PHP-code doet.
    if (count($backdoorVondsten) === $aantalVondstenVoor) {
        $backdoorVondsten[] = [
            'naam' => str_replace($startMap, '', $bestandpad),
            'reden' => 'PHP-openingstag aangetroffen in een bestand met een niet-PHP(-achtige) extensie in een uploadmap - '
                . 'mogelijke dubbele-extensietruc (bv. "foto.php.gif") of vermomd bestand zonder passende extensie, VERDACHT',
            'risico' => 70,
            'bestandspad' => $bestandpad,
            'gewijzigd' => date('Y-m-d H:i', @filemtime($bestandpad) ?: time()),
            'grootte' => strlen($inhoud),
        ];
    }
}

// ============================================================================
// SCAN ALLES RECURSIEF VOOR BACKDOORS
// ============================================================================

/**
 * Bekende, legitieme third-party libraries die door hun programmeerstijl
 * (dynamische functie-aanroepen i.c.m. bestandsfuncties) regelmatig als
 * valse-positief backdoor worden herkend. Uitsluiting gebeurt bewust op
 * basis van het VOLLEDIGE relatieve pad (niet alleen de bestandsnaam) -
 * een backdoor die toevallig dezelfde bestandsnaam gebruikt maar ergens
 * anders staat, wordt dus gewoon nog steeds gemeld.
 */
function isBekendeLegitiemeLibrary(string $volledigPad, string $startMap): bool
{
    $relatiefPad = str_replace('\\', '/', str_replace($startMap, '', $volledigPad));

    $bekendePatronen = [
        '#/com_rsseo/helpers/phpQuery\.php$#i',
        // RegularLabs' gedeelde "assignments"-helper (gebruikt door o.a.
        // Sourcerer, DPCalendar en andere RegularLabs-extensies) gebruikt
        // create_function() als callback-mechanisme - door Wouter zelf
        // gecontroleerd en bevestigd als legitiem (augustus 2026).
        '#/libraries/regularlabs/helpers/assignments/php\.php$#i',
    ];

    foreach ($bekendePatronen as $patroon) {
        if (preg_match($patroon, $relatiefPad)) {
            return true;
        }
    }

    return false;
}

/**
 * Herkent alle extensies die door minstens één veelgebruikte Apache-/
 * LiteSpeed-/hostingconfiguratie als PHP (of, bij .shtml, als server-side-
 * uitvoerbaar) kunnen worden aangemerkt.
 *
 * Uitgebreid n.a.v. een echte vondst (augustus 2026, images/- en tmp-map)
 * waarbij een geautomatiseerde uploadtool exact dezelfde payload onder
 * tientallen extensie-varianten plaatste (up_1031.pHp, up_1946.Pht,
 * up_6089.PhP5, up_6220.php8, ...) om te testen welke extensie de server
 * daadwerkelijk als PHP uitvoert. De eerdere, vaste lijst
 * (.php/.phtml/.php5/.phar, met maar één cijfer na "php") miste daarbij
 * o.a. .pht, .shtml en meercijferige varianten als .php56 - precies de
 * varianten die in die uploadgolf zaten.
 */
function isPhpUitvoerbareExtensie(string $bestandsnaam): bool
{
    return (bool) preg_match('/\.(phtml|pht|phar|shtml|php\d*)$/i', $bestandsnaam);
}

/**
 * Controleert een php.ini/.user.ini-bestand op een combinatie van
 * beveiligingsverzwakkende directives - kenmerkend voor een "sleutel zonder
 * slot" die een aanvaller vlak vóór (of samen met) een webshell neerzet, om
 * lokaal (in die map en alles eronder) de door de hostingpartij ingestelde
 * restricties (disable_functions/open_basedir) te omzeilen.
 *
 * Bewust een drempel van TWEE-OF-MEER signalen, niet één: een site-eigenaar
 * kan best legitiem een eigen php.ini in een submap hebben staan (bv. om de
 * uploadlimiet of geheugenlimiet te verhogen) - dat gebruikt vrijwel nooit
 * deze specifieke combinatie. "safe_mode" alleen al is op zichzelf al een
 * sterke aanwijzing (die instelling bestaat sinds PHP 5.4 niet meer, dus
 * geen enkele moderne, zelfgeschreven configuratie zou 'm ooit vermelden -
 * dat wijst op een verouderd, kant-en-klaar aanvalssjabloon), maar telt hier
 * toch als "maar één signaal" om dat conservatief te houden.
 */
function scanPhpIniVoorVerzwakkingen($bestandpad, &$backdoorVondsten, $startMap)
{
    $inhoud = @file_get_contents($bestandpad, false, null, 0, 8192);
    if ($inhoud === false || $inhoud === '') {
        return;
    }

    $signalen = [];

    if (preg_match('/^\s*disable_functions\s*=\s*(none)?\s*$/im', $inhoud)) {
        $signalen[] = 'disable_functions leeggemaakt';
    }
    if (preg_match('/^\s*open_basedir\s*=\s*(off|none)?\s*$/im', $inhoud)) {
        $signalen[] = 'open_basedir uitgeschakeld';
    }
    if (preg_match('/^\s*safe_mode(_gid)?\s*=/im', $inhoud)) {
        // "safe_mode" bestaat sinds PHP 5.4 (2012) niet meer - de aanwezigheid
        // ervan is op zichzelf al een teken van een verouderd, gekopieerd
        // aanvalssjabloon, ongeacht de ingestelde waarde.
        $signalen[] = 'verouderde/niet-bestaande "safe_mode"-directive aanwezig';
    }
    if (preg_match('/^\s*(exec|shell_exec)\s*=\s*on\s*$/im', $inhoud)) {
        $signalen[] = '"exec"/"shell_exec" expliciet aangezet (bestaat niet als officiële php.ini-directive - typisch voor kant-en-klare aanvalssjablonen)';
    }

    if (count($signalen) < 2) {
        return; // te weinig signalen, mogelijk een legitieme, handmatige aanpassing
    }

    $backdoorVondsten[] = [
        'naam' => str_replace($startMap, '', $bestandpad),
        'reden' => 'Beveiligingsverzwakkende php.ini-directives aangetroffen (' . implode('; ', $signalen) . ') - '
            . 'kenmerkend voor een "sleutel zonder slot" die vlak vóór/samen met een webshell wordt geplaatst om '
            . 'hostingbrede restricties (disable_functions/open_basedir) lokaal te omzeilen. Controleer deze map en '
            . 'alles eronder handmatig via FTP op een bijbehorend bestand. VERDACHT',
        'risico' => 85,
        'bestandspad' => $bestandpad,
        'gewijzigd' => date('Y-m-d H:i', @filemtime($bestandpad) ?: time()),
        'grootte' => strlen($inhoud),
    ];
}

function scanRecursief($pad, &$backdoorVondsten, &$htaccessVondsten, &$mogelijkLegitiem, $ignoreerBestanden, $startMap, $diepte = 0, array $topNiveauNegeren = [], &$rechtenAfwijkingen = null, $inGevoeligeUploadmap = false)
{
    // Mappen waarvan de INHOUD (dus alles eronder, ongeacht diepte) als
    // "gevoelige uploadmap" wordt beschouwd voor scanNietPhpBestandOpVerstopteCode()
    // hierboven - typische plekken waar een aanvaller een bestand kan
    // uploaden zonder dat er ergens een extensie-restrictie geldt. Alleen
    // toegepast op het eerste niveau van een scanRecursief()-aanroep, zodat
    // het bepalen van "zit ik in zo'n map" niet op elke diepte opnieuw op
    // naam hoeft (de vlag wordt hieronder gewoon doorgegeven aan de recursie).
    //
    // BEWUST NIET "media" hier: dat is bij Joomla het standaard bezorgpunt
    // voor JS/CSS/lettertype-/afbeeldingsbestanden die extensies zelf
    // meeleveren (bv. media/com_jce/editor/tinymce/...) - vol met legitieme
    // niet-PHP-bestanden die soms toevallig de tekens "<?php" bevatten
    // (bv. een code-editor-plugin met PHP als voorbeeldtaal in zijn eigen
    // broncode). Dat leverde bij een eerste, bredere versie van deze check
    // een valse-positief op (plugin.js van de JCE-editor). "media" is
    // functioneel heel anders dan images/tmp: geen plek waar bezoekers of
    // een aanvaller normaal gesproken zelf bestanden neerzetten.
    static $gevoeligeUploadmapNamen = ['images', 'tmp'];

    // Gedeelde veiligheidsklep over ALLE aanroepen van deze functie heen
    // (dus zowel de hoofdscan van de website als, apart, het extra
    // scanpad) - een `static` variabele in PHP wordt maar één keer
    // aangemaakt en blijft daarna gewoon bestaan, ook bij recursieve
    // aanroepen, dus dit werkt als één gezamenlijk budget voor de hele
    // scan. Nodig sinds .cagefs/.cl.selector bewust NIET meer worden
    // overgeslagen (zie de standaard-uitsluitlijst verderop) - die kunnen
    // op een gedeeld hostingaccount tienduizenden bestanden bevatten
    // (een kopie van elke geïnstalleerde PHP-versie), en zonder deze klep
    // zou dat de tijds-/geheugenlimiet van de server kunnen raken, met een
    // HTTP 500 halverwege de scan tot gevolg in plaats van een nette,
    // vroegtijdige afronding.
    static $totaalVerwerkteItems = 0;
    static $scanStartTijd = null;
    $maxTotaalTeVerwerken = 20000;
    $maxSecondenVoorRecursie = 45; // flink onder de set_time_limit(120) hierboven, zodat er ruim voldoende marge overblijft voor extensies/Super Users/het verzenden van het resultaat hierna

    if ($scanStartTijd === null) {
        $scanStartTijd = microtime(true);
    }

    if ($totaalVerwerkteItems >= $maxTotaalTeVerwerken || (microtime(true) - $scanStartTijd) > $maxSecondenVoorRecursie) {
        return;
    }

    if ($diepte > 10) {
        return;
    }

    if (!is_readable($pad)) {
        return;
    }

    $items = @scandir($pad);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($totaalVerwerkteItems >= $maxTotaalTeVerwerken || (microtime(true) - $scanStartTijd) > $maxSecondenVoorRecursie) {
            return;
        }

        if ($item === '.' || $item === '..' || $item === 'cache' || $item === 'log' || $item === 'logs' || $item === '_scan_beheer') {
            continue;
        }

        // Alleen van toepassing op het allereerste niveau van DEZE aanroep
        // (dus bijv. alleen direct binnen het extra scanpad, nooit ergens
        // dieper binnen de website zelf) - zie de aanroep bij het extra
        // scanpad hieronder.
        if ($diepte === 0 && in_array($item, $topNiveauNegeren, true)) {
            continue;
        }

        $totaalVerwerkteItems++;

        $volledigPad = $pad . '/' . $item;

        // Alleen dieper dan het topniveau - checkExtraScanpadTopNiveau()
        // heeft de rechten van de topniveau-items zelf al gecontroleerd,
        // dit zou anders dubbele meldingen opleveren voor diezelfde items.
        //
        // Uitzondering: binnen .cagefs/.cl.selector (bewust WEL op inhoud
        // gescand, zie de toelichting bij de standaard-uitsluitlijst
        // verderop - daar is ooit een echte backdoor aangetroffen) slaan we
        // de RECHTENcontrole wél over. CloudLinux zet daar voor elke
        // beschikbare PHP-versie op de server een eigen kopie van dezelfde
        // systeembestanden neer (bijv. tien+ keer hetzelfde
        // "zzzzzzz-pecl.ini", één per PHP-versie) met rechten die het zelf
        // beheert en die structureel afwijken van een normale webmap -
        // zonder deze uitzondering zou dat alleen al tot honderden
        // herhaalde, betekenisloze meldingen leiden. De BACKDOOR-detectie
        // (op inhoud) hierboven blijft binnen deze mappen gewoon volledig
        // actief.
        $binnenCageFsOfClSelector = (bool) preg_match('#[/\\\\]\.(cagefs|cl\.selector)([/\\\\]|$)#i', $volledigPad);

        if ($rechtenAfwijkingen !== null && $diepte > 0 && !$binnenCageFsOfClSelector) {
            $afwijking = controleerBestandsrechten($volledigPad, is_dir($volledigPad));
            if ($afwijking !== null) {
                $rechtenAfwijkingen[] = $afwijking;
            }
        }

        if (is_dir($volledigPad)) {
            // Bij sommige hostingpartijen (bijv. Vimexx) is "private_html"
            // een symlink die naar exact dezelfde fysieke locatie wijst als
            // de gewone website-root ("public_html") - beide mappen tonen
            // dan letterlijk dezelfde bestanden, niet een kopie ervan. Zonder
            // deze controle zou de hoofdscan (die $startMap al apart en
            // volledig doorzoekt) via het extra scanpad diezelfde bestanden
            // nog een keer tegenkomen, met dubbele "verdacht"-meldingen tot
            // gevolg - één voor elk pad waaronder hetzelfde bestand zichtbaar
            // is. realpath() vergelijken (in plaats van alleen is_link() te
            // checken) vangt dit ongeacht hoe de koppeling technisch is
            // gerealiseerd (symlink, bind mount, of iets anders).
            $werkelijkPadVanMap = @realpath($volledigPad);
            $werkelijkeStartMap = @realpath($startMap);
            if ($werkelijkPadVanMap !== false && $werkelijkeStartMap !== false && $werkelijkPadVanMap === $werkelijkeStartMap) {
                continue;
            }

            // Speciale aandacht voor nummermappen
            if (preg_match('/^\d{4,}$/', $item)) {
                // Scan alle PHP in nummergegeven map
                $subItems = @scandir($volledigPad);
                if ($subItems) {
                    foreach ($subItems as $subItem) {
                        if (isPhpUitvoerbareExtensie($subItem)
                            && !isBekendeLegitiemeLibrary($volledigPad . '/' . $subItem, $startMap)) {
                            scanPhpVoorBackdoors($volledigPad . '/' . $subItem, $backdoorVondsten, $mogelijkLegitiem, $ignoreerBestanden);
                        }
                    }
                }
            } else {
                // Normale map: scan recursief. Zodra we op het eerste niveau
                // een bekende uploadmap tegenkomen (images/tmp/media), geldt
                // dat voor de hele submap eronder, ongeacht hoe diep - vandaar
                // dat de vlag hier bepaald wordt en daarna gewoon meegegeven
                // blijft worden aan diepere aanroepen.
                $doorGevenGevoeligeUploadmap = $inGevoeligeUploadmap
                    || ($diepte === 0 && in_array(strtolower($item), $gevoeligeUploadmapNamen, true));

                scanRecursief($volledigPad, $backdoorVondsten, $htaccessVondsten, $mogelijkLegitiem, $ignoreerBestanden, $startMap, $diepte + 1, [], $rechtenAfwijkingen, $doorGevenGevoeligeUploadmap);
            }

        } else {
            // 0-byte exploit-scanner-restanten: los van bestandsextensie,
            // dus vóór de php-/.htaccess-specifieke checks hieronder.
            if (isVermoedelijkExploitScannerRestant($item, @filesize($volledigPad))) {
                $backdoorVondsten[] = [
                    'naam' => str_replace($startMap, '', $volledigPad),
                    'reden' => 'TER INFO: 0-byte bestand met een SHA-1-hash-achtige naam - bekend restant van een geautomatiseerde exploit-scanner/probe-tool die testte of deze map schrijfbaar is. Het bestand zelf is onschuldig (0 bytes), maar bewijst wel dat hier ooit schrijftoegang is geweest.',
                    'risico' => 35,
                    'bestandspad' => $volledigPad,
                    'gewijzigd' => date('Y-m-d H:i', @filemtime($volledigPad) ?: time()),
                    'grootte' => 0,
                ];
            }

            // PHP-bestanden: scan (behalve bekende, legitieme libraries met
            // een programmeerstijl die regelmatig als valse-positief wordt gezien)
            $heeftPhpUitvoerbareExtensie = isPhpUitvoerbareExtensie($item);

            if ($heeftPhpUitvoerbareExtensie && !isBekendeLegitiemeLibrary($volledigPad, $startMap)) {
                scanPhpVoorBackdoors($volledigPad, $backdoorVondsten, $mogelijkLegitiem, $ignoreerBestanden);
            }

            // Extensie-onafhankelijke inhoudscontrole - alleen binnen een
            // gevoelige uploadmap (images/tmp/media) en alleen voor bestanden
            // die de extensiecheck hierboven NIET al meenam (voorkomt een
            // dubbele scan/dubbele melding van hetzelfde bestand).
            if (!$heeftPhpUitvoerbareExtensie && $inGevoeligeUploadmap) {
                scanNietPhpBestandOpVerstopteCode($volledigPad, $backdoorVondsten, $mogelijkLegitiem, $ignoreerBestanden, $startMap);
            }

            // .htaccess-bestanden: apart scannen op zelfbeschermings-/locatiepatronen
            if (strtolower($item) === '.htaccess') {
                scanHtaccessVoorMalware($volledigPad, $htaccessVondsten, $startMap);
            }

            // php.ini/.user.ini: apart scannen op beveiligingsverzwakkende
            // directives. Deze twee bestandsnamen worden door PHP (op veel
            // hostingconfiguraties, met name CGI/FastCGI) per map gelezen -
            // een aanvaller kan zo'n bestand ergens diep in een eigen,
            // willekeurig genoemde map neerzetten om lokaal de beveiligings-
            // maatregelen van de hostingpartij (disable_functions/
            // open_basedir) te omzeilen, meestal ter voorbereiding op een
            // webshell in dezelfde of een onderliggende map. BEWUST NIET
            // beperkt tot images/tmp (zoals scanNietPhpBestandOpVerstopteCode()
            // hierboven) - dit soort bestanden werd concreet aangetroffen
            // diep genest onder components/com_media/, ver buiten die twee
            // mappen. Getest tegen een echt aangetroffen exemplaar
            // (augustus 2026, samen met een bijbehorende upload-webshell).
            if (strtolower($item) === 'php.ini' || strtolower($item) === '.user.ini') {
                scanPhpIniVoorVerzwakkingen($volledigPad, $backdoorVondsten, $startMap);
            }
        }
    }
}

// ============================================================================
// RECHTENCONTROLE (afwijkingen van het gebruikelijke 755 voor mappen /
// 644 voor bestanden) - alleen gebruikt binnen het extra scanpad, zie
// hieronder. Voor de website zelf is dit bewust niet standaard aan: veel
// hostingpartijen/beveiligingstools zetten daar bewust afwijkende, striktere
// rechten (bijv. configuration.php op 440), en dat is geen probleem.
// ============================================================================

function controleerBestandsrechten($pad, $isMap)
{
    $rechten = @fileperms($pad);
    if ($rechten === false) {
        return null; // niet uit te lezen, geen mening
    }

    $huidigOctaal = substr(sprintf('%o', $rechten), -3);
    $verwachtOctaal = $isMap ? '755' : '644';

    if ($huidigOctaal === $verwachtOctaal) {
        return null;
    }

    // Zowel te ruim (bijv. 777) als te krap (bijv. 555, waardoor de map/
    // het bestand niet meer aan te passen is - soms een teken van
    // manipulatie, soms gewoon een verkeerd bedoelde "beveiliging") wordt
    // gemeld; wat hier de juiste rechten zijn hangt af van de hostingpartij,
    // dus bewust een lager, informatief risico in plaats van een alarm.
    return [
        'type' => $isMap ? 'map' : 'bestand',
        'naam' => $pad,
        'pad' => $pad,
        'risico' => 25,
        'gewijzigd' => date('Y-m-d H:i', @filemtime($pad) ?: time()),
        'reden_override' => "Afwijkende rechten: $huidigOctaal in plaats van het gebruikelijke $verwachtOctaal.",
    ];
}

// ============================================================================
// ONBEKENDE ITEMS + RECHTENAFWIJKINGEN OP HET TOPNIVEAU VAN HET EXTRA
// SCANPAD - lichtere variant van checkRootLevel() hierboven, specifiek voor
// het extra scanpad: geen speciale "vertrouwde bestanden"-lijst (die is
// bedoeld voor een Joomla-website-root, niet voor bijv. een accountroot),
// dus hier wordt - afgezien van de handmatig opgegeven uitsluitlijst -
// letterlijk alles op dat topniveau gemeld. Gebruikt (in tegenstelling tot
// checkRootLevel) het VOLLEDIGE pad als naam, zodat altijd duidelijk blijft
// dat dit van buiten de website zelf komt.
// ============================================================================

function checkExtraScanpadTopNiveau($extraRoot, &$rootUnknown, array $negeren, $startMap)
{
    $items = @scandir($extraRoot);
    if (!$items) {
        return;
    }

    // Kanoniek pad van de website-root, om ook een ANDERS GENAAMDE koppeling
    // naar exact dezelfde fysieke locatie te herkennen (bijv. het bekende
    // private_html/public_html-paar bij Vimexx en vergelijkbare
    // hostingpartijen, waarbij de één een symlink is naar de ander). Puur op
    // naam uitsluiten (zoals hierboven bij het aanroepen van deze functie al
    // gebeurt voor de map waarin het scanscript zelf staat) vangt alleen de
    // ene kant van zo'n paar - deze fysieke vergelijking vangt hem sowieso,
    // ongeacht hoe de map heet.
    $werkelijkeStartMap = @realpath($startMap);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || in_array($item, $negeren, true)) {
            continue;
        }

        // Zelfde NFS-"silly rename"-herkenning als in checkRootLevel()
        // hierboven - zie de uitgebreide toelichting daar.
        if (preg_match('/^\.nfs[0-9a-f]{20,}$/i', $item)) {
            continue;
        }

        // Gedateerde blacklist-logbestanden (bijv. "blacklist.Apr_07_2025.log")
        // - een bekend, terugkerend patroon van een firewall-/spamfilter-
        // achtige tool die per dag een nieuw logbestand wegschrijft. De
        // datum maakt elke bestandsnaam uniek, dus op patroon herkennen in
        // plaats van als vaste naam op de uitsluitlijst te zetten.
        if (preg_match('/^blacklist\.[A-Za-z]{3}_\d{2}_\d{4}\.log$/i', $item)) {
            continue;
        }

        // Standaard php.ini-configuratiesnippets van de hostingpartij zelf
        // (bijv. "zzzzzzz-pecl.ini") - PHP laadt .ini-bestanden in een
        // configuratiemap alfabetisch, dus de reeks z'en aan het begin
        // zorgt er bewust voor dat dit bestand als laatste wordt ingelezen
        // (en dus voorrang krijgt). Niets met de website zelf te maken.
        // Het aantal z's en de naam na het streepje (welke PHP-extensie
        // het activeert) variëren, dus op patroon herkennen.
        if (preg_match('/^z{3,}-.*\.ini$/i', $item)) {
            continue;
        }

        // .cagefs/.cl.selector zelf niet als "onbekend item" melden (dat
        // weten we inmiddels, en is geen nieuwe informatie meer) - maar
        // WEL, in tegenstelling tot de rest van deze uitzonderingen, laten
        // doorlopen naar de gewone recursieve inhoudscontrole verderop
        // (scanRecursief() met dezelfde $extraRoot): daar is ooit een
        // echte backdoor aangetroffen, dus expliciet NIET via de gedeelde
        // $negeren-lijst uitsluiten (dat zou ook de recursie erin
        // blokkeren) - alleen hier, lokaal in deze functie, overslaan.
        if ($item === '.cagefs' || $item === '.cl.selector') {
            continue;
        }

        // Onzichtbare Unicode-tekens - zie checkRootLevel() voor de
        // uitgebreide toelichting. Hier direct melden (niet overslaan),
        // ook al valt dit buiten de website-root: nog steeds de moeite
        // van het bekijken waard.
        if (bevatOnzichtbareUnicodeTekens($item)) {
            $volledigPadOnzichtbaar = $extraRoot . '/' . $item;
            $rootUnknown[] = [
                'type' => is_dir($volledigPadOnzichtbaar) ? 'map' : 'bestand',
                'naam' => $volledigPadOnzichtbaar,
                'pad' => $volledigPadOnzichtbaar,
                'risico' => 90,
                'gewijzigd' => date('Y-m-d H:i', @filemtime($volledigPadOnzichtbaar) ?: time()),
                'reden_override' => 'bestandsnaam bevat onzichtbare Unicode-tekens (zero-width/RTL-override) - een bekende truc om een kwaadaardig bestand te verhullen als iets onschuldigs',
            ];
            continue;
        }

        $volledigPad = $extraRoot . '/' . $item;
        $isMap = is_dir($volledigPad);

        if ($isMap && $werkelijkeStartMap !== false) {
            $werkelijkPadVanMap = @realpath($volledigPad);
            if ($werkelijkPadVanMap !== false && $werkelijkPadVanMap === $werkelijkeStartMap) {
                continue; // fysiek dezelfde map als de website-root, ongeacht de naam hier
            }
        }

        if (!$isMap && strtolower(substr($item, -4)) === '.php') {
            $inhoudVoorVingerafdruk = @file_get_contents($volledigPad, false, null, 0, 4096);
            if ($inhoudVoorVingerafdruk !== false && isEigenScanScriptInhoud($inhoudVoorVingerafdruk)) {
                continue; // eigen scanscript van (mogelijk) een andere monitor-installatie
            }
        }

        $rootUnknown[] = [
            'type' => $isMap ? 'map' : 'bestand',
            'naam' => $volledigPad,
            'pad' => $volledigPad,
            'risico' => $isMap ? 30 : 50,
            'gewijzigd' => date('Y-m-d H:i', @filemtime($volledigPad) ?: time()),
            'grootte' => $isMap ? null : @filesize($volledigPad),
            'reden_override' => 'onbekend item in extra scanpad (' . $extraRoot . ')',
        ];

        $rechtenAfwijking = controleerBestandsrechten($volledigPad, $isMap);
        if ($rechtenAfwijking !== null) {
            $rootUnknown[] = $rechtenAfwijking;
        }
    }
}

// ============================================================================
// CHECK ROOT-LEVEL ONBEKENDE MAPPEN & BESTANDEN
// ============================================================================

function checkRootLevel($pad, &$rootUnknown, $vertrouwdeMappen, $ignoreerBestanden, $vertrouwdeBestanden, $naamPrefix = '')
{
    $items = @scandir($pad);
    if (!$items) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $volledigPad = $pad . '/' . $item;

        if (is_dir($volledigPad)) {
            if (in_array($item, $vertrouwdeMappen)) {
                continue;
            }

            // Een submap met zowel een eigen configuration.php als een eigen
            // administrator-map is zelf een complete, losstaande Joomla-
            // installatie (bijv. een oude staging-/testkopie die naast de
            // hoofdwebsite is blijven staan) - dat zijn twee kenmerken die
            // niet per ongeluk samen voorkomen, en niet iets dat een
            // geplaatste backdoor zelf zou aanmaken. In dat geval de
            // vertrouwde-Joomla-mappenlijst ook DAARBINNEN toepassen, in
            // plaats van de hele submap (en dus alles daarbinnen) in één
            // klap als "onbekend" te bestempelen.
            $isGenesteJoomlaInstallatie = is_file($volledigPad . '/configuration.php')
                && is_dir($volledigPad . '/administrator');

            if ($isGenesteJoomlaInstallatie) {
                $rootUnknown[] = [
                    'type' => 'map',
                    'naam' => $naamPrefix . $item,
                    'pad' => $volledigPad,
                    'risico' => 10,
                    'gewijzigd' => date('Y-m-d H:i', filemtime($volledigPad)),
                    'reden_override' => 'geneste, complete Joomla-installatie herkend (eigen configuration.php + administrator-map) - '
                        . 'wordt hieronder apart, als eigen website-root, verder doorzocht op dezelfde manier als de hoofdwebsite',
                ];
                checkRootLevel($volledigPad, $rootUnknown, $vertrouwdeMappen, $ignoreerBestanden, $vertrouwdeBestanden, $naamPrefix . $item . '/');
                continue;
            }

            // Root-level onbekende map
            $rootUnknown[] = [
                'type' => 'map',
                'naam' => $naamPrefix . $item,
                'pad' => $volledigPad,
                'risico' => 45,
                'gewijzigd' => date('Y-m-d H:i', filemtime($volledigPad)),
            ];
        } else {
            // Root-level bestand

            // Backup-/duplicaatversies van configuration.php lekken dezelfde
            // databasewachtwoorden en geheime sleutel als het echte bestand -
            // apart en met hoog risico melden, los van de generieke
            // "onbekend root-level item"-melding hieronder.
            $configBackupPatronen = [
                '/^configuration[._-]?bak\.php$/i',
                '/^configuration[._-]?old\.php$/i',
                '/^configuration\.php\.(bak|old|orig|save|swp|~)$/i',
                '/^config[._-]?backup\.php$/i',
                '/^configuration\d*\.php\.(txt|bak)$/i',
            ];
            $isConfigBackup = false;
            foreach ($configBackupPatronen as $patroon) {
                if (preg_match($patroon, $item)) {
                    $isConfigBackup = true;
                    break;
                }
            }
            if ($isConfigBackup) {
                $rootUnknown[] = [
                    'type' => 'bestand',
                    'naam' => $naamPrefix . $item,
                    'pad' => $volledigPad,
                    'risico' => 85,
                    'gewijzigd' => date('Y-m-d H:i', filemtime($volledigPad)),
                    'grootte' => filesize($volledigPad),
                    'reden_override' => 'BACKUP-/DUPLICAATCONFIGURATIEBESTAND - lekt dezelfde databasewachtwoorden en geheime sleutel als configuration.php. Verwijderen of veiligstellen buiten de website-root.',
                ];
                continue;
            }

            if (!in_array($item, $vertrouwdeBestanden) && !in_array($item, $ignoreerBestanden)
                && !isGoogleVerificatie($item) && !isMyJoomlaChecksum($item)) {
                // NFS-"silly rename"-tijdelijk bestand: ontstaat wanneer een
                // proces een bestand nog open heeft op het moment dat het
                // verwijderd/hernoemd wordt, op een NFS-bestandssysteem
                // (veel hostingpartijen gebruiken dit onder de motorkap voor
                // gedeelde/schaalbare serverclusters). Puur een artefact van
                // de onderliggende serverinfrastructuur, niets met de
                // website zelf te maken, en ruimt zichzelf normaal gesproken
                // op zodra het proces het bestand loslaat. Het hexadecimale
                // deel is elke keer uniek, dus op patroon herkennen in
                // plaats van als exacte naam op de uitsluitlijst te zetten.
                if (preg_match('/^\.nfs[0-9a-f]{20,}$/i', $item)) {
                    continue;
                }

                // Zelfde gedateerde-blacklist-logbestand-herkenning als in
                // checkExtraScanpadTopNiveau() - zie de toelichting daar.
                if (preg_match('/^blacklist\.[A-Za-z]{3}_\d{2}_\d{4}\.log$/i', $item)) {
                    continue;
                }

                // Zelfde php.ini-configuratiesnippet-herkenning (bijv.
                // "zzzzzzz-pecl.ini") als in checkExtraScanpadTopNiveau() -
                // zie de toelichting daar.
                if (preg_match('/^z{3,}-.*\.ini$/i', $item)) {
                    continue;
                }

                // Onzichtbare Unicode-tekens (zero-width/RTL-override) in de
                // bestandsnaam zijn een bekende truc om een kwaadaardig
                // bestand te verhullen als iets onschuldigs - altijd apart
                // en met hoog risico melden, los van de generieke "onbekend
                // bestand"-melding hieronder.
                if (bevatOnzichtbareUnicodeTekens($item)) {
                    $rootUnknown[] = [
                        'type' => 'bestand',
                        'naam' => $naamPrefix . $item,
                        'pad' => $volledigPad,
                        'risico' => 90,
                        'gewijzigd' => date('Y-m-d H:i', filemtime($volledigPad)),
                        'grootte' => filesize($volledigPad),
                        'reden_override' => 'bestandsnaam bevat onzichtbare Unicode-tekens (zero-width/RTL-override) - een bekende truc om een kwaadaardig bestand te verhullen als iets onschuldigs',
                    ];
                    continue;
                }

                // Herken ook - los van de bestandsnaam - een scanscript van
                // eenzelfde of een andere monitor-installatie voor deze site
                // (zie isEigenScanScriptInhoud() hierboven), zodat dit niet
                // telkens opnieuw als "onbekend" wordt gemeld en handmatig
                // vertrouwd moet worden na elke herupload/naamswijziging.
                $isEigenScanScript = (strtolower(substr($item, -4)) === '.php')
                    && ($inhoudVoorVingerafdruk = @file_get_contents($volledigPad, false, null, 0, 4096)) !== false
                    && isEigenScanScriptInhoud($inhoudVoorVingerafdruk);

                if ($isEigenScanScript) {
                    continue;
                }

                $isUitvoerbaar = (bool) preg_match('/\.(php\d?|phtml|phar|cgi|pl|sh)$/i', $item);
                $rootUnknown[] = [
                    'type' => 'bestand',
                    'naam' => $naamPrefix . $item,
                    'pad' => $volledigPad,
                    'risico' => $isUitvoerbaar ? 70 : 35,
                    'gewijzigd' => date('Y-m-d H:i', filemtime($volledigPad)),
                    'grootte' => filesize($volledigPad),
                ];
            }
        }
    }
}

// ============================================================================
// GEÏNSTALLEERDE EXTENSIES UITLEZEN (via de eigen database van de site)
// ============================================================================
//
// Leest configuration.php (staat toch al op de site) om de databasegegevens
// te pakken, en doet daarmee een query op de #__extensions-tabel. Dit geeft
// een volledige, 100% accurate lijst van alles wat geïnstalleerd is, met
// versie-informatie uit de manifest_cache-kolom (JSON). Geen wachtwoorden of
// aparte instellingen nodig - dit werkt met de gegevens die de site toch al
// zelf heeft.

// Hulpfunctie: haal een URL op (voor het ophalen van een update-feed).
function haalUrlEenvoudig($url, $timeoutSeconden = 8)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeoutSeconden,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_ENCODING       => '', // laat curl gzip/deflate/br automatisch decomprimeren
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2TLS, // moderne browsers gebruiken vrijwel altijd HTTP/2
        CURLOPT_COOKIEJAR      => '',
        CURLOPT_COOKIEFILE     => '',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: nl-NL,nl;q=0.9,en-US;q=0.8,en;q=0.7',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-User: ?1',
            'Upgrade-Insecure-Requests: 1',
            'Sec-Ch-Ua: "Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"',
            'Sec-Ch-Ua-Mobile: ?0',
            'Sec-Ch-Ua-Platform: "Windows"',
        ],
    ]);

    $inhoud   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Sommige servers (bijv. Balbooa's update-feeds) geven om onduidelijke
    // redenen een andere 2xx-status terug dan 200 (bijv. 202 Accepted),
    // terwijl de geldige inhoud gewoon al in de respons zit. Elke 2xx-status
    // accepteren we daarom als geslaagd, niet alleen precies 200.
    if ($inhoud === false || $httpCode < 200 || $httpCode >= 300) {
        return null;
    }

    return $inhoud;
}

// Hulpfunctie: haal meerdere URL's tegelijk op (curl_multi), voor de
// opruimronde verderop in dit bestand: als de gewone, sequentiële
// feed-ophaalronde het gedeelde tijdsbudget al heeft opgebruikt vóórdat
// alle geregistreerde update-feeds aan de beurt kwamen, worden de
// resterende feeds in één keer PARALLEL geprobeerd in plaats van (nooit
// meer) na elkaar. Op een structureel trage host - waar élk uitgaand
// verzoek meer tijd kost, niet specifiek één bepaalde feed - bepaalt de
// wachttijd van zo'n parallelle ronde dan de TRAAGSTE ENKELE feed, niet de
// opgetelde tijd van alle resterende feeds samen. Dat voorkomt dat
// steevast dezelfde (bijv. laat-alfabetische) extensies structureel als
// "Onbekend" blijven staan, puur door hun plek in de verwerkingsvolgorde.
//
// Zelfde curl-instellingen als haalUrlEenvoudig() hierboven (user-agent,
// headers, timeout-gedrag), zodat een feed zich hier niet anders gedraagt
// dan in de gewone, sequentiële ronde. Retourneert een array
// [url => inhoud|null], net als haalUrlEenvoudig() maar dan voor meerdere
// URL's ineens.
function haalUrlsParallelEenvoudig(array $urls, int $timeoutSeconden = 10): array
{
    $urls = array_values(array_unique($urls));
    $resultaat = array_fill_keys($urls, null);

    if (empty($urls)) {
        return $resultaat;
    }

    $multiHandle = curl_multi_init();
    $handles = [];

    foreach ($urls as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeoutSeconden,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2TLS,
            CURLOPT_COOKIEJAR      => '',
            CURLOPT_COOKIEFILE     => '',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: nl-NL,nl;q=0.9,en-US;q=0.8,en;q=0.7',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: none',
                'Sec-Fetch-User: ?1',
                'Upgrade-Insecure-Requests: 1',
                'Sec-Ch-Ua: "Chromium";v="124", "Google Chrome";v="124", "Not-A.Brand";v="99"',
                'Sec-Ch-Ua-Mobile: ?0',
                'Sec-Ch-Ua-Platform: "Windows"',
            ],
        ]);
        curl_multi_add_handle($multiHandle, $ch);
        $handles[$url] = $ch;
    }

    $actief = null;
    do {
        $status = curl_multi_exec($multiHandle, $actief);
        if ($actief) {
            curl_multi_select($multiHandle, 1.0);
        }
    } while ($actief && $status === CURLM_OK);

    foreach ($handles as $url => $ch) {
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $inhoud   = curl_multi_getcontent($ch);

        // Zelfde 2xx-tolerantie als haalUrlEenvoudig() hierboven (sommige
        // servers geven bijv. 202 Accepted terug met geldige inhoud).
        if ($inhoud !== false && $inhoud !== '' && $httpCode >= 200 && $httpCode < 300) {
            $resultaat[$url] = $inhoud;
        }

        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }

    curl_multi_close($multiHandle);

    return $resultaat;
}

// Hulpfunctie: bepaal de hoogste STABIELE versie (geen -dev/-alpha/-beta/-rc)
// uit een update-feed-XML, ongeacht of het een "extension"-bestand
// (<version>1.2.3</version>) of een "collectie"-bestand (version="1.2.3"
// als attribuut) is.
function haalHoogsteStabieleVersieUitXml($xmlInhoud, $huidigeVersie = null, $maxKernversie = null, $element = null)
{
    // $element (optioneel): het Joomla-element van de extensie zelf (bijv.
    // "pkg_nl-NL"). Sommige update-feeds zijn geen los bestand per
    // extensie, maar een GEDEELDE "collectie"-lijst met tientallen
    // <extension>-regels tegelijk (bijv. Joomla's eigen
    // translationlist_6.xml, met één regel per taal). Zonder dit element
    // zou hieronder domweg de hoogste versie uit de HELE lijst worden
    // gepakt - dus ook van een taal die niets met deze extensie te maken
    // heeft, als die toevallig een ander versienummer heeft. Is $element
    // bekend, dan wordt EERST geprobeerd de versie te vinden die bij
    // precies dát element hoort; alleen als dat niet lukt (bijv. bij een
    // gewoon, niet-gedeeld update-bestand met maar één <extension>), valt
    // dit terug op de oude "hoogste versie uit het hele bestand"-aanpak.
    if ($element !== null && $element !== '') {
        if (preg_match('/<extension\b[^>]*\belement="' . preg_quote($element, '/') . '"[^>]*>/i', $xmlInhoud, $tagMatch)) {
            if (preg_match('/\bversion="([0-9][0-9.]*(?:-[a-zA-Z0-9]+)?)"/', $tagMatch[0], $versionMatch)) {
                $gevondenVersie = $versionMatch[1];
                // Ook bij een direct gevonden element blijft de kernversie-
                // grens hieronder van toepassing (zie de toelichting
                // daarbij) - een vertaalteam kan ook voor het EIGEN element
                // vooruitlopen op een nog niet vrijgegeven kernversie.
                if ($maxKernversie === null || $maxKernversie === '') {
                    return $gevondenVersie;
                }
                $maxDeel = implode('.', array_slice(explode('.', $maxKernversie), 0, 3));
                $kandidaatDeel = implode('.', array_slice(explode('.', $gevondenVersie), 0, 3));
                if (version_compare($kandidaatDeel, $maxDeel, '<=')) {
                    return $gevondenVersie;
                }
                // Anders: doorvallen naar de gewone afhandeling hieronder,
                // die dezelfde grens ook op de rest van de lijst toepast.
            }
        }
    }

    $versies = [];

    if (preg_match_all('/version="([0-9][0-9.]*(?:-[a-zA-Z0-9]+)?)"/', $xmlInhoud, $m1)) {
        $versies = array_merge($versies, $m1[1]);
    }

    if (preg_match_all('/<version>\s*([^<\s][^<]*)\s*<\/version>/i', $xmlInhoud, $m2)) {
        $versies = array_merge($versies, $m2[1]);
    }

    $stabiel = array_values(array_filter($versies, function ($v) {
        return !preg_match('/-(dev|alpha|beta|rc)/i', $v);
    }));

    // Taalbestanden ontlenen hun versienummer rechtstreeks aan de Joomla-
    // kernversie (bijv. "6.1.3.1" hoort bij Joomla-kern 6.1.3), met een
    // eigen, extra vertaal-buildnummer als vierde onderdeel. Het
    // vertaalteam werkt soms vooruit op een Joomla-kernversie die zelf nog
    // niet is vrijgegeven - zonder deze check zou zo'n taalbestand dan ten
    // onrechte als "beschikbare update" worden aangemerkt, terwijl er voor
    // de bijbehorende Joomla-kernversie nog helemaal niets te installeren
    // valt. Kandidaten waarvan de eerste drie onderdelen (major.minor.patch)
    // hoger liggen dan de daadwerkelijk bekende, uitgebrachte kernversie
    // worden daarom hier weggefilterd, vóórdat er een keuze wordt gemaakt.
    if ($maxKernversie !== null && $maxKernversie !== '') {
        $maxOnderdelen = explode('.', $maxKernversie);
        $maxKernDeel = implode('.', array_slice($maxOnderdelen, 0, 3));

        $stabiel = array_values(array_filter($stabiel, function ($v) use ($maxKernDeel) {
            $kandidaatDeel = implode('.', array_slice(explode('.', $v), 0, 3));
            return version_compare($kandidaatDeel, $maxKernDeel, '<=');
        }));
    }

    if (empty($stabiel)) {
        return null;
    }

    usort($stabiel, function ($a, $b) {
        return version_compare($b, $a);
    });

    // Sommige extensies (met name taalbestanden, die versienummers direct
    // van de Joomla-kernversie overnemen) hebben een update-feed die
    // meerdere hoofdversies tegelijk vermeldt (bijv. zowel 5.x als 6.x,
    // sinds Joomla 6 bestaat). Zonder onderscheid zou dan de hoogste
    // hoofdversie worden gepakt, ook als de site nog op een oudere
    // hoofdversie draait - dat zou ten onrechte een major-upgrade als
    // gewone update voorspiegelen (zie ook groepeerVersiesPerMajor() in
    // versie_vergelijk_functies.php, dezelfde redenering voor Joomla-kern
    // zelf). Is de huidige versie bekend, dan krijgt de hoogste versie
    // BINNEN diezelfde hoofdversie voorrang; alleen als de feed daar
    // niets van bevat, valt dit terug op de hoogste versie totaal (het
    // oude gedrag) - zodat extensies met een normale, ongerelateerde
    // major-versienummering (bijv. een los component van 3.x naar 4.x)
    // gewoon hun update te zien blijven krijgen.
    if ($huidigeVersie !== null && $huidigeVersie !== '') {
        $huidigeMajor = strtok((string) $huidigeVersie, '.');
        if ($huidigeMajor !== false && $huidigeMajor !== '') {
            foreach ($stabiel as $v) {
                if (strtok($v, '.') === $huidigeMajor) {
                    return $v;
                }
            }
        }
    }

    return $stabiel[0];
}

// Let op: favicon-detectie gebeurt NIET meer hier (vanaf de site zelf),
// maar in check_sites.php op de monitor. Een site die zijn eigen publieke
// domeinnaam probeert te benaderen ("self-loopback") wordt door nogal wat
// hostingpartijen geblokkeerd/vertraagd, waardoor vrijwel alle sites op de
// terugval (het Joomla-icoon) uitkwamen - ook als ze prima een eigen
// favicon hadden. De monitor haalt sowieso al de homepage van elke site op
// (voor de website-statuscontrole), en hergebruikt die inhoud nu ook voor
// de favicon-herkenning - geen self-loopback-risico, en geen extra verzoek.

/**
 * Bepaalt de map(pen) op de schijf waar een extensie zijn bestanden heeft
 * staan, op basis van het type/element/folder/client zoals Joomla dat zelf
 * in de #__extensions-tabel bijhoudt. Geeft een lege array terug voor
 * types waar geen eenduidige eigen map bij hoort (bijv. "file"-registraties
 * of pakketten zelf - de ONDERDELEN van een pakket hebben wel gewoon hun
 * eigen mapstructuur, en worden dus via hun eigen rij meegenomen).
 */
/**
 * Bepaalt de geïnstalleerde Joomla-kernversie via libraries/src/Version.php
 * (aanwezig sinds Joomla 4). Wordt gebruikt om kernbestand-hashes per exacte
 * Joomla-versie te groeperen (zie hieronder) - los van, en sneller dan, de
 * aparte manifest-gebaseerde versiecontrole in haal_versies_op.php.
 */
function bepaalJoomlaKernVersieVoorHashing(string $startMap): ?string
{
    $versiePad = $startMap . '/libraries/src/Version.php';
    if (!is_readable($versiePad)) {
        return null;
    }

    $inhoud = @file_get_contents($versiePad);
    if ($inhoud === false) {
        return null;
    }

    if (preg_match('/MAJOR_VERSION\s*=\s*(\d+)/', $inhoud, $mMajor)
        && preg_match('/MINOR_VERSION\s*=\s*(\d+)/', $inhoud, $mMinor)
        && preg_match('/PATCH_VERSION\s*=\s*(\d+)/', $inhoud, $mPatch)
    ) {
        return $mMajor[1] . '.' . $mMinor[1] . '.' . $mPatch[1];
    }

    return null;
}

function bepaalExtensieMappen(array $rij): array
{
    $type = $rij['type'];
    $element = $rij['element'];
    $folder = $rij['folder'] ?? '';
    $isAdmin = ((int) ($rij['client_id'] ?? 0)) === 1;
    $adminPrefix = $isAdmin ? 'administrator/' : '';

    switch ($type) {
        case 'component':
            // Componenten hebben zowel een site- als een admin-map, met
            // dezelfde naam - client_id is bij componenten niet betrouwbaar
            // (staat vaak op 0), dus we nemen gewoon altijd beide mee.
            return [
                'components/' . $element,
                'administrator/components/' . $element,
            ];
        case 'module':
            return [$adminPrefix . 'modules/' . $element];
        case 'plugin':
            $folderNaam = $folder !== '' ? $folder : 'system';
            return ['plugins/' . $folderNaam . '/' . $element];
        case 'template':
            return [$adminPrefix . 'templates/' . $element];
        case 'library':
            return ['libraries/' . $element];
        default:
            return [];
    }
}

/**
 * Hasht (sha256) alle .php-bestanden binnen een map, recursief. Alleen
 * .php-bestanden - dat is waar een eventuele backdoor realistisch in zou
 * zitten, en het houdt de hoeveelheid data die naar de monitor gestuurd
 * moet worden behapbaar (afbeeldingen/taalbestanden/css/js tellen al gauw
 * op tot duizenden extra bestanden per extensie, zonder beveiligingswaarde).
 *
 * $maxBestanden is een harde grens per map, puur als veiligheidsklep tegen
 * extreem grote/ongebruikelijke mappen die de scan onnodig zouden vertragen.
 */
function hashPhpBestandenInMap(string $startMap, string $relatieveMap, int $maxBestanden = 500): array
{
    $volledigePad = rtrim($startMap, '/') . '/' . $relatieveMap;
    if (!is_dir($volledigePad)) {
        return [];
    }

    $hashes = [];

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($volledigePad, FilesystemIterator::SKIP_DOTS)
        );
    } catch (\Throwable $e) {
        return [];
    }

    foreach ($iterator as $bestand) {
        if (count($hashes) >= $maxBestanden) {
            break;
        }
        if (!$bestand->isFile()) {
            continue;
        }
        if (strtolower(substr($bestand->getFilename(), -4)) !== '.php') {
            continue;
        }

        $hash = @hash_file('sha256', $bestand->getPathname());
        if ($hash === false) {
            continue;
        }

        $relatiefPadBestand = $relatieveMap . '/' . substr($bestand->getPathname(), strlen($volledigePad) + 1);
        $relatiefPadBestand = str_replace('\\', '/', $relatiefPadBestand);

        $hashes[$relatiefPadBestand] = $hash;
    }

    return $hashes;
}

/**
 * Fallback voor als Joomla's manifest_cache geen (bruikbaar) versieveld
 * bevat: probeert het eigen XML-manifestbestand van de extensie rechtstreeks
 * op schijf te vinden en uit te lezen. Gebruikt dezelfde mapbepaling als de
 * bestandshash-vergelijking (bepaalExtensieMappen()), en zoekt daarbinnen
 * naar het meest voor de hand liggende manifestbestand op basis van de
 * gangbare Joomla-naamgevingsconventie (bijv. mod_naam/mod_naam.xml).
 */
function haalVersieUitEigenManifestbestand(string $startMap, array $rij): ?string
{
    $mappen = bepaalExtensieMappen($rij);
    if (empty($mappen)) {
        return null;
    }

    $element = strtolower(trim($rij['element'] ?? ''));
    $basisNaam = preg_replace('/^(com|mod|plg|pkg|lib|file)_/', '', $element);

    foreach ($mappen as $relatieveMap) {
        $volledigeMap = rtrim($startMap, '/') . '/' . $relatieveMap;
        if (!is_dir($volledigeMap)) {
            continue;
        }

        $kandidaatBestanden = [
            $element . '.xml',
            $basisNaam . '.xml',
            basename($relatieveMap) . '.xml',
        ];

        foreach (array_unique($kandidaatBestanden) as $bestandsnaam) {
            $pad = $volledigeMap . '/' . $bestandsnaam;
            if (!is_readable($pad)) {
                continue;
            }

            $inhoud = @file_get_contents($pad);
            if ($inhoud === false) {
                continue;
            }

            if (preg_match('/<version>\s*([^<]+?)\s*<\/version>/i', $inhoud, $m)) {
                $gevonden = trim($m[1]);
                if ($gevonden !== '') {
                    return $gevonden;
                }
            }
        }
    }

    return null;
}

/**
 * Haalt alle Super User-accounts op (naam, gebruikersnaam, e-mail,
 * aangemaakt, laatst ingelogd, geblokkeerd) - een compleet overzicht,
 * zodat een stiekem toegevoegde, onbekende beheerdersaccount opvalt bij
 * het doorlopen ervan, ook als die (nog) geen van de bekende
 * aanvallerspatronen gebruikt die scanRogueSuperUsers() hierboven al
 * automatisch herkent. Hergebruikt dezelfde databaseverbinding.
 */
function haalSuperUsers(?array $dbInfo): array
{
    if ($dbInfo === null) {
        return ['fout' => 'Geen databaseverbinding beschikbaar (zie hierboven bij de andere database-gebaseerde checks)'];
    }

    try {
        $pdo = $dbInfo['pdo'];
        $prefix = $dbInfo['prefix'];

        $stmt = $pdo->query("
            SELECT u.name, u.username, u.email, u.registerDate, u.lastvisitDate, u.block
            FROM `{$prefix}users` u
            INNER JOIN `{$prefix}user_usergroup_map` m ON m.user_id = u.id
            INNER JOIN `{$prefix}usergroups` g ON g.id = m.group_id
            WHERE g.title LIKE '%Super%'
            GROUP BY u.id
            ORDER BY u.registerDate DESC
        ");
        return ['beheerders' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    } catch (\Throwable $e) {
        return ['fout' => 'Kon de gebruikerstabellen niet uitlezen: ' . $e->getMessage()];
    }
}

function haalGeinstalleerdeExtensies(?array $dbInfo, string $startMap, int $scriptStartTijd)
{
    if ($dbInfo === null) {
        return ['fout' => 'Geen databaseverbinding beschikbaar (configuration.php niet gevonden/leesbaar, of verbinding mislukt)'];
    }

    $pdoSite = $dbInfo['pdo'];
    $dbPrefix = $dbInfo['prefix'];

    $tabel = $dbPrefix . 'extensions';

    try {
        // Het (Nederlandse) taalbestand krijgt bewust voorrang boven de
        // rest van de lijst - zie ORDER BY hieronder. Bij sites met heel
        // veel extensies kan de tijdslimiet verderop (zie
        // $maxSecondenTotaalBijScriptstart) er anders voor zorgen dat het
        // taalbestand nooit aan de beurt komt vóórdat de tijd op is,
        // waardoor de oude, mogelijk verouderde waarde onbedoeld blijft
        // staan - puur toeval welke extensies daar dan wel/niet de dupe
        // van worden. Door het taalbestand hier gegarandeerd als eerste te
        // verwerken, is dat specifieke, veelvoorkomende geval nooit meer
        // afhankelijk van willekeurige verwerkingsvolgorde.
        $stmt = $pdoSite->query("
            SELECT extension_id, name, type, element, folder, client_id, enabled, manifest_cache, package_id
            FROM `{$tabel}`
            ORDER BY (name LIKE '%language pack%' OR type = 'language') DESC, extension_id ASC
        ");
    } catch (PDOException $e) {
        return ['fout' => 'Kon de extensions-tabel niet uitlezen: ' . $e->getMessage()];
    }

    // ------------------------------------------------------------------
    // Joomla registreert voor elke extensie zelf al de officiële
    // update-locatie (dezelfde info die "Extensions > Update" gebruikt).
    // Die halen we op, zodat we NIET hoeven te gokken naar update-feeds:
    // #__update_sites_extensions koppelt extension_id aan een update-site,
    // #__update_sites bevat de daadwerkelijke feed-URL (location).
    //
    // Bewust GEEN filter op us.enabled: Joomla schakelt een update-locatie
    // zelf automatisch (en vaak onopgemerkt door de sitebeheerder) uit
    // zodra die ooit tijdelijk onbereikbaar was, om te voorkomen dat het
    // zelf een onbereikbare server blijft bestoken. Wij checken maar één
    // keer per scan (geen continu herhaald verkeer), dus die zorg geldt
    // voor ons niet - en is een feed-URL bij ons ook echt onbereikbaar,
    // dan valt dat vanzelf terug op een nette "MISLUKT"-melding hieronder,
    // in plaats van een stille, onzichtbare misser.
    // ------------------------------------------------------------------
    $updateFeedUrls = [];
    $updateSitesFout = null;
    try {
        $updateStmt = $pdoSite->query("
            SELECT use_ext.extension_id, us.location
            FROM `{$dbPrefix}update_sites_extensions` use_ext
            JOIN `{$dbPrefix}update_sites` us ON us.update_site_id = use_ext.update_site_id
        ");
        foreach ($updateStmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
            // Bij meerdere update-sites voor dezelfde extensie: de eerste aanhouden.
            if (!isset($updateFeedUrls[$rij['extension_id']])) {
                $updateFeedUrls[$rij['extension_id']] = $rij['location'];
            }
        }
    } catch (PDOException $e) {
        // Geen kritieke fout: als deze tabellen niet uitleesbaar zijn,
        // gaan we gewoon door zonder automatische versie-check.
        $updateSitesFout = $e->getMessage();
    }

    $diagnose = [
        'update_sites_fout'      => $updateSitesFout,
        'aantal_update_sites'    => count($updateFeedUrls),
        'aantal_derden'          => 0,
        'aantal_met_feed_url'    => 0,
        'aantal_feed_opgehaald'  => 0,
        'aantal_feed_mislukt'    => 0,
        'aantal_pakket_onderdeel' => 0,
        'feed_details'           => [],
    ];

    $extensies = [];
    $bestandHashesPerGroep = [];
    $totaalGehashteBestanden = 0;
    $maxTotaalBestanden = 6000; // veiligheidsklep: houdt de scan/payload behapbaar op sites met heel veel extensies
    $joomlaKernVersie = bepaalJoomlaKernVersieVoorHashing($startMap);

    // Rauwe Joomla-kernmappen die niet als extensie in #__extensions
    // geregistreerd staan (dus niet via bepaalExtensieMappen() gevonden
    // worden), maar wel nodig zijn voor de kernbestand-integriteits-
    // vergelijking tegen het officiële Joomla-pakket op de monitor (zie
    // kern_integriteit_functies.php). Eenmalig gehasht, niet per
    // extensierij - vandaar hier, vóór de hoofdlus.
    if ($joomlaKernVersie !== null && $totaalGehashteBestanden < $maxTotaalBestanden) {
        $groepSleutel = 'kern_joomla_' . str_replace('.', '_', $joomlaKernVersie);
        $raweKernmappen = ['libraries/src', 'includes', 'api', 'cli'];

        foreach ($raweKernmappen as $map) {
            $hashes = hashPhpBestandenInMap($startMap, $map);
            foreach ($hashes as $pad => $hash) {
                if ($totaalGehashteBestanden >= $maxTotaalBestanden) {
                    break 2;
                }
                $bestandHashesPerGroep[$groepSleutel][$pad] = $hash;
                $totaalGehashteBestanden++;
            }
        }

        // Losse rootbestanden - hashPhpBestandenInMap() werkt per map, dus
        // deze twee apart afhandelen.
        foreach (['index.php', 'administrator/index.php'] as $relBestand) {
            if ($totaalGehashteBestanden >= $maxTotaalBestanden) {
                break;
            }
            $volledigPad = $startMap . '/' . $relBestand;
            if (is_readable($volledigPad)) {
                $hash = @hash_file('sha256', $volledigPad);
                if ($hash !== false) {
                    $bestandHashesPerGroep[$groepSleutel][$relBestand] = $hash;
                    $totaalGehashteBestanden++;
                }
            }
        }
    }

    // Tijdsbudget voor het ophalen van update-feeds hieronder: bij veel
    // geïnstalleerde extensies (elk een eigen, los HTTP-verzoek) kan dit
    // behoorlijk oplopen, zeker bovenop de tijd die de backdoor-scan
    // hiervoor al heeft gebruikt. $scriptStartTijd (hierboven, vóór de
    // eigenlijke scan gezet) is het gezamenlijke referentiepunt voor de
    // hele scan - hier bewaken we dat er nog voldoende marge overblijft
    // tot de set_time_limit(120) bovenaan dit bestand, zodat er altijd nog
    // tijd is om het resultaat (ook zonder alle feeds) netjes te versturen.
    $maxSecondenTotaalBijScriptstart = 100;
    $tijdslimietBereikt = false;

    // Extensies waarvan de feed-check hieronder is uitgesteld omdat het
    // sequentiële tijdsbudget al op was - deze worden ná de hoofdlus in
    // één keer parallel geprobeerd (zie de opruimronde na deze lus, en de
    // toelichting bij haalUrlsParallelEenvoudig() hierboven).
    $uitgesteldeFeeds = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
        $versie = null;
        $auteur = null;

        if (!empty($rij['manifest_cache'])) {
            $manifest = json_decode($rij['manifest_cache'], true);
            if (is_array($manifest)) {
                if (!empty($manifest['version'])) {
                    $versie = (string) $manifest['version'];
                }
                if (!empty($manifest['author'])) {
                    $auteur = (string) $manifest['author'];
                }
            }
        }

        // Fallback: Joomla's manifest_cache-kolom is een momentopname die bij
        // installatie/update is vastgelegd - bij sommige extensies is die om
        // uiteenlopende redenen leeg of mist het versieveld, ook al draait de
        // extensie gewoon. In dat geval het eigen XML-manifestbestand van de
        // extensie zelf proberen te lezen (dat staat namelijk gewoon nog op
        // schijf, los van wat Joomla ooit heeft gecachet).
        if ($versie === null) {
            $versie = haalVersieUitEigenManifestbestand($startMap, $rij);
        }

        // Specifiek de officiële auteursnaam die Joomla-kernonderdelen zelf
        // gebruiken ("Joomla! Project"), NIET zomaar elk auteursveld dat
        // toevallig het woord "joomla" bevat - anders worden extensies van
        // bedrijven met "Joomla" in hun eigen merknaam (bijv. RSJoomla!,
        // JoomlaShack, Joomlashine) ten onrechte als kernonderdeel gezien,
        // en daardoor stilzwijgend overgeslagen bij de update-feed-check.
        $isKern = ($auteur !== null && stripos($auteur, 'joomla! project') !== false)
            || ($rij['type'] === 'file' && $rij['element'] === 'joomla');

        if ((int) ($rij['package_id'] ?? 0) > 0) {
            $diagnose['aantal_pakket_onderdeel']++;
        }

        // RSJoomla! (RSForm! Pro en vergelijkbare extensies van deze
        // ontwikkelaar) werkt bij een update het versienummer in de eigen
        // XML van de losse pakketonderdelen (plugins e.d.) niet bij - die
        // blijft voor altijd op de installatieversie staan (bijv. "1.0.0"),
        // ook als het pakket als geheel allang op een veel nieuwere versie
        // zit. Voor extensies die onderdeel zijn van een pakket (package_id
        // > 0) én van deze ontwikkelaar zijn, dus niet los op verouderd-
        // zijn controleren - alleen het pakket zelf (dat wél een correct
        // versienummer bijhoudt) telt dan mee.
        $isRsJoomlaPakketOnderdeel = (int) ($rij['package_id'] ?? 0) > 0
            && $auteur !== null && stripos($auteur, 'rsjoomla') !== false;

        // Alleen voor extensies van derden de nieuwste versie ophalen -
        // voor Joomla-kernonderdelen is dat niet relevant (die volgen we
        // apart via de Joomla-versie zelf).
        $nieuwsteVersie = null;
        $updateFeedUrl  = $updateFeedUrls[$rij['extension_id']] ?? null;

        // Taalbestanden ontlenen hun versienummer aan de Joomla-kernversie
        // zelf - zie de toelichting bij haalHoogsteStabieleVersieUitXml()
        // hierboven. Voor andere extensietypes is dit niet van toepassing
        // (die hebben een eigen, onafhankelijke versienummering). Joomla
        // registreert een taalbestand niet altijd als type 'language' -
        // vaak (zoals bij het Nederlandse taalbestand) als type 'package'
        // met "language pack" in de naam, dus op beide signalen
        // controleren.
        //
        // Als bovengrens gebruiken we de Joomla-KERNVERSIE VAN DEZE SITE
        // ZELF ($joomlaKernVersie, hierboven al rechtstreeks uit de
        // bestanden van de site gehaald) - niet een apart bijgehouden
        // "laatst bekende" lijst (die extra, foutgevoelige gegevensstroom
        // bleek in de praktijk niet betrouwbaar genoeg). Zolang een
        // taalbestand-update niet verder vooruitloopt dan de kernversie
        // die hier al daadwerkelijk draait, is er niets dat op een nog
        // niet vrijgegeven Joomla-versie zou kunnen wijzen.
        //
        // Bewust HIER al bepaald (i.p.v. pas ná een geslaagde feed-ophaal
        // zoals voorheen): de opruimronde ná deze lus heeft dit ook nodig
        // voor extensies die hieronder nog geen kans hebben gehad.
        $isTaalbestand = $rij['type'] === 'language'
            || stripos((string) $rij['name'], 'language pack') !== false;
        $maxKernversieVoorDitItem = $isTaalbestand ? $joomlaKernVersie : null;

        if (!$isKern && !$isRsJoomlaPakketOnderdeel) {
            $diagnose['aantal_derden']++;

            if ($updateFeedUrl && !$tijdslimietBereikt && (time() - $scriptStartTijd) > $maxSecondenTotaalBijScriptstart) {
                // Tijdslimiet voor de SEQUENTIËLE ronde bereikt: geen nieuwe
                // feeds meer één-voor-één ophalen. In plaats van deze en
                // alle volgende extensies definitief op "onbekend" te laten
                // staan (zoals voorheen), belanden ze hieronder in de
                // wachtrij voor de parallelle opruimronde ná deze lus - op
                // een trage host is vaak nog wél tijd voor één gezamenlijke
                // parallelle poging, ook al is er geen tijd meer voor nog
                // meer sequentiële, na-elkaar-uitgevoerde verzoeken.
                $tijdslimietBereikt = true;
            }

            if ($updateFeedUrl && !$tijdslimietBereikt) {
                $diagnose['aantal_met_feed_url']++;

                $feedInhoud = haalUrlEenvoudig($updateFeedUrl);
                if ($feedInhoud !== null) {
                    $nieuwsteVersie = haalHoogsteStabieleVersieUitXml($feedInhoud, $versie, $maxKernversieVoorDitItem, $rij['element'] ?? null);
                }

                if ($nieuwsteVersie !== null) {
                    $diagnose['aantal_feed_opgehaald']++;
                    $grensToelichting = $isTaalbestand
                        ? (' [taalbestand, kernversiegrens (van deze site): ' . ($maxKernversieVoorDitItem ?? 'ONBEKEND - geen grens toegepast') . ']')
                        : '';
                    $diagnose['feed_details'][] = "OK: {$rij['name']} => $nieuwsteVersie (feed: $updateFeedUrl)" . $grensToelichting;
                } else {
                    $diagnose['aantal_feed_mislukt']++;
                    if ($feedInhoud === null) {
                        $reden = 'kon niet opgehaald worden (netwerk-/HTTP-fout)';
                    } else {
                        $fragment = substr(preg_replace('/\s+/', ' ', trim($feedInhoud)), 0, 300);
                        $reden = "opgehaald, maar geen versienummer gevonden - eerste 300 tekens: \"$fragment\"";
                    }
                    $diagnose['feed_details'][] = "MISLUKT: {$rij['name']} - $reden (feed: $updateFeedUrl)";
                }
            } elseif ($updateFeedUrl && $tijdslimietBereikt) {
                $diagnose['aantal_met_feed_url']++;

                // In de wachtrij voor de opruimronde. 'index' is de positie
                // waarop dit onderdeel hieronder in $extensies terechtkomt
                // (exact één push per lus-iteratie, zie het einde van deze
                // foreach) - zo kan de opruimronde ná de lus het resultaat
                // alsnog op de juiste plek invullen zonder de hele lijst
                // opnieuw te hoeven doorzoeken.
                $uitgesteldeFeeds[] = [
                    'index'                    => count($extensies),
                    'naam'                     => $rij['name'],
                    'url'                      => $updateFeedUrl,
                    'huidige_versie'           => $versie,
                    'element'                  => $rij['element'] ?? null,
                    'is_taalbestand'           => $isTaalbestand,
                    'max_kernversie_voor_item' => $maxKernversieVoorDitItem,
                ];
            }

            // Bestandshashes verzamelen (alleen voor extensies van derden -
            // de Joomla-kern zelf wordt hieronder apart, maar op dezelfde
            // manier, afgedekt). Alleen zinvol als er ook een versienummer
            // bekend is: de vergelijking tussen sites gebeurt namelijk per
            // exact dezelfde extensie+versie, dus zonder versienummer is
            // niets te vergelijken.
            if ($versie !== null && $totaalGehashteBestanden < $maxTotaalBestanden && !$tijdslimietBereikt) {
                if ((time() - $scriptStartTijd) > $maxSecondenTotaalBijScriptstart) {
                    $tijdslimietBereikt = true;
                }
            }

            if ($versie !== null && $totaalGehashteBestanden < $maxTotaalBestanden && !$tijdslimietBereikt) {
                $mappen = bepaalExtensieMappen($rij);
                if (!empty($mappen)) {
                    $groepSleutel = strtolower($rij['type'] . '_' . $rij['element'] . '_' . $versie);

                    foreach ($mappen as $map) {
                        $hashes = hashPhpBestandenInMap($startMap, $map);
                        foreach ($hashes as $pad => $hash) {
                            if ($totaalGehashteBestanden >= $maxTotaalBestanden) {
                                break 2;
                            }
                            $bestandHashesPerGroep[$groepSleutel][$pad] = $hash;
                            $totaalGehashteBestanden++;
                        }
                    }
                }
            }
        } elseif ($joomlaKernVersie !== null && $totaalGehashteBestanden < $maxTotaalBestanden) {
            // Joomla-kernonderdeel: net als bij extensies van derden hashen,
            // maar gegroepeerd op de Joomla-kernversie zelf (niet op een los
            // versienummer per onderdeel - kernonderdelen horen altijd
            // allemaal bij dezelfde Joomla-release) - zo werkt dezelfde
            // meerderheids-vergelijking tussen sites ook voor de Joomla-kern
            // zelf, niet alleen voor extensies van derden.
            $mappen = bepaalExtensieMappen($rij);
            if (!empty($mappen)) {
                $groepSleutel = 'kern_joomla_' . str_replace('.', '_', $joomlaKernVersie);

                foreach ($mappen as $map) {
                    $hashes = hashPhpBestandenInMap($startMap, $map);
                    foreach ($hashes as $pad => $hash) {
                        if ($totaalGehashteBestanden >= $maxTotaalBestanden) {
                            break 2;
                        }
                        $bestandHashesPerGroep[$groepSleutel][$pad] = $hash;
                        $totaalGehashteBestanden++;
                    }
                }
            }
        }

        $extensies[] = [
            'extension_id'     => (int) $rij['extension_id'],
            'naam'             => $rij['name'],
            'type'             => $rij['type'],
            'element'          => $rij['element'],
            'folder'           => $rij['folder'],
            'client'           => ((int) $rij['client_id'] === 1) ? 'administrator' : 'site',
            'enabled'          => (int) $rij['enabled'] === 1,
            'versie'           => $versie,
            'auteur'           => $auteur,
            'update_feed_url'  => $updateFeedUrl,
            'nieuwste_versie'  => $nieuwsteVersie,
            'package_id'       => (int) ($rij['package_id'] ?? 0),
        ];
    }

    // Opruimronde: alle extensies die hierboven in de wachtrij zijn beland
    // omdat het sequentiële tijdsbudget al op was, in één keer PARALLEL
    // proberen (zie haalUrlsParallelEenvoudig() hierboven) - mits er nog
    // voldoende marge over is tot de set_time_limit(120) bovenaan dit
    // bestand. 8 seconden marge gereserveerd voor de rest van het script
    // (o.a. het versturen van het resultaat naar de monitor); minimaal 5
    // seconden nodig om de moeite waard te zijn, anders gewoon overslaan.
    $secondenOverVoorOpruimronde = 120 - (time() - $scriptStartTijd) - 8;
    $opruimResultaten = [];

    if (!empty($uitgesteldeFeeds) && $secondenOverVoorOpruimronde >= 5) {
        $opruimTimeout = (int) min(12, max(5, $secondenOverVoorOpruimronde));
        $opruimUrls = array_column($uitgesteldeFeeds, 'url');
        $opruimResultaten = haalUrlsParallelEenvoudig($opruimUrls, $opruimTimeout);
    }

    foreach ($uitgesteldeFeeds as $item) {
        $feedInhoud = $opruimResultaten[$item['url']] ?? null;
        $nieuwsteVersieOpgeruimd = null;

        if ($feedInhoud !== null) {
            $nieuwsteVersieOpgeruimd = haalHoogsteStabieleVersieUitXml(
                $feedInhoud,
                $item['huidige_versie'],
                $item['max_kernversie_voor_item'],
                $item['element']
            );
        }

        if ($nieuwsteVersieOpgeruimd !== null) {
            $extensies[$item['index']]['nieuwste_versie'] = $nieuwsteVersieOpgeruimd;
            $diagnose['aantal_feed_opgehaald']++;
            $grensToelichting = $item['is_taalbestand']
                ? (' [taalbestand, kernversiegrens (van deze site): ' . ($item['max_kernversie_voor_item'] ?? 'ONBEKEND - geen grens toegepast') . ']')
                : '';
            $diagnose['feed_details'][] = "OK (opruimronde): {$item['naam']} => $nieuwsteVersieOpgeruimd (feed: {$item['url']})" . $grensToelichting;
        } else {
            $diagnose['aantal_feed_mislukt']++;
            $reden = empty($opruimResultaten)
                ? 'onvoldoende tijd meer over voor de parallelle opruimronde'
                : 'ook in de parallelle opruimronde niet gelukt (netwerk-/HTTP-fout of geen versienummer gevonden)';
            $diagnose['feed_details'][] = "MISLUKT (opruimronde): {$item['naam']} - $reden (feed: {$item['url']})";
        }
    }

    return [
        'extensies' => $extensies,
        'diagnose' => $diagnose,
        'bestand_hashes' => $bestandHashesPerGroep,
    ];
}



// ============================================================================
// ZELF-BIJWERKEN - VÓÓR de eigenlijke scan
// ============================================================================
// Bewust vóór de scan (in plaats van erna, zoals voorheen): zo gebruikt een
// scan die een update tegenkomt de nieuwe code ook meteen voor zichzelf, in
// plaats van pas bij de eerstvolgende scan. PHP kan zijn eigen, al ingelezen
// functies echter niet "heropladen" binnen hetzelfde proces - daarom wordt,
// uitsluitend wanneer er daadwerkelijk een update is weggeschreven, dit
// scanscript hierna één keer helemaal opnieuw aangeroepen als verse, losse
// HTTP-aanvraag (nu dus wél met de nieuwe code), en wordt de uitvoer daarvan
// simpelweg doorgegeven - dat voelt voor de monitor (en voor jou) gewoon als
// één enkele, doorlopende scan aan.
//
// Staat bewust ná het beheersysteem hierboven (Bekijk/Quarantaine/Blokkeer/
// Verwijder): die acties verwachten een schone JSON-respons en eindigen
// altijd zelf met exit(), dus dit codepad wordt daarbij nooit bereikt.
echo "=== ZELF-BIJWERKEN ===\n";

// Wordt uitsluitend door dit script zelf gezet (zie hieronder), bij zo'n
// herhaalde aanroep ná een update - voorkomt een oneindige lus in het (zeer
// onwaarschijnlijke) geval dat de hash-vergelijking na het bijwerken
// onverhoopt toch weer "verschillend" blijft aangeven.
$ditIsEenZelfherhaling = ($_SERVER['HTTP_X_MONITOR_ZELF_HERHALING'] ?? '') === '1';

$scriptMapVoorLog = trim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$domein = ($_SERVER['HTTP_HOST'] ?? 'onbekend') . ($scriptMapVoorLog !== '' ? '/' . $scriptMapVoorLog : '');

if ($ditIsEenZelfherhaling) {
    echo "✓ Draait al met de zojuist bijgewerkte versie.\n";
} else {
    $zelfBijgewerkt = probeerZelfBijTeWerken($domein);

    if ($zelfBijgewerkt) {
        $herhaalUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ('/' . basename(__FILE__)));

        $herhaalOpties = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            // Net zo belangrijk als CURLOPT_FOLLOWLOCATION zelf: zonder deze
            // optie zet curl een POST-verzoek bij een omleiding (bijv. een
            // www/non-www- of http/https-omleiding op deze site) standaard
            // stilzwijgend om in een kale GET - waardoor de POST-gegevens die
            // hieronder juist bewust worden doorgegeven, alsnog verloren
            // zouden gaan. Waarde 7 = blijft een POST bij alle drie de
            // omleidingscodes waarbij dat kan spelen (301, 302 en 303).
            CURLOPT_POSTREDIR => 7,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => ['X-Monitor-Zelf-Herhaling: 1'],
        ];

        // Was het oorspronkelijke verzoek een POST (bv. een beheeractie
        // zoals quarantaine/verwijder, verstuurd door de monitor
        // via site_beheer_actie.php)? Dan die POST-velden hier ONGEWIJZIGD
        // meesturen naar de herhaalde aanroep. Zonder dit werd een POST-
        // verzoek stilzwijgend een kale GET, waardoor - specifiek wanneer
        // het NOG NIET bijgewerkte scanscript de meegestuurde actie nog
        // niet kende - de herhaalde aanroep na de zelf-update geen JSON-
        // antwoord op de bedoelde actie teruggaf, maar in plaats daarvan
        // gewoon een volledige, normale scan uitvoerde (en dús geen JSON,
        // met een verwarrende foutmelding in de monitor tot gevolg). Bij
        // elke nieuwe beheeractie die ooit wordt toegevoegd, zou de eerste
        // aanroep tegen een nog verouderd scanscript anders steeds op
        // dezelfde manier mislukken.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($_POST)) {
            $herhaalOpties[CURLOPT_POST] = true;
            $herhaalOpties[CURLOPT_POSTFIELDS] = http_build_query($_POST);
        }

        $ch = curl_init($herhaalUrl);
        curl_setopt_array($ch, $herhaalOpties);
        $herhaalAntwoord = curl_exec($ch);
        $herhaalFout = curl_error($ch);
        curl_close($ch);

        if ($herhaalAntwoord !== false && $herhaalAntwoord !== '') {
            echo $herhaalAntwoord;
        } else {
            echo "⚠️  Kon na het bijwerken niet meteen opnieuw scannen met de nieuwe versie ({$herhaalFout}) - de eerstvolgende scan gebruikt hem sowieso.\n";
        }
        exit;
    }
}

echo "=== JOOMLA BACKDOOR-SCAN (v10) ===\n";
echo "Domein: " . $domein . "\n";
echo "Start: " . date('Y-m-d H:i:s') . "\n";

// Vóór het eigenlijke scannen begint: de tmp-map alvast automatisch legen.
// Deze map is vaak juist de plek waar kortstondige, verdachte bestanden
// terechtkomen - door hem stelselmatig bij elke scan al leeg te maken
// blijft hij schoon zonder dat daar een handmatige actie voor nodig is.
// Niet fataal bij mislukken (bijv. een rechtenprobleem): de rest van de
// scan gaat gewoon door, alleen deze regel meldt het resultaat.
$tmpLegingResultaat = legeTmpMapAutomatisch($startMap);
if ($tmpLegingResultaat['succes']) {
    echo "tmp-map geleegd: {$tmpLegingResultaat['aantal_verwijderd']} item(en) verwijderd";
    if ($tmpLegingResultaat['aantal_mislukt'] > 0) {
        echo ", {$tmpLegingResultaat['aantal_mislukt']} mislukt (mogelijk een rechtenprobleem)";
    }
    echo ".\n";
} else {
    echo "tmp-map legen overgeslagen: {$tmpLegingResultaat['foutmelding']}\n";
}

echo "Scanning...\n\n";

$startTime = time();

// Scan root level
checkRootLevel($startMap, $rootLevelUnknown, $vertrouwdeRootMappen, $ignoreerBestanden, $vertrouwdeRootBestanden);

// Scan alles voor backdoors + verdachte .htaccess-bestanden
scanRecursief($startMap, $backdoorVondsten, $htaccessVondsten, $mogelijkLegitiem, $ignoreerBestanden, $startMap);

// ----------------------------------------------------------------------
// Cloaking-detectie op de twee Joomla-kernbestanden (index.php en
// administrator/index.php) - zie detecteerCloakingInKernbestand()
// hierboven voor de uitleg. Deze twee bestanden zijn zo core-Joomla dat ze
// nooit zelf user-agents zouden moeten checken of externe inhoud ophalen;
// scanRecursief() hierboven scant deze bestanden weliswaar ook al op
// backdoor-patronen, maar deze combinatie-check is een aanvullend,
// specifiek signaal dat daar los van staat.
// ----------------------------------------------------------------------
foreach (['index.php', 'administrator/index.php'] as $kernbestandRelatief) {
    $kernbestandPad = $startMap . '/' . $kernbestandRelatief;
    if (!is_file($kernbestandPad) || !is_readable($kernbestandPad)) {
        continue;
    }
    $kernbestandInhoud = @file_get_contents($kernbestandPad);
    if ($kernbestandInhoud === false) {
        continue;
    }
    $cloakingMelding = detecteerCloakingInKernbestand($kernbestandInhoud);
    if ($cloakingMelding !== null) {
        $rootLevelUnknown[] = [
            'type' => 'bestand',
            'naam' => $kernbestandRelatief,
            'pad' => $kernbestandPad,
            'risico' => 95,
            'gewijzigd' => date('Y-m-d H:i', @filemtime($kernbestandPad) ?: time()),
            'grootte' => @filesize($kernbestandPad),
            'reden_override' => $cloakingMelding,
        ];
    }
}

// Massaal-hernoemen-detectie - zie vindMassaleHernoeming() hierboven.
vindMassaleHernoeming($startMap, $rootLevelUnknown);

// Massale-upload-detectie (verschillende namen/extensies, identieke
// bestandsgrootte) - zie vindMassaleUpload() hierboven.
vindMassaleUpload($startMap, $rootLevelUnknown);

// ─────────────────────────────────────────────────────────────────────────────
// Optioneel: een extra map BUITEN de website-root meescannen (bijv. een
// losse back-upmap die naast public_html staat - let op: tmp/logs zitten
// al gewoon binnen de root en worden dus al door de gewone scan gedekt).
// Alleen actief als dit per site is ingesteld (zie site-instellingen op de monitor).
// Wordt bewust niet automatisch geactiveerd - vereist een expliciet
// ingesteld pad. Als de hostingpartij open_basedir gebruikt, wordt dat
// hieronder netjes opgevangen (geen crash, gewoon een duidelijke melding).
// ─────────────────────────────────────────────────────────────────────────────
// Wordt ALTIJD overgeslagen binnen het extra scanpad, ongeacht wat de
// gebruiker zelf heeft ingevuld - dit zijn herkenbare, standaard mappen/
// bestanden van de hostingpartij zelf (cPanel/CloudLinux/DirectAdmin e.d.),
// nooit onderdeel van de website, en zonder deze lijst zou automatisch
// meescannen tot aan de accountroot een onbegrijpelijke berg meldingen
// opleveren voordat ze ooit bij het uitsluitveld uitkomen.
$standaardExtraScanpadNegeren = [
    // BEWUST NIET in deze lijst: .cagefs en .cl.selector. Dat zijn weliswaar
    // ook standaard CloudLinux-systeemmappen, maar er is in de praktijk
    // (forumcase gipje/Vimexx, augustus 2026) een echte backdoor
    // aangetroffen in .cl.selector/filefuns.php en verdachte bestanden in
    // .cagefs/tmp - precies de aanleiding om deze "boven de root scannen"-
    // functie te bouwen. Deze mappen zijn vaak wereld-schrijfbaar en worden
    // zelden gecontroleerd, dus juist een populaire plek voor aanvallers om
    // een webshell te dumpen. Ze dus toch overslaan zou de functie voor
    // exact dat scenario nutteloos maken - ze worden daarom gewoon normaal
    // meegescand op verdachte inhoud.
    '.php', '.pki', '.ssh', '.cpanel',
    '.imunify_patch_id', '.myimunify_id', 'Maildir', 'imap', 'etc',
    'tmp', 'logs', 'ssl', 'domains',
    // Bij Vimexx (en vergelijkbare hostingpartijen) staat het hoofddomein
    // van het account rechtstreeks in "public_html" op de accountroot,
    // terwijl overige (addon-)domeinen in "domains/" terechtkomen - een
    // eigen, complete website dus, net als de sites binnen "domains"
    // hierboven, en om dezelfde reden hier uitgesloten: die wordt (voor
    // zover gemonitord) al apart en volledig via zijn eigen monitor-item
    // gescand.
    'public_html',
    // Standaard shell-/accountbestanden die letterlijk elk Linux-account
    // krijgt (uit /etc/skel) - geen onderdeel van de website, nooit een
    // aanwijzing voor iets verdachts.
    '.bash_logout', '.bash_profile', '.bashrc', '.bash_history', '.viminfo', '.lesshst',
    // Virtueel, per-account "nep"-bestand dat CloudLinux's CageFS aanmaakt -
    // niet het echte systeembrede /etc/shadow. (CageFS zelf, .cagefs, wordt
    // bewust NIET overgeslagen - zie de toelichting hieronder.)
    '.shadow',
    // Standaard cPanel-hostingtools: Softaculous (1-klik-installer),
    // SpamAssassin (spamfilter, komt standaard mee met cPanel-e-mail) en
    // CloudLinux's eigen WordPress-optimalisatie/monitoringmodule - alle
    // drie onderdeel van de serverlaag/het hostingaccount, nooit van de
    // website zelf.
    '.softaculous', '.spamassassin', '.clwpos',
    // Softaculous SitePad (de website-bouwer van dezelfde partij als
    // Softaculous hierboven) bewaart versies/back-ups van sitebouwer-data
    // in .appdata; automatische, periodieke hostingback-ups staan vaak in
    // een herkenbaar benoemde map als application_backups. Beide puur
    // hostingtool-gegenereerd, geen onderdeel van de website zelf.
    '.appdata', 'application_backups',
    // E-mailinfrastructuur van het hostingaccount: Dovecot's "mdbox"-
    // mailopslag (een alternatief voor het bekendere Maildir-formaat, dat
    // al hierboven staat) en Pyzor (spamherkenning, vergelijkbaar met
    // .spamassassin/.razor) - geen van beide onderdeel van de website.
    'mdbox', '.pyzor',
    // Accountbrede prullenbak van de bestandsbeheerder van de
    // hostingpartij (vergelijkbaar bij meerdere partijen aangetroffen).
    '.trash',
    // Standaard webmap voor domeinvalidatie (bijv. Let's Encrypt/ACME) -
    // hoort bij vrijwel elke moderne website, niet Joomla-specifiek en
    // geen onderdeel van de website-inhoud zelf.
    '.well-known',
    // Standaard uitvoermap van de Akeeba Backup-extensie (com_akeebabackup)
    // voor back-uparchieven (.jpa) en bijbehorende logbestanden.
    'akeeba-backup',
    // Algemene, veelvoorkomende back-upmapnamen (los van een specifieke
    // extensie/tool).
    'backups', 'softaculous_backups',
    // Standaardlocatie van de VirtueMart-webshopextensie voor eigen
    // bestanden buiten de website-root.
    'vmfiles',
    // Firefox-profielmap - ontstaat door tools die zelf een Firefox-
    // achtige weergave-engine gebruiken (bijv. voor schermafbeeldingen of
    // PDF-generatie op de server), geen onderdeel van de website.
    '.mozilla',
    // Vaste, terugkerende companion-mapnamen bij een "/data/www/domeinnaam/"-
    // hostingstructuur (een alternatief voor de gebruikelijke
    // "/home/gebruiker/"-indeling): back-up, niet-publiek toegankelijke
    // bestanden, statistieken (bijv. AWStats) en tijdelijke bestanden.
    'bu', 'private', 'statsdata', 'temp',
    // Vermoedelijk gerelateerd aan Softaculous SitePad (zie ook .appdata
    // hierboven) - een losse cachebestandsnaam van dezelfde tool.
    '.spbldr_localStorage',
];
$extraScanPadNegerenRuw = '__EXTRA_SCAN_PAD_NEGEREN__';
$extraScanPadNegerenEigen = array_filter(array_map('trim', explode(',', $extraScanPadNegerenRuw)));
$extraScanPadNegeren = array_values(array_unique(array_merge($standaardExtraScanpadNegeren, $extraScanPadNegerenEigen)));
$extraScanMelding = null;

// $extraScanRootAbsoluut, $extraScanNiveauGebruikt en $extraScanIngeschakeld
// zijn al helemaal boven in dit bestand berekend (vóór de beheeracties),
// via automatische detectie op basis van eigenaarschap - hier gewoon
// hergebruiken, geen tweede keer detecteren.
if (!$extraScanIngeschakeld) {
    $extraScanMelding = null; // uitgeschakeld voor deze site - niets te melden
} elseif ($extraScanRootAbsoluut === null) {
    $extraScanMelding = 'Automatische detectie vond geen bruikbare map boven de website-root (de bovenliggende map hoort al niet meer bij dit hostingaccount, of is niet leesbaar).';
} else {
    // De submap van de website zelf (bijv. "public_html") altijd uitsluiten
    // van het extra scanpad: die wordt namelijk al helemaal apart, volledig
    // en met de eigen, uitgebreide backdoor-detectie gescand via de
    // hoofdscan hierboven. Zonder deze uitsluiting zou dezelfde website-
    // inhoud een tweede keer (en minder grondig - alleen backdoor-/
    // rechtencontrole, geen bestandsvergelijking e.d.) worden meegescand
    // via dit extra scanpad, met als zichtbaar gevolg dat de website-eigen
    // map zelf als "onbekend item" werd gerapporteerd.
    $eigenSubmapNaam = null;
    if (strpos($startMap, $extraScanRootAbsoluut . DIRECTORY_SEPARATOR) === 0) {
        $relatiefPad = ltrim(substr($startMap, strlen($extraScanRootAbsoluut)), '/');
        $eigenSubmapNaam = explode('/', $relatiefPad)[0] ?? null;
    }
    if ($eigenSubmapNaam !== null && $eigenSubmapNaam !== '') {
        $extraScanPadNegeren[] = $eigenSubmapNaam;
        $extraScanPadNegeren = array_values(array_unique($extraScanPadNegeren));
    }

    // Ook ANDERE, volledig losstaande Joomla-installaties op het topniveau
    // van het extra scanpad herkennen (bijv. bij een gedeeld hostingaccount
    // met meerdere sites direct naast elkaar in dezelfde accountroot, zoals
    // bij Strato) - dezelfde signatuur (eigen configuration.php + eigen
    // administrator-map) als bij de geneste-Joomla-herkenning in
    // checkRootLevel() hierboven, maar dan hier toegepast op het extra
    // scanpad zelf, zodat zo'n andere site niet in zijn geheel (elk
    // bestand apart) als "onbekend"/"afwijkende rechten" wordt gemeld.
    $anderJoomlaMappen = [];
    $topNiveauItems = @scandir($extraScanRootAbsoluut);
    if ($topNiveauItems) {
        foreach ($topNiveauItems as $topItem) {
            if ($topItem === '.' || $topItem === '..' || in_array($topItem, $extraScanPadNegeren, true)) {
                continue;
            }
            $topItemPad = $extraScanRootAbsoluut . '/' . $topItem;
            if (is_dir($topItemPad) && is_file($topItemPad . '/configuration.php') && is_dir($topItemPad . '/administrator')) {
                $anderJoomlaMappen[] = $topItem;
                $rootLevelUnknown[] = [
                    'type' => 'map',
                    'naam' => $topItem,
                    'pad' => $topItemPad,
                    'risico' => 10,
                    'gewijzigd' => date('Y-m-d H:i', @filemtime($topItemPad) ?: time()),
                    'reden_override' => 'andere, volledig losstaande Joomla-installatie herkend in het extra scanpad '
                        . '(eigen configuration.php + administrator-map) - wordt daarom niet in zijn geheel als onbekend gemeld',
                ];
            }
        }
    }
    if (!empty($anderJoomlaMappen)) {
        $extraScanPadNegeren = array_values(array_unique(array_merge($extraScanPadNegeren, $anderJoomlaMappen)));
    }

    // De extra-scanpad-root ZELF kan er ook als een Joomla-installatie
    // uitzien (i.p.v. dat zo'n andere site netjes in een eigen submap
    // staat) - bijv. bij Strato, waar meerdere sites op hetzelfde account
    // hun bestanden los naast elkaar in dezelfde accountroot hebben staan.
    // In dat geval zijn "administrator", "components", "modules" e.d. op
    // dit niveau helemaal geen onbekende mappen, maar gewoon de normale
    // Joomla-kernstructuur van die andere site - dezelfde vertrouwde-
    // mappenlijst gebruiken als bij de website die we aan het scannen zijn
    // (welke exact daarvan is, is op dit niveau niet meer te herleiden -
    // maar dat hoeft ook niet, aangezien die andere site zelf, voor zover
    // gemonitord, sowieso apart en volledig gescand wordt).
    if (is_file($extraScanRootAbsoluut . '/configuration.php') && is_dir($extraScanRootAbsoluut . '/administrator')) {
        $extraScanPadNegeren = array_values(array_unique(array_merge($extraScanPadNegeren, $vertrouwdeRootMappen, $vertrouwdeRootBestanden)));
        $rootLevelUnknown[] = [
            'type' => 'map',
            'naam' => '(accountroot zelf)',
            'pad' => $extraScanRootAbsoluut,
            'risico' => 10,
            'gewijzigd' => date('Y-m-d H:i', @filemtime($extraScanRootAbsoluut) ?: time()),
            'reden_override' => 'de accountroot zelf blijkt (ook) een complete Joomla-installatie te zijn van een andere site op '
                . 'hetzelfde hostingaccount (eigen configuration.php + administrator-map, los naast deze website) - de standaard '
                . 'Joomla-kernmappen én -rootbestanden op dit niveau worden daarom niet als onbekend gemeld',
        ];
    }

    $aantalVoorExtraScan = count($backdoorVondsten) + count($htaccessVondsten);

    checkExtraScanpadTopNiveau($extraScanRootAbsoluut, $rootLevelUnknown, $extraScanPadNegeren, $startMap);

    $rechtenAfwijkingenExtra = [];
    scanRecursief($extraScanRootAbsoluut, $backdoorVondsten, $htaccessVondsten, $mogelijkLegitiem, $ignoreerBestanden, $startMap, 0, $extraScanPadNegeren, $rechtenAfwijkingenExtra);
    foreach ($rechtenAfwijkingenExtra as $afwijking) {
        $rootLevelUnknown[] = $afwijking;
    }

    $aantalNaExtraScan = count($backdoorVondsten) + count($htaccessVondsten);
    $extraScanMelding = "Extra map \"$extraScanRootAbsoluut\" meegescand ($extraScanNiveauGebruikt niveau(s) boven de website-root, automatisch bepaald: "
        . ($aantalNaExtraScan - $aantalVoorExtraScan) . " extra vondst(en) daar gevonden, "
        . count($rechtenAfwijkingenExtra) . " afwijkende rechten gesignaleerd)."
        . ' Standaard overgeslagen: ' . implode(', ', $standaardExtraScanpadNegeren) . '.'
        . (!empty($extraScanPadNegerenEigen) ? ' Zelf ook overgeslagen: ' . implode(', ', $extraScanPadNegerenEigen) . '.' : '');
}

// Kernbestand-integriteitscontrole: code vóór Joomla's _JEXEC-bootstrap
// is vrijwel altijd een teken van een actief gecompromitteerd kernbestand.
$kernEntryPoints = ['index.php', 'administrator/index.php', 'api/index.php', 'includes/app.php'];
foreach ($kernEntryPoints as $relEntry) {
    $absEntry = $startMap . '/' . $relEntry;
    if (!is_file($absEntry) || !is_readable($absEntry)) {
        continue;
    }
    $entryInhoud = @file_get_contents($absEntry);
    if ($entryInhoud === false) {
        continue;
    }
    $kernProbleem = checkKernIntegriteit($entryInhoud);
    if ($kernProbleem !== null) {
        $backdoorVondsten[] = [
            'naam' => '/' . $relEntry,
            'reden' => $kernProbleem,
            'risico' => 100,
            'bestandspad' => $absEntry,
            'gewijzigd' => date('Y-m-d H:i', filemtime($absEntry)),
            'grootte' => strlen($entryInhoud),
        ];
    }
}

// Database-gebaseerde checks (verdachte Super Users, ontmaskeringsteksten in
// templatestijlen) - gebruikt configuration.php voor een eigen, alleen-lezen
// databaseverbinding. Wordt netjes overgeslagen als dat om wat voor reden
// dan ook niet lukt (bijv. afwijkende serverconfiguratie).
$databaseVondsten = [];
$dbVerbinding = verbindMetJoomlaDatabase($startMap);
scanRogueSuperUsers($dbVerbinding, $databaseVondsten);
scanTemplateDefacement($dbVerbinding, $databaseVondsten);

// Compleet Super Users-overzicht (naam/gebruikersnaam/e-mail/aangemaakt/
// laatst ingelogd/geblokkeerd) - aanvullend op scanRogueSuperUsers()
// hierboven, die alleen automatisch AL BEKENDE aanvallerspatronen meldt.
$superUsersResultaat = haalSuperUsers($dbVerbinding);

// Geïnstalleerde extensies uitlezen via de eigen database van de site
$extensieResultaat = haalGeinstalleerdeExtensies($dbVerbinding, $startMap, $startTime);

$duration = time() - $startTime;

echo "\n=== RESULTATEN ===\n";
echo "Tijd: {$duration}s\n";
echo "Backdoors gevonden: " . count($backdoorVondsten) . "\n";
echo "Verdachte .htaccess-bestanden: " . count($htaccessVondsten) . "\n";
echo "Mogelijk legitieme verdubbelde mapnamen (ter info): " . count($mogelijkLegitiem) . "\n";
echo "Root-level onbekende mappen: " . count($rootLevelUnknown) . "\n";
echo "Database-gebaseerde vondsten (Super Users/defacement): " . count($databaseVondsten) . "\n";
if ($extraScanMelding !== null) {
    echo "Extra scanpad: $extraScanMelding\n";
}
echo "\n";

// BACKDOORS
if (!empty($backdoorVondsten)) {
    echo ">>> ❌ KRITIEK - BACKDOORS GEVONDEN:\n\n";
    foreach ($backdoorVondsten as $v) {
        echo "❌ " . $v['naam'] . "\n";
        echo "   Reden: {$v['reden']}\n";
        echo "   Grootte: {$v['grootte']} bytes\n";
        echo "   Gewijzigd: {$v['gewijzigd']}\n";
        echo "   [VERWIJDEREN!]\n\n";
    }
} else {
    echo "✅ Geen backdoor-patronen gevonden!\n\n";
}

// VERDACHTE .HTACCESS-BESTANDEN
if (!empty($htaccessVondsten)) {
    echo ">>> ❌ KRITIEK - VERDACHTE .HTACCESS-BESTANDEN:\n\n";
    foreach ($htaccessVondsten as $v) {
        echo "❌ " . $v['naam'] . "\n";
        echo "   Reden: {$v['reden']}\n";
        echo "   Grootte: {$v['grootte']} bytes\n";
        echo "   Gewijzigd: {$v['gewijzigd']}\n";
        echo "   [CONTROLEREN / VERWIJDEREN!]\n\n";
    }
} else {
    echo "✅ Geen verdachte .htaccess-bestanden gevonden!\n\n";
}

// MOGELIJK LEGITIEME VERDUBBELDE MAPNAMEN (extensie/library-naamgeving)
if (!empty($mogelijkLegitiem)) {
    echo "ℹ️  TER INFO - waarschijnlijk legitieme extensie/library-naamgeving (geen actie nodig, tenzij de datum afwijkt):\n\n";
    foreach ($mogelijkLegitiem as $v) {
        echo "   " . $v['naam'] . " (" . $v['grootte'] . " bytes, gewijzigd " . $v['gewijzigd'] . ")\n";
    }
    echo "\n";
}

// ROOT-LEVEL ONBEKENDE MAPPEN & BESTANDEN
if (!empty($rootLevelUnknown)) {
    echo ">>> ⚠️  WAARSCHUWING - Root-level onbekende items:\n\n";
    foreach ($rootLevelUnknown as $item) {
        if ($item['type'] === 'map') {
            $naamMetSlash = (strpos($item['naam'], '/') === 0 ? $item['naam'] : '/' . $item['naam']) . '/';
            echo "  ⚠️  [map] " . $naamMetSlash . "\n";
            echo "       Gewijzigd: {$item['gewijzigd']}\n";
        } else {
            $grootte = isset($item['grootte']) ? number_format($item['grootte']) . ' bytes' : 'onbekend';
            echo "  ⚠️  [file] " . $item['naam'] . " ({$grootte})\n";
            echo "       Gewijzigd: {$item['gewijzigd']}\n";
        }
    }
    echo "\n";
} else {
    echo "✅ Geen onbekende root-level items!\n\n";
}

// SUPER USERS
echo "=== SUPER USERS ===\n";
if (isset($superUsersResultaat['fout'])) {
    echo "❌ Kon Super Users niet ophalen: " . $superUsersResultaat['fout'] . "\n\n";
} else {
    $beheerdersLijst = $superUsersResultaat['beheerders'] ?? [];
    echo "Aantal Super Users: " . count($beheerdersLijst) . "\n";
    foreach ($beheerdersLijst as $beheerder) {
        $geblokkeerdTekst = ((int) ($beheerder['block'] ?? 0)) === 1 ? ' [GEBLOKKEERD]' : '';
        $laatsteLogin = $beheerder['lastvisitDate'] ?: 'nooit ingelogd';
        echo "- {$beheerder['name']} ({$beheerder['username']}, {$beheerder['email']}) - "
            . "aangemaakt: {$beheerder['registerDate']}, laatst ingelogd: {$laatsteLogin}{$geblokkeerdTekst}\n";
    }
    echo "\n";
}

// EXTENSIES
echo "=== GEÏNSTALLEERDE EXTENSIES ===\n";
if (isset($extensieResultaat['fout'])) {
    echo "❌ Kon extensielijst niet ophalen: " . $extensieResultaat['fout'] . "\n\n";
} else {
    $d = $extensieResultaat['diagnose'] ?? [];
    $aantalGehashteGroepen = count($extensieResultaat['bestand_hashes'] ?? []);
    $aantalGehashteBestanden = array_sum(array_map('count', $extensieResultaat['bestand_hashes'] ?? []));
    echo "Aantal gevonden: " . count($extensieResultaat['extensies']) . "\n";
    echo "Waarvan van derden (niet-Joomla-kern): " . ($d['aantal_derden'] ?? '?') . "\n";
    echo "Waarvan onderdeel van een pakket (package_id > 0): " . ($d['aantal_pakket_onderdeel'] ?? '?') . "\n";
    echo "Extensiebestanden gehasht t.b.v. vergelijking tussen sites: $aantalGehashteBestanden bestand(en) in $aantalGehashteGroepen extensie(s)\n";
    if (!empty($d['update_sites_fout'])) {
        echo "❌ Kon #__update_sites niet uitlezen: " . $d['update_sites_fout'] . "\n";
    } else {
        echo "Update-locaties gevonden in Joomla zelf (#__update_sites): " . ($d['aantal_update_sites'] ?? '?') . "\n";
        echo "Extensies van derden MET een geregistreerde update-locatie: " . ($d['aantal_met_feed_url'] ?? '?') . "\n";
        echo "  - waarvan nieuwste versie succesvol opgehaald: " . ($d['aantal_feed_opgehaald'] ?? '?') . "\n";
        echo "  - waarvan het ophalen mislukte: " . ($d['aantal_feed_mislukt'] ?? '?') . "\n";
        if (!empty($d['feed_details'])) {
            echo "  Details per extensie:\n";
            foreach ($d['feed_details'] as $detailRegel) {
                echo "    - $detailRegel\n";
            }
        }
    }
    echo "\n";
}

// ============================================================================
// MONITOR STUREN
// ============================================================================

$payload = [
    'geheime_code' => $geheimeCode,
    'domein' => $domein,
    'backdoors' => $backdoorVondsten,
    'htaccess_verdacht' => $htaccessVondsten,
    'mogelijk_legitiem' => $mogelijkLegitiem,
    'root_unknown' => $rootLevelUnknown,
    'database_verdacht' => $databaseVondsten,
    'geinstalleerde_extensies' => $extensieResultaat['extensies'] ?? null,
    'super_users' => $superUsersResultaat['beheerders'] ?? null,
    'super_users_fout' => $superUsersResultaat['fout'] ?? null,
    'extensies_fout' => $extensieResultaat['fout'] ?? null,
    'extensie_bestand_hashes' => $extensieResultaat['bestand_hashes'] ?? null,
    'extra_scan_pad_info' => !$extraScanIngeschakeld
        ? null
        : ($extraScanRootAbsoluut !== null
            ? "$extraScanNiveauGebruikt niveau(s) boven de website-root: $extraScanRootAbsoluut"
            : 'Geen bruikbare map gevonden boven de website-root.'),
    'scan_type' => 'scan_v11',
];

echo "=== MONITOR ===\n";

$ch = curl_init('__MONITOR_BASIS_URL__/ontvang_scan.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$antwoord = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($antwoord === false) {
    echo "❌ FOUT: kon monitor niet bereiken (curl-fout)\n";
} elseif ($httpCode !== 200) {
    echo "❌ FOUT: monitor gaf HTTP {$httpCode} terug\n";
    echo "    Antwoord: {$antwoord}\n";
} elseif (strpos($antwoord, 'niet gevonden') !== false || strpos($antwoord, 'onbekend') !== false || stripos($antwoord, 'waarschuwing') !== false) {
    echo "ℹ️  Domein niet in monitor-database\n";
    echo "    Antwoord: {$antwoord}\n";
    echo "    (Scan gegevens worden NIET opgeslagen op monitor)\n";
} elseif (stripos($antwoord, 'OK:') === 0) {
    echo "Naar monitor gestuurd ✓\n";
    echo "    Antwoord: {$antwoord}\n";
} else {
    echo "⚠️  Onverwacht antwoord van monitor:\n";
    echo "    {$antwoord}\n";
}

// ============================================================================
// Hulpfuncties voor het zelf-bijwerken (aangeroepen vanaf het begin van dit
// bestand, zie de toelichting daar)
// ============================================================================
/**
 * Vraagt de monitor of er een nieuwere versie van dit scanscript
 * beschikbaar is (eerst alleen een hash, om onnodig dataverkeer te
 * voorkomen), en haalt bij een verschil de volledige, nieuwe inhoud op om
 * zichzelf mee te overschrijven. Schrijft altijd eerst naar een tijdelijk
 * bestand en hernoemt dat pas naar de eigen bestandsnaam (rename() is op
 * vrijwel elk bestandssysteem atomisch) - zo kan een afgebroken verzoek
 * nooit een half geschreven, kapot scanscript achterlaten.
 *
 * @return bool true als er daadwerkelijk een nieuwere versie is
 *              weggeschreven, anders false (geen update nodig, of de
 *              update is om wat voor reden dan ook mislukt).
 */
function probeerZelfBijTeWerken(string $domein): bool
{
    $payloadBasis = [
        'geheime_code' => '__GEHEIME_CODE__',
        'domein' => $domein,
    ];

    $hashAntwoord = doeZelfBijwerkVerzoek(array_merge($payloadBasis, ['actie' => 'hash']));
    if ($hashAntwoord === null || !($hashAntwoord['succes'] ?? false)) {
        echo "ℹ️  Kon niet controleren of er een update beschikbaar is"
            . (isset($hashAntwoord['foutmelding']) ? " ({$hashAntwoord['foutmelding']})" : '') . ".\n";
        return false;
    }

    $eigenInhoud = @file_get_contents(__FILE__);
    if ($eigenInhoud === false) {
        echo "⚠️  Kon eigen bestandsinhoud niet lezen om te vergelijken.\n";
        return false;
    }
    $eigenHash = hash('sha256', $eigenInhoud);

    if ($hashAntwoord['hash'] === $eigenHash) {
        echo "✓ Scanscript is al up-to-date, geen actie nodig.\n";
        return false;
    }

    echo "Nieuwere versie gevonden - ophalen en zelf bijwerken...\n";

    $inhoudAntwoord = doeZelfBijwerkVerzoek(array_merge($payloadBasis, ['actie' => 'inhoud']));
    if ($inhoudAntwoord === null || !($inhoudAntwoord['succes'] ?? false) || empty($inhoudAntwoord['inhoud'])) {
        echo "❌ Kon de nieuwe inhoud niet ophalen"
            . (isset($inhoudAntwoord['foutmelding']) ? " ({$inhoudAntwoord['foutmelding']})" : '') . ".\n";
        return false;
    }

    $nieuweInhoud = $inhoudAntwoord['inhoud'];

    // Voor de zekerheid: nooit zomaar iets wegschrijven dat niet op een
    // geldig PHP-scanscript lijkt (bijv. bij een onverwacht/leeg antwoord).
    if (strpos($nieuweInhoud, '<?php') !== 0 || strlen($nieuweInhoud) < 1000) {
        echo "❌ De ontvangen inhoud lijkt niet op een geldig scanscript - zelf-update overgeslagen uit voorzorg.\n";
        return false;
    }

    $tijdelijkPad = __FILE__ . '.nieuw-' . uniqid();
    if (@file_put_contents($tijdelijkPad, $nieuweInhoud) === false) {
        echo "❌ Kon de nieuwe versie niet wegschrijven (controleer de schrijfrechten van deze map).\n";
        return false;
    }

    // Dezelfde bestandsrechten als het huidige bestand overnemen, anders
    // kan het nieuwe bestand na de rename() met te ruime/te krappe rechten
    // achterblijven.
    $huidigeRechten = @fileperms(__FILE__);
    if ($huidigeRechten !== false) {
        @chmod($tijdelijkPad, $huidigeRechten & 0777);
    }

    if (@rename($tijdelijkPad, __FILE__)) {
        // Zonder dit expliciet ongeldig te maken, kan PHP (afhankelijk van de
        // OPcache-instellingen van de hostingpartij, bijv. een hoge
        // opcache.revalidate_freq) de OUDE, al gecompileerde versie van dit
        // bestand blijven uitvoeren - ook na deze rename() - waardoor het
        // lijkt alsof een update niet is doorgekomen, terwijl het bestand op
        // schijf allang is bijgewerkt.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(__FILE__, true);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        echo "✅ Scanscript succesvol zelf bijgewerkt (geen FTP/SFTP nodig geweest).\n";
        return true;
    }

    @unlink($tijdelijkPad);
    echo "❌ Nieuwe versie kon niet worden geactiveerd (rename mislukt) - controleer de schrijfrechten van deze map.\n";
    return false;
}

/**
 * @return array{succes: bool, hash?: string, inhoud?: string, foutmelding?: string}|null
 */
function doeZelfBijwerkVerzoek(array $payload): ?array
{
    $ch = curl_init('__MONITOR_BASIS_URL__/haal_scanscript_update.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $antwoord = curl_exec($ch);
    curl_close($ch);

    if ($antwoord === false) {
        return null;
    }

    $data = json_decode($antwoord, true);
    return is_array($data) ? $data : null;
}