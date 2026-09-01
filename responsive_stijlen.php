<?php
// responsive_stijlen.php
//
// Gedeeld stijlblok, in te laden op elke pagina (net als terug_naar_boven.php)
// via een PHP include-aanroep ergens in de head-sectie.
//
// Zorgt voor twee dingen:
//   1. Algemene responsive basis (blokken/formulieren/knoppen passen zich aan
//      op smalle schermen, geen zijwaarts wegvallende inhoud).
//   2. Tabellen met de klasse "responsive-tabel" worden op smalle schermen
//      (telefoon/tablet-formaat) omgezet van kolommen naar kaartjes: elke
//      rij wordt een los blokje, met de kolomnaam als label vóór de waarde.
//      Dit werkt puur op CSS (geen JavaScript nodig), via data-label-
//      attributen die op elke tabelcel moeten staan - zie de aangepaste
//      tabellen op de pagina's zelf.
?>
<style>
/* ------------------------------------------------------------------ */
/* Licht/donker-thema: volgt automatisch de instelling van je besturings-*/
/* systeem/browser (prefers-color-scheme), met een handmatige schakelaar */
/* (rechtsboven, tussen Help en Uitloggen) die voorrang krijgt zodra je   */
/* zelf een keuze maakt - die keuze wordt onthouden voor volgende keren. */
/* ------------------------------------------------------------------ */
:root {
    --thema-bg: #ffffff;
    --thema-tekst: #222222;
    --thema-kader-bg: #ffffff;
    --thema-rand: #dddddd;
    --thema-invoer-bg: #ffffff;
    --thema-invoer-tekst: #222222;
    --thema-invoer-rand: #b5b5b5;
    --thema-uitleg-tekst: #666666;
    --thema-zebra: #fafafa;
    --thema-link: #1f6fa8;
    --thema-kop-bg: #333333;
    --thema-kop-tekst: #ffffff;
    --thema-vertrouwd-bg: #f0f7f0;
    --thema-vertrouwd-tekst: #4c7a4c;
    --thema-genegeerd-bg: #fbe9e7;
    --thema-genegeerd-tekst: #a83232;
    --thema-badge-bg: #eef1f4;
    --thema-badge-tekst: #666666;
    --thema-domein-link: #1f6fa8;
    --thema-groen: #1e7e34;
    --thema-geel: #665200;
    --thema-rood: #c0392b;
}

@media (prefers-color-scheme: dark) {
    :root {
        --thema-bg: #16181c;
        --thema-tekst: #e4e6eb;
        --thema-kader-bg: #23262b;
        --thema-rand: #3a3d42;
        --thema-invoer-bg: #2a2d33;
        --thema-invoer-tekst: #e4e6eb;
        --thema-invoer-rand: #6b7280;
        --thema-uitleg-tekst: #babec5;
        --thema-zebra: #1c1f24;
        --thema-link: #8ec4f0;
        --thema-kop-bg: #2c5f8a;
        --thema-kop-tekst: #ffffff;
        --thema-vertrouwd-bg: #1e2e21;
        --thema-vertrouwd-tekst: #7cc98a;
        --thema-genegeerd-bg: #3a2220;
        --thema-genegeerd-tekst: #e08b83;
        --thema-badge-bg: #2f3237;
        --thema-badge-tekst: #b7bbc1;
        --thema-domein-link: #cde8ff;
        --thema-groen: #5fd97a;
        --thema-geel: #e0c15c;
        --thema-rood: #e08b83;
    }
}

html[data-thema="licht"] {
    --thema-bg: #ffffff;
    --thema-tekst: #222222;
    --thema-kader-bg: #ffffff;
    --thema-rand: #dddddd;
    --thema-invoer-bg: #ffffff;
    --thema-invoer-tekst: #222222;
    --thema-invoer-rand: #b5b5b5;
    --thema-uitleg-tekst: #666666;
    --thema-zebra: #fafafa;
    --thema-link: #1f6fa8;
    --thema-kop-bg: #333333;
    --thema-kop-tekst: #ffffff;
    --thema-vertrouwd-bg: #f0f7f0;
    --thema-vertrouwd-tekst: #4c7a4c;
    --thema-genegeerd-bg: #fbe9e7;
    --thema-genegeerd-tekst: #a83232;
    --thema-badge-bg: #eef1f4;
    --thema-badge-tekst: #666666;
    --thema-domein-link: #1f6fa8;
    --thema-groen: #1e7e34;
    --thema-geel: #665200;
    --thema-rood: #c0392b;
}

html[data-thema="donker"] {
    --thema-bg: #16181c;
    --thema-tekst: #e4e6eb;
    --thema-kader-bg: #23262b;
    --thema-rand: #3a3d42;
    --thema-invoer-bg: #2a2d33;
    --thema-invoer-tekst: #e4e6eb;
    --thema-invoer-rand: #6b7280;
    --thema-uitleg-tekst: #babec5;
    --thema-zebra: #1c1f24;
    --thema-link: #8ec4f0;
    --thema-kop-bg: #2c5f8a;
    --thema-kop-tekst: #ffffff;
    --thema-vertrouwd-bg: #1e2e21;
    --thema-vertrouwd-tekst: #7cc98a;
    --thema-genegeerd-bg: #3a2220;
    --thema-genegeerd-tekst: #e08b83;
    --thema-badge-bg: #2f3237;
    --thema-badge-tekst: #b7bbc1;
    --thema-domein-link: #cde8ff;
    --thema-groen: #5fd97a;
    --thema-geel: #e0c15c;
    --thema-rood: #e08b83;
}

body {
    background: var(--thema-bg) !important;
    color: var(--thema-tekst) !important;
}

.blok, .kader, .overzicht, .melding, .leeg, .loginbox,
.viewer .kop, table, .tip, .stap, .waarschuwing, .inhoudsopgave {
    background: var(--thema-kader-bg) !important;
    border-color: var(--thema-rand) !important;
    color: var(--thema-tekst) !important;
}

/* Succes-/foutmeldingen (".melding.ok"/".melding.fout") moeten duidelijk
   groen/rood blijven opvallen, ook in donkere modus - zonder deze
   specifiekere regel zouden ze door de algemenere ".melding"-regel
   hierboven gewoon de standaard, donkere kaartkleur krijgen, en daardoor
   nauwelijks nog zichtbaar zijn tussen de rest van de pagina. */
.melding.ok {
    background: var(--thema-vertrouwd-bg) !important;
    color: var(--thema-vertrouwd-tekst) !important;
    border-color: var(--thema-vertrouwd-tekst) !important;
}

.melding.fout {
    background: var(--thema-genegeerd-bg) !important;
    color: var(--thema-genegeerd-tekst) !important;
    border-color: var(--thema-genegeerd-tekst) !important;
}

code {
    background: var(--thema-rand) !important;
    color: var(--thema-tekst) !important;
}

td {
    background: var(--thema-kader-bg) !important;
    color: var(--thema-tekst) !important;
    border-color: var(--thema-rand) !important;
}

tr:nth-child(even) td {
    background: var(--thema-zebra) !important;
}

.uitleg, .blok-uitleg, .subtitel {
    color: var(--thema-uitleg-tekst) !important;
}

input[type="text"], input[type="password"], input[type="email"],
input[type="url"], input[type="number"], textarea, select {
    background: var(--thema-invoer-bg) !important;
    color: var(--thema-invoer-tekst) !important;
    border: 1px solid var(--thema-invoer-rand) !important;
}

a {
    color: var(--thema-link);
}

.domein-link {
    color: var(--thema-domein-link);
}
</style>

<script>
/**
 * Bepaalt het momenteel actieve thema: een handmatige keuze (via het
 * data-thema-attribuut, zie het vroege script in <head>) heeft altijd
 * voorrang; anders wordt gewoon de systeeminstelling gevolgd.
 */
function huidigThema() {
    const handmatig = document.documentElement.getAttribute('data-thema');
    if (handmatig === 'licht' || handmatig === 'donker') {
        return handmatig;
    }
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'donker' : 'licht';
}

function wisselThema() {
    const nieuw = huidigThema() === 'donker' ? 'licht' : 'donker';
    document.documentElement.setAttribute('data-thema', nieuw);
    localStorage.setItem('thema_voorkeur', nieuw);
    werkThemaKnopBij();
}

function werkThemaKnopBij() {
    const knop = document.getElementById('thema-knop');
    if (!knop) {
        return;
    }
    const isDonker = huidigThema() === 'donker';
    knop.innerHTML = '<span class="icoon-glyph">' + (isDonker ? '☀️' : '🌙') + '</span>';
    knop.title = isDonker ? 'Overschakelen naar licht thema' : 'Overschakelen naar donker thema';
}

document.addEventListener('DOMContentLoaded', werkThemaKnopBij);

/**
 * Toont (of verbergt, bij nogmaals klikken op hetzelfde icoontje) een
 * pop-up met een korte samenvatting en een link naar de volledige uitleg
 * op de helppagina. Wordt aangeroepen vanuit de icoontjes die
 * hulpIcoon() (zie instellingen_functies.php) genereert.
 */
function toonHulpPopup(icoon) {
    const bestaandePopup = document.querySelector('.hulp-popup');
    const zelfdeIcoon = bestaandePopup && bestaandePopup.dataset.bijIcoon === icoon.dataset.popupId;
    if (bestaandePopup) {
        bestaandePopup.remove();
    }
    if (zelfdeIcoon) {
        return; // nogmaals klikken op hetzelfde icoontje sluit de pop-up gewoon weer
    }

    const popup = document.createElement('div');
    popup.className = 'hulp-popup';
    popup.dataset.bijIcoon = icoon.dataset.popupId;

    const tekstDiv = document.createElement('div');
    tekstDiv.textContent = icoon.dataset.samenvatting;
    popup.appendChild(tekstDiv);

    const link = document.createElement('a');
    link.href = icoon.dataset.anker;
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = 'Meer info op de helppagina →';
    popup.appendChild(link);

    document.body.appendChild(popup);

    const rect = icoon.getBoundingClientRect();
    const popupBreedte = popup.offsetWidth;
    let links = window.scrollX + rect.left;
    if (links + popupBreedte > window.scrollX + window.innerWidth - 10) {
        links = window.scrollX + window.innerWidth - popupBreedte - 10;
    }
    popup.style.top = (window.scrollY + rect.bottom + 6) + 'px';
    popup.style.left = Math.max(10, links) + 'px';

    setTimeout(() => {
        document.addEventListener('click', function sluitHulpPopup(e) {
            if (!popup.contains(e.target) && e.target !== icoon) {
                popup.remove();
                document.removeEventListener('click', sluitHulpPopup);
            }
        });
    }, 0);
}
</script>

<style>
/* ------------------------------------------------------------------ */
/* Uniforme lettergrootte: alle tekstinhoud op 14px, ongeacht wat een  */
/* pagina zelf al specifieker instelde (11px/12px/13px kwamen door     */
/* elkaar heen voor). Icoonknoppen (die font-size gebruiken om het     */
/* icoontje zelf op grootte te houden, niet als leestekst) en          */
/* kopteksten (h1/h2/h3, bewust groter voor visuele hiërarchie) zijn   */
/* hiervan uitgezonderd.                                               */
/* ------------------------------------------------------------------ */
body, table, td, th, input, select, textarea, label, p,
.uitleg, .blok-uitleg, .subtitel,
.knop:not(.knop-icoon):not(.knop-ververs-icoon),
a:not(.knop-icoon):not(.knop-ververs-icoon) {
    font-size: 14px !important;
}

/* ------------------------------------------------------------------ */
/* Monochrome icoontjes ("retina"-stijl): kleurrijke emoji-icoontjes    */
/* worden getoond als een wit silhouet, voor hoog contrast op een      */
/* zwarte knop-achtergrond - in plaats van de eigen (soms wisselend    */
/* goed leesbare) kleuren van het emoji-lettertype zelf. Geldt op elk  */
/* schermformaat, niet alleen mobiel.                                  */
/* ------------------------------------------------------------------ */
.icoon-glyph {
    display: inline-block;
    filter: brightness(0) invert(1);
}

/* ------------------------------------------------------------------ */
/* Algemene responsive basis                                          */
/* ------------------------------------------------------------------ */
@media (max-width: 700px) {
    body {
        margin: 12px;
        font-size: 14px;
    }

    header {
        flex-direction: column;
        gap: 10px;
        align-items: stretch !important;
    }

    .titel {
        flex-wrap: wrap;
    }

    .titel img {
        width: 36px !important;
        height: 36px !important;
    }

    .blok, .kader {
        max-width: 100% !important;
        padding: 15px !important;
    }

    .ftp-rij {
        flex-direction: column;
        gap: 0 !important;
    }

    .acties-boven, .tabbladen {
        flex-wrap: wrap;
    }

    /* De "Scan en check sites"-knop (op index.php) toont op een smal
       scherm alleen de symbolen, geen tekst - zodat 'ie net zo compact
       wordt als de andere ronde icoonknoppen ernaast. */
    #scan-check-knop .knop-tekst-volledig {
        display: none;
    }

    #scan-check-knop .knop-tekst-compact {
        display: inline;
    }

    input[type="text"], input[type="password"], input[type="email"] {
        font-size: 16px; /* voorkomt automatisch inzoomen op iOS bij het aantikken van een veld */
    }

    /* De overzichtsbalk bovenaan index.php (Totaal sites/Schoon/Aandacht
       nodig) toont op een breed scherm alle items naast elkaar op één
       rij; op een smal scherm is dat te krap, dus dan een net 2×2-raster
       i.p.v. een willekeurige, ongelijke terugloop. */
    .overzicht {
        gap: 14px 10px;
    }

    .overzicht-item {
        flex: 1 1 calc(50% - 10px);
        min-width: 0;
    }
}

/* ------------------------------------------------------------------ */
/* Tabellen -> kaartjes op smalle schermen                            */
/* ------------------------------------------------------------------ */
@media (max-width: 700px) {
    table.responsive-tabel {
        border: none;
        width: 100%;
    }

    /* De koprij bevat geen <thead>, maar gewoon een eerste <tr> met
       <th>'s - dus die rij specifiek verbergen (niet "thead", die tag
       wordt hier nergens gebruikt). */
    table.responsive-tabel tr:has(th) {
        display: none;
    }

    table.responsive-tabel tr {
        display: block;
        margin-bottom: 16px;
        border: 1px solid var(--thema-rand);
        border-radius: 8px;
        padding: 14px 16px;
        background: var(--thema-kader-bg) !important;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
    }

    /* Om-en-om een lichte achtergrondtint, zodat opeenvolgende kaartjes
       (per website/item) duidelijk van elkaar te onderscheiden blijven -
       de kaartjes-tegenhanger van de zebra-streping die een gewone tabel
       al had. */
    table.responsive-tabel tr:nth-child(even) {
        background: var(--thema-zebra) !important;
    }

    table.responsive-tabel td {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: flex-start;
        gap: 6px 12px;
        border: none;
        border-bottom: 1px solid var(--thema-rand);
        padding: 8px 0;
        word-break: break-word;
        overflow-wrap: anywhere;
        text-align: right;
        background: transparent !important;
    }

    table.responsive-tabel td:last-child {
        border-bottom: none;
    }

    table.responsive-tabel td::before {
        content: attr(data-label);
        font-weight: bold;
        color: var(--thema-uitleg-tekst);
        text-align: left;
        flex-shrink: 0;
        max-width: 40%;
    }

    /* Cellen zonder relevante inhoud om te labelen (bijv. een kolom met
       alleen een knop) - data-label="" laat het label gewoon leeg. */
    table.responsive-tabel td:empty::before {
        display: none;
    }

    /* Sorteerkeuze op de overzichtspagina: op een smal scherm zijn de
       klikbare kolomkoppen niet zichtbaar (de hele koprij is verborgen),
       dus daar komt in plaats daarvan dit keuzemenu voor in de plaats. */
    .mobiel-sorteren {
        display: block !important;
        margin-bottom: 12px;
    }
}

.mobiel-sorteren {
    display: none;
}

/* Inline help: klein "?"-icoontje dat een pop-up toont met een korte
   samenvatting plus een link naar de volledige uitleg op de helppagina.
   Zie hulpIcoon() in instellingen_functies.php voor het genereren ervan. */
.hulp-icoon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: var(--thema-badge-bg);
    color: var(--thema-uitleg-tekst);
    border: 1px solid var(--thema-rand);
    font-size: 11px;
    font-weight: bold;
    cursor: pointer;
    margin-left: 5px;
    vertical-align: middle;
    user-select: none;
    line-height: 1;
}

.hulp-icoon:hover,
.hulp-icoon:focus {
    background: var(--thema-link);
    color: white;
    outline: none;
}

/* In donkere modus vallen het rondje en de rand te weinig op tegen de
   donkere pagina-achtergrond (beide zijn dan namelijk ook donkergrijs) -
   hier dus een duidelijk lichter, wit rondje met een donker vraagteken
   erin, voor hetzelfde nette, goed afgebakende effect als in lichte modus. */
@media (prefers-color-scheme: dark) {
    .hulp-icoon {
        background: #f0f2f5;
        color: #333333;
        border-color: #f0f2f5;
    }
}

html[data-thema="donker"] .hulp-icoon {
    background: #f0f2f5;
    color: #333333;
    border-color: #f0f2f5;
}

/* Voorrang voor een handmatig gekozen thema boven de systeeminstelling:
   zonder deze regel zou iemand die zelf "licht" kiest terwijl het systeem
   zelf donker staat, via de @media-regel hierboven toch de donkere
   icoonstijl te zien krijgen. */
html[data-thema="licht"] .hulp-icoon {
    background: var(--thema-badge-bg);
    color: var(--thema-uitleg-tekst);
    border-color: var(--thema-rand);
}

.hulp-popup {
    position: absolute;
    z-index: 1000;
    max-width: 280px;
    background: var(--thema-kader-bg);
    color: var(--thema-tekst);
    border: 1px solid var(--thema-rand);
    border-radius: 6px;
    padding: 10px 12px;
    font-size: 13px;
    line-height: 1.5;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3);
}

.hulp-popup a {
    display: inline-block;
    margin-top: 6px;
    color: var(--thema-link);
    font-weight: bold;
    white-space: nowrap;
    text-decoration: none;
}

.hulp-popup a:hover {
    text-decoration: underline;
}
</style>
