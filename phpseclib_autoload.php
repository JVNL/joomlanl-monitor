<?php
// phpseclib_autoload.php
//
// phpseclib is normaal bedoeld om via Composer geladen te worden, maar op
// gedeelde hosting is dat vaak niet beschikbaar/gewenst. Dit bestand
// registreert een simpele, eigen autoloader die exact hetzelfde doet als
// Composer's PSR-4-autoloader zou doen - zowel voor phpseclib3 zelf, als
// voor zijn enige benodigde losse afhankelijkheid (paragonie/constant_time_encoding,
// gebruikt voor het versleutelingswerk binnen SSH). De andere afhankelijkheid
// die phpseclib normaal via Composer meekrijgt (paragonie/random_compat) is
// bewust weggelaten: die is puur een polyfill voor random_bytes() op oude
// PHP-versies (<7), en dus een dode afhankelijkheid op elke moderne server.

require_once __DIR__ . '/lib/phpseclib3/bootstrap.php';

spl_autoload_register(function ($klasse) {
    $mappen = [
        'phpseclib3\\'              => __DIR__ . '/lib/phpseclib3/',
        'ParagonIE\\ConstantTime\\'  => __DIR__ . '/lib/ParagonIE/ConstantTime/',
    ];

    foreach ($mappen as $prefix => $basisMap) {
        $lengte = strlen($prefix);
        if (strncmp($prefix, $klasse, $lengte) !== 0) {
            continue; // deze klasse hoort niet bij dit stukje van de autoloader
        }

        $relatievePad = substr($klasse, $lengte);
        $bestandsPad = $basisMap . str_replace('\\', '/', $relatievePad) . '.php';

        if (is_readable($bestandsPad)) {
            require $bestandsPad;
        }
        return;
    }
});
