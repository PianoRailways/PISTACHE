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
                // Prüfen, ob die Zeile komplett leer gecleart/gelöscht wurde
                $isEmpty = empty($fields['arrival']) && empty($fields['departure']) && empty($fields['track']) && 
                           empty($fields['actual_arrival']) && empty($fields['actual_departure']) && 
                           empty($fields['flags']) && empty($fields['remarks']);

                if ($isEmpty) {
                    $stmtDelete->execute([$train_id, $st_id]);
                } else {
                    $flags = $fields['flags'] ?? '';
                    $departureSoll = $fields['departure'] ?? '';
                    $departureIst = $fields['actual_departure'] ?? '';
                    
                    // Dispo-Kriterium berechnet hier die neue IST-Abfahrt (Prognose)
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

            // Stammdaten des Zuges laden
            $stmtTrain = $db->prepare("SELECT train_number, successor_sts_zid, sts_zid, route_id FROM trains WHERE id = ?");
            $stmtTrain->execute([$train_id]);
            $trainData = $stmtTrain->fetch(PDO::FETCH_ASSOC);
            
            $trainNum = $trainData['train_number'] ?? '';
            $successorStsZid = $trainData['successor_sts_zid'] ?? '';
            $current_sts_zid = $trainData['sts_zid'] ?? '';
            $route_id = $trainData['route_id'] ?? '';

            // Audit-Log schreiben
            $username = $_COOKIE['username'] ?? 'Unbekannt';
            if (trim($username) === '') { $username = 'Unbekannt'; }
            $localTimestamp = date('Y-m-d H:i:s');

            $stmtLog = $db->prepare("INSERT INTO audit_log (train_number, username, timestamp) VALUES (?, ?, ?)");
            $stmtLog->execute([$trainNum, $username, $localTimestamp]);

            // --- INTELLIGENTE VERSPÄTUNGSPROPAGIERUNG & KASKADE ---

            // Ersten Halt des Zuges ermitteln, um die Berechnung von vorne zu starten
            // Wir sortieren nach Ankunft/Abfahrt, um auch im freien Editor chronologisch zu bleiben
            $stmtFirst = $db->prepare("
                SELECT station_id FROM timetable 
                WHERE train_id = ? 
                ORDER BY CASE WHEN arrival != '' THEN arrival ELSE departure END ASC, id ASC 
                LIMIT 1
            ");
            $stmtFirst->execute([$train_id]);
            $first_station_id = $stmtFirst->fetchColumn();

            if ($first_station_id) {
                // Dynamische Konfiguration für den Berechnungsalgorithmus bestimmen
                $current_config = $routes_config ?? $routesConfig ?? $ROUTES ?? [];

                // FALLBACK FÜR FREIEN EDITOR: Wenn die Route 'free' ist, existiert sie nicht in der Config.
                // Wir bauen uns hier temporär ein virtuelles Streckenband aus den vorhandenen Stationen.
                if ($route_id === 'free' || !isset($current_config[$route_id])) {
                    $stmtAllStops = $db->prepare("
                        SELECT station_id FROM timetable 
                        WHERE train_id = ? 
                        ORDER BY CASE WHEN arrival != '' THEN arrival ELSE departure END ASC, id ASC
                    ");
                    $stmtAllStops->execute([$train_id]);
                    $virtualStations = [];
                    
                    // Wir lesen alle Stationen dieses freien Zuges aus
                    while ($stId = $stmtAllStops->fetchColumn()) {
                        $virtualStations[] = ['id' => $stId];
                    }
                    
                    // Injiziere die temporäre Route in den Konfigurations-Pool
                    $route_id = ($route_id === 'free') ? 'free_temp_route' : $route_id;
                    $current_config[$route_id] = ['stations' => $virtualStations];
                    
                    // Update der temporären Route im Objekt, falls die Funktion die route_id aus der DB nachlädt
                    $db->prepare("UPDATE trains SET route_id = ? WHERE id = ?")->execute([$route_id, $train_id]);
                }

                // Gesamte Fahrt des Zuges durchrechnen (Quelle 'M' für Manueller Editor)
                if (function_exists('propagateDelayForward')) {
                    propagateDelayForward($db, $train_id, $first_station_id, $current_config, 'M');
                }

                // Wenn wir für den freien Editor die ID temporär verbogen haben, stellen wir sie jetzt unbemerkt zurück
                if ($route_id === 'free_temp_route') {
                    $db->prepare("UPDATE trains SET route_id = 'free' WHERE id = ?")->execute([$train_id]);
                }
            }

            // 2. Kaskade für verkettete Nachfolgezüge anstoßen
            if (!empty($current_sts_zid) && function_exists('recalculateDelayCascade')) {
                // Die neu berechnete Endverspätung an der letzten Betriebsstelle holen
                $stmtLastStop = $db->prepare("
                    SELECT departure, arrival, actual_departure, actual_arrival FROM timetable 
                    WHERE train_id = ? 
                    ORDER BY CASE WHEN departure != '' THEN departure ELSE arrival END DESC, id DESC 
                    LIMIT 1
                ");
                $stmtLastStop->execute([$train_id]);
                $last_stop = $stmtLastStop->fetch(PDO::FETCH_ASSOC);

                if ($last_stop) {
                    // Verwende eine sichere Inline-Konvertierung, falls timeToMinutes() im Scope fehlt
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

    // Übrige Aktionen (unverändert)
    if ($action === 'get_all_data') {
        $route_id = $_POST['route_id'] ?? '';
        $current_route_stations = [];
        
        // Spezialfall: Freier Editor
        if ($route_id === 'free') {
            // Hole alle Züge mit route_id='free'
            $stmt = $db->prepare("SELECT id, train_number FROM trains WHERE route_id = ? ORDER BY CAST(train_number AS INTEGER) ASC");
            $stmt->execute(['free']);
            $trains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Normaler Workflow: Route-basiert
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

// ========================================================================
// FREIER EDITOR FUNKTIONEN
// ========================================================================
let freeEditorStations = []; // Speichert die Stationen im freien Editor

function initializeAllStationsList() {
    const datalist = document.getElementById('all_stations_list');
    const allAbbrs = new Set();
    
    // Sammle alle Stationskürzeln aus routesConfig
    for (const routeId in routesConfig) {
        const route = routesConfig[routeId];
        if (route.stations) {
            route.stations.forEach(st => {
                allAbbrs.add(st.abbr);
            });
        }
    }
    
    // Füge sie zur Datalist hinzu
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
    currentRouteId = '';
}

async function loadFreeEditorTrain() {
    const trainNum = document.getElementById('free_train_number').value.trim();
    if (!trainNum) {
        alert('Bitte gib eine Zugnummer ein');
        return;
    }
    
    // Im freien Editor verwenden wir "free" als route_id
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
            
            // Laden der existierenden Stationen und Fahrplandaten
            freeEditorStations = [];
            const tbody = document.getElementById('free_timetable_form').querySelector('tbody');
            tbody.innerHTML = '';
            
            if (data.timetable) {
                Object.keys(data.timetable).forEach(stId => {
                    const info = data.timetable[stId];
                    // Suche den vollständigen Stationsnamen
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
                    
                    freeEditorStations.push({
                        id: stId,
                        name: stationName,
                        abbr: stationAbbr,
                        track: info.track || '',
                        arrival: info.arrival || '',
                        departure: info.departure || '',
                        actual_arrival: info.actual_arrival || '',
                        actual_departure: info.actual_departure || '',
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
    
    // Suche die Station in allen Routen
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
    
    // Prüfe ob Station schon existiert
    if (freeEditorStations.find(st => st.id === stationFound.id)) {
        alert('Diese Station ist bereits im Fahrplan');
        return;
    }
    
    // Füge hinzu
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
    const tbody = document.getElementById('free_editor_table').querySelector('tbody');
    tbody.innerHTML = '';

    freeEditorStations.forEach((st, index) => {
        const tr = document.createElement('tr');
        tr.id = `free_row_${st.id}`;
        
        // Drag & Drop Attribute aktivieren
        tr.draggable = true;
        tr.style.cursor = 'move';
        
        // Innerhalb von renderFreeEditorTable()
        tr.innerHTML = `
            <td style="padding: 5px; width: 200px;"><strong class="drag-handle">☰</strong> ${st.name} (${st.abbr})</td>
            <td style="width: 50px;"><input type="text" name="stations[${st.id}][track]" value="${st.track}" style="width: 40px;"></td>
            
            <td><input type="time" name="stations[${st.id}][arrival]" value="${st.arrival}" onchange="recalcRow('${st.id}', 'arr', 'time')"></td>
            <td><input type="time" name="stations[${st.id}][actual_arrival]" value="${st.actual_arrival}" onchange="recalcRow('${st.id}', 'arr', 'time')"></td>
            <td><input type="number" id="delay_arr_${st.id}" placeholder="0" style="width: 50px;" oninput="recalcRow('${st.id}', 'arr', 'delay')"></td>
            
            <td><input type="time" name="stations[${st.id}][departure]" value="${st.departure}" onchange="recalcRow('${st.id}', 'dep', 'time')"></td>
            <td><input type="time" name="stations[${st.id}][actual_departure]" value="${st.actual_departure}" onchange="recalcRow('${st.id}', 'dep', 'time')"></td>
            <td><input type="number" id="delay_dep_${st.id}" placeholder="0" style="width: 50px;" oninput="recalcRow('${st.id}', 'dep', 'delay')"></td>
            
            <td><input type="text" name="stations[${st.id}][flags]" value="${st.flags}" style="width: 80px;" placeholder="X(Znr)"></td>
            <td><input type="text" name="stations[${st.id}][remarks]" value="${st.remarks}" style="width: 100px;"></td>
            <td><button type="button" style="background-color: #d9534f; color: white; border:none; padding: 4px 8px; cursor:pointer;" 
                        onclick="removeStationFromFreeEditor('${st.id}')">Entf</button></td>
        `;

        // Drag & Drop Events anheften
        tr.addEventListener('dragstart', (e) => {
            e.dataTransfer.setData('text/plain', index);
            tr.classList.add('dragging');
        });

        tr.addEventListener('dragend', () => {
            tr.classList.remove('dragging');
            // Nach dem Drop: Array im Speicher anhand der neuen DOM-Reihenfolge synchronisieren
            reorderFreeEditorStationsArray();
        });

        tbody.appendChild(tr);
    });

    // Eventlistener für das Platzieren (Drop-Zone) auf dem gesamten tbody
    tbody.addEventListener('dragover', (e) => {
        e.preventDefault();
        const draggingRow = tbody.querySelector('.dragging');
        const afterElement = getDragAfterElement(tbody, e.clientY);
        if (afterElement == null) {
            tbody.appendChild(draggingRow);
        } else {
            tbody.insertBefore(draggingRow, afterElement);
        }
    });
}

// Hilfsfunktion: Berechnet, vor welche Zeile das Element geschoben wird
function getDragAfterElement(tbody, y) {
    const draggableElements = [...tbody.querySelectorAll('tr:not(.dragging)')];

    return draggableElements.reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
            return { offset: offset, element: child };
        } else {
            return closest;
        }
    }, { offset: Number.NEGATIVE_INFINITY }).element;
}

// Synchronisiert die Reihenfolge im internen Array, damit beim nächsten Hinzufügen nichts verrückt spielt
function reorderFreeEditorStationsArray() {
    const tbody = document.getElementById('free_editor_table').querySelector('tbody');
    const newOrderedIds = [...tbody.querySelectorAll('tr')].map(tr => tr.id.replace('free_row_', ''));
    
    const reordered = [];
    newOrderedIds.forEach(id => {
        const found = freeEditorStations.find(st => st.id === id);
        if (found) reordered.push(found);
    });
    freeEditorStations = reordered;
}

function removeStationFromFreeEditor(stationId) {
    // 1. Suche die Zeile im DOM
    const row = document.getElementById(`free_row_${stationId}`);
    if (row) {
        // 2. Alle Input-Felder komplett leeren, damit die PHP-Logik (isEmpty) greift
        const inputs = row.querySelectorAll('input');
        inputs.forEach(input => input.value = '');

        // 3. Die Zeile visuell verstecken, damit sie für den Nutzer "gelöscht" ist
        row.style.display = 'none';
    }

    // 4. Aus dem internen JavaScript-Array entfernen, 
    // damit die Station bei Bedarf wieder neu hinzugefügt werden kann
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