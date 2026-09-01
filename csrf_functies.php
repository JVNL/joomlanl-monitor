<?php
/**
 * csrf_functies.php
 *
 * Simpele CSRF-bescherming: bij het inloggen (of eerste gebruik) wordt een
 * willekeurig token in de sessie gezet. Elk formulier stuurt dat token mee;
 * bij het verwerken van de POST wordt gecontroleerd of het meegestuurde
 * token overeenkomt met dat in de sessie. Zo niet, dan wordt de actie
 * geweigerd - dit voorkomt dat een andere website een ingelogde beheerder
 * kan verleiden tot een ongewenste actie op de monitor.
 */

require_once __DIR__ . '/sessie_start.php';

/**
 * Geeft het huidige CSRF-token terug, en genereert er één als dat nog niet
 * bestaat in de sessie.
 */
function haalCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Geeft kant-en-klare HTML voor een verborgen invoerveld met het huidige
 * CSRF-token, te gebruiken in elk <form method="post">.
 */
function csrfVeld(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(haalCsrfToken()) . '">';
}

/**
 * Controleert of het meegestuurde CSRF-token (uit $_POST) overeenkomt met
 * dat in de sessie. Geeft true/false terug - de aanroeper bepaalt zelf hoe
 * te reageren (plain-text afbreken, of een JSON-foutmelding, al naar
 * gelang het soort endpoint).
 */
function csrfTokenGeldig(): bool
{
    $verwacht    = $_SESSION['csrf_token'] ?? null;
    $meegegeven  = $_POST['csrf_token'] ?? null;

    return $verwacht !== null && $meegegeven !== null && hash_equals($verwacht, (string) $meegegeven);
}

/**
 * Voor gewone formulierpagina's: breekt af met een duidelijke melding als
 * het token niet klopt.
 */
function vereistGeldigCsrfToken(): void
{
    if (!csrfTokenGeldig()) {
        http_response_code(403);
        die('Ongeldige aanvraag (CSRF-token klopt niet of is verlopen). Ververs de pagina en probeer het opnieuw.');
    }
}
