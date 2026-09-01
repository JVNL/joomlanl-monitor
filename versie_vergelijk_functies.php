<?php
/**
 * versie_vergelijk_functies.php
 *
 * Gedeelde hulpfuncties om te bepalen of geïnstalleerde Joomla-/
 * extensieversies up-to-date zijn, op basis van de tabel
 * `nieuwste_versies` (die haal_versies_op.php automatisch bijwerkt
 * voor joomla/jce/akeeba - yootheme moet handmatig worden ingevuld).
 */

/**
 * Haalt alle bekende "nieuwste versies" op als [naam => versie].
 */
function haalNieuwsteVersies(PDO $pdo): array
{
    $resultaat = [];

    $stmt = $pdo->query("SELECT naam, versie FROM nieuwste_versies");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
        $resultaat[$rij['naam']] = $rij['versie'];
    }

    return $resultaat;
}

/**
 * Vergelijkt een geïnstalleerde versie met de nieuwste bekende versie.
 * Geeft true (up-to-date), false (verouderd) of null (onbekend, want
 * één van beide versies ontbreekt) terug.
 */
/**
 * Vergelijkt een geïnstalleerde versie met de nieuwste bekende versie.
 * Geeft true (up-to-date), false (verouderd) of null (onbekend, want
 * één van beide versies ontbreekt) terug.
 *
 * @param bool $negeerLaatsteDeel Negeert het laatste, door punten
 *     gescheiden versie-onderdeel bij de vergelijking (bijv. "6.1.2.1"
 *     wordt dan vergeleken als "6.1.2") - bedoeld voor extensies (met
 *     name taalbestanden) die een eigen, veelvuldig bijgewerkt
 *     build-nummer achter de eigenlijke versie plakken. Zie de
 *     toelichting bij de kolom "negeer_laatste_versiedeel" in
 *     extensie_catalogus.
 */
function isUpToDate(?string $huidig, ?string $nieuwste, bool $negeerLaatsteDeel = false): ?bool
{
    $huidig   = trim((string)$huidig);
    $nieuwste = trim((string)$nieuwste);

    if ($huidig === '' || $nieuwste === '') {
        return null;
    }

    // Sommige extensies (VirtueMart bijv.) leveren af en toe per ongeluk
    // een vergeten build-variabele als versienummer mee (bijv.
    // "${PHING.VERSION}") in plaats van een echt versienummer - zoiets aan
    // version_compare() voeren geeft een willekeurige, onbetrouwbare
    // uitkomst. Eerlijk "onbekend" is dan een betrouwbaarder antwoord dan
    // een vergelijking die toevallig "niet up-to-date" uitkomt.
    if (preg_match('/^[0-9]/', $huidig) !== 1 || preg_match('/^[0-9]/', $nieuwste) !== 1) {
        return null;
    }

    if ($negeerLaatsteDeel) {
        $huidig   = verwijderLaatsteVersieDeel($huidig);
        $nieuwste = verwijderLaatsteVersieDeel($nieuwste);
    }

    return version_compare($huidig, $nieuwste, '>=');
}

/**
 * Knipt het laatste, door een punt gescheiden onderdeel van een
 * versienummer af (bijv. "6.1.2.1" -> "6.1.2") - laat versies met 3 of
 * minder onderdelen ongewijzigd.
 */
function verwijderLaatsteVersieDeel(string $versie): string
{
    $delen = explode('.', $versie);
    if (count($delen) <= 3) {
        return $versie;
    }

    return implode('.', array_slice($delen, 0, 3));
}

/**
 * Haalt de volledige extensiecatalogus op: welke extensies we kennen en
 * waar hun manifest-bestand + (optioneel) update-feed te vinden is.
 * Resultaat: [ sleutel => ['label'=>.., 'manifest_pad'=>.., 'update_feed_url'=>..], ... ]
 */
function haalExtensieCatalogus(PDO $pdo): array
{
    $resultaat = [];

    $stmt = $pdo->query("SELECT sleutel, label, manifest_pad, update_feed_url, negeer_laatste_versiedeel FROM extensie_catalogus");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
        $resultaat[$rij['sleutel']] = $rij;
    }

    return $resultaat;
}

/**
 * Haalt de daadwerkelijk GEDETECTEERDE extensies (en hun versie) op voor
 * één site. Resultaat: [ sleutel => versie, ... ] - alleen extensies die
 * ook echt gevonden zijn staan hierin.
 */
function haalGeinstalleerdeExtensies(PDO $pdo, int $siteId): array
{
    $resultaat = [];

    $stmt = $pdo->prepare("SELECT sleutel, versie FROM site_extensies WHERE site_id = ?");
    $stmt->execute([$siteId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
        $resultaat[$rij['sleutel']] = $rij['versie'];
    }

    return $resultaat;
}

/**
 * Haalt in één keer voor ALLE sites de gedetecteerde extensies op,
 * gegroepeerd per site_id. Voorkomt N losse queries op index.php.
 * Resultaat: [ site_id => [ sleutel => versie, ... ], ... ]
 */
function haalAlleGeinstalleerdeExtensies(PDO $pdo): array
{
    $resultaat = [];

    $stmt = $pdo->query("SELECT site_id, sleutel, versie FROM site_extensies");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
        $resultaat[$rij['site_id']][$rij['sleutel']] = $rij['versie'];
    }

    return $resultaat;
}

/**
 * Bepaalt de up-to-date-status per GEDETECTEERDE extensie van één site.
 * Let op: dit gaat uit van wat er daadwerkelijk gevonden is (haalGeinstalleerdeExtensies),
 * niet van de volledige catalogus - een extensie die niet op deze site
 * gevonden is, verschijnt simpelweg niet in de lijst.
 */
function bepaalExtensieStatussen(array $geinstalleerd, array $catalogus, array $nieuwsteVersies): array
{
    $resultaat = [
        'niet_up_to_date' => [],
        'up_to_date'      => [],
        'onbekend'        => [],
    ];

    foreach ($geinstalleerd as $sleutel => $huidig) {
        $label    = $catalogus[$sleutel]['label'] ?? $sleutel;
        $nieuwste = $nieuwsteVersies[$sleutel] ?? null;
        $negeerLaatsteDeel = !empty($catalogus[$sleutel]['negeer_laatste_versiedeel']);
        $status   = isUpToDate($huidig, $nieuwste, $negeerLaatsteDeel);

        $item = [
            'sleutel'  => $sleutel,
            'label'    => $label,
            'huidig'   => $huidig,
            'nieuwste' => $nieuwste,
        ];

        if ($status === null) {
            $resultaat['onbekend'][] = $item;
        } elseif ($status === false) {
            $resultaat['niet_up_to_date'][] = $item;
        } else {
            $resultaat['up_to_date'][] = $item;
        }
    }

    return $resultaat;
}

/**
 * Parseert een update-XML-feed (zowel het Joomla "collection"-formaat
 * met version="1.2.3"-attributen, als het standaard "updates"-formaat
 * met <version>1.2.3</version>-elementen) en geeft de hoogste STABIELE
 * versie terug (dus geen -dev/-alpha/-beta/-rc-versies).
 */
function haalHoogsteStabieleVersie(string $xmlInhoud): ?string
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

/**
 * Groepeert een lijst ruwe versienummers per hoofdversie (major, het
 * onderdeel vóór de eerste punt) en houdt per hoofdversie de hoogste
 * STABIELE versie aan. Bijvoorbeeld: ['5.4.6','5.4.7','6.1.1','6.1.2']
 * -> ['5' => '5.4.7', '6' => '6.1.2'].
 *
 * Dit is nodig om een site die op een oudere hoofdversie draait (bijv.
 * Joomla 5.x) correct te vergelijken met de nieuwste versie BINNEN die
 * hoofdversie, in plaats van met de hoogste hoofdversie die op dat moment
 * bestaat (bijv. 6.x) - dat zou een major-upgrade zijn, geen gewone update.
 * Werkt automatisch mee met toekomstige hoofdversies (7, 8, ...), zonder
 * dat daar iets voor hoeft te worden aangepast.
 */
function groepeerVersiesPerMajor(array $ruweVersies): array
{
    $stabiel = array_filter($ruweVersies, function ($v) {
        return !preg_match('/-(dev|alpha|beta|rc)/i', $v);
    });

    $perMajor = [];
    foreach ($stabiel as $versie) {
        $major = strtok($versie, '.');
        if ($major === false || $major === '') {
            continue;
        }
        if (!isset($perMajor[$major]) || version_compare($versie, $perMajor[$major], '>')) {
            $perMajor[$major] = $versie;
        }
    }

    return $perMajor;
}

/**
 * Bepaalt de hoofdversie (major, het onderdeel vóór de eerste punt) van
 * een versienummer, bijv. "5.4.7" -> "5". Geeft null terug als dat niet
 * te bepalen is.
 */
function bepaalMajorVersie(?string $versie): ?string
{
    $versie = trim((string) $versie);
    if ($versie === '') {
        return null;
    }
    $major = strtok($versie, '.');
    return ($major === false || $major === '') ? null : $major;
}

/**
 * Bepaalt of een gedetecteerde extensie een Joomla-kernonderdeel is of van
 * derden komt. Gebruikt hiervoor het auteursveld uit de manifest_cache
 * (betrouwbaarder dan een handmatig te onderhouden lijst extensienamen).
 * Als er geen auteur bekend is, valt dit terug op een klein aantal vaste
 * Joomla-kernelementen die nooit een auteur hebben.
 */
/**
 * Normaliseert een auteursnaam voor vergelijkingsdoeleinden: kleine letters
 * én accenten/diakritische tekens weg (bijv. "Cédric KEIFLIN" en
 * "Cedric Keiflin" worden dan allebei "cedric keiflin"). Nodig omdat
 * dezelfde ontwikkelaar zijn naam in verschillende manifest-bestanden
 * (bijv. component vs. module van hetzelfde product) soms net iets anders
 * spelt - zonder dit zouden zulke onderdelen ten onrechte niet samengevoegd
 * worden.
 *
 * Gebruikt bewust een vaste vervangingstabel in plaats van iconv() met
 * TRANSLIT - dat laatste hangt af van transliteratie-tabellen die niet op
 * elke server geïnstalleerd zijn, en faalt dan stilletjes.
 */
function normaliseerAuteur(?string $auteur): string
{
    $auteur = trim((string) $auteur);
    if ($auteur === '') {
        return '';
    }

    $auteur = strtolower($auteur);

    $vervangingen = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ę' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'ñ' => 'n', 'ń' => 'n',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c',
        'ß' => 'ss',
        'š' => 's', 'ś' => 's',
        'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
    ];

    $auteur = strtr($auteur, $vervangingen);

    return trim($auteur);
}

function isJoomlaKernExtensie(array $extensie): bool
{
    $auteur = normaliseerAuteur($extensie['auteur'] ?? '');

    // Specifiek de officiële auteursnaam die Joomla-kernonderdelen zelf
    // gebruiken ("Joomla! Project"), NIET zomaar elk auteursveld dat
    // toevallig het woord "joomla" bevat - anders worden extensies van
    // bedrijven met "Joomla" in hun eigen merknaam (bijv. RSJoomla!,
    // JoomlaShack, Joomlashine) ten onrechte als kernonderdeel gezien, en
    // daardoor uit de extensielijst gefilterd.
    if ($auteur !== '' && strpos($auteur, 'joomla! project') !== false) {
        return true;
    }

    // Joomla-zelf (het "file"-type met element "joomla") heeft geen apart
    // auteursveld in de kern-manifest, maar hoort hier ook nooit in de
    // extensielijst thuis - de Joomla-versie wordt al apart bijgehouden.
    if (($extensie['type'] ?? '') === 'file' && ($extensie['element'] ?? '') === 'joomla') {
        return true;
    }

    return false;
}

/**
 * Extra, handmatig opgegeven uitsluitingen - extensies die weliswaar niet
 * per se een Joomla-auteur hebben, maar die net zo min als losse regel in
 * het extensieoverzicht thuishoren:
 *   - vaste, altijd meegeleverde editorplugins (codemirror/tinymce),
 *   - alles van type "file" (losse bestanden: taalbestanden, licenties,
 *     updater-bestanden e.d. - geen op zichzelf staande extensie),
 *   - onvertaalde taalconstantes die met "PLG_" beginnen (bijv.
 *     "PLG_KUNENA_EASYPROFILE") - dit zijn vrijwel altijd losse
 *     widget-plugins die al bij een groter, apart getoond pakket horen,
 *   - specifieke, bekende Joomla-kernonderdelen waarvan het auteursveld
 *     in het manifest niet (meer) "Joomla" bevat, en die daardoor niet
 *     via isJoomlaKernExtensie() herkend worden - bijv. mod_online
 *     ("Wie is online"), mod_custom ("Aangepaste module"), mod_newsflash
 *     ("Nieuwsflits"), de PHPMailer-bibliotheek die Joomla intern
 *     gebruikt om e-mail te versturen, com_redirect + de bijbehorende
 *     systeemplugin "System - Redirect" (voor 404-afhandeling), Language
 *     Translation Override, Mootools Upgrade, System Restore Points en
 *     "System - One Click Action", "Admin Tools Update Email", en de FOF-
 *     bibliotheek van Akeeba (zowel "F0F (NEW) DO NOT REMOVE" als de
 *     oudere "FOF30"),
 *   - tagcontenttags en mod_acymailing: geen Joomla-kern, maar horen bij het
 *     AcyMailing-pakket (bevestigd terug te vinden bij die extensie in de
 *     database) en worden dus ook hier uitgesloten in plaats van los als
 *     "te controleren" extensie getoond te worden - we volgen liever het
 *     hoofdcomponent/pakket zelf,
 *   - bekende onderdelen van een groter extensiepakket die niet via
 *     Joomla's eigen package_id-koppeling herkend worden - bijv. de
 *     "Akeeba Backup Update Check"-plugin, "Akeeba Backup Lazy
 *     Scheduling" en de "Akeeba Backup Notification Module", die alle
 *     drie gewoon bij het Akeeba Backup-pakket zelf horen.
 */
function isUitgeslotenVanExtensieoverzicht(array $extensie): bool
{
    $type    = strtolower(trim($extensie['type'] ?? ''));
    $element = strtolower(trim($extensie['element'] ?? ''));
    $naam    = trim($extensie['naam'] ?? '');
    $auteur  = trim($extensie['auteur'] ?? '');

    if ($type === 'plugin' && in_array($element, ['codemirror', 'tinymce', 'akeebaupdatecheck', 'aklazy', 'langoverride', 'mtupgrade', 'srp', 'redirect', 'oneclickaction', 'tagcontenttags', 'atoolsupdatecheck'], true)) {
        return true;
    }

    if ($type === 'module' && $element === 'mod_acymailing') {
        return true;
    }

    if ($type === 'module' && $element === 'mod_akadmin') {
        return true;
    }

    if ($type === 'module' && $element === 'mod_custom') {
        return true;
    }

    if ($type === 'module' && $element === 'mod_online') {
        return true;
    }

    if ($type === 'module' && $element === 'mod_newsflash') {
        return true;
    }

    if ($type === 'library' && $element === 'phpmailer') {
        return true;
    }

    if ($type === 'library' && in_array($element, ['fof', 'f0f', 'fof30', 'lib_f0f', 'lib_fof'], true)) {
        return true;
    }

    // phpass (Portable PHP Password Hashing Framework) - standaard onderdeel
    // van Joomla zelf (libraries/phpass/), gebruikt als terugval bij het
    // inloggen van oude accounts met een verouderd wachtwoord-hashformaat.
    // Het manifest behoudt de oorspronkelijke, externe auteursnaam ("Solar
    // Designer") in plaats van "Joomla! Project", waardoor de gewone
    // kernherkenning dit mist.
    if ($type === 'library' && in_array($element, ['phpass', 'lib_phpass'], true)) {
        return true;
    }

    if ($type === 'component' && $element === 'com_redirect') {
        return true;
    }

    // Smart Search-indexerplugins (com_finder, pluginmap "finder": o.a.
    // plg_finder_folder, plg_finder_categories, plg_finder_contacts,
    // plg_finder_content, plg_finder_contentitem, plg_finder_newsfeeds,
    // plg_finder_tags, plg_finder_weblinks) zijn standaard Joomla-
    // kernonderdelen. Hun manifest behoudt echter de auteursnaam van de
    // oorspronkelijke ontwikkelaar (bijv. "Arno Betz") in plaats van
    // "Joomla! Project", waardoor de gewone kernherkenning
    // (isJoomlaKernExtensie()) ze mist - zelfde situatie als phpass
    // hierboven. Gematcht op de pluginmap zelf ("finder") in plaats van
    // op losse elementnamen: die map bevat uitsluitend Joomla's eigen
    // Smart Search-plugins, dus dit is geen brede/riskante prefix-match.
    if ($type === 'plugin' && strtolower(trim($extensie['folder'] ?? '')) === 'finder') {
        return true;
    }

    // Kunena Language Pack heeft nooit een eigen update-feed-URL: zodra er
    // een update van het hoofdcomponent (Kunena zelf) is, wordt het
    // taalpakket daar automatisch in meegenomen. Los tonen levert dus altijd
    // status "onbekend" op, wat misleidend is. BEWUST op auteur + naam
    // gematcht in plaats van op type/element: Joomla registreert dit
    // taalpakket afhankelijk van de Kunena-/Joomla-versie soms als
    // "package", soms (mede) als "module", dus een exacte type/element-check
    // is te fragiel.
    if (stripos($auteur, 'kunena') !== false && stripos($naam, 'language pack') !== false) {
        return true;
    }

    // mod_kunenalatest en mod_kunenasearch: losse, apart gedistribueerde
    // Kunena-modules zonder eigen bekende update-feed. Omdat hun "kale"
    // naam-token ("kunenalatest"/"kunenasearch") het volledige voorvoegsel
    // "kunena" deelt met pkg_kunena, worden ze door versmeltOpTokenPrefix()
    // automatisch samengevoegd met het hoofdpakket - waardoor hun onbekende
    // status de an-zich-wel-bekende status van pkg_kunena overschrijft.
    // Net als bij het taalpakket: liever helemaal niet los tonen dan een
    // misleidende "Onbekend"-melding.
    if ($type === 'module' && in_array($element, ['mod_kunenalatest', 'mod_kunenasearch'], true)) {
        return true;
    }

    if ($type === 'file') {
        return true;
    }

    // RSJoomla! (RSForm! Pro en vergelijkbare extensies van deze
    // ontwikkelaar) registreert losse, meegeïnstalleerde plugins (bijv.
    // een actionlog-plugin) soms zonder correcte package_id-koppeling in
    // Joomla's eigen #__extensions-tabel. Daardoor mist isOnderdeelVanPakket()
    // ze, en het eigen manifest van zo'n los plugintje wordt bovendien
    // nooit bijgewerkt (blijft voor altijd op de installatieversie staan,
    // bijv. "1.0.0") - vergelijkbaar met de reden waarom scan_template.php
    // deze extensies al overslaat bij de feed-check, maar dán wél mits
    // package_id > 0. Hier expliciet ook de package_id-loze gevallen
    // afvangen.
    //
    // BEWUST op exacte naam/element gematcht, NIET breed op
    // "auteur bevat rsjoomla": de gegroepeerde hoofdrij "RSForm! Pro" zelf
    // blijkt na groepeerOpProduct() ook met type "plugin" getoond te
    // worden (het representatieve onderdeel van de groep) - een
    // auteur-brede uitsluiting zou die hoofdrij dus óók verbergen, en dat
    // is precies de rij die wél gecontroleerd moet blijven.
    if ($type === 'plugin' && $element === 'rsform' && strtolower(trim($extensie['folder'] ?? '')) === 'actionlog') {
        return true;
    }

    if ($type === 'plugin' && stripos($naam, 'ACTIONLOG_RSFORM') !== false) {
        return true;
    }

    // Zelfde verhaal als hierboven bij RSForm! Pro, nu voor Akeeba Backup:
    // losse, meegeïnstalleerde hulp-plugins van deze ontwikkelaar die al
    // wél voor een deel losstaand herkend worden (zie de element-lijst met
    // 'akeebaupdatecheck'/'aklazy' verderop hierboven), maar deze twee dus
    // kennelijk niet - zelfde symptoom (géén eigen update-feed, dus altijd
    // "Onbekend"), dus dezelfde behandeling. Bewust NIET breed op auteur
    // "Nicholas K. Dionysopoulos" uitgesloten, om dezelfde reden als bij
    // RSForm: andere, wél zelfstandig te controleren Akeeba-extensies van
    // deze auteur (zoals Admin Tools) moeten gewoon zichtbaar blijven.
    //
    // PLG_INSTALLER_TXI (Jurian Even / Twentronix) hoort hier NIET bij: dat
    // is een andere ontwikkelaar, geen bekend pakket-patroon, en "Onbekend"
    // is voor die extensie gewoon het correcte antwoord - de monitor heeft
    // er simpelweg geen update-feed voor.
    if ($type === 'plugin' && stripos($naam, 'CONSOLE_AKEEBABACKUP') !== false) {
        return true;
    }

    if ($type === 'plugin' && stripos($naam, 'JMONITORING_AKEEBABACKUP') !== false) {
        return true;
    }

    // Verwijderd: een blanket "strpos($naam, 'PLG_') === 0 => uitsluiten"
    // filterde hier alle extensies weg wiens manifest een vertaalbare
    // naam-sleutel gebruikt (<name>PLG_...</name>) in plaats van een
    // hardcoded letterlijke naam - exact de conventie die Joomla's eigen
    // kernplugins ook volgen. Zulke extensies verdwenen zo volledig uit
    // het overzicht in plaats van gewoon (onvertaald) zichtbaar te blijven.
    // Bevestigd met plg_kunena_jnlsolved en de eigen usernamecheck-plugin,
    // die beide precies om deze reden niet in de lijst verschenen.
    //
    // Kwam een écht kapotte/onopgeloste naam hiermee ooit terecht terug in
    // het overzicht? Sluit die dan liever gericht uit op de exacte naam
    // (zoals de andere regels hierboven al doen per type+element), in
    // plaats van via deze te brede prefix-match.

    return false;
}

/**
 * Bepaalt of een gedetecteerde extensie onderdeel is van een Joomla-pakket
 * (bijv. een component + bijbehorende plugins die samen als één pakket
 * geïnstalleerd zijn, zoals "Gallery"). Joomla houdt dit zelf bij via de
 * package_id-kolom in #__extensions: elk onderdeel van een pakket verwijst
 * daarmee naar het pakket zelf. Zulke losse onderdelen hoeven niet apart in
 * het extensieoverzicht te staan - het pakket zelf (met package_id = 0,
 * type "package") wordt namelijk als geheel bijgewerkt, en dat pakket
 * verschijnt gewoon als eigen rij.
 */
function isOnderdeelVanPakket(array $extensie): bool
{
    return (int) ($extensie['package_id'] ?? 0) > 0;
}

/**
 * Maakt een consistente, geldige extensie_catalogus-sleutel op basis van
 * het type en element van een gedetecteerde extensie (bijv. "component" +
 * "com_something" -> "component_com_something"). Wordt gebruikt om
 * automatisch gedetecteerde extensies te koppelen aan (en toe te voegen
 * aan) de handmatige catalogus.
 */
function maakExtensieSleutel(string $type, string $element): string
{
    $ruw     = strtolower(trim($type) . '_' . trim($element));
    $sleutel = preg_replace('/[^a-z0-9_]/', '_', $ruw);
    $sleutel = trim(preg_replace('/_+/', '_', $sleutel), '_');

    return substr($sleutel, 0, 50);
}

/**
 * Herkent de "productnaam" uit een extensienaam die het patroon
 * "Rol - Productnaam" volgt (een gangbare naamgevingsconventie bij
 * ontwikkelaars die meerdere losse plugins/modules bij één product
 * uitbrengen, bijv. "Button - Pagebuilder CK" en "Editor - Pagebuilder CK").
 * Geeft null terug als dat patroon niet wordt herkend.
 */
function bepaalProductnaamUitNaam(string $naam): ?string
{
    $delen = explode(' - ', $naam, 2);
    if (count($delen) === 2 && trim($delen[1]) !== '') {
        return trim($delen[1]);
    }
    return null;
}

// Standaard Joomla-pluginmappen ("groepen") - als een plugin hierin zit,
// zegt de map zelf niets over een specifiek product (in tegenstelling tot
// een eigen, custom groepnaam zoals "pagebuilderck").
const STANDAARD_PLUGIN_GROEPEN = [
    'system', 'content', 'user', 'authentication', 'editors', 'editors-xtd',
    'search', 'captcha', 'extension', 'installer', 'quickicon', 'actionlog',
    'privacy', 'sampledata', 'webservices', 'api-authentication', 'task',
    'fields', 'finder', 'behaviour', 'filesystem', 'media', 'multifactorauth',
    'console', 'workflow', 'twofactorauth', 'sso', 'filter', 'languages',
];

/**
 * Haalt een "herkomst-token" uit een extensie, gebaseerd op de map (bij
 * plugins, mits geen standaard Joomla-groep) of anders het element (met
 * bekende voorvoegsels als com_/mod_/plg_/pkg_/lib_/file_ eraf, en alleen
 * het eerste onderdeel vóór een underscore). Dit is vaak een veel
 * betrouwbaardere aanwijzing voor "hoort bij hetzelfde product" dan de
 * (soms onvertaalde of inconsistente) weergavenaam.
 */
function haalHerkomstToken(array $extensie): string
{
    $folder = strtolower(trim($extensie['folder'] ?? ''));
    $type   = $extensie['type'] ?? '';

    if ($type === 'plugin' && $folder !== '' && !in_array($folder, STANDAARD_PLUGIN_GROEPEN, true)) {
        return 'map:' . $folder;
    }

    $element = strtolower(trim($extensie['element'] ?? ''));
    $element = preg_replace('/^(com|mod|plg|pkg|lib|file)_/', '', $element);
    $eersteDeel = explode('_', $element)[0] ?? '';

    return 'element:' . ($eersteDeel !== '' ? $eersteDeel : $element);
}

/**
 * Pass 1: groepeert extensies op auteur + herkomst-token (map/element).
 * Het versienummer telt hier bewust NIET mee in de sleutel - losse
 * onderdelen van hetzelfde product hebben in de praktijk vaak een net
 * ietwat afwijkend eigen versienummer (bijv. een los "tool" bij Kunena),
 * dus dat zou onterecht scheiding veroorzaken. Dit vangt bijv. alle losse
 * Pagebuilder CK-widgetplugins samen (gedeelde plugin-groep "pagebuilderck"),
 * en de component/module met hetzelfde element-voorvoegsel.
 */
/**
 * Sommige ontwikkelaars schrijven hun auteursveld per extensie in een
 * andere volgorde/opmaak (bijv. "Dirk Hoeschen - computer * daten * netze
 * : feenders" versus "computer :: daten :: netze - feenders - Dirk
 * Hoeschen") - onze gewone auteursnormalisatie (kleine letters, accenten
 * weghalen) ziet zulke volgorde-verschillen nog steeds als afwijkende
 * auteurs, waardoor losse onderdelen van hetzelfde product niet aan elkaar
 * gekoppeld worden. Voor bekende, bevestigde gevallen wordt hier bewust op
 * een vast, herkenbaar trefwoord in de auteursnaam gematcht, en een vaste
 * vervangsleutel teruggegeven - zodat dit soort onderdelen alsnog als één
 * groep behandeld worden, ongeacht de exacte schrijfwijze.
 *
 * Bewust op een specifiek trefwoord i.p.v. losse woorden van de naam zelf
 * (zoals "dirk"/"hoeschen"), om te voorkomen dat dit ooit per ongeluk een
 * heel andere auteur met een toevallig gelijke voor-/achternaam raakt.
 */
function haalHandmatigeAuteursgroeperingssleutel(string $auteurRuw): ?string
{
    $auteurKleineLetters = strtolower($auteurRuw);

    $bekendeTrefwoorden = [
        'feenders' => 'handmatig_dirk_hoeschen_feenders',
    ];

    foreach ($bekendeTrefwoorden as $trefwoord => $vasteSleutel) {
        if (strpos($auteurKleineLetters, $trefwoord) !== false) {
            return $vasteSleutel;
        }
    }

    return null;
}

/**
 * Naast op auteur (zie hierboven) kan een groep ook herkend worden op een
 * vast trefwoord in de naam/het element van de extensie zelf - nodig voor
 * gevallen zoals AcyMailing, waar losse onderdelen (editor-plugin,
 * content-triggers, JCE-integratie, enz.) niet betrouwbaar hetzelfde
 * auteursveld hebben, maar wel allemaal een herkenbaar deel van de
 * productnaam in hun eigen naam/element voeren. In tegenstelling tot
 * haalHandmatigeGroeperingssleutelZonderRepresentatieveStatus() hieronder
 * (voor RSForm! Pro) is hier GEEN los onderdeel met een eigen, betrouwbaar
 * werkende update-feed bekend die apart zichtbaar zou moeten blijven - dus
 * mag deze groep gewoon, net als de auteursgebaseerde groepering
 * hierboven, wél de representatieve status gebruiken.
 */
function haalHandmatigeNaamGroeperingssleutel(array $extensie): ?string
{
    $tekst = strtolower(($extensie['naam'] ?? '') . ' ' . ($extensie['element'] ?? ''));

    $bekendeTrefwoorden = [
        'acym'       => 'handmatig_acymailing', // vangt acymailing, com_acym, acymtriggers, jceacym, enz. in één keer
        'acyeditor'  => 'handmatig_acymailing', // enige uitzondering zonder een letterlijke "acym" erin
    ];

    foreach ($bekendeTrefwoorden as $trefwoord => $vasteSleutel) {
        if (strpos($tekst, $trefwoord) !== false) {
            return $vasteSleutel;
        }
    }

    return null;
}

/**
 * Zelfde idee als haalHandmatigeAuteursgroeperingssleutel() hierboven -
 * dwingt losse onderdelen met sterk uiteenlopende element-namen alsnog in
 * dezelfde groep - maar BEWUST zonder de "alleen het representatieve
 * onderdeel bepaalt de status"-regel te activeren.
 *
 * Bij RSForm! Pro (RSJoomla!) bleek dat onderscheid nodig: er bestaan
 * sites met een los RSForm-plugin dat wél een eigen, werkende update-feed
 * heeft en dus een betrouwbare eigen status kan hebben - die zou bij de
 * "representatieve status"-behandeling ten onrechte genegeerd worden
 * zodra het hoofdcomponent zelf toevallig up-to-date is. Met deze functie
 * blijft de gewone "slechtste status wint"-regel gewoon van kracht: zodra
 * ÉÉN onderdeel (met een betrouwbare eigen versie-check) achterloopt, laat
 * de hele groep dat terecht zien - alleen de KEUZE welk onderdeel de groep
 * qua naam/type/versie representeert (component > package > overig) wordt
 * hiermee rechtgetrokken.
 *
 * BEWUST op naam/element van RSForm zelf gematcht, NIET breed op de
 * uitgever "RSJoomla!": die uitgever heeft meerdere, volledig losstaande
 * producten (bijv. RSEvents!Pro naast RSForm! Pro). Een auteur-brede match
 * trok bij een site met zowel RSForm! Pro als RSEvents!Pro geïnstalleerd
 * per ongeluk béide componenten in dezelfde groep - bij een gelijke
 * prioriteit (allebei "component") won dan gewoon wie het eerst verwerkt
 * werd, met een compleet verkeerd versienummer in de weergave tot gevolg
 * (RSEvents!Pro's eigen versie getoond onder de naam "RSForm! Pro").
 */
function haalHandmatigeGroeperingssleutelZonderRepresentatieveStatus(array $extensie): ?string
{
    $naam    = strtolower(trim($extensie['naam'] ?? ''));
    $element = strtolower(trim($extensie['element'] ?? ''));
    $type    = strtolower(trim($extensie['type'] ?? ''));

    // RSForm! Pro's losse hulp-plugins/pakketten hebben stuk voor stuk
    // "rsform" ergens in hun naam of element staan (bijv. element
    // "rsformcli", of naam "System - RSForm! Pro CLI") - in tegenstelling
    // tot compleet andere RSJoomla-producten zoals "RSEvents!Pro", waar
    // "rsform" nergens in voorkomt.
    if (strpos($naam, 'rsform') !== false || strpos($element, 'rsform') !== false) {
        return 'handmatig_rsjoomla_rsform';
    }

    // JCE (Widget Factory / Ryan Demmer): de losse plugins uit het PKG_JCE-
    // pakket (elementen "jce" en "mediajce", in de editors/content/
    // extension/installer/quickicon/system/fields-pluginmappen) komen bij
    // sommige, met name oudere installaties zonder package_id-koppeling ÉN
    // zonder ingevuld auteursveld voor - waarschijnlijk een restant van een
    // installatie van vóór JCE als verpakt "package" werd uitgebracht.
    // Zonder auteur worden zulke onderdelen door de gewone token-groepering
    // (die een gedeelde auteur vereist, zie versmeltOpTokenPrefix()) nooit
    // aan het pakket gekoppeld, en blijven ze voor altijd "Onbekend" tonen
    // terwijl PKG_JCE zelf allang een bekende, actuele versie heeft.
    //
    // BEWUST op het exacte element gematcht (niet op een brede naam-
    // substring als "bevat jce"): "JCE MediaBox" (plg_system_jcemediabox,
    // element "jcemediabox") is een volledig apart product met een eigen,
    // andere versienummering en zou door zo'n substring-match ten onrechte
    // meegetrokken worden.
    if ($type === 'plugin' && in_array($element, ['jce', 'mediajce'], true)) {
        return 'handmatig_widgetfactory_jce';
    }
    if ($type === 'component' && $element === 'com_jce') {
        return 'handmatig_widgetfactory_jce';
    }
    if ($type === 'package' && in_array(preg_replace('/^pkg_/', '', $element), ['jce', 'jce_pro'], true)) {
        return 'handmatig_widgetfactory_jce';
    }

    return null;
}

function groepeerOpHerkomst(array $extensies): array
{
    $groepen = [];

    foreach ($extensies as $extensie) {
        $auteurGenorm = normaliseerAuteur($extensie['auteur'] ?? '');
        $token        = haalHerkomstToken($extensie);
        $sleutel      = $auteurGenorm . '|' . $token;

        $handmatigeSleutel = haalHandmatigeAuteursgroeperingssleutel($extensie['auteur'] ?? '');
        $gebruikRepresentatieveStatus = $handmatigeSleutel !== null;

        if ($handmatigeSleutel === null) {
            $handmatigeSleutel = haalHandmatigeNaamGroeperingssleutel($extensie);
            // AcyMailing: hier WEL representatieve status gebruiken, zie de
            // toelichting bij haalHandmatigeNaamGroeperingssleutel() zelf.
            $gebruikRepresentatieveStatus = $handmatigeSleutel !== null;
        }

        if ($handmatigeSleutel === null) {
            $handmatigeSleutel = haalHandmatigeGroeperingssleutelZonderRepresentatieveStatus($extensie);
            // $gebruikRepresentatieveStatus blijft bewust false: deze
            // sleutel dwingt alleen de groepering af, niet welke status
            // getoond wordt (zie de toelichting bij de functie zelf).
        }

        if ($handmatigeSleutel !== null) {
            $sleutel = $handmatigeSleutel;
        }

        if (!isset($groepen[$sleutel])) {
            $groepen[$sleutel] = [
                'namen'            => [],
                'sleutels'         => [],
                'gebruik_representatief_status' => $gebruikRepresentatieveStatus,
                'types'            => [],
                'versie'           => $extensie['versie'] ?? null,
                'nieuwste_versie'  => null,
                'auteur'           => $extensie['auteur'] ?? null,
                'enabled'          => false,
                'status'           => null,
                'aantal_onderdelen' => 0,
                'heeft_component'  => false,
                'representatief_prioriteit' => 99,
                'representatief_type'    => $extensie['type'] ?? '',
                'representatief_element' => $extensie['element'] ?? '',
                'representatief_naam'    => $extensie['naam'] ?? '',
                'representatief_versie'  => $extensie['versie'] ?? null,
                'token'            => $token,
            ];
        }

        $groep = &$groepen[$sleutel];

        $groep['namen'][] = $extensie['naam'] ?? '';
        $groep['sleutels'][] = maakExtensieSleutel($extensie['type'] ?? '', $extensie['element'] ?? '');
        $groep['types'][] = $extensie['type'] ?? '?';
        $groep['aantal_onderdelen']++;
        $groep['enabled'] = $groep['enabled'] || !empty($extensie['enabled']);

        if (($extensie['type'] ?? '') === 'component') {
            $groep['heeft_component'] = true;
        }

        // Voorkeursvolgorde voor het representatieve onderdeel (bepaalt de
        // catalogus-sleutel, naam en versie): component (0) > package (1) >
        // al het andere, op volgorde van eerst-tegengekomen (2). Zonder deze
        // volgorde kon bijv. een los "file"-onderdeel de voorkeur krijgen
        // boven het eigenlijke "package"-onderdeel, puur omdat het toevallig
        // eerder werd verwerkt - met een verweesde update-feed-URL tot gevolg.
        //
        // Een taalpakket-package (bijv. "Kunena Language Pack", pkg_..._languages)
        // krijgt binnen de "package"-prioriteit bewust een lagere sub-prioriteit
        // (1.5 i.p.v. 1) dan het eigenlijke productpakket. Zonder dit onderscheid
        // zijn twee packages binnen dezelfde herkomstgroep (bijv. pkg_kunena en
        // pkg_kunena_languages) qua prioriteit gelijk, en wint dan simpelweg wie
        // het eerst verwerkt wordt - en dat is het taalpakket, omdat de query in
        // scan_template.php taalbestanden bewust als eerste verwerkt (zie de
        // toelichting daar). Gevolg: de hele groep werd getoond als "Kunena
        // Language Pack" i.p.v. "Kunena Forum".
        $isTaalpakketPackage = ($extensie['type'] ?? '') === 'package'
            && stripos((string) ($extensie['naam'] ?? ''), 'language pack') !== false;

        $prioriteitVoorType = ['component' => 0, 'package' => 1];
        $huidigePrioriteit = $isTaalpakketPackage
            ? 1.5
            : ($prioriteitVoorType[$extensie['type'] ?? ''] ?? 2);

        if ($huidigePrioriteit < $groep['representatief_prioriteit']) {
            $groep['representatief_prioriteit'] = $huidigePrioriteit;
            $groep['representatief_type']    = $extensie['type'] ?? '';
            $groep['representatief_element'] = $extensie['element'] ?? '';
            $groep['representatief_naam']    = $extensie['naam'] ?? '';
            $groep['representatief_versie']  = $extensie['versie'] ?? null;

            // Bij handmatig (op auteur) gekoppelde groepen is bevestigd dat
            // losse onderdelen vaak een eigen, niet-bijgehouden versienummer
            // hebben terwijl het hoofdonderdeel wél correct wordt beheerd -
            // in dat geval bepaalt uitsluitend het representatieve onderdeel
            // de getoonde status, in plaats van de gebruikelijke "slechtste
            // status wint"-regel die de rest van dit systeem gebruikt.
            if ($groep['gebruik_representatief_status']) {
                $groep['status'] = $extensie['status'] ?? null;
            }
        }

        $groep['nieuwste_versie'] = kiesHoogsteNieuwsteVersie($groep['nieuwste_versie'], $extensie['nieuwste_versie'] ?? null);

        $volgorde = fn($s) => $s === false ? 0 : ($s === true ? 1 : 2);
        if (!$groep['gebruik_representatief_status']
            && ($groep['status'] === null || $volgorde($extensie['status'] ?? null) < $volgorde($groep['status']))
        ) {
            $groep['status'] = $extensie['status'] ?? null;
        }
        unset($groep);
    }

    $resultaat = [];
    foreach ($groepen as $groep) {
        // Weergavenaam: bij voorkeur die van het representatieve onderdeel
        // (component > package), tenzij het "Rol - Productnaam"-patroon
        // ergens anders een duidelijkere naam oplevert.
        $naam = null;
        foreach ($groep['namen'] as $n) {
            $herkend = bepaalProductnaamUitNaam($n);
            if ($herkend !== null) {
                $naam = $herkend;
                break;
            }
        }
        if ($naam === null) {
            $naam = $groep['representatief_naam'] !== '' ? $groep['representatief_naam'] : $groep['namen'][0];
        }

        // Versie: die van het representatieve onderdeel (component > package
        // > eerst-tegengekomen), zodat dit consistent is met de sleutel.
        $versie = $groep['representatief_versie'];

        $unieketypes = array_unique($groep['types']);
        $typeTekst = count($unieketypes) === 1
            ? $unieketypes[0] . ($groep['aantal_onderdelen'] > 1 ? ' (' . $groep['aantal_onderdelen'] . 'x)' : '')
            : implode(', ', $unieketypes);

        $resultaat[] = [
            'naam'             => $naam,
            'type'             => $typeTekst,
            'versie'           => $versie,
            'nieuwste_versie'  => $groep['nieuwste_versie'],
            'auteur'           => $groep['auteur'],
            'enabled'          => $groep['enabled'],
            'status'           => $groep['status'],
            'aantal_onderdelen' => $groep['aantal_onderdelen'],
            'representatief_type'    => $groep['representatief_type'],
            'representatief_element' => $groep['representatief_element'],
            'sleutels'         => array_values(array_unique($groep['sleutels'])),
            'token'            => $groep['token'],
        ];
    }

    return $resultaat;
}

/**
 * Geeft de lengte van het gedeelde voorvoegsel van twee strings terug.
 */
function gedeeldeVoorvoegselLengte(string $a, string $b): int
{
    $lengte = min(strlen($a), strlen($b));
    for ($i = 0; $i < $lengte; $i++) {
        if ($a[$i] !== $b[$i]) {
            return $i;
        }
    }
    return $lengte;
}

/**
 * Voegt twee reeds-gegroepeerde producten (uit pass 1) samen tot één, voor
 * gebruik door versmeltOpTokenPrefix(). Component-gegevens (naam/versie/
 * representatief type+element) van de groep die er één heeft, wint.
 */
/**
 * Kiest, bij het samenvoegen van twee gegroepeerde producten, de meest
 * betrouwbare "nieuwste versie" van de twee - namelijk de HOOGSTE, niet
 * zomaar de eerst-verwerkte. Bij een product met tientallen losse
 * onderdelen (bijv. VirtueMart: één package + losse modules/plugins) meldt
 * een deel daarvan vaak een oudere versie dan het pakket/hoofdonderdeel
 * zelf - zonder deze vergelijking zou de uiteindelijke groep willekeurig
 * de laagste van de twee kunnen tonen, puur afhankelijk van de
 * (alfabetische) verwerkingsvolgorde.
 *
 * Beschermt zich tegen overduidelijk kapotte versiestrings (bijv. een
 * vergeten build-variabele als "${PHING.VERSION}", die VirtueMart soms
 * per ongeluk meelevert) door zo'n waarde simpelweg te negeren in plaats
 * van aan version_compare() aan te bieden.
 */
function kiesHoogsteNieuwsteVersie(?string $a, ?string $b): ?string
{
    $isBruikbareVersie = fn(?string $v) => $v !== null && $v !== '' && preg_match('/^[0-9]/', $v) === 1;

    $aBruikbaar = $isBruikbareVersie($a);
    $bBruikbaar = $isBruikbareVersie($b);

    if ($aBruikbaar && $bBruikbaar) {
        return version_compare($a, $b, '>=') ? $a : $b;
    }
    if ($aBruikbaar) {
        return $a;
    }
    if ($bBruikbaar) {
        return $b;
    }

    // Geen van beide bruikbaar - dan toch nog liever iets tonen dan niets.
    return $a ?? $b;
}

function combineerGegroepeerdeProducten(array $a, array $b): array
{
    $heeftComponentA = ($a['representatief_type'] ?? '') === 'component';
    $primair   = $heeftComponentA ? $a : $b;
    $secundair = $heeftComponentA ? $b : $a;

    $volgorde = fn($s) => $s === false ? 0 : ($s === true ? 1 : 2);
    $status = $volgorde($a['status']) <= $volgorde($b['status']) ? $a['status'] : $b['status'];

    return [
        'naam'             => $primair['naam'],
        'type'             => $a['type'] === $b['type'] ? $a['type'] : trim($a['type'] . ', ' . $b['type'], ', '),
        'versie'           => $primair['versie'],
        'nieuwste_versie'  => kiesHoogsteNieuwsteVersie($a['nieuwste_versie'] ?? null, $b['nieuwste_versie'] ?? null),
        'auteur'           => $primair['auteur'],
        'enabled'          => $a['enabled'] || $b['enabled'],
        'status'           => $status,
        'aantal_onderdelen' => $a['aantal_onderdelen'] + $b['aantal_onderdelen'],
        'representatief_type'    => $primair['representatief_type'],
        'representatief_element' => $primair['representatief_element'],
        'sleutels'         => array_values(array_unique(array_merge($a['sleutels'] ?? [], $b['sleutels'] ?? []))),
        'token'            => $primair['token'],
    ];
}

/**
 * Pass 1.5: versmelt wat na pass 1 nog los staat, als de "kale" tokens
 * (zonder de map:/element:-labels) van elkaar afgeleid zijn - bijv.
 * "kunena" en "kunenalatest" (van mod_kunenalatest) delen het volledige
 * "kunena"-voorvoegsel. Vereist dezelfde auteur en een gedeeld voorvoegsel
 * van minstens 5 tekens dat ook echt de VOLLEDIGE kortste token beslaat
 * (dus geen toevallige gedeelde eerste letters).
 */
function versmeltOpTokenPrefix(array $groepen): array
{
    $groepen = array_values($groepen);
    $gebruikt = array_fill(0, count($groepen), false);
    $resultaat = [];

    for ($i = 0; $i < count($groepen); $i++) {
        if ($gebruikt[$i]) {
            continue;
        }

        $huidig = $groepen[$i];
        $auteurA = normaliseerAuteur($huidig['auteur'] ?? '');

        if ($auteurA !== '') {
            for ($j = $i + 1; $j < count($groepen); $j++) {
                if ($gebruikt[$j]) {
                    continue;
                }

                $kandidaat = $groepen[$j];
                $auteurB = normaliseerAuteur($kandidaat['auteur'] ?? '');
                if ($auteurA !== $auteurB) {
                    continue;
                }

                $tokenA = preg_replace('/^(map|element):/', '', $huidig['token']);
                $tokenB = preg_replace('/^(map|element):/', '', $kandidaat['token']);

                $gedeeld = gedeeldeVoorvoegselLengte($tokenA, $tokenB);
                $kortste = min(strlen($tokenA), strlen($tokenB));

                if ($kortste >= 5 && $gedeeld === $kortste) {
                    $huidig = combineerGegroepeerdeProducten($huidig, $kandidaat);
                    $gebruikt[$j] = true;
                }
            }
        }

        $resultaat[] = $huidig;
    }

    return $resultaat;
}

/**
 * Pass 2 (vangnet): clustert wat na pass 1 nog LOSSTAAND is (aantal_onderdelen
 * === 1) nogmaals, puur op auteur + exact hetzelfde versienummer. Delen 2+
 * losse onderdelen dezelfde auteur én versie, dan is de kans groot dat ze
 * toch bij hetzelfde product horen (bijv. een hele reeks losse
 * integratie-plugins/modules die in één release tegelijk worden uitgebracht),
 * ook al is er geen gedeeld naam- of elementpatroon herkend. Minder zeker
 * dan pass 1, dus duidelijk gelabeld in de weergavenaam.
 */
function clusterOpAuteurEnVersie(array $groepen): array
{
    $singletons = array_values(array_filter($groepen, fn($g) => $g['aantal_onderdelen'] === 1));
    $overig     = array_values(array_filter($groepen, fn($g) => $g['aantal_onderdelen'] !== 1));

    $clusters = [];
    foreach ($singletons as $groep) {
        $auteurGenorm = normaliseerAuteur($groep['auteur'] ?? '');
        if ($auteurGenorm === '' || empty($groep['versie'])) {
            $overig[] = $groep;
            continue;
        }
        $sleutel = $auteurGenorm . '|' . $groep['versie'];
        $clusters[$sleutel][] = $groep;
    }

    foreach ($clusters as $cluster) {
        if (count($cluster) === 1) {
            $overig[] = $cluster[0];
            continue;
        }

        usort($cluster, fn($a, $b) => strlen($a['naam']) <=> strlen($b['naam']));
        $representatief = $cluster[0];

        $types   = [];
        $status  = null;
        $nieuwsteVersie = null;
        $enabled = false;
        $alleSleutels = [];
        $volgorde = fn($s) => $s === false ? 0 : ($s === true ? 1 : 2);

        foreach ($cluster as $item) {
            $types[] = $item['type'];
            $enabled = $enabled || !empty($item['enabled']);
            $nieuwsteVersie = kiesHoogsteNieuwsteVersie($nieuwsteVersie, $item['nieuwste_versie'] ?? null);
            if ($status === null || $volgorde($item['status']) < $volgorde($status)) {
                $status = $item['status'];
            }
            $alleSleutels = array_merge($alleSleutels, $item['sleutels'] ?? []);
        }

        $overig[] = [
            'naam'             => $representatief['naam'] . ' (+ ' . (count($cluster) - 1) . ' gerelateerde onderdelen)',
            'type'             => implode(', ', array_unique($types)),
            'versie'           => $representatief['versie'],
            'nieuwste_versie'  => $nieuwsteVersie,
            'auteur'           => $representatief['auteur'],
            'enabled'          => $enabled,
            'status'           => $status,
            'aantal_onderdelen' => array_sum(array_column($cluster, 'aantal_onderdelen')),
            'representatief_type'    => $representatief['representatief_type'] ?? '',
            'representatief_element' => $representatief['representatief_element'] ?? '',
            'sleutels'         => array_values(array_unique($alleSleutels)),
        ];
    }

    return $overig;
}

/**
 * Leidt een catalogus-sleutel af uit een gegroepeerd product (het resultaat
 * van groepeerOpProduct), op basis van het representatieve type/element dat
 * tijdens het groeperen is bijgehouden (bij voorkeur dat van het component).
 */
function sleutelVoorGegroepeerdProduct(array $groep): string
{
    return maakExtensieSleutel($groep['representatief_type'] ?? '', $groep['representatief_element'] ?? '');
}

/**
 * Of een hele gegroepeerde rij verborgen moet worden omdat-ie "genegeerd"
 * is. Bewust NIET alleen het representatieve onderdeel checken: een groep
 * kan meerdere originele extensies bundelen (bijv. een component + losse
 * plugins die er per ongeluk qua herkomst-token mee samensmelten), elk met
 * hun eigen catalogus-sleutel. Als toevallig alleen het representatieve
 * onderdeel ooit genegeerd is (bijv. omdat de opschoonlogica die overbodig
 * achtte), verdween voorheen de HELE groep - ook onderdelen waarvan de
 * eigen sleutel gewoon nog actief was en die dus wel degelijk nog getoond
 * hadden moeten worden (bevestigd misgegaan bij RSEvents: het component
 * was terecht/onterecht genegeerd, maar de bijbehorende plugin niet - toch
 * verdween de hele groep). Nu dus: alleen verbergen als ECHT elk onderdeel
 * van de groep genegeerd is.
 */
function isGegroepeerdProductVolledigGenegeerd(array $groep, array $genegeerdeSleutels): bool
{
    $sleutels = $groep['sleutels'] ?? [sleutelVoorGegroepeerdProduct($groep)];
    if (empty($sleutels)) {
        return isset($genegeerdeSleutels[sleutelVoorGegroepeerdProduct($groep)]);
    }
    foreach ($sleutels as $sleutel) {
        if (!isset($genegeerdeSleutels[$sleutel])) {
            return false;
        }
    }
    return true;
}

/**
 * Haalt de set sleutels op die in de catalogus als "genegeerd" zijn
 * gemarkeerd, zodat het extensieoverzicht die meteen kan uitfilteren -
 * zonder dat er eerst opnieuw gescand hoeft te worden.
 */
function haalGenegeerdeSleutels(PDO $pdo): array
{
    $sleutels = $pdo->query("SELECT sleutel FROM extensie_catalogus WHERE genegeerd = 1")->fetchAll(PDO::FETCH_COLUMN);
    return array_fill_keys($sleutels, true);
}

/**
 * Groepeert een lijst extensies (die al de 'status'-sleutel hebben, zie
 * haalDerdePartijExtensies) in twee stappen tot zo min mogelijk rijen per
 * daadwerkelijk product: eerst op herkomst (map/element + auteur + versie),
 * daarna wat nog los overblijft op auteur + versie als vangnet.
 */
function groepeerOpProduct(array $extensies): array
{
    $naPas1  = groepeerOpHerkomst($extensies);
    $naPas15 = versmeltOpTokenPrefix($naPas1);
    return clusterOpAuteurEnVersie($naPas15);
}

/**
 * Haalt de gedetecteerde extensies VAN DERDEN op voor één site (dus de
 * Joomla-kernonderdelen zijn er al uitgefilterd). Dit is de volledige,
 * automatisch gedetecteerde lijst (via scan-en-check-website.php), niet de
 * handmatige catalogus. Elke rij krijgt ook een berekende 'status'
 * (true/false/null - zie isUpToDate) op basis van de nieuwste_versie die
 * het scanscript zelf al heeft opgehaald via Joomla's eigen update-sites.
 * Losse plugins/modules van hetzelfde product (zonder package_id, zie
 * bepaalProductnaam) worden samengevoegd tot één rij.
 */
/**
 * Haalt gegroepeerde producten op die AL automatisch een nieuwste versie
 * hebben (dus nooit automatisch aan de catalogus zijn toegevoegd, zie
 * ontvang_scan.php), en die ook nog niet handmatig in de catalogus staan -
 * dus extensies die je op dit moment niet kunt negeren, simpelweg omdat er
 * nergens een rij voor bestaat. Optioneel gefilterd op één site; zonder
 * site-filter worden unieke sleutels over alle sites samengevoegd.
 */
function haalReedsOpgelosteExtensiesZonderCatalogusRij(PDO $pdo, ?int $siteId): array
{
    if ($siteId !== null) {
        $stmt = $pdo->prepare("SELECT * FROM site_alle_extensies WHERE site_id = ?");
        $stmt->execute([$siteId]);
    } else {
        $stmt = $pdo->query("SELECT * FROM site_alle_extensies");
    }
    $alleRijen = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Zelfde pakket-terugval als haalDerdePartijExtensies() en
    // haalAlleDerdePartijSamenvatting() hieronder: een pakket kan zelf geen
    // eigen update-locatie hebben terwijl een verborgen onderdeel ervan er
    // wél een heeft (bijv. JCFAQ). Per site apart bijhouden, want package_id
    // (Joomla's eigen extension_id van het pakket) is alleen uniek BINNEN
    // één site.
    $nieuwsteVersiePerSiteEnPakketId = [];
    foreach ($alleRijen as $rij) {
        $pakketId = (int) ($rij['package_id'] ?? 0);
        if ($pakketId > 0 && !empty($rij['nieuwste_versie'])) {
            $sId = $rij['site_id'];
            $nieuwsteVersiePerSiteEnPakketId[$sId][$pakketId] = kiesHoogsteNieuwsteVersie(
                $nieuwsteVersiePerSiteEnPakketId[$sId][$pakketId] ?? null,
                $rij['nieuwste_versie']
            );
        }
    }

    $ruw = [];
    foreach ($alleRijen as $rij) {
        if (isJoomlaKernExtensie($rij) || isOnderdeelVanPakket($rij) || isUitgeslotenVanExtensieoverzicht($rij)) {
            continue;
        }

        $nieuwsteVersie = $rij['nieuwste_versie'] ?? null;
        if (empty($nieuwsteVersie)) {
            $eigenExtensionId = (int) ($rij['extension_id'] ?? 0);
            if ($eigenExtensionId > 0 && isset($nieuwsteVersiePerSiteEnPakketId[$rij['site_id']][$eigenExtensionId])) {
                $nieuwsteVersie = $nieuwsteVersiePerSiteEnPakketId[$rij['site_id']][$eigenExtensionId];
            }
        }

        if (empty($nieuwsteVersie)) {
            continue; // dit hoort juist al thuis in de normale "zonder feed"-lijst.
        }

        $rij['nieuwste_versie'] = $nieuwsteVersie;
        $rij['status'] = isUpToDate($rij['versie'] ?? null, $nieuwsteVersie);
        $ruw[] = $rij;
    }

    $gegroepeerd = groepeerOpProduct($ruw);

    $bestaandeSleutels = array_fill_keys(
        array_column(haalExtensieCatalogusRuw($pdo), 'sleutel'),
        true
    );

    $resultaat = [];
    $gezienBinnenDezeAanroep = [];
    foreach ($gegroepeerd as $groep) {
        $sleutel = sleutelVoorGegroepeerdProduct($groep);
        if ($sleutel === '' || isset($bestaandeSleutels[$sleutel]) || isset($gezienBinnenDezeAanroep[$sleutel])) {
            continue;
        }
        $gezienBinnenDezeAanroep[$sleutel] = true;
        $groep['sleutel'] = $sleutel;
        $resultaat[] = $groep;
    }

    usort($resultaat, fn($a, $b) => strcmp($a['naam'], $b['naam']));

    return $resultaat;
}

/**
 * Ruwe lijst van alle sleutels die al in de catalogus staan (ongeacht
 * genegeerd/feed-status) - hulpfunctie voor de vorige functie.
 */
function haalExtensieCatalogusRuw(PDO $pdo): array
{
    return $pdo->query("SELECT sleutel FROM extensie_catalogus")->fetchAll(PDO::FETCH_ASSOC);
}

function haalDerdePartijExtensies(PDO $pdo, int $siteId, bool $toonGenegeerd = false): array
{
    $catalogus       = haalExtensieCatalogus($pdo);
    $nieuwsteVersies = haalNieuwsteVersies($pdo);

    $stmt = $pdo->prepare("
        SELECT naam, type, element, folder, client, enabled, versie, nieuwste_versie, update_feed_url, auteur, package_id, extension_id
        FROM site_alle_extensies
        WHERE site_id = ?
        ORDER BY naam ASC
    ");
    $stmt->execute([$siteId]);
    $alleRijen = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Sommige extensies registreren hun Joomla-update-locatie
    // (#__update_sites) niet op het PAKKET zelf, maar op een los ONDERDEEL
    // daarbinnen (bijv. JCFAQ: de update-site staat gekoppeld aan het
    // component com_jcfaq, niet aan het pakket pkg_jcfaq6 eromheen).
    // Pakketonderdelen worden hieronder normaal gesproken helemaal niet los
    // getoond (isOnderdeelVanPakket()) - zonder dit vangnet zou zo'n pakket
    // dus voor altijd "Onbekend" tonen, terwijl de nieuwste versie via het
    // onderdeel allang succesvol is opgehaald (scan_template.php checkt elke
    // extensie afzonderlijk, ongeacht of hij later als pakketonderdeel wordt
    // weggefilterd). Bij meerdere onderdelen met een eigen nieuwste versie
    // wint de hoogste, via dezelfde kiesHoogsteNieuwsteVersie() als elders
    // in dit bestand.
    $nieuwsteVersiePerPakketId = [];
    foreach ($alleRijen as $rij) {
        $pakketId = (int) ($rij['package_id'] ?? 0);
        if ($pakketId > 0 && !empty($rij['nieuwste_versie'])) {
            $nieuwsteVersiePerPakketId[$pakketId] = kiesHoogsteNieuwsteVersie(
                $nieuwsteVersiePerPakketId[$pakketId] ?? null,
                $rij['nieuwste_versie']
            );
        }
    }

    $ruw = [];
    foreach ($alleRijen as $rij) {
        if (isJoomlaKernExtensie($rij) || isOnderdeelVanPakket($rij) || isUitgeslotenVanExtensieoverzicht($rij)) {
            continue;
        }

        $nieuwsteVersie = $rij['nieuwste_versie'] ?? null;

        // Vangnet: dit is zelf geen pakketonderdeel (anders was hierboven al
        // overgeslagen), maar mogelijk wél zelf een pakket - kijk dan of een
        // van zijn (verborgen) onderdelen een nieuwste versie heeft gevonden.
        if ($nieuwsteVersie === null) {
            $eigenExtensionId = (int) ($rij['extension_id'] ?? 0);
            if ($eigenExtensionId > 0 && isset($nieuwsteVersiePerPakketId[$eigenExtensionId])) {
                $nieuwsteVersie = $nieuwsteVersiePerPakketId[$eigenExtensionId];
            }
        }

        // Terugval: als er nog steeds geen automatische nieuwste versie
        // bekend is (Joomla had zelf geen update-locatie geregistreerd voor
        // deze extensie, en ook geen pakketonderdeel had er een), kijken we
        // of er een handmatig ingevulde update-feed-URL in de catalogus
        // staat (zie extensie_beheer.php), en gebruiken we die -
        // haal_versies_op.php haalt die feed al op.
        if ($nieuwsteVersie === null) {
            $sleutel = maakExtensieSleutel($rij['type'] ?? '', $rij['element'] ?? '');
            if (isset($nieuwsteVersies[$sleutel])) {
                $nieuwsteVersie = $nieuwsteVersies[$sleutel];
            }
        }

        $sleutel = maakExtensieSleutel($rij['type'] ?? '', $rij['element'] ?? '');
        $negeerLaatsteDeel = !empty($catalogus[$sleutel]['negeer_laatste_versiedeel']);

        $rij['nieuwste_versie'] = $nieuwsteVersie;
        $rij['status'] = isUpToDate($rij['versie'] ?? null, $nieuwsteVersie, $negeerLaatsteDeel);
        $ruw[] = $rij;
    }

    $resultaat = groepeerOpProduct($ruw);

    // Genegeerde extensies (via Extensietabel beheren) meteen uitfilteren -
    // niet pas na een volgende scan. $toonGenegeerd (door extensies.php
    // doorgegeven vanuit de knop "Toon ook genegeerde extensies") bepaalt
    // of ze juist WEL getoond moeten worden - in dat geval blijven ze
    // staan, maar krijgt elke rij wél het 'genegeerd'-veld mee, zodat de
    // pagina de juiste badge en de juiste knop (Negeren vs. Herstel) kan
    // tonen. Vóór deze fix accepteerde deze functie helemaal geen derde
    // parameter, dus werd hij door PHP stilzwijgend genegeerd (geen fout,
    // gewoon een overtollig argument) - en werd 'genegeerd' nooit gezet,
    // waardoor de knop altijd "Negeren" toonde, nooit "Herstel".
    $genegeerdeSleutels = haalGenegeerdeSleutels($pdo);
    foreach ($resultaat as &$groep) {
        $groep['genegeerd'] = isGegroepeerdProductVolledigGenegeerd($groep, $genegeerdeSleutels);
    }
    unset($groep);

    if (!$toonGenegeerd) {
        $resultaat = array_values(array_filter(
            $resultaat,
            fn($groep) => !$groep['genegeerd']
        ));
    }

    // Niet-up-to-date bovenaan, dan onbekend, dan up-to-date.
    usort($resultaat, function ($a, $b) {
        $volgorde = fn($item) => $item['status'] === false ? 0 : ($item['status'] === null ? 1 : 2);
        return $volgorde($a) <=> $volgorde($b);
    });

    return $resultaat;
}

/**
 * Telt in één keer voor ALLE sites het aantal gedetecteerde extensies van
 * derden (na groepering op product) én hoeveel daarvan niet up-to-date
 * zijn, gegroepeerd per site_id. Voorkomt N losse queries op index.php.
 * Resultaat: [ site_id => ['totaal' => N, 'niet_up_to_date' => N], ... ]
 */
function haalAlleDerdePartijSamenvatting(PDO $pdo): array
{
    $catalogus       = haalExtensieCatalogus($pdo);
    $nieuwsteVersies = haalNieuwsteVersies($pdo);

    // Zelfde ORDER BY als haalDerdePartijExtensies() hierboven - noodzakelijk
    // voor consistentie: groepeerOpProduct() kiest bij gelijke prioriteit
    // (zie de toelichting in groepeerOpHerkomst()) het eerst-verwerkte
    // onderdeel als representatief voor de status van de hele groep. Zonder
    // exact dezelfde rijvolgorde als de detailpagina kon deze samenvatting
    // een ander onderdeel (en dus een andere status) kiezen voor dezelfde
    // site - met precies dit symptoom: de indexpagina toont "Deels
    // onbekend", terwijl de detailpagina van diezelfde site alles keurig
    // "Up-to-date" laat zien.
    $stmt = $pdo->query("SELECT site_id, naam, type, element, folder, client, enabled, auteur, versie, nieuwste_versie, update_feed_url, package_id, extension_id FROM site_alle_extensies ORDER BY naam ASC");
    $alleRijen = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Zelfde vangnet als haalDerdePartijExtensies() hierboven: een pakket
    // (bijv. pkg_jcfaq6) kan zelf geen eigen update-locatie hebben terwijl
    // een verborgen onderdeel ervan (bijv. com_jcfaq) er wél een heeft.
    // Per site apart bijhouden, want package_id (Joomla's eigen
    // extension_id van het pakket) is alleen uniek BINNEN één site.
    $nieuwsteVersiePerSiteEnPakketId = [];
    foreach ($alleRijen as $rij) {
        $pakketId = (int) ($rij['package_id'] ?? 0);
        if ($pakketId > 0 && !empty($rij['nieuwste_versie'])) {
            $siteId = $rij['site_id'];
            $nieuwsteVersiePerSiteEnPakketId[$siteId][$pakketId] = kiesHoogsteNieuwsteVersie(
                $nieuwsteVersiePerSiteEnPakketId[$siteId][$pakketId] ?? null,
                $rij['nieuwste_versie']
            );
        }
    }

    $perSite = [];
    foreach ($alleRijen as $rij) {
        if (isJoomlaKernExtensie($rij) || isOnderdeelVanPakket($rij) || isUitgeslotenVanExtensieoverzicht($rij)) {
            continue;
        }

        $nieuwsteVersie = $rij['nieuwste_versie'] ?? null;

        if ($nieuwsteVersie === null) {
            $eigenExtensionId = (int) ($rij['extension_id'] ?? 0);
            if ($eigenExtensionId > 0 && isset($nieuwsteVersiePerSiteEnPakketId[$rij['site_id']][$eigenExtensionId])) {
                $nieuwsteVersie = $nieuwsteVersiePerSiteEnPakketId[$rij['site_id']][$eigenExtensionId];
            }
        }

        if ($nieuwsteVersie === null) {
            $sleutel = maakExtensieSleutel($rij['type'] ?? '', $rij['element'] ?? '');
            if (isset($nieuwsteVersies[$sleutel])) {
                $nieuwsteVersie = $nieuwsteVersies[$sleutel];
            }
        }

        $sleutel = maakExtensieSleutel($rij['type'] ?? '', $rij['element'] ?? '');
        $negeerLaatsteDeel = !empty($catalogus[$sleutel]['negeer_laatste_versiedeel']);

        $rij['nieuwste_versie'] = $nieuwsteVersie;
        $rij['status'] = isUpToDate($rij['versie'] ?? null, $nieuwsteVersie, $negeerLaatsteDeel);

        $perSite[$rij['site_id']][] = $rij;
    }

    $genegeerdeSleutels = haalGenegeerdeSleutels($pdo);

    $resultaat = [];
    foreach ($perSite as $siteId => $extensies) {
        $gegroepeerd = groepeerOpProduct($extensies);
        $gegroepeerd = array_values(array_filter(
            $gegroepeerd,
            fn($groep) => !isGegroepeerdProductVolledigGenegeerd($groep, $genegeerdeSleutels)
        ));

        $resultaat[$siteId] = ['totaal' => count($gegroepeerd), 'niet_up_to_date' => 0, 'onbekend' => 0, 'up_to_date' => 0];

        foreach ($gegroepeerd as $groep) {
            if ($groep['status'] === false) {
                $resultaat[$siteId]['niet_up_to_date']++;
            } elseif ($groep['status'] === true) {
                $resultaat[$siteId]['up_to_date']++;
            } else {
                $resultaat[$siteId]['onbekend']++;
            }
        }
    }

    return $resultaat;
}
