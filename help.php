<?php
require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: login.php");
    exit;
}
require_once 'config.php';
require_once 'instellingen_functies.php';

// Voorbeelden in deze handleiding worden gevuld met de actuele instellingen,
// zodat er nooit een verwijzing naar een specifiek bedrijf/domein in blijft
// staan als dit programma ooit aan iemand anders wordt aangeboden. Zolang er
// nog niets is ingevuld op de configuratiepagina, tonen we een duidelijke
// placeholder in plaats van een (mogelijk verouderd) voorbeeld.
$monitorUrl = haalInstelling($pdo, 'monitor_basis_url', null);
$monitorUrlWeergave = $monitorUrl !== null && $monitorUrl !== '' ? $monitorUrl : '<url>';

$cronCode = ontsleutelWaarde(haalInstelling($pdo, 'cron_geheime_code', ''));
$cronCodeWeergave = $cronCode !== '' ? $cronCode : '<cron-beveiligingscode>';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="utf-8">
<script>
// Voorkeur voor licht/donker zo vroeg mogelijk toepassen (vóór de rest van
// de pagina rendert), zodat er geen flits van het verkeerde thema is.
(function () {
    var voorkeur = localStorage.getItem('thema_voorkeur');
    if (voorkeur === 'licht' || voorkeur === 'donker') {
        document.documentElement.setAttribute('data-thema', voorkeur);
    }
})();
</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include 'favicon_tags.php'; ?>
<title>Help - Monitor</title>
<style>

body {
    font-family: Arial, sans-serif;
    margin: 20px;
    font-size: 15px;
    line-height: 1.6;
}

.help-inhoud {
    max-width: 900px;
}

header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 5px;
}

h1 {
    margin: 0 0 5px 0;
}

.subtitel {
    color: #555;
    margin-bottom: 20px;
}

.inhoudsopgave {
    padding: 15px 20px;
    background: #f5f5f5;
    border: 1px solid #ddd;
    border-radius: 6px;
    margin-bottom: 25px;
}

.inhoudsopgave h2 {
    font-size: 16px;
    margin: 0 0 10px 0;
}

.inhoudsopgave ol {
    margin: 0;
    padding-left: 20px;
}

.inhoudsopgave a {
    color: var(--thema-link);
    text-decoration: none;
}

.inhoudsopgave a:hover {
    text-decoration: underline;
}

section {
    margin-bottom: 35px;
    padding-bottom: 25px;
    border-bottom: 1px solid #e5e5e5;
}

section:last-of-type {
    border-bottom: none;
}

h2 {
    font-size: 19px;
    background: var(--thema-kop-bg);
    color: var(--thema-kop-tekst);
    padding: 8px 12px;
    border-radius: 4px;
    margin-bottom: 15px;
}

h3 {
    font-size: 16px;
    margin: 18px 0 6px 0;
    color: var(--thema-link);
}

code {
    background: #eef1f4;
    padding: 1px 6px;
    border-radius: 3px;
    font-size: 14px;
}

.stap {
    margin-bottom: 10px;
    padding: 10px 14px;
    background: #f9f9f9;
    border-left: 3px solid #1f6fa8;
}

.tip {
    padding: 10px 14px;
    background: #eef6fb;
    border: 1px solid #cfe4f0;
    border-radius: 6px;
    margin-top: 10px;
}

.waarschuwing {
    padding: 10px 14px;
    background: #fff3cd;
    border: 1px solid #ffe69c;
    border-radius: 6px;
    margin-top: 10px;
    color: #664d03;
}

table {
    border-collapse: collapse;
    width: 100%;
    margin: 10px 0;
}

th, td {
    border: 1px solid var(--thema-rand);
    padding: 6px 8px;
    font-size: 14px;
    text-align: left;
    vertical-align: top;
}

th {
    background: var(--thema-zebra);
}

.badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
    color: white;
}

.badge-rood   { background: #c0392b; }
.badge-groen  { background: #2e8b3d; }
.badge-grijs  { background: #888; }
.badge-oranje { background: #e67e22; }

.knop {
    display: inline-block;
    padding: 8px 14px;
    background: #333;
    color: white;
    text-decoration: none;
    border-radius: 4px;
    font-size: 14px;
}

.knop:hover {
    background: #555;
}

</style>
<?php include 'responsive_stijlen.php'; ?>
</head>
<body>

<header>
    <div>
        <h1>❓ Help &amp; uitleg</h1>
        <div class="subtitel">Hoe de monitor werkt, stap voor stap.</div>
    </div>
    <div style="display: flex; gap: 8px;">
        <button type="button" class="knop" onclick="history.back()" style="padding: 8px 12px;" title="Eén stap terug">←</button>
        <a class="knop" href="index.php">Terug naar monitor</a>
    </div>
</header>

<div class="help-inhoud">

<div class="inhoudsopgave">
    <h2>Inhoud</h2>
    <ol>
        <li><a href="#configuratie">De configuratiepagina invullen</a></li>
        <li><a href="#uploaden">Het scanscript naar elke site krijgen (handmatig of via FTP/SFTP)</a></li>
        <li><a href="#sites-toevoegen">Sites toevoegen</a></li>
        <li><a href="#site-instellingen">Site-instellingen: extra scanpad, FTP/SFTP &amp; site verwijderen</a></li>
        <li><a href="#een-site-herscannen">Eén site snel opnieuw scannen</a></li>
        <li><a href="#handmatige-scan">Een handmatige scan starten (alle sites)</a></li>
        <li><a href="#cronjob">Automatiseren met een cronjob</a></li>
        <li><a href="#email">E-mailmeldingen instellen</a></li>
        <li><a href="#beveiliging">Het beveiligingsrapport gebruiken</a></li>
        <li><a href="#extensies">Het extensieoverzicht en de extensietabel gebruiken</a></li>
        <li><a href="#statussen">Wat betekenen de kleuren/statussen?</a></li>
        <li><a href="#backups-installatie">Back-ups, installatie- en updatepakketten</a></li>
        <li><a href="#problemen">Veelvoorkomende problemen</a></li>
    </ol>
</div>

<section id="configuratie">
    <h2>1. De configuratiepagina invullen</h2>
    <p>Ga naar het ⚙️-tandwiel bovenin de monitorpagina. Daar staan twee tabbladen: <strong>Algemeen</strong> en <strong>Site toevoegen</strong>.</p>

    <h3>Blok "E-mailinstellingen"</h3>
    <p>Bovenaan dit blok staat het veld <strong>"Naam van de monitor / afzendernaam voor e-mail"</strong> - deze naam verschijnt als afzender in notificatiemails, linksboven op de monitorpagina en het browsertabblad (zie hoofdstuk 8), én als leesbaar voorvoegsel in de bestandsnaam van nieuw aangemaakte scanscripts (bestaande scanscripts op je sites veranderen er niet door). Daaronder staan vijf aan/uit-schakelaars die bepalen wat er in de notificatiemail komt te staan (zie ook hoofdstuk 8): website status, Joomla-versie, extensies, SSL-status en beveiliging. Onderaan staat nog een aparte schakelaar <strong>"Alleen e-mail versturen bij een cronjob"</strong> - handig als je het resultaat bij een handmatige druk op de knop toch al op het scherm ziet en dan geen mail nodig hebt.</p>

    <div class="tip">
        💡 Het logo linksboven is ook het favicon in het browsertabblad, én het icoon dat verschijnt als je deze
        pagina op een telefoon of tablet "toevoegt aan beginscherm"/installeert. Upload je hieronder een eigen
        logo, dan worden favicon en installatie-icoon automatisch mee bijgewerkt naar datzelfde logo - zie
        hieronder.
    </div>

    <h3>Eigen logo</h3>
    <p>Verderop op de configuratiepagina (blok "🖼️ Logo") kun je het standaardlogo linksboven vervangen door een eigen afbeelding. Eisen: <strong>.png, .jpg of .webp</strong>, (ongeveer) <strong>vierkant</strong>, tussen de <strong>128×128 en 1024×1024 pixels</strong>, en maximaal <strong>2 MB</strong>. Een vierkante PNG met een doorzichtige achtergrond geeft meestal het beste resultaat, aangezien het logo altijd op 48×48 pixels wordt getoond, ongeacht de geüploade afmeting.</p>
    <p>Bij het uploaden worden automatisch ook alle favicon-varianten gegenereerd op basis van hetzelfde logo: het icoontje in het browsertabblad, het "installeren op beginscherm"-icoon (iOS en Android), en de grotere iconen voor de PWA-installatie. Alles blijft dus visueel consistent, zonder dat je ergens los nog iets hoeft aan te passen.</p>
    <p>Een knop "Terugzetten naar standaardlogo" is altijd beschikbaar om zowel het logo als alle favicon-varianten in één keer weer ongedaan te maken.</p>
    <div class="tip">
        💡 Het genereren van de favicons vereist de GD-afbeeldingsbibliotheek in PHP - vrijwel elke hostingpartij
        heeft die standaard actief. Is dat bij uitzondering niet het geval, dan blijft het logo linksboven gewoon
        werken; alleen het favicon/installatie-icoon blijft dan het standaardlogo tonen. De monitor meldt
        dit dan ook duidelijk terug na het uploaden.
    </div>

    <h3>Blok "Algemene instellingen"</h3>
    <table>
        <tr><th>Veld</th><th>Uitleg</th></tr>
        <tr><td>E-mailadres voor notificaties</td><td>Hierheen wordt de notificatiemail gestuurd (zie hoofdstuk 7 voor wanneer precies).</td></tr>
        <tr><td>Geheime code</td><td>Een gedeeld wachtwoord tussen de monitor en het scanscript op elke site, zodat niemand anders per ongeluk (of expres) scanresultaten naar jouw monitor kan sturen. Moet <strong>exact</strong> gelijk zijn aan de code in het scanscript op elke site - zie stap 2.</td></tr>
        <tr><td>Cron-beveiligingscode</td><td>Een <em>ander</em> gedeeld wachtwoord, specifiek om de achtergrondscripts (<code>check_sites.php</code>, <code>start_scan.php</code>, <code>haal_versies_op.php</code>, <code>cron_alles_scannen.php</code>) te beschermen tegen willekeurige bezoekers - die controleren namelijk geen login, want een cronjob heeft geen sessie. Zie hoofdstuk 6.</td></tr>
        <tr><td>Pad naar de monitorsite</td><td>De basis-URL van deze monitor zelf, bijv. <code><?php echo htmlspecialchars($monitorUrlWeergave); ?></code> (zonder afsluitende slash). Wordt gebruikt in e-mails en door het scanscript om te weten waar het de resultaten naartoe moet sturen.</td></tr>
        <tr><td>Inlognaam / wachtwoord monitor</td><td>Waarmee jijzelf inlogt op deze monitor. Klik op het 👁️-oogje om het huidige wachtwoord te zien.</td></tr>
    </table>

    <h3>Blok "Database-gegevens"</h3>
    <p>Alleen aanpassen als de monitor zelf naar een andere database moet wijzen (bijv. na een verhuizing). Nieuwe gegevens worden <strong>eerst getest</strong> voordat ze worden opgeslagen, zodat je jezelf niet per ongeluk kan buitensluiten met een typefout.</p>

    <h3>Blok "Site-scanscript"</h3>
    <p>Een kant-en-klare download met de juiste, unieke bestandsnaam voor een specifieke site vind je niet hier, maar bij Site-instellingen van die site zelf (zie hoofdstuk 4) - elke site heeft immers een eigen, unieke scanscript-bestandsnaam (zie hoofdstuk 9 voor de achtergrond hiervan). Dit blok bevat twee andere, sitegroep-brede acties:</p>
    <div class="stap"><strong>🚀 Verstuur scanscript via FTP naar alle sites met FTP-gegevens</strong> - stuurt bij alle sites met bekende FTP-/SFTP-gegevens een verse versie van hun eigen scanscript (met de actuele geheime code/monitor-pad erin verwerkt). Vooral bedoeld als noodmiddel: bij een normale wijziging (bijv. een nieuwe geheime code) hoeft dit namelijk niet meer - dat merkt het scanscript vanzelf bij de eerstvolgende scan (zie de tip hieronder).</div>
    <div class="stap"><strong>🔐 Migreer alle sites naar unieke scanscript-namen</strong> - alleen zichtbaar zolang er nog sites zijn (met FTP-gegevens) die de oude, voor elke site identieke standaardnaam gebruiken. Zet die in één keer om naar hun eigen, unieke naam. Zodra alle sites al gemigreerd zijn, verdwijnt deze knop vanzelf en zie je in plaats daarvan een bevestiging.</div>

    <div class="tip">
        💡 Wijzig je de geheime code of het monitor-pad op deze pagina? Dan hoef je <strong>niet</strong> meer zelf een
        nieuw scanscript te versturen - dat merkt elk scanscript vanzelf bij de eerstvolgende scan, en werkt zichzelf
        dan automatisch bij (zie hoofdstuk 4, "Werkt FTP/SFTP helemaal niet?"). Dit kan wel pas ná de eerstvolgende
        scan ingaan, dus reken niet op een directe, onmiddellijke verandering.
    </div>

    <h3>Blok "🔄 Github-synchronisatie catalogus"</h3>
    <p>Deze installatie leest automatisch mee met een gedeelde catalogus van update-feed-URL's op Github (zie hoofdstuk 10) - daar hoef je zelf niets voor in te stellen, dat werkt vanzelf. Bij "Extensies beheren" verschijnt vanzelf een melding zodra daar iets nieuws of gewijzigds in staat.</p>
    <p>Alleen als je zelf ook wijzigingen mag <strong>terugsturen</strong> naar die gedeelde catalogus (schrijfrechten), vink je <strong>"Ik ben beheerder met schrijfrechten op deze repository"</strong> aan - daar verschijnt dan een veld voor een eigen Github-token: een "fine-grained" personal access token met "Contents: Read and write" op de gedeelde catalogus-repo. Voor gewoon gebruik (lezen) is dit nooit nodig.</p>
    <p>Alleen de sleutel, het label, het manifestpad en de update-feed-URL van elke extensie worden gedeeld - nooit site- of klantgegevens.</p>
    <div class="tip">
        💡 Heb je een token ingevuld? Dan wordt er bij elke wijziging aan een gedeelde update-feed-URL op "Extensies
        beheren" zelf (toevoegen, bijwerken, verwijderen) automatisch ook meteen gepusht. De knop
        <strong>"⬆️ Nu handmatig pushen naar Github"</strong> (verschijnt zodra er een token is ingevuld) is vooral
        bedoeld als noodmiddel, bijv. na het (opnieuw) instellen van een token, of als een eerdere automatische push
        door een tijdelijk verbindingsprobleem niet lukte.
    </div>

    <h3>Blok "🛡️ Admin Tools: informatie voor .htaccess-maker"</h3>
    <p>Herkent automatisch, op basis van de laatst gescande extensielijst, welke sites Akeeba Admin Tools gebruiken - daar hoef je zelf niets voor aan te vinken. Klik op de knop voor een overzicht (favicon, aanklikbare domeinnaam naar de admin-backend, en de exacte scanscript-bestandsnaam) van al die sites naast elkaar, zodat je niet per site apart hoeft op te zoeken welke naam je in de .htaccess-maker moet invullen (zie hoofdstuk 3 voor het volledige stappenplan).</p>
    <div class="tip">
        💡 Dit overzicht toont alleen sites waarbij Admin Tools ooit is aangetroffen bij een scan - is een site nog
        nooit (succesvol) gescand, dan staat 'ie hier ook nog niet tussen, ook al gebruikt die Admin Tools wel
        degelijk.
    </div>
</section>

<section id="uploaden">
    <h2>2. Het scanscript naar elke site krijgen (handmatig of via FTP/SFTP)</h2>
    <p>Er zijn twee manieren: helemaal handmatig downloaden/uploaden, of automatisch laten versturen als je de FTP-/SFTP-gegevens van een site hebt ingevuld (zie hoofdstuk 4).</p>

    <h3>Handmatig</h3>
    <div class="stap"><strong>Stap 1.</strong> Ga naar Site-instellingen van de betreffende site, en klik op de downloadknop onderaan (met daarin de bij die site horende, unieke scanscript-bestandsnaam al verwerkt).</div>
    <div class="stap"><strong>Stap 2.</strong> Open FTP (bijv. FileZilla) en verbind met de website die je wilt monitoren.</div>
    <div class="stap"><strong>Stap 3.</strong> Plaats het gedownloade bestand, zonder de bestandsnaam te wijzigen, in de <strong>root</strong> van die website (dezelfde map waar ook <code>configuration.php</code> van Joomla staat).</div>
    <div class="stap"><strong>Stap 4.</strong> Herhaal dit voor elke site die je wilt monitoren.</div>

    <h3>Automatisch (FTP of SFTP)</h3>
    <p>Heb je bij een site de FTP- of SFTP-gegevens ingevuld (zie hoofdstuk 4), dan verschijnt op Site-instellingen vanzelf een knop <strong>"🚀 Verstuur nu"</strong> in plaats van de downloadknop - één druk plaatst het scanscript rechtstreeks op de juiste plek, inclusief het eventuele extra scanpad. Op de configuratiepagina staat ook een knop om dit in één keer voor <strong>alle</strong> sites met ingevulde gegevens tegelijk te doen.</p>
    <div class="tip">
        💡 Weet je het exacte pad op de server niet zeker (sommige hostingpartijen geven toegang 2-3 mappen boven
        <code>public_html</code>)? Vul dan de server/gebruikersnaam/wachtwoord in en klik op
        <strong>"🔍 Zoek automatisch"</strong> - dit doorzoekt de mappenstructuur zelf naar <code>configuration.php</code>
        en vult het juiste pad meteen in.
    </div>

    <div class="tip">
        💡 Gebruikt de site Akeeba Admin Tools? Dan blokkeert de firewall dit scanscript en de reguliere website-check
        vaak standaard. Op de pagina "Site toevoegen" (tabblad naast Algemeen) staat, achter de knop "Pas dit eerst
        aan als je Akeeba Admin Tools gebruikt", een volledig stappenplan om het IP-adres van de monitor uit te
        zonderen en het scanscript toegankelijk te maken via de .htaccess-maker van Admin Tools. Volg dat stappenplan
        per site.
    </div>
    <div class="waarschuwing">
        ⚠️ De uitzondering in Admin Tools staat op de <strong>exacte bestandsnaam</strong> van het scanscript, en
        elke site heeft daar sinds kort een eigen, uniek gegenereerde naam voor (zie hoofdstuk 9). Wijzig je die naam
        ooit (via "Vervang door nieuwe naam" bij Site-instellingen, of de migratieknop op de configuratiepagina),
        dan moet je de uitzondering in Admin Tools <strong>opnieuw</strong> instellen met de nieuwe naam - anders
        blokkeert de firewall het scanscript alsnog, ook al staat het gewoon correct op de site.
    </div>
</section>

<section id="sites-toevoegen">
    <h2>3. Sites toevoegen</h2>
    <p>Ga naar ⚙️ Configuratie → tabblad "Site toevoegen". Daar vul je twee velden in:</p>

    <h3>Domein</h3>
    <p>De domeinnaam zonder <code>https://</code> of <code>www.</code>, bijv. <code>voorbeeld.nl</code>. Plak je dat er per ongeluk toch bij, dan wordt het automatisch verwijderd.</p>
    <div class="tip">
        💡 <strong>Staat er meerdere, losse Joomla-installaties op hetzelfde hostingaccount</strong> (bijv. verschillende
        stamboom- of familiesites in eigen submappen)? Vul dan de submap achter het domein in, bijv.
        <code>voorbeeld.nl/submap</code>. Dit is puur een <strong>herkenbaar label</strong> in je eigen sitelijst en
        helpt de "Zoek automatisch"-knop bij het FTP-pad de juiste map te vinden tussen meerdere kandidaten - het
        heeft <strong>geen</strong> invloed op de URL waarmee de monitor de site zelf benadert. Is de site ook
        daadwerkelijk via die submap-URL bereikbaar (bijv. <code>https://voorbeeld.nl/submap/</code> in plaats van
        <code>https://voorbeeld.nl/</code>)? Vul dan bij Site-instellingen ook nog het aparte veld "URL-submap" in
        (zie hoofdstuk 4) - anders krijg je een "pagina niet gevonden" zodra de monitor probeert te scannen.
    </div>

    <h3>Admin-pad</h3>
    <p>Het pad naar de <code>administrator</code>-map van Joomla, relatief aan het domein. In de meeste gevallen is dit gewoon <code>administrator</code> (de standaardwaarde).</p>

    <p><strong>Heeft de site een "secret word" (geheim woord) ingesteld?</strong> Sommige sites hebben, vaak via een beveiligingsplugin, een extra geheim woord toegevoegd aan de inlog-URL, zodat de gewone <code>/administrator</code>-pagina niet direct bereikbaar is zonder dat woord. Vul in dat geval het <strong>volledige</strong> pad ín, inclusief dat geheime woord, bijvoorbeeld:</p>
    <table>
        <tr><th>Situatie</th><th>Wat je invult bij Admin-pad</th></tr>
        <tr><td>Geen geheim woord</td><td><code>administrator</code></td></tr>
        <tr><td>Geheim woord als queryparameter</td><td><code>administrator/?geheimwoord</code></td></tr>
        <tr><td>Geheim woord na index.php</td><td><code>administrator/index.php?geheimwoord</code></td></tr>
    </table>
    <p>Dit veld wordt gebruikt voor de knop "🔑 Inloggen als admin" op het extensieoverzicht. Voor de automatische manifest-detectie (een oudere, deels vervangen methode) wordt het geheime woord er trouwens automatisch weer afgehaald, dus dat hoef je zelf niet apart te regelen.</p>

    <h3>Categorie: eigen websites / websites van anderen</h3>
    <p>Elke site is óf een <strong>"Eigen website"</strong>, óf een <strong>"Website van een ander"</strong> - bijvoorbeeld een site die je voor een klant beheert, maar niet per se altijd volledig up-to-date en schoon hoeft te houden zoals je eigen sites. Deze keuze bepaalt twee dingen:</p>
    <div class="stap"><strong>Wat je op de indexpagina ziet</strong> - onder de titel staan twee tabbladen, "🏠 Eigen websites" en "👤 Websites van anderen". Er wordt steeds maar één categorie tegelijk getoond (standaard "Eigen websites"), inclusief de knop "Scan en check sites" - die raakt alleen de sites in de op dat moment zichtbare categorie.</div>
    <div class="stap"><strong>Wat er in de samenvattingsmail komt</strong> - "Websites van anderen" tellen nooit mee in de e-mail over verouderde extensies/beveiligingsissues (zie hoofdstuk 8), ongeacht welke categorie je net op de indexpagina had openstaan. Zo blijft die mail overzichtelijk voor de sites die je zelf volledig schoon wil houden.</div>
    <div class="tip">
        💡 De cronjob (zie hoofdstuk 6) scant, checkt en vergelijkt gewoon <strong>alle</strong> sites uit beide
        categorieën in één keer - de categorie-indeling speelt daar geen rol, die is puur voor de indexpagina zelf
        en de samenvattingsmail. Vanaf een site-detailpagina (bijv. het beveiligingsrapport of extensieoverzicht)
        brengt "Terug naar monitor" je trouwens automatisch terug naar het tabblad waar die site bij hoort.
    </div>

    <h3>URL-submap</h3>
    <p>Bij verreweg de meeste sites staat Joomla gewoon los op het domein (<code>https://voorbeeld.nl/</code>), en laat je dit veld leeg. Sommige sites staan echter in een submap die WEL rechtstreeks via de domeinnaam bereikbaar is, bijvoorbeeld <code>https://voorbeeld.nl/bieb/</code> in plaats van <code>https://voorbeeld.nl/</code>. Vul in dat geval hier die submap in (bijv. <code>bieb</code>).</p>
    <div class="waarschuwing">
        ⚠️ <strong>Dit is iets anders dan het FTP-pad</strong> bij de FTP-gegevens hieronder. Het FTP-pad bepaalt
        alleen in welke map het scanscript op de schijf van de server terechtkomt (voor het uploaden); de URL-submap
        bepaalt via welke webadres de monitor dat scanscript daarna kan bereiken om een scan te starten. Bij de
        meeste hostingpartijen komen deze twee wel overeen, maar dat hoeft niet altijd zo te zijn - vandaar dat het
        twee losse velden zijn.
    </div>
    <p>Staat dit veld verkeerd (of ontbreekt het terwijl het wel nodig is), dan krijg je bij het scannen een melding
    als "nog niet gescand", en geeft het rechtstreeks openen van het scanscript-bestand een "pagina niet gevonden".
    Dit veld wordt gebruikt bij alles wat de monitor rechtstreeks bij de website zelf ophaalt: het starten van een
    scan, het handmatig openen van het scanscript, quarantaine/blokkeer/verwijder-acties, de website-/SSL-status,
    en de Joomla-versiedetectie via het Admin-pad.</p>

    <h3>Slimme opslaanknop</h3>
    <p>Vul je ook meteen de FTP-/SFTP-gegevens in (inclusief een gevonden of handmatig ingevuld pad, niet de kale standaard <code>/</code>), dan verandert de knop onderaan vanzelf van <strong>"Opslaan"</strong> naar <strong>"Opslaan en FTP-bestand versturen"</strong>. In dat geval wordt <code>scan-en-check-website.php</code> na het opslaan direct automatisch naar de juiste locatie verstuurd - stap 2 hoeft dan niet meer apart.</p>

    <div class="waarschuwing">
        ⚠️ Vergeet na het toevoegen niet om <code>scan-en-check-website.php</code> ook daadwerkelijk via FTP op de nieuwe site te zetten (zie stap 2) - anders kunnen de beveiligings- en extensiescans niet draaien voor die site. (Tenzij je de slimme opslaanknop hierboven hebt gebruikt - dan is dit al automatisch gebeurd.)
    </div>
</section>

<section id="site-instellingen">
    <h2>4. Site-instellingen: extra scanpad, FTP/SFTP &amp; site verwijderen</h2>

    <h3>FTP/SFTP-gegevens</h3>
    <p>Bovenaan Site-instellingen kun je kiezen tussen <strong>FTP</strong> (met optioneel "waarvan beveiligd" voor FTPS) en <strong>SFTP</strong> - dat laatste is een compleet ander protocol, gebaseerd op SSH, met meestal poort 22 in plaats van 21. Kies het protocol dat bij de gegevens van je hostingpartij past.</p>
    <p>Zijn deze gegevens ingevuld, dan verschijnt onderaan de pagina automatisch een knop om het scanscript direct te versturen, in plaats van de handmatige downloadknop (zie hoofdstuk 2).</p>

    <h3 id="scanscript-zonder-ftp">Werkt FTP/SFTP helemaal niet? Het scanscript werkt zichzelf ook zelfstandig bij</h3>
    <p>Bij een groeiend aantal hostingpartijen wordt uitgaand FTP-/SFTP-verkeer vanuit PHP tegenwoordig standaard geblokkeerd (een beveiligingsmaatregel tegen misbruik door gehackte sites) - je krijgt dan bijvoorbeeld een melding als <em>"Kon geen FTP-verbinding maken"</em> of <em>"Connection timed out"</em>, ongeacht welke hostingpartij de site zelf gebruikt.</p>
    <p>Dit hoeft geen probleem te zijn voor het <strong>bijwerken</strong> van het scanscript: bij elke scan controleert het scanscript zelf, via een heel gewoon uitgaand HTTPS-verzoek (hetzelfde soort verzoek waarmee het ook zijn resultaten terugstuurt), of er een nieuwere versie van zichzelf bij de monitor klaarstaat - en werkt zichzelf dan automatisch bij, zonder dat daar ooit een FTP-/SFTP-verbinding voor nodig is. Dit zie je terug in het scanrapport, onder "=== ZELF-BIJWERKEN ===".</p>
    <div class="tip">
        💡 Dit lost alléén het <strong>bijwerken</strong> op - de allereerste keer dat het scanscript op een nieuwe
        site moet komen te staan, is nog steeds een eenmalige bestandsoverdracht nodig. Werkt automatisch versturen
        via FTP niet, download het bestand dan gewoon (zie hoofdstuk 2) en zet het zelf via je eigen FileZilla op de
        site - dat werkt namelijk altijd, want dat gebeurt vanaf jouw eigen computer, niet vanaf de server van de
        monitor (waar de blokkade van de hostingpartij op van toepassing is).
    </div>
    <div class="waarschuwing">
        ⚠️ Een kanttekening: dit mechanisme vertrouwt op dezelfde geheime code die ook scanresultaten beveiligt (zie
        Configuratie). Lekt die code ooit uit, dan zou daarmee in theorie niet alleen nepresultaten kunnen worden
        ingestuurd, maar ook scanscript-inhoud kunnen worden opgevraagd/vervangen op elke site die deze code
        gebruikt - bewaar 'm dus met dezelfde zorgvuldigheid als een wachtwoord.
    </div>

    <h3>Mislukte FTP-verbinding door een verkeerd IP-adres of geblokkeerde poorten (PASV-probleem)</h3>
    <p>Sommige hostingpartijen (met name bepaalde Plesk-gebaseerde hosts) geven bij het opzetten van een FTP-verbinding een <strong>verkeerd of onbereikbaar IP-adres</strong> terug voor de daadwerkelijke bestandsoverdracht - een verkeerd ingestelde "masquerade address" aan hostingzijde. FileZilla corrigeert dit automatisch en onopgemerkt; de monitor doet dit tegenwoordig ook, bij zowel het versturen van het scanscript als het automatisch zoeken van het FTP-pad: mislukt de gewone, passieve methode, dan volgen automatisch twee terugvalpogingen - eerst via curl met het IP-adres van de server genegeerd, en lukt dat ook niet, als allerlaatste redmiddel via <strong>actieve</strong> FTP-modus (waarbij de server juist terugverbindt naar de monitor, in plaats van andersom). Dit gebeurt allemaal automatisch, zonder dat je er iets voor hoeft te doen.</p>
    <div class="tip">
        💡 Blokkeert de hostingpartij van de <strong>monitor zelf</strong> uitgaand verkeer naar willekeurige, hoge
        poorten (nodig voor het passieve datakanaal)? Dan kan ook actieve modus alsnog mislukken, aangezien veel
        hostingpartijen inkomend verkeer op willekeurige poorten net zo goed blokkeren. In dat geval blijft alleen
        het handmatige pad-invoerveld (of handmatig uploaden via je eigen FileZilla) over als werkende optie - dat
        heeft dit probleem namelijk nooit, omdat dat altijd vanaf jouw eigen computer gebeurt.
    </div>

    <h3>Mislukte FTP-upload door onjuiste maprechten</h3>
    <p>Mislukt het versturen van het scanscript, dan controleert de monitor automatisch of de doelmap het <strong>uitvoer-recht</strong> voor de eigenaar mist (bijv. rechten "655" in plaats van "755") - zonder dat recht kan een map wel "schrijfbaar" lijken, maar er in de praktijk toch niets in geschreven worden. Is dat het geval, dan krijg je een specifieke melding hierover, en verschijnt er een knop <strong>"🔧 Probeer maprechten automatisch te herstellen (naar 755)"</strong>.</p>
    <div class="tip">
        💡 Deze knop wijzigt alleen iets op je expliciete verzoek - er wordt nooit automatisch/stilzwijgend aan
        maprechten gesleuteld. Lukt het automatisch herstellen niet (bijv. omdat de hostingpartij het "SITE CHMOD"-
        commando niet toestaat via gewone FTP), pas de rechten dan zelf aan via FileZilla (rechtermuisknop op de map
        → Bestandsrechten → 755).
    </div>

    <h3>Extra scanpad</h3>
    <p>Het extra scanpad kijkt automatisch mee tot aan de accountroot van het hostingpakket - kwaadaardige bestanden worden namelijk niet alleen naast <code>public_html</code> aangetroffen, maar soms ook nog hoger. Dit staat altijd aan en hoeft niet apart ingesteld te worden: het scanscript bepaalt zelf, bij elke scan opnieuw, op basis van het <strong>eigenaarschap</strong> van elke map hoe ver dat is - zolang een map nog dezelfde eigenaar heeft als de website zelf, hoort die nog bij hetzelfde hostingaccount en wordt er nog een niveau hoger gekeken; zodra de eigenaar verandert (bijv. bij de gedeelde hoofdmap van de hele server, meestal van root), stopt het daar vanzelf. Dat werkt bij elke hostingpartij, zonder dat er per host verschillende mapnamen herkend hoeven te worden.</p>
    <p>Na de eerstvolgende scan zie je bij Site-instellingen precies staan wat er gevonden is, bijv. <code>📍 Bij de laatste scan gedetecteerd: 3 niveau(s) boven de website-root: /home/gebruikersnaam</code>.</p>
    <p>Herkenbare hostingpartij-systeemmappen/-bestanden (bijv. <code>Maildir</code>, <code>.shadow</code>, <code>.pki</code>, <code>.softaculous</code>, <code>.spamassassin</code>, <code>.trash</code>, <code>.well-known</code>, <code>akeeba-backup</code>) en <code>domains</code>/<code>public_html</code> zelf worden automatisch overgeslagen, zonder dat je daar iets voor hoeft in te stellen - dat voorkomt dat de accountroot in één klap honderden systeeminterne bestandjes als "verdacht" laat zien. Vul daaronder eventueel bij <strong>"Nog extra (sub)mapnamen overslaan"</strong> alleen nog mappen in die specifiek bij déze site ook overgeslagen moeten worden (bijv. een eigen, losse back-upmap).</p>
    <div class="tip">
        💡 <strong>Bewust wél meegescand:</strong> <code>.cagefs</code> en <code>.cl.selector</code> (CloudLinux-
        systeemmappen) staan expres niet op de standaard-uitsluitlijst. Die zijn vaak wereld-schrijfbaar en worden
        zelden gecontroleerd - en dus in de praktijk een populaire plek gebleken voor een geplaatste backdoor.
    </div>
    <div class="tip">
        💡 Krijg je toch nog tientallen of honderden meldingen met "Afwijkende rechten" als reden, allemaal binnen
        dezelfde, voor jou onbekende map? Meld dit dan gerust - dat zou erop kunnen wijzen dat deze hostingpartij
        onverwacht mapstructuren gebruikt waar de automatische detectie nog niet goed op is afgestemd.
    </div>
    <div class="tip">
        💡 Werkt het niet? De meeste hostingpartijen gebruiken <code>open_basedir</code>, wat PHP hard beperkt tot de
        eigen mappenboom. De scanuitvoer meldt dat dan gewoon netjes ("niet bereikbaar"/"niet leesbaar"), zonder te crashen.
    </div>
    <div class="tip">
        💡 Sommige hostingpartijen (bijv. Vimexx) plaatsen naast <code>public_html</code> nog een map als
        <code>private_html</code>, die in werkelijkheid een symlink is naar <em>dezelfde</em> bestanden als de
        website-root zelf (geen kopie). De scan herkent dit automatisch (via het daadwerkelijke, fysieke pad, niet
        de mapnaam) en slaat zo'n map over - anders zou elke vondst dubbel worden gemeld, één keer per pad
        waaronder hetzelfde bestand zichtbaar is.
    </div>
    <p>Binnen het extra scanpad wordt, naast de gewone backdoor-scan, ook gekeken naar:</p>
    <ul>
        <li><strong>Onbekende items</strong> op het topniveau van het extra scanpad (alles behalve de automatisch overgeslagen systeemmappen en eventuele extra's die je zelf hebt opgegeven) - vergelijkbaar met de "onbekend root-level item"-melding op de website zelf, maar dan met het volledige pad als naam, zodat altijd duidelijk is dat het van buiten de website komt.</li>
        <li><strong>Afwijkende bestandsrechten</strong>: elke map die niet op de gebruikelijke 755 staat, en elk bestand dat niet op 644 staat - zowel te ruime (bijv. 777) als te krappe (bijv. 555) rechten worden gemeld. Dit gebeurt bewust <em>alleen</em> binnen het extra scanpad, niet op de website zelf, waar afwijkende rechten vaak juist bewust en legitiem zijn (bijv. <code>configuration.php</code> op 440).</li>
    </ul>
    <p>Bij een vondst binnen dit extra scanpad werken de knoppen <strong>"👁️ Bekijk"</strong> en <strong>"🔧 Rechten herstellen"</strong> gewoon, zodat je de inhoud kunt inzien en afwijkende rechten met één klik kunt herstellen. De knoppen <strong>"Quarantaine"</strong>, <strong>"Blokkeer"</strong> en <strong>"Verwijder"</strong> blijven daar bewust buiten bereik (foutmelding: "Dit kan om veiligheidsredenen niet, gebruik daarvoor handmatig FTP") - wijzigen/verwijderen buiten de website-root zou een aanvaller anders de mogelijkheid kunnen geven om via een handig geconstrueerd pad ergens anders op de server bestanden aan te raken.</p>

    <h3>Scanscript-bestandsnaam</h3>
    <p>Elke site krijgt bij het toevoegen automatisch een <strong>unieke, gegenereerde bestandsnaam</strong> op basis van de naam van deze monitor (bijv. <code>scan-door-compactwebmonitor-a3f9c2.php</code>), in plaats van voor elke site dezelfde vaste naam <code>scan-en-check-website.php</code> te gebruiken. Dat is veiliger (een voorspelbare naam die op elke site identiek is, is voor een geautomatiseerde aanvaller makkelijker te vinden) én zorgt ervoor dat de monitor zo'n bestand altijd herkent als van zichzelf, mocht het ergens los worden teruggevonden. De naam is bewust gekoppeld aan de monitor zelf, niet aan de domeinnaam van de site - welke site het is, is namelijk toch al overduidelijk uit de context; welke monitor het bestand daar heeft neergezet juist niet, bijvoorbeeld als een site door meerdere, losse monitor-installaties wordt gevolgd.</p>
    <p>Deze naam staat om die reden bewust <strong>vast</strong> - er is geen invulveld meer om zelf een naam te kiezen, ook niet bij het toevoegen van een nieuwe site. Draait er op een bepaalde site toch ook nog andere monitorsoftware (bijv. van iemand anders), dan kun je bij Site-instellingen op <strong>"🔄 Vervang door nieuwe, unieke naam"</strong> drukken - dat genereert een nieuwe, andere willekeurige naam, plaatst die op de site (via FTP/SFTP), en ruimt het oude bestand automatisch op. Dit is de enige manier om de naam nog te wijzigen.</p>
    <div class="tip">
        💡 Sites die zijn toegevoegd vóórdat deze functie bestond, gebruiken mogelijk nog de oude, voor elke site
        identieke standaardnaam. Zie hoofdstuk 1 (de configuratiepagina, blok "Site-scanscript") voor de eenmalige
        migratieknop die dit voor al die sites in één keer rechttrekt.
    </div>

    <h3>Site verwijderen</h3>
    <p>Klik op het 🗑️-icoontje achteraan een siterij op de monitorpagina. Na een bevestigingsvraag gebeurt het volgende:</p>
    <div class="stap"><strong>1. Alle lokale gegevens</strong> - de site zelf, alle gescande extensiegegevens, bestandshashes, vertrouwde items en de scanscript-bestandsnaamgeschiedenis worden uit de monitor-database verwijderd.</div>
    <div class="stap"><strong>2. Het scanscript-bestand op de site zelf</strong> - zijn er FTP-/SFTP-gegevens bekend, dan probeert de monitor het scanscript-bestand ook daadwerkelijk van de site te verwijderen. Dit gebeurt voor <em>alle</em> bestandsnamen die het scanscript op deze site ooit heeft gehad (dus ook een eerder vervangen naam, zie hoofdstuk 4) - niet alleen de meest recente naam.</div>
    <div class="stap"><strong>3. De map <code>_scan_beheer</code> op de site zelf</strong> - deze map (met daarin eventuele quarantaine-, geblokkeerd- en prullenbak-items, zie hoofdstuk 9) wordt, samen met alle inhoud, ook automatisch geprobeerd te worden opgeruimd.</div>
    <p>Na afloop toont de monitor een duidelijke melding over wat er met het scanscript-bestand en de <code>_scan_beheer</code>-map is gebeurd: geslaagd, gedeeltelijk mislukt, geen verbinding mogelijk, of geen FTP-gegevens bekend. Lukt het niet automatisch, dan blijven deze gewoon op de site staan - verwijder ze dan zelf via FTP als je dat wilt.</p>
    <div class="tip">
        💡 Dit alles is bewust een "beste poging": lukt het verwijderen op de site zelf niet, dan gaat de opruiming in
        de monitor-database gewoon door. Er wordt hier nooit iets stiekem overgeslagen zonder dat je dat te zien krijgt.
    </div>
</section>

<section id="een-site-herscannen">
    <h2>5. Eén site snel opnieuw scannen</h2>
    <p>Wil je niet wachten op de volledige scancyclus van alle sites, maar gewoon één specifieke site meteen verversen? Klik op het gele ↻-icoontje - dat staat op drie plekken: vooraan de siterij op de monitorpagina, en bovenaan zowel het extensieoverzicht als het beveiligingsrapport van die site.</p>
    <p>Dit doorloopt dezelfde stappen als de grote "Scan en check sites"-knop (zie hoofdstuk 6), maar dan alleen voor die ene site, en met een kortere wachttijd (10 in plaats van 20 seconden) - een voortgangsbalk (0-100%) laat precies zien in welke stap de scan zich bevindt, en de pagina ververst zichzelf zodra het klaar is.</p>
    <div class="tip">
        💡 Blokkeert een <code>.htaccess</code>-bestand in de hoofdmap van de site het scanverzoek zelf (bijv. een
        kwaadaardige "deny from all"-regel)? Dan herkent de monitor dit en toont een duidelijke, oranje waarschuwing
        in plaats van gewoon door te gaan naar de wachttijd - controleer in dat geval de <code>.htaccess</code>-bestanden
        handmatig via FTP.
    </div>
    <p>Twee andere icoontjes in dezelfde actiekolom: <strong>"FTP"</strong> opent de site direct in je lokale FTP-client (bijv. FileZilla), met gebruikersnaam/wachtwoord al ingevuld - tenzij die een teken bevatten dat niet betrouwbaar in zo'n link kan (bijv. een "@"), in welk geval beide apart, kopieerbaar getoond worden en FileZilla bewust niet automatisch opent. <strong>"PDF"</strong> opent het klantrapport (zie hoofdstuk 9) - een niet-technische samenvatting om door te sturen naar de site-eigenaar.</p>
</section>

<section id="handmatige-scan">
    <h2>6. Een handmatige scan starten (alle sites)</h2>
    <p>Ga naar de monitorpagina en druk op de knop <strong>"🛡️🔍 Scan en check sites"</strong>. Een voortgangsbalk (0-100%) en leesbare statusteksten laten zien wat er gebeurt. Dit doorloopt automatisch, na elkaar, vier stappen:</p>
    <div class="stap">1. <strong>Scan starten op alle websites</strong> - <code>scan-en-check-website.php</code> wordt op elke site aangeroepen (backdoor-scan + extensie-inventarisatie), parallel voor alle sites tegelijk. Vlak voordat het eigenlijke scannen van een site begint, wordt bij die site automatisch eerst de eigen <code>tmp</code>-map geleegd - handig omdat deze map vaak juist de plek is waar kortstondige, verdachte bestanden terechtkomen. Dit gebeurt bij elke scan, ook bij "Één site snel opnieuw scannen" (hoofdstuk 5) en handmatige herscans; er is geen aparte knop meer voor nodig.</div>
    <div class="stap">2. <strong>Even wachten</strong> (20 seconden, aftellend zichtbaar) - zodat de sites de tijd krijgen om de scan af te ronden en het resultaat terug te sturen.</div>
    <div class="stap">3. <strong>Website- en SSL-status controleren, Joomla-/extensieversies ophalen</strong> - inclusief "is dit de nieuwste versie?" en het favicon van elke site.</div>
    <div class="stap">4. <strong>Extensiebestanden tussen sites vergelijken</strong> - zie hoofdstuk 9.</div>
    <div class="stap">5. <strong>Notificatie-e-mail controleren</strong> - verstuurt (indien nodig, zie hoofdstuk 8) één samengevatte mail.</div>
    <p>Na afloop ververst de pagina automatisch en zie je de bijgewerkte gegevens in de tabel.</p>
</section>

<section id="cronjob">
    <h2>7. Automatiseren met een cronjob</h2>
    <p>Wil je niet elke keer handmatig op de knop drukken, dan kan dit via een cronjob (bijv. in DirectAdmin) volledig automatisch, bijvoorbeeld 1x per dag.</p>
    <p>Zet in de cronjob een verwijzing naar <code>cron_alles_scannen.php</code> (staat al klaar op <code><?php echo htmlspecialchars(__DIR__); ?>/</code>) - dit bestand doorloopt zelf dezelfde stappen als de knop hierboven (inclusief de wachttijd, en de vergelijking van extensiebestanden tussen sites, zie hoofdstuk 9), maar dan zonder dat er een browser nodig is.</p>

    <div class="waarschuwing">
        ⚠️ <code>cron_alles_scannen.php</code> (en de losse stappen erachter) checken geen login - dat kan ook niet,
        want een cronjob heeft geen sessie. In plaats daarvan moet de <strong>cron-beveiligingscode</strong> (in te
        stellen bij Configuratie → Algemeen) als <code>?cron_code=...</code> worden meegegeven in de URL, anders
        wordt de aanvraag geweigerd. De voorbeelden hieronder tonen de huidige code al.
    </div>

    <h3>Voorbeeld-commando's</h3>
    <p>Afhankelijk van wat DirectAdmin aanbiedt. Deze pagina draait op dezelfde server als de monitor zelf, dus het
    volledige pad bij "PHP-CLI" hieronder is het daadwerkelijke, actuele pad - geen placeholder meer nodig.</p>
    <?php
    $wgetCommando = 'wget -q -O /dev/null "' . $monitorUrlWeergave . '/cron_alles_scannen.php?cron_code=' . $cronCodeWeergave . '"';
    $curlCommando = 'curl -s -o /dev/null "' . $monitorUrlWeergave . '/cron_alles_scannen.php?cron_code=' . $cronCodeWeergave . '"';
    $cliCommando  = 'php -q ' . __DIR__ . '/cron_alles_scannen.php --cron_code=' . $cronCodeWeergave;
    ?>
    <table>
        <tr><th>Type</th><th>Commando</th><th style="width: 40px;"></th></tr>
        <tr>
            <td>URL-aanroep (wget)</td>
            <td><code><?php echo htmlspecialchars($wgetCommando); ?></code></td>
            <td><button type="button" class="knop" style="padding: 4px 8px; font-size: 11px;" data-commando="<?php echo htmlspecialchars($wgetCommando); ?>" onclick="kopieerCronCommando(this, this.dataset.commando)">📋</button></td>
        </tr>
        <tr>
            <td>URL-aanroep (curl)</td>
            <td><code><?php echo htmlspecialchars($curlCommando); ?></code></td>
            <td><button type="button" class="knop" style="padding: 4px 8px; font-size: 11px;" data-commando="<?php echo htmlspecialchars($curlCommando); ?>" onclick="kopieerCronCommando(this, this.dataset.commando)">📋</button></td>
        </tr>
        <tr>
            <td>PHP-CLI</td>
            <td><code><?php echo htmlspecialchars($cliCommando); ?></code></td>
            <td><button type="button" class="knop" style="padding: 4px 8px; font-size: 11px;" data-commando="<?php echo htmlspecialchars($cliCommando); ?>" onclick="kopieerCronCommando(this, this.dataset.commando)">📋</button></td>
        </tr>
    </table>
    <script>
    function kopieerCronCommando(knop, tekst) {
        if (!navigator.clipboard) {
            prompt('Kopiëren via de knop is hier niet mogelijk - selecteer en kopieer het commando hieronder handmatig:', tekst);
            return;
        }
        navigator.clipboard.writeText(tekst).then(() => {
            const origineel = knop.textContent;
            knop.textContent = '✅';
            knop.disabled = true;
            setTimeout(() => {
                knop.textContent = origineel;
                knop.disabled = false;
            }, 1500);
        }).catch(() => {
            prompt('Kopiëren is niet gelukt - selecteer en kopieer het commando hieronder handmatig:', tekst);
        });
    }
    </script>
    <p>Voor 1x per dag: minuut <code>0</code>, uur bijv. <code>3</code>, dag/maand/weekdag <code>*</code> (dus elke nacht om 03:00).</p>
    <div class="tip">
        💡 Zowel de geheime code als de cron-beveiligingscode mogen sinds kort alleen nog letters, cijfers,
        streepjes en underscores bevatten (afgedwongen bij het opslaan op de configuratiepagina) - juist om
        problemen zoals hieronder te voorkomen.
    </div>
    <div class="waarschuwing">
        ⚠️ Een "%" in de cron-beveiligingscode werkt niet in een gewone crontab-regel: cron ziet een onge-escapete
        "%" als een regeleinde, waardoor het commando halverwege afbreekt en de cronjob niet (goed) werkt. Dit
        gebeurt vaak onopgemerkt, aangezien de code op de configuratiepagina zelf gewoon correct wordt getoond en
        gebruikt - het probleem zit hem puur in hoe cron zelf de crontab-regel interpreteert. Een handmatige
        oplossing is om elke "%" te escapen met een backslash (dus <code>%</code> wordt <code>\%</code>) - maar
        met de tekenbeperking hierboven zou dit sinds kort al niet meer kunnen voorkomen.
    </div>
</section>

<section id="email">
    <h2>8. E-mailmeldingen instellen</h2>
    <p>Bij Configuratie → Algemeen → blok "E-mailinstellingen" bepaal je precies wat er in de notificatiemail komt. Er wordt alleen een mail verstuurd als er ook daadwerkelijk iets te melden is - géén mail betekent dus "alles in orde" volgens de aangevinkte categorieën.</p>
    <p>Bovenaan datzelfde blok staat het veld <strong>"Naam van de monitor / afzendernaam voor e-mail"</strong> - deze naam wordt gebruikt als afzendernaam in de mailclient van de ontvanger, als de titel van de monitor zelf (linksboven op de overzichtspagina, browsertabblad, enz.), én als leesbaar voorvoegsel in de bestandsnaam van nieuw aangemaakte scanscripts (bestaande scanscripts op je sites blijven ongewijzigd werken). Leeg laten geeft de standaardnaam "Mijn Websites Monitor".</p>
    <div class="tip">
        💡 Wijzig je de monitornaam zodanig dat dit ook de bestandsnaam zou beïnvloeden (puur cosmetische wijzigingen
        zoals een ander leesteken tellen niet mee), dan verschijnt er direct na het opslaan een <strong>optionele</strong>
        melding met een knop om de bestandsnamen van alle bestaande sites in één keer bij te werken naar de nieuwe
        naam (inclusief het opruimen van het oude bestand) - dit is nooit verplicht, bestaande scanscripts blijven
        anders gewoon werken. Gebruik je bij een site Akeeba Admin Tools' bestandsnaam-restrictie, voeg de nieuwe
        naam daar dan zelf nog aan toe, anders blokkeert Admin Tools het zojuist hernoemde scanscript alsnog.
    </div>
    <table>
        <tr><th>Categorie</th><th>Meldt wanneer</th><th>Wat er in de mail staat</th></tr>
        <tr><td>Website status</td><td>Bij één van de aangevinkte criteria: geen verbinding, HTTP-foutcode, of verdachte inhoud.</td><td><code>Website: 🔴 Offline (HTTP 500)</code></td></tr>
        <tr><td>Joomla-versie</td><td>Als er een nieuwere versie beschikbaar is dan geïnstalleerd.</td><td><code>Joomla: 6.1.1 → nieuwste 6.1.2 beschikbaar</code></td></tr>
        <tr><td>Extensies</td><td>Als er één of meer extensies niet up-to-date zijn.</td><td><code>Extensies - Niet up-to-date: 3</code></td></tr>
        <tr><td>SSL-status</td><td>Alleen als het certificaat daadwerkelijk verlopen is (niet bij "bijna verlopen").</td><td><code>SSL: certificaat verlopen</code></td></tr>
        <tr><td>Beveiliging</td><td>Als er niet-vertrouwde verdachte bestanden zijn gevonden.</td><td><code>Beveiliging - Verdachte bestand(en): 2</code></td></tr>
    </table>
    <p>De schakelaar <strong>"Alleen e-mail versturen bij een cronjob"</strong> onderaan datzelfde blok zorgt ervoor dat een handmatige druk op "Scan en check sites" nooit een mail stuurt (je ziet het resultaat toch al op het scherm) - alleen de cronjob doet dat dan nog.</p>
    <div class="tip">
        💡 Sites met categorie "Website van een ander" (zie hoofdstuk 4) tellen nooit mee in deze mail, ongeacht welke
        van de criteria hierboven van toepassing zijn.
    </div>
    <div class="tip">
        💡 De mail is een HTML-mail: bij elke site met een melding zie je het favicon (linkt naar de website zelf) en
        de domeinnaam (linkt naar het beheergedeelte van die site) - net als op de monitorpagina zelf.
    </div>
</section>

<section id="beveiliging">
    <h2>9. Het beveiligingsrapport gebruiken</h2>
    <p>Klik in de kolom "Beveiliging" op de status van een site om het volledige rapport te zien: alle gevonden verdachte bestanden/mappen, met type, pad, wijzigingsdatum en reden.</p>
    <p>Weet je zeker dat een gevonden item legitiem is (bijv. een bekende extensie-map die toevallig als "onbekend" wordt gezien)? Vink dan het bijbehorende vinkje in de kolom "Vertrouwd" aan. Dat item verdwijnt dan uit de standaardweergave bij een volgende scan. Wil je alles (ook vertrouwde items) alsnog zien, klik dan op "Toon ook de vertrouwde items".</p>
    <div class="tip">
        💡 Verandert een eerder vertrouwd bestand later opnieuw (nieuwe wijzigingsdatum)? Dan verschijnt het vanzelf weer als "nieuw verdacht" - een eenmaal vertrouwd bestand blijft dus niet blind vertrouwd als het later opnieuw wordt aangepast.
    </div>

    <h3>Risicoscore</h3>
    <p>Elke vondst krijgt een risicoscore (0-100) op basis van het gevonden patroon, met een badge: <strong>ZEER HOOG</strong> (≥ 90, bijv. een dubbel-gelaagde <code>eval(base64_decode(...))</code>-backdoor), <strong>HOOG</strong> (≥ 70), <strong>MIDDEL</strong> (≥ 40) en <strong>LAAG</strong> (&lt; 40, bijv. een verdubbelde mapnaam die vaak toch legitiem blijkt). De tabel staat standaard gesorteerd op risico, hoogste eerst - zo zie je meteen wat de meeste aandacht verdient.</p>

    <h3>Extra controles: kernbestand-integriteit, Super Users en defacement</h3>
    <p>Naast de gewone bestandscontroles voert het scanscript nog een aantal aanvullende, verdergaande checks uit:</p>
    <div class="stap"><strong>Kernbestand-integriteit</strong> - <code>index.php</code>, <code>administrator/index.php</code>, <code>api/index.php</code> en <code>includes/app.php</code> worden gecontroleerd op code die wordt uitgevoerd <em>vóórdat</em> Joomla's eigen <code>_JEXEC</code>-bootstrap is gedefinieerd. Een schoon kernbestand heeft daar nooit iets staan - vindt het scanscript hier toch iets, dan krijgt die vondst het hoogst mogelijke risico (100): dit betekent dat de site op dit moment actief wordt misbruikt bij elke paginaweergave, niet dat er ooit iets is gebeurd.</div>
    <div class="stap"><strong>Cloaking-detectie</strong> - dezelfde twee kernbestanden (<code>index.php</code>/<code>administrator/index.php</code>) worden ook gecontroleerd op de combinatie van bot-detectiepatronen (bijv. <code>Googlebot</code>/<code>bingbot</code> in de user-agent) én code die externe inhoud ophaalt (<code>file_get_contents</code>/<code>curl_exec</code>/<code>fopen</code>). Los van elkaar komen beide soms ook onschuldig voor - de combinatie in een kernbestand is een sterk signaal voor een aanval die aan zoekmachines andere (vaak spam-/malware-)inhoud toont dan aan gewone bezoekers, precies om onopgemerkt te blijven.</div>
    <div class="stap"><strong>Massaal-hernoemen-detectie</strong> - signaleert wanneer vijf of meer bestanden/mappen in de webroot hetzelfde ongebruikelijke achtervoegsel delen (bijv. <code>bestand.php__113576e</code>). Dit patroon hoort bij een aanvalstype dat de hele website in één klap onbereikbaar maakt door vrijwel alle bestanden tegelijk te hernoemen - één zo'n bestand is toeval, vijf of meer is dat vrijwel nooit.</div>
    <div class="stap"><strong>Onzichtbare Unicode-tekens</strong> - bestandsnamen met verborgen zero-width-tekens of een RTL-omkeringsteken (een bekende truc om een kwaadaardig bestand te laten lijken op iets onschuldigs, bijv. een naam die op ".jpg" lijkt te eindigen maar in werkelijkheid ".php" is) worden apart en met hoog risico gemeld.</div>
    <div class="stap"><strong>Exploit-scanner-restanten</strong> - een 0-byte bestand met een SHA-1-hash-achtige naam (bijv. <code>da39a3ee5e6b4b0d3255bfef95601890afd80709</code>, eventueel met een korte willekeurige toevoeging) is een bekend restant van een geautomatiseerde tool die testte of een map schrijfbaar is. Het bestand zelf is onschuldig (kan geen code bevatten), maar bewijst wel dat hier ooit schrijftoegang is geweest - vandaar een lage, informatieve melding ("TER INFO").</div>
    <div class="stap"><strong>Dubbele-extensietruc &amp; verstopte code in uploadmappen</strong> - een bestand in de images-/tmp-map zonder PHP(-achtige) extensie (bijv. <code>foto.php.gif</code>, of een bestand zonder enige extensie) wordt ook op inhoud gecontroleerd op een verstopte PHP-openingstag. Dat gaat verder dan alleen <code>&lt;?php</code>: ook de korte vormen <code>&lt;?=</code> en de kale <code>&lt;?</code> worden herkend (de laatste specifiek omdat een aangetroffen GIF/PHP-polyglot-webshell die vorm gebruikte om detectie op <code>&lt;?php</code> te omzeilen). Gewone, legitieme XML-/XMP-achtige inhoud (bijv. de standaard Adobe-metadataheader in JPG's/PDF's) wordt hierbij automatisch herkend en overgeslagen, net als toevallige binaire ruis in ongecomprimeerde beeldformaten (BMP) die net iets te leesbaar oogt.</div>
    <div class="stap"><strong>chr()-obfuscatie</strong> - functienamen die niet als platte tekst worden aangeroepen, maar via een reeks <code>chr()</code>-aanroepen worden samengesteld (een bekende Godzilla/Behinder-stijl webshelltechniek), worden herkend, ook in combinatie met stringconcatenatie van twee-of-meer stukken (bijv. <code>"as"."se"."rt"</code>).</div>
    <div class="stap"><strong>Massale-upload-detectie</strong> - vijf of meer verschillend genoemde bestanden met exact dezelfde bestandsgrootte in de images-/tmp-map wordt gemeld als mogelijk teken van een geautomatiseerde uploadtool die dezelfde payload onder meerdere namen/extensies test. Bij drie of meer verschillende extensies binnen zo'n cluster is het risico hoger (dat is precies het patroon van een tool die test welke extensie de server als PHP uitvoert); bij één gedeelde extensie lager ("kan toeval zijn"). Clusters waarbij alle bestanden een audio-, video- of documentextensie hebben (die kunnen sowieso nooit als PHP worden uitgevoerd) worden helemaal niet gemeld, en hetzelfde geldt voor de bekende back-up-/cachemappen van JCH Optimize.</div>
    <div class="stap"><strong>Verzwakkende php.ini-/.user.ini-bestanden</strong> - een bestand met deze naam (site-breed, niet beperkt tot images/tmp) wordt gecontroleerd op een combinatie van beveiligingsverzwakkende directives (<code>disable_functions</code> leeggemaakt, <code>open_basedir</code> uitgeschakeld, de verouderde <code>safe_mode</code>-directive, <code>exec</code>/<code>shell_exec=on</code>). Zo'n bestand doet zelf niets, maar is een bekende "sleutel zonder slot" die een aanvaller vlak vóór of samen met een webshell plaatst om hostingbrede restricties lokaal te omzeilen - vaak diep genest in een willekeurig genoemde map, bijvoorbeeld onder <code>components/com_media/</code>. Pas bij twee-of-meer van deze signalen tegelijk wordt dit gemeld, zodat een legitieme, handmatige php.ini (bijv. voor hogere uploadlimieten) niet ten onrechte wordt geraakt.</div>

    <div class="tip">
        💡 <code>.cagefs</code> en <code>.cl.selector</code> (twee CloudLinux-systeemmappen die verder standaard worden
        overgeslagen bij het extra scanpad) worden bewust WEL op inhoud doorzocht. Die mappen zijn vaak wereld-
        schrijfbaar en worden zelden gecontroleerd, en zijn in de praktijk daadwerkelijk een keer gebruikt gebleken
        om een backdoor in te verstoppen. Alleen de rechtencontrole daarbinnen wordt overgeslagen (CloudLinux beheert
        die rechten zelf, en dat zou anders bij elke PHP-versie op de server dezelfde melding herhalen) - de
        inhoudscontrole op verdachte code blijft er gewoon actief.
    </div>
    <div class="stap"><strong>Verdachte Super Users</strong> - het scanscript zet zelf, net als Joomla, een alleen-lezen databaseverbinding op (via de gegevens uit <code>configuration.php</code>) en controleert alle Super User-accounts op bekende aanvallerspatronen in gebruikersnaam of e-maildomein. Dit soort vondsten heeft het type "database" i.p.v. "backdoor"/"bestand", en heeft daarom geen Bekijk/Quarantaine/Blokkeer/Verwijder-knoppen (een gebruikersaccount heeft geen bestandspad) - alleen "Vertrouwen". Los zo'n vondst zelf op via Joomla Beheerder → Gebruikers.</div>
    <div class="stap"><strong>Defacement-detectie</strong> - templatestijlen worden gecontroleerd op ontmaskeringsteksten ("Hacked by", "Owned by", e.d.) in de parameters. Ook dit is een vondst van het type "database".</div>
    <div class="tip">
        💡 De kernbestand-integriteit-, cloaking-, Super Users- en defacement-controles maken gebruik van een eigen,
        alleen-lezen databaseverbinding die het scanscript zelf opzet - lukt dat om wat voor reden dan ook niet
        (bijv. een ongebruikelijke serverconfiguratie), dan worden deze checks gewoon overgeslagen; de rest van de
        scan gaat daarna altijd normaal door.
    </div>
    <p>Daarnaast worden ook backup-/duplicaatconfiguratiebestanden (bijv. <code>configuration.bak.php</code>) apart en met een hoog risico gemeld: die lekken namelijk dezelfde databasewachtwoorden en geheime sleutel als het echte <code>configuration.php</code>.</p>

    <h3>Compleet Super Users-overzicht</h3>
    <p>Los van de automatische herkenning van bekende aanvallerspatronen hierboven, toont het beveiligingsrapport - als de databaseverbinding is gelukt - ook een apart blokje <strong>"👤 Super Users"</strong> met álle beheerdersaccounts: naam, gebruikersnaam, e-mail, aangemaakt, laatst ingelogd en actief/geblokkeerd. Handig om zelf even te doorlopen en te controleren of je elke naam herkent - ook een account dat (nog) geen bekend aanvallerspatroon gebruikt, valt zo alsnog op.</p>

    <h3>Geneste of losstaande Joomla-installaties</h3>
    <p>Een map die zowel een eigen <code>configuration.php</code> als een eigen <code>administrator</code>-map bevat, wordt automatisch herkend als een complete, eigen Joomla-installatie - bijvoorbeeld een oude staging-kopie in een submap van de website zelf, of (bij hostingpartijen zoals Strato) een andere site die los naast de huidige in dezelfde accountroot staat. In plaats van in bulk als "onbekend" gemeld te worden, verschijnt hiervoor één duidelijke, informatieve melding, en wordt de vertrouwde-Joomla-mappenlijst er verder ook op toegepast.</p>

    <h3>Verzamelmeldingen (type "cluster")</h3>
    <p>De massale-upload-detectie hierboven gaat altijd over meerdere bestanden tegelijk in dezelfde map - dat verschijnt daarom als één "Verzamelmelding" met type <strong>cluster</strong>, in plaats van een losse vondst per bestand. Zo'n melding heeft bewust geen Quarantaine/Blokkeer/Verwijder-knoppen (net als bij een "database"-vondst) - de map bevat immers ook gewoon legitieme content, dus "verwijderen" zou geen eenduidig doelwit hebben. Bekijk en verwerk de losse bestanden in dat geval handmatig via FTP; "Vertrouwen" werkt op dit type wél gewoon, en blijft ook bij een volgende, ongewijzigde scan behouden.</p>

    <h3>Filteren op type</h3>
    <p>Staan er meerdere soorten vondsten in de lijst (bijv. zowel "backdoor" als "map" als "htaccess"), dan verschijnt bovenaan een keuzemenu om te filteren op één specifiek type - handig om niet steeds tussen soorten door elkaar heen te moeten scrollen. De lijst staat sowieso al standaard gegroepeerd per type (in volgorde van ernst), ook zonder te filteren.</p>

    <h3>Direct actie ondernemen op een vondst</h3>
    <p>Achter elke vondst staan enkele knoppen, die rechtstreeks op de site zelf worden uitgevoerd (via het scanscript, met dezelfde geheime code als een scan) - zonder dat je FTP hoeft te openen:</p>
    <div class="stap"><strong>👁️ Bekijk</strong> - toont de inhoud van het bestand (of de inhoud van een map) read-only, direct op deze pagina, ter verificatie vóórdat je een keuze maakt.</div>
    <div class="stap"><strong>🔧 Rechten herstellen</strong> - herstelt de rechten naar de gangbare, veilige waarde: 644 voor een los bestand, 755 voor een map. Staat het al op die waarde, dan gebeurt er niets.</div>
    <div class="stap"><strong>📦 Quarantaine</strong> - verplaatst het bestand naar een afgeschermde map op de site zelf (<code>_scan_beheer/quarantaine/</code>, met een eigen <code>.htaccess</code> die de hele map van het web afschermt) en zet de rechten op alleen-lezen. Volledig herstelbaar.</div>
    <div class="stap"><strong>🚫 Blokkeer</strong> - hernoemt het bestand ter plekke (bijv. <code>iets.php.BLOCKED_20260713_143022_a1b2c3</code>) en zet het op alleen-lezen. Blijft op zijn oorspronkelijke plek staan, maar kan niet meer worden uitgevoerd of aangeroepen. Volledig herstelbaar.</div>
    <div class="stap"><strong>🗑️ Verwijder</strong> - verplaatst het bestand naar een prullenbak op de site (ook afgeschermd), die na <strong>7 dagen</strong> automatisch definitief wordt geleegd. Tot die tijd nog gewoon herstelbaar.</div>
    <p>Bij een geslaagde actie verdwijnt de vondst meteen uit de lijst hierboven (en uit de tellers) - je hoeft niet te wachten op de volgende volledige scan.</p>

    <h3>Bulkacties: meerdere vondsten tegelijk afhandelen</h3>
    <p>Vooraan elke rij staat een selectievakje (met een "alles selecteren"-vakje in de kolomkop). Zodra je iets aanvinkt, verschijnt bovenaan een balk met dezelfde acties (Vertrouwen, Bekijk, Rechten herstellen, Quarantaine, Blokkeer, Verwijder), die je in één keer op alle geselecteerde items toepast - met een voortgangsteller en één bevestigingsvraag vooraf, in plaats van elk item los te moeten aanklikken.</p>
    <div class="tip">
        💡 Database-bevindingen en verzamelmeldingen (type "cluster") kunnen alleen bulk-vertrouwd worden (die los je
        verder op via Joomla zelf, resp. handmatig via FTP). Filter je eerst de lijst en selecteer je daarna pas
        iets, dan telt een item dat buiten beeld valt nooit stiekem mee bij een bulkactie.
    </div>

    <h3>Beheer (quarantaine, geblokkeerd, prullenbak)</h3>
    <p>Onderaan het beveiligingsrapport staat een aparte sectie die alles toont wat op dit moment in quarantaine, geblokkeerd, of in de prullenbak staat (op de site zelf, niet in de monitor-database). Per item kun je kiezen:</p>
    <div class="stap"><strong>↩️ Herstel</strong> - zet het item weer terug op de oorspronkelijke plek. Let op: staat er inmiddels alweer iets anders op die plek, dan mislukt dit bewust (geen overschrijven).</div>
    <div class="stap"><strong>❌ Definitief verwijderen</strong> - verwijdert het item meteen, zonder op de automatische 7-dagentermijn te wachten. Kan niet ongedaan gemaakt worden.</div>
    <p>Staan er prullenbak-items in, dan verschijnt er ook een knop om de hele prullenbak in één keer te legen.</p>
    <div class="tip">
        💡 Dit hele beheersysteem staat volledig los van de monitor-database: alle bestanden blijven gewoon op de
        site zelf staan (in een eigen, afgeschermde map), de monitor stuurt alleen de opdracht door. Verwijder je
        het scanscript ooit van een site, dan blijft die map (met alles wat er nog in quarantaine/prullenbak stond)
        gewoon achter - controleer dat zelf even via FTP als je een site helemaal opdoekt.
    </div>

    <h3>Afwijkende extensiebestanden (vergeleken met andere sites)</h3>
    <p>Onderaan het beveiligingsrapport verschijnt, als er iets gevonden is, een aparte sectie die extensiebestanden tussen al je gemonitorde sites onderling vergelijkt. Heeft een site dezelfde extensie + hetzelfde versienummer als andere sites, maar wijkt een bestand daarbinnen af van wat bij de meerderheid van die andere sites is aangetroffen? Dan is dat een signaal dat het bestand op déze site mogelijk is aangepast (bijv. door een backdoor) - zonder dat daar externe downloads voor nodig zijn.</p>
    <div class="tip">
        💡 Dit is een signaal, geen bewijs - het kan ook een bewuste, onschuldige aanpassing zijn die je zelf ooit hebt
        gedaan. Controleer het genoemde bestand handmatig via FTP om zeker te zijn. Hoe meer sites dezelfde extensie
        hebben, hoe betrouwbaarder de vergelijking.
    </div>
    <p><em>Joomla's eigen kernbestanden</em> zitten sinds versie 1.18 bewust niet meer in deze vergelijking - die hebben een eigen, preciezere vergelijking tegen het officiële Joomla-pakket, zie de sectie hieronder.</p>
    <p><strong>Samengevoegde rijen bij dezelfde afwijking op veel bestanden tegelijk</strong> - wijken van één extensie+versie meerdere bestanden tegelijk op precies dezelfde manier af (dezelfde andere site(s) hebben steeds exact dezelfde inhoud als deze site)? Dan worden die sinds versie 1.20 samengevoegd tot één rij, bijv. "73 bestanden wijken op dezelfde manier af", in plaats van 73 losse regels. Dat wijst meestal op een andere sub-versie/build van diezelfde extensie (bijv. een Pro- versus Core-editie, of een tussentijdse hotfix zonder eigen versienummer) - géén losse verdachte bestanden. Zo'n rij staat, zolang hij nog niet is beoordeeld, standaard al opengeklapt; is hij eenmaal vertrouwd, dan klapt hij (in de aparte "vertrouwd"-sectie) weer dicht.</p>
    <div class="stap"><strong>"Vertrouw alle N"</strong> - staat boven de "Actie"-kolom, bovenaan zo'n samengevoegde rij. Vertrouwt in één keer alle bestanden uit die rij, in plaats van elk bestand los te moeten aanklikken (met een voortgangsteller tijdens het verwerken). De losse "Vertrouwen"-knop per bestand blijft daarnaast gewoon bestaan, voor het geval je binnen een samengevoegde rij toch een uitzondering wil maken.</div>
    <div class="tip">
        💡 Staat een bestand NIET in zo'n samengevoegde rij, maar los ertussen? Dan wijkt dat ene bestand af op een
        andere manier dan de rest van diezelfde extensie - dat verdient extra aandacht, want dat is precies het
        soort geïsoleerde afwijking die de moeite waard is om handmatig te controleren.
    </div>

    <h3>Kernbestand-integriteit tegen het officiële Joomla-pakket</h3>
    <p>Naast de meerderheidsvergelijking hierboven (die kijkt naar wat de MEESTE van je eigen sites hebben) staat er, als er iets gevonden is, een aparte sectie <strong>"🛡️ Kernbestanden vs. officieel Joomla-pakket"</strong> - een rechtstreekse vergelijking met het officiële, ongewijzigde Joomla-pakket van downloads.joomla.org. Dat pakket wordt altijd op de monitor zelf gedownload, nooit op een klantsite, en maar één keer per daadwerkelijk voorkomende Joomla-kernversie.</p>
    <div class="tip">
        💡 Deze vergelijking dekt twee gevallen waar de meerderheidsvergelijking hierboven niets kan zeggen: een
        Joomla-kernversie die maar op één van je sites voorkomt (geen andere site om tegen af te zetten), en een
        kernbestand dat toevallig op ál je sites identiek is aangepast (dan "wint" die afwijkende versie gewoon de
        meerderheid). Beide vergelijkingen kunnen hetzelfde bestand dus onafhankelijk van elkaar signaleren.
    </div>
    <p>Klik bij een gevonden afwijking op <strong>"🔍 Bekijk verschil"</strong> voor een aparte pagina die automatisch zowel het actuele bestand van de site als het officiële bestand ophaalt, het verschil regel voor regel toont (rood = toegevoegd op de site, groen doorgestreept = verwijderd), en daarbij een automatisch, leesbaar oordeel geeft op basis van bekende verdachte patronen (<code>eval()</code>, <code>base64_decode()</code>, shell-/procesuitvoeringsfuncties, stream-wrapper-trucs, <code>chr()</code>-obfuscatie).</p>
    <div class="tip">
        💡 Dit automatische oordeel is een hulpmiddel, geen garantie. Het geeft alleen een concrete waarschuwing bij
        een treffer - "geen bekend patroon gevonden" betekent nooit automatisch "veilig". Controleer een bevinding
        bij twijfel altijd zelf, of laat 'm bevestigen.
    </div>
    <p>Op die pagina zijn na handmatige beoordeling twee acties mogelijk:</p>
    <div class="stap"><strong>✅ Vertrouwen (negeren)</strong> - de afwijking verdwijnt uit het actieve overzicht, maar blijft zichtbaar (en aanklikbaar) onder "Toon ook vertrouwde items". Verandert het bestand later opnieuw, dan verschijnt het vanzelf weer als actieve afwijking - vertrouwen geldt alleen voor precies die inhoud, niet voor het bestandspad in het algemeen.</div>
    <div class="stap"><strong>🔧 Automatisch vervangen door origineel</strong> - schrijft het officiële bestand terug naar de site, na eerst een herstelbare backup te hebben gemaakt in dezelfde beheersectie (quarantaine/geblokkeerd/prullenbak) die verderop op deze pagina wordt beschreven. Die backup staat daar herkenbaar als "🛡️ Kernbestand-backup", en is op dezelfde manier ("↩️ Herstel") terug te zetten.</div>
    <p>De beveiligingskolom op de indexpagina toont het totaal per site: <strong>"⚠️ X kernbestand(en) wijken af van officieel pakket"</strong> voor actieve afwijkingen, en apart <strong>"✅ X afwijkend(e) kernbestand(en) vertrouwd"</strong> voor eerder vertrouwde.</p>

    <h3>Klantrapport (PDF)</h3>
    <p>Naast dit interactieve beveiligingsrapport (bedoeld als eigen werkscherm) staat er in de actiekolom en bovenaan het rapport zelf ook een knop <strong>"PDF"</strong>/"📄 Klantrapport openen" - dat opent een aparte, niet-technische pagina met alleen de samenvatting: gevonden bedreigingen, bestands-/maprechten, onbekende items, verouderde extensies en de Joomla-versie, zonder knoppen om iets te wijzigen. Geschikt om rechtstreeks door te sturen naar de eigenaar van de website, die daarmee zelf kan beoordelen of (en hoe) de site opgeschoond moet worden.</p>
    <p>Via de knop <strong>"🖨️ Opslaan als PDF / afdrukken"</strong> bovenaan die pagina open je het eigen printvoorbeeld van je browser - kies daar "Opslaan als PDF" als bestemming. Klik na het opslaan op <strong>"Annuleren"</strong> in dat printvoorbeeld om terug te keren naar de pagina zelf (niet op het kruisje van het browservenster, dat sluit de hele browser). Vertrouwde/al beoordeelde items staan hier bewust niet in - dat is al eerder als onschuldig beoordeeld en hoort niet in een rapport dat bedoeld is om iemand te laten opschonen.</p>
</section>

<section id="extensies">
    <h2>10. Het extensieoverzicht en de extensietabel gebruiken</h2>

    <h3>Extensieoverzicht (per site)</h3>
    <p>Klik in de kolom "Extensies" op de status van een site voor het volledige overzicht: alle gedetecteerde extensies van derden, met geïnstalleerde versie, nieuwste versie en status.</p>
    <p>Losse plugins/modules die duidelijk bij hetzelfde product horen (bijv. tientallen losse widget-plugins van één page builder) worden automatisch samengevoegd tot één rij, op basis van pakket-koppeling (<code>package_id</code>) en herkenning van gedeelde herkomst (map/element-naam + auteur, ongevoelig voor accentverschillen zoals "é" versus "e"). Zo'n rij toont dan bijv. "plugin (12x)" in de type-kolom.</p>
    <p>Bekende Joomla-kernonderdelen en vaste pakket-onderdelen die geen eigen update-feed nodig hebben (bijv. "Wie is online", "Aangepaste module", "Nieuwsflits", of losse onderdelen van Akeeba Backup/AcyMailing) worden automatisch buiten het extensieoverzicht gehouden - kom je toch nog een onderdeel tegen dat hier evident bij zou moeten horen, geef dat dan door.</p>
    <div class="tip">
        💡 Schrijft een ontwikkelaar het auteursveld per extensie inconsistent (bijv. de naam in wisselende
        volgorde)? Dan worden losse onderdelen soms niet automatisch aan hetzelfde hoofdproduct gekoppeld. Voor
        bevestigde gevallen kan dit via een vast, herkenbaar trefwoord alsnog geforceerd worden - meld dit gewoon
        als je zoiets tegenkomt.
    </div>
    <p>Bovenaan deze pagina staat ook een gele knop om alleen deze ene site opnieuw te scannen (zie hoofdstuk 5).</p>
    <p>Via <strong>"Toon ook genegeerde extensies"</strong> zie je ook de extensies die je (op deze of een andere site) hebt weggenegeerd, herkenbaar gemarkeerd - met daarbij een "Herstel"-knop in plaats van "Negeren". Bestaat een rij uit meerdere onderliggende onderdelen (bijv. een component + een losse plugin die samen zijn gegroepeerd), dan werken Negeren en Herstel altijd op <strong>alle</strong> onderdelen tegelijk - een rij verdwijnt dus pas echt als je op "Negeren" klikt, in plaats van dat er onzichtbaar nog een deel actief blijft staan.</p>

    <h3>Extensietabel beheren</h3>
    <p>Extensies zonder automatisch gevonden nieuwste versie (omdat Joomla zelf geen update-locatie voor die extensie kent) worden automatisch toegevoegd aan de catalogus. Ga naar "🧩 Extensietabel beheren" (via het extensieoverzicht) om zelf een update-feed-URL in te vullen - bijvoorbeeld gevonden via de Joomla Extensions Directory of de site van de ontwikkelaar zelf.</p>

    <h4>Update-feed-URL uit een lokaal installatiepakket halen</h4>
    <p>Heb je het installatiebestand (.zip) van een extensie op je eigen pc staan? Bovenaan "Extensietabel beheren" kun je dat bestand selecteren en op "🔍 Zoeken in pakket" drukken. De monitor leest dan het manifest binnen het pakket uit, op zoek naar de update-feed-URL die daar mogelijk in staat (het <code>&lt;updateservers&gt;</code>-blok - dezelfde soort URL die Joomla zelf ook zou gebruiken).</p>
    <p>Wordt de extensie herkend (op basis van dezelfde "sleutel"-systematiek als de rest van de catalogus), dan wordt het gevonden veld direct ingevuld en geel gemarkeerd - druk daarna nog wel zelf op "Opslaan" bij die rij. Staat de extensie er nog niet in, dan krijg je de gevonden URL als tekst te zien. In beide gevallen staat er een <strong>"📋 Kopieer URL"</strong>-knop bij, die de URL exact naar het klembord kopieert - handig om een tikfout te voorkomen (zoals een ontbrekende "l" bij ".xml") als je 'm ergens anders moet plakken. Het geüploade bestand wordt na het uitlezen direct van de server verwijderd.</p>
    <div class="tip">
        💡 Dit werkt alleen als de ontwikkelaar van de extensie de update-feed-URL daadwerkelijk in het pakket zelf
        heeft opgenomen. Sommige (met name commerciële) ontwikkelaars regelen updates via een eigen, gesloten
        systeem buiten deze Joomla-standaard om - in dat geval krijg je een duidelijke melding dat er niets
        gevonden is.
    </div>

    <p>Deze pagina toont drie tabellen:</p>
    <div class="stap"><strong>🌐 Gedeelde extensies met update-feed</strong> - altijd volledig zichtbaar voor alle sites samen. Vul je hier een update-feed-URL in, dan geldt die meteen voor élke site die dezelfde extensie heeft - dat hoef je dus maar één keer te doen.</div>
    <div class="stap"><strong>📄 Extensies zonder update-feed</strong> - kies bovenaan een specifieke website in de dropdown om alleen de (nog niet opgeloste) extensies van díe site te zien, in plaats van de gecombineerde lijst van alle sites.</div>
    <div class="stap"><strong>✅ Overige extensies (werken al automatisch)</strong> - extensies die via Joomla's eigen update-registratie al zonder onze catalogus een nieuwste versie hebben, en die daarom nooit in de andere twee tabellen verschijnen. Wil je zo'n extensie toch liever niet in het overzicht zien (bijv. een taalpakket waar je niet in geïnteresseerd bent), dan kun je 'm hier alsnog negeren.</div>
    <p>Per rij kun je:</p>
    <ul>
        <li><strong>Negeren</strong> - de rij verdwijnt volledig uit het overzicht en komt (in tegenstelling tot verwijderen) niet terug na een nieuwe scan. Via "Toon ook genegeerde extensies" kun je ze bekijken en eventueel herstellen - daarbij staat ook sinds wanneer een extensie genegeerd is.</li>
        <li><strong>Alleen x.xx.y negeren</strong> - minder ingrijpend dan gewoon negeren: de extensie blijft gewoon zichtbaar en de up-to-date-status wordt nog steeds bijgehouden, alleen het laatste, door een punt gescheiden onderdeel van het versienummer telt niet meer mee. Bedoeld voor extensies (met name taalbestanden) die een eigen, veelvuldig bijgewerkt build-nummer achter de eigenlijke versie plakken - bijv. Joomla-taalbestanden, waarvan de versie "6.1.2.1" er bij een kleine vertaalcorrectie al snel "6.1.2.3" van wordt, zonder dat dit een echte, nieuwe (Joomla-aangeboden) update betreft. Met deze knop tellen alleen de eerste drie onderdelen (hier: "6.1.2") mee bij het bepalen van "up-to-date" - een klik op dezelfde knop ("x.x.x.y weer tonen") maakt dit weer ongedaan.</li>
        <li><strong>🧹 Negeer alle libraries/taalbestanden</strong> - één knop bovenaan die in één keer alle gedeelde libraries en vertaalbestanden wegnegeert (die krijgen toch nooit een eigen update-feed).</li>
    </ul>
    <div class="tip">
        💡 Extensies negeren kan ook rechtstreeks vanaf het extensieoverzicht van een site zelf (hoofdstuk 9) - handig
        bij bijv. een eigengemaakte module die je meteen als "geen extensie van derden om te volgen" wil markeren,
        zonder eerst hierheen te hoeven navigeren. Let op: net als hier is dit een gedeelde instelling, die dus voor
        alle sites geldt die dezelfde extensie gebruiken.
    </div>
    <div class="tip">
        💡 Is de extensietabel na een tijdje toch weer rommelig geworden (bijv. na een grote codewijziging in de
        groeperingslogica)? Dan kan het opschonen en opnieuw laten opbouwen van de catalogus helpen - vraag hiervoor
        naar het opschoonscript.
    </div>

    <div class="tip">
        💡 Extensies die uit veel losse onderdelen bestaan (bijv. VirtueMart: één package plus tientallen eigen
        modules/plugins) worden samengevoegd tot één rij. Daarbij wordt altijd de <strong>hoogste</strong> "nieuwste
        versie" onder al die onderdelen getoond - niet zomaar die van het eerst-verwerkte onderdeel - en telt een
        overduidelijk kapotte versiestring (bijv. een vergeten build-variabele als <code>${PHING.VERSION}</code>, die
        sommige extensies soms per ongeluk meeleveren) niet mee. Ook taalbestanden krijgen een extra controle: een
        update-feed die al vooruitloopt op een Joomla-kernversie die zelf nog niet is uitgebracht, wordt niet als
        "beschikbare update" getoond.
    </div>

    <h3>Catalogus delen tussen meerdere installaties (Github)</h3>
    <p>Is bij Configuratie (hoofdstuk 1) een Github-repo ingesteld, dan controleert deze pagina bij het laden automatisch of daar nieuwe of gewijzigde update-feed-URL's staan ten opzichte van de lokale catalogus. Is dat zo, dan verschijnt bovenaan een gele melding met per item een aparte selectievakje - nieuwe items staan al aangevinkt, gewijzigde bewust niet (zodat een foutieve URL van een ander niet zomaar een eigen, werkende URL overschrijft). Kies wat je wilt overnemen en druk op "⬇️ Geselecteerde items importeren".</p>

    <h4>Een update-feed-URL bewust lokaal houden (niet delen via Github)</h4>
    <p>Is bij Configuratie een Github-token ingevuld, dan staan er bij het update-feed-veld <strong>twee</strong> opslaanknoppen in plaats van één:</p>
    <ul>
        <li><strong>Opslaan met GitHub Sync</strong> - de gewone, standaard keuze: de nieuwe/gewijzigde URL wordt meteen naar de gedeelde Github-catalogus gepusht, zodat andere installaties (bijv. van een collega) 'm via de melding hierboven kunnen overnemen.</li>
        <li><strong>Opslaan zonder GitHub Sync</strong> - voor een uitzondering die alleen voor déze installatie geldt. Typisch scenario: een extensie waarvan de reguliere update-feed voor de meeste mensen prima werkt, maar bij jouw specifieke hostingpartij structureel geblokkeerd wordt (bijv. het bekende Kunena/Strato-503-probleem). Vul in dat geval een alternatieve URL in - bijv. exact dezelfde, officiële feed-URL nogmaals, zodat de monitor zelf (vanaf een andere server, met een andere IP-reeks) 'm ophaalt in plaats van de geblokkeerde site zelf - en sla op zonder synchronisatie: die uitzondering hoeft immers niet voor andere installaties te gelden, waar de gewone weg allang werkt.</li>
    </ul>
    <p>Elke rij toont een badge die aangeeft hoe de huidige URL is opgeslagen: <span class="badge badge-groen">☁️ gedeeld via Github</span> of <span class="badge badge-oranje">💻 lokaal (niet op Github)</span>. Een bewust-lokale rij wordt gegarandeerd nooit meegenomen in een push naar Github (ook niet als die wordt getriggerd door het opslaan van een heel andere rij) en verschijnt ook nooit in de importmelding hierboven - je hoeft dus niet bang te zijn dat 'm per ongeluk weer wordt overschreven.</p>
    <p>Is er geen Github-token ingevuld bij Configuratie, dan zie je gewoon de simpele, originele "Opslaan"-knop - de keuze is dan toch niet relevant, want zonder token kan er sowieso niet naar Github geschreven worden.</p>
</section>

<section id="statussen">
    <h2>11. Wat betekenen de kleuren/statussen?</h2>
    <table>
        <tr><th>Status</th><th>Betekenis</th></tr>
        <tr><td><span class="badge badge-groen">Online</span></td><td>Website reageert normaal (HTTP 200).</td></tr>
        <tr><td><span class="badge badge-rood">Offline</span></td><td>Geen verbinding mogelijk, of een foutcode (403/500/etc.) - de HTTP-code staat erbij.</td></tr>
        <tr><td><span class="badge badge-oranje">Verdacht</span></td><td>Website reageert wel, maar de inhoud bevat verdachte woorden (mogelijk gehackt/spam).</td></tr>
        <tr><td><span class="badge badge-groen">Up-to-date</span></td><td>Alle extensies van derden zijn bevestigd up-to-date.</td></tr>
        <tr><td><span class="badge badge-grijs">Deels onbekend</span></td><td>Geen enkele bevestigd-verouderde extensie, maar ook niet alles bevestigd up-to-date (mix van up-to-date en onbekend).</td></tr>
        <tr><td><span class="badge badge-rood">Niet up-to-date</span></td><td>Er is minstens één extensie waarvan een nieuwere versie beschikbaar is dan wat er nu geïnstalleerd is.</td></tr>
        <tr><td><span class="badge badge-grijs">Onbekend</span></td><td>Er kon geen nieuwste versie worden bepaald (geen update-locatie bekend, of het ophalen ervan mislukte).</td></tr>
    </table>
    <p>Onder de status van de extensiekolom staat, als er iets te melden is, een korte uitsplitsing zoals <code>2 verouderd, 3 onbekend</code> - is alles in orde, dan blijft die tweede regel gewoon weg.</p>

    <h3>Sorteren op de meeste aandacht nodig</h3>
    <p>De kolomkoppen <strong>"Domein"</strong>, <strong>"Joomla"</strong>, <strong>"Extensies"</strong> en <strong>"Beveiliging"</strong> zijn klikbaar (herkenbaar aan het driehoekige pijltje, dat bij alle sorteerbare kolommen wordt getoond). Klik je erop, dan komen de sites die daar de meeste aandacht nodig hebben bovenaan te staan: bij "Beveiliging" de sites met de meeste (niet-vertrouwde) verdachte items eerst; bij "Extensies" eerst "Niet up-to-date" (hoe meer verouderde/onbekende extensies, hoe hoger), dan "Deels onbekend", dan "Onbekend", en "Up-to-date" onderaan. De actief gekozen sortering wordt geel gemarkeerd in de kolomkop, met een pijltje dat de huidige richting toont. Op mobiel staat dezelfde keuze in een keuzelijst, met een apart ⇅-knopje ernaast om de richting om te keren - met precies hetzelfde resultaat als op het grote scherm.</p>
    <div class="tip">
        💡 Klik je nogmaals op een kolomkop waarop al gesorteerd wordt, dan draait de volgorde volledig om (het
        pijltje wisselt van richting) - handig om bijvoorbeeld snel van "meeste aandacht eerst" naar "minste
        aandacht eerst" te wisselen. Klik je op een ándere kolomkop, dan start die altijd weer in de normale
        richting voor die kolom.
    </div>
    <p>"Joomla" sorteert in meerdere stappen na elkaar, zodat een oudere hoofdversie altijd voorrang krijgt boven de status daarbinnen: eerst op <strong>hoofdversie</strong> (bijv. Joomla 3.x altijd boven 5.x, ook als die 3.x-site toevallig zelf al de nieuwste 3.x is - "up-to-date binnen de eigen hoofdversie" zegt namelijk niets over hoe oud die hoofdversie zelf is); binnen dezelfde hoofdversie dan op status (verouderd 🔴 vóór onbekend vóór up-to-date ✅); en binnen dezelfde hoofdversie én status tot slot op het exacte versienummer (oudste eerst). Sites zonder Joomla-versiedata staan altijd onderaan.</p>

    <div class="tip">
        💡 Vóór elke domeinnaam staat het favicon van die website (met een klein icoontje van Joomla zelf als
        terugval, als er geen eigen favicon gevonden kon worden). Het favicon linkt naar de website zelf; de
        domeinnaam ernaast linkt naar het beheergedeelte (met een eventueel ingesteld geheim woord, zie hoofdstuk 3).
    </div>

    <h3>De icoontjes in de kolom "Actie"</h3>
    <table>
        <tr><th style="width: 60px;">Icoon</th><th>Betekenis</th></tr>
        <tr><td>↻</td><td>Alleen deze ene site opnieuw scannen (zie hoofdstuk 5).</td></tr>
        <tr><td>⚙️</td><td>Naar Site-instellingen van deze site.</td></tr>
        <tr><td>📋</td><td>Opent <code>scan-en-check-website.php</code> rechtstreeks in een nieuw tabblad - handig om snel de rauwe scanuitvoer van alleen deze site te zien, zonder naar Site-instellingen te hoeven.</td></tr>
        <tr><td><strong>FTP</strong></td><td>Alleen zichtbaar als er FTP-/SFTP-gegevens zijn ingevuld. Opent een <code>ftp://</code>-, <code>ftpes://</code>- (FTPS, als "waarvan beveiligd" is aangevinkt) of <code>sftp://</code>-link, zodat een lokaal geïnstalleerde FTP-client (bijv. FileZilla) - als je besturingssysteem dat protocol daaraan gekoppeld heeft - direct met de juiste site verbindt. Bevat de gebruikersnaam/het wachtwoord een teken dat een browser sowieso altijd codeert (zie de waarschuwing hieronder) of dat de linkstructuur zelf zou breken, dan verschijnt in plaats daarvan een 📋-variant: die kopieert het wachtwoord (en zo nodig ook de gebruikersnaam, in een apart kopieerbaar venstertje) naar het klembord en opent de link zonder die gegevens erin, zodat je ze alleen nog hoeft te plakken.</td></tr>
        <tr><td><strong>PDF</strong></td><td>Opent het klantrapport (zie hoofdstuk 9) - een niet-technische samenvatting om door te sturen naar de eigenaar van de website.</td></tr>
        <tr><td>🗑️</td><td>Site verwijderen (met bevestigingsvraag).</td></tr>
    </table>

    <div class="waarschuwing">
        ⚠️ <strong>Let op bij het kiezen/wijzigen van een FTP-/SFTP-wachtwoord: sommige tekens maken de FTP-knop
        onbetrouwbaar.</strong> Elke browser codeert bepaalde tekens in een link altijd automatisch, ongeacht wat de
        monitor zelf doet - en FileZilla zet die codering niet terug, wat een geweigerd wachtwoord (of zelfs
        helemaal geen reactie op de knop) tot gevolg heeft. Vermijd daarom in zowel de gebruikersnaam als het
        wachtwoord de volgende tekens: <code>" # &lt; &gt; ? ` { } / : ; = @ [ \ ] ^ |</code> en een los
        <code>%</code>-teken. Veilige speciale tekens (gerust te gebruiken) zijn bijvoorbeeld:
        <code>! $ & ' ( ) * + , - . ~</code>.
        <br><br>
        Bevat een wachtwoord of gebruikersnaam tóch zo'n teken, dan blijft de site gewoon te beheren: de knop
        kopieert het wachtwoord (en zo nodig ook de gebruikersnaam) dan automatisch naar je klembord en opent
        FileZilla zonder die gegevens in de link, zodat je ze alleen nog hoeft te plakken. Een gebruikersnaam met
        een "@" erin (bijv. een door de hostingpartij toegewezen "klantnummer@domein.nl"-login, vaak bij Strato)
        kan meestal niet worden aangepast - daar blijft deze kopieerstap dus altijd nodig, ongeacht het wachtwoord.
    </div>

    <div class="tip">
        💡 <strong>Werkt het FTP-icoontje niet vanzelf op Windows 11?</strong> Windows 11 laat je <code>ftp://</code>-links
        niet via het gewone menu "Standaard-apps" aan FileZilla koppelen - dat menu kent alleen een vaste,
        vooraf samengestelde lijst met protocollen, en <code>ftp</code> staat daar niet (meer) bij. Let op: alleen de
        betaalde versie <strong>FileZilla Pro</strong> registreert zichzelf automatisch als protocolhandler (te
        controleren/wijzigen via Instellingen → Apps → Standaard-apps → "Standaardwaarden kiezen per
        koppelingstype"). Gebruik je de gewone, gratis FileZilla Client, dan doet die dit standaard niet.
        <br><br>
        Download hieronder een kant-en-klaar registerbestand dat dit voor je regelt - gewoon dubbelklikken en
        bevestigen, geen handmatige registeraanpassing meer nodig:
        <br><br>
        <a class="knop" href="filezilla-als-standaard.reg" download style="background: #1f6fa8;">⬇️ Download filezilla-als-standaard.reg</a>
        <br><br>
        Dit bestand gaat uit van het standaard installatiepad (<code>C:\Program Files\FileZilla FTP Client\filezilla.exe</code>).
        Staat FileZilla ergens anders op jouw pc geïnstalleerd? Open het bestand dan eerst met Kladblok (rechtermuisknop
        → Bewerken, <strong>niet</strong> dubbelklikken) en pas dat pad op beide plekken aan naar waar
        <code>filezilla.exe</code> bij jou daadwerkelijk staat (rechtsklik op de FileZilla-snelkoppeling → Eigenschappen →
        "Doel" om dat op te zoeken), vóórdat je het bestand uitvoert.
        <br><br>
        Dit is een standaard Windows-mechanisme (hetzelfde principe als bijv. <code>mailto:</code>-links) - geen rare
        hack, maar wel een registeraanpassing: Windows vraagt bij het uitvoeren om een bevestiging, en je kan het
        altijd weer ongedaan maken via hetzelfde menu als bij WinSCP hieronder. Gebruik je een Mac, zie dan de aparte
        tip hieronder over Cyberduck.
    </div>

    <div class="tip">
        💡 <strong>WinSCP start bij jou telkens een installer/updater in plaats van gewoon te verbinden?</strong> Dat
        is een bekend, vervelend probleem bij bepaalde WinSCP-versies/instellingen, en geen fout van de monitor zelf
        - de gegenereerde <code>ftp://</code>/<code>sftp://</code>-link bevat namelijk niets anders dan de gewone
        inloggegevens. Gebruik in dat geval liever de FileZilla-oplossing hierboven, of controleer in WinSCP zelf
        onder Voorkeuren → Bijwerken of daar een automatische updatecontrole per ongeluk aanstaat die dit gedrag
        veroorzaakt.
    </div>

    <div class="tip">
        💡 <strong>Heb je het <code>.reg</code>-bestand hierboven uitgevoerd, staat de koppeling in Windows zelf ook kloppend
        (te checken via Instellingen → Apps → Standaard-apps), maar blijft je browser toch nog steeds naar WinSCP
        (of een ander, ouder programma) verwijzen?</strong> Dan ligt dat mogelijk niet aan Windows, maar aan de
        <strong>browser zelf</strong> - met name Firefox houdt voor <code>ftp://</code>/<code>sftp://</code>-links
        een eigen, losse voorkeur bij, volledig onafhankelijk van wat er in Windows is ingesteld. Los je het op in
        Windows, dan kan de browser toch nog naar de oude keuze blijven kijken. Controleer dit via: Firefox → drie
        streepjes rechtsboven → Instellingen → Algemeen → helemaal onderaan naar "Toepassingen" scrollen → zoek de
        regel voor <code>ftp</code> (en/of <code>sftp</code>) → wijzig die naar "Altijd vragen" of rechtstreeks naar
        FileZilla, als die daar als keuze verschijnt. Andere browsers (Chrome, Edge) hebben een vergelijkbare, eigen
        instelling onder hun eigen "Standaardtoepassingen"-achtige instellingenscherm.
    </div>

    <div class="tip">
        💡 <strong>Liever geen registeraanpassing?</strong> <a href="https://winscp.net/" target="_blank" rel="noopener">WinSCP</a>
        is een gratis, open-source alternatief voor Windows dat minstens zo makkelijk werkt als FileZilla, en zich
        - in tegenstelling tot de gratis FileZilla Client - vaak automatisch registreert als handler voor
        <code>ftp://</code>, <code>sftp://</code>, <code>ftps://</code> en <code>ftpes://</code>-links. Werkt het toch
        niet vanzelf, dan is dat zonder register in te stellen via WinSCP zelf: Voorkeuren → Integratie →
        "Registreren om URL-adressen af te handelen" → "Maak WinSCP standaardhandler". WinSCP is overigens
        Windows-only (geen macOS/Linux-versie, in tegenstelling tot FileZilla).
    </div>

    <div class="tip">
        💡 <strong>Op een Mac?</strong> <a href="https://cyberduck.io/" target="_blank" rel="noopener">Cyberduck</a> is
        een gratis, open-source FTP/SFTP-programma voor macOS (en Windows) dat minstens zo makkelijk werkt als
        FileZilla. Cyberduck controleert bij elke opstart zelf of het al is ingesteld als standaardhandler voor
        FTP/SFTP - is dat nog niet zo, dan verschijnt er gewoon een pop-upvenster met de vraag of je dat wil
        instellen. Eén keer op "ja" klikken volstaat dan al; geen Register of iets dergelijks nodig, want macOS
        regelt protocolkoppelingen sowieso op een andere manier dan Windows. Verschijnt die vraag niet (meer), dan
        kan het ook altijd handmatig via Cyberduck → Voorkeuren → FTP en → SFTP.
    </div>
</section>


<section id="backups-installatie">
    <h2>12. Back-ups, installatie- en updatepakketten</h2>
    <p>Onderaan de configuratiepagina staan drie groepen downloadknoppen:</p>

    <h3>Back-up maken</h3>
    <p>Twee losse downloads: alle PHP-broncode als .zip (inclusief <code>config.php</code> en <code>geheime_sleutel.php</code> - bewaar deze download dus net zo zorgvuldig als een wachtwoord), en een volledige database-back-up als .sql-bestand, rechtstreeks te importeren in bijv. phpMyAdmin bij een herstel.</p>

    <h3>Installatie- en updatepakket</h3>
    <p>Bovenaan staat het huidige versienummer, met een link naar het wijzigingslogboek (wat er per versie is veranderd).</p>
    <div class="stap"><strong>📦 Nieuw installatiepakket</strong> - voor het delen van de monitor met iemand anders (of een gloednieuwe eigen installatie). Bevat een installatiewizard die zelf de database inricht en een gebruikersnaam/wachtwoord + geheime sleutels aanmaakt - de ontvanger hoeft dus zelf geen SQL te importeren. Bevat geen persoonlijke gegevens, wel de extensies waarvan al een update-feed-URL bekend is.</div>
    <div class="stap"><strong>📦 Updatepakket</strong> - voor iemand die de monitor al gebruikt. Bevat alleen de broncode (zonder <code>config.php</code>/<code>geheime_sleutel.php</code> - die blijven bij de ontvanger ongemoeid staan). Na het uploaden werkt de database zichzelf automatisch bij zodra de eerste pagina geopend wordt - geen handmatige SQL-import nodig.</div>
    <div class="tip">
        💡 De installatiewizard stuurt tegenwoordig zelf door: bezoekt iemand de map/het domein vóórdat de installatie
        is voltooid, dan komt diegene automatisch op de wizard uit (in plaats van een kale foutmelding). De wizard
        vraagt ook expliciet te bevestigen dat <code>LEES_DIT_EERST.txt</code> is gelezen en er al een lege database
        klaarstaat, voordat de rest van het formulier bruikbaar wordt - en zet na een geslaagde installatie zichzelf
        desgewenst met één druk op de knop op non-actief.
    </div>
    <div class="tip">
        💡 Deze twee knoppen zijn bewust alleen bij jou zichtbaar: de bestanden die ze genereren, sluiten zichzelf uit
        van elk pakket dat ermee gemaakt wordt. Bij een ontvanger ontbreken die bestanden dus vanzelf, en verdwijnt
        deze hele sectie automatisch mee - alleen jij kan dus nieuwe installatie-/updatepakketten maken.
    </div>
</section>

<section id="problemen">
    <h2>13. Veelvoorkomende problemen</h2>

    <h3>Een site geeft plotseling 403 (offline) op alles</h3>
    <p>Vaak een beveiligingsplugin (bijv. Akeeba Admin Tools) die het IP-adres van de monitor tijdelijk blokkeert, omdat de scans er als verdacht verkeer uitzien. Zet het IP-adres van de monitor in de "Exceptions" van die plugin (zie het stappenplan bij "Site toevoegen").</p>

    <h3>Een beheeractie (Bekijk/Quarantaine/Blokkeer/Verwijder) geeft "HTTP 403"</h3>
    <p>Bevat de foutmelding de tekst <strong>"Request forbidden by administrative rules"</strong>? Dan blokkeert <strong>mod_security</strong> - een firewall die de hostingpartij zelf op serverniveau instelt - dit specifieke verzoek al vóórdat het bij Joomla of het scanscript aankomt. Dit is geen instelling die vanuit de monitor is te omzeilen; neem contact op met de hostingpartij en vraag om een uitzondering voor POST-verzoeken naar <code>scan-en-check-website.php</code>. Gebruik tot die tijd gewoon FTP voor deze ene site.</p>
    <p>Een andere HTTP-foutcode? De melding toont sinds versie 1.3 ook een fragment van de daadwerkelijk ontvangen inhoud - dat helpt vaak al om de oorzaak (een andere beveiligingsplugin, een verlopen sessie, een verouderd scanscript) te herkennen.</p>

    <h3>Een scan starten of een beheeractie geeft "Onverwacht antwoord (HTTP 301)"</h3>
    <p>Dit wijst op een omleiding op de site zelf (bijv. http naar https, of www naar non-www) - de monitor volgt zo'n omleiding automatisch, maar krijgt in dit geval alsnog de omleidingspagina zelf terug in plaats van het scanscript. Controleer of er een <code>.htaccess</code>-bestand in de hoofdmap van de site (of een daarboven liggende map) staat dat het verzoek ergens anders naartoe stuurt, en of dat de bedoeling is.</p>

    <h3>Extensieoverzicht blijft "Onbekend" tonen</h3>
    <p>Controleer of <code>scan-en-check-website.php</code> wel op de site staat en of de laatste scan is gelukt. Vul anders zelf een update-feed-URL in via "Extensietabel beheren".</p>

    <h3>Joomla-versie blijft leeg op de overzichtspagina</h3>
    <p>Dit is een ander mechanisme dan de extensies: de Joomla-kernversie wordt via het admin-pad opgehaald (zie hoofdstuk 3/4). Controleer bij Site-instellingen of het admin-pad correct is ingevuld.</p>

    <h3>Na het wijzigen van de geheime code werkt niets meer</h3>
    <p>Download een nieuwe versie van <code>scan-en-check-website.php</code> via Configuratie (of via de site-specifieke download/verzendknop bij Site-instellingen) en zet die op <strong>elke</strong> site opnieuw - de oude bestanden op de sites kennen de nieuwe code nog niet.</p>

    <h3>De cronjob geeft een 403-fout ("Niet toegestaan")</h3>
    <p>Controleer of het commando in DirectAdmin de actuele <strong>cron-beveiligingscode</strong> meestuurt als <code>?cron_code=...</code> (URL-aanroep) of <code>--cron_code=...</code> (PHP-CLI). Is de code ooit gewijzigd op de configuratiepagina, werk dan ook het commando in de cronjob bij.</p>

    <h3>Extensieoverzicht toont nog steeds veel losse rijen van hetzelfde product</h3>
    <p>Draai een nieuwe "Scan en check sites" - de groepering wordt bij elke scan opnieuw toegepast op de actuele gegevens. Blijft het onnodig gefragmenteerd, dan kan een opschoning van de catalogus (waarna alles opnieuw wordt opgebouwd) helpen.</p>

    <h3>SFTP-verbinding lukt niet, of "Class ... not found"</h3>
    <p>SFTP gebruikt een los meegeleverd stukje software (phpseclib) - controleer of de map <code>lib/phpseclib3</code> (en <code>lib/ParagonIE</code>) daadwerkelijk op de monitor staan. Ontbreekt die map, dan werkt alleen gewone FTP/FTPS totdat die alsnog is geüpload.</p>

    <h3>De monitor werkt niet meer goed op mijn telefoon</h3>
    <p>Alle pagina's zijn responsive: tabellen worden op een smal scherm automatisch kaartjes in plaats van kolommen. Blijft een pagina er raar uitzien, controleer dan of <code>responsive_stijlen.php</code> nog gewoon op de server staat.</p>
</section>

</div>

<?php include 'terug_naar_boven.php'; ?>
</body>
</html>
