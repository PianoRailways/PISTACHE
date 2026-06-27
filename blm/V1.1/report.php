<?php
// report.php
$db = new SQLite3('rcs_portal.db');
$message = "";

// Für das Dropdown der zu aktualisierenden Meldungen (holt jeweils nur die aktuellste Version)
$existing_incidents = $db->query("
    SELECT d1.incident_id, d1.station_a, d1.station_b, d1.reason 
    FROM disturbances d1
    WHERE d1.id = (SELECT MAX(d2.id) FROM disturbances d2 WHERE d2.incident_id = d1.incident_id)
    ORDER BY d1.created_at DESC
");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Falls ein Update gemacht wird, behalten wir die incident_id, sonst generieren wir eine neue
    if (!empty($_POST['update_incident_id'])) {
        $incident_id = $_POST['update_incident_id'];
    } else {
        $incident_id = 'INC-' . uniqid();
    }

    $location_type = $_POST['location_type'];
    $station_a = $_POST['station_a'];
    $station_b = ($location_type === 'line') ? $_POST['station_b'] : null;
    $type = $_POST['type'];
    $status = $_POST['status'];
    $reason = $_POST['reason'];
    $impact = $_POST['impact'];
    $start_time = str_replace('T', ' ', $_POST['start_time']);
    $end_time = str_replace('T', ' ', $_POST['end_time']);
    $created_at = date('Y-m-d H:i:s');

    $stmt = $db->prepare('INSERT INTO disturbances (incident_id, location_type, station_a, station_b, type, status, reason, impact, start_time, end_time, created_at) VALUES (:incident_id, :location_type, :station_a, :station_b, :type, :status, :reason, :impact, :start_time, :end_time, :created_at)');
    
    $stmt->bindValue(':incident_id', $incident_id, SQLITE3_TEXT);
    $stmt->bindValue(':location_type', $location_type, SQLITE3_TEXT);
    $stmt->bindValue(':station_a', $station_a, SQLITE3_TEXT);
    $stmt->bindValue(':station_b', $station_b, SQLITE3_TEXT);
    $stmt->bindValue(':type', $type, SQLITE3_TEXT);
    $stmt->bindValue(':status', $status, SQLITE3_TEXT);
    $stmt->bindValue(':reason', $reason, SQLITE3_TEXT);
    $stmt->bindValue(':impact', $impact, SQLITE3_TEXT);
    $stmt->bindValue(':start_time', $start_time, SQLITE3_TEXT);
    $stmt->bindValue(':end_time', $end_time, SQLITE3_TEXT);
    $stmt->bindValue(':created_at', $created_at, SQLITE3_TEXT);
    
    $stmt->execute();
    $message = "Meldung gespeichert unter ID: " . $incident_id;
    
    // Seite neu laden, um Dropdown zu aktualisieren
    header("Refresh:2; url=report.php");
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>RCS-Kundeninfo - Verwaltung</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 20px; color: #333; }
        .form-container { max-width: 600px; background: white; padding: 25px; border-radius: 4px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin: 0 auto; }
        label { display: block; margin-top: 15px; font-weight: bold; }
        input, select, textarea { width: 100%; padding: 8px; margin-top: 5px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 3px; }
        button { margin-top: 25px; background: #222; color: white; border: none; padding: 12px; font-weight: bold; cursor: pointer; width: 100%; border-radius: 3px; }
        button:hover { background: #444; }
        .msg { background: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border-radius: 3px; }
        .row { display: flex; gap: 10px; }
        .row > div { flex: 1; }
    </style>
    <script>
        function toggleLocationFields() {
            var type = document.getElementById('location_type').value;
            document.getElementById('station_b_container').style.display = (type === 'line') ? 'block' : 'none';
        }
    </script>
</head>
<body>

<div class="form-container">
    <h2>RCS Störungsmanagement</h2>
    <?php if(!empty($message)) echo "<div class='msg'>$message</div>"; ?>
    
    <form method="POST" action="report.php">
        
        <label for="update_incident_id">Aktion:</label>
        <select id="update_incident_id" name="update_incident_id">
            <option value="">-- Neue Meldung erstellen --</option>
            <?php while($inc = $existing_incidents->fetchArray(SQLITE3_ASSOC)): ?>
                <option value="<?php echo $inc['incident_id']; ?>">
                    Update für: <?php echo htmlspecialchars($inc['station_a'] . ($inc['station_b'] ? ' - '.$inc['station_b'] : '') . ' ('.$inc['reason'].')'); ?>
                </option>
            <?php endwhile; ?>
        </select>

        <div class="row">
            <div>
                <label for="location_type">Ortstyp:</label>
                <select id="location_type" name="location_type" onchange="toggleLocationFields()">
                    <option value="station">Im Bahnhof</option>
                    <option value="line">Auf der Strecke</option>
                </select>
            </div>
            <div>
                <label for="type">Meldungstyp:</label>
                <select id="type" name="type">
                    <option value="info">Vorinformation (Blau)</option>
                    <option value="construction">Bauarbeiten (Orange)</option>
                    <option value="disruption">Störung (Rot)</option>
                </select>
            </div>
        </div>

        <div class="row">
            <div>
                <label for="station_a">Bahnhof A:</label>
                <input type="text" id="station_a" name="station_a" required placeholder="z.B. Spiez">
            </div>
            <div id="station_b_container" style="display:none;">
                <label for="station_b">Bahnhof B:</label>
                <input type="text" id="station_b" name="station_b" placeholder="z.B. Interlaken Ost">
            </div>
        </div>

        <label for="status">Betriebszustand:</label>
        <select id="status" name="status">
            <option value="restricted">eingeschränkt</option>
            <option value="interrupted">unterbrochen</option>
        </select>

        <label for="reason">Grund / Ursache:</label>
        <input type="text" id="reason" name="reason" required placeholder="z.B. Weichenstörung, Gleisbauarbeiten">

        <label for="impact">Auswirkungen (optional):</label>
        <input type="text" id="impact" name="impact" placeholder="z.B. Verspätungen und Zugausfälle">

        <div class="row">
            <div>
                <label for="start_time">Gültig von:</label>
                <input type="datetime-local" id="start_time" name="start_time" required>
            </div>
            <div>
                <label for="end_filter">Gültig bis:</label>
                <input type="datetime-local" id="end_time" name="end_time" required>
            </div>
        </div>

        <button type="submit">Meldung absenden / aktualisieren</button>
    </form>
</div>

</body>
</html>