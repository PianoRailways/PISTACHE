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

// ========================================================================
// ROUTING-ENGINE: Findet alle möglichen Routen-Kombinationen
// ========================================================================

/**
 * VEREINFACHTES ROUTING: Für jeden Waypoint-Pair den direktesten Weg finden
 * - Keine Rückwärtsfahrten
 * - Keine Stationen doppelt
 * - Einfach: Start→Via1→Via2→...→Ziel sequenziell
 */
/**
 * GRAPH-ROUTING: Findet den echten kürzesten Weg über Netzwerkknoten
 * Verhindert Schleifen und unvollständige Pfade durch topologische Graphensuche.
 */
function findRoutePaths($ROUTES, $waypoints, $stationMap) {
    if (count($waypoints) < 2) return [];

    // 1. Graph aus dem Liniennetz aufbauen (Knoten und Kanten)
    $graph = [];
    foreach ($ROUTES as $routeId => $route) {
        $stations = $route['stations'] ?? [];
        for ($i = 0; $i < count($stations) - 1; $i++) {
            $u = strtoupper($stations[$i]['abbr'] ?? '');
            $v = strtoupper($stations[$i+1]['abbr'] ?? '');
            if (!$u || !$v) continue;

            // Strecken-Distanz berechnen
            $kmDiff = abs(($stations[$i+1]['km'] ?? 0) - ($stations[$i]['km'] ?? 0));

            // Gerichtete Kante im Graphen hinterlegen
            $graph[$u][] = [
                'to' => $v,
                'km' => $kmDiff,
                'station' => $stations[$i+1]
            ];
        }
    }

    // Helper: Holt das originale Station-Metadaten-Objekt für Startbahnhöfe
    $getStationObj = function($abbr) use ($ROUTES) {
        foreach ($ROUTES as $route) {
            foreach ($route['stations'] as $st) {
                if (strtoupper($st['abbr'] ?? '') === strtoupper($abbr)) {
                    return $st;
                }
            }
        }
        return ['id' => $abbr, 'abbr' => $abbr, 'name' => $abbr, 'km' => 0];
    };

    // Kürzester-Pfad-Suche (Dijkstra) von Wegpunkt zu Wegpunkt
    $findShortestSegment = function($startNode, $endNode) use ($graph, $getStationObj) {
        $startNode = strtoupper($startNode);
        $endNode = strtoupper($endNode);

        if ($startNode === $endNode) {
            return [$getStationObj($startNode)];
        }

        $distances = [$startNode => 0];
        $previous = [];
        $edgeStationData = [];
        $queue = [$startNode => 0];

        while (!empty($queue)) {
            // Knoten mit der geringsten Distanz wählen
            asort($queue);
            $current = key($queue);
            unset($queue[$current]);

            if ($current === $endNode) break;
            if (!isset($graph[$current])) continue;

            foreach ($graph[$current] as $edge) {
                $neighbor = $edge['to'];
                
                // Distanzgewicht + minimaler Malus pro Hop, um Stations-Zickzack bei 0 km zu meiden
                $weight = $edge['km'] + 0.05; 
                $alt = $distances[$current] + $weight;

                if (!isset($distances[$neighbor]) || $alt < $distances[$neighbor]) {
                    $distances[$neighbor] = $alt;
                    $previous[$neighbor] = $current;
                    $edgeStationData[$neighbor] = $edge['station'];
                    $queue[$neighbor] = $alt;
                }
            }
        }

        // Kein valider Pfad im Schienennetz gefunden
        if (!isset($previous[$endNode])) return null;

        // Pfad von hinten nach vorne rekonstruieren
        $segmentPath = [];
        $curr = $endNode;
        while (isset($previous[$curr])) {
            $segmentPath[] = $edgeStationData[$curr];
            $curr = $previous[$curr];
        }
        $segmentPath[] = $getStationObj($startNode);

        return array_reverse($segmentPath);
    };

    // 2. Segmente sequentiell verknüpfen
    $completePath = [];

    for ($w = 0; $w < count($waypoints) - 1; $w++) {
        $segmentStations = $findShortestSegment($waypoints[$w], $waypoints[$w + 1]);

        if (!$segmentStations) {
            error_log("Routing-Fehler: Kein Schienenweg zwischen " . $waypoints[$w] . " und " . $waypoints[$w + 1]);
            return [];
        }

        // Ersten Eintrag abschneiden, um Doppelungen an den Übergabeknoten zu verhindern
        if (!empty($completePath)) {
            $segmentStations = array_slice($segmentStations, 1);
        }

        $completePath = array_merge($completePath, $segmentStations);
    }

    // Kilometer-Summe präzise über die realen Kanten des finalen Pfads neu aufbauen
    $totalKm = 0;
    for ($i = 0; $i < count($completePath) - 1; $i++) {
        $totalKm += abs(($completePath[$i+1]['km'] ?? 0) - ($completePath[$i]['km'] ?? 0));
    }

    return [
        [
            'stations' => $completePath,
            'total_km' => $totalKm
        ]
    ];
}

/**
 * Berechnet Fahrtzeiten mit korrekter km-Berechnung
 */
function calculatePathTimes($pathData, $speed, $ROUTES) {
    $pathStations = $pathData['stations'] ?? [];
    
    if (empty($pathStations)) {
        return null;
    }

    $result = [
        'stations' => [],
        'total_km' => $pathData['total_km'] ?? 0,
        'total_time' => 0,
        'speed' => $speed,
        'route_name' => 'Umgeleitet'
    ];

    // Route-Name ermitteln
    if (!empty($pathData['segments']) && !empty($pathData['segments'][0]['routes'])) {
        $firstRouteId = $pathData['segments'][0]['routes'][0];
        if (isset($ROUTES[$firstRouteId])) {
            $result['route_name'] = $ROUTES[$firstRouteId]['name'] ?? 'Umgeleitet';
        }
    }

    // Interne Berechnung in Sekunden verhindert das Aufsummieren von Rundungsfehlern
    $currentSeconds = 0;
    $cumulativeKm = 0;

    foreach ($pathStations as $i => $station) {
        $stationKm = $station['km'] ?? 0;
        // Erlaubt optionale Aufenthalte pro Station (Standard: 0 Minuten)
        $haltMinutes = $station['halt_min'] ?? 0; 

        if ($i > 0) {
            $prevStation = $pathStations[$i - 1];
            $prevKm = $prevStation['km'] ?? 0;
            
            $distanceKm = abs($stationKm - $prevKm);
            
            if ($distanceKm > 0 && $speed > 0) {
                // Reine Fahrzeit in Sekunden: (Distanz / Geschwindigkeit) * 3600
                $travelSeconds = ($distanceKm / $speed) * 3600;
                $currentSeconds += $travelSeconds;
            }

            $cumulativeKm += $distanceKm;
        }

        // Ankunftszeit sekundengenau berechnen und erst für die Ausgabe in Minuten runden
        $arrivalTime = (int)round($currentSeconds / 60);
        
        // Aufenthalt am Betriebspunkt hinzurechnen
        if ($haltMinutes > 0) {
            $currentSeconds += $haltMinutes * 60;
        }
        
        // Abfahrtszeit nach dem Halt
        $departureTime = (int)round($currentSeconds / 60);

        $result['stations'][] = [
            'id' => $station['id'] ?? strtoupper($station['abbr'] ?? ''),
            'abbr' => strtoupper($station['abbr'] ?? ''),
            'name' => $station['name'] ?? '?',
            'km' => $stationKm,
            'arrival_time' => $arrivalTime,
            'departure_time' => $departureTime,
            'cumulative_km' => round($cumulativeKm, 2),
            'halt_min' => $haltMinutes
        ];
    }

    $result['total_km'] = round($cumulativeKm, 2);
    $result['total_time'] = (int)round($currentSeconds / 60);

    return $result;
}

try {
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Datenbankfehler: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // ========================================================================
    // ACTION: find_routes
    // Findet alle möglichen Routen-Kombinationen Start → Vias → Ziel
    // ========================================================================
    if ($action === 'find_routes') {
        $start = $_POST['start'] ?? '';
        $vias = isset($_POST['vias']) ? (is_array($_POST['vias']) ? $_POST['vias'] : explode(',', $_POST['vias'])) : [];
        $destination = $_POST['destination'] ?? '';
        $speed = intval($_POST['speed'] ?? 90);

        $vias = array_filter($vias, fn($v) => !empty(trim($v)));

        if (empty($start) || empty($destination)) {
            http_response_code(400);
            echo json_encode(['error' => 'Start und Ziel erforderlich']);
            exit;
        }

        // Vias normalisieren (Großbuchstaben)
        $vias = array_map('strtoupper', $vias);
        $start = strtoupper($start);
        $destination = strtoupper($destination);

        // Finde alle Stationen in den Routes
        $stationMap = [];
        $routeMap = [];

        foreach ($ROUTES as $routeId => $route) {
            if (!isset($route['stations']) || !is_array($route['stations'])) continue;

            foreach ($route['stations'] as $station) {
                $abbr = strtoupper($station['abbr'] ?? '');
                if (!isset($stationMap[$abbr])) {
                    $stationMap[$abbr] = [];
                }
                $stationMap[$abbr][] = [
                    'route_id' => $routeId,
                    'route_name' => $route['name'],
                    'station' => $station,
                    'index' => count($routeMap[$routeId] ?? [])
                ];
            }

            $routeMap[$routeId] = $route['stations'];
        }

        // Validiere Start, Vias, Destination
        if (!isset($stationMap[$start])) {
            http_response_code(400);
            echo json_encode(['error' => "Startstation '$start' nicht gefunden"]);
            exit;
        }
        if (!isset($stationMap[$destination])) {
            http_response_code(400);
            echo json_encode(['error' => "Zielstation '$destination' nicht gefunden"]);
            exit;
        }
        foreach ($vias as $via) {
            if (!isset($stationMap[$via])) {
                http_response_code(400);
                echo json_encode(['error' => "Via-Station '$via' nicht gefunden"]);
                exit;
            }
        }

        // Finde Routen-Pfade: Start → Via1 → Via2 → ... → Ziel
        $waypoints = [$start, ...$vias, $destination];
        $paths = findRoutePaths($ROUTES, $waypoints, $stationMap);

        if (empty($paths)) {
            echo json_encode([
                'success' => false,
                'message' => 'Keine Route gefunden, die alle Punkte verbindet. Bitte Vias überprüfen.',
                'found_routes' => []
            ]);
            exit;
        }

        // Berechne Fahrtzeiten für jeden Pfad
        $results = [];
        foreach ($paths as $path) {
            $result = calculatePathTimes($path, $speed, $ROUTES);
            $results[] = $result;
        }

        echo json_encode([
            'success' => true,
            'speed' => $speed,
            'paths' => $results
        ]);
        exit;
    }

    // ========================================================================
    // ACTION: get_train_data
    // Lädt existierenden Zug-Fahrplan (falls vorhanden)
    // ========================================================================
    if ($action === 'get_train_data') {
        $train_number = $_POST['train_number'] ?? '';

        if (empty($train_number)) {
            echo json_encode(['train' => null, 'timetable' => []]);
            exit;
        }

        $stmt = $db->prepare("SELECT id, train_number, route_id, sts_zid FROM trains WHERE train_number = ?");
        $stmt->execute([$train_number]);
        $train = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$train) {
            echo json_encode(['train' => null, 'timetable' => []]);
            exit;
        }

        $stmt = $db->prepare("SELECT * FROM timetable WHERE train_id = ? ORDER BY arrival ASC, departure ASC, id ASC");
        $stmt->execute([$train['id']]);
        $timetable = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['train' => $train, 'timetable' => $timetable]);
        exit;
    }

    // ========================================================================
    // ACTION: save_redirect
    // Speichert die neue umgeleitete Fahrplan in die Datenbank
    // ========================================================================
    if ($action === 'save_redirect') {
        $train_number = intval($_POST['train_number'] ?? 0);
        $stations_data = $_POST['stations'] ?? [];

        if ($train_number <= 0 || empty($stations_data)) {
            http_response_code(400);
            echo json_encode(['error' => 'Zugnummer und Stations-Daten erforderlich']);
            exit;
        }

        $db->beginTransaction();
        try {
            // Erstelle oder lade Zug
            $stmt = $db->prepare("INSERT OR IGNORE INTO trains (train_number, route_id) VALUES (?, ?)");
            $stmt->execute([$train_number, 'redirect']);

            $stmt = $db->prepare("SELECT id FROM trains WHERE train_number = ?");
            $stmt->execute([$train_number]);
            $train = $stmt->fetch(PDO::FETCH_ASSOC);
            $train_id = $train['id'];

            // Lösche alte Einträge
            $db->prepare("DELETE FROM timetable WHERE train_id = ?")->execute([$train_id]);

            // Schreibe neue Einträge
            $stmtInsert = $db->prepare("
                INSERT INTO timetable (train_id, station_id, track, arrival, departure, flags, remarks)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($stations_data as $st_id => $fields) {
                $isEmpty = empty($fields['arrival']) && empty($fields['departure']) && empty($fields['track']);

                if (!$isEmpty) {
                    $stmtInsert->execute([
                        $train_id,
                        $st_id,
                        $fields['track'] ?? '',
                        $fields['arrival'] ?? '',
                        $fields['departure'] ?? '',
                        $fields['flags'] ?? '',
                        $fields['remarks'] ?? ''
                    ]);
                }
            }

            $db->commit();
            echo json_encode(['success' => true, 'train_id' => $train_id]);
        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // ========================================================================
    // ACTION: get_routes
    // Gibt alle verfügbaren Routen zurück
    // ========================================================================
    if ($action === 'get_routes') {
        echo json_encode($ROUTES);
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Umleitung-Planer</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { margin: 20px; }
        .planner-container { max-width: 1200px; margin: 0 auto; }
        .panel { background: #1e293b; border: 1px solid #334155; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        h1 { color: #f1f5f9; margin: 0 0 20px 0; }
        h2 { color: #f1f5f9; font-size: 18px; margin: 0 0 15px 0; border-bottom: 1px solid #334155; padding-bottom: 10px; }
        
        .form-row { display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 15px; }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        label { color: #94a3b8; font-size: 13px; font-weight: 600; }
        input, select { background: #334155; color: #fff; border: 1px solid #475569; padding: 8px 12px; border-radius: 4px; font-size: 14px; }
        input:focus, select:focus { outline: none; border-color: #0ea5e9; }
        
        button { background: #475569; color: #fff; border: none; padding: 8px 16px; border-radius: 4px; font-weight: 600; cursor: pointer; transition: background 0.2s; }
        button:hover { background: #64748b; }
        button.primary { background: #0ea5e9; }
        button.primary:hover { background: #0284c7; }
        button.success { background: #10b981; }
        button.success:hover { background: #059669; }
        button.danger { background: #ef4444; }
        button.danger:hover { background: #dc2626; }

        .results-container { display: none; }
        .route-option { background: #0f172a; border: 1px solid #334155; padding: 12px; border-radius: 4px; margin-bottom: 10px; cursor: pointer; transition: background 0.2s; }
        .route-option:hover { background: #1e293b; border-color: #0ea5e9; }
        .route-option.selected { background: #1e293b; border-color: #0ea5e9; box-shadow: 0 0 8px rgba(14, 165, 233, 0.3); }
        
        .route-info { font-size: 13px; color: #94a3b8; margin-top: 8px; }
        .route-stations { color: #f1f5f9; font-weight: 600; margin-bottom: 8px; }
        
        .editor-container { display: none; margin-top: 20px; }
        
        #editor_table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 15px; }
        #editor_table thead { background: #0f172a; }
        #editor_table th, #editor_table td { padding: 10px; text-align: left; border-bottom: 1px solid #334155; }
        #editor_table th { color: #94a3b8; font-weight: 600; }
        #editor_table td { color: #f1f5f9; }
        #editor_table input { width: 100%; padding: 4px 8px; }
        
        .checkbox-col { text-align: center; }
        .checkbox-col input[type="checkbox"] { width: auto; }
        
        .station-new { background: rgba(16, 185, 129, 0.1); }
        .station-removed { background: rgba(239, 68, 68, 0.1); text-decoration: line-through; }
        
        .info-box { background: rgba(14, 165, 233, 0.1); border-left: 3px solid #0ea5e9; padding: 12px; margin-bottom: 15px; border-radius: 4px; font-size: 13px; color: #94a3b8; }
        .success-box { background: rgba(16, 185, 129, 0.1); border-left: 3px solid #10b981; padding: 12px; margin-bottom: 15px; border-radius: 4px; font-size: 13px; color: #10b981; }
        .error-box { background: rgba(239, 68, 68, 0.1); border-left: 3px solid #ef4444; padding: 12px; margin-bottom: 15px; border-radius: 4px; font-size: 13px; color: #ef4444; }
    </style>
</head>
<body>

<div class="planner-container">
    <h1>🛤️ Umleitung-Planer</h1>

    <!-- INPUT PANEL -->
    <div class="panel">
        <h2>Route planen</h2>
        <p style="color: #94a3b8; margin-bottom: 15px; font-size: 13px;">Gib Start, optionale Vias und Ziel ein. Das System findet alle möglichen Routen.</p>
        
        <div class="form-row">
            <div class="form-group" style="flex: 1; min-width: 150px;">
                <label for="train_number">Zug-Nummer (optional):</label>
                <input type="text" id="train_number" inputmode="numeric" placeholder="z.B. 421" oninput="this.value=this.value.replace(/[^0-9]/g,'');" onchange="loadTrainData()">
            </div>

            <div class="form-group" style="flex: 1; min-width: 150px;">
                <label for="start_station">Start-Station:</label>
                <input type="text" id="start_station" placeholder="z.B. ZF, BN, etc." style="text-transform: uppercase;" oninput="this.value=this.value.toUpperCase()">
            </div>

            <div class="form-group" style="flex: 1; min-width: 150px;">
                <label for="destination_station">Ziel-Station:</label>
                <input type="text" id="destination_station" placeholder="z.B. ZF, BN, etc." style="text-transform: uppercase;" oninput="this.value=this.value.toUpperCase()">
            </div>

            <div class="form-group" style="flex: 1; min-width: 150px;">
                <label for="speed">Geschwindigkeit (km/h):</label>
                <input type="range" id="speed" min="40" max="200" value="90" style="padding: 0; height: 32px;">
                <span id="speed_display" style="color: #94a3b8; font-size: 12px; text-align: center;">90 km/h</span>
            </div>

            <button class="primary" onclick="findRoutes()">🔍 Routen suchen</button>
        </div>

        <!-- Vias Input -->
        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #334155;">
            <label style="color: #94a3b8; font-size: 13px; font-weight: 600; display: block; margin-bottom: 10px;">Vias (optional, mehrfach):</label>
            <div id="vias_container" style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 10px;">
                <input type="text" class="via-input" placeholder="z.B. LS" style="flex: 0 0 120px; text-transform: uppercase;" oninput="this.value=this.value.toUpperCase()">
            </div>
            <button type="button" style="background: #475569;" onclick="addViaField()">+ Via hinzufügen</button>
        </div>
    </div>

    <!-- RESULTS PANEL -->
    <div class="panel results-container" id="results_panel" style="display: none;">
        <h2>🛤️ Gefundene Routen</h2>
        <div id="results_list"></div>
    </div>

    <!-- GELADEN FAHRPLAN PANEL -->
    <div class="panel" id="loaded_timetable_panel" style="display: none; max-height: 400px; overflow-y: auto;">
        <h2>📋 Aktueller Fahrplan: Zug <span id="loaded_train_num"></span></h2>
        <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
            <thead style="position: sticky; top: 0; background: #0f172a; z-index: 10;">
                <tr>
                    <th style="padding: 8px; text-align: left; border-bottom: 1px solid #334155; color: #94a3b8;">Station</th>
                    <th style="padding: 8px; text-align: left; border-bottom: 1px solid #334155; color: #94a3b8;">Gleis</th>
                    <th style="padding: 8px; text-align: left; border-bottom: 1px solid #334155; color: #94a3b8;">Soll-Ank.</th>
                    <th style="padding: 8px; text-align: left; border-bottom: 1px solid #334155; color: #94a3b8;">Soll-Abf.</th>
                    <th style="padding: 8px; text-align: left; border-bottom: 1px solid #334155; color: #94a3b8;">Ist-Ank.</th>
                    <th style="padding: 8px; text-align: left; border-bottom: 1px solid #334155; color: #94a3b8;">Ist-Abf.</th>
                </tr>
            </thead>
            <tbody id="loaded_timetable_tbody">
            </tbody>
        </table>
    </div>
        <h2>Gefundene Routen</h2>
        <div id="results_list"></div>
    </div>

    <!-- EDITOR PANEL -->
    <div class="panel editor-container" id="editor_panel">
        <h2>Umgeleiteter Fahrplan</h2>
        <p style="color: #94a3b8; margin-bottom: 15px; font-size: 13px;" id="editor_info">Route gewählt. Markiere übersprungene Halte oder ergänze weitere Informationen.</p>
        
        <div id="current_train_info" style="margin-bottom: 15px; padding: 12px; background: #0f172a; border-radius: 4px; display: none;">
            <span style="color: #94a3b8;">Zug:</span> <strong id="current_train_num" style="color: #0ea5e9;"></strong>
            <span style="margin-left: 20px; color: #94a3b8;">Route:</span> <strong id="current_route_name" style="color: #0ea5e9;"></strong>
        </div>

        <form id="redirect_form" onsubmit="saveRedirect(event)">
            <table id="editor_table">
                <thead>
                    <tr>
                        <th style="width: 30px;">✗</th>
                        <th>Bahnhof</th>
                        <th style="width: 60px;">Gleis</th>
                        <th style="width: 80px;">Ankunft</th>
                        <th style="width: 80px;">Abfahrt</th>
                        <th style="width: 80px;">Halt (H/H2/H10)</th>
                        <th>Bemerkung</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>

            <div style="margin-top: 15px; display: flex; gap: 10px;">
                <button type="submit" class="success">💾 Fahrplan speichern</button>
                <button type="button" style="background: #64748b;" onclick="resetEditor()">← Zurück</button>
            </div>
        </form>
    </div>
</div>

<script>
const ROUTES = {};
let allStations = {};
let currentSpeed = 90;
let selectedPath = null;
let oldTimetable = {};

document.getElementById('speed').addEventListener('input', function() {
    currentSpeed = parseInt(this.value, 10);
    document.getElementById('speed_display').innerText = currentSpeed + ' km/h';
});

async function initPlanner() {
    const formData = new FormData();
    formData.append('action', 'get_routes');
    
    const res = await fetch('', { method: 'POST', body: formData });
    const data = await res.json();
    
    Object.assign(ROUTES, data);
    
    // Baue Stations-Index
    for (const routeId in ROUTES) {
        const route = ROUTES[routeId];
        if (route.stations && Array.isArray(route.stations)) {
            route.stations.forEach(st => {
                const abbr = st.abbr.toUpperCase();
                if (!allStations[abbr]) {
                    allStations[abbr] = [];
                }
                allStations[abbr].push({ ...st, routeId });
            });
        }
    }
}

function addViaField() {
    const container = document.getElementById('vias_container');
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'via-input';
    input.placeholder = 'z.B. LS';
    input.style.cssText = 'flex: 0 0 120px; text-transform: uppercase;';
    input.oninput = function() { this.value = this.value.toUpperCase(); };
    container.appendChild(input);
}

async function findRoutes() {
    const start = document.getElementById('start_station').value.trim().toUpperCase();
    const destination = document.getElementById('destination_station').value.trim().toUpperCase();
    const viaInputs = Array.from(document.querySelectorAll('.via-input'));
    const vias = viaInputs.map(i => i.value.trim().toUpperCase()).filter(v => v);
    const speed = currentSpeed;

    console.log('🔍 DEBUG - Eingabeparameter:');
    console.log('Start:', start);
    console.log('Via-Inputs gefunden:', viaInputs.length);
    viaInputs.forEach((inp, idx) => console.log(`  Via[${idx}] = "${inp.value}"`));
    console.log('Gefilterte Vias:', vias);
    console.log('Destination:', destination);
    console.log('Speed:', speed);

    if (!start || !destination) {
        alert('Bitte gib Start und Ziel ein');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'find_routes');
    formData.append('start', start);
    vias.forEach((via, idx) => formData.append(`vias[${idx}]`, via)); // Array-Format
    formData.append('destination', destination);
    formData.append('speed', speed);

    try {
        const res = await fetch('', { method: 'POST', body: formData });
        const data = await res.json();

        if (!data.success) {
            showResultsPanel(false);
            alert(data.message || 'Keine Route gefunden');
            return;
        }

        displayRoutePaths(data.paths);
        showResultsPanel(true);
    } catch (err) {
        console.error('Fehler:', err);
        alert('Fehler beim Suchen');
    }
}

function displayRoutePaths(paths) {
    const list = document.getElementById('results_list');
    list.innerHTML = '';

    paths.forEach((path, index) => {
        const div = document.createElement('div');
        div.className = 'route-option';
        div.id = `route_${index}`;
        div.onclick = () => selectRoute(index, path, div);

        const stationList = path.stations.map(s => `<strong>${s.abbr}</strong>`).join(' → ');
        const timeString = minutesToTime(path.total_time);
        const distanceKm = path.total_km.toFixed(1);

        div.innerHTML = `
            <div class="route-stations">${stationList}</div>
            <div class="route-info">
                <span>📍 ${distanceKm} km</span>
                <span style="margin-left: 15px;">⏱️ ${timeString} (${path.total_time} min)</span>
                <span style="margin-left: 15px;">🚄 ${path.speed} km/h</span>
            </div>
        `;

        list.appendChild(div);
    });
}

function selectRoute(index, path, element) {
    document.querySelectorAll('.route-option').forEach(e => e.classList.remove('selected'));
    element.classList.add('selected');
    selectedPath = path;
    showEditorPanel(true);
    buildEditorTable(path);
}

function buildEditorTable(path) {
    const tbody = document.getElementById('editor_table').querySelector('tbody');
    tbody.innerHTML = '';

    const trainNum = document.getElementById('train_number').value || '???';
    document.getElementById('current_train_num').innerText = trainNum;
    document.getElementById('current_route_name').innerText = path.route_name || 'Umgeleitet';
    document.getElementById('current_train_info').style.display = 'block';

    // Erstelle Set von umgeleiteten Station-IDs
    const redirectedStationIds = new Set(path.stations.map(s => s.abbr.toUpperCase()));
    const redirectStart = path.stations[0].abbr.toUpperCase();
    const redirectEnd = path.stations[path.stations.length - 1].abbr.toUpperCase();

    // ===== Finde Original-Stationen ZWISCHEN Start und End (die ausfallen) =====
    const falloutStations = [];
    let inRange = false;
    
    oldTimetable.forEach(stop => {
        const abbr = stop.station_id.toUpperCase();
        
        if (abbr === redirectStart) {
            inRange = true;
            return;
        }
        
        if (abbr === redirectEnd) {
            inRange = false;
            return;
        }
        
        if (inRange) {
            falloutStations.push(stop);
        }
    });

    // ===== TEIL 1: Original-Stationen VOR Umleitung =====
    let foundStart = false;
    oldTimetable.forEach(stop => {
        const abbr = stop.station_id.toUpperCase();
        if (abbr === redirectStart) {
            foundStart = true;
            return;
        }
        if (foundStart) return;

        const tr = document.createElement('tr');
        tr.style.background = 'rgba(100, 116, 139, 0.2)'; // Grau = Original
        
        tr.innerHTML = `
            <td class="checkbox-col"></td>
            <td style="color: #94a3b8;">
                <strong>${stop.station_id}</strong> (Original)
            </td>
            <td><input type="text" value="${stop.track || ''}" disabled style="opacity: 0.6;"></td>
            <td><input type="time" value="${stop.arrival || ''}" disabled style="opacity: 0.6;"></td>
            <td><input type="time" value="${stop.departure || ''}" disabled style="opacity: 0.6;"></td>
            <td></td>
            <td><input type="text" value="${stop.remarks || ''}" disabled style="opacity: 0.6;"></td>
        `;
        tbody.appendChild(tr);
    });

    // ===== TEIL 2: Umgeleitete Stationen =====
    path.stations.forEach((station, index) => {
        const tr = document.createElement('tr');
        const abbr = station.abbr.toUpperCase();
        const wasInOldRoute = oldTimetable.some(t => t.station_id.toUpperCase() === abbr);

        // Styling: Grün wenn alt, Blau wenn neu
        if (wasInOldRoute) {
            tr.style.background = 'rgba(34, 197, 94, 0.08)'; // Grün für alte Halte
        } else {
            tr.style.background = 'rgba(14, 165, 233, 0.08)'; // Blau für neue Halte
        }

        tr.innerHTML = `
            <td class="checkbox-col">
                <input type="checkbox" id="skip_${abbr}" 
                       ${wasInOldRoute ? '' : 'disabled'} 
                       title="${wasInOldRoute ? 'Halt in alter Route - kann übersprungen werden' : 'Neuer Halt - wird nicht übersprungen'}">
            </td>
            <td>
                <strong>${station.name}</strong> (${abbr})
                ${wasInOldRoute ? '<span style="color: #64748b; font-size: 11px; margin-left: 8px;">🗂️ alt</span>' : '<span style="color: #0ea5e9; font-size: 11px; margin-left: 8px;">✨ neu</span>'}
            </td>
            <td><input type="text" name="stations[${abbr}][track]" placeholder="z.B. 3" size="5"></td>
            <td><input type="time" name="stations[${abbr}][arrival]" value="${minutesToTime(station.arrival_time)}"></td>
            <td><input type="time" name="stations[${abbr}][departure]" value="${minutesToTime(station.departure_time)}"></td>
            <td><input type="text" name="stations[${abbr}][halt]" placeholder="H oder H2" size="5"></td>
            <td><input type="text" name="stations[${abbr}][remarks]" placeholder="Bemerkung"></td>
        `;

        tbody.appendChild(tr);
    });

    // ===== TEIL 3: Ausfallstationen (Original zwischen Start und End) =====
    if (falloutStations.length > 0) {
        // Trennlinie
        const separatorTr = document.createElement('tr');
        separatorTr.style.background = 'rgba(239, 68, 68, 0.15)';
        separatorTr.innerHTML = `<td colspan="7" style="padding: 10px; color: #ef4444; font-weight: bold; text-align: center;">⚠️ Ausfallstationen (Original)</td>`;
        tbody.appendChild(separatorTr);

        falloutStations.forEach(stop => {
            const tr = document.createElement('tr');
            tr.style.background = 'rgba(239, 68, 68, 0.1)'; // Rot = Ausfall
            
            tr.innerHTML = `
                <td class="checkbox-col">
                    <input type="checkbox" id="fallout_${stop.station_id.toUpperCase()}" checked title="Dieser Halt fällt aus (ist nicht in der Umleitung)">
                </td>
                <td style="color: #ef4444; text-decoration: line-through;">
                    <strong>${stop.station_id}</strong> ✗ AUSFALL
                </td>
                <td><input type="text" value="${stop.track || ''}" disabled style="opacity: 0.5;"></td>
                <td><input type="time" value="${stop.arrival || ''}" disabled style="opacity: 0.5;"></td>
                <td><input type="time" value="${stop.departure || ''}" disabled style="opacity: 0.5;"></td>
                <td></td>
                <td><input type="text" value="${stop.remarks || ''}" disabled style="opacity: 0.5;"></td>
            `;
            tbody.appendChild(tr);
        });
    }

    // ===== TEIL 4: Original-Stationen NACH Umleitung =====
    foundStart = false;
    let foundEnd = false;
    
    oldTimetable.forEach(stop => {
        const abbr = stop.station_id.toUpperCase();
        
        if (abbr === redirectStart) {
            foundStart = true;
            return;
        }
        
        if (abbr === redirectEnd) {
            foundEnd = true;
            return;
        }
        
        if (!foundStart || !foundEnd) return;

        const tr = document.createElement('tr');
        tr.style.background = 'rgba(100, 116, 139, 0.2)'; // Grau = Original
        
        tr.innerHTML = `
            <td class="checkbox-col"></td>
            <td style="color: #94a3b8;">
                <strong>${stop.station_id}</strong> (Original)
            </td>
            <td><input type="text" value="${stop.track || ''}" disabled style="opacity: 0.6;"></td>
            <td><input type="time" value="${stop.arrival || ''}" disabled style="opacity: 0.6;"></td>
            <td><input type="time" value="${stop.departure || ''}" disabled style="opacity: 0.6;"></td>
            <td></td>
            <td><input type="text" value="${stop.remarks || ''}" disabled style="opacity: 0.6;"></td>
        `;
        tbody.appendChild(tr);
    });
}

function timeToMinutes(timeStr) {
    if (!timeStr) return null;
    const parts = timeStr.split(':');
    if (parts.length < 2) return null;
    return parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10);
}

function minutesToTime(minutes) {
    if (minutes === null || isNaN(minutes)) return '--:--';
    const m = ((minutes % 1440) + 1440) % 1440;
    const h = Math.floor(m / 60);
    const min = m % 60;
    return `${String(h).padStart(2, '0')}:${String(min).padStart(2, '0')}`;
}

async function loadTrainData() {
    const trainNum = document.getElementById('train_number').value.trim();
    if (!trainNum) {
        oldTimetable = [];
        document.getElementById('editor_info').innerText = 'Route gewählt. Markiere übersprungene Halte oder ergänze weitere Informationen.';
        document.getElementById('loaded_timetable_panel').style.display = 'none';
        return;
    }

    const formData = new FormData();
    formData.append('action', 'get_train_data');
    formData.append('train_number', trainNum);

    try {
        const res = await fetch('', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.train) {
            oldTimetable = data.timetable || [];
            
            // Info-Text aktualisieren
            const stationList = oldTimetable.map(t => t.station_id).join(' → ');
            document.getElementById('editor_info').innerHTML = `
                <strong>Zug ${trainNum}</strong> geladen (Route: ${data.train.route_id})<br>
                <span style="color: #64748b; font-size: 12px;">Alte Halte: ${stationList || 'keine'}</span>
            `;
            
            // Fahrplan-Tabelle anzeigen
            displayLoadedTimetable(trainNum, oldTimetable);
            
            console.log(`Zug ${trainNum} geladen mit ${oldTimetable.length} Haltestellen`);
        } else {
            oldTimetable = [];
            document.getElementById('editor_info').innerText = `Zug ${trainNum} nicht in DB - neuer Fahrplan wird erstellt`;
            document.getElementById('loaded_timetable_panel').style.display = 'none';
            console.log(`Zug ${trainNum} nicht gefunden, neuer Fahrplan`);
        }
    } catch (err) {
        console.error('Fehler beim Laden des Zuges:', err);
        oldTimetable = [];
    }
}

function displayLoadedTimetable(trainNum, timetable) {
    const panel = document.getElementById('loaded_timetable_panel');
    const tbody = document.getElementById('loaded_timetable_tbody');
    
    document.getElementById('loaded_train_num').innerText = trainNum;
    tbody.innerHTML = '';
    
    if (!timetable || timetable.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="padding: 20px; text-align: center; color: #64748b;">Keine Haltestellen im Fahrplan</td></tr>';
        panel.style.display = 'block';
        return;
    }
    
    timetable.forEach((stop, idx) => {
        const tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid #334155';
        if (idx % 2 === 0) tr.style.background = 'rgba(51, 65, 85, 0.3)';
        
        tr.innerHTML = `
            <td style="padding: 8px; color: #f1f5f9;"><strong>${stop.station_id}</strong></td>
            <td style="padding: 8px; color: #94a3b8;">${stop.track || '—'}</td>
            <td style="padding: 8px; color: #94a3b8;">${stop.arrival || '—'}</td>
            <td style="padding: 8px; color: #94a3b8;">${stop.departure || '—'}</td>
            <td style="padding: 8px; color: #10b981;">${stop.actual_arrival || '—'}</td>
            <td style="padding: 8px; color: #10b981;">${stop.actual_departure || '—'}</td>
        `;
        
        tbody.appendChild(tr);
    });
    
    panel.style.display = 'block';
}

async function saveRedirect(e) {
    e.preventDefault();

    const trainNum = document.getElementById('train_number').value.trim();
    if (!trainNum) {
        alert('Bitte gib eine Zugnummer ein');
        return;
    }

    if (!selectedPath) {
        alert('Bitte wähle zuerst eine Route aus');
        return;
    }

    const formData = new FormData(document.getElementById('redirect_form'));
    formData.append('action', 'save_redirect');
    formData.append('train_number', trainNum);

    try {
        const res = await fetch('', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            alert('Umgeleiteter Fahrplan gespeichert! (Zug-ID: ' + data.train_id + ')');
            resetEditor();
        } else {
            alert('Fehler: ' + (data.error || 'Unbekannt'));
        }
    } catch (err) {
        console.error('Fehler beim Speichern:', err);
        alert('Verbindungsfehler');
    }
}

function resetEditor() {
    document.getElementById('start_station').value = '';
    document.getElementById('destination_station').value = '';
    document.getElementById('train_number').value = '';
    document.getElementById('vias_container').innerHTML = '<input type="text" class="via-input" placeholder="z.B. LS" style="flex: 0 0 120px; text-transform: uppercase;" oninput="this.value=this.value.toUpperCase()">';
    document.getElementById('speed').value = 90;
    document.getElementById('speed_display').innerText = '90 km/h';
    currentSpeed = 90;
    selectedPath = null;
    oldTimetable = {};
    showResultsPanel(false);
    showEditorPanel(false);
}

function showResultsPanel(show) {
    document.getElementById('results_panel').style.display = show ? 'block' : 'none';
}

function showEditorPanel(show) {
    document.getElementById('editor_panel').style.display = show ? 'block' : 'none';
}

window.onload = initPlanner;
</script>
</body>
</html>