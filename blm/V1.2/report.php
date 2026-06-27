<?php
// report.php
$db = new SQLite3('rcs_portal.db');
$message = "";

// Vorausberechnung der Standardzeiten (Jetzt und Jetzt + 3 Stunden)
$default_start = date('Y-m-d\TH:i');
$default_end = date('Y-m-d\TH:i', strtotime('+3 hours'));

// Alle Meldungen für das JavaScript-Daten-Array und das Dropdown holen (jeweils neueste Version)
$incidents_res = $db->query("
    SELECT d1.* FROM disturbances d1
    WHERE d1.id = (SELECT MAX(d2.id) FROM disturbances d2 WHERE d2.incident_id = d1.incident_id)
    ORDER BY d1.created_at DESC
");

$js_data = [];
$dropdown_options = [];

while ($row = $incidents_res->fetchArray(SQLITE3_ASSOC)) {
    // Zeiten für das HTML-Format datetime-local vorbereiten (Leerzeichen zu T)
    $row['start_time_html'] = str_replace(' ', 'T', $row['start_time']);
    $row['end_time_html'] = str_replace(' ', 'T', $row['end_time']);
    $js_data[$row['incident_id']] = $row;
    $dropdown_options[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['update_incident_id'])) {
        $incident_id = $_POST['update_incident_id'];
    } else {
        $incident_id = 'INC-' . uniqid();
    }

    $location_type = $_POST['location_type'];
    $station_a = $_POST['station_a'];
    $station_b = ($location_type === 'line') ? $_POST['station_b'] : null;
    $lines_affected = !empty($_POST['lines_affected']) ? $_POST['lines_affected'] : null;
    $type = $_POST['type'];
    $status = $_POST['status'];
    $reason = $_POST['reason'];
    $impact = $_POST['impact'];
    
    // Falls leer gelassen, Fallback auf berechnete Standardwerte
    $start_time = !empty($_POST['start_time']) ? $_POST['start_time'] : $default_start;
    $end_time = !empty($_POST['end_time']) ? $_POST['end_time'] : $default_end;
    
    $start_time = str_replace('T', ' ', $start_time);
    $end_time = str_replace('T', ' ', $end_time);
    $created_at = date('Y-m-d H:i:s');

    $stmt = $db->prepare('INSERT INTO disturbances (incident_id, location_type, station_a, station_b, lines_affected, type, status, reason, impact, start_time, end_time, created_at) VALUES (:incident_id, :location_type, :station_a, :station_b, :lines_affected, :type, :status, :reason, :impact, :start_time, :end_time, :created_at)');
    
    $stmt->bindValue(':incident_id', $incident_id, SQLITE3_TEXT);
    $stmt->bindValue(':location_type', $location_type, SQLITE3_TEXT);
    $stmt->bindValue(':station_a', $station_a, SQLITE3_TEXT);
    $stmt->bindValue(':station_b', $station_b, SQLITE3_TEXT);
    $stmt->bindValue(':lines_affected', $lines_affected, SQLITE3_TEXT);
    $stmt->bindValue(':type', $type, SQLITE3_TEXT);
    $stmt->bindValue(':status', $status, SQLITE3_TEXT);
    $stmt->bindValue(':reason', $reason, SQLITE3_TEXT);
    $stmt->bindValue(':impact', $impact, SQLITE3_TEXT);
    $stmt->bindValue(':start_time', $start_time, SQLITE3_TEXT);
    $stmt->bindValue(':end_time', $end_time, SQLITE3_TEXT);
    $stmt->bindValue(':created_at', $created_at, SQLITE3_TEXT);
    
    $stmt->execute();
    $message = "Meldung erfolgreich eingepflegt unter ID: " . $incident_id;
    header("Refresh:1; url=report.php");
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
        // Übergabe der PHP-Datenstrukturen an JavaScript
        const incidentData = <?php echo json_encode($js_data); ?>;
        const defaultStart = "<?php echo $default_start; ?>";
        const defaultEnd = "<?php echo $default_end; ?>";

        function loadIncidentDetails() {
            const id = document.getElementById('update_incident_id').value;
            
            if(id && incidentData[id]) {
                const data = incidentData[id];
                document.getElementById('location_type').value = data.location_type;
                document.getElementById('type').value = data.type;
                document.getElementById('station_a').value = data.station_a;
                document.getElementById('station_b').value = data.station_b ? data.station_b : '';
                document.getElementById('lines_affected').value = data.lines_affected ? data.lines_affected : '';
                document.getElementById('status').value = data.status;
                document.getElementById('reason').value = data.reason;
                document.getElementById('impact').value = data.impact ? data.impact : '';
                document.getElementById('start_time').value = data.start_time_html;
                document.getElementById('end_time').value = data.end_time_html;
            } else {
                // Formular zurücksetzen für neue Meldung
                document.getElementById('location_type').value = 'station';
                document.getElementById('type').value = 'info';
                document.getElementById('station_a').value = '';
                document.getElementById('station_b').value = '';
                document.getElementById('lines_affected').value = '';
                document.getElementById('status').value = 'restricted';
                document.getElementById('reason').value = '';
                document.getElementById('impact').value = '';
                document.getElementById('start_time').value = defaultStart;
                document.getElementById('end_time').value = defaultEnd;
            }
            toggleLocationFields();
        }

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
        
        <label for="update_incident_id">Meldung bearbeiten / aktualisieren:</label>
        <select id="update_incident_id" name="update_incident_id" onchange="loadIncidentDetails()">
            <option value="">-- Neue Meldung erstellen --</option>
            <?php foreach($dropdown_options as $inc): ?>
                <option value="<?php echo $inc['incident_id']; ?>">
                    [<?php echo strtoupper($inc['type']); ?>] <?php echo htmlspecialchars($inc['station_a'] . ($inc['station_b'] ? ' - '.$inc['station_b'] : '')); ?> (<?php echo htmlspecialchars($inc['reason']); ?>)
                </option>
            <?php endforeach; ?>
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
                <input type="text" id="station_a" name="station_a" required>
            </div>
            <div id="station_b_container" style="display:none;">
                <label for="station_b">Bahnhof B:</label>
                <input type="text" id="station_b" name="station_b">
            </div>
        </div>

        <label for="lines_affected">Betroffene Linien (optional):</label>
        <input type="text" id="lines_affected" name="lines_affected" placeholder="z.B. S1, S3, IR65">

        <label for="status">Betriebszustand:</label>
        <select id="status" name="status">
            <option value="restricted">eingeschränkt</option>
            <option value="interrupted">unterbrochen</option>
        </select>

        <label for="reason">Grund / Ursache:</label>
        <input type="text" id="reason" name="reason" required>

        <label for="impact">Auswirkungen (optional):</label>
        <input type="text" id="impact" name="impact">

        <div class="row">
            <div>
                <label for="start_time">Gültig von (leer lassen für "Jetzt"):</label>
                <input type="datetime-local" id="start_time" name="start_time" value="<?php echo $default_start; ?>">
            </div>
            <div>
                <label for="end_time">Gültig bis (leer lassen für "+3 Std"):</label>
                <input type="datetime-local" id="end_time" name="end_time" value="<?php echo $default_end; ?>">
            </div>
        </div>

        <button type="submit">Meldung absenden / Version speichern</button>
    </form>
</div>

</body>
</html>