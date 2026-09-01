<?php
/**
 * endpoint_beveiliging.php
 *
 * Voor scripts die op twee manieren aangeroepen moeten kunnen worden:
 *   - vanuit de ingelogde monitorpagina (bijv. de knop "Scan en check sites"),
 *   - vanuit een cronjob, zonder browser-sessie.
 *
 * Toegang wordt verleend als: (a) er een geldige ingelogde sessie is, OF
 * (b) de juiste geheime cron-code is meegegeven als queryparameter
 * (?cron_code=...). Zonder één van beide wordt de aanvraag geweigerd, zodat
 * dit bestand niet zomaar door willekeurige bezoekers is te activeren.
 */

require_once __DIR__ . '/sessie_start.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/instellingen_functies.php';

$ingelogd = isset($_SESSION['ingelogd']);

$cronCodeVereist    = ontsleutelWaarde(haalInstelling($pdo, 'cron_geheime_code', ''));
$cronCodeMeegegeven = (string) ($_GET['cron_code'] ?? '');

// Ondersteuning voor aanroepen via PHP-CLI (bijv. "php script.php --cron_code=xxx"),
// waar een queryparameter in de URL niet als $_GET binnenkomt.
if ($cronCodeMeegegeven === '' && php_sapi_name() === 'cli' && isset($argv)) {
    foreach ($argv as $arg) {
        if (strpos($arg, '--cron_code=') === 0) {
            $cronCodeMeegegeven = substr($arg, strlen('--cron_code='));
        }
    }
}

$cronCodeGeldig = $cronCodeVereist !== '' && hash_equals($cronCodeVereist, $cronCodeMeegegeven);

if (!$ingelogd && !$cronCodeGeldig) {
    http_response_code(403);
    die('Niet toegestaan.');
}

// Beschikbaar maken voor het script dat dit bestand include't, zodat het
// onderscheid kan maken tussen "getriggerd via de ingelogde monitorpagina"
// en "getriggerd via de cronjob" (bijv. om e-mailmeldingen alleen bij een
// cronjob te versturen, niet bij een handmatige druk op de knop).
$EINDPUNT_VIA_CRON = $cronCodeGeldig;
$EINDPUNT_INGELOGD = $ingelogd;
