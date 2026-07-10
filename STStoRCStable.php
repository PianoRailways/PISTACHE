<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// NEUE STRUKTUR: sequence_index für Mehrfach-Halte an gleicher Station
$db->exec("CREATE TABLE IF NOT EXISTS timetable (
    id INTEGER PRIMARY KEY AUTOINCREMENT, 
    train_id INTEGER NOT NULL, 
    station_id TEXT NOT NULL, 
    sequence_index INTEGER NOT NULL DEFAULT 0,
    track TEXT, 
    arrival TEXT, 
    departure TEXT, 
    flags TEXT, 
    remarks TEXT, 
    FOREIGN KEY(train_id) REFERENCES trains(id) ON DELETE CASCADE,
    UNIQUE(train_id, station_id, sequence_index)
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
            // Gruppiere Records nach Zug und Station (um sequence_index zu vergeben)
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
                
                // Füge Zug ein oder ignoriere, falls bereits vorhanden
                $stmt = $db->prepare("
                    INSERT OR IGNORE INTO trains (train_number, route_id, sts_zid, successor_sts_zid) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$zugNum, $route_id, $sts_zid, $successor_zid]);

                // Update ZIDs, falls Zug bereits existierte
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

                // Hole Train-ID
                $stmt = $db->prepare("SELECT id FROM trains WHERE train_number = ?");
                $stmt->execute([$zugNum]);
                $train_id = $stmt->fetchColumn();

                // Gruppiere Stops nach Station für sequence_index
                $stopsByStation = [];
                foreach ($stops as $r) {
                    $sid = $r['station_id'] ?? '';
                    if (empty($sid)) continue;
                    
                    if (!isset($stopsByStation[$sid])) {
                        $stopsByStation[$sid] = [];
                    }
                    $stopsByStation[$sid][] = $r;
                }

                // Speichere jeden Stop mit aufsteigendem sequence_index
                $stmt = $db->prepare("
                    INSERT INTO timetable (train_id, station_id, sequence_index, track, arrival, departure, flags, remarks) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, '')
                    ON CONFLICT(train_id, station_id, sequence_index) DO UPDATE SET
                        track = CASE WHEN excluded.track != '' THEN excluded.track ELSE timetable.track END,
                        arrival = CASE WHEN excluded.arrival != '' THEN excluded.arrival ELSE timetable.arrival END,
                        departure = CASE WHEN excluded.departure != '' THEN excluded.departure ELSE timetable.departure END,
                        flags = CASE WHEN excluded.flags != '' THEN excluded.flags ELSE timetable.flags END
                ");
                
                foreach ($stopsByStation as $stId => $stationStops) {
                    foreach ($stationStops as $idx => $r) {
                        $stmt->execute([
                            $train_id, 
                            $r['station_id'] ?? '', 
                            $idx,  // sequence_index
                            $r['track'] ?? '', 
                            $r['arrival'] ?? '', 
                            $r['departure'] ?? '', 
                            $r['flags'] ?? ''
                        ]);
                    }
                }
            }
            
            $db->commit();
            $message = "✓ Erfolgreich gespeichert! Die Daten wurden sauber in die Fahrpläne integriert. Mehrfach-Halte an gleicher Station sind jetzt unterstützt.";
        } catch (Exception $e) {
            $db->rollBack();
            $message = "✗ Fehler beim Speichern: " . $e->getMessage();
        }
    }
}

// SCHRITT 1: Vorschau generieren
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_preview') {
    $rawInput = $_POST['sts_data'] ?? '';
    $lines = explode("\n", str_replace("\r", "", trim($rawInput)));
    
    // Überspringe die Header-Zeile
    if (count($lines) > 0 && (strpos($lines[0], 'ZID') !== false || strpos($lines[0], 'Zug') !== false)) {
        array_shift($lines);
    }

    $sts_zids = []; 
    $train_zid_map = []; 
    $train_order = []; 

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        // Teile nach Tabs oder mehreren Leerzeichen
        $cols = preg_split('/(\t+|\s{2,})/', $line);
        $cols = array_filter($cols, function($c) { return $c !== ''; });
        $cols = array_values($cols);

        if (count($cols) < 5) continue;

        // SPALTE 0: ZID
        $zid = trim($cols[0]);
        
        // SPALTE 2: Zug-Spalte
        $zugSpalte = trim($cols[2] ?? '');
        
        // Extrahiere Zugnummer
        $vorProzent = explode('%', $zugSpalte)[0];
        preg_match_all('/\d+/', $vorProzent, $matches);
        $zugNum = !empty($matches[0]) ? intval(end($matches[0])) : 0;
        
        if ($zugNum <= 0) continue;

        // Speichere die ZID für diesen Zug
        if (!isset($sts_zids[$zugNum]) && !empty($zid) && $zid !== 'ZID') {
            $sts_zids[$zugNum] = $zid;
            $train_zid_map[$zugNum] = $zid;
            if (!in_array($zugNum, $train_order)) {
                $train_order[] = $zugNum;
            }
        }

        // SPALTE 3: Station/Gleis
        $gleisSpalte = trim($cols[3] ?? '');
        if (empty($gleisSpalte) || strtolower($gleisSpalte) === 'keine') continue;

        $station_id = $gleisSpalte;
        $track = "";

        // Verarbeite verschiedene Formate:
        // "KZ4A" → station_id=KZ, track=4A
        // "BO2G" → station_id=BO, track=2G
        // "BN 10 kurz" → station_id=BN, track=10 kurz
        if (preg_match('/^([A-Za-z]+)\s+(.+)$/', $gleisSpalte, $matches)) {
            $station_id = $matches[1];
            $track = $matches[2];
        } elseif (preg_match('/^([A-Za-z]+)(\d.*)$/', $gleisSpalte, $matches)) {
            $station_id = $matches[1];
            $track = $matches[2];
        }

        // SPALTEN 4, 5: Ankunft, Abfahrt
        $arrival = trim($cols[4] ?? '');
        $departure = trim($cols[5] ?? '');

        // SPALTE 8: Flags
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

    // Nachfolger-ZIDs automatisch ermitteln
    $successor_zids = []; 
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
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Fahrplan-Import mit STS-ZID</title>
    <style>
        body { background: #121212; color: #e0e0e0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; padding: 20px; max-width: 1200px; margin: 0 auto; }
        h1, h2, h3 { color: #fff; }
        textarea { width: 100%; height: 220px; background: #1e1e1e; color: #00ff00; border: 1px solid #333; font-family: 'Courier New', monospace; padding: 12px; border-radius: 4px; box-sizing: border-box; }
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
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; font-size: 14px; }
        .info { background: #1a2f4a; border: 1px solid #2563eb; padding: 12px; border-radius: 4px; margin-bottom: 20px; font-size: 13px; line-height: 1.5; }
        .stat { display: inline-block; background: #2d2d2d; padding: 8px 12px; border-radius: 4px; margin-right: 15px; font-weight: bold; color: #4ade80; }
    </style>
</head>
<body>

    <h1>🚂 Fahrplan-Import mit STS-ZID</h1>
    
    <?php if ($message): ?>
        <div class="msg <?= strpos($message, '✗') === 0 ? 'error' : '' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="info">
        <strong>ℹ️ Anleitung:</strong> 
        Kopiere deine STS-Fahrplan-Zeilen direkt aus der STSFahrplan.txt. Das System erkennt automatisch die ZID-Spalte (1. Spalte) und speichert diese in deiner Spalte <code>sts_zid</code>. Die Strecken-ID sollte der ID in deiner <code>routes.php</code> entsprechen (z.B. <code>sempachersee</code>). Mehrfach-Halte an gleicher Station werden automatisch korrekt mit <code>sequence_index</code> erfasst.
    </div>

    <form method="POST">
        <input type="hidden" name="action" value="generate_preview">
        
        <div class="form-group">
            <label for="route_id">Strecken-ID (muss exakt der ID aus routes.php entsprechen):</label>
            <input type="text" id="route_id" name="route_id" value="<?= htmlspecialchars($current_route) ?>" placeholder="z.B. sempachersee" required style="width: 300px;">
        </div>

        <div class="form-group">
            <label for="sts_data">STS-Fahrplandaten (Kopie aus der STSFahrplan.txt):</label>
            <textarea id="sts_data" name="sts_data" placeholder="Kopiere hier die Zeilen aus STSFahrplan.txt..." spellcheck="false"></textarea>
        </div>

        <button type="submit">📋 Daten einlesen und Vorschau anzeigen</button>
    </form>

    <?php if (!empty($preview_data)): ?>
        <h2>Erkannte Fahrplandaten</h2>
        
        <div>
            <span class="stat">✓ <?= count(array_unique(array_column($preview_data, 'train_number'))) ?> Züge</span>
            <span class="stat">✓ <?= count($preview_data) ?> Haltestellen (mit Mehrfach-Halten)</span>
            <span class="stat">✓ <?= count(array_filter($sts_zids)) ?> mit ZID</span>
        </div>
        
        <form method="POST">
            <input type="hidden" name="action" value="save_final">
            <input type="hidden" name="route_id" value="<?= htmlspecialchars($current_route) ?>">
            <input type="hidden" name="json_records" value="<?= htmlspecialchars(json_encode(array_values($preview_data))) ?>">
            <input type="hidden" name="sts_zids" value="<?= htmlspecialchars(json_encode($sts_zids)) ?>">
            <input type="hidden" name="successor_zids" value="<?= htmlspecialchars(json_encode($successor_zids ?? [])) ?>">
            
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
                            <td colspan="2">← Importiert mit <?= count(array_filter($preview_data, fn($r) => $r['train_number'] == $train_num)) ?> Fahrplaneinträgen (inklusive Mehrfach-Halte)</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <button type="submit" class="save">💾 Diese Daten in der Datenbank speichern</button>
        </form>
    <?php endif; ?>

</body>
</html>