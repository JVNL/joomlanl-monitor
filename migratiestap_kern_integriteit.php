<?php
// ============================================================================
// TOE TE VOEGEN aan haalMigraties() in auto_migratie.php, als nieuw,
// eerstvolgend nummer (bijv. het huidige hoogste nummer + 1).
// Vergeet niet MONITOR_VERSIE in versie.php te verhogen bij release.
// ============================================================================

/* VOORBEELD - pas het nummer hieronder aan het eerstvolgende vrije getal aan: */
// N => function (PDO $pdo) {
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
// },


// ============================================================================
// NIEUW BESTAND: vergelijk_kern_bestanden.php
// (analoog aan vergelijk_extensie_bestanden.php, los aangeroepen zodat
// een download-fout op de officiële-pakket-check nooit de bestaande
// meerderheidsvergelijking kan raken)
// ============================================================================
