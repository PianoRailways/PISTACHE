<?php
// Konfiguration
$richtigesPasswort = 'controlsystem';
$datenbankOrdner = __DIR__ . '/dbs';
$aktuellerDbName = 'fahrplan.sqlite'; // Name der Live-Datenbank

// 1. Passwort-Prüfung
$passwort = $_GET['pw'] ?? '';

if ($passwort !== $richtigesPasswort) {
    // Rote Fehlermeldung bei falschem oder fehlendem Passwort
    header('HTTP/1.1 403 Forbidden');
    echo '<div style="color: #cc0000; font-family: sans-serif; font-weight: bold; padding: 20px; border: 1px solid #cc0000; background-color: #fff0f0; border-radius: 5px; margin: 20px auto; max-width: 600px;">';
    echo 'Zugriff verweigert. Ungültiges oder fehlendes Passwort.';
    echo '</div>';
    exit;
}

$vollstaendigerPfad = $datenbankOrdner . '/' . $aktuellerDbName;
$meldung = '';
$meldungsTyp = 'info'; // 'info', 'success' oder 'error'

// 2. Aktion verarbeiten (Button wurde gedrückt)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'neues_spiel') {
    if (file_exists($vollstaendigerPfad)) {
        // Zeitstempel generieren (Format: YYYY-MM-DD_HH-MM-SS)
        $zeitstempel = date('Y-m-d_H-i-s');
        $neuerDbName = 'spiel_archiv_' . $zeitstempel . '.sqlite';
        $neuerPfad = $datenbankOrdner . '/' . $neuerDbName;

        // Ordnerrechte prüfen und umbenennen
        if (rename($vollstaendigerPfad, $neuerPfad)) {
            $meldung = "Das alte Spiel wurde erfolgreich archiviert unter: <strong>" . htmlspecialchars($neuerDbName) . "</strong>. Ein neues Spiel kann jetzt starten.";
            $meldungsTyp = 'success';
        } else {
            $meldung = "Fehler: Die Datenbank konnte nicht umbenannt werden. Bitte Schreibrechte des Ordners prüfen.";
            $meldungsTyp = 'error';
        }
    } else {
        $meldung = "Es existiert aktuell keine aktive Datenbank (<strong>" . htmlspecialchars($aktuellerDbName) . "</strong>), die umbenannt werden könnte.";
        $meldungsTyp = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>System Setup</title>
    <style>
        body { font-family: sans-serif; background-color: #f4f6f9; color: #333; padding: 40px; }
        .container { max-width: 600px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        h1 { font-size: 20px; margin-top: 0; color: #222; border-bottom: 2px solid #eaeaea; padding-bottom: 10px; }
        .status-box { padding: 15px; border-radius: 4px; margin-bottom: 20px; font-size: 14px; background-color: #f0f4f8; border-left: 4px solid #1070ca; }
        .btn { display: inline-block; background-color: #d93d3d; color: white; border: none; padding: 12px 20px; font-size: 15px; font-weight: bold; border-radius: 4px; cursor: pointer; width: 100%; text-align: center; box-sizing: border-box; }
        .btn:hover { background-color: #b82e2e; }
        .alert-success { background-color: #eaf8f0; border-left: 4px solid #1fad66; color: #0e5a34; }
        .alert-error { background-color: #fff0f0; border-left: 4px solid #cc0000; color: #7d0000; }
    </style>
</head>
<body>

<div class="container">
    <h1>Systemsteuerung &amp; Spiel-Setup</h1>

    <?php if ($meldung): ?>
        <div class="status-box alert-<?php echo $meldungsTyp; ?>">
            <?php echo $meldung; ?>
        </div>
    <?php endif; ?>

    <div class="status-box">
        <strong>Aktueller Status:</strong><br>
        <?php if (file_exists($vollstaendigerPfad)): ?>
            Die Live-Datenbank (<code><?php echo htmlspecialchars($aktuellerDbName); ?></code>) ist aktiv und bereit.
        <?php else: ?>
            Keine aktive Live-Datenbank im Verzeichnis gefunden. (Wartet vermutlich auf Initialisierung durch das Hauptskript).
        <?php endif; ?>
    </div>

    <?php if (file_exists($vollstaendigerPfad)): ?>
        <form action="?pw=<?php echo urlencode($passwort); ?>" method="POST">
            <input type="hidden" name="action" value="neues_spiel">
            <button type="submit" class="btn">Neues Spiel starten (frische Datenbank starten)</button>
        </form>
    <?php endif; ?>
</div>

</body>
</html>