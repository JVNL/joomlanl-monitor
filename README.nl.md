# joomlanl-monitor

*[Read this in English](README.md)*

Monitoringtool voor Joomla-websites: controleert online-status, geïnstalleerde extensies en bekende exploits.

## Installeren of updaten

Elke [release](../../releases) biedt twee soorten bestanden:

- **Source code (zip)** — een complete momentopname van de hele tool zoals die was op dat release-moment. Gebruik dit voor een verse installatie, of als je alles in één keer wilt vervangen in plaats van stap voor stap te updaten.
- **`update-vX.zip`** — bevat *alleen* de bestanden die zijn gewijzigd sinds de vorige release. Gebruik dit als je de vorige versie al draait en alleen wilt bijwerken.

### Let op: updates zijn cumulatief, niet over te slaan

Elke `update-vX.zip` bevat alleen wat er is veranderd ten opzichte van de *direct voorgaande* versie — niet ten opzichte van de versie die jij toevallig draait.

Loop je meerdere versies achter, dan moet je de updates op volgorde toepassen:

- v1.18 -> update-v1.19.zip toepassen -> nu op v1.19
- v1.19 -> update-v1.20.zip toepassen -> nu op v1.20
- v1.20 -> update-v1.21.zip toepassen -> nu op v1.21

Alleen de laatste `update-vX.zip` pakken terwijl je een oudere versie draait, mist de tussenliggende wijzigingen.

**Alternatief:** in plaats van meerdere updates na elkaar toe te passen, kun je ook de **Source code (zip)** van de laatste release downloaden en die als verse installatie/overschrijving gebruiken. Dat geeft hetzelfde eindresultaat in één stap. Let op: als een release een database-migratie bevatte (een script zoals `auto_migratie.php`), moet je die na het overschrijven van de bestanden alsnog draaien — bestanden overschrijven past geen databasewijzigingen toe.

### Wat wordt uitgesloten

`config.php`, `geheime_sleutel.php` en `installatie.voltooid` zitten nooit in deze pakketten — die zijn specifiek voor jouw eigen installatie en worden aangemaakt door de installer (`installeer.php`).
