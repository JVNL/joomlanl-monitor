<?php
// auto_migratie.php
//
// Zorgt ervoor dat de database automatisch op het juiste schema staat voor
// de huidige versie van de software - zowel bij een gloednieuwe installatie
// (via installeer.php) als bij een update (gewoon de nieuwe bestanden
// uploaden, geen handmatige SQL meer nodig).
//
// Werking: elke wijziging aan het schema staat als genummerde, idempotente
// stap in $MIGRATIES hieronder (idempotent = veilig om per ongeluk twee keer
// te draaien, bijv. via "CREATE TABLE IF NOT EXISTS" en controles vóór een
// ALTER TABLE). De hoogst al toegepaste stap wordt bijgehouden in de tabel
// `schema_versie`. Bij het opstarten (via config.php) wordt gecontroleerd of
// er nog nieuwe stappen open staan, en zo ja, worden die automatisch
// uitgevoerd.
//
// Nieuwe versie maken? Voeg gewoon een nieuw nummer toe aan $MIGRATIES met
// de gewenste wijziging (met kolomBestaat()/tabelBestaat()-checks als het
// een ALTER TABLE is), en verhoog MONITOR_VERSIE in versie.php.

function tabelBestaat(PDO $pdo, string $tabel): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $stmt->execute([$tabel]);
    return (int) $stmt->fetchColumn() > 0;
}

function kolomBestaat(PDO $pdo, string $tabel, string $kolom): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$tabel, $kolom]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Genummerde migratiestappen. Elke stap krijgt de $pdo-verbinding mee, en
 * moet zelf idempotent zijn (mag geen fout geven als 'm per ongeluk twee
 * keer draait).
 */
function haalMigraties(): array
{
    return [

        // ----------------------------------------------------------------
        // Stap 1 (versie 1.0): het volledige basis-schema. Gebruikt overal
        // CREATE TABLE IF NOT EXISTS, dus volledig veilig op een database
        // die (zoals bij een bestaande installatie) een deel van deze
        // tabellen al via losse, handmatige migraties heeft gekregen.
        // ----------------------------------------------------------------
        1 => function (PDO $pdo) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `sites` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `domein` varchar(255) NOT NULL,
                    `admin_pad` varchar(500) DEFAULT NULL,
                    `favicon_url` varchar(500) DEFAULT NULL,
                    `status` varchar(50) DEFAULT '',
                    `prioriteit` varchar(50) DEFAULT '',
                    `extra_scan_pad` varchar(255) DEFAULT NULL,
                    `ftp_host` varchar(255) DEFAULT NULL,
                    `ftp_poort` int(11) DEFAULT 21,
                    `ftp_gebruikersnaam` varchar(255) DEFAULT NULL,
                    `ftp_wachtwoord` varchar(500) DEFAULT NULL,
                    `ftp_pad` varchar(255) DEFAULT '/',
                    `ftp_ssl` tinyint(1) NOT NULL DEFAULT 0,
                    `ftp_protocol` enum('ftp','sftp') NOT NULL DEFAULT 'ftp',
                    `joomla_versie` varchar(50) DEFAULT NULL,
                    `live_website_status` varchar(100) DEFAULT NULL,
                    `live_website_class` varchar(20) DEFAULT NULL,
                    `live_ssl_verloopt` date DEFAULT NULL,
                    `live_ssl_status_tekst` varchar(100) DEFAULT NULL,
                    `live_ssl_class` varchar(20) DEFAULT NULL,
                    `laatste_check` datetime DEFAULT NULL,
                    `verdacht_aantal` int(11) DEFAULT NULL,
                    `verdacht_details` text DEFAULT NULL,
                    `verdacht_laatste_scan` datetime DEFAULT NULL,
                    `extensies_laatste_scan` datetime DEFAULT NULL,
                    `extensies_fout` varchar(500) DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `domein` (`domein`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `instellingen` (
                    `sleutel` varchar(50) NOT NULL,
                    `waarde` varchar(500) DEFAULT NULL,
                    PRIMARY KEY (`sleutel`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `extensie_catalogus` (
                    `sleutel` varchar(190) NOT NULL,
                    `label` varchar(100) NOT NULL,
                    `manifest_pad` varchar(255) DEFAULT NULL,
                    `update_feed_url` varchar(255) DEFAULT NULL,
                    `genegeerd` tinyint(1) NOT NULL DEFAULT 0,
                    PRIMARY KEY (`sleutel`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `site_extensies` (
                    `site_id` int(11) NOT NULL,
                    `sleutel` varchar(190) NOT NULL,
                    `versie` varchar(50) DEFAULT NULL,
                    `laatst_gecontroleerd` datetime DEFAULT NULL,
                    PRIMARY KEY (`site_id`,`sleutel`),
                    CONSTRAINT `site_extensies_site_fk` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `site_alle_extensies` (
                    `site_id` int(11) NOT NULL,
                    `extension_id` int(11) NOT NULL,
                    `naam` varchar(255) DEFAULT NULL,
                    `type` varchar(50) DEFAULT NULL,
                    `element` varchar(255) DEFAULT NULL,
                    `folder` varchar(100) DEFAULT NULL,
                    `client` varchar(20) DEFAULT NULL,
                    `enabled` tinyint(1) DEFAULT NULL,
                    `versie` varchar(50) DEFAULT NULL,
                    `nieuwste_versie` varchar(50) DEFAULT NULL,
                    `update_feed_url` varchar(500) DEFAULT NULL,
                    `auteur` varchar(255) DEFAULT NULL,
                    `package_id` int(11) DEFAULT 0,
                    `laatst_gezien` datetime DEFAULT NULL,
                    PRIMARY KEY (`site_id`,`extension_id`),
                    CONSTRAINT `site_alle_extensies_site_fk` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `nieuwste_versies` (
                    `naam` varchar(50) NOT NULL,
                    `versie` varchar(50) DEFAULT NULL,
                    `opgehaald_op` datetime DEFAULT NULL,
                    PRIMARY KEY (`naam`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `verdacht_vertrouwd` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `site_id` int(11) NOT NULL,
                    `item_hash` varchar(32) NOT NULL,
                    `item_naam` varchar(500) DEFAULT NULL,
                    `toegevoegd_op` datetime DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `site_hash_uniek` (`site_id`,`item_hash`),
                    KEY `site_id` (`site_id`),
                    CONSTRAINT `verdacht_vertrouwd_site_fk` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `extensie_bestand_hashes` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `site_id` int(11) NOT NULL,
                    `groep_sleutel` varchar(190) NOT NULL,
                    `relatief_pad` varchar(500) NOT NULL,
                    `hash` char(64) NOT NULL,
                    `laatst_gezien` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `site_pad` (`site_id`, `relatief_pad`(255)),
                    KEY `groep_sleutel` (`groep_sleutel`),
                    KEY `site_id` (`site_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `extensie_bestand_afwijkingen` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `site_id` int(11) NOT NULL,
                    `groep_sleutel` varchar(190) NOT NULL,
                    `relatief_pad` varchar(500) NOT NULL,
                    `eigen_hash` char(64) NOT NULL,
                    `meerderheid_hash` char(64) NOT NULL,
                    `aantal_sites_meerderheid` int(11) NOT NULL,
                    `aantal_sites_totaal` int(11) NOT NULL,
                    `gevonden_op` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `site_id` (`site_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Standaard/lege instellingen alvast klaarzetten, zodat de rest
            // van de software niet struikelt over ontbrekende sleutels vóór
            // installeer.php (of de configuratiepagina) ze heeft ingevuld.
            // ON DUPLICATE KEY UPDATE waarde=waarde: bestaande waarden op
            // een al langer lopende installatie blijven altijd ongemoeid.
            $standaardInstellingen = [
                'notificatie_email', 'geheime_code', 'cron_geheime_code',
                'monitor_basis_url', 'login_gebruikersnaam', 'login_wachtwoord',
                'email_afzendernaam', 'email_website_status_enabled',
                'email_website_criteria', 'email_joomla_enabled',
                'email_extensies_enabled', 'email_ssl_enabled',
                'email_beveiliging_enabled', 'email_alleen_bij_cron',
                'gegevens_versleuteld',
            ];
            $insertInstellingStmt = $pdo->prepare("
                INSERT INTO instellingen (sleutel, waarde) VALUES (?, '')
                ON DUPLICATE KEY UPDATE waarde = waarde
            ");
            foreach ($standaardInstellingen as $sleutel) {
                $insertInstellingStmt->execute([$sleutel]);
            }
        },

        // ----------------------------------------------------------------
        // Stap 2 (versie 1.2): kolommen voor domeinstatus (WHOIS/RDAP-vervaldatum
        // van de domeinregistratie zelf). Deze functie is bewust leeg gelaten:
        // de functionaliteit zelf is later weer teruggedraaid (zie stap 3
        // hieronder) omdat de belangrijkste registry voor onze sites, .nl
        // (SIDN), de vervaldatum structureel niet vrijgeeft - waardoor de
        // kolom voor het overgrote deel van de sites toch altijd "Onbekend"
        // bleef tonen. Deze stap blijft als lege functie staan (i.p.v.
        // volledig verwijderd) zodat het volgnummer niet verspringt voor
        // installaties die 'm destijds al hebben uitgevoerd.
        // ----------------------------------------------------------------
        2 => function (PDO $pdo) {
            // Bewust leeg - zie toelichting hierboven.
        },

        // ----------------------------------------------------------------
        // Stap 3 (versie 1.2): domeinstatus-kolommen weer opruimen (zie
        // toelichting bij stap 2 hierboven).
        // ----------------------------------------------------------------
        3 => function (PDO $pdo) {
            foreach (['domein_vervaldatum', 'domein_whois_status_tekst', 'domein_whois_class', 'domein_laatste_whois_check'] as $kolom) {
                if (kolomBestaat($pdo, 'sites', $kolom)) {
                    $pdo->exec("ALTER TABLE `sites` DROP COLUMN `{$kolom}`");
                }
            }
        },

        // ----------------------------------------------------------------
        // Stap 4 (versie 1.4): eigen scanscript-bestandsnaam per site -
        // handig als er op dezelfde site ook nog andere monitorsoftware
        // (van iemand anders) draait, zodat de twee scanscripts elkaar niet
        // overschrijven. Leeg/NULL betekent: gewoon de standaardnaam
        // "scan-en-check-website.php" gebruiken.
        // ----------------------------------------------------------------
        4 => function (PDO $pdo) {
            if (!kolomBestaat($pdo, 'sites', 'scan_bestandsnaam')) {
                $pdo->exec("ALTER TABLE `sites` ADD COLUMN `scan_bestandsnaam` varchar(255) DEFAULT NULL AFTER `admin_pad`");
            }
        },

        // ----------------------------------------------------------------
        // Stap 5 (versie 1.6): bijhouden onder welke bestandsnaam/namen het
        // scanscript ooit naar een site is verstuurd (via de FTP-knoppen).
        // Nodig omdat de "eigen bestandsnaam"-instelling (stap 4) in de
        // loop van de tijd kan wijzigen - bij het verwijderen van een site
        // willen we ALLE ooit-gebruikte bestandsnamen kunnen opruimen, niet
        // alleen de nu ingestelde naam.
        // ----------------------------------------------------------------
        5 => function (PDO $pdo) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `site_scanscript_geschiedenis` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `site_id` int(11) NOT NULL,
                    `bestandsnaam` varchar(255) NOT NULL,
                    `laatst_verstuurd` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `site_bestandsnaam` (`site_id`, `bestandsnaam`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        },

        // ----------------------------------------------------------------
        // Stap 6 (versie 1.9): per extensie optioneel het laatste
        // versie-onderdeel kunnen negeren bij het bepalen van "up-to-date".
        // Bedoeld voor extensies (met name taalbestanden) die een extra,
        // eigen build-nummer achteraan hun versie plakken (bijv. Joomla
        // taalbestanden: "6.1.2.1" - de kernversie plus een eigen,
        // veelvuldig bijgewerkt vertaalnummer) - zonder deze optie zou de
        // monitor bij elke kleine vertaalcorrectie "niet up-to-date" tonen,
        // ook al biedt Joomla zelf zo'n update vaak nog helemaal niet aan.
        // ----------------------------------------------------------------
        6 => function (PDO $pdo) {
            if (!kolomBestaat($pdo, 'extensie_catalogus', 'negeer_laatste_versiedeel')) {
                $pdo->exec("ALTER TABLE `extensie_catalogus` ADD COLUMN `negeer_laatste_versiedeel` TINYINT(1) NOT NULL DEFAULT 0 AFTER `genegeerd`");
            }
        },

        7 => function (PDO $pdo) {
            // Voorheen kon "extra scanpad" alleen aan/uit ('..', exact één
            // map boven de website-root); wordt nu een vrij in te vullen
            // pad (bijv. '../../..' voor drie niveaus hoger), dus geen
            // schemawijziging nodig voor die kolom zelf - wel nieuw: een
            // losse kolom met (sub)mapnamen die je binnen dat extra pad
            // wilt overslaan (bijv. Maildir, tmp, domains van andere sites).
            if (!kolomBestaat($pdo, 'sites', 'extra_scan_pad_negeren')) {
                $pdo->exec("ALTER TABLE `sites` ADD COLUMN `extra_scan_pad_negeren` TEXT NULL AFTER `extra_scan_pad`");
            }
        },

        8 => function (PDO $pdo) {
            // "extra scanpad" is niet langer een handmatig gekozen aantal
            // niveaus, maar wordt door het scanscript zelf automatisch
            // bepaald (op basis van eigenaarschap: net zo ver omhoog als
            // nog bij hetzelfde hostingaccount hoort). Deze kolom bewaart
            // wat daaruit is gekomen, puur om op Site-instellingen te
            // kunnen tonen - de kolom `extra_scan_pad` zelf blijft bestaan
            // en betekent nu alleen nog aan ('auto') of uit (leeg/NULL).
            if (!kolomBestaat($pdo, 'sites', 'extra_scan_pad_gedetecteerd')) {
                $pdo->exec("ALTER TABLE `sites` ADD COLUMN `extra_scan_pad_gedetecteerd` TEXT NULL AFTER `extra_scan_pad_negeren`");
            }
        },

        9 => function (PDO $pdo) {
            // Compleet overzicht van Super User-accounts (naam/gebruikers-
            // naam/e-mail/aangemaakt/laatst ingelogd/geblokkeerd), zoals
            // opgehaald door haalSuperUsers() in het scanscript - aanvullend
            // op de al bestaande automatische rogue-super-user-detectie
            // (die alleen bekende aanvallerspatronen meldt). Opgeslagen als
            // JSON-tekst, puur voor weergave op beveiliging.php.
            if (!kolomBestaat($pdo, 'sites', 'super_users_json')) {
                $pdo->exec("ALTER TABLE `sites` ADD COLUMN `super_users_json` TEXT NULL AFTER `extra_scan_pad_gedetecteerd`");
            }
            if (!kolomBestaat($pdo, 'sites', 'super_users_fout')) {
                $pdo->exec("ALTER TABLE `sites` ADD COLUMN `super_users_fout` TEXT NULL AFTER `super_users_json`");
            }
        },

        10 => function (PDO $pdo) {
            // Het extra scanpad (meescannen tot aan de accountroot) is niet
            // langer een keuze - de automatische detectie op basis van
            // eigenaarschap zorgt er toch al voor dat dit nooit verder gaat
            // dan bij het hostingaccount hoort, dus een los aan/uit-vinkje
            // had geen echte functie meer. Voor alle sites die het nog niet
            // aan hadden staan (leeg/NULL), hier alsnog op 'auto' zetten.
            $pdo->exec("UPDATE `sites` SET `extra_scan_pad` = 'auto' WHERE `extra_scan_pad` IS NULL OR `extra_scan_pad` = ''");
        },

        11 => function (PDO $pdo) {
            // Bij sommige sites staat Joomla niet in de webroot zelf, maar
            // in een submap die WEL rechtstreeks via de domeinnaam bereikbaar
            // is (bijv. https://podia-klassiek.nl/bieb/) - anders dan het
            // FTP-pad (dat alleen bepaalt waar het bestand op de schijf
            // terechtkomt), is dit de submap die in de daadwerkelijke,
            // publieke URL van de site voorkomt. Leeg = webroot zelf
            // (verreweg de meeste sites).
            if (!kolomBestaat($pdo, 'sites', 'url_subpad')) {
                $pdo->exec("ALTER TABLE `sites` ADD COLUMN `url_subpad` VARCHAR(255) NULL AFTER `admin_pad`");
            }
        },

        12 => function (PDO $pdo) {
            // Onderscheid tussen eigen websites en websites die voor een
            // ander worden beheerd - bepaalt op de indexpagina welke sites
            // getoond worden, en of een site meetelt in de samenvattingsmail
            // over verouderde extensies/beveiligingsissues (alleen "eigen").
            // Bestaande sites worden allemaal als "eigen" beschouwd, om het
            // bestaande gedrag (alles zit in de mail) niet stilzwijgend te
            // veranderen.
            if (!kolomBestaat($pdo, 'sites', 'categorie')) {
                $pdo->exec("ALTER TABLE `sites` ADD COLUMN `categorie` VARCHAR(20) NOT NULL DEFAULT 'eigen' AFTER `domein`");
            }
        },

        13 => function (PDO $pdo) {
            // Tijdstip waarop een extensie op "genegeerd" is gezet - puur
            // ter ondersteuning bij het achterhalen van WANNEER dat is
            // gebeurd (bijv. na een onverwachte bevinding zoals "waarom
            // staat dit ineens op genegeerd?"). Bewust geen "door wie"-
            // kolom: elke installatie heeft maar één inlogaccount, dus dat
            // zou nooit iets onderscheidends toevoegen.
            //
            // NULL = nooit genegeerd geweest, of de status is via een oude
            // (van vóór deze kolom bestond) database-wijziging tot stand
            // gekomen - in dat laatste geval blijft het simpelweg leeg,
            // in plaats van een onterecht "nu" in te vullen.
            if (!kolomBestaat($pdo, 'extensie_catalogus', 'genegeerd_op')) {
                $pdo->exec("ALTER TABLE `extensie_catalogus` ADD COLUMN `genegeerd_op` DATETIME NULL AFTER `genegeerd`");
            }
        },

        14 => function (PDO $pdo) {
            // Onderscheidt of de HUIDIGE update_feed_url van een extensie
            // bewust lokaal is gehouden (via "Opslaan zonder GitHub Sync" -
            // bijv. een site-specifieke uitzondering die een externe
            // blokkade omzeilt, en die niet voor andere installaties hoeft
            // te gelden) of gewoon meedoet met de gedeelde Github-catalogus.
            //
            // NULL = onbekend/legacy (van vóór deze kolom bestond, of nooit
            // via een van deze twee knoppen ingevuld) - wordt overal
            // behandeld als "niet bewust lokaal", zodat bestaand gedrag niet
            // stilzwijgend verandert.
            // 1 = bewust lokaal - wordt NOOIT meegenomen in een push naar
            // Github, ongeacht wat er elders in de catalogus gebeurt.
            // 0 = expliciet (opnieuw) gesynchroniseerd, of overgenomen via
            // een Github-import.
            if (!kolomBestaat($pdo, 'extensie_catalogus', 'feed_lokaal')) {
                $pdo->exec("ALTER TABLE `extensie_catalogus` ADD COLUMN `feed_lokaal` TINYINT(1) NULL DEFAULT NULL AFTER `update_feed_url`");
            }
        },

        // ----------------------------------------------------------------
        // Stap 15 (versie 1.18): kernbestand-integriteitscontrole tegen het
        // officiële, ongewijzigde Joomla-pakket van downloads.joomla.org -
        // een aanvulling op de bestaande meerderheidsvergelijking tussen
        // sites (extensie_bestand_hashes/-afwijkingen), die niets kan
        // zeggen over een Joomla-kernversie die maar op 1 site voorkomt, of
        // over een kernbestand dat toevallig op alle sites identiek
        // gecompromitteerd is. Zie kern_integriteit_functies.php.
        // ----------------------------------------------------------------
        15 => function (PDO $pdo) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `kern_officiele_hashes` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `kernversie` varchar(20) NOT NULL,
                    `relatief_pad` varchar(500) NOT NULL,
                    `hash` char(64) NOT NULL,
                    `opgehaald_op` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `versie_pad` (`kernversie`, `relatief_pad`(255)),
                    KEY `kernversie` (`kernversie`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `kern_bestand_afwijkingen` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `site_id` int(11) NOT NULL,
                    `kernversie` varchar(20) NOT NULL,
                    `relatief_pad` varchar(500) NOT NULL,
                    `eigen_hash` char(64) NOT NULL DEFAULT '',
                    `officiele_hash` char(64) NOT NULL,
                    `status` enum('gewijzigd','ontbreekt') NOT NULL DEFAULT 'gewijzigd',
                    `gevonden_op` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `site_id` (`site_id`),
                    CONSTRAINT `kern_bestand_afwijkingen_site_fk` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        },

        // ----------------------------------------------------------------
        // Stap 16 (versie 1.18): "vertrouwd/genegeerd" markeren van een
        // specifieke kernbestand-afwijking (site + kernversie + pad + de
        // exacte, handmatig beoordeelde hash), zodat 'm niet steeds opnieuw
        // als melding terugkomt zolang het bestand niet ook nog eens
        // verandert. Zelfde soort opzet als verdacht_vertrouwd, maar met
        // een eigen tabel omdat de sleutel hier anders is opgebouwd (geen
        // los te identificeren "item_naam", wel een pad + kernversie).
        // ----------------------------------------------------------------
        16 => function (PDO $pdo) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `kern_vertrouwd` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `site_id` int(11) NOT NULL,
                    `kernversie` varchar(20) NOT NULL,
                    `relatief_pad` varchar(500) NOT NULL,
                    `hash` char(64) NOT NULL,
                    `toegevoegd_op` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `site_pad_hash` (`site_id`, `relatief_pad`(255), `hash`),
                    KEY `site_id` (`site_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        },

    ];
}

/**
 * Controleert of de database op het nieuwste schema staat, en voert zo
 * nodig de ontbrekende migratiestappen automatisch uit. Geeft het aantal
 * uitgevoerde stappen terug (0 = alles was al up-to-date).
 */
function voerAutoMigratieUit(PDO $pdo): int
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `schema_versie` (
            `versie` int(11) NOT NULL,
            `toegepast_op` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`versie`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $huidigeVersie = (int) ($pdo->query("SELECT COALESCE(MAX(versie), 0) FROM schema_versie")->fetchColumn());

    $migraties = haalMigraties();
    ksort($migraties);

    $uitgevoerd = 0;
    foreach ($migraties as $nummer => $stap) {
        if ($nummer <= $huidigeVersie) {
            continue; // deze stap is al eerder toegepast
        }

        $stap($pdo);

        $pdo->prepare("INSERT INTO schema_versie (versie) VALUES (?)")->execute([$nummer]);
        $uitgevoerd++;
    }

    return $uitgevoerd;
}
