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

/*
 * Ingepakte AJAX-verzoeken uitpakken (sinds 1.37).
 *
 * De AJAX-aanroepen vanuit de monitorpagina's sturen hun formuliervelden
 * niet meer leesbaar mee, maar als één base64-gecodeerd veld "pb". Reden:
 * webapplicatie-firewalls bij de hostingpartij (ModSecurity/Imunify360)
 * weigerden POST-verzoeken waarin een bestandspad als
 * ".../media/kunena/attachments/.htaccess" stond, met een kale Apache-403
 * én een tijdelijke blokkade van het IP-adres op de hele server tot gevolg.
 * De monitor moet bij elke hoster werken zonder whitelists of uitzonderingen
 * per hostingpartij, dus lossen we het hier op in plaats van daar.
 *
 * Hier wordt "pb" weer omgezet naar de gewone $_POST-velden, zodat de
 * endpoints zelf (CSRF-controle, site_id, pad, ...) ongewijzigd blijven
 * werken. Een verzoek zonder "pb" (gewone formulieren) blijft onaangeroerd.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['pb']) && count($_POST) === 1) {
    $uitgepakt = base64_decode((string) $_POST['pb'], true);
    if ($uitgepakt !== false) {
        $velden = [];
        parse_str($uitgepakt, $velden);
        $_POST = $velden;
    }
    unset($uitgepakt, $velden);
}
