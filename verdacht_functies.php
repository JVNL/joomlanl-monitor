<?php
/**
 * verdacht_functies.php
 *
 * Gedeelde hulpfuncties voor het verwerken van scanresultaten
 * (kolom verdacht_details) en het bijhouden van "vertrouwde" items.
 *
 * Wordt gebruikt door zowel index.php als beveiliging.php, zodat
 * beide pagina's exact dezelfde telling en herkenning gebruiken.
 */

/**
 * Schat het risico (0-100) van een vondst in op basis van de reden-tekst.
 * Zelfde soort classificatie als in het scanscript zelf (scan_template.php)
 * gebruikt wordt om de risicoscore al ín de scanuitvoer te embedden - deze
 * kopie hier dient als terugval voor oudere, al opgeslagen scanresultaten
 * van vóór de risicoscore werd toegevoegd (die hebben nog geen [risico=]
 * in hun opgeslagen tekst staan).
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
 * Geeft het label + CSS-klasse voor een risicoscore, voor de badge-weergave.
 */
function risicoLabel(int $score): array
{
    if ($score >= 90) {
        return ['ZEER HOOG', 'risico-zeerhoog'];
    }
    if ($score >= 70) {
        return ['HOOG', 'risico-hoog'];
    }
    if ($score >= 40) {
        return ['MIDDEL', 'risico-middel'];
    }
    return ['LAAG', 'risico-laag'];
}

/**
 * Een inhoud-vingerafdruk is de (ingekorte) sha256 van de bestandsinhoud,
 * zoals het scanscript die per vondst meestuurt: 32 hexadecimale tekens.
 * Alles wat daar niet precies aan voldoet, wordt behandeld als "geen
 * vingerafdruk bekend".
 */
function isGeldigeInhoudVingerafdruk($waarde): bool
{
    return is_string($waarde) && preg_match('/^[a-f0-9]{32}$/', $waarde) === 1;
}

/**
 * Bepaalt de sleutel waaraan een vondst bij een volgende scan wordt herkend
 * (en waarop "Vertrouwen" wordt vastgelegd). Eén plek voor deze regel, zodat
 * het beveiligingsrapport, de monitorpagina, het klantrapport, de e-mail en
 * het verwerken van een scan gegarandeerd hetzelfde antwoord geven.
 *
 *  - MAP en CLUSTER: alleen type + naam. De datum van een map verandert al
 *    zodra er een bestand in wordt aangemaakt of hernoemd; bij een cluster is
 *    de datum het moment van de scan zelf.
 *  - BESTAND MET inhoud-vingerafdruk: type + naam + inhoud. De wijzigingsdatum
 *    telt dan NIET mee: een bestand dat opnieuw is weggeschreven met exact
 *    dezelfde inhoud is niet gewijzigd, en hoort dus ook niet opnieuw gemeld
 *    te worden. Verandert de inhoud, dan verandert de sleutel en verschijnt
 *    het bestand weer als nieuw - ook als de datum daarbij gelijk is gebleven.
 *  - BESTAND ZONDER vingerafdruk (ouder scanscript, bestand niet leesbaar of
 *    te groot om te hashen): type + naam + wijzigingsdatum, zoals voorheen.
 */
function berekenVondstHash(string $type, string $naam, string $gewijzigd, ?string $inhoud = null): string
{
    if (in_array($type, ['map', 'cluster'], true)) {
        return md5($type . '|' . $naam);
    }

    if (isGeldigeInhoudVingerafdruk($inhoud)) {
        return md5($type . '|' . $naam . '|inhoud:' . $inhoud);
    }

    return md5($type . '|' . $naam . '|' . $gewijzigd);
}

/**
 * Bouwt één regel voor de kolom verdacht_details. De tegenhanger van
 * parseVerdachtDetails(): wat hier wordt weggeschreven, leest die functie
 * weer terug. "[inhoud=...]" komt er alleen bij als er een geldige
 * vingerafdruk is.
 */
function maakVondstRegel(string $type, string $naam, string $gewijzigd, string $reden, int $risico, ?string $inhoud = null): string
{
    $regel = "[{$type}] {$naam} ({$gewijzigd}) - {$reden} [risico={$risico}]";

    if (isGeldigeInhoudVingerafdruk($inhoud)) {
        $regel .= " [inhoud={$inhoud}]";
    }

    return $regel;
}

/**
 * Parseert de ruwe verdacht_details-tekst (regels in het formaat
 * "[type] naam (gewijzigd) - reden [risico=N] [inhoud=H]") naar een array
 * van items. Elk item krijgt een stabiele hash (zie berekenVondstHash()),
 * zodat we hetzelfde item bij een volgende scan kunnen herkennen.
 *
 * De stukjes "[risico=N]" en "[inhoud=H]" aan het einde zijn allebei
 * optioneel, voor achterwaartse compatibiliteit met al opgeslagen
 * scanresultaten en met sites waar nog een ouder scanscript draait:
 *  - ontbreekt het risico, dan wordt het berekend uit de reden-tekst;
 *  - ontbreekt de inhoud-vingerafdruk, dan telt de wijzigingsdatum mee in de
 *    hash, zoals voorheen.
 */
function parseVerdachtDetails(?string $details): array
{
    $items = [];
    $details = trim($details ?? '');

    if ($details === '') {
        return $items;
    }

    $regels = preg_split('/\r\n|\r|\n/', $details);

    foreach ($regels as $regel) {
        $regel = trim($regel);
        if ($regel === '') {
            continue;
        }

        if (preg_match('/^\[(.+?)\]\s+(.+?)\s+\((.*?)\)\s+-\s+(.*?)(?:\s+\[risico=(\d+)\])?(?:\s+\[inhoud=([a-f0-9]{32})\])?$/u', $regel, $m)) {
            $type      = $m[1];
            $naam      = $m[2];
            $gewijzigd = $m[3];
            $reden     = $m[4];
            $risico    = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : bepaalRisico($reden);
            $inhoud    = isset($m[6]) && $m[6] !== '' ? $m[6] : null;
        } else {
            // Onbekend formaat: toon de ruwe regel toch, zodat er niets verloren gaat.
            $type      = '-';
            $naam      = $regel;
            $gewijzigd = '-';
            $reden     = '-';
            $risico    = 50;
            $inhoud    = null;
        }

        // Bij een MAP en bij een CLUSTER (verzamelmelding over meerdere
        // gelijkgrote bestanden, zie vindMassaleUpload()/vindMassaleHernoeming()
        // in scan_template.php) telt de wijzigingsdatum bewust niet mee in de
        // hash:
        //  - bij een MAP verandert die datum al zodra er simpelweg een
        //    bestand aan wordt toegevoegd of verwijderd (heel normaal voor
        //    bijv. een eigen downloadmap), zonder dat de map zelf ergens
        //    verdacht om is geworden;
        //  - bij een CLUSTER is de meegegeven datum sowieso altijd het
        //    moment van de scan zelf, NIET een echte bestandsdatum (de
        //    melding gaat over meerdere, los van elkaar gewijzigde
        //    bestanden tegelijk, dus er is geen eenduidige "ene" datum om
        //    te tonen). Zonder deze uitzondering veranderde de hash dus
        //    letterlijk bij ELKE scan, waardoor eenzelfde, ongewijzigde
        //    clustermelding na het klikken op "Vertrouwen" bij de
        //    eerstvolgende scan alsnog weer als nieuw verscheen - ontdekt
        //    en gemeld in augustus 2026.
        // Bij een BESTAND telt de INHOUD: verandert die bij een bestand dat
        // je eerder vertrouwde, dan is dat wél terecht een reden om opnieuw
        // te waarschuwen. Alleen als het scanscript geen inhoud-vingerafdruk
        // kon meesturen, valt dit terug op de wijzigingsdatum - zie
        // berekenVondstHash() voor de volledige regel.
        $hash = berekenVondstHash($type, $naam, $gewijzigd, $inhoud);

        $items[] = [
            'hash'      => $hash,
            'type'      => $type,
            'naam'      => $naam,
            'gewijzigd' => $gewijzigd,
            'reden'     => $reden,
            'risico'    => $risico,
            'inhoud'    => $inhoud,
        ];
    }

    return $items;
}

/**
 * Naam van de verzamelmelding die het scanscript stuurt als de bestandsscan zijn tijds- of aantalsgrens heeft gehaald
 * (zie de afronding van scanRecursief() in scan_template.php).
 */
const SCAN_ONVOLLEDIG_NAAM = 'SCAN ONVOLLEDIG - scanbudget bereikt';

/**
 * Haalt de melding "SCAN ONVOLLEDIG" uit de lijst met vondsten en geeft de voortgang van de scan terug.
 *
 * Die melding is geen verdacht bestand, maar zegt dat de laatste scan niet alles heeft kunnen bekijken. Telde hij
 * mee als vondst, dan stond er op de monitorpagina "1 verdacht" bij een site waar niets verdachts is gevonden, en kon
 * hij met "Vertrouwen" worden weggeklikt terwijl de scan nog steeds onvolledig was. Daarom gaat hij er hier uit, op
 * één plek, en tonen de monitorpagina, het beveiligingsrapport, het klantrapport en de e-mail hem als voortgang
 * ("Scan afgerond voor N%"). De opgeslagen tekst zelf (verdacht_details) blijft ongewijzigd.
 *
 * Het percentage staat sinds 1.33 in de reden ("38% van de bestanden bekeken", of "hoogstens 38% ..." als ook het
 * tellen van de rest is afgebroken). Een scanscript van vóór 1.33 stuurt de melding zonder percentage: dan is
 * 'procent' null en tonen de pagina's alleen "scan onvolledig".
 *
 * @param array $items Uitvoer van parseVerdachtDetails(); de melding wordt hier uit verwijderd.
 * @return array{procent: ?int, bovengrens: bool, reden: string, gewijzigd: string}|null null = scan was volledig
 */
function haalScanVoortgangUitItems(array &$items): ?array
{
    $voortgang = null;

    foreach ($items as $sleutel => $item) {
        if (($item['type'] ?? '') !== 'cluster' || ($item['naam'] ?? '') !== SCAN_ONVOLLEDIG_NAAM) {
            continue;
        }

        $reden = (string) ($item['reden'] ?? '');
        $procent = null;
        $bovengrens = false;
        if (preg_match('/(hoogstens )?(\d{1,3})% van de bestanden bekeken/u', $reden, $m)) {
            $procent = min(99, (int) $m[2]);
            $bovengrens = $m[1] !== '';
        }

        $voortgang = [
            'procent' => $procent,
            'bovengrens' => $bovengrens,
            'reden' => $reden,
            'gewijzigd' => (string) ($item['gewijzigd'] ?? ''),
        ];
        unset($items[$sleutel]);
    }

    $items = array_values($items);

    return $voortgang;
}

/**
 * Korte tekst voor de voortgang, bijvoorbeeld "Scan afgerond voor 38%" of "Scan afgerond voor hoogstens 38%".
 */
function scanVoortgangTekst(array $voortgang): string
{
    if ($voortgang['procent'] === null) {
        return 'Scan onvolledig';
    }

    return 'Scan afgerond voor ' . ($voortgang['bovengrens'] ? 'hoogstens ' : '') . $voortgang['procent'] . '%';
}

/**
 * Verwijdert één specifieke vondst (op basis van het pad/naam) direct uit
 * de opgeslagen verdacht_details van een site, en werkt verdacht_aantal
 * bij. Wordt aangeroepen door site_beheer_actie.php na een geslaagde
 * quarantaine/blokkeer/verwijder-actie, zodat de beveiligingspagina meteen
 * bijgewerkt is zonder op de volgende volledige scan te hoeven wachten.
 */
function verwijderVondstUitOpslag(PDO $pdo, int $siteId, string $naam): void
{
    $stmt = $pdo->prepare("SELECT verdacht_details FROM sites WHERE id = ?");
    $stmt->execute([$siteId]);
    $huidigeDetails = $stmt->fetchColumn();

    if ($huidigeDetails === false || $huidigeDetails === null) {
        return;
    }

    $items = parseVerdachtDetails($huidigeDetails);
    $overgebleven = array_filter($items, function ($item) use ($naam) {
        return $item['naam'] !== $naam;
    });

    $nieuweRegels = [];
    foreach ($overgebleven as $item) {
        // Via maakVondstRegel(), zodat de inhoud-vingerafdruk van de overgebleven
        // vondsten behouden blijft - anders zouden die na deze actie ineens een
        // andere hash krijgen en weer als "nieuw" verschijnen.
        $nieuweRegels[] = maakVondstRegel($item['type'], $item['naam'], $item['gewijzigd'], $item['reden'], (int) $item['risico'], $item['inhoud'] ?? null);
    }

    $update = $pdo->prepare("UPDATE sites SET verdacht_details = ?, verdacht_aantal = ? WHERE id = ?");
    $update->execute([implode("\n", $nieuweRegels), count($nieuweRegels), $siteId]);
}

/**
 * Haalt de verzameling vertrouwde item-hashes op voor één site.
 * Geeft een array terug met de hash als key (voor snelle O(1)-lookup).
 */
function haalVertrouwdeHashes(PDO $pdo, int $siteId): array
{
    $stmt = $pdo->prepare("SELECT item_hash FROM verdacht_vertrouwd WHERE site_id = ?");
    $stmt->execute([$siteId]);
    $hashes = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return array_fill_keys($hashes, true);
}

/**
 * Haalt in één keer voor ALLE sites de vertrouwde hashes op, gegroepeerd
 * per site_id. Voorkomt dat index.php per site een aparte query moet doen.
 *
 * Resultaat: [ site_id => [ hash => true, ... ], ... ]
 */
function haalAlleVertrouwdeHashes(PDO $pdo): array
{
    $resultaat = [];

    $stmt = $pdo->query("SELECT site_id, item_hash FROM verdacht_vertrouwd");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
        $resultaat[$rij['site_id']][$rij['item_hash']] = true;
    }

    return $resultaat;
}

/**
 * Zet bestaand vertrouwen eenmalig over van de oude sleutel (met de
 * wijzigingsdatum erin) naar de nieuwe sleutel (met de inhoud erin), voor
 * elke vondst waarvan het scanscript nu een inhoud-vingerafdruk meestuurt.
 *
 * Zonder dit zou na het bijwerken van het scanscript ELK eerder vertrouwd
 * bestand, op elke site, één keer opnieuw als "nieuw" verschijnen - de
 * sleutel verandert immers van vorm, ook al is er aan het bestand niets
 * veranderd.
 *
 * Wanneer is overzetten terecht? Alleen als de vondst op dit moment, met de
 * wijzigingsdatum van nu, onder de OUDE sleutel vertrouwd is. Dat is precies
 * de situatie waarin de monitor het bestand vóór deze wijziging ook al als
 * vertrouwd zou hebben getoond - er wordt dus niets vertrouwd dat eerder
 * niet vertrouwd was. Een bestand waarvan de datum sinds het vertrouwen is
 * veranderd, wordt NIET overgezet: daarvan is niet meer na te gaan of de
 * inhoud nog dezelfde is, dus dat moet één keer opnieuw worden beoordeeld.
 *
 * De oude rij wordt hierbij VERPLAATST (niet gekopieerd): vanaf dat moment
 * is het vertrouwen aan de inhoud gekoppeld en is er geen weg terug meer
 * naar "zelfde datum is genoeg".
 *
 * @param array $items Uitvoer van parseVerdachtDetails() voor deze site.
 * @return int Aantal overgezette vondsten.
 */
function zetVertrouwenOverOpInhoud(PDO $pdo, int $siteId, array $items): int
{
    $vertrouwd = haalVertrouwdeHashes($pdo, $siteId);
    if (empty($vertrouwd)) {
        return 0;
    }

    $verplaatsStmt = $pdo->prepare("UPDATE verdacht_vertrouwd SET item_hash = ? WHERE site_id = ? AND item_hash = ?");
    $aantal = 0;

    foreach ($items as $item) {
        if (!isGeldigeInhoudVingerafdruk($item['inhoud'] ?? null)) {
            continue; // geen vingerafdruk: deze vondst gebruikt de oude sleutel nog gewoon
        }
        if (isset($vertrouwd[$item['hash']])) {
            continue; // al vertrouwd op inhoud
        }

        $oudeHash = berekenVondstHash($item['type'], $item['naam'], $item['gewijzigd'], null);
        if ($oudeHash === $item['hash'] || !isset($vertrouwd[$oudeHash])) {
            continue;
        }

        $verplaatsStmt->execute([$item['hash'], $siteId, $oudeHash]);

        unset($vertrouwd[$oudeHash]);
        $vertrouwd[$item['hash']] = true;
        $aantal++;
    }

    return $aantal;
}

/**
 * Voegt vondsten met precies dezelfde melding (type + risico + reden, en dezelfde vertrouwd-status) samen tot één
 * groep, zodra die melding minstens $drempel keer voorkomt. Aanleiding: een zwaar besmette site met ruim 4.500
 * identieke .htaccess-meldingen gaf een onwerkbare lijst en een klantrapport van ruim 500 pagina's.
 *
 * Geeft een lijst blokken terug, in de volgorde van de eerste vondst van elk blok:
 *  - ['item' => $item]                                  voor een losse vondst;
 *  - ['leden' => [$item, ...], 'eerste' => $item,
 *     'van' => 'oudste datum', 'tot' => 'nieuwste datum'] voor een groep.
 * De vondsten zelf blijven ongewijzigd; vertrouwen en beheeracties werken dus gewoon per bestand.
 */
function groepeerGelijkeVondsten(array $items, int $drempel = 5, array $vertrouwdHashes = []): array
{
    $sleutelVan = function (array $item) use ($vertrouwdHashes): string {
        return strtolower((string) ($item['type'] ?? '')) . '|' . (int) ($item['risico'] ?? 0) . '|' . (string) ($item['reden'] ?? '')
            . '|' . (isset($vertrouwdHashes[$item['hash'] ?? '']) ? 'v' : 'n');
    };

    $perSleutel = [];
    foreach ($items as $item) {
        $perSleutel[$sleutelVan($item)][] = $item;
    }

    $blokken = [];
    $gehad = [];
    foreach ($items as $item) {
        $sleutel = $sleutelVan($item);
        if (count($perSleutel[$sleutel]) < $drempel) {
            $blokken[] = ['item' => $item];
            continue;
        }
        if (isset($gehad[$sleutel])) {
            continue;
        }
        $gehad[$sleutel] = true;
        $datums = array_values(array_filter(array_map(fn($i) => (string) ($i['gewijzigd'] ?? ''), $perSleutel[$sleutel]),
            fn($d) => $d !== '' && $d !== '-'));
        sort($datums);
        $blokken[] = [
            'leden'  => $perSleutel[$sleutel],
            'eerste' => $item,
            'van'    => $datums[0] ?? '-',
            'tot'    => $datums[count($datums) - 1] ?? '-',
        ];
    }

    return $blokken;
}
