<?php
/**
 * CLI-Fahrplan-Importer für STS-Dateien
 * Schreibt alle .txt-Dateien eines Ordners direkt in die SQLite-Datenbank.
 * Unterstützt Mehrfach-Halte an gleicher Station mit sequence_index.
 */

if (php_sapi_name() !== 'cli') {
    die("Dieses Script kann nur über die Kommandozeile direkt auf dem Server ausgeführt werden.\n");
}

// Pfade definieren
$db_file = __DIR__ . '/dbs/fahrplan.sqlite';
$import_folder = __DIR__ . '/anschlussprobleme'; // Ordner, in dem deine 100 Dateien liegen

// Standard-Strecken-ID, falls sie nicht aus dem Dateinamen extrahiert werden kann
$default_route_id = 'sempachersee';

// 1. Datenbankverbindung aufbauen
try {
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Datenbankfehler: " . $e->getMessage() . "\n");
}

// Tabellen-Struktur sicherstellen
$db->exec("CREATE TABLE IF NOT EXISTS trains (
    id INTEGER PRIMARY KEY AUTOINCREMENT, 
    train_number INTEGER UNIQUE, 
    route_id TEXT,
    sts_zid TEXT,
    successor_sts_zid TEXT
)");

// NEUE STRUKTUR: sequence_index für Mehrfach-Halte an gleicher Station
$db->exec("CREATE TABLE IF NOT EXISTS timetable (
    id INTEGER PRIMARY KEY AUTOINCREMENT, 
    train_id INTEGER NOT NULL, 
    station_id TEXT NOT NULL, 
    sequence_index INTEGER NOT NULL DEFAULT 0,
    track TEXT, 
    arrival TEXT, 
    departure TEXT, 
    actual_arrival TEXT,
    actual_departure TEXT,
    flags TEXT, 
    remarks TEXT, 
    FOREIGN KEY(train_id) REFERENCES trains(id) ON DELETE CASCADE,
    UNIQUE(train_id, station_id, sequence_index)
)");

// Prüfen, ob der Import-Ordner existiert
if (!is_dir($import_folder)) {
    die("Fehler: Der Ordner '$import_folder' existiert nicht. Bitte anlegen und Dateien hineinlegen.\n");
}

// 2. Dateien einlesen
$files = glob($import_folder . '/*.txt');
echo "Gefundene Dateien: " . count($files) . "\n";

foreach ($files as $file_path) {
    $filename = basename($file_path);
    echo "Verarbeite: $filename ... ";

    // Optional: Strecken-ID aus dem Dateinamen ableiten (z.B. "sempachersee.txt" -> "sempachersee")
    // Falls alle Dateien zur gleichen Strecke gehören, einfach $default_route_id nutzen.
    $route_id = pathinfo($filename, PATHINFO_FILENAME);
    if (empty($route_id)) {
        $route_id = $default_route_id;
    }

    $rawInput = file_get_contents($file_path);
    $lines = explode("\n", str_replace("\r", "", trim($rawInput)));
    
    if (count($lines) > 0 && (strpos($lines[0], 'ZID') !== false || strpos($lines[0], 'Zug') !== false)) {
        array_shift($lines);
    }

    $preview_data = [];
    $sts_zids = []; 
    $train_zid_map = []; 
    $train_order = []; 

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

        $station_id = $gleisSpalte;  // Default
        $track = "";

        // Verarbeite verschiedene Formate:
        // "KZ4A" → station_id=KZ, track=4A
        // "BO2G" → station_id=BO, track=2G
        // "BN 10 kurz" → station_id=BN, track=10 kurz
        // "ROSS 3" → station_id=ROSS, track=3
        // "GMM2" → station_id=GMM, track=2
        
        if (preg_match('/^([A-Za-z]+)\s+(.+)$/', $gleisSpalte, $matches)) {
            // Format mit Leerzeichen: "STATION GLEIS..."
            // z.B. "BN 10 kurz", "ROSS 3", "KZ 4A"
            $station_id = $matches[1];              // z.B. "BN", "ROSS", "KZ"
            $track = $matches[2];                   // z.B. "10 kurz", "3", "4A"
        } elseif (preg_match('/^([A-Za-z]+)(\d.*)$/', $gleisSpalte, $matches)) {
            // Format ohne Leerzeichen: "STATIONGLEIS..."
            // z.B. "KZ4A", "BO2G", "GMM2", "ROSS3"
            $station_id = $matches[1];              // z.B. "KZ", "BO", "GMM", "ROSS"
            $track = $matches[2];                   // z.B. "4A", "2G", "2", "3"
        }

        $arrival = trim($cols[4] ?? '');
        $departure = trim($cols[5] ?? '');

        $flags = '';
        if (count($cols) > 8) {
            $flags = trim($cols[8]);
        }

        $preview_data[] = [
            'train_number' => $zugNum,
            'station_id' => $station_id,
            'track' => $track,
            'arrival' => $arrival,
            'departure' => $departure,
            'flags' => $flags,
        ];
    }

    // Nachfolger-ZIDs ermitteln
    $successor_zids = []; 
    for ($i = 0; $i < count($train_order) - 1; $i++) {
        $current_train = $train_order[$i];
        $next_train = $train_order[$i + 1];
        if (isset($train_zid_map[$next_train])) {
            $successor_zids[$current_train] = $train_zid_map[$next_train];
        }
    }

    // 3. Datenbank-Speicherung (Transaktion pro Datei)
    if (!empty($preview_data)) {
        $db->beginTransaction();
        try {
            $trains_by_number = [];
            foreach ($preview_data as $r) {
                $trains_by_number[$r['train_number']][] = $r;
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
                if ($sts_zid) { $updates[] = "sts_zid = ?"; $params[] = $sts_zid; }
                if ($successor_zid) { $updates[] = "successor_sts_zid = ?"; $params[] = $successor_zid; }
                
                if (!empty($updates)) {
                    $params[] = $zugNum;
                    $stmt = $db->prepare("UPDATE trains SET " . implode(", ", $updates) . " WHERE train_number = ?");
                    $stmt->execute($params);
                }

                $stmt = $db->prepare("SELECT id FROM trains WHERE train_number = ?");
                $stmt->execute([$zugNum]);
                $train_id = $stmt->fetchColumn();

                // Gruppiere Stops nach Station für sequence_index
                $stopsByStation = [];
                foreach ($stops as $r) {
                    $sid = $r['station_id'];
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
                            $r['station_id'], 
                            $idx,  // sequence_index
                            $r['track'], 
                            $r['arrival'], 
                            $r['departure'], 
                            $r['flags']
                        ]);
                    }
                }
            }
            $db->commit();
            echo "OK (" . count($trains_by_number) . " Züge eingepflegt mit Mehrfach-Halt-Unterstützung)\n";
        } catch (Exception $e) {
            $db->rollBack();
            echo "FEHLER: " . $e->getMessage() . "\n";
        }
    } else {
        echo "Keine Daten gefunden.\n";
    }
}

echo "\nFertig! Alle Fahrpläne wurden in die Instanz integriert.\n";
?>