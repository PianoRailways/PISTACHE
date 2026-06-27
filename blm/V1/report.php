<?php
// report.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = new SQLite3('rcs_portal.db');
    
    $station = $_POST['station'];
    $type = $_POST['type'];
    $reason = $_POST['reason'];
    $impact = $_POST['impact'];
    // Konvertierung des datetime-local Formats (T in Leerzeichen ersetzen)
    $start_time = str_replace('T', ' ', $_POST['start_time']);
    $end_time = str_replace('T', ' ', $_POST['end_time']);

    $stmt = $db->prepare('INSERT INTO disturbances (station, type, reason, impact, start_time, end_time) VALUES (:station, :type, :reason, :impact, :start_time, :end_time)');
    $stmt->bindValue(':station', $station, SQLITE3_TEXT);
    $stmt->bindValue(':type', $type, SQLITE3_TEXT);
    $stmt->bindValue(':reason', $reason, SQLITE3_TEXT);
    $stmt->bindValue(':impact', $impact, SQLITE3_TEXT);
    $stmt->bindValue(':start_time', $start_time, SQLITE3_TEXT);
    $stmt->bindValue(':end_time', $end_time, SQLITE3_TEXT);
    
    $stmt->execute();
    $message = "Meldung erfolgreich erfasst!";
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>RCS-Kundeninfo - Störung erfassen</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 20px; color: #333; }
        .form-container { max-width: 500px; background: white; padding: 25px; border-radius: 4px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h2 { margin-top: 0; color: #111; }
        label { display: block; margin-top: 15px; font-weight: bold; }
        input, select, textarea { width: 100%; padding: 8px; margin-top: 5px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 3px; }
        button { margin-top: 20px; background: #eb0000; color: white; border: none; padding: 10px 15px; font-weight: bold; cursor: pointer; width: 100%; border-radius: 3px; }
        button:hover { background: #c50000; }
        .msg { background: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border-radius: 3px; }
    </style>
</head>
<body>

<div class="form-container">
    <h2>RCS Störungsmeldung erfassen</h2>
    <?php if(isset($message)) echo "<div class='msg'>$message</div>"; ?>
    
    <form method="POST" action="report.php">
        <label for="station">Betroffener Bahnhof / Strecke:</label>
        <input type="text" id="station" name="station" required placeholder="z.B. Spiez - Interlaken Ost">

        <label for="type">Meldungstyp (Design):</label>
        <select id="type" name="type">
            <option value="strike">Fahrplanänderung / Streik (Rot)</option>
            <option value="construction">Bauarbeiten / Unterbruch (Blau)</option>
        </select>

        <label for="reason">Grund / Ursache:</label>
        <input type="text" id="reason" name="reason" required placeholder="z.B. Streik in Italien oder Weichenstörung">

        <label for="impact">Auswirkungen (optional):</label>
        <input type="text" id="impact" name="impact" placeholder="z.B. Verspätungen und Ausfälle zu erwarten">

        <label for="start_time">Gültig von:</label>
        <input type="datetime-local" id="start_time" name="start_time" required>

        <label for="end_time">Gültig bis:</label>
        <input type="datetime-local" id="end_time" name="end_time" required>

        <button type="submit">Meldung aktivieren</button>
    </form>
</div>

</body>
</html>