<?php
// bekijk_kern_afwijking.php
//
// Haalt bij een gevonden kernbestand-afwijking (kern_bestand_afwijkingen)
// automatisch BEIDE kanten van de vergelijking op - de daadwerkelijke,
// actuele inhoud van het bestand op de site zelf (via dezelfde "Bekijk"-
// actie die scan_template.php al ondersteunt voor andere vondsten), en het
// bijbehorende officiële bestand uit het Joomla-pakket - en toont ze naast
// elkaar met een automatisch, leesbaar oordeel. Bedoeld zodat iemand zonder
// Joomla-kennis dit niet zelf via FTP/phpMyAdmin hoeft uit te zoeken.
//
// LET OP: het automatische oordeel is een hulpmiddel, geen garantie. Het
// herkent een aantal bekende, veelvoorkomende verdachte patronen - het kan
// nooit alle mogelijke kwaadaardige code herkennen, en "geen bekend patroon
// gevonden" betekent dus niet automatisch "veilig". Bij twijfel altijd
// handmatig laten beoordelen.

require_once 'sessie_start.php';
if (!isset($_SESSION['ingelogd'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'instellingen_functies.php';
require_once 'kern_integriteit_functies.php';
require_once 'csrf_functies.php';

// Kan een download van het officiële pakket vergen (enkele tientallen MB) -
// ruimer budget dan een gewone paginaweergave, net als bij
// vergelijk_kern_bestanden.php.
@set_time_limit(120);
@ini_set('memory_limit', '512M');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $pdo->prepare("SELECT * FROM kern_bestand_afwijkingen WHERE id = ?");
$stmt->execute([$id]);
$afwijking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$afwijking) {
    die("Deze afwijking bestaat niet (meer) - mogelijk is de vergelijking inmiddels opnieuw gedraaid. Ga terug naar de beveiligingspagina en probeer het daar opnieuw.");
}

$siteStmt = $pdo->prepare("SELECT id, domein, scan_bestandsnaam, url_subpad FROM sites WHERE id = ?");
$siteStmt->execute([$afwijking['site_id']]);
$site = $siteStmt->fetch(PDO::FETCH_ASSOC);

if (!$site) {
    die("De bijbehorende site bestaat niet meer.");
}

$geheimeCode = ontsleutelWaarde(haalInstelling($pdo, 'geheime_code', ''));
$scanBestandsnaam = bepaalScanBestandsnaam($site);
$url = bepaalSiteUrl($site, $scanBestandsnaam);

// ----------------------------------------------------------------------
// Stap 1: de ACTUELE inhoud rechtstreeks bij de site ophalen - niet de
// hash uit de database, want die zegt alleen "is het anders", niet "wat is
// het". Zelfde mechanisme als de "Bekijk"-knop elders in het
// beveiligingsrapport.
// ----------------------------------------------------------------------
/**
 * Kale curl-aanroep naar het scanscript op de site, zonder JSON-decodering
 * (dat doet de aanroeper). Gedeeld door haalSiteBestandOp() hieronder.
 */
function verstuurKernInfoVerzoek(string $url, array $body): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_POSTREDIR => 7,
    ]);
    $antwoord = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlFout = curl_error($ch);
    curl_close($ch);

    return ['antwoord' => $antwoord, 'curl_errno' => $curlErrno, 'curl_fout' => $curlFout];
}

function haalSiteBestandOp(string $url, string $geheimeCode, string $pad): array
{
    $body = [
        'geheime_code' => $geheimeCode,
        'actie' => 'bekijk',
        'pad' => $pad,
    ];
    $poging = verstuurKernInfoVerzoek($url, $body);

    if ($poging['curl_errno'] !== 0) {
        return ['ok' => false, 'foutmelding' => "Kon de site niet bereiken: {$poging['curl_fout']}"];
    }

    $resultaat = json_decode((string) $poging['antwoord'], true);

    // Zelfde aanpak als bij een schrijvende actie (zie kern_bestand_actie.php):
    // bij een net uitgerolde, nog onbekende actie werkt het scanscript
    // zichzelf eerst bij en voert de actie dan INTERN al een keer uit - het
    // JSON-resultaat daarvan staat aan het eind van de respons, achter een
    // platte-tekst voortgangsverslag. Eerst proberen dat eruit te halen,
    // vóórdat er alsnog een nieuwe (voor "bekijk" onschadelijke, maar voor
    // de consistentie toch vermeden) aanroep gedaan wordt.
    if (!is_array($resultaat)) {
        $laatsteAccolade = strrpos((string) $poging['antwoord'], '{');
        if ($laatsteAccolade !== false) {
            $mogelijkeJson = json_decode(substr((string) $poging['antwoord'], $laatsteAccolade), true);
            if (is_array($mogelijkeJson)) {
                $resultaat = $mogelijkeJson;
            }
        }
    }

    if (!is_array($resultaat) && stripos((string) $poging['antwoord'], 'ZELF-BIJWERKEN') !== false) {
        $herhaaldePoging = verstuurKernInfoVerzoek($url, $body);
        if ($herhaaldePoging['curl_errno'] === 0) {
            $herhaaldResultaat = json_decode((string) $herhaaldePoging['antwoord'], true);
            if (is_array($herhaaldResultaat)) {
                $resultaat = $herhaaldResultaat;
            }
        }
    }

    if (!is_array($resultaat) || empty($resultaat['succes'])) {
        $foutmelding = is_array($resultaat) && !empty($resultaat['foutmelding'])
            ? $resultaat['foutmelding']
            : 'Onverwacht antwoord van de site.';
        return ['ok' => false, 'foutmelding' => $foutmelding];
    }
    if (($resultaat['type'] ?? '') !== 'bestand') {
        return ['ok' => false, 'foutmelding' => 'Dit pad is (niet meer) een bestand op de site.'];
    }

    return ['ok' => true, 'inhoud' => (string) $resultaat['inhoud'], 'afgekapt' => !empty($resultaat['afgekapt'])];
}

$siteResultaat = haalSiteBestandOp($url, $geheimeCode, $afwijking['relatief_pad']);
$officieelResultaat = haalOfficieelBestandInhoud($afwijking['kernversie'], $afwijking['relatief_pad']);

$diffRegels = null;
$oordeel = null;
if ($siteResultaat['ok'] && $officieelResultaat['ok']) {
    $diffRegels = berekenRegelDiff($officieelResultaat['inhoud'], $siteResultaat['inhoud']);
    $oordeel = beoordeelDiffOpVerdachtePatronen($diffRegels);
}

?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <script>
    // Voorkeur voor licht/donker zo vroeg mogelijk toepassen (vóór de rest
    // van de pagina rendert), zodat er geen flits van het verkeerde thema
    // is - zelfde mechanisme als op elke andere pagina van de monitor.
    (function () {
        var voorkeur = localStorage.getItem('thema_voorkeur');
        if (voorkeur === 'licht' || voorkeur === 'donker') {
            document.documentElement.setAttribute('data-thema', voorkeur);
        }
    })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include 'favicon_tags.php'; ?>
    <title>Kernbestand vergelijken - <?php echo htmlspecialchars($site['domein']); ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            font-size: 13px;
        }
        header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 5px;
        }
        header h1 {
            margin: 0 0 5px 0;
        }
        .domein-titel {
            font-size: 18px;
            font-weight: bold;
            color: var(--thema-tekst);
            margin-bottom: 20px;
        }
        .knop {
            display: inline-block;
            margin-top: 20px;
            padding: 8px 14px;
            background: #333;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            border: none;
            font-size: 13px;
            cursor: pointer;
        }
        header .knop {
            margin-top: 0;
        }
        .knop:hover {
            background: #555;
        }
        .diff-regel { font-family: 'Courier New', monospace; font-size: 13px; white-space: pre-wrap; word-break: break-all; padding: 1px 8px; }
        .diff-toegevoegd { background: var(--thema-genegeerd-bg) !important; color: var(--thema-genegeerd-tekst) !important; }
        .diff-verwijderd { background: var(--thema-vertrouwd-bg) !important; color: var(--thema-vertrouwd-tekst) !important; text-decoration: line-through; opacity: 0.8; }
        .diff-gelijk { color: var(--thema-uitleg-tekst) !important; }
        .oordeel-banner { padding: 16px 20px; border-radius: 8px; margin: 20px 0; font-size: 15px; background: var(--thema-kader-bg) !important; color: var(--thema-tekst) !important; }
        .oordeel-rood { border: 2px solid var(--thema-rood); }
        .oordeel-geel { border: 2px solid var(--thema-geel); }
        .oordeel-fout { border: 2px solid var(--thema-uitleg-tekst); }
        .diff-kader { border: 1px solid var(--thema-rand); }
    </style>
    <?php include 'responsive_stijlen.php'; ?>
</head>
<body>

<header>
    <div>
        <h1>🛡️ Kernbestand vergelijken</h1>
        <div class="domein-titel"><?php echo htmlspecialchars($site['domein']); ?></div>
    </div>
    <div style="display: flex; gap: 8px;">
        <button type="button" class="knop" onclick="history.back()" style="padding: 8px 12px;" title="Eén stap terug">←</button>
        <a class="knop" href="beveiliging.php?id=<?php echo (int) $site['id']; ?>">Terug naar beveiligingsrapport</a>
    </div>
</header>

    <p>
        <strong>Site:</strong> <?php echo htmlspecialchars($site['domein']); ?><br>
        <strong>Joomla-kernversie:</strong> <?php echo htmlspecialchars($afwijking['kernversie']); ?><br>
        <strong>Bestand:</strong> <code><?php echo htmlspecialchars($afwijking['relatief_pad']); ?></code>
    </p>

    <?php if (!$siteResultaat['ok'] || !$officieelResultaat['ok']): ?>
        <div class="oordeel-banner oordeel-fout">
            <strong>⚠️ Kon de vergelijking niet volledig maken.</strong><br>
            <?php if (!$siteResultaat['ok']): ?>
                Bestand op de site zelf ophalen mislukte: <?php echo htmlspecialchars($siteResultaat['foutmelding']); ?><br>
            <?php endif; ?>
            <?php if (!$officieelResultaat['ok']): ?>
                Officieel bestand ophalen mislukte: <?php echo htmlspecialchars($officieelResultaat['foutmelding']); ?>
            <?php endif; ?>
            <br><br>
            Dit is GEEN reden voor gerustheid - het betekent alleen dat de automatische vergelijking niet is gelukt.
            Controleer dit bestand voor de zekerheid handmatig via FTP.
        </div>
    <?php else: ?>

        <?php if ($oordeel['niveau'] === 'rood'): ?>
        <div class="oordeel-banner oordeel-rood">
            <strong>🔴 Waarschuwing: dit bestand bevat een patroon dat vaak bij backdoors voorkomt.</strong><br>
            Reden: <?php echo htmlspecialchars($oordeel['reden']); ?>.<br><br>
            <strong>Wat te doen:</strong> onderneem GEEN actie op basis van dit oordeel alleen. Laat dit door een
            ervaren ontwikkelaar bevestigen (of neem contact op met Wouter) voordat je iets verwijdert of aanpast -
            dit kan ook een bewuste, legitieme aanpassing zijn, maar dat patroon rechtvaardigt altijd een tweede blik.
        </div>
        <?php else: ?>
        <div class="oordeel-banner oordeel-geel">
            <strong>🟡 Geen bekend verdacht patroon gevonden in het verschil.</strong><br>
            Dat is GEEN garantie dat het onschuldig is - het betekent alleen dat er geen bekende, veelvoorkomende
            aanvalstechniek is herkend. Bekijk het verschil hieronder zelf, of laat het beoordelen als je twijfelt.
        </div>
        <?php endif; ?>

        <div style="display: flex; gap: 10px; align-items: center; margin: 20px 0;">
            <button type="button" class="knop" id="knop-vertrouw" onclick="kernActie('vertrouw', this)">✅ Vertrouwen (negeren)</button>
            <button type="button" class="knop" id="knop-vervang" onclick="kernActie('vervang', this)" style="background: #c0392b;">🔧 Automatisch vervangen door origineel</button>
        </div>
        <div id="kern-actie-status"></div>

        <?php if ($siteResultaat['afgekapt'] || $officieelResultaat['afgekapt']): ?>
        <p class="uitleg" style="font-size: 13px;">
            ℹ️ Eén van beide bestanden is groter dan 64 KB en hier afgekapt weergegeven - de vergelijking hieronder
            is dus mogelijk niet volledig.
        </p>
        <?php endif; ?>

        <h2 style="margin-top: 30px;">Verschil (rood = toegevoegd op de site, groen doorgestreept = verwijderd t.o.v. het origineel)</h2>
        <div class="diff-kader" style="border-radius: 6px; overflow-x: auto;">
            <?php foreach ($diffRegels as $regel): ?>
                <?php
                $klasse = 'diff-gelijk';
                $teken = '  ';
                if ($regel['type'] === 'toegevoegd') {
                    $klasse = 'diff-toegevoegd';
                    $teken = '+ ';
                } elseif ($regel['type'] === 'verwijderd') {
                    $klasse = 'diff-verwijderd';
                    $teken = '- ';
                }
                ?>
                <div class="diff-regel <?php echo $klasse; ?>"><?php echo htmlspecialchars($teken . $regel['regel']); ?></div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

<script>
const CSRF_TOKEN = <?php echo json_encode(haalCsrfToken()); ?>;
const AFWIJKING_ID = <?php echo (int) $afwijking['id']; ?>;
const SITE_ID = <?php echo (int) $site['id']; ?>;

function kernActie(actie, knop) {
    const bevestiging = actie === 'vervang'
        ? 'Weet je zeker dat je dit bestand automatisch wilt laten vervangen door de officiële versie? De huidige inhoud wordt eerst herstelbaar bewaard, maar het bestand op de site wordt direct overschreven.'
        : 'Weet je zeker dat je deze afwijking wilt vertrouwen? Hij verdwijnt dan uit het beveiligingsrapport, tenzij dit bestand later opnieuw verandert.';

    if (!confirm(bevestiging)) {
        return;
    }

    const statusEl = document.getElementById('kern-actie-status');
    document.getElementById('knop-vertrouw').disabled = true;
    document.getElementById('knop-vervang').disabled = true;
    statusEl.innerHTML = '⏳ Bezig...';

    const body = new URLSearchParams();
    body.append('csrf_token', CSRF_TOKEN);
    body.append('id', AFWIJKING_ID);
    body.append('actie', actie);

    fetch('kern_bestand_actie.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
        .then(r => r.json())
        .then(data => {
            if (data.succes) {
                statusEl.innerHTML = '<span style="color: var(--thema-vertrouwd-tekst);">✅ ' + data.melding
                    + '</span><br><a class="knop" style="margin-top: 10px;" href="beveiliging.php?id=' + SITE_ID + '">Terug naar beveiligingsrapport</a>';
            } else {
                statusEl.innerHTML = '<span style="color: var(--thema-genegeerd-tekst);">⚠️ ' + data.foutmelding + '</span>';
                document.getElementById('knop-vertrouw').disabled = false;
                document.getElementById('knop-vervang').disabled = false;
            }
        })
        .catch(() => {
            statusEl.innerHTML = '<span style="color: var(--thema-genegeerd-tekst);">⚠️ Onverwachte fout - probeer het opnieuw.</span>';
            document.getElementById('knop-vertrouw').disabled = false;
            document.getElementById('knop-vervang').disabled = false;
        });
}
</script>

<?php include 'terug_naar_boven.php'; ?>
</body>
</html>
