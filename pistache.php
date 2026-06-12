<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Verhindert, dass PHP-Fehlermeldungen das JSON beschädigen

date_default_timezone_set('Europe/Berlin');
header('Content-Type: application/json');

$log_file = __DIR__ . '/pistache_debug.log';

function write_log($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

write_log("--- NEUER REQUEST EMPFANGEN ---");

set_exception_handler(function ($e) {
    write_log("CRITICAL EXCEPTION: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
});

if (!file_exists(__DIR__ . '/routes.php')) {
    write_log("ERROR: routes.php fehlt!");
    echo json_encode(['success' => false, 'message' => 'routes.php fehlt auf dem Server.']);
    exit;
}
require_once __DIR__ . '/routes.php';

$db_file = __DIR__ . '/dbs/fahrplan.sqlite';
$json_file = __DIR__ . '/dbs/live_sts_zuege.json';

try {
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    write_log("DATENBANK-FEHLER: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Datenbankfehler']);
    exit;
}

// Hilfsfunktion: Rechnet Minuten auf eine Zeit (HH:MM) drauf
function addMinutes($timeStr, $min) {
    if (empty($timeStr)) return null;
    $p = explode(':', $timeStr);
    if (count($p) < 2) return null;
    $total = intval($p[0]) * 60 + intval($p[1]) + $min;
    if ($total < 0) $total += 1440; 
    return sprintf('%02d:%02d', floor($total / 60) % 24, $total % 60);
}

// =========================================================================
// VERBESSERTE DATENBANK-KASKADE MIT AUFHOLRESERVE & FLAG-RESPEKT
// =========================================================================
/**
 * Berechnet die Verspätungskaskade für Nachfolgerzüge mit:
 * - 7% Aufholreserve (Recovery Rate)
 * - Flag-Respekt (V, X, C1-7): Verspätung kann durch Recovery nicht reduziert werden
 * - Netzwerkweite Propagation (rekursiv)
 * - Wendezeit-Puffer
 */
function recalculateDelayCascade($db, $current_zid, $current_delay) {
    // Nur bei echter Verspätung kaskadieren
    if ($current_delay <= 0) return;

    global $write_log;
    
    // 1. Nachfolger finden
    $stmt = $db->prepare("SELECT successor_sts_zid FROM trains WHERE sts_zid = ?");
    $stmt->execute([$current_zid]);
    $successor_zid = $stmt->fetchColumn();

    if (empty($successor_zid)) return;
    
    // 2. Nachfolgerzug laden
    $stmt = $db->prepare("SELECT id, train_number FROM trains WHERE sts_zid = ?");
    $stmt->execute([$successor_zid]);
    $next_train = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$next_train) return;
    
    $next_train_id = $next_train['id'];
    $next_train_num = $next_train['train_number'];
    
    // 3. Alle Halte des Nachfolgerzuges laden (mit Flags!)
    $stmtStops = $db->prepare("
        SELECT station_id, arrival, departure, flags 
        FROM timetable 
        WHERE train_id = ? 
        ORDER BY id ASC
    ");
    $stmtStops->execute([$next_train_id]);
    $next_stops = $stmtStops->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($next_stops)) return;

    // 4. Wendezeit am ersten Halt berechnen
    $first_stop = $next_stops[0];
    $soll_arr_min = timeToMinutes($first_stop['arrival']);
    $soll_dep_min = timeToMinutes($first_stop['departure']);
    $wendezeit = $soll_dep_min - $soll_arr_min;
    
    // Die Abfahrt darf maximal um (Delay - Wendezeit) verzögert werden
    $abfahrts_delay = max(0, $current_delay - $wendezeit);
    
    // 5. Fahrtzeit der Gesamtstrecke für Aufholreserve
    $last_stop = end($next_stops);
    $gesamtfahrtzeit = timeToMinutes($last_stop['departure']) - $soll_arr_min;
    // 7% Aufholreserve
    $aufholreserve = max(1, intval(round($gesamtfahrtzeit * 0.07)));
    
    write_log("🔄 KASKADE: Nachfolgezug $next_train_num, Verspätung: +$current_delay Min, Aufholreserve: +$aufholreserve Min");
    
    // 6. Update der Halte mit Flag-Respekt
    foreach ($next_stops as $ns) {
        $station_id = $ns['station_id'];
        $soll_arr_min = timeToMinutes($ns['arrival']);
        $soll_dep_min = timeToMinutes($ns['departure']);
        $flags = $ns['flags'] ?? '';
        
        // Prüfe: Sind Dispositions-Flags gesetzt? (V, X, C, C1-7)
        $hasDispoFlag = !empty($flags) && preg_match('/^(X|V|C[4-7]?)\(/i', trim($flags));
        
        // Berechne Ankunftsverspätung mit Aufholreserve
        $arrival_delay = $current_delay - $aufholreserve;
        
        // WICHTIG: Wenn Flags gesetzt sind, nicht unter die aktuelle Verspätung reduzieren!
        // D.h. Aufholen ist erlaubt, aber nicht unter die ursprüngliche Verspätung
        if ($hasDispoFlag && $arrival_delay < $current_delay) {
            $arrival_delay = $current_delay;
        }
        
        // Ankunft: Mit Aufholreserve (oder minimal mit Flags)
        $act_arr_min = $soll_arr_min + $arrival_delay;
        
        // Abfahrt: Nutzt Abfahrts-Delay (weniger wegen Wendezeit), aber respektiert auch Ankunft
        $abfahrt_delay = $abfahrts_delay - $aufholreserve;
        
        // Wenn Flags: Nicht unter ursprüngliche Verspätung reduzieren
        if ($hasDispoFlag && $abfahrt_delay < $abfahrts_delay) {
            $abfahrt_delay = $abfahrts_delay;
        }
        
        $act_dep_min = max(
            $act_arr_min + ($soll_dep_min - $soll_arr_min),  // Mindestens Wendezeit nach Ankunft
            $soll_dep_min + $abfahrt_delay                   // Oder die berechnete Verspätung
        );
        
        $act_arr = minutesToTime($act_arr_min);
        $act_dep = minutesToTime($act_dep_min);
        
        // Berechne tatsächliche Verspätung für Remarks
        $final_dep_delay = timeToMinutes($act_dep) - $soll_dep_min;
        $flag_hint = $hasDispoFlag ? " [Flag-geschützt]" : "";
        
        $stmtUp = $db->prepare("
            UPDATE timetable 
            SET actual_arrival = ?, actual_departure = ?, remarks = ? 
            WHERE train_id = ? AND station_id = ?
        ");
        $stmtUp->execute([
            $act_arr, 
            $act_dep, 
            "+" . $final_dep_delay . " Min (Kaskade)" . $flag_hint,
            $next_train_id, 
            $station_id
        ]);
    }
    
    // 7. Kaskade weitergeben (mit Aufholreserve-reduzierter Verspätung)
    $final_delay_for_next = max(0, $abfahrts_delay - $aufholreserve);
    if ($final_delay_for_next > 0) {
        recalculateDelayCascade($db, $successor_zid, $final_delay_for_next);
    }
}

// Hilfsfunktionen (falls noch nicht vorhanden)
function timeToMinutes($timeStr) {
    if (empty($timeStr)) return 0;
    $p = explode(':', $timeStr);
    return (intval($p[0]) * 60) + intval($p[1]);
}

function minutesToTime($m) {
    $m = $m % 1440; // Tagessprung abfangen
    $h = floor($m / 60);
    $min = $m % 60;
    return sprintf("%02d:%02d", $h, $min);
}

/**
 * Propagiert die Verspätung eines Zuges auf alle nachfolgenden Halte der GLEICHEN Route.
 * Nutzt 90% Aufholreserve wie in app.js propagateForward()
 */
function propagateDelayForward($db, $train_id, $modified_station_id, $routes_config, $is_am_gleis = false) {
    global $write_log;
    
    // Aktuelle Uhrzeit im Minutentakt holen (z. B. "14:35" -> 875)
    $current_time_str = date('H:i');
    $current_time_min = timeToMinutes($current_time_str);
    
    write_log("🔄 PROPAGATION: Starte zeitabhängige Verspätungs-Propagation ab Station $modified_station_id (Aktuelle Uhrzeit: $current_time_str)");
    
    // 1. Hole alle Halte des Zuges (chronologisch)
    $stmt = $db->prepare("
        SELECT station_id, arrival, departure, actual_arrival, actual_departure, remarks
        FROM timetable 
        WHERE train_id = ? 
        ORDER BY id ASC
    ");
    $stmt->execute([$train_id]);
    $all_stops = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($all_stops)) return;
    
    // 2. Finde den Index der modifizierten Station
    $modified_index = null;
    foreach ($all_stops as $i => $stop) {
        if ($stop['station_id'] === $modified_station_id) {
            $modified_index = $i;
            break;
        }
    }
    
    if ($modified_index === null) return;
    
    // 3. Bestimme die aktuelle Verspätung an der modifizierten Station
    $mod_stop = $all_stops[$modified_index];
    $soll_dep_mod = timeToMinutes($mod_stop['departure']);
    $act_dep_mod = timeToMinutes($mod_stop['actual_departure']);
    
    if ($soll_dep_mod === 0 || $act_dep_mod === 0) {
        $soll_dep_mod = timeToMinutes($mod_stop['arrival']);
        $act_dep_mod = timeToMinutes($mod_stop['actual_arrival']);
    }
    
    $current_delay = $act_dep_mod - $soll_dep_mod;
    write_log("   Ausgangsverspätung an Station $modified_station_id: $current_delay Min.");
    
    // 4. Propagiere die Verspätung für alle nachfolgenden Halte
    for ($i = $modified_index + 1; $i < count($all_stops); $i++) {
        $stop = $all_stops[$i];
        $station_id = $stop['station_id'];
        
        $sollArr = timeToMinutes($stop['arrival']);
        $sollDep = timeToMinutes($stop['departure']);
        
        if ($sollArr === 0 && $sollDep === 0) continue;
        
        // ZEIT-CHECK: Bestimme, wann der Zug hier voraussichtlich abfahren soll.
        // Wenn bereits eine berechnete Ist-Zeit existiert, nehmen wir diese, sonst die Soll-Zeit.
        $predicted_dep_min = (!empty($stop['actual_departure'])) ? timeToMinutes($stop['actual_departure']) : $sollDep;
        if ($predicted_dep_min === 0) {
            $predicted_dep_min = (!empty($stop['actual_arrival'])) ? timeToMinutes($stop['actual_arrival']) : $sollArr;
        }
        
        // Wenn die vorausberechnete Abfahrt in der Vergangenheit liegt, wird der Halt geschützt.
        if ($predicted_dep_min < $current_time_min) {
            write_log("   ⏭️ ÜBERSPRUNGEN: Halt $station_id liegt zeitlich in der Vergangenheit (Erwartete Abfahrt: " . minutesToTime($predicted_dep_min) . ").");
            
            // Basis-Verspätung für die folgenden Stationen anhand dieses vergangenen Haltes aktualisieren
            $effective_soll = ($sollDep > 0) ? $sollDep : $sollArr;
            if ($predicted_dep_min > 0 && $effective_soll > 0) {
                $current_delay = $predicted_dep_min - $effective_soll;
            }
            continue;
        }
        
        // --- 5. Aufholreserve berechnen ---
        if ($current_delay > 0 && $i > 0) {
            $prev_soll_dep = timeToMinutes($all_stops[$i-1]['departure']);
            // Fallback, falls der vorherige Halt keine Abfahrtszeit hatte (Anfangsstation o.ä.)
            if ($prev_soll_dep === 0) {
                $prev_soll_dep = timeToMinutes($all_stops[$i-1]['arrival']);
            }
            
            if ($prev_soll_dep > 0 && $sollArr > $prev_soll_dep) {
                $section_travel_time = $sollArr - $prev_soll_dep;
                $recovery = max(0, intval(round($section_travel_time * 0.07)));
                $current_delay = max(0, $current_delay - $recovery);
            }
        }
        
        // --- 6. Neue Ist-Zeiten unter Berücksichtigung der Mindesthalthaltezeit berechnen ---
        // Wenn keine Soll-Ankunft existiert (z.B. Startbahnhof), nutzen wir die Soll-Abfahrt als Basis
        $baseArr = ($sollArr > 0) ? $sollArr : $sollDep;
        $newArrMin = $baseArr + $current_delay;
        
        // Mindesthaltzeit aus dem Plan berechnen (0 bei reinen Ankunfts-/Abfahrtshalten)
        $min_stoptime = ($sollDep > 0 && $sollArr > 0) ? max(0, $sollDep - $sollArr) : 0;
        
        // Neue Abfahrt erzwingt den Mindesthalt nach der tatsächlichen Ankunft
        if ($sollDep > 0) {
            $newDepMin = max($sollDep + $current_delay, $newArrMin + $min_stoptime);
            $current_delay = $newDepMin - $sollDep;
        } else {
            // Reiner Ankunftsbahnhof (Endstation)
            $newDepMin = $newArrMin;
        }
        
        $actual_arrival = ($sollArr > 0) ? minutesToTime($newArrMin) : null;
        $actual_departure = ($sollDep > 0) ? minutesToTime($newDepMin) : null;
        
        // --- 7. Update in die Datenbank ---
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
        
        $log_arr = $actual_arrival ?? "--:--";
        $log_dep = $actual_departure ?? "--:--";
        write_log("   📍 Zukünftiger Halt $station_id: Neu Ankunft $log_arr, Abfahrt $log_dep (+$current_delay Min)");
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_raw = file_get_contents('php://input');
    $input_data = json_decode($input_raw, true);
    
    $action = $_POST['action'] ?? $input_data['action'] ?? '';

    // =========================================================================
    // AKTION 1: Live-Zugliste für das Mapping-GUI sichern
    // =========================================================================
    if ($action === 'save_live_zugliste') {
        $zuege = $input_data['zuege'] ?? [];
        if (!empty($zuege)) {
            file_put_contents($json_file, json_encode($zuege, JSON_PRETTY_PRINT));
            write_log("Live-Zugliste mit " . count($zuege) . " Zügen gesichert.");
            echo json_encode(['success' => true, 'message' => 'Live-Zugliste erfolgreich gespeichert.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Keine Züge gefunden.']);
        }
        exit;
    }

    // =========================================================================
    // AKTION 2: Live-Delay Update vom Python-Plugin
    // =========================================================================
    if ($action === 'plugin_update_delay') {
        $sts_zid = $_POST['sts_zid'] ?? $input_data['sts_zid'] ?? '';
        $raw_gleis = $_POST['station_abbr'] ?? $input_data['station_abbr'] ?? '';
        $delay = isset($_POST['delay']) ? intval($_POST['delay']) : (isset($input_data['delay']) ? intval($input_data['delay']) : 0);

        if (empty($sts_zid) || empty($raw_gleis)) {
            write_log("WARNUNG: Parameter unvollständig.");
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Fehlende Parameter']);
            exit;
        }

        //preg_match('/^([a-zA-Z]+)(\d*)$/', trim($raw_gleis), $matches);
		preg_match('/^([a-zA-Z]+)\s*(\d*)$/', trim($raw_gleis), $matches);
        $station_abbr = isset($matches[1]) ? strtoupper($matches[1]) : strtoupper($raw_gleis);
        $track_number = isset($matches[2]) && $matches[2] !== '' ? $matches[2] : null;

        // 1. Suche den aktuellen Zug
        $stmt = $db->prepare("SELECT id, route_id, train_number FROM trains WHERE sts_zid = ?");
        $stmt->execute([$sts_zid]);
        $train = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$train) {
            echo json_encode(['success' => false, 'message' => "ZID $sts_zid nicht verknüpft."]);
            exit;
        }

        $train_id = $train['id'];
        $route_id = $train['route_id'];
        $train_num = $train['train_number'];

// 2. Station in routes.php suchen (mit globalem Fallback)
        $station_id = null;
        $plugin_abbr = strtoupper(trim($station_abbr));
        
        write_log("DEBUG: Suche Station mit Kürzel: '$plugin_abbr' in Route '$route_id'");

        // A) Erst: Suche in der spezifischen Route des Zuges
        if (isset($ROUTES[$route_id]['stations'])) {
            foreach ($ROUTES[$route_id]['stations'] as $st) {
                if (strtoupper(trim($st['abbr'])) === $plugin_abbr) {
                    $station_id = $st['id'];
                    break;
                }
            }
        }
        
        // B) FALLBACK: Wenn in der Route nichts gefunden, suche GLOBAL in ALLEN Routen!
        if (!$station_id) {
            write_log("DEBUG: Spezifische Suche fehlgeschlagen, starte globale Suche für '$plugin_abbr'...");
            foreach ($ROUTES as $rid => $route) {
                foreach ($route['stations'] as $st) {
                    if (strtoupper(trim($st['abbr'])) === $plugin_abbr) {
                        $station_id = $st['id'];
                        write_log("DEBUG: Station global in Route '$rid' gefunden (ID: $station_id).");
                        break 2; // Beendet beide Schleifen (die innere und die äußere)
                    }
                }
            }
        }

        // C) Finaler Check
        if (!$station_id) {
            write_log("FEHLER: Konnte Plugin-Kürzel '$plugin_abbr' nirgendwo in routes.php zuordnen.");
            echo json_encode(['success' => false, 'message' => "Station '$station_abbr' unbekannt."]);
            exit;
        }

        // 3. Soll-Zeiten holen
        $stmt = $db->prepare("SELECT arrival, departure, flags FROM timetable WHERE train_id = ? AND station_id = ?");
        $stmt->execute([$train_id, $station_id]);
        $timetable_entry = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$timetable_entry) {
            echo json_encode(['success' => false, 'message' => 'Kein Soll-Eintrag vorhanden.']);
            exit;
        }

        // 3.5 SCHUTZ: Prüfe ob dieser Halt mit "!" markiert ist (Fixverspätung)
        $flags = $timetable_entry['flags'] ?? '';
        if (preg_match('/!\s*\+?\d+/i', $flags)) {
            write_log("🔒 GESCHÜTZT: Zug $train_num an $station_abbr ist mit ! markiert → Plugin-Update ignoriert");
            echo json_encode(['success' => true, 'message' => "Halt ist fixiert (!), Plugin-Update ignoriert."]);
            exit;
        }

        // 3.6 AMGLEIS PRÜFUNG: Wenn am_gleis=true, ändere nur die Abfahrtszeit!
        $am_gleis = $_POST['am_gleis'] ?? $input_data['am_gleis'] ?? '0';
        $am_gleis_flag = ($am_gleis === '1' || $am_gleis === true);

        // 4. Ist-Zeiten für den aktuellen Bahnhof berechnen
        if ($am_gleis_flag) {
            // Zug ist bereits am Gleis → nur Abfahrtszeit ändern, Ankunftszeit bleibt gleich!
            $actual_arrival = null; // Nicht ändern
            $actual_departure = addMinutes($timetable_entry['departure'], $delay);
            write_log("⏳ AM GLEIS: Zug $train_num an $station_abbr - nur Abfahrt wird geändert (+$delay Min)");
        } else {
            // Zug kommt noch an → beide Zeiten ändern
            $actual_arrival = addMinutes($timetable_entry['arrival'], $delay);
            $actual_departure = addMinutes($timetable_entry['departure'], $delay);
            write_log("📍 VORLAUF: Zug $train_num an $station_abbr - An- und Abfahrt werden geändert (+$delay Min)");
        }

        // 5. In die Datenbank schreiben (mit Conditional Updates für NULL-Werte)
        if ($am_gleis_flag) {
            // Nur Abfahrt ändern (Ankunft bleibt unverändert)
            $stmtUpdate = $db->prepare("
                UPDATE timetable 
                SET actual_departure = ?, track = ?, remarks = ? 
                WHERE train_id = ? AND station_id = ?
            ");
            $stmtUpdate->execute([$actual_departure, $track_number ?? '', "+" . $delay . " (Am Gleis)", $train_id, $station_id]);
        } else {
            // Beide ändern (normal)
            $stmtUpdate = $db->prepare("
                UPDATE timetable 
                SET actual_arrival = ?, actual_departure = ?, track = ?, remarks = ? 
                WHERE train_id = ? AND station_id = ?
            ");
            $stmtUpdate->execute([$actual_arrival, $actual_departure, $track_number ?? '', "+" . $delay, $train_id, $station_id]);
        }
        
        write_log("🎉 DB-UPDATE: Zug $train_num an $station_abbr auf +$delay Min gesetzt.");

        // 1. Propagiere die Verspätung auf alle nachfolgenden Halte des GLEICHEN Zuges
        propagateDelayForward($db, $train_id, $station_id, $ROUTES);
        
        // 2. Optional: Cascade für Nachfolgerzug (nur wenn successor_sts_zid existiert)
        // Das werden wir separat aktivieren wenn nötig
        // recalculateDelayCascade($db, $sts_zid, $delay);

        // 6. Audit-Log schreiben (mit echtem Error-Handling)
        try {
            $sts_user = $_POST['sts_user'] ?? $input_data['sts_user'] ?? '';
            $sts_sim = $_POST['sts_sim'] ?? $input_data['sts_sim'] ?? '';
            $log_username = (!empty($sts_user) && !empty($sts_sim)) ? "$sts_user ($sts_sim)" : "STS-Plugin";

            $stmtLog = $db->prepare("INSERT INTO audit_log (train_number, username, timestamp) VALUES (?, ?, ?)");
            $result = $stmtLog->execute([$train_num, $log_username, date('Y-m-d H:i:s')]);
            
            if ($result) {
                write_log("✓ Audit-Log geschrieben: $train_num von $log_username");
            } else {
                write_log("✗ FEHLER beim Schreiben des Audit-Logs für Zug $train_num");
            }
        } catch (Exception $logEx) {
            write_log("✗ EXCEPTION beim Audit-Log: " . $logEx->getMessage());
        }

        echo json_encode(['success' => true, 'message' => "Daten verarbeitet und Kaskade für Nachfolger berechnet."]);
        exit;
    }

    // =========================================================================
    // AKTIONEN FÜR DAS FRONTEND
    // =========================================================================
    if ($action === 'get_routes') {
        echo json_encode($ROUTES ?? []);
        exit;
    }

    if ($action === 'get_all_data') {
        $route_id = $_POST['route_id'] ?? $input_data['route_id'] ?? '';
        
        $stmt = $db->prepare("SELECT id, train_number, sts_zid, successor_sts_zid FROM trains WHERE route_id = ?");
        $stmt->execute([$route_id]);
        $trains = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($trains as $t) {
            $stmt2 = $db->prepare("SELECT station_id, track, arrival, departure, actual_arrival, actual_departure, flags, remarks FROM timetable WHERE train_id = ? ORDER BY id ASC");
            $stmt2->execute([$t['id']]);
            $stops = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            $t['stops'] = $stops;
            $result[] = $t;
        }
        echo json_encode($result);
        exit;
    }

    if ($action === 'get_audit_log') {
        try {
            $stmt = $db->query("SELECT train_number, username, timestamp FROM audit_log ORDER BY id DESC LIMIT 30");
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($logs);
        } catch (Exception $ex) {
            echo json_encode([]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unbekannte POST-Aktion.']);
    exit;
}

echo json_encode(['status' => 'Pistache API läuft.', 'zeit' => date('H:i:s')]);