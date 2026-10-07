# Wijzigingslogboek - Mijn Websites Monitor

## 1.32 - 2026-10-07

### Scanscript: scanbudget op = melding, en de tussenniveaus vallen er niet meer onder (`scan_template.php`)
De bestandsscan heeft één gedeeld budget voor de hele scan (20.000 items of 45 seconden). Was dat op, dan stopte de scan stil: de rest werd niet bekeken, terwijl het rapport er volledig uitzag. Op een grote site met een volle accountroot gebeurde dat vóórdat de tussenniveaus uit 1.31 aan de beurt waren, waardoor een kwaadaardige `.htaccess` boven `public_html` niet werd gemeld (de backdoor ernaast wel, via de aparte PHP-regel).

- **Losse bestanden op de tussenniveaus** (`.htaccess`, PHP-bestanden, `php.ini`/`.user.ini`) worden nu rechtstreeks gecontroleerd, buiten het budget om. Het zijn er maar een paar per niveau. De tussenniveaus worden bovendien vóór de rest van de accountroot gescand.
- **Nieuw: melding "SCAN ONVOLLEDIG"** (risico 50) zodra het budget op is, met de plek waar de scan stopte. Ook in de ruwe scanuitvoer.
- **Het budget gaat van 20.000 naar 150.000 items.** Een grote site met een `.htaccess` in vrijwel elke map haalde de oude grens al na 7 seconden, ruim voordat de tijdsgrens (45 seconden) bereikt was; daardoor werd een deel van de website zelf niet gescand. De tijdsgrens blijft de bescherming tegen een time-out.
- In de ruwe scanuitvoer staat per tussenniveau welke losse bestanden er zijn gezien, met grootte, rechten en of PHP ze kan lezen.
- **`.htaccess` in een map `awstats` wordt niet meer helemaal overgeslagen.** De uitzondering op mapnaam geldt alleen nog voor de lichte melding "ongebruikelijke .htaccess"; de kritieke patronen (zelfbeschermende FilesMatch-regel, cloaking-RewriteRule) worden daar nu ook gemeld. Aanleiding: in een `awstats`-map naast `public_html` stond dezelfde kwaadaardige `.htaccess` als in de domeinmap, samen met twee backdoors.

### Beveiligingsrapport: lange vondstenlijst werd stil afgekapt (`auto_migratie.php`, `ontvang_scan.php`)
De vondsten van een site worden opgeslagen in één databasekolom van het type TEXT, met een maximum van 65.535 bytes. Bij een zwaar besmette site (ruim 3.000 vondsten, samen ongeveer 1 MB) kapte MySQL de lijst zonder foutmelding af: het rapport toonde er ruim 200, een beschrijving stopte midden in een woord, en alles achteraan de lijst ontbrak, waaronder vondsten buiten de website-root en de melding "scan onvolledig".

- **Migratiestap 24** zet de kolom om naar MEDIUMTEXT (maximaal 16 MB). Dat gebeurt automatisch bij het eerste gebruik na de update; er is geen handmatige SQL nodig.
- Past een lijst daar ooit nog niet in, dan wordt hij ingekort met een duidelijke slotregel "LIJST INGEKORT" in plaats van stil.

## 1.31 - 2026-10-07

### Beveiligingsrapport: een vertrouwd bestand wordt herkend aan zijn inhoud, niet aan zijn wijzigingsdatum (`scan_template.php`, `ontvang_scan.php`, `verdacht_functies.php`)
Een bestand dat als vertrouwd was gemarkeerd, verscheen bij een volgende scan soms opnieuw als "nieuw verdacht", en kwam daardoor ook steeds terug in de e-mailmelding. Oorzaak: de monitor herkende een bestand aan type, pad en wijzigingsdatum. Sommige programma's schrijven hun eigen bestanden regelmatig opnieuw weg met exact dezelfde inhoud; de datum verspringt dan telkens, terwijl er niets is veranderd.

- **Het scanscript stuurt bij elke vondst over een los bestand een vingerafdruk van de inhoud mee** (de eerste 32 tekens van de sha256). Dat gebeurt op één centrale plek, vlak voor het versturen (`voegInhoudVingerafdrukkenToe()`), en geldt dus voor alle soorten vondsten: er is geen lijst van bestandsnamen, mappen of programma's.
- **De monitor herkent zo'n bestand voortaan aan type, pad en inhoud** (`berekenVondstHash()`). Opnieuw weggeschreven met dezelfde inhoud: geen nieuwe melding. Inhoud gewijzigd: wel een nieuwe melding, ook als de wijzigingsdatum daarbij is teruggezet. Dat laatste ging voorheen ongemerkt voorbij.
- De kolom "Gewijzigd" blijft de actuele datum tonen, ter informatie.
- **Terugval op de wijzigingsdatum, zoals voorheen,** als er geen vingerafdruk is: bij een site waar nog een ouder scanscript draait, bij een bestand dat niet leesbaar is en bij een bestand groter dan 16 MB (bijvoorbeeld een back-uparchief). Voor mappen en verzamelmeldingen verandert er niets: daar telde de datum al niet mee.
- **Bestaand vertrouwen blijft behouden.** Bij de eerste scan met het bijgewerkte scanscript zet de monitor het vertrouwen over naar de nieuwe herkenning, voor elk bestand dat op dat moment ook volgens de oude methode nog vertrouwd was (`zetVertrouwenOverOpInhoud()`). Er verschijnt dus geen golf van oude meldingen. Het antwoord van de monitor onder "=== MONITOR ===" meldt hoeveel bestanden zijn overgezet.
- Een bestand waarvan de datum sinds het vertrouwen al was versprongen, wordt bewust niet automatisch overgezet: daarvan is niet na te gaan of de inhoud nog dezelfde is. Dat verschijnt nog één keer en blijft na "Vertrouwen" weg.
- De ruwe scanuitvoer toont per bestand de regel "Inhoud-vingerafdruk", zodat twee scans eenvoudig naast elkaar te leggen zijn.
- De vingerafdruk staat achteraan de opgeslagen regel van de vondst (`[inhoud=...]`); er is geen wijziging in de database nodig. Het maken en teruglezen van die regel loopt via `maakVondstRegel()` en `parseVerdachtDetails()`, ook na een beheeractie (Quarantaine, Blokkeer, Verwijder).

### Beveiligingsrapport: bestandsweergave sluit ook na "Vertrouwen" (`beveiliging.php`)
- Stond een bestand open via "Bekijk" en klikte je daarna op "Vertrouwen", dan verdween de regel uit de lijst maar bleef de inhoud van het bestand in beeld staan. De weergave sluit nu ook bij "Vertrouwen" en "Niet meer vertrouwen", per regel en via de bulkbalk. Bij Quarantaine, Blokkeer en Verwijder was dat al zo.
- Bij "Rechten herstellen" blijft de weergave bewust open: de vondst blijft staan en moet meestal nog worden beoordeeld.
- Klik je op een actieknop terwijl de inhoud nog wordt geladen, dan verschijnt die inhoud daarna niet alsnog.
- Bugfix: na het vertrouwen van het laatste item bleef een lege tabel met alleen kolomkoppen staan in plaats van de melding dat alle items vertrouwd zijn. De pagina keek daarvoor naar de eerste tabel op de pagina (het Super Users-overzicht) in plaats van naar de lijst met vondsten.

### Helppagina (`help.php`)
- Hoofdstuk 9: de uitleg over "Vertrouwen" beschrijft nu de knop (in plaats van een vinkje) en het vergelijken op inhoud, met de twee uitzonderingen (mappen en niet-vergelijkbare bestanden).

### Scanscript: losse bestanden tussen accountroot en website-root (`scan_template.php`)
Bij een indeling als `domains/<domein>/public_html` (DirectAdmin) sloeg het scannen boven de root de complete map `domains` over, omdat daarin de website zelf staat. Losse bestanden in `domains/` en in de domeinmap zelf, één niveau boven `public_html`, werden daardoor nooit bekeken. Juist daar stonden een backdoor en een kwaadaardige `.htaccess`.

- **Elk tussenliggend niveau wordt nu apart gescand**, zonder de route naar de website zelf. In `domains/` alleen de losse bestanden (de submappen zijn andere sites met een eigen monitor-item); in de domeinmap alles behalve `public_html` en de standaard-uitsluitlijst.
- **Elk PHP-bestand op zo'n tussenniveau wordt gemeld** (risico 80), ongeacht inhoud: de hostingpartij en Joomla zetten daar nooit PHP neer.
- Geen rechtencontrole op deze niveaus (mappen als `stats`/`awstats` hebben eigen rechten van het hostingpaneel).

### Scanscript: nieuw patroon voor achterdeuren met een tekentabel (`scan_template.php`)
De backdoor die op zo'n tussenniveau stond, werd ook op inhoud door geen enkel patroon herkend, dus ook niet als hij binnen de website had gestaan. Alle gevaarlijke functienamen (`create_function`, `base64_decode`, `file_get_contents`, `filter_input` ...) staan er alleen als getallenreeksen in, die via een zelfgebouwde tekentabel worden vertaald; de code is opgeknipt met tientallen `goto`-sprongen.

- **PATROON 29** (`detecteerTekentabelObfuscatie()`): `eval()` + minstens 5 `goto`-sprongen + een functienaam opgebouwd uit losse ge-escapete tekens (`"\x72" . "\141" . ...`) en/of een tekentabel van `~` tot spatie. Gemeld als zekere achterdeur.
- Getest tegen een schoon Joomla 5.4-pakket (6.144 PHP-bestanden, inclusief vendor-libraries): geen treffers.

### Scanscript: back-ups op een publiek bereikbare plek (`scan_template.php`)
De `images/`-regel uit 1.30 sloeg aan op een Akeeba-backuplog in `images/`: de uitvoermap van Akeeba Backup stond daar, en daarmee ook de complete back-uparchieven, rechtstreeks te downloaden.

- **Databestanden met een `die()`-kop in `images/`** (zoals Akeeba-logs) worden niet overgeslagen, maar met een eigen melding gemeld (risico 45): zelf geen achterdeur, wel een teken dat er een back-up- of logmap op een publieke plek staat.
- **Nieuw: back-uparchieven en databasedumps binnen de website-root** worden gemeld (risico 70):
  - Akeeba-archieven (`.jpa`, `.jps`, `.j01` enz.) overal, behalve in de componentmap van Akeeba Backup zelf (daar staan de afgeschermde standaarduitvoermap en de meegeleverde herstelscripts `brs*.jpa`).
  - `.sql`, `.sql.gz`, `.sql.zip`, `.sql.bz2` alleen in de website-root zelf en in `images/`: elders zijn het meegeleverde installatie- of testbestanden van Joomla of een extensie (bv. `com_admin/sql/updates/`, testbestanden in een `vendor`-map, een lege `blank.sql`). Een eerste, ruimere versie gaf daardoor ruim honderd valse meldingen per site met Akeeba Backup.

## 1.30 - 2026-10-06

### Scanscript: PHP in de afbeeldingsmap en nummermappen (`scan_template.php`)
Een webshell-kit liet in drie submappen van `images/` een leeg `index.php` achter, met daarnaast een nummermap met alleen een `.htaccess` die alle PHP weigert behalve `index.php` (zelfbescherming van de kit). De scan meldde hier niets van.

- **Elk PHP-bestand onder `images/` wordt nu gemeld**, ongeacht inhoud of grootte (risico 75, met "0 bytes" erbij als het leeg is). Joomla en normale extensies zetten daar geen PHP neer. Tot nu toe werd zo'n bestand alleen inhoudelijk gescand, en een leeg bestand werd daarbij overgeslagen.
- **Nummermappen (bv. `116117`) worden nu gewoon recursief gescand.** Voorheen werden alleen de PHP-bestanden direct in zo'n map bekeken: een `.htaccess` of submap erin bleef onzichtbaar. De bestaande `.htaccess`-controle meldt de zelfbeschermingsregel nu als kritiek.

## 1.29 - 2026-10-03

### Nieuwste versies van extensies waarvan de update-server websites blokkeert (nieuw: `feed_terugval_functies.php`, `feed_handmatige_versie.php`; `scan_template.php`, `ontvang_scan.php`, `start_scan.php`, `haal_versies_op.php`, `extensies.php`, `index.php`, `auto_migratie.php`)
Sommige update-servers blokkeren verzoeken van websites met een botbeveiliging: in plaats van de update-feed komt er een captchapagina of een weigering (HTTP 403) terug. De extensie bleef dan bij alle sites "Onbekend", en bij "alles scannen" maakte het grote aantal verzoeken vlak na elkaar het alleen maar erger.

**Het scanscript meldt zich nu als wat het is**
- Het scanscript vroeg update-feeds op met de afzender van een gewone browser (browser-User-Agent plus browserspecifieke headers). Een "browser" die zich daarna niet als browser gedraagt, is juist wat een botbeveiliging als bot aanmerkt: op dezelfde site en server kon de eigen updatecontrole van Joomla dezelfde feed wél openen.
- Feeds worden nu opgevraagd precies zoals Joomla's eigen updatecontrole dat doet: met Joomla's eigen afzender (inclusief de Joomla-versie van de site) en zonder bijzondere headers (`feedCurlOpties()`). Het scanscript draait op een Joomla-site en vraagt de update-feed van een daar geïnstalleerde extensie op, en zo meldt het verzoek zich nu ook.
- De oude, browserachtige aanpak dient alleen nog als tweede poging bij een gewone fout (time-out, serverfout). Na een blokkade (captchapagina, HTTP 403/429) volgt geen tweede poging.
- Vuistregel: kan Joomla zelf in de beheeromgeving van een site een feed openen, dan kan het scanscript op die site dat ook.

**Feeds van blokkerende update-servers worden centraal en zelden opgehaald**
- De monitor leert zelf welke update-servers blokkeren: een site die een captchapagina of HTTP 403/429 krijgt, meldt die server in het scanresultaat; een blokkade die de monitor zelf krijgt, telt ook. Geen vaste lijst met namen in de code; een server blijft 60 dagen na de laatste blokkade op de lijst.
- Bij het starten van een scan geeft de monitor die servers mee aan het scanscript. De sites vragen zulke feeds dan niet meer zelf op (scanuitvoer: "CENTRAAL").
- Per feed haalt hooguit eens per 12 uur één site de feed op, bij toerbeurt, en stuurt hem mee naar de monitor; die gebruikt hem voor alle sites. De monitor neemt alleen een echte feed met een versienummer over.
- Mislukt het via een site, dan mag al na een uur een site op een andere server het proberen. De monitor onthoudt per site of het lukte of mislukte: sites waar het eerder lukte krijgen voorrang, sites waar het onlangs mislukte worden overgeslagen zolang er een andere kandidaat is.
- Toewijzingen worden atomair geclaimd: ook bij gelijktijdige scans gaat er nooit meer dan één verzoek tegelijk naar dezelfde feed.
- De monitor zelf vraagt zo'n feed alleen op als er geen site aan de beurt is geweest, en wacht na een eigen blokkade 3 dagen.
- De laatst succesvol opgehaalde feed blijft bewaard: een tijdelijke blokkade maakt een extensie niet meer "Onbekend".
- Staat hetzelfde pakket bij sommige sites met een net andere feed-URL geregistreerd, dan volstaat een bewaarde feed van dezelfde update-server waarin hetzelfde Joomla-element voorkomt.
- Er komt hiervoor niets in de extensiecatalogus en er gaat niets naar Github. Joomla-kernonderdelen, onderdelen van pakketten die bewust niet los worden gecontroleerd, en taalbestanden doen niet mee.

**Extensieoverzicht: blok "Centraal opgehaalde update-feeds"**
- Toont per feed van die site wanneer hij voor het laatst is opgehaald en hoe de laatste poging afliep.
- Knop **"Probeer nu via deze site"**: scant die ene site opnieuw en laat haar de feeds zelf ophalen, zonder wachttijd. Lukt het, dan krijgen alle sites de versie meteen.
- **Versie handmatig invullen**: link om de feed in de eigen browser te openen, plus een invoerveld voor het versienummer. Geldt meteen voor alle sites met die extensie, met de datum van invullen erbij; lukt het automatisch ophalen later weer, dan neemt dat het over. Alleen feeds die de monitor al uit een scan kent en alleen een geldig versienummer worden geaccepteerd.
- Na een herscan van één site vult "Versies ophalen" de centraal bekende versies voor alle sites aan.

**Overig**
- Bugfix in de versiebepaling van het scanscript: de XML-declaratie bovenaan een feed telde mee als versiekandidaat, waardoor bij een geïnstalleerde versie in de 1-reeks een te lage "nieuwste versie" kon worden getoond.
- Duidelijkere scanuitvoer bij een mislukte feed (HTTP-code, of "geblokkeerd door de botbeveiliging van de update-server"), en onder "=== MONITOR ===" welke versies de monitor heeft aangevuld.
- Database: tabellen `feed_terugval_cache`, `feed_centrale_hosts`, `feed_site_ips` en `feed_site_resultaten` (migratiestap 23; worden zo nodig ook bij het eerste gebruik aangemaakt).

### Site-instellingen: donkere modus en FTP/SFTP-tekst (`site_instellingen.php`)
- De domeinnaam onder de kop "Site-instellingen" en het resultaatvak onder de knop om het scanscript te versturen waren in donkere modus nauwelijks leesbaar (vaste kleuren). Ze gebruiken nu de themakleuren.
- Knop en meldingen noemen nu "FTP/SFTP", omdat het versturen ook via SFTP kan gaan.

### Neutrale voorbeelden in invoervelden en helppagina (`extensie_beheer.php`, `site_toevoegen.php`, `site_instellingen.php`, `extensies.php`, `help.php`)
- Voorbeeldteksten in invoervelden bevatten geen namen van extensies, versienummers of domeinnamen meer, maar een korte omschrijving van wat er ingevuld moet worden.
- De helppagina noemt geen specifieke extensies, hostingpartijen, versienummers of domeinnamen meer, en is bijgewerkt voor het bovenstaande (hoofdstuk 10 en 14).

### Getest
- Een nagebootste update-server die een "browser" een captchapagina geeft en Joomla de feed: het scanscript krijgt de feed in één verzoek. Een server die de Joomla-afzender met een serverfout afwijst: tweede poging slaagt. HTTP 403: geen tweede poging.
- Toerbeurt, wachttijden, voorkeur voor sites waar het lukte, "andere server na een uur", en acht tot tien gelijktijdige processen: steeds precies één toewijzing of verzoek per feed.
- Aangeleverde feeds: echte feed overgenomen en bij andere sites juist toegepast; captchapagina en willekeurige tekst geweigerd.
- Handmatige versie: opslaan, doorwerking naar alle sites en naar een verwante feed-URL, wissen, voorrang van een later automatisch opgehaalde feed, weigering van ongeldige invoer en van een onbekende feed.
- Volledige keten met een echte MariaDB (scan ontvangen, versies aanvullen, catalogus-opruiming, extensieoverzicht), inclusief een upgrade vanaf een database van de vorige versie.
- `php -l` op alle gewijzigde bestanden; regeleinden gecontroleerd (CRLF, geen `\r\r\n`).

## 1.28 - 2026-09-30

### Hostingoverzicht: de werkelijke hostingpartij in plaats van de technische naam erachter (`hosting_functies.php`, `hosting_overzicht.php`)
Het hostingoverzicht toonde soms de naam van het netwerk of datacenter in plaats van de partij waar het hostingpakket wordt afgenomen - bijvoorbeeld **ZXCS** voor sites bij Vimexx.
- **Vimexx in plaats van ZXCS**: de servers van Vimexx heten `webNNNN.zxcs.nl` en het netwerk heet "AS-ZXCS Stichting DIGI NL", maar de IP-blokken van die servers staan in RIPE op naam van VIMEXX (`NL-VIMEXX-SHARED`, `NL-VIMEXX-DEDICATED`). ZXCS wordt nu als infrastructuurpartij behandeld: de hostingpartij volgt per site uit de IP-toewijzing, zonder vaste koppeling ZXCS = Vimexx. Lukt die opzoeking niet, dan blijft er ZXCS staan.
- **Generiek: IP-toewijzing (RIPE)**: per server-IP wordt nu ook opgevraagd aan wie dát specifieke IP-blok is toegewezen (RIPEstat `whois`, het inetnum-object: netname, omschrijving, organisatie). Dat is vaak specifieker dan de netwerkeigenaar. Een hostingpartij die ruimte huurt bij een datacenter heeft het datacenter als netwerkeigenaar, maar het IP-blok staat meestal op naam van de hostingpartij zelf.
- **Datacenters en cloudplatforms tellen pas als laatste**: ZXCS, Previder, BIT, Serverius, WorldStream, Leaseweb, Hetzner, OVHcloud, DigitalOcean, Linode, Vultr, AWS, Google Cloud en Azure worden nog steeds herkend, maar alleen gebruikt als er geen specifiekere hostingpartij te vinden is (nieuwe `haalInfrastructuurpartijen()`). Wordt niets bekends herkend, dan wordt de naam uit de IP-toewijzing getoond, behalve als die nietszeggend is ("customer network", een adres) of de site zelf noemt; anders, zoals voorheen, de netwerkeigenaar.
- **Extra herkenning van servernamen**: `*.hstgr.io` (Hostinger), `*.kundenserver.de` (IONOS), `*.sgvps.net` (SiteGround), `argewebhosting.nl` (Argeweb), plus Contabo, Previder en BIT.
- De tooltip bij de hostingpartij toont nu ook aan wie het IP-blok is toegewezen, en de CSV-download heeft een extra kolom **IP-toewijzing**. Helppagina (hoofdstuk 13, Hostingpartij) bijgewerkt.
- Een mislukte opzoeking van de IP-toewijzing is geen fout: dan werkt de herkenning zoals voorheen (servernaam, FTP-server, netwerkeigenaar).

### Getest
- Nagebootste scenario's met echte RIPE-gegevens: Vimexx met en zonder servernaam (en zonder IP-toewijzing), een hostingpartij die ruimte huurt bij een datacenter (met en zonder bekende naam), servernamen van bekende partijen, een nietszeggend Leaseweb-klantblok, een IP-blok op naam van de site zelf, en een onbekende partij zonder IP-toewijzing.
- Woordgrenzen: klantdomeinen en bedrijfsnamen als "Orbit B.V." worden niet ten onrechte herkend.

## 1.27 - 2026-09-29

### Nieuw: hostingoverzicht (`hosting_overzicht.php`)
Nieuwe knop **🖥️ Hostingoverzicht** helemaal rechts in de samenvattingsbalk op de monitorpagina (op de regel met "Totaal sites", "Schoon", enz.). Opent een los overzicht met per website de **hostingpartij**, de **server** en het **IP-adres**, in dezelfde opzet als de monitorpagina (alle sites onder elkaar, gegevens in kolommen, met de tabs Eigen websites / Websites van anderen).
- **Alles live opgezocht, niets opgeslagen**: geen databasewijziging en niets nodig op de websites zelf. Het IP-adres komt uit de DNS van de domeinnaam (en van de FTP-server, als die is ingevuld), de servernaam uit de reverse DNS van dat IP-adres, en de netwerkeigenaar uit de openbare RIPE-gegevens (`stat.ripe.net`, gratis, zonder sleutel).
- **Hostingpartij** wordt herkend aan een bekende naam in de servernaam (bijv. `web0171.zxcs.nl` = ZXCS, `w82.rzone.de` = Strato), dan in de FTP-server, dan aan de netwerkeigenaar; lukt dat niet, dan wordt de (opgeschoonde) naam van de netwerkeigenaar getoond. Bij de naam staat (als tooltip) waaraan die herkend is.
- **Cloudflare en andere CDN's** (Cloudflare, Fastly, Akamai, Sucuri, Imperva) worden herkend aan hun netwerknummer: het IP-adres van de domeinnaam is dan niet de echte server, die wordt dan via de FTP-server bepaald. Zonder FTP-gegevens staat er eerlijk "Verborgen".
- **Waarschuwing bij een afwijkende FTP-server**: wijst de FTP-server naar een ander IP-adres dan de website, dan wordt dat gemeld (mogelijk verhuisde site met verouderde FTP-gegevens). Zo'n afwijkende FTP-hostnaam telt dan ook niet mee voor het bepalen van de hostingpartij.
- Per site een los verzoek (`hosting_info.php`, maximaal 4 tegelijk), zodat de pagina direct verschijnt en de rijen één voor één worden ingevuld zonder de PHP-tijdslimiet te raken. Het endpoint geeft de sessie direct vrij (`session_write_close()`), anders zouden de parallelle verzoeken toch op elkaar wachten.
- Sorteren op elke kolom, per hostingpartij een telling boven de tabel (klikbaar als filter), en een knop **CSV downloaden** (puntkomma-gescheiden met BOM, opent direct goed in Excel).
- **Filterpijltje ▾ bij de kolom Server**: uitklapmenu met alle servers (met aantal sites en een zoekveld), aan te vinken en toe te passen; het pijltje kleurt geel zolang het filter actief is. Werkt samen met het filter op hostingpartij.
- Bij elke kolomkop een **?-icoontje** met een korte uitleg en een link naar de bijbehorende uitleg op de helppagina (net als op de monitorpagina). Een klik op het icoontje of het filterpijltje sorteert de kolom niet.
- Datum én tijd van de laatste opzoeking ("Opgehaald op 29-09-2026 om 13:26").
- Lettergrootte gelijk aan de monitorpagina (14px, via de uniforme lettergrootte in `responsive_stijlen.php`): bijkomende regels zijn niet meer kleiner en geen apart monospace-lettertype meer, maar vallen op door een gedempte kleur.
- Nieuwe bestanden: `hosting_overzicht.php`, `hosting_info.php`, `hosting_functies.php`. Nieuw, beknopt hoofdstuk 13 op de helppagina met per kolom een eigen kopje (waar de ?-icoontjes naartoe linken); "Veelvoorkomende problemen" is nu hoofdstuk 14.

### Bugfix hostingoverzicht: verkeerde servernaam voor sites op dezelfde server als de monitor (`hosting_functies.php`)
Voor sites op dezelfde server als de monitor zelf toonde de kolom Server de naam uit de installatie-image van die server (bv. `clean-install.<leverancier>.net`) in plaats van de echte naam. Oorzaak: `gethostbyaddr()` kijkt eerst in `/etc/hosts` van de eigen server, en daar stond voor het eigen IP-adres nog die oude naam. De PTR-naam wordt nu rechtstreeks in de DNS opgevraagd (`dns_get_record()`, ook voor IPv6 en voor classless reverse-delegatie via een CNAME), met `gethostbyaddr()` alleen nog als terugval. Daarnaast wordt `witxl.nl` (de servernamen van Wned) nu als Wned herkend.

### Bugfix: scan stopte bij een groot PHP-bestand ("Allowed memory size exhausted") (`scan_template.php`)
Op een site met een groot PHP-bestand stopte het scanscript met een fatale geheugenfout. De monitor kreeg daardoor een HTTP 200 zonder herkenbaar scanresultaat en meldde "Onverwachte inhoud ontvangen". Oorzaak: `token_get_all()` (gebruikt om commentaar te negeren) kost 50 tot 100 keer de bestandsgrootte aan geheugen, en draaide via de lader-detectie op élk PHP-bestand.
- **Tokenizer begrensd**: `verwijderPhpCommentaar()`, `maskeerPhpCommentaar()` en `splitsInFunctieBlokken()` slaan de tokenizer over bij bestanden boven 1 MB (zelfde gedrag als zonder tokenizer: hooguit een extra treffer in commentaar, nooit een gemiste echte treffer).
- **Lader-detectie lichter**: de tokenizer draait alleen nog als de ruwe tekst een vermomde include bevat, in plaats van bij elk PHP-bestand. Dit maakt elke scan sneller en zuiniger.
- **PHP-bestanden boven 4 MB** worden niet meer inhoudelijk gescand, maar wel gemeld als VERDACHT ("te groot om inhoudelijk te scannen"), zodat een opgevulde achterdeur geen blinde vlek wordt.
- **Gzip-controle** pakt bestanden streamend uit en leest alleen de eerste 200 KB (voorheen werd het hele bestand in het geheugen uitgepakt).
- **`.htaccess`-controle** leest maximaal 1 MB per bestand.
- **Databestanden met een `die()`-kop worden overgeslagen** (`isNietUitvoerbaarPhpDatabestand()`). Het grote bestand bleek een Akeeba Backup-log (`*.log.php`); Akeeba (en Joomla zelf bij logs) geeft zulke bestanden een `.php`-extensie met `<?php die(); ?>` als eerste regel, zodat ze via de browser niets prijsgeven. Zo'n bestand kan nooit code uitvoeren, ook niet via include(), en is dus nooit een achterdeur. Herkenning op de eerste bytes van de inhoud, niet op pad of naam; een voorwaardelijke kop als `defined('_JEXEC') or die` telt bewust niet mee.

### Bugfix: rechtenafwijking leek een dubbele vermelding in de scanuitvoer (`scan_template.php`)
Bij de root-level items werd de reden niet getoond, waardoor een rechtenafwijking op een bestand dat ook als onbekend item in de lijst stond eruitzag als twee keer hetzelfde bestand (één keer met grootte "onbekend"). De reden staat er nu onder. Ook de telling "afwijkende rechten gesignaleerd" bij het extra scanpad telt de afwijkingen op het topniveau nu mee (gaf "0" terwijl er wel een in de lijst stond).

### Bugfix: een crash van het scanscript werd gemeld als ".htaccess-probleem" (`start_scan.php`)
Crashte het scanscript op de site (bv. geheugen- of tijdslimiet), dan gaf PHP de foutmelding terug als gewone pagina met HTTP 200, en meldde de monitor "Onverwachte inhoud ontvangen - mogelijk stuurt een .htaccess-bestand dit verzoek door". Nu herkent de monitor een PHP-fout ("Fatal error: ... on line N") in het antwoord en toont die letterlijk, met een korte uitleg bij een geheugen- of tijdslimiet. Dit werkt ook als de crash pas ná de kopregel van de scan gebeurt (die werd voorheen als "gestart" gemeld). Een antwoord dat het laatste blok van de scan bevat, telt nooit als crash, zodat een foutmelding in bv. een opgehaalde update-feed geen valse melding geeft. Een HTTP 500 (crash zonder zichtbare melding) wordt nu ook als waarschuwing gemeld in plaats van als "gestart".

### Getest
- Herkenning en opschoning van netwerkeigenaren (o.a. `CLDIN-NL Your Hosting B.V.`, `CLOUDFLARENET - Cloudflare, Inc., US`, `HETZNER-AS Hetzner Online GmbH, DE`) en servernamen, inclusief klantdomeinen die niet ten onrechte als hostingpartij mogen tellen (bijv. een eigen domeinnaam of `ftp.<klantdomein>`).
- Nagebootste scenario's: gewone site, site achter Cloudflare mét en zonder FTP-gegevens, verhuisde site met oude FTP-gegevens, domein dat alleen met www bestaat.
- Echte DNS-opzoekingen voor vier live sites, en de pagina in licht en donker thema.
- In de browser: serverfilter (Niets → twee servers aanvinken → Toepassen), gecombineerd met het hostingpartij-filter, en Filter wissen; ?-icoontjes openen de pop-up zonder te sorteren; berekende lettergrootte 14px in alle cellen.

## 1.26 - 2026-09-28

### Nieuwe detecties: verborgen laders, gzip-payloads en verstopte mappen (`scan_template.php`)
Na het opschonen van een zwaar besmette site meldde de monitor "Schoon", terwijl de site nog steeds een 503 gaf. Bij handmatig onderzoek bleek er een complete tweede laag besmetting te zitten die geen enkele bestaande controle zag: ruim veertig implantaten verspreid over bijna alle kernmappen. De werkwijze: één regel code (de *lader*) in een bestaand bestand laadt een elders verstopt, gzip-gecomprimeerd PHP-bestand (de *payload*) met een onschuldige extensie. Alles is ingebouwd op basis van de echte bestanden.
- **PATROON 28 - verborgen lader** (`detecteerVerborgenLader()`). Herkent `eval('goto L_xxxxxx; ...')` (code opgeknipt met goto-sprongen), een include via een stream-wrapper (`new class('compress.zlib://...')`, ook als die in een base64-reeks verstopt zit) en include/require met vermomde hoofdletters (`InCLuDe_onCE`). Base64-reeksen in de code worden daarvoor zelf gedecodeerd (`haalIngebedBase64Tekst()`), er wordt niets uitgevoerd. De melding noemt het pad van de payload en of die er nog staat. Staat de lader in een verder normaal bestand, dan vermeldt de melding dat je het bestand moet vervangen door een schoon exemplaar in plaats van het in quarantaine te zetten (anders valt de site uit).
- **Gzip-payload met misleidende extensie** (`controleerVermomdGecomprimeerdBestand()`). Elk bestand dat met de gzip-kop begint maar niet op `.gz`/`.tgz`/`.svgz` eindigt, wordt uitgepakt en gecontroleerd. Zit er PHP in, dan volgt ZEKER BACKDOOR; anders VERDACHT. Dit draait over de hele site en niet alleen in images/tmp, want de payloads stonden in `administrator/components/com_media` en `com_mails` (als `.less`, `.scss`, `.js`, `.png`, `.jpg`, `.webp`, `.htm`, `.html`).
- **Verborgen map** (`isWillekeurigeAanvallersMapnaam()`): een map met een willekeurige naam (kort voorvoegsel + 5-6 hex-tekens, zoals `app59b4fb` of `cache34f3bd`, of alleen hex/cijfers zoals `4ff9d` of `331736`) waar PHP in staat, ergens binnen de site.
- **PHP-bestand in een map voor statische bestanden** onder `media/` (`js`, `css`, `scss`, `less`, `images`, `img`, `fonts`, `icons`).
- **PATROON 27 - "aqua" remote file manager**: een complete achterdeur (voert meegestuurde code uit, kopieert zichzelf, schrijft `defines.php` en code bovenin `index.php`, geeft bestanden een nepdatum in 2020). Wordt nu bij naam gemeld.
- **`administrator/defines.php`** wordt gemeld als hij bestaat. Joomla laadt dit bestand automatisch vóór al het andere. De variant in de root werd al als onbekend PHP-bestand gemeld.
- **Bekijk-weergave** markeert nu ook goto-sprongen, stream-wrappers (`compress.zlib://` e.d.) en `new class('...')`.

### Bugfix: `.cagefs/tmp` werd niet echt doorzocht
Het extra scanpad scande in `.cagefs/tmp` alleen bestanden met een PHP-extensie. De daar aangetroffen achterdeur (zeven kopieën) had geen extensie (`.AOZ2rZ`) en werd dus overgeslagen. `.cagefs/tmp` geldt nu als uploadmap, zodat ook bestanden zonder extensie op PHP-code worden gecontroleerd. Voor bestanden zonder extensie is de groottegrens verhoogd van 60 KB naar 1 MB (de achterdeur was 61.997 bytes). Een verborgen bestand als `.AOZ2rZ` wordt ook niet langer gezien als bestand met de extensie "AOZ2rZ".

### Bugfix: `libraries/loader.php` en andere losse bestanden in `libraries/` vielen buiten de kernbestandvergelijking
Voor de vergelijking met het officiële Joomla-pakket werd alleen `libraries/src` gehasht, niet de PHP-bestanden direct in `libraries/` (`loader.php`, `bootstrap.php`, `cms.php`, ...). Juist `loader.php`, dat bij elke paginaweergave wordt geladen, bevatte een lader en werd daardoor niet als afwijkend gemeld. Die bestanden worden nu meegenomen.

### Getest
- Een nagebootste besmette site met de echte kenmerken: alle laders, payloads (inclusief "al weg"/"staat er nog"), verborgen mappen, het PHP-bestand in `media/.../js/`, `administrator/defines.php` en de aqua-achterdeur in `.cagefs/tmp` worden gevonden.
- Geen valse meldingen op een schone Joomla 5.4.8 (officieel pakket), Kunena, TCPDF, dompdf en phpseclib (samen ruim 15.000 bestanden). Legitieme `*.min.js.gz`-bestanden en fotomappen met jaar-maandnamen worden niet gemeld.

## 1.25 - 2026-09-21

### Bugfix: valse "ZEKER BACKDOOR"-melding op RSForm! (deel 2) - `components/com_rsform/controller.php`
Na 1.24 bleef `components/com_rsform/controller.php` (i.t.t. `helpers/rsform.php`, die 1.24 al oploste) nog steeds gemeld worden. Broncode opgevraagd en nagelopen: in `ajaxValidate()` wordt `$form` (via `$formId`, via `$post`) uiteindelijk afgeleid van `$_POST['form']` - RSForm zet de opgeschoonde formulierdata daar bewust op regel 189 (`$_POST['form'] = $post;`) terug, zodat plugins die zelf `$_POST` uitlezen ook de verwerkte waarden zien, en leest die op regel 200 (`$post = $_POST['form'];`) ook weer terug. `detecteerEvalOpVerzoekinvoer()` (1.24) volgt de gegevensstroom nu wél per functie, maar behandelde `eval($form->ScriptProcess)` daardoor alsof het `eval($form)` was: de regex in stap 3 pakt bij het herkennen van de ge-evalde variabele alleen de kale naam `\$(\w+)` en negeert alles wat erna komt, dus `->ScriptProcess` viel gewoon weg.
- Het verschil is essentieel: `$formId` bepaalt alleen **welk bestaand, door de sitebeheerder zelf geschreven PHP-script** (RSForm's ingebouwde "PHP Scripts"-functie, opgeslagen in de database) wordt uitgevoerd, niet de inhoud ervan. De request bepaalt de *keuze*, niet de *code*. Dat is precies wat `eval($form->AdminEmailScript)`/`eval($form->UserEmailScript)`/`eval($form->ScriptProcess)`/`eval($form->ScriptProcess2)` in RSForm doen - gedocumenteerd, bewust gedrag van de extensie.
- Fix: stap 3 van `detecteerEvalOpVerzoekinvoer()` telt `eval($obj->property)` niet langer mee, ook niet als `$obj` besmet is (`(?!\s*->)` na de gevangen variabelenaam). `eval($var['sleutel'])` (array-toegang op een besmette variabele) blijft wél gewoon meetellen - alleen `->eigenschap`-toegang wordt uitgesloten. Dit is een bewuste, conservatieve keuze in dezelfde lijn als de rest van deze detectie: `eval($obj->property)` waarbij de property zelf rechtstreeks (zonder tussenvariabele) met `$obj->property = $_POST[...]` gevuld is, wordt met deze fix (net als voorheen - dat werd al niet gevolgd) niet gedetecteerd. Een echte aanvaller kan zo'n eigenschap sowieso niet vullen zonder al ergens anders op de site te kunnen schrijven.
- Getest: de 8 gevallen uit 1.24 (allemaal nog steeds correct), plus 3 nieuwe - het echte `ajaxValidate()`-fragment uit RSForm!'s broncode (nu schoon), `eval($data['code'])` op een besmette array (blijft terecht gemeld) en de eerder genoemde bekende blinde vlek (rechtstreekse `$obj->property = $_POST[...]`, blijft gemist, zoals voorheen).

## 1.24 - 2026-09-21

### Bugfix: valse "ZEKER BACKDOOR"-melding op RSForm! (PATROON 26 keek over het hele bestand)
Ontdekt kort na uitrol van 1.23: `administrator/components/com_rsform/helpers/rsform.php` en `components/com_rsform/controller.php` werden op meerdere sites gemeld als "EVAL OP VERZOEKINVOER - ZEKER BACKDOOR", terwijl dit gewoon de officiële RSForm!-bestanden zijn. Oorzaak: `detecteerEvalOpVerzoekinvoer()` (PATROON 26, zie 1.23 hieronder) volgde welke variabelen uit `$_REQUEST`/`$_POST`/`$_GET`/`$_COOKIE` komen en of zo'n variabele bij `eval()`/`assert()` terechtkomt, maar deed dat over het **hele bestand** in plaats van per functie. RSForm gebruikt de veelvoorkomende naam `$value` op twee geheel losstaande plekken in hetzelfde bestand: in de formulierverwerking wordt die gevuld vanuit `$_POST`, en in een heel andere functie (`isCode()`, die een door de sitebeheerder zelf ingevoerde PHP-validatiesnippet uit de database uitvoert) heet een lokale parameter toevallig ook `$value` en gaat naar `eval()`. Twee ongerelateerde variabelen met dezelfde naam werden zo als één gegevensstroom gezien.
- Nieuwe helperfunctie `splitsInFunctieBlokken()` (op basis van `token_get_all()`, dezelfde tokenizer als `verwijderPhpCommentaar()`) splitst de code op in de body van elke functie/methode/closure, plus de rest daarbuiten als los blok. `detecteerEvalOpVerzoekinvoer()` volgt de gegevensstroom nu per blok in plaats van bestandsbreed.
- Getest op 8 gevallen: het RSForm-patroon (nu schoon), de oorspronkelijke mod_version-achterdeur uit 1.23 (nog steeds gemeld, zowel binnen één functie als top-level zonder functie-wrapper), een rechtstreekse `eval($_GET[...])` binnen een functie, een achterdeur verstopt in een closure, een achterdeur via een `base64_decode()`-tussenstap - en twee losse legitieme gevallen (een vaste `eval()`-string naast onafhankelijk verzoekgebruik elders in het bestand; twee gelijknamige `$value`-variabelen die geen van beide met elkaar te maken hebben). Alle 8 gaven het verwachte resultaat.

## 1.23 - 2026-09-19

### Bugfix: het rapport toonde bestanden die al lang weg waren (scanresultaat kwam niet of half aan)
Na het opruimen van een geïnfecteerde site bleven `httpd.conf` en een clustermelding in het beveiligingsrapport staan, terwijl er via FTP niets meer van te vinden was. Onderzoek liet zien dat het rapport altijd de stand van de laatste *ontvangen* scan toont (min wat via de knoppen Verwijder/Quarantaine/Blokkeer is verwerkt) - en dat scanresultaten van deze site niet (volledig) aankwamen, zonder dat de monitor dat liet merken. Drie oorzaken tegelijk aangepakt:
- **Scanscript (`scan_template.php`): een mislukte verzending was onzichtbaar.** Bij een fout stond er alleen "kon monitor niet bereiken (curl-fout)", zonder reden. Nu staat er de echte curl-fout (nummer + tekst), de tijdsduur en de grootte van het resultaat. Verder: het tijdsbudget voor het verzenden is ruimer en past zich aan (was een vaste 15 seconden, terwijl de monitor bij een grote site honderden extensieregels en duizenden bestand-hashes moet verwerken), er volgt één nieuwe poging bij een mislukte verzending, `Expect: 100-continue` is uitgezet (een grote POST wacht daar bij sommige proxy's/firewalls tevergeefs op) en HTTP/1.1 wordt expliciet gebruikt. Ook: één bestandsnaam met ongeldige UTF-8 (bij aangevallen sites niet ondenkbaar) laat het hele resultaat niet meer stranden op een lege POST-body.
- **Monitor (`ontvang_scan.php`): half verwerkte scanresultaten.** Zodra de verbinding vanaf de site wegviel (bv. door die 15 seconden), kon de webserver de verwerking halverwege afbreken. Alles wat daarna kwam bleef achter: zo bleef een site met een lege extensielijst achter ("Extensies: geen gevonden") of met maar een deel van de bestand-hashes (wat weer voor onterechte "afwijkt van andere sites"-meldingen kan zorgen). Nu draait de verwerking door ook als de site allang is afgehaakt (`ignore_user_abort`), en staan extensies, catalogus en bestand-hashes in één transactie: het lukt volledig of de vorige, complete stand blijft staan. Getest tegen een echte database: bij een fout halverwege bleef eerst 2.209 van 5.867 hashes over, nu blijven alle 5.867 van de vorige scan staan; een gewone verwerking van een site met 297 extensies en 5.867 hashes ging van 0,78 naar 0,23 seconde.
- **Herscan (`beveiliging.php` + `start_scan.php`): "opnieuw gescand" zonder dat er resultaat was.** De knop wachtte een vaste 10 seconden, meldde dan dat de website opnieuw was gescand en herlaadde de pagina - ook als er helemaal geen nieuw resultaat was aangekomen, waarna de oude gegevens er als vers uitzagen. Nu wacht de knop tot de monitor het nieuwe resultaat echt heeft ontvangen (maximaal 90 seconden; `start_scan.php?site_id=..&alleen_status=1` geeft daarvoor alleen het tijdstip van het laatst ontvangen resultaat terug, zonder een scan te starten). Komt er niets aan, dan volgt een duidelijke waarschuwing met de tijd van de laatst ontvangen scan en de tip om via 📋 onder "=== MONITOR ===" te kijken. Het overzicht en het extensieoverzicht hebben nog hun eigen, vaste wachttijd.
- Onder de tellers van het rapport staat nu een korte uitleg dat het rapport de laatst *ontvangen* stand toont, en dat een clustermelding (verzamelmelding) blijft staan totdat een nieuwe scan is aangekomen, ook als je de losse bestanden al hebt verwerkt.
- De scanuitvoer toont voortaan ook `Scanmap: ...` (het echte pad dat wordt gescand), zodat te controleren is of je via FTP wel in dezelfde map kijkt.

### Bugfix: het scanscript kon uit een cache worden beantwoord (er "draait" een scan, maar er gebeurt niets)
Na twee of drie keer opnieuw scannen toonde het 📋-icoontje nog steeds de scanuitvoer van 17:00, terwijl de laatste scan om 19:27 was. Een scanscript dat echt draait toont bij `Start:` altijd de actuele tijd - een steeds gelijke uitvoer betekent dus dat het antwoord uit een cache komt en het script niet is uitgevoerd. Dat geldt voor de browser, een CDN als Cloudflare of de paginacache van de hostingpartij (LiteSpeed, Varnish, nginx) en voor een scan die de monitor zelf start: `start_scan.php` vroeg steeds exact dezelfde URL op, en het scanscript stuurde nergens headers mee die caching verbieden. Zo'n scan werd als "gestart" gemeld terwijl er niets draaide en er dus ook geen nieuw resultaat aankwam. Welke cache-laag het op die site is, is uit de bestanden niet af te leiden; onderstaande maatregelen werken tegen alle genoemde varianten:
- **Scanscript (`scan_template.php`)** stuurt nu `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`, `Pragma: no-cache` en `Expires: 0` mee, plus `X-LiteSpeed-Cache-Control: no-cache` en `X-Accel-Expires: 0` voor LiteSpeed en nginx. De uitvoer bevat daarnaast `Ververs-code: ...` (de code die de monitor meestuurt). De aanroep na het zelf-bijwerken vraagt zelf ook expliciet om een niet-gecachet antwoord.
- **Elke aanroep een unieke URL:** `bepaalVerseScanUrl()` (`instellingen_functies.php`) plakt `?nc=<willekeurige code>` achter de URL van het scanscript. `start_scan.php` gebruikt die, samen met `Cache-Control: no-cache`/`Pragma: no-cache` in het verzoek, en het 📋-icoontje op de monitorpagina vernieuwt de code bij elke klik (`onmousedown`), zodat het icoon nooit een oud antwoord uit de browser toont.
- **Controle dat het antwoord vers is** (`bepaalVerouderdAntwoord()` in `start_scan.php`): de monitor waarschuwt nu als de responsheaders een cache-treffer melden (o.a. `X-Cache: HIT`, `CF-Cache-Status: HIT`, `X-LiteSpeed-Cache: hit`, `Age` > 0, twee ids in `X-Varnish`), of als een bijgewerkt scanscript de meegestuurde ververs-code niet terugecho't. Dat geeft de oranje waarschuwing "verouderd antwoord" met de tip om het scanscript uit te sluiten van caching of te hernoemen (Site-instellingen), in plaats van ten onrechte "scan gestart". Een nog niet bijgewerkt, ouder scanscript zonder deze kenmerken krijgt bewust géén waarschuwing (die werkt zichzelf bij zodra hij één keer echt draait). Getest op tien scenario's, waaronder een bijgewerkt script met oude ververs-code, drie soorten cache-headers en een gewone verse scan.

### Bugfix: het Bekijk-venster bleef in beeld na verwijderen
- Na Quarantaine, Blokkeer of Verwijder (los of via een bulkactie) verdween de rij wel, maar het venster met de inhoud van dat bestand bleef staan. `sluitViewerVoorPad()` sluit dat venster nu zodra het getoonde bestand is verwerkt; bij "Bekijk" op meerdere items tegelijk verdwijnt alleen het paneel van dat ene bestand. Bekijk je een ander bestand, dan blijft het venster gewoon staan.

### Bugfix: valse backdoor-meldingen op bekende libraries, zonder blinde vlek
- **PATROON 20 (`document.write(unescape(...))`)** sloeg aan op elke Composer-library die e-mailadressen tegen spam-harvesters versleutelt - Smarty's `{mailto}` (o.a. in Event Gallery): 58 van de 100 officiële Smarty-releases (3.1.41 t/m 5.8.4) werden gemeld. `isPhpZijdigeUnescapeEncoder()` herkent die onschuldige vorm nu op *inhoud*: het argument moet een door PHP zelf ingevoegde variabele zijn die het bestand zelf als `%XX`-reeks opbouwt (`.= '%' . bin2hex(...)`), zonder letterlijke `%XX`-payload, zonder `$_GET`/`$_POST`/`$_REQUEST`/`$_COOKIE` en zonder `eval`/`assert`/`base64_decode`/`gzinflate`/`gzuncompress`/`str_rot13`. Elke andere vorm wordt nog steeds gemeld (getest op negen kwaadaardige varianten, waaronder een officiële Smarty met een injectie erbij).
- **`isBekendeLegitiemeLibrary()`** sloeg bestanden op *pad* volledig over. Dat was een blinde vlek: een aanvaller kan onder hetzelfde pad een backdoor neerzetten en die werd dan nooit meer gescand. Nu wordt een bestand alleen overgeslagen als de SHA-256 van zijn inhoud (regeleinden genormaliseerd) exact overeenkomt met een vastgelegde, gecontroleerde variant - gecontroleerd voor de meegeleverde phpQuery 0.9.5 van com_rsseo (5.671 van de 5.689 regels letterlijk gelijk aan de officiële broncode; de rest zijn PHP 8-compatibiliteitspatches). Elke wijziging, ook één teken, laat het bestand direct weer onder de gewone scan vallen. De pad-uitsluitingen voor Smarty 5 en voor `regularlabs/helpers/assignments/php.php` zijn verwijderd (het laatste bestand bestaat niet meer in de huidige Regular Labs Library en `src/Php.php` scant schoon). Een andere kopie van een bekend bestand toevoegen: `tr -d '\r' < bestand.php | sha256sum` en de hash bij de bestandsnaam zetten.

### Bugfix: valse meldingen door het woord in een opmerking (PATROON 9 en 12)
- Smarty's `modifier.capitalize.php` (Event Gallery) noemt `create_function()` alleen in een bugnotitie in de commentaarkop en werd daardoor als "VERDACHT" gemeld; phpQuery beschrijft in een docblock `$_SERVER['HTTP_HOST'] (if any)`, wat op een dynamische functie-aanroep leek. `verwijderPhpCommentaar()` haalt het commentaar weg met PHP's eigen tokenizer, en PATROON 9 (dynamische aanroep + superglobal) en PATROON 12 (`create_function`) kijken nu alleen nog naar uitvoerbare code. Commentaar wordt nooit uitgevoerd, dus dit kan niet worden misbruikt om code te verstoppen. Van de 75 officiële versies van `modifier.capitalize.php` blijven er twee gemeld (3.1.14 en 3.1.15): die roepen `create_function()` echt aan.
- Bijkomend voordeel: een commentaar tússen de naam en het haakje (`create_function/**/(...)`, `$c[11]/**/(...)`) was een omweg die de oude patronen niet zagen; die wordt nu wél gemeld. Getest, samen met de eerdere negen kwaadaardige varianten, een verstopte `?>`-truc en varianten met `#`-commentaar.

### Bugfix: de eigen prullenbak gaf een valse "hernoemaanval"-melding (risico 95)
Na het opruimen bleef één verzamelmelding over: `__s1784789290 - achtervoegsel, 10x aangetroffen`. Dat waren geen aangetroffen bestanden op de site, maar de monitor zelf. De quarantaine en prullenbak (`_scan_beheer/`) geven een verplaatst bestand de naam `<datum_tijd_id>__<oorspronkelijke naam>`. Tien verwijderde proefbestanden van de aanvaller, allemaal met de oorspronkelijke naam `s1784789290.gif` (het getal is het tijdstip van de aanval, 23 juli 2026 08:48), leken daardoor op tien bestanden met hetzelfde achtervoegsel `__s1784789290`. De massaal-hernoemen-detectie liep als enige controle wél door `_scan_beheer/` heen (de gewone scan en de massale-upload-detectie sloegen die map al over). Nagebootst met dezelfde bestandsnamen: exact dezelfde melding, inclusief de teller 10 (het bestand met de dubbele extensie `.php.gif` telt niet mee). Nu wordt `_scan_beheer/` overgeslagen; een échte hernoemaanval buiten die map (zes bestanden met hetzelfde achtervoegsel) wordt nog steeds gemeld.

### Nieuw: een index.php als enige bestand in een verdubbelde map wordt nu gemeld
- Een niet-generieke verdubbelde mapnaam (bv. `tmpl/tmpl`) stond tot nu toe altijd als "ter info: waarschijnlijk legitiem" (Kunena, GeSHi, PHPMailer herhalen hun eigen naam). Op een gehackte site bleven zo dertien `index.php`-bestanden staan, terwijl zeven daarvan in Joomla-kernmappen liggen waar noch het bestand noch de dubbele map in het officiële Joomla 3.10.12 voorkomt, en alle dertien exact dezelfde wijzigingstijden hebben (18 juni 04:01/04:02 en 26 juni 11:11) als de al bevestigde webshells uit dezelfde aanvalsgolf. Een aanvaller maakt een verse map aan en zet daar alleen zijn eigen `index.php` in.
- `isLosPhpBestandInMap()`: bevat een verdubbelde map niets anders dan één bestand met de naam **`index.php`** (eventueel met een `index.html` en/of `.htaccess`), dan volgt een echte melding ("LOS PHP-BESTAND IN VERDUBBELDE MAP", risico 80) met Bekijk/Quarantaine/Verwijder, in plaats van "ter info".
- **Bewust alleen `index.php`.** Een eerste versie van deze regel meldde elk enkel PHP-bestand in een verdubbelde map en gaf daarmee een valse melding op iCagenda: iCagenda zet elke class in een eigen map met dezelfde naam (`administrator/components/com_icagenda/src/Utilities/Utilities/Utilities.php`, namespace `iCutilities\Utilities`; net zo `src/Utilities/Menus/Menus.php`). Dat is een gangbare indeling (map = klassenaam, bestand = klassenaam.php), dus één bestand in een dubbel genoemde map zegt op zichzelf niets. Alle dertien aangetroffen aanvallersbestanden heetten `index.php`. Getest: het iCagenda-bestand is weer "ter info", een losse `index.php` (ook met `index.html` ernaast) wordt gemeld, een verdubbelde map met veel bestanden, met een tweede PHP-bestand, of onder `vendor/` blijft ongemoeid, en de generieke namen (`models/models` enz.) werken zoals voorheen.

### Bugfix: een geïnfecteerd kernbestand kreeg geen herstelknop (kernvergelijking ontbrak na een herscan)
Op een gehackte site bleek de `index.php` in de webroot bleek een cloaking-injectie te bevatten (markeringen `2DUAN_START` en `__B26_2DUAN_JOOMLA_INLINE_GUARD_MK_v2__`). De code haalt met de bezoekersgegevens (host, url, user-agent, referrer, IP, taal en of het een Googlebot/Bingbot is) inhoud op bij een externe server, en toont die in plaats van de site. De server-URL staat versleuteld in de code (percent-gecodeerd én met de ROT13-tabel: `%34%31%35%35…` wordt `4155-en-v4-1.20cs.xyz`); bekende SEO-tools worden overgeslagen. Daarnaast zit er een achterdeur in: met `?pwd=…&gv=<naam>` wordt een bestand met die naam aangemaakt, met als inhoud de naam zelf (en dus ook een PHP-bestand). Het rapport meldde alleen "index.php" als losse vondst; de verwachte knop "Automatisch vervangen door origineel" ontbrak. Oorzaken en oplossingen:
- **De vergelijking met het officiële Joomla-pakket (`vergelijk_kern_bestanden.php`) draaide alleen via de cronjob.** Na een herscan (beveiligingsrapport, monitorpagina, extensieoverzicht) en na "Scan en check sites" werd `kern_bestand_afwijkingen` dus niet bijgewerkt, ook al staat in dat bestand dat het als laatste stap na `haal_versies_op.php` hoort te draaien. De stap is nu toegevoegd aan alle vier de ketens; een fout in die stap laat de scan niet mislukken. In het beveiligingsrapport komt bij een mislukte vergelijking een waarschuwing met de reden.
- **De downloadlink van het officiële pakket klopte niet met die van Joomla.** Op de officiële downloadsite van Joomla staat de bestandsnaam met streepjes (`Joomla_3-10-12-Stable-Full_Package.zip`, `Joomla_5-3-3-Stable-Full_Package.zip`); de monitor probeerde `Joomla_3.10.12-…` met punten. `downloadOfficieelJoomlaPakket()` probeert nu eerst de officiële naam en pas daarna de oude als terugval, en meldt per poging waarom het mislukte. Ook bleef bij elke download een leeg tijdelijk bestand achter (`tempnam()`); dat wordt nu opgeruimd. Getest tegen een lokale nep-downloadserver (streepjes-naam, alleen puntjes-naam, te klein bestand, bestaat niet). Of de oude naam met punten op de downloadsite zelf óók werkte, heb ik niet kunnen nagaan.
- **De cloaking-melding zegt nu wat je moet doen:** vervang het kernbestand door het officiële exemplaar (sectie "Kernbestanden", knop "Automatisch vervangen door origineel"), niet verwijderen of blokkeren (dan valt de site uit), en zoek daarna in andere bestanden naar dezelfde code.
- **Eén bestand, één melding.** Na de nieuwe patronen stond dezelfde `index.php` drie keer in het rapport: als backdoor ("/index.php", PATROON 24), als losse "bestand"-melding ("index.php", zonder slash, cloaking-controle) en in de kernbestanden-sectie. De laatste is bedoeld (die beantwoordt een andere vraag: wat is het origineel, en heeft de herstelknop), maar de eerste twee zijn hetzelfde bestand onder twee namen, en een actie op de ene rij nam de andere niet mee. `voegDubbeleVondstenSamen()` voegt meldingen over hetzelfde bestand (namen genormaliseerd naar een voorlopende slash) samen tot één vondst met alle redenen en het hoogste risico; een melding die verder nergens is gemeld blijft staan.
- **Joomla-ingangsbestanden zijn in het rapport beschermd.** Bij `index.php`, `administrator/index.php`, `api/index.php` en `includes/app.php` ontbreken Quarantaine, Blokkeer en Verwijder, en ze worden bij een bulkactie overgeslagen: die zetten de hele site uit, zoals eerder bij een bulk-verwijdering van alle vondsten met de Smarty-bestanden gebeurde. Bekijk blijft beschikbaar; het herstel loopt via de kernbestanden-sectie ("Automatisch vervangen door origineel").
- **Twee nieuwe gedragspatronen (24 en 25):** geen enkel bestaand patroon herkende deze injectie, dus dezelfde code in een ander bestand (bv. een template-`index.php`) was onopgemerkt gebleven; alleen `index.php` en `administrator/index.php` gingen door de cloaking-controle. PATROON 24: de ROT13-vertaaltabel met `strtr()` i.c.m. een externe aanvraag. PATROON 25: `md5()` van een verzoekparameter, vergeleken met een vaste hash, gevolgd door een bestand schrijven met een naam uit het verzoek. Getest op de volledige officiële Joomla 3.10.12-broncode (3.753 PHP-bestanden: geen enkele nieuwe melding), op de geïnfecteerde index.php, op varianten met alleen het ene of het andere deel, en op legitieme code met alleen een deel van de kenmerken.

### Bugfix: een eval() op verzoekinvoer ontsnapte aan alle inhoudspatronen (PATROON 26)
Op een gehackte site bleek `administrator/modules/mod_version/mod_version/index.php` (787 bytes, een van de dertien "ter info"-bestanden uit dezelfde aanvalsgolf) bleek een echte achterdeur: `$a = $_REQUEST["jack"]; ... @eval($a);`, dus iedereen die de URL met `?jack=<php-code>` aanroept, voert willekeurige PHP uit, zonder wachtwoord. Tussen invoer en eval() zit een "versleutel"-stap: tweemaal XOR met dezelfde sleutel (`JLJBLDALS`), wat de invoer gewoon teruggeeft (nagerekend: `x(x($in)) === $in`), dus alleen bedoeld om handtekening-scanners te misleiden. De `proc_open()` eromheen is schijn.
- Op inhoud meldde het scanscript dit bestand **niet** (getest): PATROON 18 (XOR + eval + invoer) zoekt één vaste lusvorm waarin de modulo en de XOR direct bij elkaar staan, en hier staan ze op aparte regels. Alleen de locatieregel uit de vorige stap (een losse `index.php` in een verdubbelde map) ving hem, dus dezelfde code in een gewoon bestand of met een ander bestand ernaast was onopgemerkt gebleven.
- `detecteerEvalOpVerzoekinvoer()` volgt in plaats van een vaste vorm de gegevensstroom: welke variabelen worden (ook indirect, via functieaanroepen, samenvoegen of een `foreach` over `$_POST`) uit `$_REQUEST`/`$_POST`/`$_GET`/`$_COOKIE` gevuld, en wordt zo'n variabele aan `eval()` of `assert()` gegeven? Ook een rechtstreekse `eval(... $_POST[..] ...)` telt. Commentaar wordt genegeerd. De melding krijgt risico 100 en wordt aan een eventuele andere reden (bv. de locatie) toegevoegd, zodat je ziet dat het echt om een achterdeur gaat.
- Getest: het bestand zelf, zeven kwaadaardige varianten (rechtstreeks, via variabele, ketting van omzettingen, `assert`, `foreach`, aangepaste XOR, samenvoegen) en vijf legitieme voorbeelden (eval op een letterlijke tekst of op een lokaal bestand, alleen in commentaar, een `==`-vergelijking, geen eval) blijven schoon. Op de officiële Joomla 3.10.12-broncode (3.753 PHP-bestanden) en phpseclib geen enkele nieuwe melding.

### Nieuw: "Bekijk" markeert de opvallende regels in de code, met uitleg in gewoon Nederlands
Ook iemand zonder PHP-kennis moet in de getoonde code kunnen zien waar de achterdeur zit.
- **Het scanscript bepaalt de regels** (`bepaalVerdachteRegels()`, in het antwoord van de "Bekijk"-actie als `markeringen`), omdat de patronen daar al zitten en het bestand daar wordt ingelezen. Per regel staat er een korte uitleg bij, bijvoorbeeld "eval() voert tekst uit als PHP-code" of "Hier komt invoer van de bezoeker binnen (in $a)".
- **Twee niveaus:** *geel* (zwarte tekst op geel, altijd, ook in de donkere modus): de kern van een achterdeur (code uitvoeren met `eval()`/`assert()`, een opdracht op de server draaien, een geüpload bestand opslaan, een bestand laden of schrijven op aanwijzing van de bezoeker, een verborgen wachtwoordcontrole, de ROT13-tabel) én de regels waarlangs invoer van de bezoeker bij zo'n `eval()` terechtkomt (bron, elke tussenstap en het eind). *Gele rand*: een aanwijzing die ook in gewone code voorkomt (invoer van de bezoeker lezen, verborgen tekst omzetten, gegevens bij een andere server ophalen, cloaking-kenmerken). Boven de code staat een legenda die dat uitlegt, met de melding dat het een automatische aanwijzing is en geen bewijs.
- **Voorbeeld** (`mod_version/mod_version/index.php`, de `?jack=`-achterdeur): precies vier regels worden geel, elk met uitleg: waar de invoer binnenkomt, de twee "versleutel"-stappen en de `eval()`. Een officiële Joomla-`index.php`, een bestand met alleen commentaar en Smarty's `modifier.capitalize.php` krijgen geen markering; Smarty's `{mailto}` krijgt één gele rand met de uitleg dat het ook van e-mailverbergers bekend is.
- **Regelnummers kloppen** ook bij Windows-regeleinden en commentaar (`maskeerPhpCommentaar()` behoudt alle regeleinden). Bestanden zonder PHP-tag (bv. een afbeelding met PHP erin wordt wel gemarkeerd zodra er `<?` in staat) en mappen krijgen geen markering. Het werkt in het losse Bekijk-venster én bij "Bekijk" op meerdere items; de vergelijkingsweergave van extensies is ongewijzigd. Bij een site met een nog niet bijgewerkt scanscript ontbreekt het veld `markeringen` en verschijnt de code gewoon zoals voorheen.
- **Veiligheid:** de weergave escapet zowel de code als de uitleg (de inhoud van een verdacht bestand is per definitie niet te vertrouwen). Getest met `<script>`- en `<img onerror>`-tekst in beide: die verschijnt als tekst, niet als element.

### Kleine verbetering
- `CURL_HTTP_VERSION_2TLS` (pas beschikbaar vanaf libcurl 7.47) is afgeschermd met `defined()`. Op oudere servers gaf elke aanroep een waarschuwing in de scanuitvoer (op een site achttien keer per scan), en in PHP 8 zou het een fatale fout zijn.

## 1.22 - 2026-09-15

### Nieuw: bredere backdoor-detectie
- Patroon 23 toegevoegd: `str_rot13()` + `eval()` in hetzelfde bestand. Vangt de "Undergrounds Webshells"-familie, waarvan de volledige payload (file manager, shell-commando's, upload) verborgen zit in een heredoc-string die pas at runtime via `str_rot13()` ontsleuteld en met `eval()` uitgevoerd wordt - geen enkel bestaand patroon (die allemaal op leesbare eval/base64-combinaties zoeken) pikte dit op, want er is geen zichtbare `eval(base64_decode`, geen tweede `<?php`-tag in de brontekst (ontstaat pas na decodering) en geen herkenbare stringmarkers (ook die zijn zelf geROT13'd). Een specifiekere variant (`eval("?>".str_rot13(...))`, de exacte vingerafdruk van deze webshell-familie) wordt als "ZEKER BACKDOOR" gemeld, de kale combinatie als "VERDACHT". Ontdekt op een klantsite: in eerste instantie 4 kopieën gevonden via handmatig zoeken (buiten de monitor om), later - na toevoeging van dit patroon - bleken het er 18 te zijn, verspreid over administrator/components, modules, libraries, plugins en media. Dezelfde scan signaleerde op diezelfde site, via de al langer bestaande cloaking-detectie op kernbestanden, ook een toegevoegd blok in `index.php` dat bij Googlebot-achtige user-agents een los bestand laadde dat op zijn beurt een externe, voor deze site op maat gemaakte spampagina ophaalde en rechtstreeks aan de zoekmachine toonde

### Bugfix: monitor-notificatiemail kwam in spam terecht
- De e-mail met scanresultaten (verstuurd vanaf het eigen mailaccount naar het notificatieadres) miste een aantal standaard MIME-headers (`MIME-Version`, `Content-Transfer-Encoding`), en het onderwerp (dat een emoji bevat) was niet volgens RFC 2047 gecodeerd - beide zorgden ervoor dat spamfilters (o.a. Outlook/webmail) de mail eerder als verdacht beoordeelden. Beide nu toegevoegd/gecorrigeerd

## 1.21 - 2026-09-02

### Correctie: Kunena-modules niet zichtbaar in de extensielijst
- `mod_kunenalatest` en `mod_kunenasearch` werden ten onrechte samengesmolten met het hoofdpakket `pkg_kunena` in `versie_vergelijk_functies.php`, waardoor ze nooit als eigen regel te zien waren in de "Volledige extensielijst (van derden)" - ook niet wanneer ze zelf een eigen, bekende versiestatus hadden
- Er is nu een controle toegevoegd die vlak vóór het samenvoegen checkt of de kandidaat-module al een eigen bekende status heeft. Is dat zo, dan wordt de samenvoeging overgeslagen en blijft de module als losse regel staan, met zijn eigen correcte status - naast het hoofdpakket, zonder dat daar iets van wordt overschreven

### Nieuw: extensiebestand-afwijkingen losgekoppeld van de Beveiliging-kolom
- De Beveiliging-kolom vermengde twee heel verschillende soorten signalen: bevestigde dreigingen (backdoor-scan, kernbestand-afwijkingen t.o.v. het officiële Joomla-pakket) en het zachtere "dit bestand wijkt af van de meerderheid van andere sites" (kan net zo goed een andere editie/build zijn). Hierdoor kon een groot deel van de sites als "aandacht nodig" gemarkeerd staan, wat een vertekend beeld gaf
- Extensiebestand-afwijkingen (t.o.v. andere sites) staan nu in een eigen, nieuwe kolom "Bestandsafwijkingen" (🗂️): een groen vinkje bij nul afwijkingen, anders een oranje ⚠️ met aantal - bewust oranje in plaats van rood, om dit visueel te onderscheiden van een bevestigde dreiging in de Beveiliging-kolom. Sorteerbaar, ook op mobiel (met een eigen label in de kaartweergave)
- Kernbestand-afwijkingen t.o.v. het officiële Joomla-pakket blijven bewust wél in de Beveiliging-kolom staan - dat is een vergelijking met iets officieels, dus een hard signaal, net als de reguliere verdachte-bestanden-scan
- Nieuwe, aparte teller bovenin ("Bestandsafwijkingen", oranje), los van "Aandacht nodig - beveiliging" - die laatste telt nu realistischer, omdat de zachtere signalen er niet meer in meetellen

## 1.20 - 2026-09-01

### Nieuw: preciezere status bij gegroepeerde extensies met meerdere onderdelen
- Bij een gegroepeerd product (bijv. een component + losse subplugins die samen tot één rij worden getoond) kon de status "Niet up-to-date" verschijnen naast een versiepaar dat juist al gelijk was - het onderdeel dat de afwijkende status veroorzaakte was namelijk niet per se hetzelfde onderdeel als het representatieve onderdeel waarvan het versienummer werd getoond. Er wordt nu per groep bijgehouden welk specifiek onderdeel (met bijbehorend versiepaar en naam) een "Niet up-to-date"-status veroorzaakt, en dát paar wordt getoond in plaats van altijd het representatieve onderdeel. Werkt op alle plekken waar statussen van meerdere onderdelen samenkomen (representatieve groepering, samengevoegde producten, auteurs-clusters). Met dank aan Astrid voor deze uitbreiding

### Correctie: overbodige catalogusrijen die nergens een eigen feed nodig hebben, bleven soms voor altijd staan
- Een catalogusrij zonder eigen update-feed-URL werd tot nu toe alleen opgeruimd als ALLE sites er automatisch al een nieuwste versie voor hadden gevonden. Een sleutel die op elke site altijd onderdeel van een pakket is (en dus nooit los een eigen feed nodig heeft, bijv. sommige AcyMailing-subplugins) voldeed daar echter nooit aan - zijn ruwe versie-kolom wordt namelijk nergens rechtstreeks gevuld. Zo'n rij wordt nu ook opgeruimd zodra hij nergens (meer) als los, zelfstandig product wordt gezien, ongeacht die versie-kolom

### Nieuw: zichtbaar wanneer een extensie zonder feed elders al is opgelost
- Bij "Extensietabel beheren", gefilterd op één specifieke site, staat nu een badge bij een sleutel zonder eigen feed-URL als een ANDERE site 'm al automatisch heeft opgelost - voorheen was niet te zien of zo'n rij op déze site al onnodig was, of dat de rij alleen nog bestaat omdat een andere site 'm nog echt nodig heeft

### Nieuw: samengevoegde weergave voor bulk-afwijkende extensiebestanden
- Wijken van één extensie+versie tientallen bestanden tegelijk op precies dezelfde manier af van de meerderheid (bijv. een hele Pro-editie versus Core-editie, of een tussentijdse hotfix-build)? Dan werden die voorheen stuk voor stuk als losse rij getoond - bij een extensie met honderden bestanden kon dat de werkelijk interessante uitzondering (een los bestand met een ANDERE site-verdeling dan de rest van diezelfde extensie) volledig doen verdrinken. Bestanden met een identieke site-verdeling worden nu samengevoegd tot één rij (bijv. "73 bestanden wijken op dezelfde manier af"); een bestand dat niet in zo'n samengevoegde rij past, blijft gewoon los zichtbaar en valt daardoor juist op
- Nieuwe knop "Vertrouw alle N", boven de "Actie"-kolom van zo'n samengevoegde rij: vertrouwt alle bestanden uit die rij in één keer (met voortgangsteller), in plaats van elk bestand los te moeten aanklikken
- Een nog niet beoordeelde samengevoegde rij staat standaard opengeklapt in plaats van alleen een dichtgeklapt pijltje - zodat iemand die voor het eerst op deze pagina kijkt niet zou missen dat er nog bestanden op beoordeling wachten. Eenmaal vertrouwde rijen (in de aparte "vertrouwd"-sectie) blijven dichtgeklapt, want daar hoeft niets meer mee te gebeuren

## 1.19 - 2026-09-01

### Correctie: gegroepeerde extensie toonde "Niet up-to-date" naast een versiepaar dat al gelijk was
- Bij een gegroepeerd product kon de status "Niet up-to-date" verschijnen terwijl het getoonde versiepaar zelf al identiek was (bijv. `plg_content_phocaopengraph` toonde 6.0.3/6.0.3, maar toch "Niet up-to-date"). Oorzaak: een uitgeschakeld zusje-onderdeel binnen dezelfde groep (in dit geval `plg_system_phocaopengraph`, 6.0.0→6.0.1) veroorzaakte de afwijkende status, zonder dat zijn eigen versiepaar ergens zichtbaar was
- Nieuwe helperfuncties (`combineerStatus()`, `maakStatusBron()`) zorgen er nu voor dat bij een "Niet up-to-date"-groep het versiepaar van het daadwerkelijk afwijkende onderdeel wordt getoond, in plaats van altijd het representatieve onderdeel. Een nieuw veld `status_onderdeel_naam` geeft aan welk onderdeel de status veroorzaakt. Doorgevoerd op alle plekken waar groepering plaatsvindt (representatieve groepering, samengevoegde producten, auteurs-clusters)

### Correctie: "Extensies zonder update-feed" toonde onterecht pakketonderdelen
- Bij "Extensietabel beheren" toonde de lijst "Extensies zonder update-feed" ten onrechte losse pakketonderdelen (bijv. `com_jce`, `plugin_jceacym`, `plugin_acymtriggers`) voor sites waar die onderdelen via `package_id` al correct onder een hoofdpakket geregistreerd stonden. Het sitefilter gebruikt nu dezelfde uitsluitingscontroles als het echte extensieoverzicht

### Nieuw: badge bij extensies zonder feed die op deze site al zijn opgelost
- Bij "Extensietabel beheren", gefilterd op één specifieke site, staat nu een badge bij een sleutel zonder eigen feed-URL als de nieuwste versie op déze site al bekend is (bijv. via een ander pakketonderdeel) - ook als de rij nog bestaat omdat een andere site 'm nog nodig heeft
- De badge houdt nu ook rekening met pakket-overerving: een pakket zonder eigen automatische versie (zoals Package AcyMailing) wordt alsnog als "opgelost" herkend zodra een onderdeel ervan (zoals `mod_acym`) wél een bekende nieuwste versie heeft
## 1.18 - 2026-08-29

### Nieuw: kernbestand-integriteitscontrole tegen het officiële Joomla-pakket
- Naast de al bestaande meerderheidsvergelijking tussen eigen sites (die vergelijkt wat de MEESTE gemonitorde sites hebben) is er nu een vergelijking die rechtstreeks naast het officiële, ongewijzigde Joomla-pakket van de officiële downloadsite legt. Dit dekt twee situaties die de meerderheidsvergelijking niet kan zien: een Joomla-kernversie die maar op één site voorkomt (geen andere site om tegen te vergelijken), en een kernbestand dat toevallig op alle gemonitorde sites identiek is aangepast (dan "wint" die afwijkende versie gewoon de meerderheid)
- Het scanscript hasht nu, naast de al bestaande, aan extensierijen gekoppelde kernbestanden, ook de rauwe kernmappen die niet als los geregistreerde extensie voorkomen (`libraries/src`, `includes`, `api`, `cli`, en de root-`index.php`-bestanden)
- Het officiële Joomla-pakket wordt centraal op de monitor zelf gedownload (nooit op de klantsites) - en dat slechts één keer per daadwerkelijk voorkomende Joomla-kernversie, niet bij elke scan
- Nieuwe sectie "🛡️ Kernbestanden vs. officieel Joomla-pakket" op het beveiligingsrapport, met een aparte, ingeklapte "vertrouwd"-sectie (bereikbaar via de bestaande knop "Toon ook vertrouwde items") voor eerder handmatig beoordeelde afwijkingen
- Nieuwe pagina "Kernbestand vergelijken" (bereikbaar via "🔍 Bekijk verschil"): haalt automatisch zowel het actuele bestand van de site als het officiële bestand op, toont het verschil regel voor regel, en geeft een automatisch, leesbaar oordeel op basis van bekende verdachte patronen (`eval()`, `base64_decode()`, shell-/procesuitvoeringsfuncties, stream-wrapper-trucs, `chr()`-obfuscatie). Dit oordeel is een hulpmiddel, geen garantie - het geeft alleen een concrete waarschuwing bij een treffer, nooit een "veilig"-oordeel bij het uitblijven daarvan
- Vanaf die pagina zijn twee acties mogelijk na handmatige beoordeling: "✅ Vertrouwen (negeren)" (blijft zichtbaar onder "Toon ook vertrouwde items", maar telt niet meer mee als actieve waarschuwing) en "🔧 Automatisch vervangen door origineel" (schrijft het officiële bestand terug, na eerst een herstelbare backup te hebben gemaakt in dezelfde quarantainemap die de rest van de monitor al gebruikt)
- Beveiligingskolom op de indexpagina toont nu ook "⚠️ X kernbestand(en) wijken af van officieel pakket" en, apart, "✅ X afwijkend(e) kernbestand(en) vertrouwd"
- De al langer bestaande meerderheidsvergelijking tussen eigen sites (sectie "Afwijkende bestanden (vergeleken met andere sites)") sluit Joomla-kernbestanden nu bewust uit - die kregen daar, sinds kernbestanden ook worden gehasht t.b.v. de nieuwe officiële-pakket-vergelijking hierboven, ongewenst een tweede, onafhankelijke melding met een eigen (ontbrekend) vertrouwen-mechanisme

### Belangrijke correctie: kernbestand-vergelijking gaf duizenden valse meldingen
- Een eerdere versie van de vergelijking meldde ook elk officieel kernbestand dat een site niet had aangeleverd als "ontbreekt" (mogelijk verwijderd) - maar het scanscript hasht, om het tijdsbudget van een scan behapbaar te houden, niet gegarandeerd de volledige Joomla-kern. "Niet gehasht" en "niet aanwezig op de site" bleken twee verschillende dingen die niet zomaar te onderscheiden waren, wat op een gewone site meteen tienduizenden valse meldingen gaf. De "ontbreekt"-detectie is verwijderd; alleen bestanden die de site daadwerkelijk heeft gehasht én aantoonbaar afwijken worden nog gemeld

### Bugfix: dubbele uitvoering bij een lopend zelf-bijwerkmoment
- Een gloednieuwe actie (zoals het automatisch vervangen van een kernbestand) tegen een site die het scanscript nog niet had bijgewerkt, kon **twee keer** worden uitgevoerd: het bestaande zelf-bijwerkmechanisme voert de actie zelf al eenmaal intern uit tijdens het bijwerken, maar geeft dat resultaat terug verpakt in platte tekst - waardoor een eigen herhaalpoging de actie een tweede keer aanriep. Bij de meeste bestaande, verplaatsende acties (quarantaine/blokkeer/verwijderen) bleef dit onzichtbaar (een tweede poging vindt dan gewoon niets meer en faalt stil), maar bij de nieuwe, kopiërende "vervangen"-actie leidde dit zichtbaar tot dubbele backup-regels. Er wordt nu eerst geprobeerd het al-uitgevoerde resultaat uit de zelf-bijwerkrespons te halen, vóórdat er (als allerlaatste redmiddel) alsnog een nieuwe aanroep gedaan wordt
- "Herstel" van zo'n backup-regel werkte bovendien nooit: de bestaande hersteldiscussie weigert als er al iets op de oorspronkelijke plek staat (normaal een teken dat er iets misgaat), maar bij deze backup staat daar altíjd al iets - de zojuist teruggeschreven, officiële inhoud. Hersteld met een apart geval dat voor dit backuptype juist overschrijft in plaats van weigert
- De vertrouwd-teller op de indexpagina bleef na een succesvolle "vervangen"-actie soms ten onrechte meetellen: als een bestand eerst vertrouwd was en pas later ook nog vervangen werd, bleef de oude vertrouwd-markering (voor de inmiddels al vervangen inhoud) staan. Wordt nu opgeruimd zodra een bestand daadwerkelijk vervangen is

## 1.17 - 2026-08-28

### Belangrijke correctie: extensies werden bij elke scan stilzwijgend genegeerd
- Een opschoonstap in `ontvang_scan.php` die catalogus-rijen zonder eigen update-feed-URL opruimt zodra geen enkele site ze meer als terugval nodig heeft, zette zo'n rij voorheen op "genegeerd" in plaats van 'm te verwijderen. Daardoor verdwenen extensies (o.a. Akeeba Backup, Sourcerer, JCE) na een scan van alle sites keer op keer weer uit het overzicht, ook nadat ze handmatig hersteld waren - "genegeerd" betekent een bewuste keuze van de gebruiker, niet "deze rij is administratief overbodig". Zo'n rij wordt nu verwijderd in plaats van genegeerd; hij wordt vanzelf opnieuw aangemaakt (actief, niet genegeerd) zodra een site 'm ooit weer nodig heeft. Dank aan Astrid voor het vinden van de oorzaak
- Een gegroepeerde rij (bijv. een component + een losse plugin die samen tot één rij zijn samengevoegd) verdween voorheen al volledig uit het overzicht zodra **één** van de onderliggende onderdelen genegeerd was - ook als een ander onderdeel van diezelfde rij nog gewoon actief was. Een rij verdwijnt nu pas als écht elk onderliggend onderdeel genegeerd is
- De snelle "Negeren"-knop op het extensieoverzicht van een site zelf negeerde bij zo'n samengevoegde rij alleen het representatieve onderdeel, niet de rest - waardoor de rij na het klikken op "Negeren" alsnog zichtbaar bleef. Negeren en herstellen raken nu altijd alle onderliggende onderdelen van een rij tegelijk

### Bugfix: "Toon ook genegeerde extensies" deed op het extensieoverzicht van een site niets
- De knop riep de onderliggende functie al langer aan met een derde parameter die aangeeft of genegeerde extensies getoond moeten worden, maar die functie accepteerde die parameter niet - PHP negeert een overtollig argument stilzwijgend, dus de knop had feitelijk nooit effect. Genegeerde extensies kregen bovendien nooit een herkenbaar veld mee, waardoor zelfs een reparatie van de eerste bug alsnog de verkeerde knop (altijd "Negeren", nooit "Herstel") getoond zou hebben. Beide zijn nu gerepareerd

### Nieuw: bewust lokale update-feed-URL's, los van de gedeelde Github-catalogus
- Bij "Extensietabel beheren" staan nu twee opslaanknoppen bij het update-feed-veld (zichtbaar zodra er een Github-token is ingesteld bij Configuratie): "Opslaan met GitHub Sync" (zoals voorheen) en "Opslaan zonder GitHub Sync" - voor een uitzondering die alleen op déze installatie hoeft te gelden, bijv. een alternatieve of nogmaals ingevulde feed-URL om een structurele externe blokkade te omzeilen (zoals het bekende Kunena/Strato-503-probleem) zonder dat de gewone, werkende Github-versie van diezelfde extensie bij andere installaties wordt overschreven
- Elke rij toont een badge (☁️ gedeeld via Github / 💻 lokaal) die in één oogopslag laat zien hoe de huidige URL is opgeslagen
- Een bewust-lokale rij wordt gegarandeerd nooit mee gepusht naar Github (een push stuurt normaliter de hele lokale catalogus in één keer mee, dus dit moest expliciet worden uitgesloten) en verschijnt ook nooit in de importmelding die nieuwe/gewijzigde Github-items aanbiedt - dus geen risico dat een bewuste uitzondering per ongeluk weer wordt overschreven
- Is er geen Github-token ingesteld, dan blijft gewoon de originele, enkele "Opslaan"-knop staan - de keuze is dan toch niet relevant

### Nieuw: zichtbaar tijdstip waarop een extensie genegeerd is
- Elke genegeerde extensie toont voortaan ook sinds wanneer dat zo is (kolom `genegeerd_op`) - handig bij het achterhalen of iets recent of allang geleden is weggenegeerd

### Bugfix: extensies met een correct opgehaalde nieuwste versie toonden soms toch "Onbekend"
- Sommige extensies registreren hun Joomla-update-locatie niet op het pakket zelf, maar op een verborgen onderdeel daarbinnen (bijv. JCFAQ: de update-site staat gekoppeld aan het component, niet aan het pakket eromheen) - pakketonderdelen worden normaal gesproken niet los getoond, dus zonder correctie bleef zo'n pakket voor altijd "Onbekend" tonen terwijl de nieuwste versie via het onderdeel allang bekend was. Dit is nu opgelost met een terugval die de verborgen onderdelen alsnog raadpleegt
- Joomla's Smart Search-indexerplugins (`plg_finder_folder` en de rest van de "finder"-pluginmap) werden ten onrechte als onbekende extensie van derden gezien, omdat hun manifest de naam van de oorspronkelijke ontwikkelaar behoudt in plaats van "Joomla! Project" - nu correct als Joomla-kernonderdeel herkend
- JCE-plugins zonder ingevuld auteursveld en zonder pakket-koppeling (een restant van installaties van vóór JCE als verpakt pakket werd uitgebracht) werden nooit aan het hoofdpakket gekoppeld, en bleven daardoor voor altijd "Onbekend" tonen terwijl het pakket zelf allang een bekende, actuele versie had

### Wijziging: robuustere feed-ophaalronde op trage hostingomgevingen
- Bij een structureel trage host kon het sequentiële tijdsbudget voor het ophalen van update-feeds op zijn - de resterende extensies (vaak dezelfde, laat-alfabetische) kregen dan blijvend geen nieuwste versie te zien. Deze resterende feeds worden nu, als er nog voldoende tijd over is, in één keer parallel geprobeerd in plaats van na elkaar - de wachttijd van die laatste ronde wordt dan bepaald door de traagste ENKELE feed, niet door de opgetelde tijd van alle resterende feeds samen

## 1.16 - 2026-08-21

### Wijziging: tmp-map legen is nu automatisch, niet meer een handmatige knop
- De in 1.15 toegevoegde knop "🧹 Leeg temp-map" op het beveiligingsrapport is verwijderd. In plaats daarvan wordt de tmp-map van een site nu automatisch, stil, geleegd vlak voordat het eigenlijke scannen van die site begint - bij elke scan, dus zowel bij "Scan en check sites" (alle sites) als bij een losse herscan van één site. Dit voorkomt dat de map alleen schoon wordt gemaakt wanneer iemand daar expliciet aan denkt, en scheelt bovendien de extra herscan die de knop na het legen zelf altijd al triggerde (de eerstvolgende scan gebeurt nu toch al meteen na het legen). De onderliggende, al eerder geteste verwijderlogica (hardcoded pad naar `$startMap/tmp`, geen enkele input vanuit `$_POST`) is ongewijzigd hergebruikt.

## 1.15 - 2026-08-20

### Nieuw: genegeerde extensies inzien en herstellen vanaf de site-eigen extensiepagina
- Een extensie "negeren" (bijv. omdat de update-feed structureel niet te bereiken is, zoals bij Kunena via het bekende Strato-503-probleem) is een GLOBALE instelling - ze verdwijnt daarmee voor alle sites uit het overzicht, niet alleen de site waar je op dat moment naar keek. Tot nu toe was de enige manier om dat terug te zien of ongedaan te maken via de aparte pagina "Extensietabel beheren"
- Op de extensiepagina van een individuele site staat nu ook een knop "Toon ook genegeerde extensies" (net als op "Extensietabel beheren"), die de genegeerde extensies van déze site alsnog toont - herkenbaar gemarkeerd, en altijd onderaan de lijst
- Bij een getoonde genegeerde extensie staat een "Herstel"-knop in plaats van "Negeren" - een genegeerde extensie direct vanaf deze pagina weer terugzetten, zonder om te hoeven naar "Extensietabel beheren"

### Belangrijke veiligheidscorrectie: destructieve knoppen bij verzamelmeldingen
- Een verzamelmelding over meerdere gelijk-grote bestanden (bijv. bij een geautomatiseerde uploadtool) kreeg per ongeluk dezelfde Quarantaine/Blokkeer/Verwijder-knoppen als een losse bestandsvondst. Door een haakje in de meldingsnaam werd bij het opslaan/teruglezen het verkeerde stuk tekst als doelwit herkend, waardoor "Verwijder" de HELE map zou wissen (inclusief alle legitieme content erin) in plaats van alleen de gemelde bestanden. Verzamelmeldingen krijgen nu een eigen type ("cluster") zonder deze knoppen, en de naam bevat ook geen haakjes meer als extra vangnet

### Nieuw: bredere backdoor-detectie
- chr()-gebaseerde obfuscatie herkend (bijv. Godzilla/Behinder-stijl webshells die functienamen via chr()-aanroepen samenstellen in plaats van als platte tekst)
- Massale-upload-detectie: vijf of meer verschillend genoemde bestanden met exact dezelfde bestandsgrootte in de images-/tmp-map wordt gemeld als mogelijk teken van een geautomatiseerde uploadtool (met een hogere risicoscore als daarbij ook nog drie of meer verschillende extensies worden gebruikt - kenmerkend voor een tool die test welke extensie de server als PHP uitvoert)
- Dubbele-extensietruc herkend: een niet-PHP(-achtig) bestand (bijv. een afbeelding in een uploadmap) wordt nu ook op inhoud gecontroleerd op een verstopte PHP-openingstag, inclusief bestanden zonder enige extensie
- Stringconcatenatie-detectie verbreed van exact twee naar twee-of-meer aaneengeschakelde stukken (ving voorheen bijv. `"sys"."tem"` maar niet `"as"."se"."rt"`)
- Kale `<?`-korte-tag wordt nu ook herkend (naast `<?php` en `<?=`), specifiek om een aangetroffen GIF/PHP-polyglot-webshell te vangen die deze vorm gebruikte om detectie op de langere `<?php` te omzeilen

### Nieuw: "Leeg temp-map"-knop
- Direct vanaf het beveiligingsrapport de tmp-map van een site in één klik legen (met bevestiging vooraf en een voortgangsbalk), gevolgd door een automatische herscan - handig omdat deze map vaak de bron is van kortstondige, verdachte bestanden

### Nieuw: detectie van verzwakkende php.ini-/.user.ini-bestanden
- Een `php.ini`- of `.user.ini`-bestand ergens in de site (site-breed, niet beperkt tot de images-/tmp-map) wordt nu gecontroleerd op een combinatie van beveiligingsverzwakkende directives (`disable_functions` leeggemaakt, `open_basedir` uitgeschakeld, de verouderde/niet-bestaande `safe_mode`-directive, `exec`/`shell_exec=on`) - kenmerkend voor een "sleutel zonder slot" die een aanvaller vlak vóór of samen met een webshell plaatst, om hostingbrede restricties lokaal te omzeilen. Pas bij twee-of-meer signalen tegelijk wordt dit gemeld, zodat een legitieme, handmatige php.ini (bijv. voor hogere uploadlimieten) niet ten onrechte wordt geraakt. Ontdekt bij een echt aangetroffen exemplaar, samen met een bijbehorende upload-webshell, diep genest onder `components/com_media/`

### Belangrijke correctie: upload-backdoor-detectie miste bestanden onder com_media
- De detectie van een upload-backdoor (`move_uploaded_file()` achter een aangepaste requestparameter) sloot voorheen ELK pad uit waar toevallig "com_media" in voorkwam - ook een diep geneste, willekeurig genoemde aanvallersmap eronder. Een "kale" upload-backdoor zonder verdere opvallende code zou daardoor volledig gemist zijn geweest. De uitzondering geldt nu alleen nog voor de daadwerkelijk bekende Joomla-kernsubmappen van com_media (`src`, `tmpl`, `views`, e.d.)

### Bugfix: valse meldingen bij legitieme afbeeldingen met Adobe XMP-metadata
- De uitzondering voor XML-/XMP-achtige "processing instructions" (bijv. de standaard Adobe-metadataheader in JPG-/PDF-bestanden) herkende voorheen alleen specifieke, met naam genoemde varianten (`xml`, `xpacket`). Een derde, veelvoorkomende variant (`adobe-xap-filters`) viel daar per ongeluk buiten, waardoor gewone foto's met volledige XMP-metadata (bijv. boekomslagen) alsnog als verdacht werden gemeld. Dit wordt nu generiek herkend (elke XML-achtige processing instruction zonder PHP-variabele erin), in plaats van steeds een nieuw woord aan een groeiende lijst toe te voegen

### Bugfix: valse meldingen bij ongecomprimeerde afbeeldingen (BMP)
- De leesbaarheidscontrole (bedoeld om toevallige binaire ruis te onderscheiden van echte, verstopte code) was afgestemd op gecomprimeerde beeldformaten (JPEG) en kon bij een ongecomprimeerd formaat als BMP toch net over de drempel schieten, puur door de rauwe pixelbytes van een lichtgekleurde afbeelding. Voor de ambigue kale `<?`-vorm wordt nu aanvullend geëist dat het venster ook daadwerkelijk iets bevat dat op PHP-syntax lijkt (een `$variabele` of een bekende gevaarlijke functienaam) - dit raakt bewust niet de veel specifiekere `<?php`/`<?=`-vormen

### Bugfix: valse clustermeldingen bij nooit-uitvoerbare bestandstypen en bekende extensiecache
- Een verzamelmelding over gelijk-grote bestanden werd ook gegenereerd voor bestandstypen die sowieso nooit als PHP uitgevoerd kunnen worden (bijv. een cluster .mid-bestanden van verschillende koorstemmen van hetzelfde muziekstuk, toevallig even groot omdat ze uit dezelfde notatiesoftware komen). Clusters waarbij alle bestanden een audio-, video- of documentextensie hebben, worden nu overgeslagen
- Dezelfde melding verscheen ook voor de automatisch gegenereerde back-up-/cachemappen van JCH Optimize (een veelgebruikte Joomla-snelheidsextensie: back-uplogo's, WebP-conversies, responsive-formaten) - deze mapnamen zijn nu, net als de al bestaande cache-/logmappen, uitgesloten van de clusterdetectie (de gewone inhoudscontrole blijft daar wel gewoon actief)

### Bugfix: favicon niet gevonden bij een site met een URL-submap
- Voor een site in een submap (bijv. de submap `clabbers`) genereert Joomla zelf al een root-relatief favicon-pad dat de submap correct bevat (bijv. `/clabbers/templates/.../favicon.ico`). De monitor plakte de URL-submap daar tot nu toe altijd nog eens los voor, waardoor die er dubbel in kwam te staan (`.../clabbers/clabbers/...`) - een niet-bestaande URL, dus viel de favicon-detectie stil terug op het standaard Joomla-icoontje. Een pad dat met een enkele "/" begint wordt nu, zoals in elke browser, als root-relatief ten opzichte van het kale domein behandeld; alleen een pad zónder leidende "/" krijgt de URL-submap er nog terecht voor geplakt

### Bugfix: HTTP 301/302-omleiding bij een beheeractie of scan-herhaling
- Ontbrak `CURLOPT_FOLLOWLOCATION` bij een beheeractie (Bekijk/Quarantaine/etc.) op een site met een omleiding (bijv. http naar https, of www/non-www), dan kreeg de monitor de omleidingspagina zelf terug in plaats van ooit het echte scanscript te bereiken - met een verwarrende "Onverwacht antwoord (HTTP 301)"-melding tot gevolg. De omleiding wordt nu gevolgd, mét behoud van de verstuurde POST-gegevens (geheime code, actie, pad): zonder die aanvullende voorziening zou curl een POST-verzoek bij een omleiding stilzwijgend hebben omgezet in een kale GET, waardoor de actie alsnog niet zou zijn aangekomen

### Bugfix: "Vertrouwen" bij een verzamelmelding werkte niet blijvend
- De hash die bepaalt of een melding al eerder als vertrouwd is gemarkeerd, bevatte bij een verzamelmelding de weergegeven wijzigingsdatum - die was echter altijd het moment van de scan zelf, niet een echte bestandsdatum. Daardoor veranderde de hash bij elke scan, en verscheen een al vertrouwde, ongewijzigde verzamelmelding bij de eerstvolgende scan gewoon weer als nieuw. De wijzigingsdatum telt nu, net als bij een map, niet meer mee in de hash van een verzamelmelding. Als bonus toont de kolom "Gewijzigd" bij een verzamelmelding nu ook de daadwerkelijke, meest recente bestandsdatum binnen het cluster, in plaats van altijd het scanmoment

### Nieuw: sorteren op de Joomla-kolom
- De kolomkop "Joomla" op de monitorpagina is nu, net als "Extensies" en "Beveiliging", klikbaar om te sorteren - zowel op het grote scherm als op mobiel (dezelfde sorteeroptie in de mobiele keuzelijst), met exact hetzelfde resultaat op beide. Sorteert in meerdere stappen na elkaar: eerst op hoofdversie (bijv. Joomla 3.x altijd boven 5.x, ook als die 3.x-site toevallig zelf al de nieuwste 3.x is - "up-to-date binnen de eigen hoofdversie" zegt niets over hoe oud die hoofdversie zelf is), dan binnen dezelfde hoofdversie op status (verouderd vóór onbekend vóór up-to-date), en tot slot op het exacte versienummer; sites zonder Joomla-versiedata staan altijd onderaan
- Het driehoekige sorteerpijltje bij de kolomkoppen wordt nu bij alle vier de sorteerbare kolommen altijd getoond (voorheen alleen bij de op dat moment actieve kolom), zodat meteen duidelijk is welke kolommen sorteerbaar zijn
- Bugfix: alle sorteerlinks (en de mobiele keuzelijst) namen de actieve categorie ("Eigen websites"/"Websites van anderen") niet mee - sorteren vanaf het tabblad "Websites van anderen" zette je daardoor onbedoeld terug op "Eigen websites". Ook het wisselen tussen beide tabbladen zelf behoudt nu de gekozen sortering, in plaats van steeds terug te vallen op alfabetisch
- Nieuw: nogmaals klikken op een al actieve kolomkop draait de sorteervolgorde nu volledig om (inclusief de tiebreaks) - bij álle vier de kolommen, ook "Domein". Op mobiel staat hiervoor een apart ⇅-knopje naast de keuzelijst. Klikken op een andere kolom start altijd weer in de normale richting voor die kolom

### Bugfix: eerste klik na een zelf-bijwerking van het scanscript
- Zodra het scanscript zichzelf net had bijgewerkt, kon de EERSTE klik op een beheeractie (Bekijk/Quarantaine/etc.) een onterechte "onverwacht antwoord"-melding geven, ook al werkte de actie feitelijk gewoon. Er wordt nu automatisch, stil, één keer opnieuw geprobeerd

### Overige verbeteringen
- Knopuitlijning op het beveiligingsrapport gecorrigeerd
- Voortgangsbalk op het beveiligingsrapport, gelijk aan de monitorpagina
- De verouderde instructietekst "Druk op 'Check sites'..." bij het starten van een scan is verwijderd - zowel de monitorpagina als het beveiligingsrapport doorlopen de vervolgstappen inmiddels zelf automatisch, dus dit advies was achterhaald en verscheen daardoor verwarrend genoeg juist zichtbaar bij een foutmelding (bijv. een HTTP 403)

## 1.13 - 2026-08-12

### Nieuw: onderscheid tussen eigen websites en websites van anderen
- Elke site is voortaan óf een "Eigen website", óf een "Website van een ander" (bijv. een klantsite die je beheert, maar niet per se altijd volledig up-to-date/schoon hoeft te houden). Instelbaar bij zowel het toevoegen van een nieuwe site als bij Site-instellingen
- Op de indexpagina staan nu twee tabbladen onder de titel ("🏠 Eigen websites" / "👤 Websites van anderen", met aantallen) - er wordt steeds maar één categorie tegelijk getoond, en "Scan en check sites" raakt alleen de sites in de zichtbare categorie. De cronjob blijft, ongeacht deze indeling, gewoon alle sites uit beide categorieën in één keer scannen/checken
- "Websites van anderen" tellen niet meer mee in de samenvattingsmail over verouderde extensies/beveiligingsissues - zo blijft die mail overzichtelijk voor de sites die je zelf volledig schoon wil houden
- "Terug naar monitor" vanaf een site-detailpagina brengt je nu terug naar het tabblad waar die site ook daadwerkelijk bij hoort, in plaats van altijd naar het standaardtabblad

### Nieuw: URL-submap (los van het FTP-pad)
- Sommige sites staan niet in de webroot zelf, maar in een submap die WEL rechtstreeks via de domeinnaam bereikbaar is (bijv. de submap `bieb` in plaats van de webroot). Daarvoor is een nieuw veld "URL-submap" toegevoegd, zowel bij het toevoegen van een nieuwe site als bij Site-instellingen
- Belangrijk onderscheid met het al bestaande FTP-pad: dat bepaalt alleen waar het scanscript op de schijf van de server terechtkomt: dit nieuwe veld bepaalt via welke URL de monitor het scanscript daarna kan *bereiken* om te scannen - die twee hoeven niet overeen te komen, en deden dat bij een concreet aangetroffen site ook niet (het scanscript stond in de juiste map, maar bleef "nog niet gescand" tonen doordat de monitor het via de verkeerde URL probeerde te benaderen)
- Alle plekken die de site rechtstreeks benaderen houden hier nu rekening mee: het starten van een scan, het handmatig openen van het scanscript, quarantaine/blokkeer/verwijder-acties, de website-/SSL-status (inclusief de favicon-herkenning), Joomla-versiedetectie via het adminpad, het Admin Tools-overzicht, en de links in de samenvattingsmail

### Bugfix: taalbestand-update kon soms toch nog verkeerd getoond worden
- Bij sites met veel geïnstalleerde extensies kon de tijdslimiet die eerder is ingebouwd (om HTTP 500-fouten bij "alles scannen" te voorkomen) er willekeurig voor zorgen dat het scanscript nog niet aan de controle van het (Nederlandse) taalbestand toekwam vóórdat de tijd op was - waardoor de oude, mogelijk verouderde waarde per ongeluk bleef staan. Dit verklaarde waarom de melding soms bij een paar sites verscheen en na een volgende scan weer vanzelf verdween. Het taalbestand wordt nu, ongeacht het aantal overige extensies, altijd als eerste gecontroleerd, dus nooit meer het slachtoffer van deze tijdslimiet

### Overige verbeteringen
- Een aantal pagina's die actuele scangegevens tonen (extensieoverzicht, klantrapport, beveiligingsrapport, extensiecatalogus, site-instellingen) misten expliciete "nooit cachen"-headers, in tegenstelling tot de indexpagina die deze al langer had. Zonder die headers kon een browser (of een cache-laag ertussen) zo'n pagina bewaren en later verouderd hergebruiken, ook na een nieuwe scan met correcte gegevens - nu op alle vijf gelijkgetrokken
- Bugfix: meerdere meldingskleuren (groen/rood/geel) bij "Zoek automatisch" (FTP-pad/scanpad) waren met een vaste hexcode ingesteld, die in donkere modus nauwelijks leesbaar was. Nu via twee nieuwe, thema-bewuste kleurvariabelen (`--thema-geel`, `--thema-rood`), op alle 19 betrokken plekken
- Bugfix: de twee keuzeknoppen bij de nieuwe categorie-indeling (eigen/anderen) werden door een al bestaande, algemene opmaakregel op deze pagina's niet gelijk breed getrokken - nu met een steviger opgezette layout gerepareerd

## 1.12 - 2026-08-08

### Nieuw: catalogus delen tussen meerdere installaties (Github)
- De update-feed-URL's uit "Extensies beheren" kunnen nu automatisch gedeeld worden met andere, losse installaties (bijv. van een collega) via een gedeelde catalogus op Github - lezen werkt direct, zonder instellingen. Alleen wie zelf mag bijdragen (schrijfrechten) vinkt bij Configuratie "Ik ben beheerder met schrijfrechten op deze repository" aan en vult daar een eigen token in
- Bij wijzigingen aan een gedeelde update-feed-URL wordt (met token) automatisch gepusht; andersom verschijnt bij "Extensies beheren" een melding zodra er nieuwe/gewijzigde items op Github staan

### Extra scanpad: geen keuze meer, altijd aan
- Het vinkje "Ook automatisch buiten de website-root scannen" is vervangen door een puur informatief balkje - dit stond toch al feitelijk altijd aan te raden, en de automatische detectie zorgt er sowieso al voor dat dit nooit verder gaat dan bij het hostingaccount hoort. Bestaande sites zijn automatisch meegenomen, niemand hoeft zelf iets aan te passen

### Belangrijke correctie: .cagefs/.cl.selector weer volledig gescand
- Deze twee CloudLinux-systeemmappen stonden op de standaard-uitsluitlijst van het extra scanpad, in de veronderstelling dat dit altijd onschuldige systeemmappen zijn. Na een concreet aangetroffen backdoor in `.cl.selector/filefuns.php` bij een klant is dat teruggedraaid: beide mappen worden weer volledig op inhoud doorzocht. Alleen de rechtencontrole daarbinnen wordt overgeslagen (CloudLinux beheert die rechten zelf, en zonder deze uitzondering leverde dat honderden herhaalde, betekenisloze meldingen op - één voor elke geïnstalleerde PHP-versie op de server)
- De bijbehorende "FilesMatch + deny"-backdoor-detectie herkent nu ook de oudere Apache 2.2-schrijfwijze (`Order allow,deny` + `Deny from all`), naast de al herkende nieuwere `Require all denied` - het echt aangetroffen exemplaar bleek de oudere schrijfwijze te gebruiken

### Nieuw: exploit-scanner-restanten herkennen
- Een 0-byte bestand met een SHA-1-hash-achtige naam (eventueel met een korte willekeurige toevoeging) wordt nu herkend als bekend restant van een geautomatiseerde tool die testte of een map schrijfbaar is - een lage, informatieve melding, geen directe bedreiging

### Verbeterde versievergelijking bij extensies
- Bugfix: bij extensies die uit veel losse onderdelen bestaan (bijv. VirtueMart: één package plus tientallen eigen modules/plugins), werd bij het samenvoegen tot één rij voorheen willekeurig de eerst-verwerkte "nieuwste versie" getoond, in plaats van de hoogste - waardoor soms een verouderde versie werd getoond terwijl de daadwerkelijk nieuwste versie al lang geïnstalleerd was. Dit zat op maar liefst drie plekken in de groeperingslogica, nu overal gerepareerd
- Bugfix: een overduidelijk kapotte versiestring (bijv. een vergeten build-variabele als `${PHING.VERSION}`, die sommige extensies soms per ongeluk meeleveren) telt niet langer mee bij het bepalen van de hoogste versie, en geeft nu eerlijk "onbekend" in plaats van een onbetrouwbare "niet up-to-date"-vergelijking
- Nieuw: taalbestanden krijgen een aparte grens - een update-feed die al vooruitloopt op een Joomla-kernversie die zelf nog niet is uitgebracht (bijv. een taalbestand "6.1.3.1" terwijl Joomla-kern nog op 6.1.2 staat), wordt niet als "beschikbare update" getoond. Gebruikt hiervoor de kernversie van de site zelf (die het scanscript toch al rechtstreeks uitleest), niet een apart bijgehouden lijst
- RSJoomla!-extensies (RSForm! Pro e.d.) die onderdeel zijn van een pakket, worden niet meer los op verouderd-zijn gecontroleerd - deze ontwikkelaar werkt het versienummer van losse pakketonderdelen namelijk niet bij, alleen het pakket zelf telt nu nog mee
- Bugfix: "html/html" (een bekend, legitiem Joomla-architectuurpatroon, ook gebruikt door externe frameworks zoals Extly) wordt niet meer als verdachte "verdubbelde mapnaam" aangemerkt

### Belangrijke bugfix: geneste Joomla-installaties bij het automatisch zoeken van het FTP-pad
- De "Zoek automatisch"-knop bij het FTP-scanpad stopte altijd bij de eerste `configuration.php` die hij tegenkwam. Staat een site specifiek in een submap die zelf weer binnen een andere website-root ligt (bijv. `public_html/klantnaam/` in plaats van ernaast), dan werd voorheen de verkeerde, buitenste website gevonden. De submap die bij de site hoort krijgt nu altijd voorrang, ook als de omliggende map toevallig al zijn eigen `configuration.php` heeft
- De zoekfunctie meldt nu ook expliciet wanneer een submap die bij de site hoort wél is gezien, maar niet doorzocht kon worden (bijv. een aparte toegangsbeperking) - in plaats van daar stilzwijgend overheen te gaan

### Stabiliteit: diverse tijdslimieten verruimd
- Bugfix: bij "Scan verdacht" op alle sites tegelijk kon een van de automatische vervolgstappen (Joomla-/extensieversies ophalen, extensiebestanden tussen sites vergelijken, de samenvattingsmail) een kale HTTP 500 opleveren zodra het aantal sites/bestanden de standaard tijds-/geheugenlimiet van de hostingpartij naderde. Alle betrokken stappen hebben nu een eigen, ruimere limiet, en tonen als het onverhoopt tóch misgaat de echte foutmelding in plaats van een lege 500
- Bugfix: het scanscript zelf (backdoor-scan + extensiecontrole) kon om dezelfde reden vastlopen op sites met een bijzonder omvangrijke `.cagefs`-map (nu dat weer wordt meegescand) - heeft nu eigen, op elkaar afgestemde tijdsbudgetten, met een nette, onvolledige afronding in plaats van een crash

### Overige verbeteringen
- Extra standaard-uitsluitingen voor het extra scanpad, op basis van in de praktijk aangetroffen, onschuldige bestanden/mappen: `backups`, `softaculous_backups`, `vmfiles` (VirtueMart), `.mozilla`, `bu`/`private`/`statsdata`/`temp` (bij een "/data/www/domeinnaam/"-hostingstructuur), gedateerde `blacklist.*.log`-bestanden en `zzz...-pecl.ini`-configuratiesnippets (beide op patroon herkend, niet als vaste naam)
- `.well-known` wordt nu ook bij de website zelf herkend als vertrouwde map (stond al op de uitsluitlijst van het extra scanpad, maar nog niet bij de website-root zelf)
- PWA-icoon (bij "Zet op beginscherm" op een smartphone) krijgt nu een cache-buster mee, wat op Android/Chrome de kans vergroot dat een nieuw logo wordt opgepikt. Op iOS/Safari blijft dit een platformbeperking: een eenmaal toegevoegde snelkoppeling controleert het manifest daarna nooit meer, ongeacht wat de monitor zelf doet - daar is verwijderen en opnieuw toevoegen het enige werkende middel

## 1.11 - 2026-08-05

### Extra scanpad: nu volledig automatisch (geen keuze meer nodig)
- Was eerst een handmatige keuze van 0 t/m 4 niveaus boven de website-root; is nu een simpel aan/uit-vinkje. Het scanscript bepaalt zelf, bij elke scan opnieuw, hoe ver het omhoog kijkt - op basis van **eigenaarschap**: zolang een map nog dezelfde eigenaar heeft als de website zelf, hoort die bij hetzelfde hostingaccount en wordt er nog een niveau hoger gekeken; zodra de eigenaar verandert (bijv. de gedeelde hoofdmap van de hele server), stopt het daar vanzelf. Werkt zo bij elke hostingpartij, zonder mapnamen te hoeven raden
- Bij Site-instellingen verschijnt na elke scan automatisch wat er gedetecteerd is (bijv. "3 niveau(s) boven de website-root: /home/gebruikersnaam"), puur ter info
- Bugfix, tijdens het bouwen ontdekt: `$startMap` werd nergens door `realpath()` gehaald, waardoor een symlink ergens in het pad (bijv. de accountroot zelf) kon zorgen dat de website-eigen map zichzelf niet herkende en zichzelf per ongeluk als "onbekend" meldde

### Extra scanpad: standaard-uitsluitlijst flink uitgebreid
- Herkenbare hostingpartij-systeemmappen/-bestanden worden nu automatisch overgeslagen, zonder dat je daar zelf iets voor hoeft in te stellen: `.cagefs`, `.cl.selector`, `.php`, `.pki`, `.ssh`, `.cpanel`, `.imunify_patch_id`, `.myimunify_id`, `.shadow` (CloudLinux/CageFS); `Maildir`, `mdbox`, `.pyzor`, `imap` (e-mailinfrastructuur); `.softaculous`, `.spamassassin`, `.clwpos` (cPanel-hostingtools); `.appdata`, `application_backups`, `akeeba-backup` (back-uptools); `.trash`, `.well-known`; standaard Linux-accountbestanden (`.bash_logout`, `.bash_profile`, `.bashrc`, `.bash_history`, `.viminfo`, `.lesshst`); en `domains`/`public_html` (bij accounts met meerdere sites, resp. Vimexx-achtige structuren)
- Het losse invoerveld "Nog extra (sub)mapnamen overslaan" is nu nadrukkelijk alleen nog voor site-specifieke uitzonderingen, niet meer de plek waar dit soort standaardgevallen zelf ingevuld moesten worden

### Nieuw: herkenning van andere, complete Joomla-installaties
- Een map die zowel een eigen `configuration.php` als een eigen `administrator`-map bevat, wordt nu herkend als een volledig eigen, losstaande Joomla-installatie (bijv. een oude staging-kopie in een submap, of - bij Strato en vergelijkbare hostingpartijen - een andere site die los naast de huidige in dezelfde accountroot staat). In plaats van de hele boel in bulk als "onbekend" te melden, wordt de vertrouwde-Joomla-mappenlijst er ook op toegepast, met één duidelijke, informatieve melding in plaats van tientallen losse
- Dit werkt zowel voor de website zelf (een geneste installatie in een submap) als voor het extra scanpad (een andere, losstaande installatie in de accountroot)

### Nieuw: vier beveiligingsdetecties overgenomen na analyse van vergelijkbare software
- **Super Users-overzicht**: compleet overzicht van alle beheerdersaccounts (naam, gebruikersnaam, e-mail, aangemaakt, laatst ingelogd, geblokkeerd), rechtstreeks uit de database van de site zelf - los van de al bestaande automatische herkenning van bekende aanvallerspatronen, zodat ook een nieuw account dat nog geen bekend patroon gebruikt meteen opvalt bij het doorlopen ervan. Verschijnt als eigen blok op het beveiligingsrapport
- **Cloaking-detectie**: `index.php` en `administrator/index.php` worden gecontroleerd op de combinatie van bot-detectiepatronen (Googlebot/Bingbot in de user-agent) én code die externe inhoud ophaalt - los van elkaar soms onschuldig, samen in een kernbestand een sterk signaal voor een aanval die andere inhoud toont aan zoekmachines dan aan bezoekers
- **Massaal-hernoemen-detectie**: signaleert wanneer vijf of meer bestanden/mappen in de webroot hetzelfde ongebruikelijke achtervoegsel delen (bijv. `bestand.php__113576e`) - kenmerkend voor een aanvalstype dat de hele website in één klap onbereikbaar maakt
- **Onzichtbare Unicode-tekens**: bestandsnamen met verborgen zero-width-tekens of een RTL-omkeringsteken (een bekende truc om een kwaadaardig bestand te laten lijken op iets onschuldigs) worden nu apart en met hoog risico gemeld, zowel bij de website zelf als in het extra scanpad
- Daarnaast: automatische herkenning van Google Search Console-verificatiebestanden en mySites.guru-checksumbestanden, om onnodige meldingen te voorkomen

### Nieuw: klantrapport (PDF)
- Nieuwe knop **"PDF"** in de actiekolom en op het beveiligingsrapport: genereert een overzichtelijke, niet-technische pagina met alles wat een site-eigenaar zelf moet weten (bedreigingen, bestands-/maprechten, onbekende items, verouderde extensies, Joomla-versie) - geschikt om door te sturen naar een klant zonder toegang tot deze monitor
- Via de eigen "Opslaan als PDF"-knop van de browser (geen aparte, kwetsbare PDF-bibliotheek nodig) - blijft bij het afdrukken/opslaan altijd op een licht, "papieren" kleurenschema, ook als het scherm zelf op donker staat
- Volledig onderdeel van de monitor: opent binnen hetzelfde venster, met eigen "Terug naar monitor"-knop en licht/donker-schakelaar

### FTP-koppeling: afhandeling van onveilige gebruikersnamen verbeterd
- Bugfix: als niet alleen het wachtwoord maar ook de gebruikersnaam een teken bevat dat niet betrouwbaar in een link kan (bijv. een `@`), opende de link alsnog zonder gebruikersnaam - FileZilla probeerde dan een anonieme inlog in plaats van netjes te vragen om de ontbrekende gegevens, wat altijd faalde. Toont nu in dat geval eerst de gebruikersnaam en het wachtwoord los, kopieerbaar, zonder FileZilla nog automatisch te openen
- Het FTP-icoontje in de actiekolom is nu, net als het nieuwe PDF-icoontje, een duidelijk leesbare tekstbadge in plaats van een klein lijnicoontje

### Nieuw: automatische herhaalpoging bij "Scan verdacht", en snellere timeouts
- Slaagt het scanverzoek de eerste keer niet (bijv. door een beveiligingslaag die een onbekend verzoek eenmalig met een tussenpagina afvangt), dan wordt automatisch één keer opnieuw geprobeerd, met een korte pauze ertussen - zonder dat je dit zelf handmatig hoeft te herhalen
- Bugfix: bij "alles scannen" kon de optelsom van wachttijden (vooral in combinatie met de nieuwe herhaalpoging) de gateway-timeout van de server overschrijden, met een HTTP 504 tot gevolg. Tijdsbudgetten zijn nu strakker, en PHP's eigen uitvoeringslimiet is losgekoppeld

### Nieuw: monitornaam-wijziging en scanscript-bestandsnamen
- Duidelijker gemaakt dat de monitornaam op drie plekken gebruikt wordt: e-mailafzender, programmatitel, én als voorvoegsel in de bestandsnaam van nieuw aangemaakte scanscripts (bestaande scanscripts veranderen niet automatisch mee)
- Nieuw, optioneel: na een naamswijziging kun je met één druk op de knop alle scanscript-bestandsnamen laten bijwerken naar de nieuwe naam - inclusief het automatisch verwijderen van het oude bestand. Met een duidelijke waarschuwing: gebruik je Akeeba Admin Tools' bestandsnaam-restrictie, dan moet de nieuwe naam daar zelf nog aan toegevoegd worden

### Overige bugfixes en verbeteringen
- Bugfix: dubbele rechtencontrole op het topniveau van het extra scanpad kon hetzelfde item twee keer laten zien
- Bugfix: `.nfsXXXXXXXX`-bestanden (een standaard, onschuldig artefact van NFS-opslag bij de hostingpartij, ontstaat als een proces een bestand nog open heeft op het moment dat het wordt verwijderd) werden ten onrechte als onbekend gemeld - nu op patroon herkend
- Cosmetisch: uitlegtekst in donkere modus was met `#a3a7ad` net iets te donkergrijs op de donkere achtergrond; is lichter gemaakt (`#babec5`)
- `config.php` eindigde met een overbodige afsluitende `?>`-tag - verwijderd, de aanbevolen, veilige schrijfwijze voor pure-PHP-bestanden

## 1.10 - 2026-08-03

### Bugfix: bestand op root-niveau kon niet bekeken/verwijderd worden
- Bugfix: `veiligPad()` (gebruikt door Bekijk, Quarantaine, Blokkeer en Verwijder) concludeerde "bestaat niet meer op deze locatie" zodra een losse padcontrole (`realpath()`/`file_exists()`) op het exacte bestand faalde - ook als het bestand daadwerkelijk gewoon aanwezig was. Er is nu een terugvalcontrole die, net als de scan zelf, `scandir()` van de bovenliggende map gebruikt om het bestaan te bevestigen
- Deze terugvalcontrole matcht ook op de **getrimde** bestandsnaam - bestandsnamen met een spatie voor/achteraan (die door de platte-tekst-opslag van gevonden items altijd getrimd worden getoond) worden zo alsnog correct gevonden en zijn weer normaal te beheren

### Bugfix: taalbestand toonde ten onrechte een update naar een nieuwere Joomla-hoofdversie
- Bugfix: de per-extensie update-feed-check (voor extensies van derden, met name taalbestanden) pakte altijd de hoogste stabiele versie uit de door Joomla zelf geregistreerde update-feed, ook als die versie bij een nieuwere Joomla-hoofdversie hoorde dan wat er geïnstalleerd is. Sinds het bestaan van Joomla 6 kon dit op een Joomla 5-site ten onrechte een update naar bijv. "6.1.2.3" tonen
- Is de huidige geïnstalleerde versie bekend, dan krijgt de hoogste versie **binnen diezelfde hoofdversie** nu voorrang - dezelfde redenering die al voor de Joomla-kern zelf gold (zie 1.9 en eerder), nu ook toegepast op losse extensie-updates. Valt er niets binnen die hoofdversie te vinden, dan blijft de hoogste versie totaal de terugval, zodat extensies met een eigen, ongerelateerde versienummering gewoon hun update blijven tonen

### Cosmetisch: donkere modus en helppagina
- De zwevende "terug naar boven"-knop was in donkere modus een nauwelijks zichtbare zwarte cirkel met witte pijl - wordt in donkere modus nu een lichtgrijze cirkel met zwarte pijl
- Bugfix: op de helppagina stond de knop "Terug naar monitor" rechts uitgelijnd tegen de (voor de leesbaarheid smal gehouden) tekstkolom, in plaats van tegen de rand van de pagina zoals op elke andere pagina - kwam doordat de leesbreedte-beperking op `<body>` zelf stond in plaats van op een aparte inhoud-wrapper

### Bugfix: scanscript herkende zichzelf (of een zusje op een andere monitor) ten onrechte als backdoor
- Bugfix: bij meerdere monitor-installaties die dezelfde site beheren (elk met een eigen, willekeurig gegenereerde scanscript-bestandsnaam) - of gewoon na een handmatige FTP-herupload met een nieuw volgnummer - werd het eigen scanscript soms door een ANDERE draaiende instantie als "onbekend root-level item" én als "ZEKER BACKDOOR" gerapporteerd. Dat laatste kwam doordat de tekst van de eigen backdoor-detectiepatronen (bijv. de regex-string voor "eval(eval(base64_decode") toevallig letterlijk in de eigen broncode voorkomt, en dus zichzelf matchte
- Elk scanscript uit dit sjabloon bevat nu een vaste, unieke herkenningsregel. Zowel de root-level-check als de backdoor-scan slaan een `.php`-bestand voortaan over zodra die inhoud wordt herkend - ongeacht bestandsnaam, monitor-installatie of geheime code. Echte backdoors blijven gewoon gedetecteerd, want die bevatten nooit toevallig exact deze herkenningsregel

### Verbetering: zelf-bijwerken werkt nu vóór de scan, niet meer erna
- Het scanscript controleerde altijd pas ná het melden van de scanresultaten of er een nieuwere versie beschikbaar was - een scan die zo'n update tegenkwam, gebruikte dus zelf nog de oude code, en pas de eerstvolgende scan de nieuwe. Bij een verse bugfix kostte dat dus altijd een "wasted" scanronde voordat de fix zichtbaar werd
- Wordt nu vóór de scan gecontroleerd. Is er een update, dan wordt die eerst weggeschreven en roept het scanscript zichzelf daarna één keer opnieuw aan (als aparte, verse aanvraag - dat is nodig omdat PHP zijn eigen, al ingelezen functies niet kan "heropladen"), zodat de resultaten die je te zien krijgt altijd al met de nieuwste code zijn gegenereerd

### Nieuw: inline hulp-icoontjes ("?")
- Klein "?"-icoontje bij diverse kopjes/kolomtitels: een klik toont een korte, feitelijke samenvatting in een pop-up, met een link naar de volledige uitleg op de betreffende plek in de handleiding - zonder dat je de pagina hoeft te verlaten om iets op te zoeken
- Nu aanwezig op de overzichtspagina (kolommen Domein, Website, Joomla, SSL status, Extensies, Beveiliging, Actie), de configuratiepagina (E-mailinstellingen, Algemene instellingen, Logo, Database-gegevens, Site-scanscript, Admin Tools-informatie, Back-up, Installatie-/updatepakket), Site-instellingen (FTP-gegevens, Extra scanpad) en - nieuw in deze versie - Site toevoegen (bij het veld "Domein", met de tip over meerdere Joomla-installaties in submappen)

## 1.9 - 2026-08-02

### Nieuw: laatste versie-onderdeel kunnen negeren per extensie
- Nieuwe, herbruikbare instelling in "Extensietabel beheren": knop **"Alleen x.xx.y negeren"** naast de bestaande "Negeren"-knop, bij alle drie de tabellen. Bedoeld voor extensies (met name taalbestanden) die een eigen, veelvuldig bijgewerkt build-nummer achter de eigenlijke versie plakken (bijv. Joomla-taalbestanden: "6.1.2.1") - zonder deze optie toonde de monitor bij elke kleine correctie "niet up-to-date", ook al bood Joomla zelf zo'n update nog helemaal niet aan
- In tegenstelling tot volledig "Negeren" blijft de extensie hierbij gewoon zichtbaar en up-to-date-status bijgehouden - alleen het laatste, door een punt gescheiden versie-onderdeel telt niet meer mee bij de vergelijking

### Nieuw: rechtstreeks negeren vanaf het extensieoverzicht
- Nieuwe "Negeren"-knop per rij op de extensiepagina van een site zelf - voorheen moest je hiervoor altijd eerst naar "Extensietabel beheren". Handig bij bijv. eigengemaakte modules die je meteen als "geen extensie van derden om te volgen" wil markeren

### Nieuw: scanscript-bestandsnaam nu gebaseerd op de monitor, niet de site
- De automatisch gegenereerde scanscript-bestandsnaam (bijv. `scan-door-compactwebmonitor-a3f9c2.php`) is voortaan gebaseerd op de naam van de monitor zelf, in plaats van op de domeinnaam van de site waar het bestand op komt te staan - dat laatste is namelijk altijd al overduidelijk uit de context, terwijl "welke monitor heeft dit hier neergezet" juist wél nuttige informatie is, bijvoorbeeld als een site door meerdere, losse monitor-installaties wordt gevolgd
- De migratieknop op de configuratiepagina herkent nu ook sites die weliswaar al een unieke naam hadden, maar nog volgens het oude naamgevingspatroon - anders zou de knop na deze wijziging ten onrechte "niets te migreren" blijven melden

### Nieuw: automatisch herkennen van bekende, legitieme rootmappen
- Symlink-mappen die bij sommige hostingpartijen (bijv. Vimexx) naast `public_html` staan (zoals `private_html`) en naar exact dezelfde bestanden verwijzen, worden nu herkend via het daadwerkelijke, fysieke pad - voorkomt dubbele "verdacht"-meldingen voor precies dezelfde bestanden
- De standaard uploadmappen van de extensies **Phoca Download** (`phocadownload`, `phocadownloadpap`) en **Phoca Cart** (`phocacartattachment`, `phocacartdownload`, `phocacartdownloadpublic`) staan nu op de vaste lijst met vertrouwde rootmappen - geen handmatig vertrouwen per site meer nodig
- **phpass** (de wachtwoord-hashing-bibliotheek die standaard met Joomla wordt meegeleverd, terug te vinden als `lib_phpash`/`phpass` met auteur "Solar Designer") wordt nu herkend als Joomla-kernonderdeel, in plaats van als onbekende extensie van derden

### Bugfixes
- Bugfix: de downloadknop bij Site-instellingen verdween volledig zodra er FTP-gegevens waren ingevuld, waardoor er geen manier meer was om het scanscript handmatig te downloaden als automatisch versturen om serverredenen niet lukte. Beide knoppen staan nu altijd samen: downloaden (als betrouwbare terugval) én automatisch versturen (als dat beschikbaar is)
- Bugfix: een map die eerder als "vertrouwd" was gemarkeerd bij een root-level-vondst, kwam telkens weer terug als "nieuw" zodra er simpelweg een bestand aan werd toegevoegd of uit verwijderd (bijv. bij een eigen downloadmap) - de wijzigingsdatum van de map zelf telde per ongeluk mee in de vertrouwen-herkenning. Bij mappen telt deze datum niet meer mee (bij bestanden blijft dit, terecht, wel het geval)

### Documentatie: FileZilla als standaard FTP-programma op Windows
- Kant-en-klaar downloadbaar registerbestand (`filezilla-als-standaard.reg`) op de helppagina, dat de gratis FileZilla Client in één keer als standaardprogramma voor `ftp://`/`sftp://`-links instelt - geen handmatige registeraanpassing meer nodig
- Nieuwe tip over een veelvoorkomende, onverwachte bijkomstigheid: sommige browsers (met name Firefox) houden hiervoor een eigen, losse voorkeur bij, los van wat er in Windows zelf is ingesteld - inclusief uitleg waar en hoe je dat in Firefox controleert en wijzigt

## 1.8 - 2026-08-01

### Nieuw: permanent unieke scanscript-namen, ook voor bestaande sites
- Elke nieuwe site krijgt voortaan een automatisch gegenereerde, unieke scanscript-bestandsnaam (bijv. `scan-voorbeeldnl-a3f9c2.php`) - er is geen invulveld meer om zelf een naam te kiezen, ook niet bij het toevoegen van een site
- Deze naam staat vast en is niet meer los te bewerken bij Site-instellingen; wijzigen kan alleen nog via de nieuwe knop "🔄 Vervang door nieuwe, unieke naam", die automatisch een nieuw bestand plaatst én het oude opruimt
- Nieuwe, eenmalige migratieknop op de configuratiepagina ("🔐 Migreer alle sites naar unieke scanscript-namen") voor sites die vóór deze functie zijn toegevoegd - toont het exacte aantal nog te migreren sites, en verdwijnt vanzelf (met een bevestiging) zodra er niets meer te doen is
- De downloadknop bij Site-instellingen geeft het bestand nu de correcte, bij de site horende bestandsnaam mee - eerder kreeg elke download altijd de oude standaardnaam, wat bij een handmatige FTP-plaatsing tot een naamsmismatch met de database zou hebben geleid
- De generieke, naamloze downloadknop op de configuratiepagina is verwijderd (leverde toch nooit een bruikbaar, bij een site passend bestand op) - een kant-en-klare download vind je voortaan altijd per site bij Site-instellingen

### Nieuw: automatische terugval bij een verkeerd IP-adres tijdens FTP-uploads
- Sommige hostingpartijen (met name bepaalde Plesk-hosts) geven bij een FTP-verbinding een verkeerd/onbereikbaar IP-adres terug voor de bestandsoverdracht zelf (een "PASV masquerade"-probleem) - FileZilla corrigeert dit altijd al automatisch; de monitor doet dit nu ook, via een automatische curl-gebaseerde tweede poging zodra de gewone upload mislukt

### Nieuw: Admin Tools-ondersteuning bij unieke scanscript-namen
- Elke site heeft sinds kort een eigen, uniek gegenereerde scanscript-naam - gebruikt een site Akeeba Admin Tools, dan moet de "Allow direct access to these files"-uitzondering in de .htaccess-maker daarom bij élke naamswijziging opnieuw worden ingesteld. Dit stond nog onvoldoende vermeld en is nu op meerdere plekken verduidelijkt: bij het toevoegen van een nieuwe site, bij de "Vervang door nieuwe naam"-knop (inclusief de bevestigingsvraag zelf), bij de bulkmigratieknop, en op de helppagina
- Nieuw blok "🛡️ Admin Tools: informatie voor .htaccess-maker" op de configuratiepagina: herkent automatisch (op basis van de laatst gescande extensielijst, dus zonder dat je iets hoeft aan te vinken) welke sites Admin Tools gebruiken, en toont per site het favicon, de aanklikbare domeinnaam (naar de admin-backend) en de exacte scanscript-bestandsnaam die in de .htaccess-maker moet worden ingevuld

### Nieuw: extra weerbaarheid bij FTP-verbindingsproblemen
- De curl-terugval bij een verkeerd IP-adres (PASV-probleem) werkt nu ook bij het automatisch zoeken van het FTP-pad, niet alleen bij het versturen van het scanscript - inclusief een verder verruimde zoekdiepte (van 4 naar 7 mappen) voor hostingpartijen die de website-root dieper nesten
- Bugfix: de curl-terugval gebruikte per ongeluk het `ftps://`-schema, wat curl een *impliciete* TLS-verbinding laat verwachten (zoals bij poort 990) - terwijl gangbare FTPS op poort 21 juist *expliciete* TLS gebruikt (eerst een onversleuteld welkomstbericht, dan een "AUTH TLS"-upgrade). Dit veroorzaakte een cryptische "wrong version number"-foutmelding; nu wordt altijd het juiste `ftp://`-schema gebruikt, met de TLS-upgrade apart correct ingesteld
- Als allerlaatste redmiddel wordt nu ook **actieve FTP-modus** geprobeerd (de server verbindt terug naar de monitor, in plaats van andersom) - relevant voor hostingpartijen die uitgaand verkeer naar willekeurige, hoge poorten blokkeren, waardoor zowel de gewone als de curl-gebaseerde passieve methode altijd zouden falen

### Bugfixes: extensiestatus op de indexpagina
- Bugfix: de FOF-bibliotheek werd bij de schrijfwijze `lib_fof` (met de letter O) niet herkend als uit te sluiten kernbibliotheek - alleen `fof`, `f0f`, `fof30` en `lib_f0f` (met een nul) stonden op de lijst. Dit kon een site onterecht "Deels onbekend" laten tonen door precies dit ene, niet-uitgesloten onderdeel
- Bugfix: de databasequery die de extensiestatus voor de indexpagina samenvat, haalde niet dezelfde kolommen op als de query achter de extensiepagina zelf (`client`, `enabled` en `update_feed_url` ontbraken) - nu volledig gelijkgetrokken
- Bugfix (belangrijkste oorzaak): de indexpagina keek voor de extensiestatus naar twee losse databronnen tegelijk - de volledige scan (betrouwbaar en compleet) én een ouder, apart mechanisme (gevoed door het reguliere "Scan en check sites", los van de volledige scan) dat de extensiepagina zelf nooit gebruikte. Dat oudere mechanisme kon een geïnstalleerde versie kennen zonder ooit een nieuwste versie te hebben kunnen achterhalen, wat tot een vals "Deels onbekend" leidde terwijl de volledige scan het antwoord al lang wist. De indexpagina vertrouwt nu nog maar op één bron, dezelfde die ook de extensiepagina gebruikt
- De "Onbekend"-status (wanneer er nog helemaal geen extensiedata bekend is) is nu, net als de andere statussen, een aanklikbare link naar de extensiepagina - voorheen was dit platte tekst

### Bugfix: databaseverbinding met de site zelf
- Bugfix: bij het rechtstreeks uitlezen van de geïnstalleerde extensies uit de database van een site (voor sites waar `configuration.php` de poort direct achter het hostadres zet, bijv. `127.0.0.1:3306`) probeerde het scanscript de volledige tekenreeks als hostnaam te herleiden via DNS, wat altijd faalde met een verwarrende "Unknown server host"-foutmelding - ook bij een op zich geldig adres als `127.0.0.1`. Host en poort worden nu, net als bij de andere databaseverbinding in het scanscript, correct van elkaar gesplitst

### Bugfixes en verbeteringen
- De geheime code en de cron-beveiligingscode vereisen nu een minimale lengte (20 resp. 12 tekens) - voorkomt dat deze ooit per ongeluk worden afgezwakt tot iets te makkelijk te raden, wat sinds het zelf-bijwerkende scanscript (zie 1.7) een iets zwaardere verantwoordelijkheid draagt
- Het inlogscherm toont nu het eigen, geüploade logo (als dat is ingesteld) in plaats van altijd het standaardlogo
- De knoppen op het inlogscherm ("Toon wachtwoord", "Inloggen") zijn nu themagebonden gestyled in plaats van de standaard, witte browserknop te tonen in donkere modus; het wachtwoordveld gebruikt nu ook het vertrouwde oogje-in-het-veld-patroon in plaats van een losse knop eronder
- Het inlogscherm is smaller/beter passend gemaakt op mobiele schermen
- Succes- en foutmeldingen (groene/rode kaders) waren in donkere modus bijna onzichtbaar geworden door een botsing met de algemene donkere-kaartkleur-regel - nu weer duidelijk groen/rood, ook in donkere modus

## 1.7 - 2026-07-30

### Nieuw: vernieuwde installatiewizard
- Bezoek je de map/het domein van de monitor vóórdat de installatie is voltooid, dan word je nu automatisch doorgestuurd naar de installatiewizard, in plaats van een kale foutmelding te zien
- De wizard vraagt eerst expliciet te bevestigen dat `LEES_DIT_EERST.txt` is gelezen én dat er al een lege database is aangemaakt - de rest van het formulier blijft (zichtbaar én functioneel) vergrendeld totdat beide vakjes zijn aangevinkt, met een duidelijke melding als je toch al in een veld probeert te klikken
- Na een geslaagde installatie kan `installeer.php` direct met één druk op de knop zichzelf van de server verwijderen, met een nette bevestigingspagina die na 5 seconden automatisch doorstuurt naar de inlogpagina
- Databasefoutmeldingen (bijv. verkeerde inloggegevens, niet-bestaande database, verkeerd serveradres) worden nu herkend en voorzien van een duidelijke, Nederlandse uitleg - de technische, Engelse foutmelding van MySQL zelf blijft er nog wel bij staan, voor het geval je daarmee om hulp moet vragen

### Nieuw: volledige opruiming van de `_scan_beheer`-map bij het verwijderen van een site
- Naast het scanscript-bestand probeert de monitor bij het verwijderen van een site nu ook de eigen `_scan_beheer`-map (met eventuele quarantaine-, geblokkeerd- en prullenbak-inhoud) via FTP/SFTP volledig op te ruimen

### Bugfixes
- De "📋 Kopieer"-knoppen (bij de update-feed-URL in Extensietabel beheren, en bij de cronjob-commando's op de helppagina) deden het niet: de gekopieerde tekst werd via `JSON.stringify()`/`json_encode()` in een `onclick`-attribuut geplakt, wat de aanhalingstekens van elkaar liet botsen en de knop-code onbruikbaar maakte. Beide knoppen gebruiken nu een veilig `data-`-attribuut, en hebben een terugvalmelding voor het geval de kopieerfunctie van de browser zelf niet beschikbaar is (bijv. bij een site die nog over gewoon HTTP i.p.v. HTTPS wordt bezocht)
- Datahygiëne: een gepersonaliseerd, verouderd scanscript-bestand (met een echt domein en een echte geheime code erin verwerkt) is uit de ontwikkelomgeving verwijderd, zodat dit nooit per ongeluk in een installatie-/updatepakket terecht had kunnen komen

### Kleine verbeteringen
- Voorbeeldteksten die eerder de specifieke mapnaam "00-beheer" toonden (bij de installatiewizard, de configuratiepagina, en de installatie-instructies), tonen nu overal de neutrale placeholder "mapnaam"

## 1.6 - 2026-07-29

### Nieuw: volledige opruiming bij het verwijderen van een site
- Bij het verwijderen van een site probeert de monitor nu ook het scanscript-bestand daadwerkelijk van de site zelf te verwijderen (via FTP/SFTP, als de gegevens bekend zijn) - inclusief eventuele eerder gebruikte, inmiddels gewijzigde bestandsnamen (nieuwe tabel `site_scanscript_geschiedenis` houdt dit bij)
- Duidelijke terugkoppeling na het verwijderen: geslaagd, gedeeltelijk mislukt, geen verbinding mogelijk, of geen FTP-gegevens bekend
- Ook de resterende, nog niet opgeruimde databasetabellen (vertrouwde items, afwijkende-bestanden-meldingen) worden nu netjes meegenomen

### Nieuw: bulkacties en filteren op het beveiligingsrapport
- Selectievakjes per vondst (plus "alles selecteren"), met een bulkactiebalk voor Vertrouwen, Bekijken, Rechten herstellen, Quarantaine, Blokkeren en Verwijderen in één keer op alle geselecteerde items
- De lijst met vondsten staat nu standaard gegroepeerd per type (backdoor, .htaccess, database, bestand, map), met een filter-keuzemenu bovenaan zodra er meerdere typen aanwezig zijn
- Selectievakjes zijn nu ook in donkere modus goed leesbaar (eigen getekende stijl met een lichte rand en een wit vinkje, in plaats van de standaard witte browserweergave)

### Nieuw: bestands- en maprechten herstellen
- Nieuwe knop "🔧 Rechten naar 644" per vondst (en in de bulkbalk) om afwijkende bestandsrechten van een los bestand te herstellen naar de gangbare, veilige waarde 644
- Mislukt het versturen van het scanscript via FTP/SFTP, dan controleert de monitor automatisch of de doelmap het uitvoer-recht voor de eigenaar mist (bijv. rechten 655 in plaats van 755) - bij een gevonden probleem verschijnt een specifieke melding én een knop om dit, op expliciet verzoek, automatisch te herstellen naar 755

### Nieuw: herkenning van een blokkerende .htaccess bij het scannen
- Blokkeert een kwaadaardig `.htaccess`-bestand in de hoofdmap van een site het scanverzoek zelf (bijv. via "deny from all", of door het verzoek stiekem door te sturen), dan herkent de monitor dit nu (HTTP 403/401, of een HTTP 200 zonder herkenbare scanuitvoer) en toont een duidelijke waarschuwing in plaats van simpelweg door te gaan naar de wachttijd en uiteindelijk een onverklaarde "Nog niet gescand" te tonen

### Kleine verbeteringen
- De update-feed-URL die uit een lokaal installatiepakket wordt gehaald (Extensietabel beheren) heeft nu een "📋 Kopieer URL"-knop, om een tikfout bij het overtypen (zoals een ontbrekende "l" in ".xml") te voorkomen
- De voorbeeld-cronjobcommando's op de helppagina tonen nu het daadwerkelijke, volledige serverpad (bepaald door de monitor zelf, in plaats van een in te vullen placeholder-gebruikersnaam), en hebben elk een eigen kopieerknop
- De geheime code en de cron-beveiligingscode accepteren voortaan alleen nog letters, cijfers, streepjes en underscores - voorkomt dat een teken als "%" de cronjob laat mislukken (in een crontab-regel heeft "%" een speciale betekenis)

## 1.5 - 2026-07-28

### Betrouwbaarheid: PHP-compatibiliteit scanscript
- Kritieke bugfix: het scanscript gebruikte een union-type retourtype (`: string|false`), een syntax die pas sinds PHP 8.0 bestaat - op een oudere PHP-versie (bijv. 7.4) leidde dit tot een kale parse-fout (HTTP 500) en dus een volledig niet-werkend scanscript op die site. Vervangen door een overal werkende PHPDoc-annotatie
- Uitgebreid gecontroleerd op vergelijkbare PHP 8-only-syntax (`match()`, `enum`, `readonly`, `?->`, benoemde argumenten, de nieuwere stringfuncties) - niets anders gevonden

### Betrouwbaarheid: extensiedetectie
- Bugfix: de "is dit een Joomla-kernonderdeel"-herkenning controleerde alleen of het woord "joomla" ergens in het auteursveld voorkwam - daardoor werden extensies van bedrijven met "Joomla" in hun eigen merknaam (bijv. RSJoomla!, JoomlaShack, Joomlashine) ten onrechte als kernonderdeel gezien, en dus stilzwijgend overgeslagen bij de update-feed-controle én uit de extensielijst gefilterd. Nu specifiek gecontroleerd op de daadwerkelijke, officiële Joomla-kernauteur ("Joomla! Project"). Gerepareerd op zowel de scan- als de monitorkant
- Bugfix: bij het controleren van Joomla's eigen geregistreerde update-locaties werd gefilterd op "ingeschakeld" - Joomla schakelt een update-locatie zelf automatisch (en onopgemerkt) uit na een eerdere, tijdelijke onbereikbaarheid. Die filter is verwijderd; een echt onbereikbare feed valt nu gewoon netjes terug op een "mislukt"-melding in plaats van stilzwijgend overgeslagen te worden
- Bugfix: bij extensies die uit meerdere losse onderdelen bestaan (bijv. verschillende plugins van hetzelfde product) kon de samenvatting op de overzichtspagina een andere status tonen dan de gedetailleerde extensiepagina van dezelfde site, doordat de twee onderliggende databasequery's een verschillende (en voor het eerst-gekozen representatieve onderdeel relevante) rijvolgorde hadden. Beide gebruiken nu dezelfde ordening
- Bugfix: de extensiekolom op de overzichtspagina kon "Niet up-to-date" of "Deels onbekend" tonen voor een site waarvan de volledige scan nog nooit is geslaagd (bijv. door een serverbeperking) - dit gebeurde doordat sommige extensiegegevens via een los, van het scanscript onafhankelijk kanaal binnenkomen. Toont nu ook hier consistent "Nog niet gescand", net als de beveiligingskolom al deed
- Bugfix: het opslaan van extensiebestand-hashes kon in zeldzame gevallen (hetzelfde bestandspad bij twee overlappende extensiegroepen) een onafgevangen databasefout geven die de rest van de scanverwerking liet crashen - omgezet naar een "invoegen-of-bijwerken", zodat een dubbel pad de scan niet meer kan laten mislukken
- Extra bekende onderdelen toegevoegd aan de uitsluitingslijst: FOF30 (oudere Akeeba-bibliotheek), Admin Tools Update Email, en de schrijfwijze `lib_f0f` van de FOF-bibliotheek

### Nieuw: ondersteuning voor meerdere installaties op één hostingaccount
- Sites die niet in de website-root staan, maar in een submap (bijv. bij meerdere, losse Joomla-installaties onder hetzelfde account), kunnen nu als domein plus submap geregistreerd worden - het scanscript herkent automatisch zijn eigen locatie en meldt zich bij de monitor met de juiste, volledige domein+submap-combinatie

### Verbeteringen aan bestandsbeheer bij vondsten
- De knop "Bekijken" bij een beveiligingsvondst werkt nu ook voor bestanden binnen het (optionele) extra scanpad, zodat de inhoud altijd in te zien is, ook buiten de strikte website-root
- "Quarantaine", "Blokkeer" en "Verwijderen" blijven daar bewust buiten bereik, maar geven nu een duidelijke melding ("gebruik daarvoor handmatig FTP") in plaats van een generieke foutmelding

### Prestaties
- Bugfix: het versturen van het scanscript via FTP naar "alle sites tegelijk" kon bij veel sites een HTTP 504 (time-out) veroorzaken, met bovendien geen zicht op welke specifieke site vastliep. Dit gebeurt nu per site apart, met live bijgewerkte voortgang per site

### Beveiliging
- Extra bescherming tegen een verouderde PHP-instelling (`mbstring.func_overload`), die op sommige (met name oudere/goedkopere) shared-hostingpakketten `substr()`/`strlen()` ongemerkt multibyte-bewust maakt - dit kon bij het ontsleutelen van opgeslagen wachtwoorden (zoals FTP-wachtwoorden) tot een corrupt resultaat leiden. Verholpen met expliciet byte-veilige stringfuncties

## 1.4 - 2026-07-26

### Nieuw: eigen scanscript-bestandsnaam per site
- Draait er op een site ook nog andere monitorsoftware (bijv. van iemand anders)? Dan kan het scanscript nu een eigen, afwijkende bestandsnaam krijgen in plaats van altijd `scan-en-check-website.php` te heten - instelbaar zowel bij het toevoegen van een nieuwe site als achteraf bij Site-instellingen
- Vergeet je de verplichte `.php`-extensie bij het invullen? Die wordt automatisch aangevuld
- Alle plekken die de site daadwerkelijk aanspreken (FTP/SFTP-upload, scan starten, beheeracties zoals quarantaine/verwijderen, de "scanscript openen"-knop) gebruiken nu overal de juiste, eigen bestandsnaam
- Het scanscript herkent zichzelf voortaan altijd correct in zijn eigen bestandenlijst-uitsluiting (via `basename(__FILE__)`), ongeacht de gekozen naam, en meldt zichzelf dus nooit per ongeluk als verdacht
- Nieuwe controletool bij Site-instellingen: "Controleer of het oude bestand nog bestaat" - toont (puur informatief, zonder zelf iets te verwijderen) of een eerder gebruikte standaardnaam nog ergens op de site staat, voor als je overstapt naar een eigen naam

### Nieuw: eigen logo
- Via Configuratie kan nu een eigen logo geüpload worden ter vervanging van het standaardlogo, met validatie op bestandstype (.png/.jpg/.webp), afmeting (128-1024 pixels, bij voorkeur vierkant) en bestandsgrootte
- Bij het uploaden worden automatisch ook alle favicon-varianten gegenereerd op basis van hetzelfde logo (browsertabblad-icoon, "installeren op beginscherm"-icoon voor iOS/Android, en de grotere PWA-installatie-iconen) - alles blijft dus visueel consistent, zonder aparte handmatige stappen. Vereist de GD-afbeeldingsbibliotheek in PHP (vrijwel altijd standaard aanwezig); is die uitzonderlijk niet beschikbaar, dan blijft het logo gewoon werken en volgt een duidelijke melding dat alleen het favicon niet is bijgewerkt
- Een knop om zowel het logo als alle favicon-varianten in één keer weer terug te zetten naar het standaardlogo

### Betrouwbaarheid en prestaties
- Bugfix: bij sites met veel geconfigureerde update-feeds en/of veel sites tegelijk kon "Joomla- en extensieversies ophalen" een HTTP 504 (time-out) veroorzaken, doordat alle feeds en per-site controles na elkaar werden opgehaald. Dit gebeurt nu parallel (net als bij de website-/SSL-status-controle), waardoor de totale wachttijd nog maar zo lang duurt als de traagste ene aanvraag
- Het scanscript probeert zelf de geheugen- en tijdslimiet te verruimen (naar 256M / 120s) bij de start van elke scan - voorkomt een kale HTTP 500 bij sites met een krappe standaardlimiet van de hostingpartij, zonder gevolgen als een hostingpartij dit bewust blokkeert
- De tool voor het uitlezen van een update-feed-URL uit een lokaal installatiepakket doorzoekt nu ook niet-standaard pakketstructuren (zoals het "CB Package Installer"-systeem van Community Builder) door élke geneste ZIP te doorzoeken, niet alleen Joomla's eigen "packages/"-conventie

### Extensieoverzicht
- Extra bekende Joomla-kernonderdelen en pakket-onderdelen toegevoegd aan de uitsluitingslijst: "System - One Click Action", tagcontenttags (onderdeel van AcyMailing), de AcyMailing-module zelf, en de FOF-bibliotheek van Akeeba ("F0F (NEW) DO NOT REMOVE")

### Donkere modus
- De domeinnaam-titels op de overzichtspagina nogmaals lichter gemaakt voor beter contrast
- Tabelkoppen op de helppagina volgen nu ook het thema, in plaats van een vaste witte achtergrond te tonen

## 1.3 - 2026-07-22

### Beveiligingsscans - nieuwe controles
- Kernbestand-integriteitscontrole: index.php, administrator/index.php, api/index.php en includes/app.php worden gecontroleerd op code die wordt uitgevoerd vóór Joomla's _JEXEC-bootstrap - een vrijwel valse-positief-vrij signaal dat een site *op dit moment* actief besmet is
- Verdachte Super Users: het scanscript zet zelf een alleen-lezen databaseverbinding op (via configuration.php, net als Joomla zelf) en herkent Super User-accounts met bekende aanvallerspatronen in gebruikersnaam of e-maildomein
- Defacement-detectie: ontmaskeringsteksten ("Hacked by", "Owned by", enz.) in templatestijl-parameters
- Backup-/duplicaatconfiguratiebestanden (configuration.bak.php en varianten) worden apart en met hoog risico gemeld - deze lekken dezelfde databasewachtwoorden als het echte configuration.php
- Twee nieuwe backdoor-patronen: payload geladen via een stream-wrapper-truc (zip://, phar://, enz.) en numerieke byte-array-decodering via chr() - beide bekende ontwijkingstechnieken
- Bugfix: het stream-wrapper-patroon was aanvankelijk te los geformuleerd en gaf een valse-positief bij bepaalde bibliotheekbestanden (bijv. dompdf) - nu vereist het een daadwerkelijke require/include-aanroep met de stream-wrapper-tekst als direct argument
- Kruislingse bestandsvergelijking tussen sites uitgebreid naar Joomla's eigen kernbestanden (niet alleen extensies van derden meer) - gegroepeerd op exacte Joomla-kernversie

### Nieuwe, structurele valse-positief-uitsluitingen
- Vertrouwde rootmappen uitgebreid met `private_html` (standaard bij sommige hostingpartijen) en `log` (bevat o.a. Joomla's ingebouwde takenplanner)
- Bekende, onschuldige auto-gegenereerde .htaccess-bestanden (iCagenda, Admin Tools) worden herkend op hun vaste tekstsignatuur en volledig overgeslagen
- Alles binnen een map genaamd `awstats` wordt overgeslagen (losse tool van de hostingpartij, geen Joomla-onderdeel)
- Specifiek pad `com_rsseo/helpers/phpQuery.php` uitgesloten (bekende valse-positief in een meegeleverde bibliotheek)

### Extensieoverzicht
- Uitgebreide lijst met bekende Joomla-kernonderdelen en pakket-onderdelen die niet apart als "te controleren" extensie getoond worden: mod_online, mod_custom, mod_newsflash, PHPMailer, com_redirect + de bijbehorende systeemplugin, Language Translation Override, Mootools Upgrade, System Restore Points, System - One Click Action, en diverse vaste onderdelen van Akeeba Backup en AcyMailing
- Nieuw: ontwikkelaars die hun auteursveld per extensie inconsistent schrijven (bijv. woorden in wisselende volgorde) kunnen nu via een vast, herkenbaar trefwoord alsnog correct aan hetzelfde product gekoppeld worden, met de status van het hoofdonderdeel (component/pakket) leidend in plaats van het slechtst-scorende losse onderdeel
- Fallback voor het bepalen van de geïnstalleerde versie: als Joomla's eigen manifest_cache geen bruikbaar versienummer bevat, wordt nu automatisch het eigen XML-manifestbestand van de extensie op de site zelf geraadpleegd
- Bugfix: de "aantal onbekend/verouderd"-telling op de overzichtspagina kon afwijken van het detailoverzicht per site, door een ontbrekend databaseveld in de samenvattingsquery

### Overzichtspagina en algemene weergave
- Kolomvolgorde aangepast: "SSL status" staat nu vóór "Extensies"
- Kolom "Domein" en de statuskleur "groen" zijn in donkere modus lichter gemaakt voor beter contrast, zonder de algemene linkkleur elders op de pagina te raken
- Bugfix: de melding na het toevoegen van een nieuwe site kon na een schermverversing blijven hangen; expliciete cache-headers voorkomen dat de browser een verouderde versie van de pagina toont
- Nieuwe, kleine "◄ één stap terug"-knop naast "Terug naar monitor" op alle subpagina's

### Beheeracties (quarantaine/blokkeer/verwijder)
- Uitgebreide foutdiagnose: bij een onverwacht antwoord van de site wordt nu ook een fragment van de daadwerkelijk ontvangen inhoud getoond
- Specifieke, begrijpelijke melding wanneer een actie wordt geblokkeerd door mod_security op de server van de hostingpartij, met concreet vervolgadvies

### Diverse donkere-modus correcties
Op meerdere pagina's (Beveiligingsrapport, Extensieoverzicht, Extensietabel beheren, Configuratie, Site toevoegen) zijn hardgecodeerde lichte achtergrond- en tekstkleuren vervangen door themagebonden variabelen: de domeinnaam-titel, "vertrouwd"- en "genegeerd"-rijen, statusmeldingen, waarschuwings-/adviesvakken, formuliervakken, en de live FTP-padzoekresultaten.

## 1.1 - 2026-07-13

- Beveiligingsrapport: bekijk, zet in quarantaine, blokkeer, verwijder en herstel verdachte bestanden direct vanaf de monitor, zonder FTP - met een risicoscore per vondst
- Bugfix: verdachte .htaccess-bestanden werden ten onrechte niet opgeslagen in het beveiligingsrapport
- Update-feed-URL automatisch uit een lokaal gedownload Joomla-installatiepakket halen
- Overzichtspagina: sorteren op "meeste aandacht nodig" bij de kolommen Extensies en Beveiliging
- FTP-/SFTP-gegevens direct openen in een lokale FTP-client (FileZilla/WinSCP/Cyberduck)
- Slimmere automatische FTP-paddetectie bij meerdere domeinen op hetzelfde account
- Licht/donker thema: volgt automatisch je systeeminstelling, met een handmatige schakelaar
- Zwart-witte "retina"-icoontjes voor alle knoppen, met behoud van kleur waar functioneel
- Diverse leesbaarheids- en weergaveverbeteringen (mobiele kaartjesweergave, tabelindeling, lettergrootte)

## 1.0 - Eerste basisversie

Eerste volledige, testbare versie. Hieronder een overzicht van alle functionaliteit die
in deze versie zit.

### Overzichtspagina
- Alle gemonitorde sites in één tabel: domein (met favicon, valt terug op het Joomla-icoon
  als er geen eigen favicon gevonden kan worden), website-status, Joomla-versie,
  extensiestatus, SSL-verloopdatum en beveiligingsstatus.
- Domeinnaam linkt naar het beheergedeelte van de site (met een eventueel ingesteld geheim
  woord); het favicon linkt naar de website zelf.
- Per site: een site opnieuw laten scannen (↻), naar site-instellingen (⚙️), het scanscript
  van die site rechtstreeks openen (📋), en - als er FTP-/SFTP-gegevens zijn ingevuld - een
  knop om de gegevens direct in een lokale FTP-client (bijv. FileZilla) te openen.
- Een zwevend klokje per site tijdens het scannen, zodat je bij veel sites tegelijk per
  site kan zien of die nog bezig is - ook als de bovenste voortgangsbalk niet meer in beeld is.
- Eén druk op de knop "Scan en check sites" doorloopt de volledige cyclus (scannen op alle
  sites tegelijk, wachten, status controleren, versies ophalen, extensiebestanden tussen
  sites vergelijken, notificatiemail versturen).

### Scannen en beveiliging
- Automatische scan per site: backdoor-detectie (patroonherkenning), verdachte
  .htaccess-bestanden, onbekende root-bestanden/mappen, en herkenning van waarschijnlijk
  legitieme verdubbelde mapnamen.
- "Vertrouwd"-markering per gevonden item, met uitleg en de mogelijkheid ze weer te tonen.
- Vergelijking van extensiebestanden tussen alle gemonitorde sites onderling: bestanden die
  bij dezelfde extensie + versie afwijken van de meerderheid van de andere sites worden
  gemarkeerd als mogelijk gemanipuleerd - zonder dat daar externe downloads voor nodig zijn.
- Extra scanpad per site instelbaar, voor hostingpartijen die een losse map naast de
  website-root gebruiken.

### Extensies
- Volledige, automatische extensie-inventarisatie rechtstreeks uit de Joomla-database van
  elke site (dus niet afhankelijk van wat een extensie zelf claimt).
- Automatische up-to-date-controle via Joomla's eigen geregistreerde update-locaties, met een
  handmatig uit te breiden extensiecatalogus voor extensies zonder eigen update-feed.
- Losse plugins/modules van hetzelfde product automatisch samengevoegd tot één rij
  (pakket-koppeling + herkenning van gedeelde herkomst, ongevoelig voor accentverschillen).
- Drie overzichten bij "Extensietabel beheren": gedeelde extensies met feed (geldt voor alle
  sites tegelijk), extensies zonder feed (per site te filteren), en extensies die al
  automatisch werken (optioneel te negeren).
- Genuanceerde statussen op de overzichtspagina: Up-to-date / Deels onbekend / Niet
  up-to-date / Onbekend, met een korte uitsplitsing ("2 verouderd, 3 onbekend").

### FTP en SFTP
- Automatisch scanscript versturen via FTP, FTPS of SFTP (SFTP via de meegeleverde
  phpseclib-library, werkt op vrijwel elke hostingpartij zonder speciale serverextensies).
- Automatische paddetectie: zoekt zelf naar de map met `configuration.php`, ook bij
  hostingpartijen die 2-3 mappen boven de website-root beginnen, en ook bij accounts met
  meerdere domeinen op hetzelfde FTP-account (domeinbewust zoeken, met een losse/fuzzy
  zoekstap voor addon-domeinen in een submap met een kortere eigen naam).
- Slimme opslaanknop bij "Site toevoegen": zijn de FTP-gegevens compleet ingevuld, dan
  verstuurt "Opslaan" het scanscript meteen automatisch mee.
- Eén druk op de knop om het scanscript naar alle sites met FTP-gegevens tegelijk te sturen.

### E-mailnotificaties
- Vijf instelbare categorieën (website, Joomla-versie, extensies, SSL, beveiliging).
- HTML-mail met per site het favicon (linkt naar de website) en de domeinnaam (linkt naar
  het beheergedeelte).
- Optioneel: alleen mailen bij een cronjob, niet bij een handmatige druk op de knop.
- Cronjob-ondersteuning voor de volledige scancyclus, met een aparte beveiligingscode
  (los van de sessie-login, want een cronjob heeft geen browsersessie).

### Installatie, updates en versiebeheer
- Eigen versienummer, zichtbaar op de monitorpagina en de configuratiepagina.
- Wijzigingslogboek (dit bestand), met een knop op de configuratiepagina om het te bekijken.
- Automatisch database-migratiesysteem: bij een update hoeft er nooit meer handmatig SQL
  geïmporteerd te worden - de software controleert en werkt het schema zelf bij.
- Installatiepakket samenstellen (alleen zichtbaar/bruikbaar voor de ontwikkelaar zelf, en
  dat blijft ook zo bij de ontvanger - de mogelijkheid sluit zichzelf uit van elk pakket):
  bevat een installatiewizard die database, inloggegevens en geheime sleutels zelf aanmaakt/
  genereert, plus de al bekende extensies-met-feed, zonder dat de ontvanger zelf SQL hoeft
  te importeren.
- Updatepakket samenstellen: bevat alleen de broncode (nooit de eigen `config.php`/
  `geheime_sleutel.php` van de ontvanger), met automatische database-bijwerking na uploaden.

### Back-ups
- Volledige broncode (incl. afbeeldingen) en/of database met één druk op de knop
  downloaden vanaf de configuratiepagina.

### Weergave en toegankelijkheid
- Volledig responsive: tabellen worden op een telefoon/tablet automatisch kaartjes in plaats
  van kolommen die zijwaarts wegvallen.
- Vaste, op inhoud afgestemde kolombreedtes, zodat ook op een breed scherm geen kolom
  onnodig veel ruimte inneemt.
- Consistente, hoog-contrast zwart-witte icoontjes ("retina"-stijl) voor alle knoppen, met
  behoud van kleur waar die functioneel is (status-indicatoren zoals groen/rood/oranje).
- Uniforme lettergrootte (14px) voor alle tekstinhoud.
- Eigen logo, gebruikt als favicon, als "toevoegen aan beginscherm"-icoon op telefoon/tablet
  (via een PWA-manifest), op de inlogpagina en linksboven op de monitorpagina.
- Zwevende "terug naar boven"-knop op elke pagina.
- Programmanaam (gebruikt in de titel, e-mailafzender en het PWA-installatie-icoon) is één
  centrale instelling.

### Overig
- Uitgebreide handleiding (deze `help.php`-pagina), met dertien hoofdstukken.
- CSRF-bescherming, sessiebeveiliging en versleutelde opslag van wachtwoorden/geheime codes.

---

*(Nieuwe versies worden hierboven toegevoegd, met de datum en een korte lijst van wat er
gewijzigd is.)*
