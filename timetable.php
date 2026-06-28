<?php
date_default_timezone_set('Europe/Berlin');
set_exception_handler(function ($e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
});

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

// ========================================================================
// FOLGEZUG-VERSPÄTUNGS-PROPAGATION (Cascade Delay)
// ========================================================================

/**
 * recalculateDelayCascade()
 * 
 * Propagiert die Verspätung eines Zuges auf seinen Folgezug.
 * Berücksichtigt die Standzeit des Folgezuges am Übergabepunkt.
 * 
 * @param PDO $db Datenbank-Verbindung
 * @param string $current_sts_zid STS-ZID des aktuellen Zuges (der verspätet ist)
 * @param int $delay_minutes Verspätung in Minuten (positiv = Verzug)
 */
function recalculateDelayCascade($db, $current_sts_zid, $delay_minutes) {
    if (empty($current_sts_zid) || $delay_minutes <= 0) {
        return;
    }

    // 1. Finde den aktuellen Zug in der Datenbank
    $stmtCurrentTrain = $db->prepare("
        SELECT id, successor_sts_zid, route_id
        FROM trains
        WHERE sts_zid = ?
    ");
    $stmtCurrentTrain->execute([$current_sts_zid]);
    $currentTrain = $stmtCurrentTrain->fetch(PDO::FETCH_ASSOC);

    if (!$currentTrain || empty($currentTrain['successor_sts_zid'])) {
        // Kein Folgezug definiert oder Zug nicht gefunden
        return;
    }

    $successor_sts_zid = $currentTrain['successor_sts_zid'];

    // 2. Finde den Folgezug
    $stmtSuccessorTrain = $db->prepare("
        SELECT id, train_number, route_id
        FROM trains
        WHERE sts_zid = ?
    ");
    $stmtSuccessorTrain->execute([$successor_sts_zid]);
    $successorTrain = $stmtSuccessorTrain->fetch(PDO::FETCH_ASSOC);

    if (!$successorTrain) {
        // Folgezug existiert nicht in der Datenbank
        return;
    }

    // 3. Finde den letzten Halt des aktuellen Zuges (Übergabepunkt)
    $stmtLastStopCurrent = $db->prepare("
        SELECT station_id, actual_departure, departure
        FROM timetable
        WHERE train_id = ?
        ORDER BY CASE WHEN departure != '' THEN departure ELSE arrival END DESC, id DESC
        LIMIT 1
    ");
    $stmtLastStopCurrent->execute([$currentTrain['id']]);
    $lastStopCurrent = $stmtLastStopCurrent->fetch(PDO::FETCH_ASSOC);

    if (!$lastStopCurrent) {
        return;
    }

    $handoverStation = $lastStopCurrent['station_id'];

    // 4. Finde denselben Bahnhof im Folgezug
    $stmtHandoverStopSuccessor = $db->prepare("
        SELECT id, arrival, departure, actual_arrival, actual_departure, flags
        FROM timetable
        WHERE train_id = ? AND station_id = ?
    ");
    $stmtHandoverStopSuccessor->execute([$successorTrain['id'], $handoverStation]);
    $handoverStopSuccessor = $stmtHandoverStopSuccessor->fetch(PDO::FETCH_ASSOC);

    if (!$handoverStopSuccessor) {
        // Folgezug hält nicht an dieser Station
        return;
    }

    // 5. Hilfsfunktion: Zeit-String zu Minuten
    $toMin = function($tStr) {
        if (empty($tStr)) return 0;
        $p = explode(':', $tStr);
        return (count($p) >= 2) ? (intval($p[0]) * 60 + intval($p[1])) : 0;
    };

    // 6. Berechne die Standzeit des Folgezuges an der Übergabestation (Soll)
    $successor_soll_arrival = $toMin($handoverStopSuccessor['arrival']);
    $successor_soll_departure = $toMin($handoverStopSuccessor['departure']);
    $successor_standzeit = max(0, $successor_soll_departure - $successor_soll_arrival);

    // 7. Berechne die neue Ist-Ankunft des Folgezuges
    //    Neue Ankunft = alte Ist-Ankunft + Verspätung des vorherigen Zuges
    $successor_ist_arrival = $toMin($handoverStopSuccessor['actual_arrival']);
    
    if ($successor_ist_arrival === 0) {
        // Falls noch keine Ist-Ankunft erfasst: von Soll ausgehen
        $successor_ist_arrival = $successor_soll_arrival;
    }

    // Neue Ist-Ankunft = alte Ist-Ankunft + Verspätung
    $new_successor_ist_arrival = $successor_ist_arrival + $delay_minutes;

    // 8. Berechne die neue Ist-Abfahrt unter Standzeit-Schutz
    //    Wenn Folgezug eine R- oder E-Flag hat: Standzeit = mind. 1 Min
    //    Sonst: Standzeit kann auf 0 reduziert werden (zum Verzug abbremsen)
    
    $flags = $handoverStopSuccessor['flags'] ?? '';
    $minStandzeit = 0;
    if (preg_match('/[RE]/i', $flags)) {
        $minStandzeit = 1; // R- oder E-Flag: Minimum 1 Min Standzeit
    }

    // Tatsächliche Standzeit = max(Soll-Standzeit, Minimum)
    $effective_standzeit = max($successor_standzeit, $minStandzeit);

    // Neue Ist-Abfahrt = neue Ankunft + effektive Standzeit
    $new_successor_ist_departure = $new_successor_ist_arrival + $effective_standzeit;

    // 9. Hilfsfunktion: Minuten zu Zeit-String
    $minutesToTime = function($m) {
        $positiveMinutes = (($m % 1440) + 1440) % 1440;
        $h = floor($positiveMinutes / 60);
        $min = $positiveMinutes % 60;
        return sprintf('%02d:%02d', $h, $min);
    };

    // 10. Speichere die neuen Ist-Zeiten des Folgezuges
    $stmtUpdateSuccessor = $db->prepare("
        UPDATE timetable
        SET actual_arrival = ?, actual_departure = ?
        WHERE id = ?
    ");

    $stmtUpdateSuccessor->execute([
        $minutesToTime($new_successor_ist_arrival),
        $minutesToTime($new_successor_ist_departure),
        $handoverStopSuccessor['id']
    ]);

    // 11. Rekursiv: Wenn der Folgezug selbst einen Folgezug hat, propagiere weiter
    $new_successor_delay = $new_successor_ist_departure - $successor_soll_departure;
    
    if ($new_successor_delay > 0) {
        recalculateDelayCascade($db, $successor_sts_zid, $new_successor_delay);
    }
}

//Diskri
function evaluateDispoCriteria($db, $currentStationId, $flags, $planDepartureStr, $actualDepartureStr) {
    if (empty($flags)) return $actualDepartureStr;

    if (!preg_match('/^(X|V|C(?:10|[1-9])?)\((\d+)\)/i', trim($flags), $matches)) {
        return $actualDepartureStr; 
    }

    $type = strtoupper($matches[1]);
    $conflictTrainNum = $matches[2];

    $buffer = 0;
    if ($type === 'V') {
        $buffer = 2;
    } elseif ($type === 'C' || preg_match('/^C([1-3])$/', $type)) {
        $buffer = 3;
    } elseif (preg_match('/^C([4-7])$/', $type, $cMatches)) {
        $buffer = intval($cMatches[1]);
    }

    $stmt = $db->prepare("
        SELECT tt.actual_arrival, tt.arrival, tt.actual_departure, tt.departure
        FROM timetable tt
        JOIN trains t ON tt.train_id = t.id
        WHERE t.train_number = ? AND tt.station_id = ?
    ");
    $stmt->execute([$conflictTrainNum, $currentStationId]);
    $conflictStop = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conflictStop) {
        return $actualDepartureStr;
    }

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
    
    $baseDepartureStr = !empty($actualDepartureStr) ? $actualDepartureStr : $planDepartureStr;
    $baseMinutes = $timeToMin($baseDepartureStr);
    
    $minDepartureMinutes = $refMinutes + $buffer;

    if ($minDepartureMinutes > $baseMinutes) {
        $h = floor($minDepartureMinutes / 60) % 24;
        $m = $minDepartureMinutes % 60;
        return sprintf('%02d:%02d', $h, $m);
    }

    return $actualDepartureStr;
}

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
                $isEmpty = empty($fields['arrival']) && empty($fields['departure']) && empty($fields['track']) && 
                           empty($fields['actual_arrival']) && empty($fields['actual_departure']) && 
                           empty($fields['flags']) && empty($fields['remarks']);

                if ($isEmpty) {
                    $stmtDelete->execute([$train_id, $st_id]);
                } else {
                    $flags = $fields['flags'] ?? '';
                    $departureSoll = $fields['departure'] ?? '';
                    $departureIst = $fields['actual_departure'] ?? '';
                    
                    if (!empty($flags) && !empty($departureSoll)) {
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

            $stmtTrain = $db->prepare("SELECT train_number, successor_sts_zid, sts_zid, route_id FROM trains WHERE id = ?");
            $stmtTrain->execute([$train_id]);
            $trainData = $stmtTrain->fetch(PDO::FETCH_ASSOC);
            
            $trainNum = $trainData['train_number'] ?? '';
            $successorStsZid = $trainData['successor_sts_zid'] ?? '';
            $current_sts_zid = $trainData['sts_zid'] ?? '';
            $route_id = $trainData['route_id'] ?? '';

            $username = $_COOKIE['username'] ?? 'Unbekannt';
            if (trim($username) === '') { $username = 'Unbekannt'; }
            $localTimestamp = date('Y-m-d H:i:s');

            $stmtLog = $db->prepare("INSERT INTO audit_log (train_number, username, timestamp) VALUES (?, ?, ?)");
            $stmtLog->execute([$trainNum, $username, $localTimestamp]);

            $stmtFirst = $db->prepare("
                SELECT station_id FROM timetable 
                WHERE train_id = ? 
                ORDER BY CASE WHEN arrival != '' THEN arrival ELSE departure END ASC, id ASC 
                LIMIT 1
            ");
            $stmtFirst->execute([$train_id]);
            $first_station_id = $stmtFirst->fetchColumn();

            if ($first_station_id) {
                $current_config = $routes_config ?? $routesConfig ?? $ROUTES ?? [];

                if ($route_id === 'free' || !isset($current_config[$route_id])) {
                    $stmtAllStops = $db->prepare("
                        SELECT station_id FROM timetable 
                        WHERE train_id = ? 
                        ORDER BY CASE WHEN arrival != '' THEN arrival ELSE departure END ASC, id ASC
                    ");
                    $stmtAllStops->execute([$train_id]);
                    $virtualStations = [];
                    
                    while ($stId = $stmtAllStops->fetchColumn()) {
                        $virtualStations[] = ['id' => $stId];
                    }
                    
                    $route_id = ($route_id === 'free') ? 'free_temp_route' : $route_id;
                    $current_config[$route_id] = ['stations' => $virtualStations];
                    
                    $db->prepare("UPDATE trains SET route_id = ? WHERE id = ?")->execute([$route_id, $train_id]);
                }

                if (function_exists('propagateDelayForward')) {
                    propagateDelayForward($db, $train_id, $first_station_id, $current_config, 'M');
                }

                if ($route_id === 'free_temp_route') {
                    $db->prepare("UPDATE trains SET route_id = 'free' WHERE id = ?")->execute([$train_id]);
                }
            }

            if (!empty($current_sts_zid) && function_exists('recalculateDelayCascade')) {
                $stmtLastStop = $db->prepare("
                    SELECT departure, arrival, actual_departure, actual_arrival FROM timetable 
                    WHERE train_id = ? 
                    ORDER BY CASE WHEN departure != '' THEN departure ELSE arrival END DESC, id DESC 
                    LIMIT 1
                ");
                $stmtLastStop->execute([$train_id]);
                $last_stop = $stmtLastStop->fetch(PDO::FETCH_ASSOC);

                if ($last_stop) {
                    $toMin = function($tStr) {
                        if (empty($tStr)) return 0;
                        $p = explode(':', $tStr);
                        return (count($p) >= 2) ? (intval($p[0]) * 60 + intval($p[1])) : 0;
                    };

                    $soll_time = !empty($last_stop['departure']) ? $toMin($last_stop['departure']) : $toMin($last_stop['arrival']);
                    $act_time = !empty($last_stop['actual_departure']) ? $toMin($last_stop['actual_departure']) : $toMin($last_stop['actual_arrival']);
                    
                    if ($soll_time > 0 && $act_time > 0) {
                        $final_delay = $act_time - $soll_time;
                        if ($final_delay > 0) {
                            recalculateDelayCascade($db, $current_sts_zid, $final_delay);
                        }
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

    if ($action === 'get_all_data') {
        $route_id = $_POST['route_id'] ?? '';
        $current_route_stations = [];
        
        if ($route_id === 'free') {
            $stmt = $db->prepare("SELECT id, train_number FROM trains WHERE route_id = ? ORDER BY CAST(train_number AS INTEGER) ASC");
            $stmt->execute(['free']);
            $trains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
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
        }

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
        <div class="form-row" style="display: flex; gap: 20px; align-items: flex-end; margin-bottom: 15px; flex-wrap: wrap;">
            
            <div class="form-group" style="flex: 1; min-width: 200px;">
                <label for="route_filter">Strecke suchen:</label>
                <input type="text" id="route_filter" placeholder="Z.B. Mil..." onkeyup="filterRoutes()" style="width: 100%; box-sizing: border-box;">
            </div>

            <div class="form-group" style="flex: 1; min-width: 200px;">
                <label for="route_select">Strecke:</label>
                <select id="route_select" onchange="switchRoute()">
                    <option value="">-- Strecke wählen --</option>
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
                    <!-- <button type="button" style="background-color: #8b5cf6;" 
                    onclick="activateFreeEditor()">📝 Freier Editor</button> -->
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
                <button class="form-group" type="button" onclick="saveTrainLink()" style="background-color: #059669; color: white;">
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
                        <th>clear</th>
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

    <!-- FREIER EDITOR PANEL -->
    <div id="free_editor_panel" class="panel hidden">
        <h2>📝 Freier Fahrplan-Editor</h2>
        <p style="color: #666; margin-bottom: 15px;">Erstelle einen Fahrplan ohne vordefinierte Route. Stationen werden manuell hinzugefügt.</p>
        
        <button type="button" style="background-color: #64748b; color: white; margin-bottom: 15px;" onclick="closeFreeEditor()">← Zurück zur Routenauswahl</button>
        <input type="hidden" id="free_editor_train_id">
        
        <div style="margin-bottom: 15px; padding: 10px; background: var(--bg-th); border-radius: 4px;">
            <label>Zugnummer:</label>
            <input type="text" id="free_train_number" inputmode="numeric" pattern="[0-9]*" maxlength="6" 
                   placeholder="z.B. 421" oninput="this.value=this.value.replace(/[^0-9]/g,'');" 
                   onkeypress="if(event.key==='Enter') loadFreeEditorTrain();"
                   style="width: 100px;">
            <button type="button" onclick="loadFreeEditorTrain()" style="background-color: #3b82f6; color: white; margin-left: 10px;">Zug laden/erstellen</button>
        </div>

        <div style="margin-bottom: 15px; padding: 10px; background: var(--bg-th); border-radius: 4px;">
            <label for="station_input">Station hinzufügen (Kürzel):</label>
            <div style="display: flex; gap: 5px; margin-top: 8px;">
                <input type="text" id="station_input" placeholder="z.B. ZF, BRIT, etc." list="all_stations_list" 
                       style="flex: 1; padding: 8px; border: 1px solid var(--border); border-radius: 4px;">
                <datalist id="all_stations_list"></datalist>
                <button type="button" onclick="addStationToFreeEditor()" style="background-color: #10b981; color: white;">+ Station</button>
            </div>
        </div>

        <form id="free_timetable_form" onsubmit="saveFreeTimetable(event)">
            <table id="free_editor_table">
                <thead>
                    <tr>
                        <th>Bahnhof</th><th>Gl.</th>
                        <th>Ank (Soll)</th><th>Ist</th><th>Versp.</th>
                        <th>Abf (Soll)</th><th>Ist</th><th>Versp.</th>
                        <th>Flags</th><th>Bemerkung</th><th></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
            <div style="margin-top: 15px; display: flex; gap: 10px;">
                <button type="submit">Fahrplan speichern</button>
                <button type="button" style="background-color: #64748b; color: white;" onclick="closeFreeEditor()">Abbrechen</button>
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

    <!-- Keyboard Shortcuts Legende -->
    <div id="keyboard_legend" style="position: fixed; bottom: 20px; right: 20px; background: rgba(30, 30, 30, 0.95); color: #fff; padding: 12px 16px; border-radius: 6px; font-size: 12px; font-family: monospace; border: 1px solid #555; z-index: 9999; line-height: 1.6;">
        <div style="font-weight: bold; margin-bottom: 6px; border-bottom: 1px solid #666; padding-bottom: 6px;">Tastenkombinationen:</div>
        <div><kbd style="background: #444; padding: 2px 6px; border-radius: 3px;">R</kbd> Route suchen</div>
        <div><kbd style="background: #444; padding: 2px 6px; border-radius: 3px;">T</kbd> Zug suchen</div>
        <div><kbd style="background: #444; padding: 2px 6px; border-radius: 3px;">F</kbd> Freier Editor</div>
        <div><kbd style="background: #444; padding: 2px 6px; border-radius: 3px;">Shift+F</kbd> Zu Flags</div>
        <div><kbd style="background: #444; padding: 2px 6px; border-radius: 3px;">Esc</kbd> Schliessen</div>
    </div>
</div>

<script src="app.js"></script>
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

// ===== KEYBOARD SHORTCUTS =====
document.addEventListener('keydown', (e) => {
    // Browser-Shortcuts wie Strg/Cmd+F oder F3 nicht blockieren
    if (e.ctrlKey || e.metaKey || e.altKey) {
        return;
    }

    // Nicht triggern wenn man in Textfeldern tippt (ausser Escape)
    const isInInput = document.activeElement.tagName === 'INPUT' || 
                      document.activeElement.tagName === 'TEXTAREA' ||
                      document.activeElement.tagName === 'SELECT';
    
    const key = e.key.toLowerCase();
    
    // R - Route Filter fokussieren
    if (key === 'r' && !isInInput) {
        e.preventDefault();
        document.getElementById('route_filter').focus();
        document.getElementById('route_filter').select();
    }
    
    // T - Zug-Nummer fokussieren
    if (key === 't' && !isInInput) {
        e.preventDefault();
        document.getElementById('train_number').focus();
        document.getElementById('train_number').select();
    }
    
    // F - Freier Editor öffnen (nur wenn kein Input aktiv)
    if (key === 'f' && !isInInput && !e.shiftKey) {
        e.preventDefault();
        activateFreeEditor();
    }
    
    // Shift+F - Zu Flags springen
    if (key === 'f' && e.shiftKey) {
        e.preventDefault();
        jumpToFlags();
    }
    
    // Escape - Editor/Dialog schliessen
    if (key === 'escape') {
        e.preventDefault();
        const editorPanel = document.getElementById('editor_panel');
        const freeEditorPanel = document.getElementById('free_editor_panel');
        
        if (!editorPanel.classList.contains('hidden')) {
            closeEditor();
        } else if (!freeEditorPanel.classList.contains('hidden')) {
            closeFreeEditor();
        }
    }
});

// Hilfsfunktion: Zu Flags springen
function jumpToFlags() {
    const editorPanel = document.getElementById('editor_panel');
    const freeEditorPanel = document.getElementById('free_editor_panel');
    
    let firstFlagInput = null;
    
    // Normale Editor-Tabelle
    if (!editorPanel.classList.contains('hidden')) {
        firstFlagInput = editorPanel.querySelector('#editor_table input[name*="flags"]');
    }
    
    // Freier Editor-Tabelle
    if (!freeEditorPanel.classList.contains('hidden')) {
        firstFlagInput = freeEditorPanel.querySelector('#free_editor_table input[name*="flags"]');
    }
    
    if (firstFlagInput) {
        firstFlagInput.focus();
        firstFlagInput.select();
        // Scrolle zum Input
        firstFlagInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

// Overwrite: activateFreeEditor - mit sofortigem Fokus auf Zugnummerfeld
const originalActivateFreeEditor = activateFreeEditor;
if (originalActivateFreeEditor) {
    activateFreeEditor = function() {
        originalActivateFreeEditor();
        // Nach dem Öffnen sofort das Zugnummerfeld fokussieren
        setTimeout(() => {
            const trainNumberField = document.getElementById('free_train_number');
            if (trainNumberField) {
                trainNumberField.focus();
                trainNumberField.select();
            }
        }, 50);
    };
}
</script>
</body>
</html>