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
 * Parseert de ruwe verdacht_details-tekst (regels in het formaat
 * "[type] naam (gewijzigd) - reden [risico=N]") naar een array van items.
 * Elk item krijgt een stabiele hash op basis van type + naam + gewijzigd,
 * zodat we hetzelfde item bij een volgende scan kunnen herkennen.
 *
 * Het "[risico=N]"-stukje aan het einde is optioneel, voor achterwaartse
 * compatibiliteit met scanresultaten van vóór de risicoscore-functionaliteit -
 * ontbreekt het, dan wordt het risico alsnog berekend uit de reden-tekst.
 *
 * Let op: als het wijzigingstijdstip van een bestand verandert (het
 * bestand is dus opnieuw aangepast), krijgt het een NIEUWE hash en
 * wordt het dus terecht weer als (nieuw) verdacht getoond, ook al was
 * de oude versie ooit vertrouwd.
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

        if (preg_match('/^\[(.+?)\]\s+(.+?)\s+\((.*?)\)\s+-\s+(.*?)(?:\s+\[risico=(\d+)\])?$/u', $regel, $m)) {
            $type      = $m[1];
            $naam      = $m[2];
            $gewijzigd = $m[3];
            $reden     = $m[4];
            $risico    = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : bepaalRisico($reden);
        } else {
            // Onbekend formaat: toon de ruwe regel toch, zodat er niets verloren gaat.
            $type      = '-';
            $naam      = $regel;
            $gewijzigd = '-';
            $reden     = '-';
            $risico    = 50;
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
        //    en gemeld door Wouter (augustus 2026, leestafel.info).
        // Bij een BESTAND blijft de wijzigingsdatum wel meetellen -
        // verandert de inhoud van een bestand dat je eerder vertrouwde, dan
        // is dat wél terecht een reden om opnieuw te waarschuwen.
        $hash = in_array($type, ['map', 'cluster'], true)
            ? md5($type . '|' . $naam)
            : md5($type . '|' . $naam . '|' . $gewijzigd);

        $items[] = [
            'hash'      => $hash,
            'type'      => $type,
            'naam'      => $naam,
            'gewijzigd' => $gewijzigd,
            'reden'     => $reden,
            'risico'    => $risico,
        ];
    }

    return $items;
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
        $nieuweRegels[] = "[{$item['type']}] {$item['naam']} ({$item['gewijzigd']}) - {$item['reden']} [risico={$item['risico']}]";
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
