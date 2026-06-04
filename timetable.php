<?php
date_default_timezone_set('Europe/Berlin'); // Oder 'Europe/Zurich'
set_exception_handler(function ($e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
});

// Routen laden
require_once __DIR__ . '/routes.php';

$db_file = __DIR__ . '/dbs/fahrplan.sqlite';

if (!file_exists($db_file)) {
    touch($db_file);
    chmod($db_file, 0666);
}

try {
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Datenbankfehler: ' . $e->getMessage());
}

// Tabellen-Strukturen sicherstellen
$db->exec("CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    train_number INTEGER,
    username TEXT,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS trains (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    train_number TEXT NOT NULL,
    route_id TEXT NOT NULL,
    sts_zid TEXT,
    successor_sts_zid TEXT
)");

$db->exec("CREATE TABLE IF NOT EXISTS timetable (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    train_id INTEGER,
    station_id TEXT,
    track TEXT,
    arrival TEXT,
    departure TEXT,
    actual_arrival TEXT,
    actual_departure TEXT,
    flags TEXT,
    remarks TEXT,
    FOREIGN KEY(train_id) REFERENCES trains(id) ON DELETE CASCADE,
    UNIQUE(train_id, station_id)
)");

// =========================================================================
// HILFSFUNKTION FÜR DISPOSITIONSKRITERIEN (X, V, C)
// =========================================================================
function evaluateDispoCriteria($db, $currentStationId, $flags, $planDepartureStr, $actualDepartureStr) {
    if (empty($flags)) return $actualDepartureStr;

    // Regulärer Ausdruck filtert: Typ (X, V, C, C4-C7) und Zugnummer in den Klammern
    if (!preg_match('/^(X|V|C[4-7]?)\((\d+)\)/i', trim($flags), $matches)) {
        return $actualDepartureStr; 
    }

    $type = strtoupper($matches[1]);
    $conflictTrainNum = $matches[2];

    // Zeit-Puffer bestimmen
    $buffer = 0;
    if ($type === 'V')  $buffer = 2;
    if ($type === 'C')  $buffer = 3;
    if (preg_match('/^C([4-7])$/', $type, $cMatches)) {
        $buffer = intval($cMatches[1]);
    }

    // 1. Hole die ID und die Zeiten des Konfliktzuges an DIESER Station
    $stmt = $db->prepare("
        SELECT tt.actual_arrival, tt.arrival, tt.actual_departure, tt.departure
        FROM timetable tt
        JOIN trains t ON tt.train_id = t.id
        WHERE t.train_number = ? AND tt.station_id = ?
    ");
    $stmt->execute([$conflictTrainNum, $currentStationId]);
    $conflictStop = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conflictStop) {
        return $actualDepartureStr; // Konfliktzug existiert hier nicht
    }

    // 2. Bestimme das relevante Referenz-Ereignis des Konfliktzuges (Ist-Zeit bevorzugt)
    $refTimeStr = '';
    if ($type === 'X' || strpos($type, 'C') === 0) {
        $refTimeStr = !empty($conflictStop['actual_arrival']) ? $conflictStop['actual_arrival'] : $conflictStop['arrival'];
    } else if ($type === 'V') {
        $refTimeStr = !empty($conflictStop['actual_departure']) ? $conflictStop['actual_departure'] : $conflictStop['departure'];
    }

    if (empty($refTimeStr)) return $actualDepartureStr;

    $timeToMin = function($tStr) {
        if (empty($tStr)) return 0;
        $p = explode(':', $tStr);
        return (count($p) >= 2) ? (intval($p[0]) * 60 + intval($p[1])) : 0;
    };

    $refMinutes = $timeToMin($refTimeStr);
    
    // Basis ist die Ist-Abfahrt falls vorhanden, sonst die Soll-Abfahrt
    $baseDepartureStr = !empty($actualDepartureStr) ? $actualDepartureStr : $planDepartureStr;
    $baseMinutes = $timeToMin($baseDepartureStr);
    
    $minDepartureMinutes = $refMinutes + $buffer;

    // Maximum-Logik: Falls die Dispo-Mindestzeit SPÄTER ist, verschiebe die Ist-Abfahrt
    if ($minDepartureMinutes > $baseMinutes) {
        $h = floor($minDepartureMinutes / 60) % 24;
        $m = $minDepartureMinutes % 60;
        return sprintf('%02d:%02d', $h, $m);
    }

    return $actualDepartureStr;
}

// Dropdown-Liste holen
$aktiveZuege = [];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $db->query("SELECT DISTINCT train_number FROM trains ORDER BY CAST(train_number AS INTEGER) ASC");
    $aktiveZuege = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'get_or_create_train') {
        $train_num = intval($_POST['train_number'] ?? 0);
        $route_id = $_POST['route_id'] ?? '';

        if ($train_num <= 0 || $train_num > 999999 || empty($route_id)) {
            http_response_code(400);
            echo json_encode(['error' => 'Ungültige Zugnummer oder Route fehlt']);
            exit;
        }

        $stmt = $db->prepare("INSERT OR IGNORE INTO trains (train_number, route_id) VALUES (?, ?)");
        $stmt->execute([$train_num, $route_id]);

        $stmt = $db->prepare("SELECT id, train_number, route_id, sts_zid, successor_sts_zid FROM trains WHERE train_number = ?");
        $stmt->execute([$train_num]);
        $train = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT station_id, track, arrival, departure, actual_arrival, actual_departure, flags, remarks FROM timetable WHERE train_id = ? ORDER BY arrival ASC, id ASC");
        $stmt->execute([$train['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $timetable = [];
        foreach ($rows as $row) {
            $timetable[$row['station_id']] = $row;
        }

        echo json_encode(['train' => $train, 'timetable' => $timetable]);
        exit;
    }

    if ($action === 'save_timetable') {
        $train_id = intval($_POST['train_id'] ?? 0);
        $data = $_POST['stations'] ?? [];

        $db->beginTransaction();
        try {
// Bereite das Insert/Update und ein Delete-Statement vor
            $stmtUpsert = $db->prepare("
                INSERT INTO timetable (train_id, station_id, track, arrival, departure, actual_arrival, actual_departure, flags, remarks) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(train_id, station_id) DO UPDATE SET
                    track = excluded.track,
                    arrival = excluded.arrival,
                    departure = excluded.departure,
                    actual_arrival = excluded.actual_arrival,
                    actual_departure = excluded.actual_departure,
                    flags = excluded.flags,
                    remarks = excluded.remarks
            ");

            $stmtDelete = $db->prepare("DELETE FROM timetable WHERE train_id = ? AND station_id = ?");

            foreach ($data as $st_id => $fields) {
                // Prüfen, ob die Zeile komplett leer gecleart wurde
                $isEmpty = empty($fields['arrival']) && empty($fields['departure']) && empty($fields['track']) && 
                           empty($fields['actual_arrival']) && empty($fields['actual_departure']) && 
                           empty($fields['flags']) && empty($fields['remarks']);

                if ($isEmpty) {
                    // WICHTIG: Wenn alles leer ist, löschen wir den alten Eintrag aus der Datenbank!
                    $stmtDelete->execute([$train_id, $st_id]);
                } else {
                    // Wenn mindestens ein Feld Daten enthält, wird gespeichert/aktualisiert
                    $flags = $fields['flags'] ?? '';
                    $departureSoll = $fields['departure'] ?? '';
                    $departureIst = $fields['actual_departure'] ?? '';
                    
                    // WICHTIG: Wenn ! am Anfang (Fixverspätung), nicht mit Diskris evaluieren
                    $isFixed = preg_match('/^\s*!\s*\+?\d+/i', $flags);
                    
                    // Dispo-Kriterium berechnet hier die neue IST-Abfahrt (Prognose)
                    // Aber NICHT, wenn ! gesetzt ist (Fixverspätung)
                    if (!empty($flags) && !empty($departureSoll) && !$isFixed) {
                        $departureIst = evaluateDispoCriteria($db, $st_id, $flags, $departureSoll, $departureIst);
                    }

                    $stmtUpsert->execute([
                        $train_id,
                        $st_id,
                        $fields['track'] ?? '',
                        $fields['arrival'] ?? '',
                        $departureSoll, 
                        $fields['actual_arrival'] ?? '',
                        $departureIst,
                        $flags,
                        $fields['remarks'] ?? ''
                    ]);
                }
            }

            // Audit-Log schreiben
            $stmtTrain = $db->prepare("SELECT train_number, successor_sts_zid FROM trains WHERE id = ?");
            $stmtTrain->execute([$train_id]);
            $trainData = $stmtTrain->fetch(PDO::FETCH_ASSOC);
            $trainNum = $trainData['train_number'];
            $successorStsZid = $trainData['successor_sts_zid'];

            $username = $_COOKIE['username'] ?? 'Unbekannt';
            if (trim($username) === '') { $username = 'Unbekannt'; }
            $localTimestamp = date('Y-m-d H:i:s');

            $stmtLog = $db->prepare("INSERT INTO audit_log (train_number, username, timestamp) VALUES (?, ?, ?)");
            $stmtLog->execute([$trainNum, $username, $localTimestamp]);

            // Verspätungspropagierung (unverändert)
            if (!empty($successorStsZid)) {
                $stmtSuccessor = $db->prepare("SELECT id FROM trains WHERE sts_zid = ?");
                $stmtSuccessor->execute([$successorStsZid]);
                $successorTrain = $stmtSuccessor->fetch(PDO::FETCH_ASSOC);
                
                if ($successorTrain) {
                    $successorTrainId = $successorTrain['id'];
                    
                    $stmtDelay = $db->prepare("
                        SELECT 
                            AVG(
                                CAST(
                                    CASE 
                                        WHEN actual_departure != '' AND departure != '' THEN
                                            (CAST(substr(actual_departure, 1, 2) as INTEGER) * 60 + CAST(substr(actual_departure, 4, 2) as INTEGER)) -
                                            (CAST(substr(departure, 1, 2) as INTEGER) * 60 + CAST(substr(departure, 4, 2) as INTEGER))
                                        ELSE 0
                                    END AS INTEGER
                                )
                            ) as avg_delay
                        FROM timetable 
                        WHERE train_id = ? AND actual_departure != ''
                    ");
                    $stmtDelay->execute([$train_id]);
                    $delayData = $stmtDelay->fetch(PDO::FETCH_ASSOC);
                    $avgDelay = intval($delayData['avg_delay'] ?? 0);
                    
                    if ($avgDelay != 0) {
                        $stmtUpdateSuccessor = $db->prepare("
                            UPDATE timetable 
                            SET 
                                actual_arrival = CASE 
                                    WHEN arrival != '' THEN printf('%02d:%02d', 
                                        (CAST(substr(arrival, 1, 2) as INTEGER) * 60 + CAST(substr(arrival, 4, 2) as INTEGER) + ?) / 60,
                                        (CAST(substr(arrival, 1, 2) as INTEGER) * 60 + CAST(substr(arrival, 4, 2) as INTEGER) + ?) % 60
                                    )
                                    ELSE ''
                                END,
                                actual_departure = CASE 
                                    WHEN departure != '' THEN printf('%02d:%02d',
                                        (CAST(substr(departure, 1, 2) as INTEGER) * 60 + CAST(substr(departure, 4, 2) as INTEGER) + ?) / 60,
                                        (CAST(substr(departure, 1, 2) as INTEGER) * 60 + CAST(substr(departure, 4, 2) as INTEGER) + ?) % 60
                                    )
                                    ELSE ''
                                END
                            WHERE train_id = ?
                        ");
                        $stmtUpdateSuccessor->execute([$avgDelay, $avgDelay, $avgDelay, $avgDelay, $successorTrainId]);
                    }
                }
            }

            $db->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // Übrige Aktionen (unverändert)
    if ($action === 'get_all_data') {
        $route_id = $_POST['route_id'] ?? '';
        $current_route_stations = [];
        if (isset($ROUTES[$route_id]['stations'])) {
            foreach ($ROUTES[$route_id]['stations'] as $st) {
                $current_route_stations[] = $st['id'];
            }
        }
        if (empty($current_route_stations)) {
            echo json_encode([]);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($current_route_stations), '?'));
        $query = "SELECT DISTINCT t.id, t.train_number 
                  FROM trains t
                  JOIN timetable tt ON t.id = tt.train_id
                  WHERE tt.station_id IN ($placeholders)
                  ORDER BY t.train_number ASC";
                  
        $stmt = $db->prepare($query);
        $stmt->execute($current_route_stations);
        $trains = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $all_data = [];
        foreach ($trains as $t) {
            $stmt = $db->prepare("SELECT * FROM timetable WHERE train_id = ? ORDER BY arrival ASC, id ASC");
            $stmt->execute([$t['id']]);
            $all_data[] = [
                'train_number' => $t['train_number'],
                'stops' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];
        }
        echo json_encode($all_data);
        exit;
    }

    if ($action === 'get_audit_log') {
        $stmt = $db->query("SELECT train_number, username, strftime('%H:%M:%S', timestamp) as zeit FROM audit_log ORDER BY id DESC LIMIT 10");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($action === 'get_routes') {
        echo json_encode($ROUTES);
        exit;
    }
    
    if ($action === 'delete_train') {
        $train_id = intval($_POST['train_id'] ?? 0);
        $stmtTrain = $db->prepare("SELECT train_number FROM trains WHERE id = ?");
        $stmtTrain->execute([$train_id]);
        $trainNum = $stmtTrain->fetchColumn();

        $stmt = $db->prepare("DELETE FROM trains WHERE id = ?");
        $stmt->execute([$train_id]);

        $username = $_COOKIE['username'] ?? 'Unbekannt';
        $localTimestamp = date('Y-m-d H:i:s');

        $stmtLog = $db->prepare("INSERT INTO audit_log (train_number, username, timestamp) VALUES (?, ?, ?)");
        $stmtLog->execute([$trainNum, $username . ' (gelöscht)', $localTimestamp]);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'save_train_link') {
        $train_id = intval($_POST['train_id'] ?? 0);
        $sts_zid = $_POST['sts_zid'] ?? '';
        $successor_sts_zid = $_POST['successor_sts_zid'] ?? '';

        $stmt = $db->prepare("UPDATE trains SET sts_zid = ?, successor_sts_zid = ? WHERE id = ?");
        $stmt->execute([$sts_zid ?: null, $successor_sts_zid ?: null, $train_id]);

        echo json_encode(['success' => true]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Bildfahrplan-Editor</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="container">
    <h1>Fahrplandisposition & Bildfahrplan</h1>
    <div class="panel">
        <label for="username">Disponent:</label>
        <input type="text" id="username" placeholder="Dein Name" value="<?= htmlspecialchars($_COOKIE['username'] ?? '') ?>" 
               onchange="document.cookie='username='+this.value">
    </div>
    <div class="panel">
        <h2>Strecke & Zug wählen</h2>
        <div class="form-row" style="display: flex; gap: 20px; align-items: flex-end;">
            
            <div class="form-group">
                <label for="route_filter">Strecke suchen:</label>
                <input type="text" id="route_filter" placeholder="Z.B. Mil..." onkeyup="filterRoutes()" style="width: 100%; box-sizing: border-box;">
            </div>

            <div class="form-group">
                <label for="route_select">Strecke:</label>
                <select id="route_select" onchange="switchRoute()">
                    <?php foreach ($ROUTES as $id => $r): ?>
                        <?php 
                        $searchTerms = [];
                        if (!empty($r['stations'])) {
                            foreach ($r['stations'] as $station) {
                                $searchTerms[] = $station['name'];
                                $searchTerms[] = $station['abbr'];
                            }
                        }
                        $searchString = implode(' ', $searchTerms);
                        ?>
                        <option value="<?= htmlspecialchars($id) ?>" data-search="<?= htmlspecialchars($searchString) ?>">
                            <?= htmlspecialchars($r['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="train_select">Vorhandene Züge:</label>
                <select id="train_select" onchange="selectExistingTrain(this.value)">
                    <option value="">-- Zug wählen --</option>
                    <?php foreach ($aktiveZuege as $zug): ?>
                        <option value="<?= htmlspecialchars($zug['train_number']) ?>"><?= htmlspecialchars($zug['train_number']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="train_number">Zugnummer (1-6 stellig):</label>
                <form onsubmit="event.preventDefault(); loadTrain();" style="display: flex; gap: 5px;">
                    <input type="text" id="train_number" inputmode="numeric" pattern="[0-9]*" maxlength="6" 
                           placeholder="z.B. 421" oninput="this.value=this.value.replace(/[^0-9]/g,'');">
                    <button type="submit">Editor öffnen</button>
                </form>
            </div>
        </div>
    </div>

    <div id="editor_panel" class="panel hidden">
        <h2>Fahrplan für Zug <span id="current_train_num"></span></h2>
        <button type="button" style="background-color: #64748b; color: white;" onclick="closeEditor()">Editor schliessen</button>
        <input type="hidden" id="current_train_id">
        
        <div style="margin-bottom: 15px; padding: 10px; background: var(--bg-th); border-radius: 4px;">
            <div style="display: flex; gap: 20px; align-items: flex-end;">
                <div class="form-group">
                    <label for="sts_zid">STS-ZID (eigene):</label>
                    <input type="text" id="sts_zid" placeholder="z.B. 74982" style="width: 100px;">
                </div>
                <div class="form-group">
                    <label for="successor_sts_zid">Nachfolger-ZID:</label>
                    <input type="text" id="successor_sts_zid" placeholder="z.B. 126275" style="width: 100px;">
                </div>
                <button type="button" onclick="saveTrainLink()" style="background-color: #059669; color: white;">
                    Verkettung
                </button>
            </div>
        </div>

        <form id="timetable_form" onsubmit="saveTimetable(event)">
            <table id="editor_table">
                <thead>
                    <tr>
                        <th>Bahnhof</th>
                        <th>Gleis</th>
                        <th>Ankunft (Soll)</th>
                        <th>Ist-Ankunft / Verspätung</th>
                        <th>Abfahrt (Soll)</th>
                        <th>Ist-Abfahrt / Verspätung</th>
                        <th>Flags</th>
                        <th>Bemerkung</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                </tbody>
            </table>
            <div style="margin-top: 15px; display: flex; gap: 10px;">
                <button type="submit">Fahrplan speichern</button>
                <button type="button" style="background-color: #64748b; color: white;" onclick="closeEditor()">Abbrechen</button>
                <button type="button" id="delete_train_btn" style="background-color: #d9534f; color: white;" onclick="deleteCurrentTrain()">Zug löschen</button>
            </div>
        </form>
    </div>

    <div class="panel">
        <h2>Grafischer Fahrplan</h2>
        <div style="display: flex; gap: 15px; align-items: center; margin-bottom: 15px;">
            <label>Zeitfenster von: <input type="time" id="graph_start" value="11:30" onchange="renderGraph()"></label>
            <label>bis: <input type="time" id="graph_end" value="15:00" onchange="renderGraph()"></label>
            <button onclick="renderGraph()">Aktualisieren</button>
        </div>
        <canvas id="graphCanvas" width="1200" height="800"></canvas>
    </div>
</div>

<script>
const routesConfig = <?= json_encode($ROUTES) ?>;

function closeEditor() {
    document.getElementById('editor_panel').classList.add('hidden');
    document.getElementById('train_number').value = '';
    document.getElementById('train_select').value = '';
}

function selectExistingTrain(trainNumber) {
    if (trainNumber) {
        document.getElementById('train_number').value = trainNumber;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    switchRoute();
});

function filterRoutes() {
    const filterValue = document.getElementById('route_filter').value.toLowerCase();
    const select = document.getElementById('route_select');
    const options = select.options;
    
    let firstVisibleOption = null;

    for (let i = 0; i < options.length; i++) {
        const option = options[i];
        const routeName = option.text.toLowerCase();
        const stationTerms = option.getAttribute('data-search') ? option.getAttribute('data-search').toLowerCase() : '';
        
        if (routeName.includes(filterValue) || stationTerms.includes(filterValue)) {
            option.style.display = "";
            if (!firstVisibleOption) {
                firstVisibleOption = option;
            }
        } else {
            option.style.display = "none";
        }
    }

    if (firstVisibleOption && select.value !== firstVisibleOption.value) {
        select.value = firstVisibleOption.value;
        switchRoute(); 
    }
}
</script>
<script src="app.js"></script>
</body>
</html>