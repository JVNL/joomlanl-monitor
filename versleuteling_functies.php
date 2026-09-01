<?php
/**
 * versleuteling_functies.php
 *
 * Omkeerbare versleuteling (AES-256-CBC) voor gevoelige velden die de
 * gebruiker zelf ook weer moet kunnen terugzien (dus geen one-way hash,
 * zoals password_hash() dat zou zijn). De sleutel staat bewust in een
 * los bestand (geheime_sleutel.php), niet in de database.
 */

require_once __DIR__ . '/geheime_sleutel.php';

/**
 * Versleutelt een waarde. Geeft een base64-string terug die de IV +
 * versleutelde data bevat. Lege invoer geeft lege uitvoer (handig, zodat
 * "leeg" niet per se hoeft versleuteld/ontsleuteld te worden).
 */
function versleutelWaarde(?string $klareTekst): string
{
    if ($klareTekst === null || $klareTekst === '') {
        return '';
    }

    $sleutel = hex2bin(VERSLEUTEL_SLEUTEL);
    $iv      = random_bytes(16);

    $cijfertekst = openssl_encrypt($klareTekst, 'aes-256-cbc', $sleutel, OPENSSL_RAW_DATA, $iv);

    if ($cijfertekst === false) {
        return '';
    }

    return base64_encode($iv . $cijfertekst);
}

/**
 * Ontsleutelt een waarde die met versleutelWaarde() is versleuteld. Geeft
 * een lege string terug als ontsleutelen niet lukt (bijv. corrupte data,
 * of de waarde was nooit versleuteld), zodat een fout hier nooit een
 * fatale fout elders veroorzaakt.
 */
function ontsleutelWaarde(?string $versleuteld): string
{
    if ($versleuteld === null || $versleuteld === '') {
        return '';
    }

    $data = base64_decode($versleuteld, true);
    // Bewust mb_substr()/mb_strlen() met de vaste '8bit'-codering i.p.v. de
    // gewone substr()/strlen(): op sommige (met name oudere/goedkopere)
    // shared-hostingpakketten staat de inmiddels afgeraden PHP-instelling
    // "mbstring.func_overload" nog aan, die substr()/strlen() ongemerkt
    // multibyte-bewust maakt. Bij ruwe, binaire data (zoals hier, na
    // base64_decode()) kan dat de IV/versleutelde data verkeerd
    // uiteenhalen, met een corrupt ontsleuteld wachtwoord tot gevolg -
    // bijvoorbeeld een FTP-wachtwoord dat na opslaan niet meer klopt.
    // '8bit' dwingt overal gewoon byte-voor-byte gedrag af, ongeacht die
    // instelling.
    if ($data === false || mb_strlen($data, '8bit') <= 16) {
        return '';
    }

    $sleutel = hex2bin(VERSLEUTEL_SLEUTEL);
    $iv      = mb_substr($data, 0, 16, '8bit');
    $cijfertekst = mb_substr($data, 16, null, '8bit');

    $klareTekst = openssl_decrypt($cijfertekst, 'aes-256-cbc', $sleutel, OPENSSL_RAW_DATA, $iv);

    return $klareTekst !== false ? $klareTekst : '';
}
