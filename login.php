<?php
require_once 'sessie_start.php';

// Is de installatiewizard nog niet doorlopen (config.php bestaat dan nog
// niet)? Stuur dan automatisch door naar installeer.php, in plaats van
// hier vast te lopen op een "config.php niet gevonden"-fout (HTTP 500) -
// dat gebeurt anders al bij de allereerste keer dat iemand de zojuist
// geplaatste bestanden in de browser opent.
if (!file_exists(__DIR__ . '/config.php')) {
    if (file_exists(__DIR__ . '/installeer.php')) {
        header('Location: installeer.php');
        exit;
    }
    http_response_code(500);
    die('config.php ontbreekt, en installeer.php is niet gevonden - upload eerst alle bestanden van de monitor (inclusief installeer.php) naar deze map.');
}

require_once 'config.php';
require_once 'instellingen_functies.php';

$programmaNaam = trim(haalInstelling($pdo, 'email_afzendernaam', '')) ?: 'Mijn Websites Monitor';

$gebruikersnaam = haalInstelling($pdo, 'login_gebruikersnaam', 'admin');
$wachtwoord = ontsleutelWaarde(haalInstelling($pdo, 'login_wachtwoord', ''));

$fout = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    if (
        $_POST['gebruikersnaam'] === $gebruikersnaam &&
        $_POST['wachtwoord'] === $wachtwoord
    ) {

        $_SESSION['ingelogd'] = true;

        header("Location: index.php");
        exit;

    } else {

        $fout = "Onjuiste gebruikersnaam of wachtwoord.";
    }
}
?>

<!DOCTYPE html>

<html>
<head>
<meta charset="utf-8">
<script>
// Voorkeur voor licht/donker zo vroeg mogelijk toepassen (vóór de rest van
// de pagina rendert), zodat er geen flits van het verkeerde thema is.
(function () {
    var voorkeur = localStorage.getItem('thema_voorkeur');
    if (voorkeur === 'licht' || voorkeur === 'donker') {
        document.documentElement.setAttribute('data-thema', voorkeur);
    }
})();
</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php include 'favicon_tags.php'; ?>
<title><?php echo htmlspecialchars($programmaNaam); ?> - Inloggen</title>

<style>

body {
    font-family: Arial, sans-serif;
    background: #f5f5f5;
    margin: 0;
}

.loginbox {
    width: 400px;
    max-width: calc(100% - 40px);
    margin: 80px auto;
    background: white;
    padding: 25px;
    border-radius: 8px;
    box-shadow: 0 0 10px rgba(0,0,0,0.15);
    box-sizing: border-box;
}

.login-logo {
    display: block;
    width: 110px;
    height: 110px;
    margin: 0 auto 15px auto;
}

input {
    width: 100%;
    padding: 10px;
    box-sizing: border-box;
}

button, input[type="submit"] {
    padding: 10px 16px;
    background: #1f6fa8;
    color: #fff;
    border: none;
    border-radius: 4px;
    font-size: 14px;
    cursor: pointer;
}

button:hover, input[type="submit"]:hover {
    background: #175a87;
}

.inloggen {
    width: 100%;
    margin-top: 15px;
}

.wachtwoord-veld {
    position: relative;
}

.wachtwoord-veld input {
    padding-right: 40px;
}

.wachtwoord-veld .oogje {
    position: absolute;
    right: 4px;
    top: 50%;
    transform: translateY(-50%);
    background: #000;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 15px;
    padding: 6px 8px;
}

.fout {
    color: red;
    margin-bottom: 15px;
}

@media (max-width: 480px) {
    .loginbox {
        margin: 30px auto;
        padding: 20px;
    }
}

</style>

<?php include 'responsive_stijlen.php'; ?>
</head>
<body>

<div class="loginbox">

<img class="login-logo" src="<?php echo htmlspecialchars(huidigLogoPad(haalAlleInstellingen($pdo))); ?>" alt="<?php echo htmlspecialchars($programmaNaam); ?>">
<h2 style="text-align: center;"><?php echo htmlspecialchars($programmaNaam); ?></h2>

<?php
if ($fout != "") {
    echo "<div class='fout'>$fout</div>";
}
?>

<form method="post">

Gebruikersnaam:<br><br>

<input
 type="text"
 name="gebruikersnaam">

<br><br>

Wachtwoord:<br><br>

<div class="wachtwoord-veld">
<input
 type="password"
 name="wachtwoord"
 id="wachtwoord">
<button type="button" class="oogje" onclick="toonWachtwoord()"><span class="icoon-glyph">👁️</span></button>
</div>

<br><br>

<input
 class="inloggen"
 type="submit"
 value="Inloggen">

</form>

</div>

<script>

function toonWachtwoord() {

    var veld =
        document.getElementById('wachtwoord');

    var knop = document.querySelector('.wachtwoord-veld .oogje');

    if (veld.type === 'password') {

        veld.type = 'text';
        knop.innerHTML = '<span class="icoon-glyph">🙈</span>';

    } else {

        veld.type = 'password';
        knop.innerHTML = '<span class="icoon-glyph">👁️</span>';

    }
}

</script>

<?php include 'terug_naar_boven.php'; ?>
</body>
</html>
