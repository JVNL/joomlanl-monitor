<?php
// kern_integriteit_functies.php
//
// Vergelijkt Joomla-kernbestanden met het OFFICIËLE, ongewijzigde
// Joomla-pakket van downloads.joomla.org - als aanvulling op de bestaande
// meerderheidsvergelijking tussen sites (zie vergelijk_extensie_bestanden.php).
//
// Waarom een aparte vergelijking naast de meerderheidsvergelijking nodig is:
//   1. Een Joomla-kernversie die maar op 1 site voorkomt, heeft geen enkele
//      andere site om tegen te vergelijken - de meerderheidsvergelijking
//      heeft minimaal 2 sites nodig om iets te kunnen zeggen.
//   2. Zou hetzelfde kernbestand op meerdere sites tegelijk (identiek)
//      gecompromitteerd zijn, dan wint die afwijkende hash gewoon de
//      meerderheid en valt er bij de bestaande vergelijking niets op.
// Vergelijken met het officiële pakket dekt beide gevallen, zonder een
// eigen, handmatig te onderhouden hash-database - het pakket wordt gewoon
// vers bij Joomla zelf opgehaald.
//
// Het pakket wordt hoogstens één keer per Joomla-kernversie gedownload
// (niet per site, niet per scan) en centraal op de monitorserver
// gecached in de database - klantsites downloaden zelf nooit iets.

require_once __DIR__ . '/config.php';

/**
 * Bepaalt of een relatief pad een "echt" kernbestand is waarvoor een
 * officiële-pakket-vergelijking zinvol is. Bewust dezelfde soort
 * uitsluitingen als bij CoreRestoreHelper-achtige implementaties elders
 * (templates/images/cache/tmp/logs hebben geen vaste, vergelijkbare
 * inhoud, of horen sowieso al niet bij "kern" in de zin van deze check).
 */
function isEchtKernPad(string $pad): bool
{
    $pad = str_replace('\\', '/', $pad);
    $pad = ltrim($pad, '/');

    if (strtolower(pathinfo($pad, PATHINFO_EXTENSION)) !== 'php') {
        return false; // alleen .php - zelfde redenering als hashPhpBestandenInMap()
    }

    $uitgesloten = [
        'installation/',
        'images/',
        'tmp/',
        'cache/',
        'logs/',
        'administrator/cache/',
        'administrator/logs/',
        'templates/',
        'administrator/templates/',
        'media/templates/',
    ];
    foreach ($uitgesloten as $voorvoegsel) {
        if (strpos($pad, $voorvoegsel) === 0) {
            return false;
        }
    }

    // configuration.php is per site uniek (bevat wachtwoorden) en hoort
    // sowieso nooit vergeleken te worden met een "officiële" versie.
    if (in_array(strtolower(basename($pad)), ['configuration.php'], true)) {
        return false;
    }

    return (bool) preg_match(
        '#^(libraries/|includes/|api/|cli/|administrator/includes/|administrator/components/com_|components/com_|plugins/|media/vendor/|media/system/|administrator/modules/mod_|modules/mod_|layouts/)#',
        $pad
    ) || in_array($pad, ['index.php', 'administrator/index.php'], true);
}

/**
 * Bouwt (indien nog niet aanwezig) de officiële hash-tabel voor een
 * Joomla-kernversie op, en geeft 'm terug als [relatief_pad => hash].
 * Downloadt het officiële pakket hoogstens één keer per versie - een
 * volgende aanroep voor dezelfde versie leest gewoon uit de database.
 *
 * @return array{ok: bool, hashes: array<string,string>, foutmelding: ?string}
 */
function haalOfficieleKernHashes(PDO $pdo, string $kernversie): array
{
    if (!preg_match('/^\d+\.\d+\.\d+$/', $kernversie)) {
        return ['ok' => false, 'hashes' => [], 'foutmelding' => "Onherkenbaar versienummer: $kernversie"];
    }

    $bestaatStmt = $pdo->prepare("SELECT relatief_pad, hash FROM kern_officiele_hashes WHERE kernversie = ?");
    $bestaatStmt->execute([$kernversie]);
    $bestaande = $bestaatStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    if (!empty($bestaande)) {
        return ['ok' => true, 'hashes' => $bestaande, 'foutmelding' => null];
    }

    // Nog niet eerder gezien voor deze versie - eenmalig ophalen en cachen.
    $major = strtok($kernversie, '.');
    $slug = str_replace('.', '-', $kernversie);
    $url = "https://downloads.joomla.org/cms/joomla{$major}/{$slug}/Joomla_{$kernversie}-Stable-Full_Package.zip?format=zip";

    $tmpBestand = tempnam(sys_get_temp_dir(), 'joomla_officieel_') . '.zip';

    $ch = curl_init($url);
    $fh = fopen($tmpBestand, 'wb');
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $gelukt = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlFout = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    if (!$gelukt || $httpCode !== 200 || filesize($tmpBestand) < 100000) {
        @unlink($tmpBestand);
        $reden = $curlFout ?: "HTTP $httpCode";
        return ['ok' => false, 'hashes' => [], 'foutmelding' => "Kon officieel Joomla $kernversie pakket niet downloaden: $reden"];
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpBestand) !== true) {
        @unlink($tmpBestand);
        return ['ok' => false, 'hashes' => [], 'foutmelding' => 'Gedownload pakket kon niet als zip geopend worden.'];
    }

    $hashes = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat) || empty($stat['name']) || substr($stat['name'], -1) === '/') {
            continue;
        }
        $relatiefPad = ltrim(str_replace('\\', '/', $stat['name']), '/');
        if (!isEchtKernPad($relatiefPad)) {
            continue;
        }
        $inhoud = $zip->getFromIndex($i);
        if ($inhoud === false) {
            continue;
        }
        $hashes[$relatiefPad] = hash('sha256', $inhoud);
    }
    $zip->close();
    @unlink($tmpBestand);

    if (empty($hashes)) {
        return ['ok' => false, 'hashes' => [], 'foutmelding' => 'Pakket gedownload, maar geen bruikbare kernbestanden erin gevonden.'];
    }

    // Wegschrijven naar de cache-tabel, zodat een volgende aanroep (andere
    // site, of de vergelijkstap zelf) niet opnieuw hoeft te downloaden.
    $invoegStmt = $pdo->prepare("
        INSERT INTO kern_officiele_hashes (kernversie, relatief_pad, hash)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE hash = VALUES(hash)
    ");
    $pdo->beginTransaction();
    foreach ($hashes as $pad => $hash) {
        $invoegStmt->execute([$kernversie, $pad, $hash]);
    }
    $pdo->commit();

    return ['ok' => true, 'hashes' => $hashes, 'foutmelding' => null];
}

/**
 * Vergelijkt alle in `extensie_bestand_hashes` aanwezige kern_joomla_*-
 * groepen met de officiële referentie, en slaat afwijkingen op in
 * `kern_bestand_afwijkingen`. Wordt net als vergelijk_extensie_bestanden.php
 * als losse stap aangeroepen, ná ontvang_scan.php.
 *
 * @return array{vergeleken_groepen: int, afwijkingen: int, download_fouten: string[]}
 */
function vergelijkKernBestandenMetOfficieel(PDO $pdo): array
{
    $groepenStmt = $pdo->query("SELECT DISTINCT groep_sleutel FROM extensie_bestand_hashes WHERE groep_sleutel LIKE 'kern_joomla\_%' ESCAPE '\\\\'");
    $groepen = $groepenStmt->fetchAll(PDO::FETCH_COLUMN);

    $pdo->exec("TRUNCATE TABLE kern_bestand_afwijkingen");

    $invoegStmt = $pdo->prepare("
        INSERT INTO kern_bestand_afwijkingen
            (site_id, kernversie, relatief_pad, eigen_hash, officiele_hash, status)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $vergelekenGroepen = 0;
    $aantalAfwijkingen = 0;
    $downloadFouten = [];

    foreach ($groepen as $groepSleutel) {
        // groepSleutel-vorm: kern_joomla_6_1_2  ->  6.1.2
        $kernversie = str_replace('_', '.', substr($groepSleutel, strlen('kern_joomla_')));
        if (!preg_match('/^\d+\.\d+\.\d+$/', $kernversie)) {
            continue;
        }

        $officieel = haalOfficieleKernHashes($pdo, $kernversie);
        if (!$officieel['ok']) {
            $downloadFouten[] = "$kernversie: {$officieel['foutmelding']}";
            continue; // geen referentie beschikbaar - deze groep overslaan, niet crashen
        }
        $vergelekenGroepen++;

        $bestandenStmt = $pdo->prepare("
            SELECT site_id, relatief_pad, hash
            FROM extensie_bestand_hashes
            WHERE groep_sleutel = ?
        ");
        $bestandenStmt->execute([$groepSleutel]);

        // BEWUST GEEN "ontbreekt"-detectie (een officieel bestand dat niet
        // in de site-hashes voorkomt): het scanscript hasht, om het
        // tijdsbudget van een scan behapbaar te houden, niet gegarandeerd
        // de VOLLEDIGE Joomla-kern (zie $maxTotaalBestanden in
        // scan_template.php, en de padgrenzen van isEchtKernPad()) - "niet
        // gehasht" en "niet aanwezig op de site" zijn dus twee andere
        // dingen, en zonder betrouwbare dekkingsgarantie is dat onderscheid
        // hier niet te maken. Een eerdere versie deed dit wél en gaf op een
        // gewone site meteen tienduizenden valse meldingen. Alleen
        // bestanden die de site DAADWERKELIJK heeft gehasht én die
        // aantoonbaar afwijken van het origineel, zijn een betrouwbaar
        // signaal - ongeacht hoeveel van de kern er verder wel/niet is
        // meegenomen.
        //
        // BEWUST OOK VERTROUWDE AFWIJKINGEN GEWOON MEEGENOMEN: "vertrouwen"
        // (kern_vertrouwd) is een WEERGAVE-keuze, geen vastleggingskeuze -
        // vertrouwde items moeten bereikbaar blijven (voor de "Toon ook
        // vertrouwde items"-sectie op beveiliging.php, en om ze later
        // alsnog te kunnen laten vervangen), dus die worden hier niet
        // overgeslagen.
        foreach ($bestandenStmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
            if (!isset($officieel['hashes'][$rij['relatief_pad']])) {
                continue; // niet (meer) een kernbestand volgens de officiële lijst - geen oordeel
            }

            if ($rij['hash'] !== $officieel['hashes'][$rij['relatief_pad']]) {
                $invoegStmt->execute([
                    $rij['site_id'],
                    $kernversie,
                    $rij['relatief_pad'],
                    $rij['hash'],
                    $officieel['hashes'][$rij['relatief_pad']],
                    'gewijzigd',
                ]);
                $aantalAfwijkingen++;
            }
        }
    }

    return [
        'vergeleken_groepen' => $vergelekenGroepen,
        'afwijkingen' => $aantalAfwijkingen,
        'download_fouten' => $downloadFouten,
    ];
}

/**
 * Haalt de inhoud van ÉÉN specifiek officieel kernbestand op, voor de
 * "Bekijk verschil"-pagina (bekijk_kern_afwijking.php). Downloadt daarvoor
 * (net als haalOfficieleKernHashes()) het officiële pakket - dat gebeurt
 * hier bewust NIET blijvend gecached op schijf (in lijn met de rest van
 * deze codebase, die overal tempnam()+opruimen gebruikt i.p.v. een eigen
 * cache-map): dit is een zeldzame, handmatige actie (één klik door een
 * beheerder), geen onderdeel van de reguliere scan, dus de kosten van een
 * herdownload wegen niet op tegen de extra complexiteit van blijvende
 * schijfcaching.
 *
 * @param bool $volledig Bij true geen 64KB-afkapping toepassen - nodig voor
 *   de "automatisch vervangen"-actie (kern_bestand_actie.php), die het
 *   ONVERKORTE bestand moet terugschrijven. Bij false (standaard, voor de
 *   "Bekijk verschil"-weergave) wordt dezelfde grens gebruikt als de
 *   "Bekijk"-actie in scan_template.php, zodat beide kanten van de
 *   vergelijking eerlijk op dezelfde manier afgekapt worden.
 * @return array{ok: bool, inhoud: string, afgekapt: bool, foutmelding: ?string}
 */
function haalOfficieelBestandInhoud(string $kernversie, string $relatiefPad, bool $volledig = false): array
{
    $leeg = ['ok' => false, 'inhoud' => '', 'afgekapt' => false, 'foutmelding' => null];

    if (!preg_match('/^\d+\.\d+\.\d+$/', $kernversie)) {
        $leeg['foutmelding'] = "Onherkenbaar versienummer: $kernversie";
        return $leeg;
    }

    $major = strtok($kernversie, '.');
    $slug = str_replace('.', '-', $kernversie);
    $url = "https://downloads.joomla.org/cms/joomla{$major}/{$slug}/Joomla_{$kernversie}-Stable-Full_Package.zip?format=zip";

    $tmpBestand = tempnam(sys_get_temp_dir(), 'joomla_officieel_') . '.zip';

    $ch = curl_init($url);
    $fh = fopen($tmpBestand, 'wb');
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $gelukt = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlFout = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    if (!$gelukt || $httpCode !== 200 || filesize($tmpBestand) < 100000) {
        @unlink($tmpBestand);
        $leeg['foutmelding'] = "Kon officieel Joomla $kernversie pakket niet downloaden: " . ($curlFout ?: "HTTP $httpCode");
        return $leeg;
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpBestand) !== true) {
        @unlink($tmpBestand);
        $leeg['foutmelding'] = 'Gedownload pakket kon niet als zip geopend worden.';
        return $leeg;
    }

    $inhoud = $zip->getFromName($relatiefPad);
    $zip->close();
    @unlink($tmpBestand);

    if ($inhoud === false) {
        $leeg['foutmelding'] = "Bestand \"$relatiefPad\" staat niet (meer) in het officiële Joomla $kernversie pakket.";
        return $leeg;
    }

    // Zelfde grens als de "Bekijk"-actie in scan_template.php (65536 bytes),
    // zodat beide zijden van de vergelijking eerlijk op dezelfde manier
    // afgekapt worden - overgeslagen als de volledige inhoud is gevraagd.
    $max = 65536;
    $afgekapt = !$volledig && strlen($inhoud) > $max;
    if ($afgekapt) {
        $inhoud = substr($inhoud, 0, $max);
    }

    return ['ok' => true, 'inhoud' => $inhoud, 'afgekapt' => $afgekapt, 'foutmelding' => null];
}

/**
 * Eenvoudige, regelgebaseerde diff tussen twee tekstbestanden (LCS-
 * algoritme). Bedoeld voor de "Bekijk verschil"-pagina - beide teksten zijn
 * daar al vooraf begrensd tot 65536 bytes (zie hierboven en scan_template.php),
 * dus de O(n×m)-tijd/geheugenkost hiervan blijft behapbaar voor een
 * zeldzame, handmatige actie.
 *
 * @return list<array{type: 'gelijk'|'toegevoegd'|'verwijderd', regel: string}>
 */
function berekenRegelDiff(string $oud, string $nieuw): array
{
    $oudeRegels = explode("\n", $oud);
    $nieuweRegels = explode("\n", $nieuw);
    $n = count($oudeRegels);
    $m = count($nieuweRegels);

    $lengte = [];
    for ($i = 0; $i <= $n; $i++) {
        $lengte[$i] = array_fill(0, $m + 1, 0);
    }
    for ($i = $n - 1; $i >= 0; $i--) {
        for ($j = $m - 1; $j >= 0; $j--) {
            $lengte[$i][$j] = ($oudeRegels[$i] === $nieuweRegels[$j])
                ? $lengte[$i + 1][$j + 1] + 1
                : max($lengte[$i + 1][$j], $lengte[$i][$j + 1]);
        }
    }

    $resultaat = [];
    $i = 0;
    $j = 0;
    while ($i < $n && $j < $m) {
        if ($oudeRegels[$i] === $nieuweRegels[$j]) {
            $resultaat[] = ['type' => 'gelijk', 'regel' => $oudeRegels[$i]];
            $i++;
            $j++;
        } elseif ($lengte[$i + 1][$j] >= $lengte[$i][$j + 1]) {
            $resultaat[] = ['type' => 'verwijderd', 'regel' => $oudeRegels[$i]];
            $i++;
        } else {
            $resultaat[] = ['type' => 'toegevoegd', 'regel' => $nieuweRegels[$j]];
            $j++;
        }
    }
    while ($i < $n) {
        $resultaat[] = ['type' => 'verwijderd', 'regel' => $oudeRegels[$i]];
        $i++;
    }
    while ($j < $m) {
        $resultaat[] = ['type' => 'toegevoegd', 'regel' => $nieuweRegels[$j]];
        $j++;
    }

    return $resultaat;
}

/**
 * Geeft een LEESBAAR, niet-technisch oordeel over een berekende diff, zodat
 * iemand zonder Joomla-kennis niet zelf hoeft te bepalen of een wijziging
 * verdacht is. Kijkt uitsluitend naar TOEGEVOEGDE regels (wat de site heeft
 * dat het officiële pakket niet heeft) - dat is de kant die relevant is bij
 * een backdoor. Dezelfde soort signalen als elders in de scanner
 * (checkKernIntegriteit()/scanPhpVoorBackdoors() in scan_template.php),
 * hier ingezet op een klein, al-geïsoleerd stukje tekst i.p.v. een hele
 * bestandsboom.
 *
 * BELANGRIJK: dit is een hulpmiddel, geen garantie. Het geeft geen
 * "veilig"-oordeel bij het uitblijven van een treffer - alleen een concrete
 * waarschuwing bij een treffer.
 *
 * @return array{niveau: 'rood'|'geel', reden: ?string}
 */
function beoordeelDiffOpVerdachtePatronen(array $diffRegels): array
{
    $toegevoegd = implode("\n", array_map(
        fn($r) => $r['regel'],
        array_filter($diffRegels, fn($r) => $r['type'] === 'toegevoegd')
    ));

    if (trim($toegevoegd) === '') {
        // Alleen regels verwijderd, niets toegevoegd - geen nieuw uit te
        // voeren code, dus geen reden voor een rode waarschuwing.
        return ['niveau' => 'geel', 'reden' => null];
    }

    $verdachtePatronen = [
        'eval() - kan willekeurige code uitvoeren' => '/\beval\s*\(/i',
        'base64_decode() - vaak gebruikt om code te verbergen' => '/base64_decode\s*\(/i',
        'assert() gebruikt als code-uitvoering' => '/\bassert\s*\(/i',
        'shell-/procesuitvoeringsfunctie (system/exec/shell_exec/passthru/proc_open)' => '/\b(system|exec|shell_exec|passthru|proc_open)\s*\(/i',
        'stream-wrapper-truc (zip://, phar://, data://) - vaak gebruikt om een payload te verbergen' => '/(zip|phar|compress\.zlib|compress\.bzip2|data):\/\//i',
        'tekst opgebouwd via chr() i.c.m. gebruikersinvoer - obfuscatietechniek' => '/chr\s*\(\s*\d{1,3}\s*\)/i',
    ];

    foreach ($verdachtePatronen as $label => $patroon) {
        if (preg_match($patroon, $toegevoegd)) {
            return ['niveau' => 'rood', 'reden' => $label];
        }
    }

    return ['niveau' => 'geel', 'reden' => null];
}
