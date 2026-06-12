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
// PHP HILFSFUNKTIONEN FÜR ZEITBERECHNUNGEN
// =========================================================================
function timeToMinutesPHP($timeStr) {
    if (empty($timeStr)) return 0;
    $p = explode(':', $timeStr);
    return (count($p) >= 2) ? (intval($p[0]) * 60 + intval($p[1])) : 0;
}

function minutesToTimePHP($totalMinutes) {
    if ($totalMinutes < 0) return "00:00"; // Unterlauf-Schutz
    $positiveMinutes = $totalMinutes % 1440;
    $h = floor($positiveMinutes / 60) % 24;
    $m = $positiveMinutes % 60;
    return sprintf('%02d:%02d', $h, $m);
}

// =========================================================================
// AUTOMATISCHE VERSPÄTUNGSPROPAGATION (ZEITABHÄNGIG & ABSICHERUNG)
// =========================================================================
function propagateDelayForward($db, $train_id, $modified_station_id, $routes_config, $is_am_gleis = false) {
    $current_time_str = date('H:i');
    $current_time_min = timeToMinutesPHP($current_time_str);
    
    $stmt = $db->prepare("
        SELECT station_id, arrival, departure, actual_arrival, actual_departure, remarks
        FROM timetable 
        WHERE train_id = ? 
        ORDER BY id ASC
    ");
    $stmt->execute([$train_id]);
    $all_stops = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($all_stops)) return;
    
    $modified_index = null;
    foreach ($all_stops as $i => $stop) {
        if ($stop['station_id'] === $modified_station_id) {
            $modified_index = $i;
            break;
        }
    }
    
    if ($modified_index === null) return;
    
    $mod_stop = $all_stops[$modified_index];
    $soll_dep_mod = timeToMinutesPHP($mod_stop['departure']);
    $act_dep_mod = timeToMinutesPHP($mod_stop['actual_departure']);
    
    if ($soll_dep_mod === 0 || $act_dep_mod === 0) {
        $soll_dep_mod = timeToMinutesPHP($mod_stop['arrival']);
        $act_dep_mod = timeToMinutesPHP($mod_stop['actual_arrival']);
    }
    
    $current_delay = $act_dep_mod - $soll_dep_mod;
    
    for ($i = $modified_index + 1; $i < count($all_stops); $i++) {
        $stop = $all_stops[$i];
        $station_id = $stop['station_id'];
        
        if (empty($stop['arrival']) && empty($stop['departure'])) {
            continue;
        }
        
        $sollArr = timeToMinutesPHP($stop['arrival']);
        $sollDep = timeToMinutesPHP($stop['departure']);
        
        if ($sollArr === 0 && $sollDep === 0) {
            continue;
        }
        
        $predicted_dep_min = (!empty($stop['actual_departure'])) ? timeToMinutesPHP($stop['actual_departure']) : $sollDep;
        
        if ($predicted_dep_min <= 0) {
            continue;
        }
        
        if ($predicted_dep_min < $current_time_min) {
            if (!empty($stop['actual_departure'])) {
                $current_delay = $predicted_dep_min - $sollDep;
            }
            continue;
        }
        
        if ($current_delay > 0 && $i > 0) {
            $prev_soll_dep = timeToMinutesPHP($all_stops[$i-1]['departure']);
            if ($prev_soll_dep > 0) {
                $section_travel_time = $sollArr - $prev_soll_dep;
                if ($section_travel_time > 0) {
                    $recovery = max(0, intval(round($section_travel_time * 0.07)));
                    $current_delay = max(0, $current_delay - $recovery);
                }
            }
        }
        
        $newArrMin = $sollArr + $current_delay;
        $min_stoptime = max(0, $sollDep - $sollArr);
        $newDepMin = max($sollDep + $current_delay, $newArrMin + $min_stoptime);
        
        $current_delay = $newDepMin - $sollDep;
        
        if ($newArrMin < 0 || $newDepMin < 0) {
            continue;
        }
        
        $actual_arrival = minutesToTimePHP($newArrMin);
        $actual_departure = minutesToTimePHP($newDepMin);
        
        $stmtUpdate = $db->prepare("
            UPDATE timetable 
            SET actual_arrival = ?, actual_departure = ?, remarks = ?
            WHERE train_id = ? AND station_id = ?
        ");
        $stmtUpdate->execute([
            $actual_arrival,
            $actual_departure,
            "Propagiert (Verspätung: +" . $current_delay . " Min)",
            $train_id,
            $station_id
        ]);
    }
}

// =========================================================================
// HILFSFUNKTION FÜR DISPOSITIONSKRITERIEN (X, V, C)
// =========================================================================
function evaluateDispoCriteria($db, $currentStationId, $flags, $planDepartureStr, $actualDepartureStr) {
    if (empty($flags)) return $actualDepartureStr;

    if (!preg_match('/^(X|V|C[4-7]?)\((\d+)\)/i', trim($flags), $matches)) {
        return $actualDepartureStr; 
    }

    $type = strtoupper($matches[1]);
    $conflictTrainNum = $matches[2];

    $buffer = 0;
    if ($type === 'V')  $buffer = 2;
    if ($type === 'C')  $buffer = 3;
    if (preg_match('/^C([4-7])$/', $type, $cMatches)) {
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

    $refMinutes = timeToMinutesPHP($refTimeStr);
    $baseDepartureStr = !empty($actualDepartureStr) ? $actualDepartureStr : $planDepartureStr;
    $baseMinutes = timeToMinutesPHP($baseDepartureStr);
    
    $minDepartureMinutes = $refMinutes + $buffer;

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

        if ($train_num <= 0 || $train_num > 999999 || $route_id === '') {
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
            $stmtOld = $db->prepare("SELECT station_id, actual_departure, departure FROM timetable WHERE train_id = ?");
            $stmtOld->execute([$train_id]);
            $oldStops = [];
            foreach ($stmtOld->fetchAll(PDO::FETCH_ASSOC) as $os) {
                $oldStops[$os['station_id']] = $os['actual_departure'] ?: $os['departure'];
            }

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

            $foundFirstChange = false;
            $timeDeltaMinutes = 0;
            $updatedStationIds = [];
            $firstChangedStationId = null;

            $shiftTime = function($timeStr, $delta) {
                if (empty($timeStr)) return '';
                $p = explode(':', $timeStr);
                $total = intval($p[0]) * 60 + intval($p[1]) + $delta;
                while ($total < 0) $total += 1440;
                return sprintf('%02d:%02d', floor($total / 60) % 24, $total % 60);
            };

            foreach ($data as $st_id => $fields) {
                $isEmpty = empty($fields['arrival']) && empty($fields['departure']) && empty($fields['track']) && 
                           empty($fields['actual_arrival']) && empty($fields['actual_departure']) && 
                           empty($fields['flags']) && empty($fields['remarks']);

                if ($isEmpty) {
                    $stmtDelete->execute([$train_id, $st_id]);
                } else {
                    $flags = $fields['flags'] ?? '';
                    $arrivalSoll = $fields['arrival'] ?? '';
                    $departureSoll = $fields['departure'] ?? '';
                    
                    $arrivalIst = $fields['actual_arrival'] ?? '';
                    $departureIst = $fields['actual_departure'] ?? '';

                    if (!$foundFirstChange && isset($oldStops[$st_id])) {
                        $oldTime = $oldStops[$st_id];
                        $newTime = $departureIst ?: $departureSoll;

                        if (!empty($oldTime) && !empty($newTime) && $oldTime !== $newTime) {
                            $foundFirstChange = true;
                            $firstChangedStationId = $st_id;
                            
                            $pOld = explode(':', $oldTime);
                            $pNew = explode(':', $newTime);
                            $minOld = intval($pOld[0]) * 60 + intval($pOld[1]);
                            $minNew = intval($pNew[0]) * 60 + intval($pNew[1]);
                            
                            $timeDeltaMinutes = $minNew - $minOld;
                        }
                    }

                    if ($foundFirstChange && $timeDeltaMinutes !== 0) {
                        if (!preg_match('/!\s*\+?\d+/i', $flags)) {
                            if (empty($arrivalIst) && !empty($arrivalSoll)) {
                                $arrivalIst = $shiftTime($arrivalSoll, $timeDeltaMinutes);
                            } else if (!empty($arrivalIst)) {
                                $arrivalIst = $shiftTime($arrivalIst, $timeDeltaMinutes);
                            }

                            if (empty($departureIst) && !empty($departureSoll)) {
                                $departureIst = $shiftTime($departureSoll, $timeDeltaMinutes);
                            } else if (!empty($departureIst)) {
                                $departureIst = $shiftTime($departureIst, $timeDeltaMinutes);
                            }
                        }
                    }
                    
                    if (!empty($flags) && !empty($departureSoll)) {
                        $departureIst = evaluateDispoCriteria($db, $st_id, $flags, $departureSoll, $departureIst);
                    }

                    $stmtUpsert->execute([
                        $train_id,
                        $st_id,
                        $fields['track'] ?? '',
                        $arrivalSoll,
                        $departureSoll, 
                        $arrivalIst,
                        $departureIst,
                        $flags,
                        $fields['remarks'] ?? ''
                    ]);

                    $updatedStationIds[] = $st_id;
                }
            }

            // Fahrtverlauf nachziehen
            if ($foundFirstChange && $timeDeltaMinutes !== 0) {
                $stmtAll = $db->prepare("SELECT id, station_id, arrival, departure, actual_arrival, actual_departure, flags FROM timetable WHERE train_id = ? ORDER BY arrival ASC, id ASC");
                $stmtAll->execute([$train_id]);
                $allStops = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

                $startShifting = false;
                foreach ($allStops as $stop) {
                    $sid = $stop['station_id'];

                    if (in_array($sid, $updatedStationIds)) {
                        $startShifting = true;
                        continue;
                    }

                    if ($startShifting) {
                        if (preg_match('/!\s*\+?\d+/i', $stop['flags'] ?? '')) {
                            continue;
                        }

                        $currentArr = $stop['actual_arrival'] ?: $stop['arrival'];
                        $currentDep = $stop['actual_departure'] ?: $stop['departure'];

                        $newActualArrival = $shiftTime($currentArr, $timeDeltaMinutes);
                        $newActualDeparture = $shiftTime($currentDep, $timeDeltaMinutes);

                        $stmtFahrtverlauf = $db->prepare("
                            UPDATE timetable 
                            SET actual_arrival = ?, actual_departure = ?, remarks = ? 
                            WHERE id = ?
                        ");
                        $stmtFahrtverlauf->execute([
                            $newActualArrival,
                            $newActualDeparture,
                            ($timeDeltaMinutes > 0 ? "+" : "") . $timeDeltaMinutes . " Min (Fahrtverlauf)",
                            $stop['id']
                        ]);
                    }
                }
            }

            // Nach dem Speichern triggern wir die Kaskaden-Propagation für diesen Zug!
            if ($firstChangedStationId !== null) {
                propagateDelayForward($db, $train_id, $firstChangedStationId, $ROUTES, false);
            }

            // Audit-Log & Nachfolgezüge
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
        $stmt = $db->query("SELECT train_number, username, strftime('%H:%M:%S', timestamp) as zeit FROM audit_log ORDER BY id DESC LIMIT 100");
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
                    <button type="button" style="background-color: #8b5cf6;" 
                    onclick="activateFreeEditor()">📝 Freier Editor</button>
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
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                </tbody>
            </table>
            <div style="margin-top: 15px; display: flex; gap: 10px;">
                <button type="submit">Fahrplan保存</button>
                <button type="button" style="background-color: #64748b; color: white;" onclick="closeEditor()">Abbrechen</button>
                <button type="button" id="delete_train_btn" style="background-color: #d9534f; color: white;" onclick="deleteCurrentTrain()">Zug löschen</button>
            </div>
        </form>
    </div>

    <div id="free_editor_panel" class="panel hidden">
        <h2>📝 Freier Fahrplan-Editor</h2>
        <p style="color: #666; margin-bottom: 15px;">Erstelle einen Fahrplan ohne vordefinierte Route.</p>
        
        <button type="button" style="background-color: #64748b; color: white; margin-bottom: 15px;" onclick="closeFreeEditor()">← Zurück zur Routenauswahl</button>
        <input type="hidden" id="free_editor_train_id">
        
        <div style="margin-bottom: 15px; padding: 10px; background: var(--bg-th); border-radius: 4px;">
            <label>Zugnummer:</label>
            <input type="text" id="free_train_number" inputmode="numeric" pattern="[0-9]*" maxlength="6" 
                   placeholder="z.B. 421" oninput="this.value=this.value.replace(/[^0-9]/g,'');" style="width: 100px;">
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
                        <th>Bahnhof</th>
                        <th>Gleis</th>
                        <th>Ankunft (Soll)</th>
                        <th>Ist-Ankunft</th>
                        <th>Abfahrt (Soll)</th>
                        <th>Ist-Abfahrt</th>
                        <th>Flags</th>
                        <th>Bemerkung</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="free_editor_tbody">
                </tbody>
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
</div>

<script>
// REINER JAVASCRIPT BEREICH! KEIN PHP-CODE MEHR HIER DRINNEN.
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
    if (typeof switchRoute === 'function') {
        switchRoute();
    }
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
        if (typeof switchRoute === 'function') {
            switchRoute(); 
        }
    }
}

let freeEditorStations = []; 

function initializeAllStationsList() {
    const datalist = document.getElementById('all_stations_list');
    const allAbbrs = new Set();
    
    for (const routeId in routesConfig) {
        const route = routesConfig[routeId];
        if (route.stations) {
            route.stations.forEach(st => {
                allAbbrs.add(st.abbr);
            });
        }
    }
    
    datalist.innerHTML = '';
    allAbbrs.forEach(abbr => {
        const option = document.createElement('option');
        option.value = abbr;
        datalist.appendChild(option);
    });
}

function activateFreeEditor() {
    freeEditorStations = [];
    document.getElementById('editor_panel').classList.add('hidden');
    document.getElementById('free_editor_panel').classList.remove('hidden');
    document.getElementById('free_train_number').value = '';
    document.getElementById('free_editor_train_id').value = '';
    document.getElementById('free_timetable_form').querySelector('tbody').innerHTML = '';
    document.getElementById('station_input').value = '';
    initializeAllStationsList();
}

function closeFreeEditor() {
    document.getElementById('free_editor_panel').classList.add('hidden');
    document.getElementById('route_select').value = '';
    document.getElementById('train_number').value = '';
    if (typeof currentRouteId !== 'undefined') {
        currentRouteId = '';
    }
}

async function loadFreeEditorTrain() {
    const trainNum = document.getElementById('free_train_number').value.trim();
    if (!trainNum) {
        alert('Bitte gib eine Zugnummer ein');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'get_or_create_train');
    formData.append('train_number', trainNum);
    formData.append('route_id', 'free');
    
    try {
        const res = await fetch('', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.error) {
            alert('Fehler: ' + data.error);
            return;
        }
        
        if (data.train) {
            document.getElementById('free_editor_train_id').value = data.train.id;
            
            freeEditorStations = [];
            const tbody = document.getElementById('free_timetable_form').querySelector('tbody');
            tbody.innerHTML = '';
            
            if (data.timetable) {
                Object.keys(data.timetable).forEach(stId => {
                    const info = data.timetable[stId];
                    let stationName = stId;
                    let stationAbbr = stId;
                    
                    for (const routeId in routesConfig) {
                        const route = routesConfig[routeId];
                        if (route.stations) {
                            const found = route.stations.find(s => s.id === stId);
                            if (found) {
                                stationName = found.name;
                                stationAbbr = found.abbr;
                                break;
                            }
                        }
                    }
                    
                    const delayArrMinutes = info.actual_arrival && info.arrival && typeof timeToMinutes === 'function'
                        ? timeToMinutes(info.actual_arrival) - timeToMinutes(info.arrival)
                        : 0;
                    const delayDepMinutes = info.actual_departure && info.departure && typeof timeToMinutes === 'function'
                        ? timeToMinutes(info.actual_departure) - timeToMinutes(info.departure)
                        : 0;
                    
                    freeEditorStations.push({
                        id: stId,
                        name: stationName,
                        abbr: stationAbbr,
                        track: info.track || '',
                        arrival: info.arrival || '',
                        departure: info.departure || '',
                        actual_arrival: info.actual_arrival || '',
                        actual_departure: info.actual_departure || '',
                        delay_arrival: delayArrMinutes,  
                        delay_departure: delayDepMinutes,  
                        flags: info.flags || '',
                        remarks: info.remarks || ''
                    });
                });
                
                renderFreeEditorTable();
            }
            
            alert(`Zug ${trainNum} geladen (Route: free)`);
        }
    } catch (err) {
        console.error('Fehler beim Laden:', err);
        alert('Verbindungsfehler beim Laden des Zuges');
    }
}

function addStationToFreeEditor() {
    const input = document.getElementById('station_input').value.trim().toUpperCase();
    if (!input) return;
    
    let stationFound = null;
    for (const routeId in routesConfig) {
        const route = routesConfig[routeId];
        if (route.stations) {
            const found = route.stations.find(s => s.abbr.toUpperCase() === input);
            if (found) {
                stationFound = found;
                break;
            }
        }
    }
    
    if (!stationFound) {
        alert(`Station mit Kürzel "${input}" nicht gefunden`);
        return;
    }
    
    if (freeEditorStations.find(st => st.id === stationFound.id)) {
        alert('Diese Station ist bereits im Fahrplan');
        return;
    }
    
    freeEditorStations.push({
        id: stationFound.id,
        name: stationFound.name,
        abbr: stationFound.abbr,
        track: '',
        arrival: '',
        departure: '',
        actual_arrival: '',
        actual_departure: '',
        flags: '',
        remarks: ''
    });
    
    renderFreeEditorTable();
    document.getElementById('station_input').value = '';
}

function renderFreeEditorTable() {
    const tbody = document.getElementById('free_editor_tbody');
    if (!tbody) return;
    tbody.innerHTML = '';

    freeEditorStations.forEach((st, index) => {
        const tr = document.createElement('tr');
        tr.id = `free_row_${st.id}`; 
        tr.setAttribute('data-station-id', st.id);

        let delayArr = '';
        if (st.arrival && st.actual_arrival && typeof timeToMinutes === 'function') {
            delayArr = timeToMinutes(st.actual_arrival) - timeToMinutes(st.arrival);
        }
        let delayDep = '';
        if (st.departure && st.actual_departure && typeof timeToMinutes === 'function') {
            delayDep = timeToMinutes(st.actual_departure) - timeToMinutes(st.departure);
        }

        tr.innerHTML = `
            <td><strong class="drag-handle" style="cursor:move; margin-right:8px;">☰</strong> ${st.name} (${st.abbr})</td>
            <td><input type="text" name="stations[${st.id}][track]" value="${st.track || ''}" size="3"></td>
            
            <td><input type="time" name="stations[${st.id}][arrival]" value="${st.arrival || ''}" oninput="if(typeof recalcRowFree==='function') recalcRowFree('${st.id}', 'arrival', ${index})"></td>
            <td>
                <div style="display:flex; gap:5px; align-items:center;">
                    <input type="time" id="free_ist_arr_${st.id}" name="stations[${st.id}][actual_arrival]" value="${st.actual_arrival || ''}" oninput="if(typeof recalcRowFree==='function') recalcRowFree('${st.id}', 'actual_arrival', ${index})">
                    <input type="number" id="free_delay_arr_${st.id}" style="width:45px;" value="${delayArr !== '' ? delayArr : ''}" placeholder="+0" oninput="if(typeof recalcRowFree==='function') recalcRowFree('${st.id}', 'arrival_delay', ${index})">
                </div>
            </td>
            
            <td><input type="time" name="stations[${st.id}][departure]" value="${st.departure || ''}" oninput="if(typeof recalcRowFree==='function') recalcRowFree('${st.id}', 'departure', ${index})"></td>
            <td>
                <div style="display:flex; gap:5px; align-items:center;">
                    <input type="time" id="free_ist_dep_${st.id}" name="stations[${st.id}][actual_departure]" value="${st.actual_departure || ''}" oninput="if(typeof recalcRowFree==='function') recalcRowFree('${st.id}', 'actual_departure', ${index})">
                    <input type="number" id="free_delay_dep_${st.id}" style="width:45px;" value="${delayDep !== '' ? delayDep : ''}" placeholder="+0" oninput="if(typeof recalcRowFree==='function') recalcRowFree('${st.id}', 'departure_delay', ${index})">
                </div>
            </td>
            
            <td><input type="text" name="stations[${st.id}][flags]" value="${st.flags || ''}" size="5"></td>
            <td><input type="text" name="stations[${st.id}][remarks]" value="${st.remarks || ''}" size="10"></td>
            <td><button type="button" class="btn-danger" style="padding:2px 6px;" onclick="removeStationFromFreeEditor('${st.id}')">✕</button></td>
        `;
        tbody.appendChild(tr);
    });
}

function removeStationFromFreeEditor(stationId) {
    const row = document.getElementById(`free_row_${stationId}`);
    if (row) {
        const inputs = row.querySelectorAll('input');
        inputs.forEach(input => input.value = '');
        row.style.display = 'none';
    }
    freeEditorStations = freeEditorStations.filter(st => st.id !== stationId);
}

async function saveFreeTimetable(e) {
    e.preventDefault();
    
    const trainId = document.getElementById('free_editor_train_id').value;
    if (!trainId) {
        alert('Bitte lade oder erstelle erst einen Zug');
        return;
    }
    
    const formData = new FormData(document.getElementById('free_timetable_form'));
    formData.append('action', 'save_timetable');
    formData.append('train_id', trainId);
    
    try {
        const res = await fetch('', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            alert('Fahrplan gespeichert!');
            closeFreeEditor();
        } else {
            alert('Fehler beim Speichern: ' + (data.error || 'Unbekannter Fehler'));
        }
    } catch (err) {
        console.error('Fehler beim Speichern:', err);
        alert('Verbindungsfehler beim Speichern');
    }
}
</script>
<script src="app.js"></script>
</body>
</html>