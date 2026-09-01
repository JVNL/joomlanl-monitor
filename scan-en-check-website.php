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

$geheimeCode = 'YndiMonitor2026!';
$startMap = __DIR__;

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
];

$backdoorVondsten = [];
$rootLevelUnknown = [];
$htaccessVondsten = [];
$mogelijkLegitiem = [];

// ============================================================================
// BACKDOOR-DETECTIE
// ============================================================================

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
            if (strpos($bestandpad, DIRECTORY_SEPARATOR . 'com_media' . DIRECTORY_SEPARATOR) === false) {
                $reden = 'move_uploaded_file() achter custom request-parameter, buiten com_media - UPLOAD BACKDOOR PATROON';
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
    if (!$verdacht && preg_match('/\$\w+\s*=\s*[\'"]\w*[\'"]\s*\.\s*[\'"]\w*[\'"]\s*;/', $inhoud)) {
        if (preg_match('/\$\$?\w+\s*\(/', $inhoud)) {
            if (preg_match('/\$_(COOKIE|REQUEST|POST|GET|SERVER)\b/i', $inhoud) || preg_match('/\b(eval|base64_decode)\s*\(/i', $inhoud)) {
                $reden = 'Samengestelde functienaam via string-concatenatie + dynamische aanroep, i.c.m. superglobal/eval - OBFUSCATED FUNCTION CALL';
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
                'util', 'assets', 'asset', 'html', 'json', 'xml',
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

    if ($verdacht) {
        $vondsten[] = [
            'naam' => str_replace(__DIR__, '', $bestandpad),
            'reden' => $reden,
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
function scanHtaccessVoorMalware($bestandpad, &$vondsten, $startMap)
{
    if (!is_readable($bestandpad)) {
        return;
    }

    $inhoud = file_get_contents($bestandpad);
    if ($inhoud === false) {
        return;
    }

    $redenen = [];
    $kritiek = false;

    // Het echte gevaar: FilesMatch + Require all denied op php/phtml/.suspected-
    // achtige extensies - dit is de zelfbeschermingstruc die we op deze site
    // massaal aantroffen. Dit alleen is voldoende voor een KRITIEKE melding.
    if (preg_match('/FilesMatch/i', $inhoud) && preg_match('/Require\s+all\s+denied/i', $inhoud)) {
        if (preg_match('/\.\s*\(.*\b(php\d?|phtml|suspected|phar)\b/i', $inhoud) || stripos($inhoud, 'suspected') !== false) {
            $redenen[] = 'FilesMatch + "Require all denied" op php/phtml/.suspected-achtige extensies - '
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
        $vondsten[] = [
            'naam' => str_replace($startMap, '', $bestandpad),
            'reden' => ($kritiek ? '[KRITIEK] ' : '[TER INFO] ') . implode(' | ', $redenen),
            'bestandspad' => $bestandpad,
            'gewijzigd' => date('Y-m-d H:i', filemtime($bestandpad)),
            'grootte' => strlen($inhoud),
        ];
    }
}

// ============================================================================
// SCAN ALLES RECURSIEF VOOR BACKDOORS
// ============================================================================

function scanRecursief($pad, &$backdoorVondsten, &$htaccessVondsten, &$mogelijkLegitiem, $ignoreerBestanden, $startMap, $diepte = 0)
{
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
        if ($item === '.' || $item === '..' || $item === 'cache' || $item === 'log' || $item === 'logs') {
            continue;
        }

        $volledigPad = $pad . '/' . $item;

        if (is_dir($volledigPad)) {
            // Speciale aandacht voor nummermappen
            if (preg_match('/^\d{4,}$/', $item)) {
                // Scan alle PHP in nummergegeven map
                $subItems = @scandir($volledigPad);
                if ($subItems) {
                    foreach ($subItems as $subItem) {
                        if (strtolower(substr($subItem, -4)) === '.php') {
                            scanPhpVoorBackdoors($volledigPad . '/' . $subItem, $backdoorVondsten, $mogelijkLegitiem, $ignoreerBestanden);
                        }
                    }
                }
            } else {
                // Normale map: scan recursief
                scanRecursief($volledigPad, $backdoorVondsten, $htaccessVondsten, $mogelijkLegitiem, $ignoreerBestanden, $startMap, $diepte + 1);
            }

        } else {
            // PHP-bestanden: scan
            if (strtolower(substr($item, -4)) === '.php' || preg_match('/\.(php\d|phtml|php5|phar)$/i', $item)) {
                scanPhpVoorBackdoors($volledigPad, $backdoorVondsten, $mogelijkLegitiem, $ignoreerBestanden);
            }

            // .htaccess-bestanden: apart scannen op zelfbeschermings-/locatiepatronen
            if (strtolower($item) === '.htaccess') {
                scanHtaccessVoorMalware($volledigPad, $htaccessVondsten, $startMap);
            }
        }
    }
}

// ============================================================================
// CHECK ROOT-LEVEL ONBEKENDE MAPPEN & BESTANDEN
// ============================================================================

function checkRootLevel($pad, &$rootUnknown, $vertrouwdeMappen, $ignoreerBestanden)
{
    $items = @scandir($pad);
    if (!$items) {
        return;
    }

    // Vertrouwde bestanden op root level
    $vertrouwdeBestanden = [
        'index.php',
        'index.html',
        'configuration.php',
        'htaccess.txt',
        '.htaccess',
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

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $volledigPad = $pad . '/' . $item;

        if (is_dir($volledigPad)) {
            // Root-level onbekende map
            if (!in_array($item, $vertrouwdeMappen)) {
                $rootUnknown[] = [
                    'type' => 'map',
                    'naam' => $item,
                    'pad' => $volledigPad,
                    'gewijzigd' => date('Y-m-d H:i', filemtime($volledigPad)),
                ];
            }
        } else {
            // Root-level bestand
            if (!in_array($item, $vertrouwdeBestanden) && !in_array($item, $ignoreerBestanden)) {
                $rootUnknown[] = [
                    'type' => 'bestand',
                    'naam' => $item,
                    'pad' => $volledigPad,
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
function haalUrlEenvoudig($url, $timeoutSeconden = 15, ?string &$foutmelding = null)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeoutSeconden,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; CompactWebMonitor/1.0; +https://compactweb.nl)',
    ]);

    $inhoud    = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($inhoud === false || $httpCode !== 200) {
        $foutmelding = $curlErrno !== 0
            ? "curl-fout $curlErrno: $curlError"
            : "HTTP $httpCode";
        return null;
    }

    return $inhoud;
}

// Hulpfunctie: bepaal de hoogste STABIELE versie (geen -dev/-alpha/-beta/-rc)
// uit een update-feed-XML, ongeacht of het een "extension"-bestand
// (<version>1.2.3</version>) of een "collectie"-bestand (version="1.2.3"
// als attribuut) is.
function haalHoogsteStabieleVersieUitXml($xmlInhoud)
{
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

    if (empty($stabiel)) {
        return null;
    }

    usort($stabiel, function ($a, $b) {
        return version_compare($b, $a);
    });

    return $stabiel[0];
}

function haalGeinstalleerdeExtensies($startMap)
{
    $configPad = $startMap . '/configuration.php';

    if (!is_readable($configPad)) {
        return ['fout' => 'configuration.php niet gevonden of niet leesbaar'];
    }

    // configuration.php definieert enkel de klasse JConfig met publieke
    // properties (host, user, password, db, dbprefix, ...) - includen is
    // hier veilig, het voert zelf geen logica uit.
    require_once $configPad;

    if (!class_exists('JConfig')) {
        return ['fout' => 'JConfig-klasse niet gevonden in configuration.php'];
    }

    $config = new JConfig();

    $dbHost   = $config->host ?? 'localhost';
    $dbUser   = $config->user ?? '';
    $dbPass   = $config->password ?? '';
    $dbNaam   = $config->db ?? '';
    $dbPrefix = $config->dbprefix ?? '';

    try {
        $pdoSite = new PDO(
            "mysql:host={$dbHost};dbname={$dbNaam};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_TIMEOUT => 8,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]
        );
    } catch (PDOException $e) {
        return ['fout' => 'Kon niet verbinden met de database van deze site: ' . $e->getMessage()];
    }

    $tabel = $dbPrefix . 'extensions';

    try {
        $stmt = $pdoSite->query("
            SELECT extension_id, name, type, element, folder, client_id, enabled, manifest_cache
            FROM `{$tabel}`
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
    // ------------------------------------------------------------------
    $updateFeedUrls = [];
    $updateSitesFout = null;
    try {
        $updateStmt = $pdoSite->query("
            SELECT use_ext.extension_id, us.location
            FROM `{$dbPrefix}update_sites_extensions` use_ext
            JOIN `{$dbPrefix}update_sites` us ON us.update_site_id = use_ext.update_site_id
            WHERE us.enabled = 1
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
    ];

    $extensies = [];

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

        $isKern = ($auteur !== null && stripos($auteur, 'joomla') !== false)
            || ($rij['type'] === 'file' && $rij['element'] === 'joomla');

        // Alleen voor extensies van derden de nieuwste versie ophalen -
        // voor Joomla-kernonderdelen is dat niet relevant (die volgen we
        // apart via de Joomla-versie zelf).
        $nieuwsteVersie = null;
        $updateFeedUrl  = $updateFeedUrls[$rij['extension_id']] ?? null;

        if (!$isKern) {
            $diagnose['aantal_derden']++;

            if ($updateFeedUrl) {
                $diagnose['aantal_met_feed_url']++;

                $feedFoutmelding = null;
                $feedInhoud = haalUrlEenvoudig($updateFeedUrl, 15, $feedFoutmelding);
                if ($feedInhoud === null) {
                    // Eerste poging mislukt - op tragere hostingpartijen
                    // (bijv. Strato) is dat vaak een eenmalige hapering,
                    // geen structureel probleem. Eén herhaalpoging voorkomt
                    // dat zo'n toevallige hapering een extensie de hele
                    // scan lang als "onbekend" laat tonen.
                    $feedInhoud = haalUrlEenvoudig($updateFeedUrl, 15, $feedFoutmelding);
                }
                if ($feedInhoud !== null) {
                    $nieuwsteVersie = haalHoogsteStabieleVersieUitXml($feedInhoud);
                }

                if ($nieuwsteVersie !== null) {
                    $diagnose['aantal_feed_opgehaald']++;
                } else {
                    $diagnose['aantal_feed_mislukt']++;
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
        ];
    }

    return ['extensies' => $extensies, 'diagnose' => $diagnose];
}



echo "=== JOOMLA BACKDOOR-SCAN (v10) ===\n";
echo "Domein: " . ($_SERVER['HTTP_HOST'] ?? 'CLI') . "\n";
echo "Start: " . date('Y-m-d H:i:s') . "\n";
echo "Scanning...\n\n";

$startTime = time();

// Scan root level
checkRootLevel($startMap, $rootLevelUnknown, $vertrouwdeRootMappen, $ignoreerBestanden);

// Scan alles voor backdoors + verdachte .htaccess-bestanden
scanRecursief($startMap, $backdoorVondsten, $htaccessVondsten, $mogelijkLegitiem, $ignoreerBestanden, $startMap);

// Geïnstalleerde extensies uitlezen via de eigen database van de site
$extensieResultaat = haalGeinstalleerdeExtensies($startMap);

$duration = time() - $startTime;

echo "\n=== RESULTATEN ===\n";
echo "Tijd: {$duration}s\n";
echo "Backdoors gevonden: " . count($backdoorVondsten) . "\n";
echo "Verdachte .htaccess-bestanden: " . count($htaccessVondsten) . "\n";
echo "Mogelijk legitieme verdubbelde mapnamen (ter info): " . count($mogelijkLegitiem) . "\n";
echo "Root-level onbekende mappen: " . count($rootLevelUnknown) . "\n\n";

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
            echo "  ⚠️  [map] /" . $item['naam'] . "/\n";
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

// EXTENSIES
echo "=== GEÏNSTALLEERDE EXTENSIES ===\n";
if (isset($extensieResultaat['fout'])) {
    echo "❌ Kon extensielijst niet ophalen: " . $extensieResultaat['fout'] . "\n\n";
} else {
    $d = $extensieResultaat['diagnose'] ?? [];
    echo "Aantal gevonden: " . count($extensieResultaat['extensies']) . "\n";
    echo "Waarvan van derden (niet-Joomla-kern): " . ($d['aantal_derden'] ?? '?') . "\n";
    if (!empty($d['update_sites_fout'])) {
        echo "❌ Kon #__update_sites niet uitlezen: " . $d['update_sites_fout'] . "\n";
    } else {
        echo "Update-locaties gevonden in Joomla zelf (#__update_sites): " . ($d['aantal_update_sites'] ?? '?') . "\n";
        echo "Extensies van derden MET een geregistreerde update-locatie: " . ($d['aantal_met_feed_url'] ?? '?') . "\n";
        echo "  - waarvan nieuwste versie succesvol opgehaald: " . ($d['aantal_feed_opgehaald'] ?? '?') . "\n";
        echo "  - waarvan het ophalen mislukte: " . ($d['aantal_feed_mislukt'] ?? '?') . "\n";
    }
    echo "\n";
}

// ============================================================================
// MONITOR STUREN
// ============================================================================

$domein = $_SERVER['HTTP_HOST'] ?? 'onbekend';
$payload = [
    'geheime_code' => $geheimeCode,
    'domein' => $domein,
    'backdoors' => $backdoorVondsten,
    'htaccess_verdacht' => $htaccessVondsten,
    'mogelijk_legitiem' => $mogelijkLegitiem,
    'root_unknown' => $rootLevelUnknown,
    'geinstalleerde_extensies' => $extensieResultaat['extensies'] ?? null,
    'extensies_fout' => $extensieResultaat['fout'] ?? null,
    'scan_type' => 'scan_v11',
];

echo "=== MONITOR ===\n";

$ch = curl_init('https://compactweb.nl/00-beheer/ontvang_scan.php');
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