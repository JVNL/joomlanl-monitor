# Wijzigingslogboek - Mijn Websites Monitor

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

## 1.18 - 2026-08-29

### Nieuw: kernbestand-integriteitscontrole tegen het officiële Joomla-pakket
- Naast de al bestaande meerderheidsvergelijking tussen eigen sites (die vergelijkt wat de MEESTE gemonitorde sites hebben) is er nu een vergelijking die rechtstreeks naast het officiële, ongewijzigde Joomla-pakket van downloads.joomla.org legt. Dit dekt twee situaties die de meerderheidsvergelijking niet kan zien: een Joomla-kernversie die maar op één site voorkomt (geen andere site om tegen te vergelijken), en een kernbestand dat toevallig op alle gemonitorde sites identiek is aangepast (dan "wint" die afwijkende versie gewoon de meerderheid)
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
- Voor een site in een submap (bijv. `https://domein.nl/clabbers/`) genereert Joomla zelf al een root-relatief favicon-pad dat de submap correct bevat (bijv. `/clabbers/templates/.../favicon.ico`). De monitor plakte de URL-submap daar tot nu toe altijd nog eens los voor, waardoor die er dubbel in kwam te staan (`.../clabbers/clabbers/...`) - een niet-bestaande URL, dus viel de favicon-detectie stil terug op het standaard Joomla-icoontje. Een pad dat met een enkele "/" begint wordt nu, zoals in elke browser, als root-relatief ten opzichte van het kale domein behandeld; alleen een pad zónder leidende "/" krijgt de URL-submap er nog terecht voor geplakt

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
- Sommige sites staan niet in de webroot zelf, maar in een submap die WEL rechtstreeks via de domeinnaam bereikbaar is (bijv. `https://voorbeeld.nl/bieb/` in plaats van `https://voorbeeld.nl/`). Daarvoor is een nieuw veld "URL-submap" toegevoegd, zowel bij het toevoegen van een nieuwe site als bij Site-instellingen
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
- Sites die niet in de website-root staan, maar in een submap (bijv. bij meerdere, losse Joomla-installaties onder hetzelfde account), kunnen nu als `domein.nl/submap` geregistreerd worden - het scanscript herkent automatisch zijn eigen locatie en meldt zich bij de monitor met de juiste, volledige domein+submap-combinatie

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
