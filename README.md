# Joomla!NL Websitesmonitor

Monitor voor Joomla-websites: controleert of je sites online zijn, of Joomla en de extensies up-to-date zijn, en scant de bestanden en de database op achterdeuren en andere sporen van een hack. De monitor en alle meldingen zijn in het Nederlands.

## Installeren

Zie [LEES_DIT_EERST.txt](LEES_DIT_EERST.txt) voor de complete installatie-instructies.

## Bijwerken

Elke [release](../../releases) heeft twee soorten bestanden:

- **`update-vX.XX.zip`**: alleen de bestanden die sinds de *vorige* release zijn veranderd. Upload de inhoud over je bestaande installatie heen.
- **Source code (zip)**: de complete monitor van die versie.

De update-zips moet je op volgorde toepassen. Loop je meer dan één versie achter, gebruik dan de **Source code (zip)** van de laatste release en zet de monitor schoon opnieuw neer. Hoe dat gaat zonder je sites en instellingen kwijt te raken, staat in [LEES_DIT_EERST.txt](LEES_DIT_EERST.txt).

Database-aanpassingen gaan vanzelf zodra je de monitorpagina opent. De scanscripts op je sites werken zichzelf bij bij de volgende scan.

## Wat zit er nooit in een pakket

`config.php`, `geheime_sleutel.php` en `installatie.voltooid` horen bij je eigen installatie en worden door de installatiewizard (`installeer.php`) aangemaakt. Overschrijf of deel ze nooit.

## Wijzigingen

Zie [CHANGELOG.md](CHANGELOG.md), of de knop "Wijzigingsgeschiedenis" onder Configuratie in de monitor.
