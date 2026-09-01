<?php
// ontvang_scan.php
// Ontvangt scanresultaten vanuit scan-en-check-website.php (voorheen
// scan_verdacht*.php, geplaatst op elke site) en slaat die op in de
// Sites-tabel.
//
// Ondersteunt twee payload-formaten:
//   - OUD (scan_verdacht.php):        { 'vondsten': [...] }
//   - NIEUW (scan-en-check-website.php e.v.): { 'backdoors': [...], 'root_unknown': [...] }
// zodat oudere en nieuwere scanscript-versies allebei correct verwerkt worden.

error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain; charset=utf-8');

require_once 'config.php';
require_once 'versie_vergelijk_functies.php';
require_once 'instellingen_functies.php';
require_once 'verdacht_functies.php'; // voor bepaalRisico() als terugval

// Moet hetzelfde zijn als in het scanscript (zie configuratiepagina).
$geheimeCode = ontsleutelWaarde(haalInstelling($pdo, 'geheime_code', ''));

$inhoud = file_get_contents('php://input');
$data = json_decode($inhoud, true);

if (!$data || ($data['geheime_code'] ?? '') !== $geheimeCode) {
    http_response_code(403);
    die('Ongeldige of ontbrekende geheime code.');
}

$domein = trim($data['domein'] ?? '');

if ($domein === '') {
    http_response_code(400);
    die('Geen domein meegestuurd.');
}

// Sites worden altijd zonder "www."-voorvoegsel opgeslagen (zie
// site_toevoegen.php), maar $_SERVER['HTTP_HOST'] op de site zelf kan dat
// voorvoegsel wél bevatten - anders wordt de site hier ten onrechte niet
// gevonden en gaat het hele scanresultaat verloren.
$domeinVoorVergelijk = preg_replace('#^www\.#i', '', $domein);

$siteStmt = $pdo->prepare("SELECT id FROM sites WHERE domein = ?");
$siteStmt->execute([$domeinVoorVergelijk]);
$siteId = $siteStmt->fetchColumn();

// --------------------------------------------------------------------
// Vondsten samenstellen, ongeacht welk scanscript-formaat is gebruikt.
// --------------------------------------------------------------------

$vondsten = [];

if (isset($data['vondsten']) && is_array($data['vondsten'])) {
    // Oud formaat (scan_verdacht.php: alleen root + tmp, naam-gebaseerd)
    $vondsten = array_merge($vondsten, $data['vondsten']);
}

if (isset($data['backdoors']) && is_array($data['backdoors'])) {
    // Nieuw formaat: backdoors (inhoud-gebaseerde detectie, v5/v6/v7)
    foreach ($data['backdoors'] as $b) {
        $vondsten[] = [
            'type'      => 'backdoor',
            'naam'      => $b['naam'] ?? '(onbekend)',
            'gewijzigd' => $b['gewijzigd'] ?? '',
            'reden'     => $b['reden'] ?? '',
            'risico'    => $b['risico'] ?? null,
        ];
    }
}

if (isset($data['htaccess_verdacht']) && is_array($data['htaccess_verdacht'])) {
    // Verdachte .htaccess-bestanden - stond hier voorheen niet bij, waardoor
    // deze vondsten (ondanks dat ze wél gescand en verstuurd werden) nooit
    // in het beveiligingsrapport terechtkwamen. Nu wel meegenomen.
    foreach ($data['htaccess_verdacht'] as $h) {
        $vondsten[] = [
            'type'      => 'htaccess',
            'naam'      => $h['naam'] ?? '(onbekend)',
            'gewijzigd' => $h['gewijzigd'] ?? '',
            'reden'     => $h['reden'] ?? '',
            'risico'    => $h['risico'] ?? null,
        ];
    }
}

if (isset($data['root_unknown']) && is_array($data['root_unknown'])) {
    // Nieuw formaat: onbekende root-level bestanden/mappen (v5/v6/v7)
    foreach ($data['root_unknown'] as $r) {
        $vondsten[] = [
            'type'      => $r['type'] ?? 'bestand',
            'naam'      => $r['naam'] ?? '(onbekend)',
            'gewijzigd' => $r['gewijzigd'] ?? '',
            // 'reden_override' wordt meegestuurd voor specifiekere gevallen
            // (bijv. backup-configuratiebestanden) - anders de generieke tekst.
            'reden'     => $r['reden_override'] ?? 'onbekend root-level item',
            'risico'    => $r['risico'] ?? null,
        ];
    }
}

if (isset($data['database_verdacht']) && is_array($data['database_verdacht'])) {
    // Database-gebaseerde vondsten (verdachte Super Users, ontmaskeringsteksten
    // in templatestijlen) - rechtstreeks door het scanscript zelf opgehaald via
    // een eigen, alleen-lezen verbinding met de Joomla-database van de site.
    foreach ($data['database_verdacht'] as $dv) {
        $vondsten[] = [
            'type'      => 'database',
            'naam'      => $dv['naam'] ?? '(onbekend)',
            'gewijzigd' => $dv['gewijzigd'] ?? '',
            'reden'     => $dv['reden'] ?? '',
            'risico'    => $dv['risico'] ?? null,
        ];
    }
}

$aantal = count($vondsten);

$details = '';
foreach ($vondsten as $vondst) {
    $type = $vondst['type'] ?? '?';
    $naam = $vondst['naam'] ?? '?';
    $gewijzigd = $vondst['gewijzigd'] ?? '?';
    $reden = $vondst['reden'] ?? '?';
    $risico = $vondst['risico'] ?? bepaalRisico($reden);
    $details .= "[{$type}] {$naam} ({$gewijzigd}) - {$reden} [risico={$risico}]\n";
}

$stmt = $pdo->prepare("
    UPDATE sites
    SET verdacht_aantal = ?,
        verdacht_details = ?,
        verdacht_laatste_scan = NOW()
    WHERE domein = ?
");
$stmt->execute([
    $aantal,
    $details,
    $domeinVoorVergelijk
]);

if ($stmt->rowCount() === 0) {
    echo "Waarschuwing: geen site gevonden in database met domein '$domeinVoorVergelijk'.";
} else {
    echo "OK: scanresultaat opgeslagen voor $domeinVoorVergelijk ($aantal verdachte item(s)).";
}

// --------------------------------------------------------------------
// Automatisch gedetecteerde extra-scanpad-info opslaan, puur om te tonen
// bij Site-instellingen (het scanscript bepaalt zelf, bij elke scan
// opnieuw, hoe ver het omhoog kan/mag kijken op basis van eigenaarschap -
// hier wordt alleen het resultaat daarvan bewaard voor weergave).
// --------------------------------------------------------------------
if (array_key_exists('extra_scan_pad_info', $data)) {
    $stmt = $pdo->prepare("UPDATE sites SET extra_scan_pad_gedetecteerd = ? WHERE domein = ?");
    $stmt->execute([$data['extra_scan_pad_info'], $domeinVoorVergelijk]);
}

// --------------------------------------------------------------------
// Super Users-overzicht opslaan (compleet overzicht, aanvullend op de
// automatische rogue-super-user-detectie die al in $data['root_unknown']/
// verdacht_details terechtkomt) - puur om te tonen bij beveiliging.php.
// --------------------------------------------------------------------
if (array_key_exists('super_users', $data) || array_key_exists('super_users_fout', $data)) {
    $stmt = $pdo->prepare("UPDATE sites SET super_users_json = ?, super_users_fout = ? WHERE domein = ?");
    $stmt->execute([
        isset($data['super_users']) ? json_encode($data['super_users']) : null,
        $data['super_users_fout'] ?? null,
        $domeinVoorVergelijk,
    ]);
}

// --------------------------------------------------------------------
// Geïnstalleerde extensies opslaan (v11: volledige, automatisch
// gedetecteerde lijst rechtstreeks uit de database van de site zelf).
// --------------------------------------------------------------------

if ($siteId) {
    $extensieFout = $data['extensies_fout'] ?? null;

    $extensiesUpdateStmt = $pdo->prepare("
        UPDATE sites
        SET extensies_laatste_scan = NOW(),
            extensies_fout = ?
        WHERE id = ?
    ");
    $extensiesUpdateStmt->execute([$extensieFout, $siteId]);

    if (isset($data['geinstalleerde_extensies']) && is_array($data['geinstalleerde_extensies'])) {

        // Volledig vervangen: eerst de oude lijst voor deze site weg, dan
        // de nieuwe invoegen. Zo verdwijnen ook extensies die inmiddels
        // verwijderd zijn van de site.
        $verwijderStmt = $pdo->prepare("DELETE FROM site_alle_extensies WHERE site_id = ?");
        $verwijderStmt->execute([$siteId]);

        $invoegStmt = $pdo->prepare("
            INSERT INTO site_alle_extensies
                (site_id, extension_id, naam, type, element, folder, client, enabled, versie, nieuwste_versie, update_feed_url, auteur, package_id, laatst_gezien)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        foreach ($data['geinstalleerde_extensies'] as $extensie) {
            // Bepaalde typen slaan we bewust helemaal niet op (vaste
            // editorplugins, losse "file"-items, onvertaalde PLG_-constantes
            // die bij een groter pakket horen) - die hoeven nooit als losse
            // regel in de extensietabel te verschijnen.
            if (isUitgeslotenVanExtensieoverzicht($extensie)) {
                continue;
            }

            $invoegStmt->execute([
                $siteId,
                $extensie['extension_id'] ?? 0,
                $extensie['naam'] ?? null,
                $extensie['type'] ?? null,
                $extensie['element'] ?? null,
                $extensie['folder'] ?? null,
                $extensie['client'] ?? null,
                isset($extensie['enabled']) ? (int) $extensie['enabled'] : null,
                $extensie['versie'] ?? null,
                $extensie['nieuwste_versie'] ?? null,
                $extensie['update_feed_url'] ?? null,
                $extensie['auteur'] ?? null,
                $extensie['package_id'] ?? 0,
            ]);
        }

        // ------------------------------------------------------------------
        // Automatisch toevoegen aan de handmatige catalogus: NIET per los
        // Joomla-extensieregeltje (dat gaf een enorme, onoverzichtelijke
        // lijst met tientallen losse plugin-onderdelen van dezelfde
        // extensie), maar per GEGROEPEERD PRODUCT - dezelfde groepering
        // (herkomst via map/element, met auteur+versie als vangnet) die ook
        // het extensieoverzicht gebruikt. Zo komt er per daadwerkelijk
        // product hooguit één rij bij, met een sleutel afgeleid van het
        // meest herkenbare onderdeel (bij voorkeur het component).
        // ------------------------------------------------------------------
        $catalogusAutoInsertStmt = $pdo->prepare("
            INSERT IGNORE INTO extensie_catalogus (sleutel, label, manifest_pad, update_feed_url)
            VALUES (?, ?, NULL, ?)
        ");

        // Als de groeperingslogica later verbetert, kan de "representatieve"
        // sleutel van een extensie verschuiven (bijv. van file_baforms naar
        // component_com_baforms). Om te voorkomen dat een eerder handmatig
        // ingevulde update-feed-URL daarmee "verweest" raakt op de oude
        // sleutel, zoeken we bij een NIEUWE sleutel eerst of er al een
        // andere rij met hetzelfde label een feed-URL heeft, en nemen die
        // dan automatisch over.
        //
        // BEWUST beperkt tot component_/package_-sleutels (zie de check
        // vlak vóór het gebruik hieronder): het label komt bij veel
        // producten (via bepaalProductnaamUitNaam()'s "Rol - Productnaam"-
        // patroon) van tíentallen losse, onderling verschillende
        // plugin/file-onderdelen van hetzelfde product - allemaal met
        // hetzelfde gestripte label, maar met compleet eigen identiteit en
        // (vaak ontbrekende) eigen update-feed. Bevestigd misgegaan bij
        // RSForm! Pro: de sleutel "plugin_rsform" (van het losse "Page
        // Cache"-plugintje, altijd "1.0.0", geen eigen feed) kreeg zo de
        // update-feed-URL van het HOOFDCOMPONENT toegewezen, puur omdat
        // een eerdere rij met hetzelfde label "RSForm! Pro" toevallig die
        // URL had - met een structureel verkeerde "verouderd"-melding tot
        // gevolg (1.0.0 vergeleken met de componentversie 3.4.9, in plaats
        // van "Onbekend"). Component/package-sleutels lopen dit risico
        // vrijwel niet: die zijn zelf al de meest betrouwbare, unieke
        // representant van een product (vandaar ook hun voorrang in de
        // representatief_prioriteit-logica elders in dit bestand).
        $zoekFeedOpLabelStmt = $pdo->prepare("
            SELECT update_feed_url FROM extensie_catalogus
            WHERE label = ? AND update_feed_url IS NOT NULL AND update_feed_url != ''
            LIMIT 1
        ");

        $nietKernNietPakket = [];
        foreach ($data['geinstalleerde_extensies'] as $extensie) {
            $rij = [
                'type'    => $extensie['type'] ?? '',
                'element' => $extensie['element'] ?? '',
                'auteur'  => $extensie['auteur'] ?? '',
                'naam'    => $extensie['naam'] ?? '',
                'folder'  => $extensie['folder'] ?? '',
                'versie'  => $extensie['versie'] ?? null,
                'nieuwste_versie' => $extensie['nieuwste_versie'] ?? null,
                'enabled' => $extensie['enabled'] ?? false,
                'package_id' => $extensie['package_id'] ?? 0,
                'status'  => isUpToDate($extensie['versie'] ?? null, $extensie['nieuwste_versie'] ?? null),
            ];

            if (!isJoomlaKernExtensie($rij) && !isOnderdeelVanPakket($rij) && !isUitgeslotenVanExtensieoverzicht($rij)) {
                $nietKernNietPakket[] = $rij;
            }
        }

        $gegroepeerdeProducten = groepeerOpProduct($nietKernNietPakket);

        $nieuwToegevoegdAanCatalogus = 0;
        $feedOvergenomenVanOudeSleutel = 0;

        foreach ($gegroepeerdeProducten as $groep) {
            if (!empty($groep['nieuwste_versie'])) {
                continue; // al een automatische versie bekend, geen catalogus-rij nodig.
            }

            $sleutel = sleutelVoorGegroepeerdProduct($groep);
            if ($sleutel === '') {
                continue;
            }

            $isComponentOfPackageSleutel = strpos($sleutel, 'component_') === 0 || strpos($sleutel, 'package_') === 0;

            $overgenomenFeedUrl = null;
            if ($isComponentOfPackageSleutel) {
                $zoekFeedOpLabelStmt->execute([$groep['naam']]);
                $overgenomenFeedUrl = $zoekFeedOpLabelStmt->fetchColumn();
                $overgenomenFeedUrl = $overgenomenFeedUrl !== false ? $overgenomenFeedUrl : null;
            }

            $catalogusAutoInsertStmt->execute([$sleutel, $groep['naam'], $overgenomenFeedUrl]);
            if ($catalogusAutoInsertStmt->rowCount() > 0) {
                $nieuwToegevoegdAanCatalogus++;
                if ($overgenomenFeedUrl !== null) {
                    $feedOvergenomenVanOudeSleutel++;
                }
            }
        }

        echo "\nOK: " . count($data['geinstalleerde_extensies']) . " extensie(s) opgeslagen voor $domeinVoorVergelijk.";
        if ($feedOvergenomenVanOudeSleutel > 0) {
            echo "\nOK: bij $feedOvergenomenVanOudeSleutel nieuwe sleutel(s) is een bestaande update-feed-URL van een gelijknamige extensie automatisch overgenomen.";
        }
        if ($nieuwToegevoegdAanCatalogus > 0) {
            echo "\nOK: $nieuwToegevoegdAanCatalogus extensie(s) zonder automatische versie toegevoegd aan de catalogus (zie \"Extensietabel beheren\").";
        }

        // ------------------------------------------------------------------
        // Opschonen: catalogus-rijen zonder eigen feed-URL (en nog niet
        // genegeerd) die ooit automatisch zijn aangemaakt omdat een scan op
        // dát moment, op díe ene site, geen automatische nieuwste_versie kon
        // vinden - maar waarvoor inmiddels GEEN ENKELE site meer op de
        // catalogus-rij hoeft te leunen, zijn overbodig geworden.
        //
        // BELANGRIJKE CORRECTIE t.o.v. een eerdere versie van deze opschoning:
        // zo'n rij wordt nu VERWIJDERD, niet meer op genegeerd = 1 gezet.
        // "genegeerd" betekent namelijk niet "deze catalogus-rij is
        // administratief overbodig" - het betekent letterlijk "verberg deze
        // extensie helemaal, overal in de monitor" (zie ook de knop "Negeer
        // alle libraries/taalbestanden", die precies daarvoor bedoeld is).
        // Door genegeerd te gebruiken voor opschoning verdwenen extensies
        // (o.a. RSEvents, iCagenda, Admin Tools, Akeeba, Sourcerer) niet
        // alleen uit het beheer-tabelletje "Extensies zonder update-feed",
        // maar uit het HELE extensieoverzicht - inclusief hun up-to-date-
        // status, die mensen juist wilden blijven zien. Terugzetten via
        // "Niet meer negeren" hielp niet blijvend, want de eerstvolgende
        // scan negeerde dezelfde sleutel gewoon weer.
        //
        // Verwijderen is hier veilig: zo'n rij bevat toch geen eigen data
        // (geen URL, geen manifest_pad) en wordt door de auto-insert-logica
        // hierboven vanzelf opnieuw aangemaakt zodra een site 'm ooit weer
        // nodig heeft (bijv. als een update-site-registratie later alsnog
        // kapotgaat). Rijen die een gebruiker zelf bewust heeft genegeerd
        // (genegeerd = 1) blijven hier volledig buiten schot.
        //
        // "Automatisch bekend op minstens één site" blijft ook nu NIET
        // genoeg reden: verschillende sites kunnen voor dezelfde extensie
        // een compleet andere update-site-registratie hebben. Een sleutel
        // wordt dus alleen opgeruimd als ECHT GEEN ENKELE site 'm nog nodig
        // heeft.
        // ------------------------------------------------------------------
        $alleVersieStatusStmt = $pdo->query("
            SELECT type, element, folder, naam, auteur, package_id, enabled,
                   (nieuwste_versie IS NOT NULL AND nieuwste_versie != '') AS heeft_versie
            FROM site_alle_extensies
        ");

        $sleutelsMetAutomatischeVersie = [];
        $sleutelsNogNodig             = [];
        // NIEUW: houdt bij of een sleutel op MINSTENS één site ooit als los,
        // zelfstandig product is gezien (dus niet kern/pakketonderdeel/
        // uitgesloten daar). Zonder dit blijft een sleutel die OVERAL
        // onderdeel van een pakket is (bijv. AcyMailing-subplugins die
        // altijd via package_id aan pkg_acymailing hangen) voor altijd in
        // de catalogus staan: zijn ruwe nieuwste_versie-kolom wordt nergens
        // gevuld (dat gebeurt alleen via het package_id-vangnet in-memory
        // bij het tonen van het overzicht, nooit teruggeschreven naar
        // site_alle_extensies), dus hij voldeed nooit aan
        // "heeft_versie" - en werd dus nooit als "overbodig" herkend,
        // ondanks dat hij nergens ooit een eigen feed nodig heeft.
        $sleutelsWelEensLosRelevant = [];
        foreach ($alleVersieStatusStmt->fetchAll(PDO::FETCH_ASSOC) as $rij) {
            $sleutel = maakExtensieSleutel($rij['type'] ?? '', $rij['element'] ?? '');
            if ($sleutel === '') {
                continue;
            }

            // Zelfde drie uitsluitingsfuncties als het echte overzicht: als
            // deze rij hier kern, pakketonderdeel, of sowieso uitgesloten
            // is, telt hij niet mee als "op deze site nog een losse
            // catalogus-rij nodig".
            if (isJoomlaKernExtensie($rij) || isOnderdeelVanPakket($rij) || isUitgeslotenVanExtensieoverzicht($rij)) {
                continue;
            }

            $sleutelsWelEensLosRelevant[$sleutel] = true;

            if (!empty($rij['heeft_versie'])) {
                $sleutelsMetAutomatischeVersie[$sleutel] = true;
            } else {
                // Minstens één site heeft deze extensie met een NOG
                // ONBEKENDE versie - die site heeft de catalogus-rij dus
                // nog steeds nodig, ongeacht wat andere sites hebben.
                $sleutelsNogNodig[$sleutel] = true;
            }
        }

        $catalogusZonderFeedStmt = $pdo->query("
            SELECT sleutel
            FROM extensie_catalogus
            WHERE (update_feed_url IS NULL OR update_feed_url = '')
              AND genegeerd = 0
        ");

        $verwijderOverbodigeRijStmt = $pdo->prepare("DELETE FROM extensie_catalogus WHERE sleutel = ? AND genegeerd = 0");
        $aantalOverbodigeRijenVerwijderd = 0;
        foreach ($catalogusZonderFeedStmt->fetchAll(PDO::FETCH_COLUMN) as $sleutel) {
            // Twee onafhankelijke redenen om een sleutel zonder eigen feed
            // op te ruimen:
            //   1. de oorspronkelijke check: overal waar hij automatisch
            //      een versie heeft, en nergens meer als "nog nodig" geldt;
            //   2. NIEUW: hij is nergens (meer) een los, zelfstandig
            //      product - overal kern/pakketonderdeel/uitgesloten, of
            //      helemaal niet meer gedetecteerd. Zo'n sleutel zal nooit
            //      een eigen feed nodig hebben, ongeacht zijn (nooit
            //      gevulde) automatische-versie-status.
            $heeftOveralAutomatischeVersie = isset($sleutelsMetAutomatischeVersie[$sleutel]) && !isset($sleutelsNogNodig[$sleutel]);
            $nergensLosRelevant            = !isset($sleutelsWelEensLosRelevant[$sleutel]);

            if ($heeftOveralAutomatischeVersie || $nergensLosRelevant) {
                $verwijderOverbodigeRijStmt->execute([$sleutel]);
                $aantalOverbodigeRijenVerwijderd += $verwijderOverbodigeRijStmt->rowCount();
            }
        }

        if ($aantalOverbodigeRijenVerwijderd > 0) {
            echo "\nOK: $aantalOverbodigeRijenVerwijderd overbodige lege catalogus-rij(en) opgeruimd (nergens meer een site die de fallback nodig heeft) - blijven vanzelf gewoon zichtbaar in het extensieoverzicht.";
        }
    } elseif ($extensieFout !== null) {
        echo "\nWaarschuwing: extensielijst kon niet worden opgehaald - $extensieFout";
    }

    // ----------------------------------------------------------------
    // Extensiebestand-hashes opslaan (voor de vergelijking tussen sites -
    // zie vergelijk_extensie_bestanden.php). Ook hier: volledig vervangen
    // per site, zodat verwijderde/gewijzigde bestanden niet blijven hangen.
    // ----------------------------------------------------------------
    $verwijderHashesStmt = $pdo->prepare("DELETE FROM extensie_bestand_hashes WHERE site_id = ?");
    $verwijderHashesStmt->execute([$siteId]);

    if (isset($data['extensie_bestand_hashes']) && is_array($data['extensie_bestand_hashes'])) {
        // "ON DUPLICATE KEY UPDATE" i.p.v. een kale INSERT: hetzelfde
        // bestandspad kan in zeldzame gevallen bij twee verschillende,
        // elkaar overlappende extensiegroepen tegelijk horen (bijv. bij
        // pakketten met gedeelde onderliggende bestanden) - de unieke
        // sleutel op (site_id, relatief_pad) staat dan een gewone INSERT
        // niet toe. Bij zo'n dubbel pad wint gewoon de laatst aangeleverde
        // groep/hash, in plaats van dat de hele scanverwerking crasht.
        $invoegHashStmt = $pdo->prepare("
            INSERT INTO extensie_bestand_hashes (site_id, groep_sleutel, relatief_pad, hash, laatst_gezien)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE groep_sleutel = VALUES(groep_sleutel), hash = VALUES(hash), laatst_gezien = NOW()
        ");

        $aantalHashesOpgeslagen = 0;
        foreach ($data['extensie_bestand_hashes'] as $groepSleutel => $bestanden) {
            if (!is_array($bestanden)) {
                continue;
            }
            foreach ($bestanden as $relatiefPad => $hash) {
                if (!is_string($hash) || strlen($hash) !== 64) {
                    continue; // geen geldige sha256-hex-hash, overslaan
                }
                $invoegHashStmt->execute([$siteId, (string) $groepSleutel, (string) $relatiefPad, $hash]);
                $aantalHashesOpgeslagen++;
            }
        }

        if ($aantalHashesOpgeslagen > 0) {
            echo "\nOK: $aantalHashesOpgeslagen extensiebestand-hash(es) opgeslagen voor $domeinVoorVergelijk.";
        }
    }
}