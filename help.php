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

.hoofdstuk-knoppen {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    margin-bottom: 10px;
}
.knop.knop-licht {
    background: transparent;
    color: var(--thema-link);
    border: 1px solid var(--thema-rand);
    padding: 5px 10px;
    font-size: 13px;
    cursor: pointer;
}
.knop.knop-licht:hover {
    background: var(--thema-zebra);
}
details.hoofdstuk {
    border: 1px solid var(--thema-rand);
    border-radius: 6px;
    margin-bottom: 8px;
    overflow: hidden;
}
details.hoofdstuk > summary {
    list-style: none;
    cursor: pointer;
    /* Vast blauw (gelijk aan de kopkleur in donkere weergave), ook in lichte
       weergave - daar is de algemene kopkleur zwart. */
    background: #2c5f8a;
    color: #fff;
    padding: 10px 14px 10px 36px;
    position: relative;
    user-select: none;
}
details.hoofdstuk > summary::-webkit-details-marker {
    display: none;
}
details.hoofdstuk > summary::before {
    content: "\25B8";
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    transition: transform 0.15s;
}
details.hoofdstuk[open] > summary::before {
    transform: translateY(-50%) rotate(90deg);
}
details.hoofdstuk > summary:hover {
    filter: brightness(1.1);
}
details.hoofdstuk > summary h2 {
    display: inline;
    font-size: 17px;
    margin: 0;
    padding: 0;
    background: none;
    color: inherit;
}
.hoofdstuk-inhoud {
    padding: 4px 18px 16px 18px;
}
ol.stappenlijst {
    margin: 8px 0;
    padding-left: 24px;
}
ol.stappenlijst li {
    margin-bottom: 3px;
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

.inhoudsopgave {
    border: 1px solid var(--thema-rand);
    border-radius: 6px;
    padding: 10px 14px;
    margin-bottom: 12px;
}
.inhoudsopgave ol {
    margin: 6px 0 0 0;
    padding-left: 24px;
    columns: 2;
    column-gap: 30px;
}
@media (max-width: 700px) {
    .inhoudsopgave ol { columns: 1; }
}
h4 {
    font-size: 15px;
    margin: 14px 0 4px 0;
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

<div class="hoofdstuk-knoppen">
    <button type="button" class="knop knop-licht" onclick="zetAlleHoofdstukken(true)">Alles openklappen</button>
    <button type="button" class="knop knop-licht" onclick="zetAlleHoofdstukken(false)">Alles dichtklappen</button>
</div>

<nav class="inhoudsopgave">
    <strong>Inhoud</strong>
    <ol>
        <li><a href="#werking">Zo werkt de monitor</a></li>
        <li><a href="#configuratie">De configuratiepagina</a></li>
        <li><a href="#sites-toevoegen">Sites toevoegen</a></li>
        <li><a href="#uploaden">Het scanscript op de site</a></li>
        <li><a href="#admin-tools">Firewall-extensies (Akeeba Admin Tools)</a></li>
        <li><a href="#site-instellingen">Site-instellingen: extra scanpad en site verwijderen</a></li>
        <li><a href="#scannen">Scannen: één site, alle sites, cronjob</a></li>
        <li><a href="#email">E-mailmeldingen</a></li>
        <li><a href="#statussen">De monitorpagina</a></li>
        <li><a href="#beveiliging">Het beveiligingsrapport</a></li>
        <li><a href="#controles">Wat het scanscript controleert</a></li>
        <li><a href="#afwijkingen">Afwijkende bestanden en kernbestanden</a></li>
        <li><a href="#extensies">Extensieoverzicht en extensietabel</a></li>
        <li><a href="#hostingoverzicht">Het hostingoverzicht</a></li>
        <li><a href="#backups-installatie">Back-ups, installeren en bijwerken</a></li>
        <li><a href="#problemen">Veelvoorkomende problemen</a></li>
    </ol>
</nav>

<details class="hoofdstuk" id="werking">
    <summary><h2>1. Zo werkt de monitor</h2></summary>
    <div class="hoofdstuk-inhoud">
    <p>Op elke site staat een klein <strong>scanscript</strong> (een PHP-bestand in de root van de site, met een eigen, unieke naam). Bij een scan roept de monitor dat script aan. Het script doorzoekt de bestanden van de site (en het hostingaccount erboven) op verdachte code, inventariseert de geïnstalleerde extensies en zoekt per extensie de nieuwste versie op. Het resultaat stuurt het terug naar de monitor.</p>
    <p>Daarnaast controleert de monitor zelf per site of de website online is, hoe lang het SSL-certificaat nog geldig is en welke Joomla-versie er draait. Alles komt samen op de monitorpagina (hoofdstuk 9), met per site een beveiligingsrapport (hoofdstuk 10) en een extensieoverzicht (hoofdstuk 13).</p>
    <p>Kort de volgorde bij een nieuwe installatie:</p>
    <ol class="stappenlijst">
        <li>Configuratiepagina invullen (hoofdstuk 2).</li>
        <li>Sites toevoegen (hoofdstuk 3) en het scanscript op elke site zetten (hoofdstuk 4). Gebruikt een site een firewall-extensie, dan eerst hoofdstuk 5.</li>
        <li>Een eerste scan starten (hoofdstuk 7), en daarna een cronjob instellen zodat het vanzelf gaat.</li>
    </ol>
    </div>
</details>

<details class="hoofdstuk" id="configuratie">
    <summary><h2>2. De configuratiepagina</h2></summary>
    <div class="hoofdstuk-inhoud">
    <p>Via het ⚙️-tandwiel bovenin de monitorpagina. Daar staan twee tabbladen: <strong>Algemeen</strong> en <strong>Site toevoegen</strong> (hoofdstuk 3).</p>

    <h3>Blok "E-mailinstellingen"</h3>
    <p>De naam van de monitor, en wat er in de notificatiemail komt. Zie hoofdstuk 8.</p>

    <h3>Blok "Algemene instellingen"</h3>
    <table>
        <tr><th>Veld</th><th>Uitleg</th></tr>
        <tr><td>E-mailadres voor notificaties</td><td>Hierheen gaat de notificatiemail (hoofdstuk 8).</td></tr>
        <tr><td>Geheime code</td><td>Gedeeld wachtwoord tussen de monitor en de scanscripts, zodat niemand anders scanresultaten naar de monitor kan sturen of het scanscript kan aansturen. Staat verwerkt in het scanscript op elke site.</td></tr>
        <tr><td>Cron-beveiligingscode</td><td>Een <em>ander</em> wachtwoord, voor de achtergrondscripts (<code>check_sites.php</code>, <code>start_scan.php</code>, <code>haal_versies_op.php</code>, <code>cron_alles_scannen.php</code>). Die controleren geen login, want een cronjob heeft geen sessie. Zie hoofdstuk 7.</td></tr>
        <tr><td>Pad naar de monitorsite</td><td>De basis-URL van deze monitor, bijv. <code><?php echo htmlspecialchars($monitorUrlWeergave); ?></code> (zonder afsluitende slash). Gebruikt in e-mails en door het scanscript om te weten waar het de resultaten naartoe stuurt.</td></tr>
        <tr><td>Inlognaam / wachtwoord monitor</td><td>Waarmee je zelf inlogt. Het 👁️-oogje toont het huidige wachtwoord.</td></tr>
    </table>
    <p>Beide codes mogen alleen letters, cijfers, streepjes en underscores bevatten. Dat wordt bij het opslaan afgedwongen (een <code>%</code> zou bijvoorbeeld een crontab-regel breken).</p>
    <div class="waarschuwing">
        ⚠️ <strong>Wijzig je de geheime code of het pad naar de monitorsite, dan moet het scanscript opnieuw naar
        alle sites.</strong> De scripts op de sites gebruiken nog de oude waarden, en kunnen zich daarmee ook niet
        meer zelf bijwerken (de monitor weigert de oude code, of het oude pad bestaat niet meer). Na het opslaan
        verschijnt een knop om het script naar alle sites met FTP-gegevens te sturen; sites zonder FTP-gegevens
        zet je zelf opnieuw (hoofdstuk 4).
    </div>

    <h3>Blok "🖼️ Logo"</h3>
    <p>Vervangt het standaardlogo linksboven en op het inlogscherm. Eisen: <strong>.png, .jpg of .webp</strong>, (ongeveer) <strong>vierkant</strong>, tussen <strong>128×128 en 1024×1024 pixels</strong>, maximaal <strong>2 MB</strong>. Een vierkante PNG met doorzichtige achtergrond werkt het best; het logo wordt altijd op 48×48 pixels getoond.</p>
    <p>Bij het uploaden worden ook het favicon en de iconen voor "toevoegen aan beginscherm" (iOS, Android, PWA) uit hetzelfde logo gemaakt. "Terugzetten naar standaardlogo" draait alles in één keer terug.</p>
    <div class="tip">
        💡 Voor de favicons is de GD-bibliotheek van PHP nodig (bij vrijwel elke hostingpartij aanwezig). Ontbreekt
        die, dan werkt het logo linksboven wel, maar blijven favicon en installatie-icoon het standaardlogo. De
        monitor meldt dat na het uploaden.
    </div>

    <h3>Blok "Database-gegevens"</h3>
    <p>Alleen aanpassen als de monitor naar een andere database moet wijzen (bijv. na een verhuizing). Nieuwe gegevens worden eerst getest; lukt de verbinding niet, dan wordt er niets gewijzigd.</p>

    <h3>Blok "Site-scanscript"</h3>
    <p>Het scanscript voor één site download of verstuur je bij de Site-instellingen van die site (hoofdstuk 4). Dit blok bevat twee acties voor alle sites tegelijk:</p>
    <div class="stap"><strong>🚀 Verstuur scanscript via FTP naar alle sites met FTP-gegevens</strong> - zet bij elke site met FTP-/SFTP-gegevens een vers scanscript neer. Nodig na het wijzigen van de geheime code of het monitorpad; een nieuwe versie van het scanscript zelf haalt elke site vanzelf op (hoofdstuk 4).</div>
    <div class="stap"><strong>🔐 Migreer alle sites naar unieke scanscript-namen</strong> - alleen zichtbaar zolang er nog sites zijn met de oude, voor elke site gelijke standaardnaam. Zet die in één keer om naar een eigen naam.</div>

    <h3>Blok "🛡️ Admin Tools: informatie voor .htaccess-maker"</h3>
    <p>Toont alle sites waarbij bij de laatste scan een firewall-extensie met een .htaccess-maker is gevonden, met per site het favicon, een link naar de beheeromgeving en de exacte scanscript-bestandsnaam. Handig bij het stappenplan in hoofdstuk 5. Een site die nog nooit is gescand, staat hier nog niet tussen.</p>
    </div>
</details>

<details class="hoofdstuk" id="sites-toevoegen">
    <summary><h2>3. Sites toevoegen</h2></summary>
    <div class="hoofdstuk-inhoud">
    <p>⚙️ Configuratie → tabblad "Site toevoegen".</p>

    <h3>Domein</h3>
    <p>De domeinnaam zonder <code>https://</code> of <code>www.</code>; plak je dat er toch bij, dan wordt het verwijderd.</p>
    <div class="waarschuwing">
        ⚠️ <strong>Joomla hoort niet in een submap.</strong> Staan er meerdere Joomla-installaties op één
        hostingaccount, maak dan per installatie een subdomein aan en voeg dat subdomein als site toe. Lukt dat
        (nog) niet, dan kun je de submap achter het domein zetten (<code>domein.nl/submap</code>) als herkenbaar label;
        dat helpt "Zoek automatisch" de juiste map te vinden. Is de site ook via die submap-URL bereikbaar, vul dan
        daarnaast het veld "URL-submap" in (hieronder).
    </div>

    <h3>Admin-pad</h3>
    <p>Het pad naar de <code>administrator</code>-map, relatief aan het domein. Meestal gewoon <code>administrator</code>. Heeft de site een geheim woord in de inlog-URL (vaak via een beveiligingsextensie), vul dan het volledige pad in:</p>
    <table>
        <tr><th>Situatie</th><th>Wat je invult</th></tr>
        <tr><td>Geen geheim woord</td><td><code>administrator</code></td></tr>
        <tr><td>Geheim woord als queryparameter</td><td><code>administrator/?geheimwoord</code></td></tr>
        <tr><td>Geheim woord na index.php</td><td><code>administrator/index.php?geheimwoord</code></td></tr>
    </table>
    <p>Gebruikt voor de link naar de beheeromgeving (domeinnaam op de monitorpagina, "→ updates", "🔑 Inloggen als admin") en voor het ophalen van de Joomla-versie.</p>

    <h3>Categorie: eigen websites / websites van anderen</h3>
    <p>Elke site is een <strong>"Eigen website"</strong> of een <strong>"Website van een ander"</strong> (bijv. een site die je alleen scant, maar niet zelf bijhoudt). De keuze bepaalt:</p>
    <div class="stap"><strong>Wat je op de monitorpagina ziet</strong> - er zijn twee tabbladen, "🏠 Eigen websites" en "👤 Websites van anderen", en er wordt steeds één categorie getoond. "Scan en check sites" raakt alleen de zichtbare categorie.</div>
    <div class="stap"><strong>Wat er in de notificatiemail komt</strong> - standaard alleen eigen websites. Met de schakelaar "Ook websites van anderen (alleen beveiliging)" komen de andere sites er ook in, maar alleen met beveiligingsmeldingen (hoofdstuk 8).</div>
    <p>De cronjob scant altijd alle sites uit beide categorieën. "Terug naar monitor" op een sitepagina brengt je terug naar het tabblad van die site.</p>

    <h3>URL-submap</h3>
    <p>Meestal leeg. Alleen invullen als Joomla in een submap staat die via de domeinnaam bereikbaar is (de site is dan <code>https://domein.nl/submap/</code>): vul dan alleen de naam van die submap in. Gebruikt bij alles wat de monitor rechtstreeks bij de website ophaalt: een scan starten, het scanscript openen (📋), beheeracties, website-/SSL-status en Joomla-versie.</p>
    <div class="waarschuwing">
        ⚠️ Dit is iets anders dan het <strong>FTP-pad</strong>. Het FTP-pad bepaalt waar het scanscript op de schijf
        komt; de URL-submap bepaalt via welk webadres de monitor het daarna aanroept. Meestal komen ze overeen, maar
        niet altijd. Staat de URL-submap verkeerd, dan blijft de site op "nog niet gescand" staan en geeft het
        scanscript een "pagina niet gevonden".
    </div>

    <h3>Slimme opslaanknop</h3>
    <p>Vul je meteen de FTP-/SFTP-gegevens in, met een gevonden of zelf ingevuld pad (niet de kale <code>/</code>), dan wordt de knop <strong>"Opslaan en FTP-bestand versturen"</strong>: het scanscript wordt na het opslaan direct op de site gezet. Anders zet je het daarna zelf neer (hoofdstuk 4).</p>
    <div class="tip">
        💡 Gebruikt de nieuwe site Akeeba Admin Tools? Doe dan eerst het stappenplan in hoofdstuk 5. Bij "Site
        toevoegen" staat het ook, met de bestandsnaam van de nieuwe site al ingevuld.
    </div>
    </div>
</details>

<details class="hoofdstuk" id="uploaden">
    <summary><h2>4. Het scanscript op de site</h2></summary>
    <div class="hoofdstuk-inhoud">

    <h3 id="scanscript-naam">De bestandsnaam</h3>
    <p>Elke site krijgt bij het toevoegen een <strong>unieke, gegenereerde naam</strong>: de naam van de monitor met een willekeurige code erachter. Een voorspelbare naam die op elke site gelijk is, is voor een aanvaller makkelijker te vinden. De naam hoort bij de monitor, niet bij de site: zo is ook te zien welke monitor een bestand heeft neergezet als een site door meerdere monitors wordt gevolgd.</p>
    <p>Zelf een naam kiezen kan niet. Bij Site-instellingen maakt <strong>"🔄 Vervang door nieuwe, unieke naam"</strong> een nieuwe naam, zet het script onder die naam op de site (via FTP/SFTP) en ruimt het oude bestand op.</p>
    <div class="waarschuwing">
        ⚠️ Een firewall-extensie met een uitzondering op de bestandsnaam (hoofdstuk 5) moet na elke naamswijziging
        opnieuw worden ingesteld, anders blokkeert die het script alsnog. Dat geldt ook na de migratieknop op de
        configuratiepagina, en na het wijzigen van de monitornaam als je daarna de bestandsnamen bijwerkt.
    </div>

    <h3>Handmatig plaatsen</h3>
    <div class="stap"><strong>Stap 1.</strong> Open Site-instellingen van de site en klik op de downloadknop onderaan (de unieke naam staat er al in).</div>
    <div class="stap"><strong>Stap 2.</strong> Zet het bestand met een FTP-programma, zonder de naam te wijzigen, in de <strong>root</strong> van de site (de map met <code>configuration.php</code>).</div>

    <h3>Automatisch via FTP of SFTP</h3>
    <p>Bovenaan Site-instellingen kies je <strong>FTP</strong> (met optioneel "waarvan beveiligd" voor FTPS) of <strong>SFTP</strong> (via SSH, meestal poort 22). Zijn de gegevens ingevuld, dan staat onderaan <strong>"🚀 Verstuur nu"</strong> in plaats van de downloadknop.</p>
    <div class="tip">
        💡 Weet je het pad op de server niet zeker? Vul server, gebruikersnaam en wachtwoord in en klik op
        <strong>"🔍 Zoek automatisch"</strong>: de monitor zoekt zelf de map met <code>configuration.php</code> van dit
        domein en vult het pad in. Staat daar geen Joomla (meer), dan wordt de webroot van het domein ingevuld
        (bijv. <code>domains/voorbeeld.nl/public_html</code>), met een waarschuwing. Er wordt nooit uitgeweken naar de
        map van een ander domein op hetzelfde account.
    </div>

    <h3 id="scanscript-zonder-ftp">Het scanscript werkt zichzelf bij</h3>
    <p>Bij elke scan vraagt het scanscript via een gewoon HTTPS-verzoek aan de monitor of er een nieuwere versie van zichzelf is, en vervangt zichzelf als dat zo is. Een update van de monitor bereikt de sites dus vanzelf bij de volgende scan, ook als FTP vanaf de server van de monitor niet werkt. In de scanuitvoer (📋) staat dit onder "=== ZELF-BIJWERKEN ===".</p>
    <div class="tip">
        💡 Twee keer is een bestandsoverdracht wél nodig: de allereerste keer dat het script op een site komt, en na
        het wijzigen van de geheime code of het pad naar de monitor (hoofdstuk 2). Werkt FTP vanaf de monitor niet,
        download het script dan en zet het met je eigen FTP-programma neer - dat gebeurt vanaf je eigen computer en
        heeft geen last van een blokkade op de server van de monitor.
    </div>
    <div class="waarschuwing">
        ⚠️ Het bijwerken gebruikt dezelfde geheime code die ook de scanresultaten beveiligt. Wie die code kent, kan
        in principe nepresultaten insturen en het scanscript aansturen. Bewaar hem als een wachtwoord.
    </div>

    <h3>FTP lukt niet: verkeerd IP-adres of geblokkeerde poorten</h3>
    <p>Sommige servers geven bij een FTP-verbinding een verkeerd of onbereikbaar IP-adres terug voor de bestandsoverdracht (passieve modus). FileZilla corrigeert dat ongemerkt; de monitor ook. Mislukt de gewone passieve methode, dan volgen vanzelf twee terugvalpogingen: via curl met het IP-adres van de server genegeerd, en als laatste via <strong>actieve</strong> modus. Dat geldt voor zowel het versturen als "Zoek automatisch".</p>
    <p>Blokkeert de hostingpartij van de <strong>monitor</strong> uitgaand verkeer naar hoge poorten, dan kan ook dat mislukken. Een veel gehoorde melding is dan "Kon geen FTP-verbinding maken" of "Connection timed out". Plaats het script dan handmatig.</p>

    <h3>FTP-upload mislukt door maprechten</h3>
    <p>Mislukt het versturen, dan kijkt de monitor of de doelmap het <strong>uitvoerrecht</strong> voor de eigenaar mist (bijv. 655 in plaats van 755). Zonder dat recht kun je niets in de map schrijven. Je krijgt dan een melding met de knop <strong>"🔧 Probeer maprechten automatisch te herstellen (naar 755)"</strong>; zonder die klik wordt er nooit aan rechten gesleuteld. Staat de server "SITE CHMOD" niet toe, pas de rechten dan zelf aan in je FTP-programma.</p>
    </div>
</details>

<details class="hoofdstuk" id="firewall">
    <summary><h2>5. Firewall-extensies (Akeeba Admin Tools)</h2></summary>
    <div class="hoofdstuk-inhoud">
    <h3 id="admin-tools">Akeeba Admin Tools (of een vergelijkbare firewall)</h3>
    <p>Admin Tools blokkeert standaard zowel het IP-adres van de monitor (de scans lijken op verdacht verkeer) als het rechtstreeks aanroepen van losse PHP-bestanden, zoals het scanscript. Zonder de aanpassingen hieronder krijg je bij het scannen een 403, of staat de site na een paar scans als offline in de monitor.</p>
    <p>Bij "Site toevoegen" staat dit stappenplan ook (knop "Klik hier als je Akeeba Admin Tools gebruikt"), met de bestandsnaam voor de nieuwe site al ingevuld. Voor een bestaande site staat de naam bij Site-instellingen onder "Scanscript-bestandsnaam", of voor alle sites tegelijk in het 🛡️-blok op de configuratiepagina.</p>
    <ol class="stappenlijst">
        <li>Zoek het IPv4- en IPv6-adres op van de monitorwebsite.</li>
        <li>Ga in Admin Tools naar <strong>Web Application Firewall</strong> en open <strong>Configure WAF</strong>.</li>
        <li>Open het tabblad <strong>Exceptions</strong>.</li>
        <li>Maak met het groene plus-teken een nieuwe regel met het IP-adres, met als omschrijving bijvoorbeeld <code>Website Monitor</code>. Doe dit voor het IPv4- én het IPv6-adres.</li>
        <li>Ga naar de Admin Tools <strong>.htaccess maker</strong> en scroll naar <strong>Server protection</strong>.</li>
        <li><strong>Backend directories where file type exceptions are allowed</strong> - maak een nieuwe regel voor <code>administrator</code> met daarachter <code>manifests</code>.</li>
        <li>Daaronder, bij <strong>Backend file types allowed in selected directories</strong>: typ <code>xml</code> en druk op Enter.</li>
        <li>Scroll naar <strong>Exceptions from Server Protection</strong> → <strong>Allow direct access to these files</strong>, maak een nieuwe regel en zet daarin de scanscript-bestandsnaam van deze site (bijv. <code>scan-door-mijnmonitor-a3f9c2.php</code>).</li>
        <li>Sla op met <strong>"Save and Create .htaccess"</strong> linksboven.</li>
    </ol>
    <div class="waarschuwing">
        ⚠️ <strong>Vervang je de scanscript-naam later</strong>, zet dan bij stap 8 de nieuwe naam in plaats van de
        oude en sla opnieuw op (stap 9).
    </div>
    <div class="tip">
        💡 <strong>Controleren:</strong> open het scanscript via 📋 op de monitorpagina. Krijg je een 403-pagina van
        Admin Tools in plaats van scanuitvoer, controleer dan stap 8 (exacte bestandsnaam) en stap 4 (IP-adres). In
        Admin Tools staat onder <strong>Web Application Firewall → Blocked Requests Log</strong> waarom een verzoek is
        geblokkeerd.
    </div>
    </div>
</details>

<details class="hoofdstuk" id="site-instellingen">
    <summary><h2>6. Site-instellingen: extra scanpad en site verwijderen</h2></summary>
    <div class="hoofdstuk-inhoud">
    <p>Via ⚙️ in de actiekolom van een site. Hier staan de FTP-/SFTP-gegevens en de scanscript-naam (hoofdstuk 4), de URL-submap (hoofdstuk 3), en de instellingen hieronder.</p>

    <h3>Extra scanpad: boven de website</h3>
    <p>Kwaadaardige bestanden staan niet alleen in de website, maar ook ernaast en erboven in het hostingaccount. Het scanscript kijkt daarom altijd mee tot de root van het account. Hoe ver dat is, bepaalt het bij elke scan zelf aan de hand van het <strong>eigenaarschap</strong> van de mappen: zolang een map dezelfde eigenaar heeft als de website, hoort hij bij het account en wordt er een niveau hoger gekeken. Dat werkt bij elke hostingpartij. Na een scan staat bij Site-instellingen wat er gevonden is, bijv. <code>📍 Bij de laatste scan gedetecteerd: 3 niveau(s) boven de website-root: /home/gebruikersnaam</code>.</p>
    <p>Automatisch overgeslagen: systeemmappen van de hostingpartij (bijv. <code>Maildir</code>, <code>.softaculous</code>, <code>.spamassassin</code>, <code>.trash</code>, <code>.well-known</code>), <code>domains</code>/<code>public_html</code> zelf, andere sites in <code>domains</code>, andere Joomla-installaties naast de site (een map met eigen <code>configuration.php</code> en <code>administrator</code>-map), mappen die niet van het account zijn én waarin het account niet kan schrijven, en een map als <code>private_html</code> die naar dezelfde bestanden wijst als de website (herkend aan het echte pad, niet de naam). Welke andere installaties zijn overgeslagen, staat in de scanuitvoer bij "Tussenniveau".</p>
    <div class="tip">
        💡 <strong>Wél meegescand: <code>.cagefs</code> en <code>.cl.selector</code></strong> (CloudLinux). Daar zijn in
        de praktijk backdoors gevonden. De inhoud wordt gewoon doorzocht; alleen de rechtencontrole wordt er
        overgeslagen (CloudLinux beheert die zelf), net als de kopieën van alle PHP-versies in
        <code>.cagefs/opt/alt/</code>, die niet van het account zijn. In <code>.cagefs/tmp</code>, de tijdelijke map van
        PHP, wordt extra streng gekeken: een PHP-bestand, een map, of een verborgen bestand van 65 bytes of meer
        wordt daar altijd gemeld, en een map met de naam <code>alfacgiapi</code>, <code>ALFA_DATA</code> of
        <code>alfasymlink</code> (de ALFA-webshell) overal buiten de website als KRITIEK.
    </div>
    <p>Binnen het extra scanpad wordt, naast de gewone controle op verdachte code, ook gemeld:</p>
    <ul>
        <li><strong>Onbekende items</strong> op het bovenste niveau, met het volledige pad als naam, zodat duidelijk is dat ze van buiten de website komen.</li>
        <li><strong>Afwijkende rechten</strong>: een map die niet op 755 staat of een bestand dat niet op 644 staat, te ruim én te krap. Alleen hier, niet in de website zelf, waar afwijkende rechten vaak bewust zijn (bijv. <code>configuration.php</code> op 440).</li>
        <li><strong>Uitvoerbare bestanden</strong>: een bestand met een x-bit in de rechten of de kop van een Linux-programma (ELF) is KRITIEK. Bestanden van een andere eigenaar waarin het account niet kan schrijven tellen niet mee, en het AWStats-script van de host wordt herkend.</li>
        <li><strong>Crontab en opstartbestanden</strong>: de crontab van het account en <code>.bashrc</code>, <code>.bash_profile</code> en <code>.profile</code> worden doorzocht op bekende backdoorsignaturen (bijv. gsocket). Elke treffer is KRITIEK; de scanuitvoer toont de actieve crontab-regels.</li>
    </ul>
    <p>Bij een vondst hier werken <strong>"👁️ Bekijk"</strong> en <strong>"🔧 Rechten herstellen"</strong>. Quarantaine, Blokkeer en Verwijder zijn buiten de website bewust uitgeschakeld (gebruik daar FTP): anders zou een handig gekozen pad bestanden elders op de server kunnen raken.</p>
    <div class="tip">
        💡 Gebruikt de hostingpartij <code>open_basedir</code>, dan kan PHP niet boven de website kijken. De
        scanuitvoer meldt dat netjes ("niet bereikbaar"/"niet leesbaar"); de rest van de scan loopt gewoon door.
    </div>

    <h3 id="scanpad-overslaan">Nog extra (sub)mapnamen overslaan</h3>
    <p>Mapnamen, kommagescheiden, die het extra scanpad bij déze site niet hoeft te doorzoeken, bijv. een grote eigen back-upmap naast de website. Geldt voor mappen in de accountroot en op de niveaus tussen accountroot en website, niet voor mappen in de website zelf. Wat hierboven al automatisch wordt overgeslagen, hoef je hier niet in te vullen.</p>

    <h3 id="root-negeer-patronen">Onbekende items in de website-root niet melden</h3>
    <p>Voor bestanden of mappen in de website-root die je kent en die steeds veranderen, zodat vertrouwen niet helpt - bijv. een sitemapgenerator die elke nacht nieuwe <code>sitemap-*.xml</code>-bestanden schrijft. Kommagescheiden patronen: <code>*</code> is willekeurige tekens, <code>?</code> één teken, hoofdletters maken niet uit. Voorbeeld: <code>sitemap*.xml, catalogi</code>.</p>
    <div class="tip">
        💡 Dit verbergt alleen de melding "onbekend item", alleen voor namen direct in de website-root. De inhoud van
        zo'n map wordt gewoon doorzocht, en een PHP-bestand (of ander uitvoerbaar bestand) wordt altijd gemeld. Het
        scanscript neemt de wijziging bij de volgende scan over; de scanuitvoer meldt hoeveel items zo niet zijn gemeld.
    </div>

    <h3>Site verwijderen</h3>
    <p>Via 🗑️ achteraan de siterij op de monitorpagina. Na een bevestiging:</p>
    <div class="stap"><strong>1.</strong> Alle gegevens van de site worden uit de monitor verwijderd: de site, gescande extensies, bestandshashes, vertrouwde items en de naamgeschiedenis van het scanscript.</div>
    <div class="stap"><strong>2.</strong> Zijn er FTP-/SFTP-gegevens, dan probeert de monitor het scanscript van de site te verwijderen - onder <em>alle</em> namen die het daar ooit had.</div>
    <div class="stap"><strong>3.</strong> Ook de map <code>_scan_beheer</code> op de site (quarantaine, geblokkeerd, prullenbak en het inhoudsgeheugen van het scanscript) wordt geprobeerd op te ruimen.</div>
    <p>Daarna meldt de monitor wat er op de site gelukt is. Wat niet lukte, blijft op de site staan; verwijder dat zelf via FTP. De opruiming in de monitor gaat altijd door.</p>
    </div>
</details>

<details class="hoofdstuk" id="scannen">
    <summary><h2>7. Scannen: één site, alle sites, cronjob</h2></summary>
    <div class="hoofdstuk-inhoud">

    <h3 id="een-site-herscannen">Eén site opnieuw scannen</h3>
    <p>Het gele ↻-icoontje staat vooraan de siterij op de monitorpagina, en bovenaan het extensieoverzicht en het beveiligingsrapport van die site. Het doorloopt dezelfde stappen als "Scan en check sites", maar alleen voor deze site.</p>
    <p>In het beveiligingsrapport wacht de monitor tot het nieuwe resultaat echt binnen is (maximaal 90 seconden), met een voortgangsbalk; de pagina ververst zichzelf. Komt er niets binnen, dan volgt een oranje waarschuwing. Vanaf de monitorpagina en het extensieoverzicht geldt een vaste wachttijd van 10 seconden.</p>
    <div class="tip">
        💡 Blokkeert een <code>.htaccess</code> in de root van de site het scanverzoek zelf (bijv. een kwaadaardige
        "deny from all"), dan toont de monitor een oranje waarschuwing. Controleer de <code>.htaccess</code>-bestanden
        dan via FTP.
    </div>

    <h3 id="handmatige-scan">Alle sites scannen</h3>
    <p>Druk op de monitorpagina op <strong>"🛡️🔍 Scan en check sites"</strong>. Dit geldt voor de sites in het zichtbare tabblad. Een voortgangsbalk toont de vijf stappen:</p>
    <div class="stap">1. <strong>Scan starten op alle sites</strong> - parallel. Vlak voor de scan wordt de <code>tmp</code>-map van de site geleegd (daar komen vaak kortstondige, verdachte bestanden terecht); dat gebeurt bij elke scan. Staan meerdere sites op hetzelfde hostingaccount, dan scant het scanscript ze na elkaar: een scan wacht maximaal 90 seconden op een andere scan op hetzelfde account (de scanuitvoer meldt hoe lang). Zo lopen ze niet allebei tegen de maximale scantijd aan.</div>
    <div class="stap">2. <strong>Wachten</strong> (20 seconden, zichtbaar aftellend), zodat de sites hun resultaat kunnen terugsturen.</div>
    <div class="stap">3. <strong>Website- en SSL-status, Joomla- en extensieversies</strong> ophalen, plus het favicon van elke site.</div>
    <div class="stap">4. <strong>Bestanden vergelijken</strong> tussen sites en met het officiële Joomla-pakket (hoofdstuk 12).</div>
    <div class="stap">5. <strong>Notificatiemail</strong> versturen als er iets te melden is (hoofdstuk 8).</div>
    <p>Daarna ververst de pagina. Kon het scanscript bij een site niet gestart worden, dan staat dat bij die site in de kolom Beveiliging (hoofdstuk 9).</p>

    <h3 id="cronjob">Automatisch met een cronjob</h3>
    <p>Laat een cronjob (bijv. in DirectAdmin) <code>cron_alles_scannen.php</code> aanroepen, bijvoorbeeld eens per dag. Dit doorloopt dezelfde stappen als de knop, voor <strong>alle</strong> sites, zonder browser. Het bestand staat in <code><?php echo htmlspecialchars(__DIR__); ?>/</code>.</p>
    <div class="waarschuwing">
        ⚠️ Een cronjob heeft geen sessie, dus deze scripts controleren geen login. In plaats daarvan moet de
        <strong>cron-beveiligingscode</strong> (Configuratie → Algemeen) worden meegegeven, anders wordt de aanroep
        geweigerd. De commando's hieronder bevatten de huidige code al.
    </div>
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
    <p>Eens per dag: minuut <code>0</code>, uur bijv. <code>3</code>, dag/maand/weekdag <code>*</code> (elke nacht om 03:00).</p>
    </div>
</details>

<details class="hoofdstuk" id="email">
    <summary><h2>8. E-mailmeldingen</h2></summary>
    <div class="hoofdstuk-inhoud">
    <p>Configuratie → Algemeen → blok "E-mailinstellingen". Er wordt alleen een mail verstuurd als er iets te melden is: géén mail betekent "alles in orde" voor de aangevinkte onderdelen.</p>

    <h3>Naam van de monitor</h3>
    <p>Het veld <strong>"Naam van de monitor / afzendernaam voor e-mail"</strong> wordt gebruikt als afzendernaam, als titel van de monitor (linksboven en in het browsertabblad) en als voorvoegsel in de bestandsnaam van nieuwe scanscripts. Leeg laten geeft "Mijn Websites Monitor".</p>
    <div class="tip">
        💡 Verandert de bestandsnaam door een nieuwe monitornaam, dan verschijnt na het opslaan een <strong>optionele</strong>
        knop om de scanscripts van alle sites te hernoemen. Niet verplicht: de bestaande scripts blijven gewoon werken.
        Hernoem je wel, pas dan de uitzondering in een firewall-extensie aan (hoofdstuk 5).
    </div>

    <h3>Wat er in de mail komt</h3>
    <table>
        <tr><th>Onderdeel</th><th>Meldt wanneer</th><th>Voorbeeld in de mail</th></tr>
        <tr><td>Website status</td><td>Bij de aangevinkte criteria: geen verbinding, HTTP-foutcode, of verdachte inhoud.</td><td><code>Website: 🔴 Offline (HTTP 500)</code></td></tr>
        <tr><td>Joomla-versie</td><td>Er is een nieuwere versie beschikbaar.</td><td><code>Joomla: geïnstalleerde versie → nieuwste versie beschikbaar</code></td></tr>
        <tr><td>Extensies</td><td>Eén of meer extensies niet up-to-date (met een link naar de updatepagina van de site).</td><td><code>Extensies - Niet up-to-date: 3</code></td></tr>
        <tr><td>SSL-status</td><td>Alleen als het certificaat echt verlopen is.</td><td><code>SSL: certificaat verlopen</code></td></tr>
        <tr><td>Beveiliging</td><td>Niet-vertrouwde verdachte items, een onvolledige scan, of een scan die niet gestart kon worden.</td><td><code>Beveiliging - Verdachte bestand(en): 2</code></td></tr>
    </table>
    <div class="stap"><strong>Ook websites van anderen (alleen beveiliging)</strong> - standaard tellen alleen eigen websites mee. Met deze schakelaar komen ook de sites van anderen in de mail, maar alleen met beveiligingsmeldingen. Werkt alleen als "Beveiliging" aanstaat.</div>
    <div class="stap"><strong>Alleen e-mail versturen bij een cronjob</strong> - een handmatige "Scan en check sites" stuurt dan nooit een mail (je ziet het resultaat al op het scherm).</div>
    <p>De mail is een HTML-mail: bij elke site staan het favicon (link naar de website) en de domeinnaam (link naar de beheeromgeving).</p>
    </div>
</details>

<details class="hoofdstuk" id="statussen">
    <summary><h2>9. De monitorpagina</h2></summary>
    <div class="hoofdstuk-inhoud">
    <p>Bovenaan staan de tabbladen per categorie (hoofdstuk 3) en een samenvattingsbalk met het aantal sites dat aandacht nodig heeft. Daarna één rij per site. Voor elke domeinnaam staat het favicon van de site (link naar de website); de domeinnaam zelf linkt naar de beheeromgeving, met het eventuele geheime woord uit het admin-pad.</p>

    <h3>Kolommen en statussen</h3>
    <table>
        <tr><th>Kolom</th><th>Betekenis</th></tr>
        <tr><td>Website</td><td><span class="badge badge-groen">Online</span> de site reageert normaal (HTTP 200). <span class="badge badge-rood">Offline</span> geen verbinding of een foutcode (die staat erbij). <span class="badge badge-oranje">Verdacht</span> de site reageert, maar de inhoud bevat verdachte woorden (mogelijk gehackt of spam).</td></tr>
        <tr><td>Joomla</td><td>De geïnstalleerde versie, opgehaald via het admin-pad. Rood als er binnen dezelfde hoofdversie een nieuwere versie is.</td></tr>
        <tr><td>PHP</td><td>Zie hieronder.</td></tr>
        <tr><td>SSL status</td><td>Aantal dagen tot het certificaat verloopt; oranje/rood als dat weinig wordt.</td></tr>
        <tr><td>Extensies</td><td><span class="badge badge-groen">Up-to-date</span> alle extensies van derden bevestigd bij. <span class="badge badge-rood">Niet up-to-date</span> minstens één nieuwere versie beschikbaar; de link <strong>"→ updates"</strong> erachter opent meteen de updatepagina in de beheeromgeving van de site. <span class="badge badge-grijs">Deels onbekend</span> niets verouderd, maar ook niet alles bevestigd. <span class="badge badge-grijs">Onbekend</span> geen nieuwste versie te bepalen. <span class="badge badge-grijs">Geen Joomla</span> op de plek van de site staat geen Joomla (meer), bijv. na omzetten naar een statische pagina; de beveiligingsscan loopt gewoon door. Is er iets te melden, dan staat eronder een uitsplitsing als <code>2 verouderd, 3 onbekend</code>.</td></tr>
        <tr><td>Beveiliging</td><td>Het aantal niet-vertrouwde verdachte items van de laatste scan; "🟢 Schoon" als er niets openstaat (pas na een volledige scan). <strong>"⏳ Scan afgerond voor N%"</strong>: de scan was niet volledig (hoofdstuk 16). <strong>"⛔ Scan niet gestart (HTTP 403) sinds ..."</strong>: bij de laatste poging kon het scanscript niet gestart worden; wat eronder staat is van een eerdere scan. Beweeg met de muis erover voor de precieze reden.</td></tr>
        <tr><td>🗂️</td><td>Bestandsafwijkingen: extensiebestanden die afwijken van je andere sites (hoofdstuk 12). Een zachter signaal dan Beveiliging, daarom oranje.</td></tr>
    </table>

    <h3>Sorteren</h3>
    <p>De kolomkoppen met een driehoekje zijn klikbaar en zetten de sites die de meeste aandacht nodig hebben bovenaan. Nog een keer klikken draait de volgorde om. Op mobiel staat dezelfde keuze in een keuzelijst, met een ⇅-knop voor de richting.</p>
    <ul>
        <li><strong>Beveiliging</strong>: de meeste niet-vertrouwde items eerst.</li>
        <li><strong>Extensies</strong>: eerst "Niet up-to-date" (hoe meer verouderd/onbekend, hoe hoger), dan "Deels onbekend", "Onbekend", en "Up-to-date" onderaan.</li>
        <li><strong>Joomla</strong>: eerst op hoofdversie (een oudere hoofdversie staat altijd boven, ook als die binnen de eigen reeks bij is), dan op status, dan op versienummer (oudste eerst). Sites zonder versie staan onderaan.</li>
        <li><strong>PHP</strong>: oudste versie eerst; sites zonder bekende versie onderaan.</li>
    </ul>

    <h3 id="php-versie">De kolom "PHP"</h3>
    <p>De PHP-versie waarop de website draait, gemeld door het scanscript bij elke scan (ook bovenaan de scanuitvoer 📋). Een cronjob of de opdrachtregel op dezelfde server kan op een andere versie draaien. <strong>Leeg</strong> betekent: nog niet gescand. De kolom geeft geen oordeel: welke versie geschikt is, hangt af van de Joomla-versie en de extensies. De PHP-versie wijzig je in het beheerpaneel van de hostingpartij.</p>

    <h3>De icoontjes in de kolom "Actie"</h3>
    <table>
        <tr><th style="width: 60px;">Icoon</th><th>Betekenis</th></tr>
        <tr><td>↻</td><td>Alleen deze site opnieuw scannen (hoofdstuk 7).</td></tr>
        <tr><td>⚙️</td><td>Site-instellingen (hoofdstuk 6).</td></tr>
        <tr><td>📋</td><td>Het scanscript van deze site openen in een nieuw tabblad: een verse scan met de ruwe uitvoer, nooit uit een cache.</td></tr>
        <tr><td><strong>FTP</strong></td><td>Alleen met FTP-/SFTP-gegevens. Opent een <code>ftp://</code>-, <code>ftpes://</code>- (FTPS) of <code>sftp://</code>-link in je FTP-programma, als je computer dat protocol daaraan gekoppeld heeft (zie hieronder). Bevat gebruikersnaam of wachtwoord een teken dat niet betrouwbaar in een link kan, dan staat er een 📋 bij: het wachtwoord (en zo nodig de gebruikersnaam) gaat naar het klembord en de link opent zonder die gegevens.</td></tr>
        <tr><td><strong>PDF</strong></td><td>Het klantrapport (hoofdstuk 10).</td></tr>
        <tr><td>🗑️</td><td>Site verwijderen (hoofdstuk 6).</td></tr>
    </table>

    <h3 id="uitbreidingen">Knoppen naast "Hostingoverzicht"</h3>
    <p><strong>🖥️ Hostingoverzicht</strong> staat altijd in de samenvattingsbalk (hoofdstuk 14). Is er op deze monitor een losse uitbreiding geïnstalleerd in de map <code>uitbreidingen/</code>, dan verschijnt daar ook de knop van die uitbreiding, bijvoorbeeld <strong>📦 Updates</strong> voor de uitbreiding in <code>uitbreidingen/updates/</code>. Zo'n uitbreiding hoort niet bij de monitor zelf, wordt apart bijgehouden en heeft een eigen versienummer en eigen uitleg. Zonder uitbreiding is er geen knop.</p>

    <h3 id="ftp-client">De FTP-knop koppelen aan je FTP-programma</h3>
    <div class="waarschuwing">
        ⚠️ <strong>Sommige tekens in een FTP-gebruikersnaam of -wachtwoord maken de FTP-knop onbetrouwbaar.</strong>
        Een browser codeert ze altijd in een link, en FileZilla zet dat niet terug. Vermijd daarom:
        <code>" # &lt; &gt; ? ` { } / : ; = @ [ \ ] ^ |</code> en een los <code>%</code>. Veilig zijn bijvoorbeeld
        <code>! $ & ' ( ) * + , - . ~</code>. Kan het niet anders (bijv. een gebruikersnaam in de vorm
        "klantnummer@domein"), dan gebruik je de kopieerstap van de 📋-variant.
    </div>
    <div class="stap"><strong>Windows 11 + gratis FileZilla Client</strong> - Windows laat <code>ftp://</code> niet via "Standaard-apps" aan FileZilla koppelen, en de gratis FileZilla registreert zichzelf niet (FileZilla Pro wel). Dit registerbestand koppelt <code>ftp://</code>, <code>ftpes://</code> en <code>sftp://</code> aan FileZilla; dubbelklikken en bevestigen. Het gaat uit van <code>C:\Program Files\FileZilla FTP Client\filezilla.exe</code>; staat FileZilla elders, pas dan eerst het pad op beide plekken aan in Kladblok (rechtermuisknop → Bewerken). Werkt de knop niet bij sites met FTP over TLS, voer de nieuwste versie van het bestand dan nog een keer uit.<br><br>
        <a class="knop" href="filezilla-als-standaard.reg" download style="background: #1f6fa8;">⬇️ Download filezilla-als-standaard.reg</a></div>
    <div class="stap"><strong>Browser blijft naar een ander programma verwijzen</strong> - Firefox houdt voor <code>ftp://</code>/<code>sftp://</code> een eigen voorkeur bij, los van Windows: Instellingen → Algemeen → Toepassingen → regel <code>ftp</code>/<code>sftp</code> → "Altijd vragen" of FileZilla. Andere browsers hebben een vergelijkbare instelling.</div>
    <div class="stap"><strong>WinSCP</strong> (gratis, alleen Windows) - registreert zich vaak zelf voor alle vier de protocollen; anders via Voorkeuren → Integratie → "Registreren om URL-adressen af te handelen". Start WinSCP steeds een updater in plaats van te verbinden, kijk dan bij Voorkeuren → Bijwerken.</div>
    <div class="stap"><strong>Mac: Cyberduck</strong> (gratis) - vraagt bij het opstarten zelf of het de standaard voor FTP/SFTP mag worden; anders via Voorkeuren → FTP en → SFTP.</div>
    </div>
</details>

<details class="hoofdstuk" id="beveiliging">
    <summary><h2>10. Het beveiligingsrapport</h2></summary>
    <div class="hoofdstuk-inhoud">
    <p>Klik in de kolom "Beveiliging" op de status van een site: alle gevonden verdachte items, met type, pad, wijzigingsdatum, reden en risicoscore. Het rapport toont de stand van de laatste <strong>ontvangen</strong> scan ("Laatste scan" bovenaan); de monitor kijkt niet zelf op de site. Verwijder je iets via FTP, dan blijft het in de lijst tot er een nieuwe scan binnen is.</p>

    <h3>Risicoscore en volgorde</h3>
    <p>Elke vondst krijgt een score van 0 tot 100: <strong>ZEER HOOG</strong> (≥ 90, bijv. een dubbel gelaagde <code>eval&#40;base64_decode&#40;...))</code>), <strong>HOOG</strong> (≥ 70), <strong>MIDDEL</strong> (≥ 40) en <strong>LAAG</strong> (&lt; 40). De lijst is gegroepeerd per type, in volgorde van ernst, en daarbinnen gesorteerd op risico. Zijn er meerdere types, dan kun je bovenaan filteren op één type.</p>
    <p>Komt dezelfde melding vijf keer of vaker voor (bijv. honderden identieke <code>.htaccess</code>-bestanden na een besmetting), dan staan die onder één kopregel met het aantal. Klap open met <strong>▶</strong> voor de losse bestanden; vink de kopregel aan om de hele groep te selecteren. Het klantrapport toont van zo'n groep het aantal, de melding en tien voorbeelden.</p>

    <h3>Vertrouwen</h3>
    <p>Is een item legitiem, klik dan op <strong>"✅ Vertrouwen"</strong>. Het verdwijnt uit de lijst, de tellers en de mail. Met "Toon ook de vertrouwde items" zie je ze weer; het rapport opent altijd met vertrouwde items verborgen.</p>
    <p>Vertrouwen geldt voor precies de <strong>inhoud</strong> van dat moment. Verandert de inhoud, dan komt het bestand terug als nieuw verdacht, ook als de wijzigingsdatum gelijk bleef. Andersom geeft alleen een nieuwe datum geen nieuwe melding. Uitzonderingen: bij een <strong>map</strong> telt alleen de naam, en bij een bestand dat het scanscript niet kan vergelijken (niet leesbaar, of erg groot zoals een back-uparchief) telt de wijzigingsdatum.</p>

    <h3>Actie op een vondst</h3>
    <p>De knoppen achter een vondst worden via het scanscript op de site zelf uitgevoerd, zonder FTP:</p>
    <div class="stap"><strong>👁️ Bekijk</strong> - toont de inhoud (of de inhoud van een map), alleen-lezen. In PHP-code worden opvallende regels gemarkeerd met uitleg eronder: <span style="background:#ffe14d;color:#000;padding:0 6px;border-radius:3px;">zwart op geel</span> = hier gebeurt waarschijnlijk het kwaad (of de route van bezoekersinvoer daarnaartoe), een <em>gele rand</em> = komt ook in gewone code voor. Een aanwijzing, geen bewijs.</div>
    <div class="stap"><strong>🔧 Rechten herstellen</strong> - naar 644 voor een bestand, 755 voor een map.</div>
    <div class="stap"><strong>📦 Quarantaine</strong> - verplaatst het bestand naar <code>_scan_beheer/quarantaine/</code> op de site (van het web afgeschermd) en maakt het alleen-lezen. Herstelbaar.</div>
    <div class="stap"><strong>🚫 Blokkeer</strong> - hernoemt het bestand ter plekke (bijv. <code>iets.php.BLOCKED_20260713_143022_a1b2c3</code>) en maakt het alleen-lezen. Herstelbaar.</div>
    <div class="stap"><strong>🗑️ Verwijder</strong> - naar de prullenbak op de site, die na <strong>7 dagen</strong> vanzelf wordt geleegd. Tot dan herstelbaar.</div>
    <p>Na een geslaagde actie (of Vertrouwen) verdwijnt de vondst meteen uit de lijst en sluit het Bekijk-venster. Alleen bij "Rechten herstellen" blijft alles staan, want die vondst moet meestal nog beoordeeld worden.</p>
    <div class="tip">
        💡 Bij de kernbestanden <code>index.php</code>, <code>administrator/index.php</code>, <code>api/index.php</code> en
        <code>includes/app.php</code> zijn Quarantaine, Blokkeer en Verwijder uitgeschakeld (ook in bulk): de site valt
        dan uit. Vervang zo'n bestand door het origineel (hoofdstuk 12). Vondsten van het type "database" en
        "cluster" hebben alleen Vertrouwen (hoofdstuk 11).
    </div>

    <h3>Bulkacties</h3>
    <p>Vooraan elke rij staat een selectievakje (met "alles selecteren" in de kolomkop). Bij een selectie verschijnt bovenaan een balk met dezelfde acties, met één bevestiging en een voortgangsteller. Items die buiten een actief filter vallen, tellen nooit mee.</p>

    <h3>Beheer: quarantaine, geblokkeerd, prullenbak</h3>
    <p>Onderaan het rapport staat wat er op de site in quarantaine, geblokkeerd of in de prullenbak staat. Per item: <strong>↩️ Herstel</strong> (terug naar de oorspronkelijke plek; mislukt bewust als daar inmiddels iets anders staat) of <strong>❌ Definitief verwijderen</strong>. Er is ook een knop om de prullenbak te legen. Alles staat op de site zelf in <code>_scan_beheer</code>, niet in de monitor.</p>

    <h3>Super Users</h3>
    <p>Is de databaseverbinding van het scanscript gelukt, dan staat in het rapport een blok <strong>"👤 Super Users"</strong> met alle beheerdersaccounts: naam, gebruikersnaam, e-mail, aangemaakt, laatst ingelogd en actief/geblokkeerd. Loop het na: ook een account zonder bekend aanvallerspatroon valt zo op.</p>

    <h3>Klantrapport (PDF)</h3>
    <p>Via <strong>PDF</strong> in de actiekolom of "📄 Klantrapport openen" bovenaan het rapport: een niet-technische samenvatting zonder knoppen, om naar de eigenaar van de site te sturen. Met bedreigingen, rechten, onbekende items, verouderde extensies, de Joomla-versie, de Super Users (met de vraag of de eigenaar ze allemaal herkent) en een eventuele onvolledige scan. Vertrouwde items staan er niet in.</p>
    <p><strong>"🖨️ Opslaan als PDF / afdrukken"</strong> opent het printvoorbeeld van je browser; kies daar "Opslaan als PDF". Klik daarna op "Annuleren" in het printvoorbeeld om terug te keren (niet op het kruisje van het browservenster).</p>
    </div>
</details>

<details class="hoofdstuk" id="controles">
    <summary><h2>11. Wat het scanscript controleert</h2></summary>
    <div class="hoofdstuk-inhoud">
    <p>Naast de controle van alle PHP-bestanden op bekende backdoorpatronen (eval, verborgen of versleutelde code, webshells, laders en droppers) kijkt het scanscript naar onbekende items in de website-root en de volgende signalen:</p>
    <div class="stap"><strong>Kernbestanden</strong> - <code>index.php</code>, <code>administrator/index.php</code>, <code>api/index.php</code> en <code>includes/app.php</code> op code die draait <em>vóór</em> Joomla's eigen <code>_JEXEC</code> is gedefinieerd. Een schoon kernbestand heeft daar niets staan; een vondst krijgt risico 100: de site wordt nu bij elke paginaweergave misbruikt.</div>
    <div class="stap"><strong>Cloaking</strong> - <code>index.php</code> en <code>administrator/index.php</code> op de combinatie van botherkenning (bijv. <code>Googlebot</code> in de user-agent) en het ophalen van externe inhoud. Los komen beide onschuldig voor; samen in een kernbestand wijst het op een aanval die zoekmachines andere inhoud toont dan bezoekers. Verwant: een ROT13-vertaaltabel met <code>strtr()</code> plus een externe aanvraag, en een <code>md5()</code> van een verzoekparameter tegen een vaste hash gevolgd door het schrijven van een bestand - ook buiten kernbestanden.</div>
    <div class="stap"><strong>eval() op verzoekinvoer</strong> - <code>eval&#40;)</code> of <code>assert&#40;)</code> op code uit <code>&#36;_REQUEST</code>, <code>&#36;_POST</code>, <code>&#36;_GET</code> of <code>&#36;_COOKIE</code>, ook via tussenvariabelen of een nep-versleutelstap. Risico 100. Er wordt naar de gegevensstroom gekeken, niet naar één vaste vorm.</div>
    <div class="stap"><strong>Massaal hernoemen</strong> - vijf of meer bestanden/mappen in de webroot met hetzelfde ongebruikelijke achtervoegsel (bijv. <code>bestand.php__113576e</code>): het patroon van een aanval die de hele site in één keer onbruikbaar maakt. <code>_scan_beheer/</code> telt niet mee.</div>
    <div class="stap"><strong>Onzichtbare Unicode-tekens</strong> - bestandsnamen met zero-width-tekens of een RTL-omkeringsteken (een naam die op ".jpg" lijkt te eindigen maar ".php" is).</div>
    <div class="stap"><strong>Verdubbelde mapnamen</strong> - een bestand in een map in een map met dezelfde naam (bijv. <code>models/models/index.php</code>). Bij een generieke Joomla-mapnaam altijd een melding. Bij een andere naam meestal een extensie die haar eigen naam herhaalt, en dus "ter info" - behalve een <code>index.php</code> die als enige bestand in zo'n map staat ("LOS PHP-BESTAND IN VERDUBBELDE MAP"). Let bij "ter info" op de datum: valt die samen met een gevonden backdoor, dan hoort het erbij.</div>
    <div class="stap"><strong>Uploadmappen (images, tmp)</strong> - elk PHP-bestand onder <code>images/</code> (ook 0 bytes) wordt gemeld. Bestanden zonder PHP-extensie (bijv. <code>foto.php.gif</code>) worden op een verstopte PHP-tag gecontroleerd, ook de korte vormen <code>&lt;?=</code> en <code>&lt;?</code>; normale XMP-metadata en binaire ruis in afbeeldingen worden herkend. Vijf of meer verschillend genoemde bestanden met exact dezelfde grootte geven een verzamelmelding (hoger risico bij drie of meer extensies); clusters van alleen audio-, video- of documentbestanden niet.</div>
    <div class="stap"><strong>Exploit-scanner-restanten</strong> - een 0-byte bestand met een SHA-1-achtige naam (bijv. <code>da39a3ee5e6b4b0d3255bfef95601890afd80709</code>). Onschuldig op zich, maar het bewijst dat er ooit schrijftoegang was: "TER INFO".</div>
    <div class="stap"><strong>Verzwakkende php.ini / .user.ini</strong> - bij twee of meer verzwakkende instellingen tegelijk (lege <code>disable_functions</code>, <code>open_basedir</code> uit, <code>safe_mode</code>, <code>exec</code>/<code>shell_exec=on</code>). Een gewone php.ini voor bijv. een hogere uploadlimiet wordt niet gemeld.</div>
    <div class="stap"><strong>Back-ups van configuration.php</strong> - bijv. <code>configuration.bak.php</code>: hoog risico, want die bevatten dezelfde wachtwoorden.</div>
    <div class="stap"><strong>JCE-editorprofielen</strong> - de database wordt gecontroleerd op kwaadaardige profielen van de JCE-editor, die na een aanval vaak blijven staan.</div>
    <div class="stap"><strong>Verdachte Super Users</strong> - accounts met bekende aanvallerspatronen in gebruikersnaam of e-maildomein.</div>
    <div class="stap"><strong>Defacement</strong> - templatestijlen met teksten als "Hacked by" of "Owned by" in de parameters.</div>
    <p>Buiten de website gelden nog extra regels (hoofdstuk 6).</p>

    <h3>Databasecontroles</h3>
    <p>Voor de controles op Super Users, JCE-profielen en defacement zet het scanscript, net als Joomla, een eigen verbinding op met de gegevens uit <code>configuration.php</code>. Die vondsten hebben het type <strong>"database"</strong> en geen bestandspad, dus alleen "Vertrouwen"; los ze op in de beheeromgeving van de site. Lukt de verbinding niet, dan worden deze controles overgeslagen en loopt de rest van de scan door.</p>

    <h3>Verzamelmeldingen (type "cluster")</h3>
    <p>De melding over bestanden met dezelfde grootte gaat over een hele map. Die map bevat meestal ook gewone bestanden, dus er is geen Quarantaine/Blokkeer/Verwijder; alleen Vertrouwen. Verwerk de losse bestanden via FTP. Zo'n melding blijft staan tot een nieuwe scan iets anders vindt.</p>

    <h3>Geneste Joomla-installaties</h3>
    <p>Een map met een eigen <code>configuration.php</code> en <code>administrator</code>-map in de website (bijv. een oude testkopie) wordt herkend als een aparte installatie en geeft één informatieve melding in plaats van honderden onbekende items.</p>

    <h3>Geen uitzonderingen op naam of pad</h3>
    <p>Sommige legitieme bestanden lijken op een backdoor, bijvoorbeeld Smarty's <code>{mailto}</code>, dat e-mailadressen versleutelt met <code>document.write&#40;unescape&#40;...))</code>. Zulke bestanden worden nooit op naam of pad overgeslagen, want een aanvaller kan onder hetzelfde pad iets neerzetten. De onschuldige vorm wordt op <strong>inhoud</strong> herkend, en een enkel bekend bestand alleen overgeslagen als de <strong>SHA-256</strong> van de inhoud exact klopt. Een melding die niet klopt: eerst de oorzaak zoeken, en anders vertrouwen.</p>
    <div class="tip">
        💡 <strong>Akeeba-back-ups buiten de website-root</strong> worden herkend en niet gemeld: een archief van déze
        site met de standaardnaam van Akeeba (<code>site-&lt;domein&gt;-&lt;datum&gt;-&lt;tijd&gt;-&lt;code&gt;.jpa</code>,
        ook <code>.jps</code>, <code>.zip</code> en gesplitste delen) dat ook echt zo'n archief is, en een map met alleen
        zulke archieven en de bijbehorende Akeeba-bestanden. <strong>In</strong> de website-root blijft een archief buiten de eigen map
        van Akeeba wel een melding: dat is vaak te downloaden.
    </div>
    </div>
</details>

<details class="hoofdstuk" id="afwijkingen">
    <summary><h2>12. Afwijkende bestanden en kernbestanden</h2></summary>
    <div class="hoofdstuk-inhoud">

    <h3>Afwijkende extensiebestanden (vergeleken met je andere sites)</h3>
    <p>Kolom 🗂️ op de monitorpagina, en onderaan het beveiligingsrapport. Staat dezelfde extensie in <em>dezelfde versie</em> op meerdere sites, dan horen de bestanden overal gelijk te zijn. De monitor vergelijkt ze per bestand en kijkt wat de meeste sites hebben; wijkt een site af, dan wordt dat bestand daar gemeld.</p>
    <div class="tip">
        💡 Voorbeeld: een extensie staat in dezelfde versie op 5 sites. Op 4 sites is <code>editor.php</code> gelijk,
        op de vijfde niet. Dan is het bestand daar aangepast: door een mislukte update, door iemand zelf, of door een
        aanvaller.
    </div>
    <p>Dit is een signaal, geen bewijs: er wordt vergeleken met je andere sites, niet met het origineel van de maker. Daarom een eigen kolom, met oranje. Regeleinden (Windows of Linux) tellen niet mee. Hoe te lezen:</p>
    <div class="stap"><strong>Veel bestanden van één extensie die op dezelfde manier afwijken</strong> - samengevoegd tot één rij, bijv. "73 bestanden wijken op dezelfde manier af". Meestal een andere editie of build met hetzelfde versienummer (Pro tegenover Core, een hotfix). <strong>"Vertrouw alle N"</strong> bovenaan zo'n rij vertrouwt ze in één keer; losse bestanden kun je ook apart vertrouwen.</div>
    <div class="stap"><strong>Eén los bestand</strong> - dit is het geval om naar te kijken. Bekijk of er code in staat die er niet hoort (eval, base64, een vreemde include).</div>
    <p>Staat een extensie maar op één site, of heeft elke site een andere versie, dan is er niets te vergelijken. Bij twee sites die verschillen is niet te zeggen welke goed is. Joomla's eigen kernbestanden (ook CodeMirror en TinyMCE) zitten niet in deze vergelijking, maar in die met het officiële pakket hieronder.</p>

    <h3 id="restanten">Restanten van oudere versies (de meest voorkomende oorzaak)</h3>
    <p>Joomla's installer kopieert bij een update alleen wat in het nieuwe pakket zit. Vervallen of hernoemde bestanden blijven staan, tenzij de extensie ze zelf opruimt - en veel extensies doen dat niet. Welk restant er staat, hangt af van de versie waarmee een site ooit begon. Daardoor verschillen zulke bestanden tussen je sites.</p>
    <div class="stap"><strong>Herkennen</strong>: alleen het commentaar bovenin verschilt (bijv. het jaartal), of de code is anders maar ziet eruit als gewone code van de maker, zonder eval, base64 of een vreemde include.</div>
    <div class="stap"><strong>Controleren</strong>: pak het installatiepakket van precies die versie uit en kijk of het bestand erin zit. Modules en plugins staan vaak in een submap (bijv. <code>packages/</code>) of als zip in de zip.
        <ul>
            <li><strong>Niet in het pakket</strong>: een restant. Het kan op álle sites weg, ook waar het niet gemeld wordt.</li>
            <li><strong>Wel in het pakket</strong>: vergelijk de hash (PowerShell: <code>Get-FileHash bestand.php -Algorithm SHA256</code>) met die op je sites. Wie afwijkt, heeft de extensie niet goed bijgewerkt: opnieuw installeren lost het op.</li>
        </ul>
    </div>
    <div class="tip">
        💡 De meerderheid is niet altijd de juiste: bij restanten heeft vaak de meerderheid het oude bestand en is
        juist de afwijkende site goed. Het installatiepakket is de enige maatstaf.
    </div>
    <div class="tip">
        💡 Twee mappen die alleen in hoofdletters verschillen (bijv. <code>tmpl/NginXConfMaker</code> naast
        <code>tmpl/nginxconfmaker</code>): de oude map met hoofdletters is een restant. Linux ziet twee mappen,
        Windows en macOS niet, dus de maker merkt het vaak niet. De monitor slaat beide paden apart op; de oude map kan weg.
    </div>
    <div class="tip">
        💡 Een restant dat maar op één site staat, of overal gelijk is, wordt niet gemeld. Vind je er één, kijk dan
        ook op je andere sites met die extensie.
    </div>

    <h3>Kernbestanden tegen het officiële Joomla-pakket</h3>
    <p>Als er iets gevonden is, staat in het rapport een sectie <strong>"🛡️ Kernbestanden vs. officieel Joomla-pakket"</strong>: een vergelijking met het ongewijzigde pakket van de officiële Joomla-downloadserver. Dat pakket wordt op de monitor gedownload (nooit op een site), één keer per Joomla-versie die voorkomt. Dit vangt ook een versie die maar op één site staat, en een kernbestand dat op al je sites gelijk is aangepast.</p>
    <p>De vergelijking draait na elke scan (knop, ↻ en cronjob). Meldt het scanscript een kernbestand als verdacht, maar staat het niet in deze sectie, dan kon het officiële pakket niet worden opgehaald; het rapport toont dan de reden.</p>
    <p><strong>"🔍 Bekijk verschil"</strong> toont het bestand van de site naast het officiële, regel voor regel (rood = toegevoegd op de site, groen doorgestreept = weggehaald), met een automatisch oordeel op bekende patronen. "Geen bekend patroon gevonden" betekent niet "veilig". Daarna kun je:</p>
    <div class="stap"><strong>✅ Vertrouwen</strong> - geldt voor precies deze inhoud; verandert het bestand opnieuw, dan komt het terug.</div>
    <div class="stap"><strong>🔧 Automatisch vervangen door origineel</strong> - zet het officiële bestand terug, na een back-up die als "🛡️ Kernbestand-backup" in het beheerblok van het rapport staat en met "↩️ Herstel" terug te zetten is.</div>
    <p>De kolom Beveiliging toont het totaal: <strong>"⚠️ X kernbestand(en) wijken af van officieel pakket"</strong>, en apart het aantal vertrouwde.</p>
    </div>
</details>

<details class="hoofdstuk" id="extensies">
    <summary><h2>13. Extensieoverzicht en extensietabel</h2></summary>
    <div class="hoofdstuk-inhoud">

    <h3>Extensieoverzicht (per site)</h3>
    <p>Klik in de kolom "Extensies" op de status van een site: alle extensies van derden, met geïnstalleerde versie, nieuwste versie en status. Bovenaan staat de gele ↻-knop om alleen deze site te scannen.</p>
    <p>Onderdelen van hetzelfde product (bijv. tientallen plugins van één page builder) worden samengevoegd tot één rij, op basis van de pakketkoppeling en een gedeelde herkomst (map-/elementnaam en auteur); de typekolom toont dan bijv. "plugin (12x)". Bij zo'n rij geldt de <strong>hoogste</strong> nieuwste versie van alle onderdelen, en telt een kapotte versiestring (bijv. <code>${PHING.VERSION}</code>) niet mee. Een update-feed die vooruitloopt op een Joomla-versie die nog niet uit is, telt bij taalpakketten niet als update. Joomla-kernonderdelen en vaste pakketonderdelen zonder eigen feed worden niet getoond.</p>
    <p><strong>"Toon ook genegeerde extensies"</strong> toont ook wat je hebt genegeerd, met een "Herstel"-knop. Negeren en Herstel werken altijd op alle onderdelen van een samengevoegde rij tegelijk.</p>

    <h4>Feed geblokkeerd vanaf de site: de monitor haalt hem centraal op</h4>
    <p>Het scanscript haalt de nieuwste versie op via de update-locatie die Joomla kent, op dezelfde manier als Joomla's eigen updatecontrole. Vuistregel: kan Joomla in de beheeromgeving de feed openen, dan kan het scanscript het ook; meldt Joomla "Kan updatesite niet openen", dan lukt het het scanscript ook niet. Sommige update-servers blokkeren verzoeken van websites (captchapagina of HTTP 403/429), vaak juist als veel sites vlak na elkaar vragen. Daarom, zonder dat je iets instelt:</p>
    <ul>
        <li><strong>De monitor onthoudt welke servers blokkeren</strong> (60 dagen na de laatste blokkade). De sites vragen die feeds daarna niet meer zelf op; in de scanuitvoer staat dan "CENTRAAL".</li>
        <li><strong>Hooguit eens per 12 uur haalt één site zo'n feed op, bij toerbeurt</strong>, en stuurt hem naar de monitor, die hem voor alle sites gebruikt ("OK (namens de monitor opgehaald, geldt voor alle sites)"). Lukt het niet, dan mag na een uur een site op een andere server het proberen. De monitor kiest bij voorkeur sites waar het eerder lukte.</li>
        <li><strong>De monitor zelf probeert het alleen als geen site aan de beurt was</strong>, en wacht na een blokkade 3 dagen.</li>
        <li><strong>De laatst bekende versie blijft staan</strong> als een poging mislukt. Een nieuwe versie komt meteen bij alle sites met die extensie terecht, zonder nieuwe scan; een getoonde nieuwste versie wordt nooit verlaagd.</li>
    </ul>
    <p>Het blok "Centraal opgehaalde update-feeds" op het extensieoverzicht toont wanneer elke feed voor het laatst is opgehaald. <strong>"🚀 Probeer nu via deze site"</strong> laat deze site meteen een nieuwe poging doen (handig voor een site waar Joomla de feed wél kan openen). Weigert de server alle automatische verzoeken, open de feed dan via <em>"Open de feed in je browser"</em>, vul het versienummer in en sla op: dat geldt voor alle sites, tot automatisch ophalen weer lukt. Een leeg veld opslaan wist de handmatige versie. Taalbestanden doen hier niet aan mee.</p>

    <h3>Extensietabel beheren</h3>
    <p>Via "🧩 Extensietabel beheren" op het extensieoverzicht. Extensies waarvoor Joomla zelf geen update-locatie kent, komen hier vanzelf in; vul er een update-feed-URL bij in (bijv. van de site van de maker). Een URL geldt meteen voor élke site met die extensie. Drie tabellen:</p>
    <div class="stap"><strong>🌐 Gedeelde extensies met update-feed</strong> - de ingevulde feeds, voor alle sites samen.</div>
    <div class="stap"><strong>📄 Extensies zonder update-feed</strong> - nog op te lossen; kies bovenaan een site om alleen die van díe site te zien.</div>
    <div class="stap"><strong>✅ Overige extensies</strong> - werken al via Joomla's eigen update-registratie. Hier kun je ze alsnog negeren.</div>
    <p>Per rij:</p>
    <ul>
        <li><strong>Negeren</strong> - verdwijnt uit het overzicht en komt na een scan niet terug. Geldt voor alle sites met die extensie. Kan ook vanaf het extensieoverzicht van een site.</li>
        <li><strong>Alleen x.xx.y negeren</strong> - het laatste deel van het versienummer telt niet mee. Voor extensies (vooral taalpakketten) die bij elke kleine correctie een vierde nummer ophogen. "x.x.x.y weer tonen" draait het terug.</li>
        <li><strong>🧹 Negeer alle libraries/taalbestanden</strong> - één knop bovenaan; die krijgen toch nooit een eigen feed.</li>
    </ul>

    <h4>Update-feed-URL uit een installatiepakket halen</h4>
    <p>Selecteer bovenaan het installatiebestand (.zip) van een extensie en druk op "🔍 Zoeken in pakket". De monitor leest het <code>&lt;updateservers&gt;</code>-blok uit het manifest. Wordt de extensie herkend, dan wordt het veld ingevuld en geel gemarkeerd (druk zelf nog op "Opslaan"); anders krijg je de URL te zien, met een <strong>"📋 Kopieer URL"</strong>-knop. Het bestand wordt na het uitlezen verwijderd. Staat er geen feed in het pakket (sommige commerciële makers gebruiken een eigen systeem), dan meldt de monitor dat.</p>
    </div>
</details>

<details class="hoofdstuk" id="hostingoverzicht">
    <summary><h2>14. Het hostingoverzicht</h2></summary>
    <div class="hoofdstuk-inhoud">
    <p>Via <strong>🖥️ Hostingoverzicht</strong> in de samenvattingsbalk: per site de hostingpartij, de server en het IP-adres. Handig om te zien welke sites samen op één server staan, of dat een site verhuisd is. Alles wordt bij het openen <strong>live opgezocht</strong> in openbare gegevens (DNS en RIPE) en nergens bewaard; op de sites hoeft niets te staan. <strong>Opnieuw ophalen</strong> zoekt alles opnieuw op.</p>

    <h3 id="hosting-domein">Domein</h3>
    <p>Het icoontje opent de website; de naam opent de site-instellingen.</p>

    <h3 id="hosting-hostingpartij">Hostingpartij</h3>
    <p>Herkend aan (in deze volgorde) een bekende naam in de servernaam, in de <strong>IP-toewijzing</strong> (aan wie het IP-blok is toegewezen), in de FTP-server, of aan de netwerkeigenaar volgens RIPE. Beweeg met de muis over de naam om te zien waaraan.</p>
    <p>Een hostingpartij hoeft niet vooraf bekend te zijn: een servernaam binnen een domein, bevestigd door een tweede bron (nameservers, FTP-server of RIPE), geeft dat domein als hostingpartij. Een servernaam binnen het domein van de site zelf telt niet. Getoond wordt de partij waar je het pakket afneemt; waar bekend de merknaam in plaats van de technische naam. Huurt een hostingpartij ruimte bij een datacenter, dan staat het IP-blok meestal op haar eigen naam en wordt die getoond. Een netwerkcode met een lange reeks cijfers wordt nooit als naam getoond. <em>Onbekend</em>: niets herkend. <em>Verborgen</em>: de site staat achter Cloudflare (of iets vergelijkbaars) en er zijn geen FTP-gegevens om de echte server te vinden.</p>
    <p>Boven de tabel staat per hostingpartij het aantal sites; klik op een naam om alleen die sites te tonen.</p>

    <h3 id="hosting-server">Server</h3>
    <p>De naam van de server, meestal een nummer met de naam van de hostingpartij. Sites met dezelfde naam delen een server. Daaronder het AS-nummer van het netwerk. Met <strong>▾</strong> in de kolomkop filter je op een of meer servers (geel zolang het filter aan staat); dat werkt samen met het filter op hostingpartij.</p>

    <h3 id="hosting-ip">IP-adres</h3>
    <p>Staat een site achter Cloudflare, dan wordt het echte adres via de FTP-server bepaald; het Cloudflare-adres staat er ter info onder.</p>

    <h3 id="hosting-toelichting">Toelichting</h3>
    <p>Bijzonderheden per site, plus de ingestelde FTP-server en de nameservers. Let vooral op <em>"De FTP-server is een ander IP-adres dan de website"</em>: vaak is de site verhuisd en wijzen de FTP-gegevens nog naar de oude hostingpartij.</p>
    <div class="tip">
        💡 Klik op een kolomkop om te sorteren (op IP-adres staan sites op dezelfde server onder elkaar).
        <strong>CSV downloaden</strong> geeft een bestand dat direct in Excel opent.
    </div>
    </div>
</details>

<details class="hoofdstuk" id="backups-installatie">
    <summary><h2>15. Back-ups, installeren en bijwerken</h2></summary>
    <div class="hoofdstuk-inhoud">
    <h3>Back-up maken</h3>
    <p>Onderaan de configuratiepagina staan twee downloads: alle broncode als .zip (inclusief <code>config.php</code> en <code>geheime_sleutel.php</code> - bewaar die als een wachtwoord) en de database als .sql-bestand, te importeren in bijv. phpMyAdmin. Daaronder staan het huidige versienummer en de wijzigingsgeschiedenis.</p>

    <h3>Installeren en bijwerken</h3>
    <p>De monitor wordt verspreid via de releases op Github. <code>LEES_DIT_EERST.txt</code> beschrijft beide routes:</p>
    <div class="stap"><strong>Nieuwe installatie</strong> - de volledige broncode van de laatste release uploaden en de map openen in de browser. De installatiewizard (<code>installeer.php</code>) richt de database in en maakt een gebruikersnaam, wachtwoord en geheime sleutels aan.</div>
    <div class="stap"><strong>Bijwerken</strong> - het updatebestand van elke release uploaden, in volgorde: elk bestand bevat alleen wat sinds de vorige release is gewijzigd. Wie meer dan één versie achterloopt, zet de monitor schoon opnieuw neer met de volledige broncode van de laatste release (zie "Schoon opnieuw neerzetten" in <code>LEES_DIT_EERST.txt</code>); sites en instellingen blijven bewaard. De database werkt zichzelf bij zodra de eerste pagina geopend wordt. Bestanden die in een release zijn vervallen, staan in de wijzigingsgeschiedenis; die verwijder je bij een gewone update zelf.</div>
    </div>
</details>

<details class="hoofdstuk" id="problemen">
    <summary><h2>16. Veelvoorkomende problemen</h2></summary>
    <div class="hoofdstuk-inhoud">

    <h3>"⛔ Scan niet gestart" bij een site</h3>
    <p>Bij de laatste poging kon het scanscript niet gestart worden; het resultaat eronder is van een eerdere scan. Beweeg met de muis over de melding voor de reden. Een HTTP-code wijst meestal op een van de problemen hieronder (403, 301, 406). De melding verdwijnt bij de eerstvolgende geslaagde start.</p>

    <h3>Een site geeft plotseling 403 (offline) op alles</h3>
    <p>Meestal een beveiligingsextensie die het IP-adres van de monitor blokkeert, omdat de scans op verdacht verkeer lijken. Zet het IP-adres van de monitor bij de uitzonderingen (<a href="#admin-tools">hoofdstuk 5</a>, stap 4).</p>

    <h3>Het scanscript geeft een 403 terwijl het wel op de site staat</h3>
    <p>Bij een firewall-extensie staat de bestandsnaam waarschijnlijk niet (of met de oude naam) bij de uitzonderingen: <a href="#admin-tools">hoofdstuk 5</a>, stap 8.</p>

    <h3>Een beheeractie (Bekijk/Quarantaine/Blokkeer/Verwijder) geeft "HTTP 403"</h3>
    <p>Staat er <strong>"Request forbidden by administrative rules"</strong> in de melding, dan blokkeert <strong>mod_security</strong> van de hostingpartij het verzoek al voor het bij de site aankomt. Vraag de hostingpartij om een uitzondering voor POST-verzoeken naar het scanscript van deze site, en gebruik tot die tijd FTP. Bij een andere foutcode toont de melding een stukje van wat er terugkwam; dat wijst vaak de oorzaak aan.</p>

    <h3>"Onverwacht antwoord (HTTP 301)"</h3>
    <p>Een omleiding op de site (bijv. http naar https, of www naar zonder www) die de monitor niet bij het scanscript laat uitkomen. Controleer de <code>.htaccess</code> in de root van de site (en daarboven) op een omleiding die daar niet hoort.</p>

    <h3>"Bekijk" (of een andere actie) geeft "HTTP 406 Not Acceptable"</h3>
    <p>Bijna altijd mod_security van de hostingpartij, die het verzoek of het antwoord blokkeert, soms vanwege verdachte code in het bestand zelf. Het scanscript is dan gewoon aanwezig. Bekijk het bestand via FTP, of vraag om een uitzondering voor POST-verzoeken naar het scanscript.</p>

    <h3>Het 📋-scanrapport toont steeds dezelfde tijd, of een herscan meldt "verouderd antwoord"</h3>
    <p>Een scanscript dat echt draait toont bij <strong>"Start:"</strong> de actuele tijd. Steeds dezelfde tijd betekent dat het antwoord uit een <strong>cache</strong> komt (browser, Cloudflare, LiteSpeed of Varnish) en het script niet draait. Het script verbiedt caching en de monitor gebruikt elke keer een unieke URL, maar niet elke cache houdt zich daaraan.</p>
    <div class="stap">1. <strong>Controleren</strong> - open het scanscript in een privévenster en kijk in de netwerktab (F12) naar de headers: <code>Age</code>, <code>X-Cache</code>, <code>CF-Cache-Status</code> of <code>X-LiteSpeed-Cache</code> met HIT verraadt de cache.</div>
    <div class="stap">2. <strong>Uitsluiten</strong> - zet de bestandsnaam van het scanscript op de uitzonderingslijst van de cache (bijv. "Do Not Cache URIs" in een LiteSpeed-cacheplugin, of een Cloudflare-regel "Bypass cache").</div>
    <div class="stap">3. <strong>Of hernoemen</strong> - "Vervang door nieuwe, unieke naam" bij Site-instellingen geeft een nieuwe URL zonder cache. Pas daarna een firewall-uitzondering aan (hoofdstuk 5).</div>

    <h3>Het rapport toont bestanden die er niet meer zijn, of de scan meldt "kon monitor niet bereiken"</h3>
    <p>Het rapport toont de laatste <em>ontvangen</em> scan. Open het scanscript via 📋 en kijk onder <strong>"=== MONITOR ==="</strong> of het resultaat is aangekomen, en zo niet waarom. Veel voorkomend: <strong>fout 28</strong> (time-out), <strong>fout 52/55/56</strong> (verbinding verbroken, vaak een firewall of een limiet op de grootte van een aanvraag op de server van de monitor) of een <strong>HTTP-code</strong> als 403/413. Het script probeert het bij een mislukte verzending zelf nog één keer.</p>
    <p>Twijfel je of je via FTP in dezelfde map kijkt? De scanuitvoer begint met <strong>"Scanmap: ..."</strong>: alle paden in het rapport zijn relatief aan die map.</p>

    <h3>"⏳ Scan afgerond voor N%" / "Onvolledige scan"</h3>
    <p>De bestandsscan heeft een maximale tijd, zodat hij nooit door de tijdslimiet van de server wordt afgebroken. Op een trage server of een grote site is die soms te kort. In de kolom Beveiliging staat dan het deel dat bekeken is ("hoogstens N%": ook het tellen was niet klaar). Een onvolledige scan telt als aandachtspunt; "🟢 Schoon" verschijnt pas na een volledige scan.</p>
    <p>Klik op die regel: het rapport opent met de balk <strong>"Onvolledige scan"</strong> en start meteen een vervolgscan (ook via "Scan vervolgen"). De balk noemt waar de scan stopte. <strong>Een site is pas volledig gecontroleerd als de balk na een scan niet meer verschijnt.</strong></p>
    <p>Het scanscript onthoudt welke bestanden het al schoon heeft bevonden en leest die de volgende keer niet opnieuw in, zolang ze niet zijn gewijzigd. Meestal is de melding na een of twee scans weg. Een gewijzigd bestand wordt altijd opnieuw gecontroleerd (ook als de datum is teruggezet), net als een bestand met een melding. Na een update van het scanscript, en eens per 30 dagen, wordt alles opnieuw ingelezen. De scanuitvoer toont bij <strong>"Inhoudsgeheugen"</strong> hoeveel er is overgeslagen. Blijft de melding staan, ruim dan onnodige bestanden op (oude back-ups, testinstallaties) of sluit grote mappen buiten de website uit (hoofdstuk 6).</p>
    <p>Staan er twee gemonitorde sites op hetzelfde hostingaccount, scan ze dan niet tegelijk in twee tabbladen: ze scannen allebei het hele account. Het scanscript laat de tweede tot 90 seconden wachten.</p>

    <h3>Extensieoverzicht blijft "Onbekend" tonen</h3>
    <p>Kijk of de laatste scan gelukt is, en in de scanuitvoer (📋) bij "Details per extensie" en "=== MONITOR ===" of het ophalen van de feed mislukte. Bij een centraal opgehaalde feed staat er "CENTRAAL" (hoofdstuk 13). Lukt het ook centraal niet, of kent Joomla geen update-locatie, vul dan zelf een feed-URL in via "Extensietabel beheren".</p>

    <h3>Extensieoverzicht toont veel losse rijen van hetzelfde product</h3>
    <p>Draai "Scan en check sites": de groepering wordt bij elke scan opnieuw toegepast. Rijen die je niet wilt zien, kun je negeren in "Extensietabel beheren" (hoofdstuk 13).</p>

    <h3>Joomla-versie blijft leeg</h3>
    <p>De Joomla-versie wordt via het admin-pad opgehaald. Controleer bij Site-instellingen of dat klopt, inclusief een eventueel geheim woord (hoofdstuk 3).</p>

    <h3>Na het wijzigen van de geheime code of het monitorpad werkt niets meer</h3>
    <p>De scripts op de sites gebruiken nog de oude waarden en kunnen zich zo ook niet zelf bijwerken. Stuur het scanscript opnieuw naar alle sites (knop op de configuratiepagina), en zet het bij sites zonder FTP-gegevens zelf neer (hoofdstuk 4).</p>

    <h3>De cronjob geeft een 403 ("Niet toegestaan")</h3>
    <p>Het commando stuurt niet de actuele <strong>cron-beveiligingscode</strong> mee als <code>?cron_code=...</code> (URL) of <code>--cron_code=...</code> (PHP-CLI). Is de code gewijzigd, werk dan ook de cronjob bij (hoofdstuk 7).</p>

    <h3>SFTP lukt niet, of "Class ... not found"</h3>
    <p>SFTP gebruikt de meegeleverde bibliotheek phpseclib. Controleer of <code>lib/phpseclib3</code> en <code>lib/ParagonIE</code> op de monitor staan; zonder die mappen werkt alleen FTP/FTPS.</p>

    <h3>De monitor ziet er raar uit op mijn telefoon</h3>
    <p>Alle pagina's zijn responsive: tabellen worden op een smal scherm kaartjes. Controleer anders of <code>responsive_stijlen.php</code> op de server staat.</p>
    </div>
</details>

</div>

<script>
// Hoofdstukken als uitklapbare blokken (accordion). Een link naar een anker
// (bijv. help.php#admin-tools, of een "?"-icoontje elders in de monitor)
// klapt het hoofdstuk waar dat anker in staat automatisch open en scrolt
// ernaartoe - ook als het anker een kopje binnen een hoofdstuk is.
function zetAlleHoofdstukken(open) {
    document.querySelectorAll('details.hoofdstuk').forEach(function (d) { d.open = open; });
}
function openAnker() {
    var id = decodeURIComponent(location.hash.replace(/^#/, ''));
    if (!id) {
        return;
    }
    var doel = document.getElementById(id);
    if (!doel) {
        return;
    }
    var hoofdstuk = doel.closest('details.hoofdstuk');
    if (hoofdstuk) {
        hoofdstuk.open = true;
    }
    doel.scrollIntoView({ block: 'start' });
}
window.addEventListener('hashchange', openAnker);
document.addEventListener('DOMContentLoaded', openAnker);
</script>

<?php include 'terug_naar_boven.php'; ?>
</body>
</html>
