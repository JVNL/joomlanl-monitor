<?php
/**
 * genereer_scanscript_functies.php
 *
 * Gedeelde functie om een kant-en-klare scan-en-check-website.php te
 * genereren, met de geheime code + monitor-URL (en optioneel het
 * per-site extra scanpad) al verwerkt. Wordt gebruikt door zowel de
 * downloadknop (download_scan_script.php) als het rechtstreeks via FTP
 * versturen (ftp_verstuur_scanscript.php).
 */

/**
 * Genereert de inhoud van scan-en-check-website.php voor een specifieke
 * site (of algemeen, als $site null is - dan blijft het extra scanpad leeg).
 */
function genereerScanScriptInhoud(PDO $pdo, ?array $site = null): string
{
    require_once __DIR__ . '/instellingen_functies.php';

    $geheimeCode = ontsleutelWaarde(haalInstelling($pdo, 'geheime_code', ''));
    $monitorUrl  = rtrim(haalInstelling($pdo, 'monitor_basis_url', ''), '/');
    $extraScanPad = $site !== null ? trim($site['extra_scan_pad'] ?? '') : '';
    $extraScanPadNegeren = $site !== null ? trim($site['extra_scan_pad_negeren'] ?? '') : '';

    $sjabloonPad = __DIR__ . '/scan_template.php';

    if (!is_readable($sjabloonPad)) {
        throw new RuntimeException('Sjabloonbestand scan_template.php ontbreekt op de server.');
    }

    $inhoud = file_get_contents($sjabloonPad);
    $inhoud = str_replace('__GEHEIME_CODE__', $geheimeCode, $inhoud);
    $inhoud = str_replace('__MONITOR_BASIS_URL__', $monitorUrl, $inhoud);
    $inhoud = str_replace('__EXTRA_SCAN_PAD__', $extraScanPad, $inhoud);
    $inhoud = str_replace('__EXTRA_SCAN_PAD_NEGEREN__', $extraScanPadNegeren, $inhoud);

    return $inhoud;
}
