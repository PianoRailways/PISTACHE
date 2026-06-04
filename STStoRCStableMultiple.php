<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Pfad zur Datenbank im Unterordner /dbs/
$db_file = __DIR__ . '/dbs/fahrplan.sqlite';

try {
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Datenbankfehler beim Verbinden: " . $e->getMessage());
}

// Tabellen-Strukturen sicherstellen
$db->exec("CREATE TABLE IF NOT EXISTS trains (
    id INTEGER PRIMARY KEY AUTOINCREMENT, 
    train_number INTEGER UNIQUE, 
    route_id TEXT,
    sts_zid TEXT,
    successor_sts_zid TEXT
)");

try {
    $db->exec("ALTER TABLE trains ADD COLUMN successor_sts_zid TEXT");
} catch (Exception $e) {
    // Ignorieren falls Spalte existiert
}

$db->exec("CREATE TABLE IF NOT EXISTS timetable (
    id INTEGER PRIMARY KEY AUTOINCREMENT, 
    train_id INTEGER, 
    station_id TEXT, 
    track TEXT, 
    arrival TEXT, 
    departure TEXT, 
    flags TEXT, 
    remarks TEXT, 
    UNIQUE(train_id, station_id)
)");

$message = "";
$preview_data = [];
$current_route = $_POST['route_id'] ?? 'sempachersee';
$sts_zids = [];
$successor_zids = [];

// SCHRITT 2: Endgültiges Speichern aus dem JSON-String
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_final') {
    $route_id = $_POST['route_id'] ?? '';
    $json_data = $_POST['json_records'] ?? '[]';
    $records = json_decode($json_data, true);
    $sts_zids = json_decode($_POST['sts_zids'] ?? '{}', true);
    $successor_zids = json_decode($_POST['successor_zids'] ?? '{}', true);

    if (!empty($records) && !empty($route_id)) {
        $db->beginTransaction();
        try {
            $trains_by_number = [];
            foreach ($records as $r) {
                $zugNum = intval($r['train_number'] ?? 0);
                if ($zugNum <= 0) continue;
                
                if (!isset($trains_by_number[$zugNum])) {
                    $trains_by_number[$zugNum] = [];
                }
                $trains_by_number[$zugNum][] = $r;
            }

            foreach ($trains_by_number as $zugNum => $stops) {
                $sts_zid = $sts_zids[$zugNum] ?? null;
                $successor_zid = $successor_zids[$zugNum] ?? null;
                
                $stmt = $db->prepare("
                    INSERT OR IGNORE INTO trains (train_number, route_id, sts_zid, successor_sts_zid) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$zugNum, $route_id, $sts_zid, $successor_zid]);

                $updates = [];
                $params = [];
                if ($sts_zid) {
                    $updates[] = "sts_zid = ?";
                    $params[] = $sts_zid;
                }
                if ($successor_zid) {
                    $updates[] = "successor_sts_zid = ?";
                    $params[] = $successor_zid;
                }
                
                if (!empty($updates)) {
                    $params[] = $zugNum;
                    $stmt = $db->prepare("UPDATE trains SET " . implode(", ", $updates) . " WHERE train_number = ?");
                    $stmt->execute($params);
                }

                $stmt = $db->prepare("SELECT id FROM trains WHERE train_number = ?");
                $stmt->execute([$zugNum]);
                $train_id = $stmt->fetchColumn();

                $stmt = $db->prepare("
                    INSERT INTO timetable (train_id, station_id, track, arrival, departure, flags, remarks) 
                    VALUES (?, ?, ?, ?, ?, ?, '')
                    ON CONFLICT(train_id, station_id) DO UPDATE SET
                        track = CASE WHEN excluded.track != '' THEN excluded.track ELSE timetable.track END,
                        arrival = CASE WHEN excluded.arrival != '' THEN excluded.arrival ELSE timetable.arrival END,
                        departure = CASE WHEN excluded.departure != '' THEN excluded.departure ELSE timetable.departure END,
                        flags = CASE WHEN excluded.flags != '' THEN excluded.flags ELSE timetable.flags END
                ");
                
                foreach ($stops as $r) {
                    $stmt->execute([
                        $train_id, 
                        $r['station_id'] ?? '', 
                        $r['track'] ?? '', 
                        $r['arrival'] ?? '', 
                        $r['departure'] ?? '', 
                        $r['flags'] ?? ''
                    ]);
                }
            }
            
            $db->commit();
            $message = "✓ Erfolgreich gespeichert! Die Daten aus allen Dateien wurden sauber in die Fahrpläne integriert.";
        } catch (Exception $e) {
            $db->rollBack();
            $message = "✗ Fehler beim Speichern: " . $e->getMessage();
        }
    }
}

// SCHRITT 1: Mehrere Dateien einlesen und Vorschau generieren
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_files') {
    if (!empty($_FILES['timetable_files']['name'][0])) {
        
        $train_zid_map = []; 
        $train_order = [];
        $total_files_parsed = 0;

        // Iteration über jede hochgeladene Datei
        foreach ($_FILES['timetable_files']['tmp_name'] as $index => $tmpName) {
            if ($_FILES['timetable_files']['error'][$index] !== UPLOAD_ERR_OK) {
                continue;
            }

            // Datei zeilenweise einlesen (lokal aus dem Temp-Speicher)
            $fileContent = file_get_contents($tmpName);
            $lines = explode("\n", str_replace("\r", "", trim($fileContent)));
            
            // Header-Zeile überspringen, falls vorhanden
            if (count($lines) > 0 && (strpos($lines[0], 'ZID') !== false || strpos($lines[0], 'Zug') !== false)) {
                array_shift($lines);
            }

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                $cols = preg_split('/(\t+|\s{2,})/', $line);
                $cols = array_filter($cols, function($c) { return $c !== ''; });
                $cols = array_values($cols);

                if (count($cols) < 5) continue;

                $zid = trim($cols[0]);
                $zugSpalte = trim($cols[2] ?? '');
                
                $vorProzent = explode('%', $zugSpalte)[0];
                preg_match_all('/\d+/', $vorProzent, $matches);
                $zugNum = !empty($matches[0]) ? intval(end($matches[0])) : 0;
                
                if ($zugNum <= 0) continue;

                if (!isset($sts_zids[$zugNum]) && !empty($zid) && $zid !== 'ZID') {
                    $sts_zids[$zugNum] = $zid;
                    $train_zid_map[$zugNum] = $zid;
                    if (!in_array($zugNum, $train_order)) {
                        $train_order[] = $zugNum;
                    }
                }

                $gleisSpalte = trim($cols[3] ?? '');
                if (empty($gleisSpalte) || strtolower($gleisSpalte) === 'keine') continue;

                $station_id = $gleisSpalte;
                $track = "";

                if (strpos($gleisSpalte, ' ') !== false) {
                    $parts = explode(' ', $gleisSpalte, 2);
                    $station_id = $parts[0];
                    $track = $parts[1];
                } elseif (preg_match('/^([A-Za-z]+)(\d+)$/', $gleisSpalte, $matches)) {
                    $station_id = $matches[1];
                    $track = $matches[2];
                }

                $arrival = trim($cols[4] ?? '');
                $departure = trim($cols[5] ?? '');

                $flags = '';
                if (count($cols) > 8) {
                    $flags = trim($cols[8]);
                }

                $preview_data[] = [
                    'zid' => $zid,
                    'train_number' => $zugNum,
                    'station_id' => $station_id,
                    'track' => $track,
                    'arrival' => $arrival,
                    'departure' => $departure,
                    'flags' => $flags,
                ];
            }
            $total_files_parsed++;
        }

        // Nachfolger-ZIDs über alle eingelesenen Züge hinweg ermitteln
        for ($i = 0; $i < count($train_order) - 1; $i++) {
            $current_train = $train_order[$i];
            $next_train = $train_order[$i + 1];
            
            if (isset($train_zid_map[$next_train])) {
                $successor_zids[$current_train] = $train_zid_map[$next_train];
            }
        }

        foreach ($preview_data as &$record) {
            $record['successor_zid'] = $successor_zids[$record['train_number']] ?? null;
        }

        if ($total_files_parsed > 0) {
            $message = "✓ $total_files_parsed Datei(en) erfolgreich analysiert. Bitte überprüfe die Vorschau unten.";
        } else {
            $message = "✗ Keine gültigen Dateien hochgeladen.";
        }
    } else {
        $message = "✗ Bitte wähle mindestens eine Datei aus.";
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Fahrplan-Multi-Datei-Import</title>
    <style>
        body { background: #121212; color: #e0e0e0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; padding: 20px; max-width: 1200px; margin: 0 auto; }
        h1, h2, h3 { color: #fff; }
        .file-dropzone { border: 2px dashed #444; padding: 30px; text-align: center; background: #1e1e1e; border-radius: 6px; cursor: pointer; margin-bottom: 15px; }
        .file-dropzone:hover { border-color: #2563eb; background: #222; }
        input[type="file"] { display: none; }
        input[type="text"] { background: #1e1e1e; color: #fff; border: 1px solid #333; padding: 10px; border-radius: 4px; font-size: 14px; }
        button { background: #2563eb; color: #fff; border: none; padding: 12px 24px; cursor: pointer; border-radius: 4px; font-weight: bold; font-size: 14px; }
        button:hover { background: #1d4ed8; }
        button.save { background: #16a34a; margin-top: 20px; font-size: 16px; padding: 14px 32px; }
        button.save:hover { background: #15803d; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; background: #1e1e1e; }
        th, td { border: 1px solid #333; padding: 12px; text-align: left; font-size: 13px; }
        th { background: #2d2d2d; color: #fff; font-weight: bold; }
        tr:hover { background: #252525; }
        .msg { padding: 15px; margin-bottom: 20px; border-radius: 4px; border: 1px solid #2e7d32; background: #0d2818; }
        .msg.error { background: #3d1818; border-color: #c53030; color: #ff6b6b; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: bold; margin-bottom: 8px; font-size: 14px; }
        .info { background: #1a2f4a; border: 1px solid #2563eb; padding: 12px; border-radius: 4px; margin-bottom: 20px; font-size: 13px; line-height: 1.5; }
        .stat { display: inline-block; background: #2d2d2d; padding: 8px 12px; border-radius: 4px; margin-right: 15px; font-weight: bold; color: #4ade80; }
        .file-list-info { margin-top: 10px; font-size: 13px; color: #aaa; font-style: italic; }
    </style>
</head>
<body>

    <h1>🚂 Fahrplan-Import (Multi-Datei-Upload)</h1>
    
    <?php if ($message): ?>
        <div class="msg <?= (strpos($message, '✗') === 0 || strpos($message, 'Fehler') !== false) ? 'error' : '' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="info">
        <strong>ℹ️ Funktionsweise:</strong> <br>
        Wähle eine oder mehrere Fahrplan-Textdateien gleichzeitig aus. Das Skript liest alle Daten nacheinander im Speicher ein und verknüpft die Züge chronologisch über alle Dateien hinweg für die Nachfolger-Berechnung. Es werden keine Dateien dauerhaft auf dem Server abgelegt.
    </div>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="process_files">
        
        <div class="form-group">
            <label for="route_id">Strecken-ID (für alle hochgeladenen Züge):</label>
            <input type="text" id="route_id" name="route_id" value="<?= htmlspecialchars($current_route) ?>" placeholder="z.B. sempachersee" required style="width: 300px;">
        </div>

        <div class="form-group">
            <label>Fahrplan-Dateien hochladen (Mehrfachauswahl möglich):</label>
            <div class="file-dropzone" onclick="document.getElementById('timetable_files').click();">
                <span>📂 Klicke hier, um eine oder mehrere Fahrplan-Dateien auszuwählen</span>
                <input type="file" id="timetable_files" name="timetable_files[]" multiple accept=".txt,.sqlite" onchange="updateFileList(this)">
                <div id="file_list" class="file-list-info">Keine Dateien ausgewählt</div>
            </div>
        </div>

        <button type="submit">📋 Dateien einlesen und Vorschau anzeigen</button>
    </form>

    <?php if (!empty($preview_data)): ?>
        <h2>Erkannte Fahrplandaten aus den Dateien</h2>
        
        <div>
            <span class="stat">✓ <?= count(array_unique(array_column($preview_data, 'train_number'))) ?> Züge</span>
            <span class="stat">✓ <?= count($preview_data) ?> Haltestellen</span>
            <span class="stat">✓ <?= count(array_filter($sts_zids)) ?> mit ZID</span>
        </div>
        
        <form method="POST">
            <input type="hidden" name="action" value="save_final">
            <input type="hidden" name="route_id" value="<?= htmlspecialchars($current_route) ?>">
            <input type="hidden" name="json_records" value="<?= htmlspecialchars(json_encode(array_values($preview_data))) ?>">
            <input type="hidden" name="sts_zids" value="<?= htmlspecialchars(json_encode($sts_zids)) ?>">
            <input type="hidden" name="successor_zids" value="<?= htmlspecialchars(json_encode($successor_zids)) ?>">
            
            <table>
                <thead>
                    <tr>
                        <th>ZID (sts_zid)</th>
                        <th>Nachfolger-ZID</th>
                        <th>Zugnummer</th>
                        <th>Station</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $shown_trains = [];
                    foreach ($preview_data as $row): 
                        $train_num = $row['train_number'];
                        if (in_array($train_num, $shown_trains)) {
                            continue;
                        }
                        $shown_trains[] = $train_num;
                    ?>
                        <tr style="background: #2a2a2a; font-weight: bold;">
                            <td><strong><?= htmlspecialchars($row['zid'] ?? '-') ?></strong></td>
                            <td><strong style="color: #4ade80;"><?= htmlspecialchars($row['successor_zid'] ?? '-') ?></strong></td>
                            <td><?= htmlspecialchars($row['train_number']) ?></td>
                            <td colspan="2">← Gefunden mit <?= count(array_filter($preview_data, fn($r) => $r['train_number'] == $train_num)) ?> Haltestellen</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <button type="submit" class="save">💾 Diese Daten in der Datenbank speichern</button>
        </form>
    <?php endif; ?>

    <script>
    // Zeigt dem User an, wie viele und welche Dateien ausgewählt wurden
    function updateFileList(input) {
        const fileListDiv = document.getElementById('file_list');
        if (input.files.length > 0) {
            const fileNames = Array.from(input.files).map(f => f.name).join(', ');
            fileListDiv.textContent = `Ausgewählt (${input.files.length}): ${fileNames}`;
            fileListDiv.style.color = '#4ade80';
        } else {
            fileListDiv.textContent = 'Keine Dateien ausgewählt';
            fileListDiv.style.color = '#aaa';
        }
    }
    </script>

</body>
</html>