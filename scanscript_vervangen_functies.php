<?php
// scanscript_vervangen_functies.php
//
// Vervangt het scanscript-bestand van een site door een nieuw, uniek
// gegenereerd exemplaar (zie genereerUniekeScanBestandsnaam() in
// instellingen_functies.php) - het nieuwe bestand wordt eerst geplaatst,
// en pas ná een geslaagde plaatsing wordt het oude bestand (of de oude
// bestanden, zie site_scanscript_geschiedenis) opgeruimd. Vereist FTP-/
// SFTP-gegevens bij de site; zonder die gegevens kan dit niet automatisch,
// aangezien er geen andere manier is om een NIEUW bestand voor de eerste
// keer op de site te plaatsen (zie ook de toelichting op de helppagina
// over het zelf-bijwerkende scanscript, dat wél zonder FTP kan, maar
// alleen voor een bestand dat er al staat).

require_once __DIR__ . '/genereer_scanscript_functies.php';
require_once __DIR__ . '/sftp_functies.php';
require_once __DIR__ . '/instellingen_functies.php';

/**
 * @return array{succes: bool, melding: string, nieuwe_naam: ?string}
 */
function vervangScanscriptDoorUniekeNaam(PDO $pdo, array $site): array
{
    $domein = $site['domein'];

    if (empty($site['ftp_host'])) {
        return [
            'succes' => false,
            'melding' => "$domein: geen FTP-/SFTP-gegevens bekend bij deze site - kan niet automatisch vervangen worden. Vul eerst FTP-/SFTP-gegevens in bij Site-instellingen.",
            'nieuwe_naam' => null,
        ];
    }

    try {
        $nieuweInhoud = genereerScanScriptInhoud($pdo, $site);
    } catch (RuntimeException $e) {
        return ['succes' => false, 'melding' => "$domein: kon scanscript niet genereren - " . $e->getMessage(), 'nieuwe_naam' => null];
    }

    $nieuweNaam = genereerUniekeScanBestandsnaam($pdo);
    $pad = trim($site['ftp_pad'] ?? '/', '/');
    $protocol = ($site['ftp_protocol'] ?? 'ftp') === 'sftp' ? 'sftp' : 'ftp';

    // Alle ooit gebruikte bestandsnamen verzamelen, zodat we straks alles
    // wat nog van vóór deze vervanging dateert kunnen opruimen - dezelfde
    // aanpak als bij het volledig verwijderen van een site.
    $oudeNamen = [];
    $geschiedenisStmt = $pdo->prepare("SELECT bestandsnaam FROM site_scanscript_geschiedenis WHERE site_id = ?");
    $geschiedenisStmt->execute([$site['id']]);
    foreach ($geschiedenisStmt->fetchAll(PDO::FETCH_COLUMN) as $naam) {
        $oudeNamen[$naam] = true;
    }
    $oudeNamen[bepaalScanBestandsnaam($site)] = true;
    unset($oudeNamen[$nieuweNaam]); // voor de zekerheid, kan niet echt voorkomen
    $oudeNamen = array_keys($oudeNamen);

    $uploadGelukt = false;

    if ($protocol === 'sftp') {
        $host       = $site['ftp_host'];
        $poort      = !empty($site['ftp_poort']) ? (int) $site['ftp_poort'] : 22;
        $gebruiker  = $site['ftp_gebruikersnaam'] ?? '';
        $wachtwoord = ontsleutelWaarde($site['ftp_wachtwoord'] ?? '');

        [$sftp, $foutmelding] = sftpVerbind($host, $poort, $gebruiker, $wachtwoord);
        if ($sftp === null) {
            return ['succes' => false, 'melding' => "$domein: SFTP-verbinding mislukt - $foutmelding", 'nieuwe_naam' => null];
        }

        $nieuwPad = $pad !== '' ? "/$pad/$nieuweNaam" : "/$nieuweNaam";
        $uploadGelukt = sftpUploadInhoud($sftp, $nieuwPad, $nieuweInhoud);

        if ($uploadGelukt) {
            foreach ($oudeNamen as $oudeNaam) {
                $oudPad = $pad !== '' ? "/$pad/$oudeNaam" : "/$oudeNaam";
                if (@$sftp->file_exists($oudPad)) {
                    @$sftp->delete($oudPad, false);
                }
            }
        }
    } else {
        $host       = $site['ftp_host'];
        $poort      = !empty($site['ftp_poort']) ? (int) $site['ftp_poort'] : 21;
        $gebruiker  = $site['ftp_gebruikersnaam'] ?? '';
        $wachtwoord = ontsleutelWaarde($site['ftp_wachtwoord'] ?? '');
        $gebruikSsl = !empty($site['ftp_ssl']);

        $conn = $gebruikSsl
            ? @ftp_ssl_connect($host, $poort, 10)
            : @ftp_connect($host, $poort, 10);

        if ($conn === false) {
            return ['succes' => false, 'melding' => "$domein: kon geen FTP-verbinding maken met \"$host:$poort\".", 'nieuwe_naam' => null];
        }
        if (!@ftp_login($conn, $gebruiker, $wachtwoord)) {
            ftp_close($conn);
            return ['succes' => false, 'melding' => "$domein: FTP-inloggen mislukt (controleer gebruikersnaam/wachtwoord).", 'nieuwe_naam' => null];
        }

        ftp_pasv($conn, true);
        $nieuwPad = $pad !== '' ? "/$pad/$nieuweNaam" : "/$nieuweNaam";

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $nieuweInhoud);
        rewind($stream);
        $uploadGelukt = @ftp_fput($conn, $nieuwPad, $stream, FTP_BINARY);
        fclose($stream);

        if ($uploadGelukt) {
            foreach ($oudeNamen as $oudeNaam) {
                $oudPad = $pad !== '' ? "/$pad/$oudeNaam" : "/$oudeNaam";
                @ftp_delete($conn, $oudPad);
            }
        }

        ftp_close($conn);
    }

    if (!$uploadGelukt) {
        return ['succes' => false, 'melding' => "$domein: uploaden van het nieuwe scanscript-bestand is mislukt (controleer het ingestelde pad).", 'nieuwe_naam' => null];
    }

    // Pas NA een geslaagde plaatsing de database bijwerken - zo blijft de
    // oude, werkende situatie intact als er iets misgaat.
    $pdo->prepare("UPDATE sites SET scan_bestandsnaam = ? WHERE id = ?")->execute([$nieuweNaam, $site['id']]);
    registreerVerstuurdScanBestand($pdo, (int) $site['id'], $nieuweNaam);

    return [
        'succes' => true,
        'melding' => "$domein: scanscript vervangen door een nieuwe, unieke naam ($nieuweNaam). Oude bestand(en) opgeruimd.",
        'nieuwe_naam' => $nieuweNaam,
    ];
}
