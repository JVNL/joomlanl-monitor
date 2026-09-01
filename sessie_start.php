<?php
/**
 * sessie_start.php
 *
 * Centrale sessieconfiguratie. Zet expliciet Secure/HttpOnly/SameSite op de
 * sessiecookie (in plaats van op PHP's standaardinstellingen te vertrouwen)
 * en start daarna de sessie. Elk bestand dat session_start() nodig heeft,
 * gebruikt voortaan `require_once 'sessie_start.php';` in plaats van
 * session_start() rechtstreeks aan te roepen.
 */

if (session_status() === PHP_SESSION_NONE) {

    $isHttps = !empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,        // sessiecookie: verloopt bij sluiten browser
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps, // alleen over HTTPS versturen (indien actief)
        'httponly' => true,     // niet uitleesbaar via JavaScript
        'samesite' => 'Lax',    // beperkt cross-site meesturen van de cookie
    ]);

    session_start();
}
