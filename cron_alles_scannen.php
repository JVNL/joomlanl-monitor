<?php
// cron_alles_scannen.php
//
// Bedoeld om via een cronjob (bijv. in DirectAdmin) automatisch te draaien,
// bijvoorbeeld 1x per dag. Doet hetzelfde als de knop "Scan en check sites"
// op de monitorpagina, maar dan zonder browser nodig te hebben:
//
//   1. start_scan.php      - stuurt scan-en-check-website.php naar elke site
//   2. (wachten, zodat de scans op de sites kunnen voltooien)
//   3. check_sites.php     - controleert website- en SSL-status
//   4. haal_versies_op.php - haalt Joomla-/extensieversies + nieuwste versies op
//   5. vergelijk_extensie_bestanden.php - vergelijkt extensiebestanden tussen sites
//   6. vergelijk_kern_bestanden.php - vergelijkt Joomla-kernbestanden met het officiële pakket
//   7. verstuur_notificatie_email.php - stuurt (indien nodig) één notificatiemail
//
// Elke stap wordt als eigen, onafhankelijk HTTP-verzoek aangeroepen (net als
// de knop op de monitorpagina zelf doet), zodat er geen risico is op
// conflicten tussen de losse scripts.
//
// Dit bestand hoeft NOOIT handmatig in de browser geopend te worden - vul
// in de cronjob simpelweg de URL naar dit bestand in (zie uitleg onderaan).

require_once 'config.php';
require_once 'endpoint_beveiliging.php';
require_once 'instellingen_functies.php';

$basisUrl = rtrim(haalInstelling($pdo, 'monitor_basis_url', ''), '/');
$cronCode = ontsleutelWaarde(haalInstelling($pdo, 'cron_geheime_code', ''));

if ($basisUrl === '') {
    die("FOUT: 'monitor_basis_url' is niet ingesteld op de configuratiepagina.\n");
}
if ($cronCode === '') {
    die("FOUT: 'cron_geheime_code' is niet ingesteld op de configuratiepagina.\n");
}

function roepStapAan(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $resultaat = curl_exec($ch);
    $fout      = curl_error($ch);
    curl_close($ch);

    if ($resultaat === false) {
        return "(kon niet worden aangeroepen: $fout)";
    }
    return $resultaat;
}

echo "=== Cron gestart: " . date('Y-m-d H:i:s') . " ===\n\n";

echo "--- Stap 1/3: start_scan.php ---\n";
echo roepStapAan("$basisUrl/start_scan.php?cron_code=" . urlencode($cronCode)) . "\n\n";

echo "--- Wachten 30 seconden, zodat de scans op de sites kunnen voltooien ---\n\n";
sleep(30);

echo "--- Stap 2/3: check_sites.php ---\n";
echo roepStapAan("$basisUrl/check_sites.php?cron_code=" . urlencode($cronCode)) . "\n\n";

echo "--- Stap 3/3: haal_versies_op.php ---\n";
echo roepStapAan("$basisUrl/haal_versies_op.php?cron_code=" . urlencode($cronCode)) . "\n\n";

echo "--- Stap 4/6: vergelijk_extensie_bestanden.php ---\n";
echo roepStapAan("$basisUrl/vergelijk_extensie_bestanden.php?cron_code=" . urlencode($cronCode)) . "\n\n";

echo "--- Stap 5/6: vergelijk_kern_bestanden.php ---\n";
echo roepStapAan("$basisUrl/vergelijk_kern_bestanden.php?cron_code=" . urlencode($cronCode)) . "\n\n";

echo "--- Stap 6/6: verstuur_notificatie_email.php ---\n";
echo roepStapAan("$basisUrl/verstuur_notificatie_email.php?cron_code=" . urlencode($cronCode)) . "\n\n";

echo "=== Cron klaar: " . date('Y-m-d H:i:s') . " ===\n";
