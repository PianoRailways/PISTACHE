<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

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

function addMinutes($timeStr, $min) {
    if (empty($timeStr)) return null;
    $p = explode(':', $timeStr);
    if (count($p) < 2) return null;
    $total = intval($p[0]) * 60 + intval($p[1]) + $min;
    if ($total < 0) $total += 1440; 
    return sprintf('%02d:%02d', floor($total / 60) % 24, $total % 60);
}

function timeToMinutes($timeStr) {
    if (empty($timeStr)) return null;
    $p = explode(':', $timeStr);
    if (count($p) < 2) return null;
    return (intval($p[0]) * 60) + intval($p[1]);
}

function minutesToTime($m) {
    $m = $m % 1440;
    $h = floor($m / 60);
    $min = $m % 60;
    return sprintf("%02d:%02d", $h, $min);
}

// =========================================================================
// PRÜFE OB HALT MIT DISPO-FLAG GESCHÜTZT IST
// =========================================================================
function isHaltProtected($flags) {
    if (empty($flags)) return false;
    
    // "!" = Fixverspätung (absolute Fixierung)
    if (preg_match('/!/', trim($flags))) {
        return true;
    }
    
    return false;
}

// =========================================================================
// DISPO-KRITERIEN EVALUIEREN WÄHREND PROPAGATION
// =========================================================================
function evaluateDispoCriteria($db, $station_id, $flags, $current_delay, $soll_dep_min) {
    if (empty($flags)) return $current_delay;

    if (!preg_match('/^(X|V|C[4-7]?)\((\d+)\)/i', trim($flags), $matches)) {
        return $current_delay;
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
        SELECT actual_arrival, arrival, actual_departure, departure
        FROM timetable tt
        JOIN trains t ON tt.train_id = t.id
        WHERE t.train_number = ? AND tt.station_id = ?
    ");
    $stmt->execute([$conflictTrainNum, $station_id]);
    $conflictStop = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conflictStop) return $current_delay;

    $refTimeStr = '';
    if ($type === 'X' || strpos($type, 'C') === 0) {
        $refTimeStr = !empty($conflictStop['actual_arrival']) ? $conflictStop['actual_arrival'] : $conflictStop['arrival'];
    } else if ($type === 'V') {
        $refTimeStr = !empty($conflictStop['actual_departure']) ? $conflictStop['actual_departure'] : $conflictStop['departure'];
    }

    if (empty($refTimeStr)) return $current_delay;

    $refMinutes = timeToMinutes($refTimeStr);
    if ($refMinutes === null) return $current_delay;

    $minDepartureMinutes = $refMinutes + $buffer;
    $actualDepartureMinutes = $soll_dep_min + $current_delay;

    if ($minDepartureMinutes > $actualDepartureMinutes) {
        return $minDepartureMinutes - $soll_dep_min;
    }

    return $current_delay;
}

/**
 * propagateTravelTimeWithReserve() - PHP-Version für Delay-Propagation
 * 
 * Wird vom STS-Plugin aufgerufen und propagiert Verspätung mit:
 * - 7% Fahrtzeit-Reserve
 * - Standzeitabbau (außer bei R-Flag)
 * - Dispo-Kriterium Schutz
 */
function propagateTravelTimeWithReserve($db, $trainId) {
    $stmt = $db->prepare("
        SELECT id, station_id, arrival, departure, actual_arrival, actual_departure, flags
        FROM timetable
        WHERE train_id = ?
        ORDER BY 
            CASE WHEN arrival != '' THEN arrival ELSE departure END ASC,
            id ASC
    ");
    $stmt->execute([$trainId]);
    $stops = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $timeToMin = function($tStr) {
        if (empty($tStr)) return null;
        $p = explode(':', $tStr);
        return (count($p) >= 2) ? (intval($p[0]) * 60 + intval($p[1])) : null;
    };

    $minToTime = function($minutes) {
        if ($minutes === null || is_nan($minutes)) return '';
        $normalized = ((intval($minutes) % 1440) + 1440) % 1440;
        $h = intval($normalized / 60);
        $m = intval($normalized % 60);
        return sprintf('%02d:%02d', $h, $m);
    };

    $prevDepMin = null;
    $prevSollDepMin = null;

    for ($i = 0; $i < count($stops); $i++) {
        $stop = $stops[$i];
        $stationId = $stop['station_id'];
        $flags = $stop['flags'] ?? '';

        $sollArrMin = $timeToMin($stop['arrival']);
        $sollDepMin = $timeToMin($stop['departure']);

        if ($sollArrMin === null && $sollDepMin === null) {
            continue;
        }

        // 🔒 SCHUTZ: Dispo-Kriterium prüfen
        if (preg_match('/^(X|V|C[4-7]?)\((\d+)\)/i', trim($flags))) {
            error_log("🔒 GESCHÜTZT (Dispo): $stationId → wird übersprungen");
            continue;
        }

        $istArrMin = $timeToMin($stop['actual_arrival']);
        $istDepMin = $timeToMin($stop['actual_departure']);

        // ========== ANKUNFT BERECHNEN ==========
        if ($prevDepMin !== null && $prevSollDepMin !== null && $istArrMin === null) {
            $sollFahrtzeit = 0;
            if ($sollArrMin !== null) {
                $sollFahrtzeit = $sollArrMin - $prevSollDepMin;
            } elseif ($sollDepMin !== null) {
                $sollFahrtzeit = $sollDepMin - $prevSollDepMin;
            }

            if ($sollFahrtzeit > 0) {
                $minFahrtzeit = round($sollFahrtzeit * 0.93);
                $istFahrtzeit = max($minFahrtzeit, $sollFahrtzeit);
                $istArrMin = $prevDepMin + $istFahrtzeit;
            }
        }

        if ($istArrMin === null && $prevDepMin !== null) {
            $istArrMin = $prevDepMin;
        }

        // ========== STANDZEIT & ABFAHRT ==========
        if ($istArrMin !== null && $sollDepMin !== null) {
            // Soll-Standzeit
            $sollStandzeit = 0;
            if ($sollArrMin !== null) {
                $sollStandzeit = max(0, $sollDepMin - $sollArrMin);
            }

            // R-Flag prüfen
            $hasRFlag = preg_match('/R/i', $flags) ? true : false;
            $minStandzeit = $hasRFlag ? 2 : 0;

            // Verspätung bei Ankunft
            $arrivalDelay = ($sollArrMin !== null) ? ($istArrMin - $sollArrMin) : 0;

            // Verfügbare Abbremsung
            $availableBraking = max(0, $sollStandzeit - $minStandzeit);
            $actualBraking = min(max(0, $arrivalDelay), $availableBraking);

            // Ist-Abfahrt = Soll-Abfahrt + (Ankunftsversp. - Abbremsung)
            $remainingDelay = max(0, $arrivalDelay - $actualBraking);
            $istDepMin = $sollDepMin + $remainingDelay;

            error_log("📍 $stationId: Ank.Versp={$arrivalDelay}min, Abbr={$actualBraking}min, Restversp={$remainingDelay}min");
        } elseif ($istArrMin !== null) {
            // Keine geplante Abfahrt, aber Ankunft: Ist-Abfahrt = Ist-Ankunft
            $istDepMin = $istArrMin;
        }

        // ========== UPDATE IN DB ==========
        $stmt = $db->prepare("
            UPDATE timetable
            SET actual_arrival = ?, actual_departure = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $minToTime($istArrMin),
            $minToTime($istDepMin),
            $stop['id']
        ]);

        // Aktualisiere Werte für nächste Iteration
        if ($istDepMin !== null) {
            $prevDepMin = $istDepMin;
            $prevSollDepMin = $sollDepMin;
        }
    }
}

// =========================================================================
// KASKADE FÜR NACHFOLGERZÜGE
// =========================================================================
function recalculateDelayCascade($db, $current_zid, $current_delay) {
    if ($current_delay <= 0) return;

    global $write_log;
    
    $stmt = $db->prepare("SELECT successor_sts_zid FROM trains WHERE sts_zid = ?");
    $stmt->execute([$current_zid]);
    $successor_zid = $stmt->fetchColumn();

    if (empty($successor_zid)) return;
    
    $stmt = $db->prepare("SELECT id, train_number FROM trains WHERE sts_zid = ?");
    $stmt->execute([$successor_zid]);
    $next_train = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$next_train) return;
    
    $next_train_id = $next_train['id'];
    $next_train_num = $next_train['train_number'];
    
    $stmtStops = $db->prepare("SELECT station_id, arrival, departure, actual_arrival, actual_departure, flags FROM timetable WHERE train_id = ? ORDER BY id ASC");
    $stmtStops->execute([$next_train_id]);
    $next_stops = $stmtStops->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($next_stops)) return;

    write_log("🔄 KASKADE: Nachfolgezug $next_train_num, Verspätung: +$current_delay Min");
    
    foreach ($next_stops as $ns) {
        $station_id = $ns['station_id'];
        $soll_arr_min = timeToMinutes($ns['arrival']);
        $soll_dep_min = timeToMinutes($ns['departure']);
        $flags = $ns['flags'] ?? '';
        
        if (!empty($ns['actual_departure']) || !empty($ns['actual_arrival'])) {
            write_log("    ⏭️ RÜCKWIRKUNG-SCHUTZ: Halt $station_id hat bereits Ist-Daten.");
            continue;
        }

        if (isHaltProtected($flags)) {
            write_log("    🔒 GESCHÜTZT: Halt $station_id ist mit ! markiert → Übersprungen.");
            continue;
        }

        $act_arr_min = $soll_arr_min + $current_delay;
        $act_dep_min = max($act_arr_min + ($soll_dep_min - $soll_arr_min), $soll_dep_min + $current_delay);
        
        $act_arr = minutesToTime($act_arr_min);
        $act_dep = minutesToTime($act_dep_min);
        
        $stmtUp = $db->prepare("UPDATE timetable SET actual_arrival = ?, actual_departure = ?, remarks = ? WHERE train_id = ? AND station_id = ?");
        $stmtUp->execute([$act_arr, $act_dep, "+" . ($act_dep_min - $soll_dep_min) . " Min (Kaskade)", $next_train_id, $station_id]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_raw = file_get_contents('php://input');
    $input_data = json_decode($input_raw, true);
    
    $action = $_POST['action'] ?? $input_data['action'] ?? '';

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
    // PLUGIN-UPDATE MIT 7%-RESERVE PROPAGATION
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

        // Extrahiere Stationskürzl aus Plattform-ID
        // Supportet: "KZ4A", "BO2G", "BN 10 kurz", "ROSS 3", "GMM2", etc.
        // station_id = nur die Stationskürzl (Buchstaben am Anfang)
        // track = die komplette Gleisbezeichnung (Nummer + optional Buchstaben/Zusatz)
        
        if (preg_match('/^([A-Za-z]+)\s+(.+)$/', trim($raw_gleis), $matches)) {
            // Format mit Leerzeichen: "STATION GLEIS..."
            // z.B. "BN 10 kurz", "ROSS 3", "KZ 4A"
            $station_abbr = strtoupper($matches[1]);           // z.B. "BN", "ROSS", "KZ"
            $track_number = $matches[2];                       // z.B. "10 kurz", "3", "4A"
        } elseif (preg_match('/^([A-Za-z]+)(\d.*)$/', trim($raw_gleis), $matches)) {
            // Format ohne Leerzeichen: "STATIONGLEIS..."
            // z.B. "KZ4A", "BO2G", "GMM2", "ROSS3", "BN10"
            $station_abbr = strtoupper($matches[1]);           // z.B. "KZ", "BO", "GMM", "ROSS", "BN"
            $track_number = $matches[2];                       // z.B. "4A", "2G", "2", "3", "10"
        } else {
            // Fallback: Nur Buchstaben, keine Nummer
            $station_abbr = strtoupper(trim($raw_gleis));
            $track_number = null;
        }

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

        // 2. Station suchen
        $station_id = null;
        $plugin_abbr = strtoupper(trim($station_abbr));
        
        write_log("DEBUG: Suche Station mit Kürzel: '$plugin_abbr' in Route '$route_id'");

        if (isset($ROUTES[$route_id]['stations'])) {
            foreach ($ROUTES[$route_id]['stations'] as $st) {
                if (strtoupper(trim($st['abbr'])) === $plugin_abbr) {
                    $station_id = $st['id'];
                    break;
                }
            }
        }
        
        // FALLBACK: Global suchen
        if (!$station_id) {
            write_log("DEBUG: Spezifische Suche fehlgeschlagen, starte globale Suche für '$plugin_abbr'...");
            foreach ($ROUTES as $rid => $route) {
                foreach ($route['stations'] as $st) {
                    if (strtoupper(trim($st['abbr'])) === $plugin_abbr) {
                        $station_id = $st['id'];
                        write_log("DEBUG: Station global in Route '$rid' gefunden (ID: $station_id).");
                        break 2;
                    }
                }
            }
        }

        if (!$station_id) {
            write_log("FEHLER: Konnte Plugin-Kürzel '$plugin_abbr' nirgendwo zuordnen.");
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

        // SCHUTZ: Prüfe ob mit "!" markiert
        $flags = $timetable_entry['flags'] ?? '';
        if (isHaltProtected($flags)) {
            write_log("🔒 GESCHÜTZT: Zug $train_num an $station_abbr ist mit ! markiert → Plugin-Update ignoriert");
            echo json_encode(['success' => true, 'message' => "Halt ist fixiert (!), Plugin-Update ignoriert."]);
            exit;
        }

        // 4. Ist-Zeiten berechnen
        $am_gleis = $_POST['am_gleis'] ?? $input_data['am_gleis'] ?? '0';
        $am_gleis_flag = ($am_gleis === '1' || $am_gleis === true);

        if ($am_gleis_flag) {
            $actual_arrival = null;
            $actual_departure = addMinutes($timetable_entry['departure'], $delay);
            write_log("⏳ AM GLEIS: Zug $train_num an $station_abbr - nur Abfahrt wird geändert (+$delay Min)");
        } else {
            $actual_arrival = addMinutes($timetable_entry['arrival'], $delay);
            $actual_departure = addMinutes($timetable_entry['departure'], $delay);
            write_log("📍 VORLAUF: Zug $train_num an $station_abbr - An- und Abfahrt werden geändert (+$delay Min)");
        }

        // 5. In DB schreiben
        if ($am_gleis_flag) {
            $stmtUpdate = $db->prepare("UPDATE timetable SET actual_departure = ?, track = ?, remarks = ? WHERE train_id = ? AND station_id = ?");
            $stmtUpdate->execute([$actual_departure, $track_number ?? '', "+" . $delay . " (Am Gleis)", $train_id, $station_id]);
        } else {
            $stmtUpdate = $db->prepare("UPDATE timetable SET actual_arrival = ?, actual_departure = ?, track = ?, remarks = ? WHERE train_id = ? AND station_id = ?");
            $stmtUpdate->execute([$actual_arrival, $actual_departure, $track_number ?? '', "+" . $delay, $train_id, $station_id]);
        }
        
        write_log("🎉 DB-UPDATE: Zug $train_num an $station_abbr auf +$delay Min gesetzt.");

        // 7%-RESERVE-PROPAGATION FÜR GANZE FAHRT (JEDESMAL NEU)
        propagateTravelTimeWithReserve($db, $train_id);  // NUR 2 Parameter!
        
        // Optional: Cascade für Nachfolgerzug
        // recalculateDelayCascade($db, $sts_zid, $delay);

        // 6. Audit-Log
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

        echo json_encode(['success' => true, 'message' => "Fahrt mit 7%-Reserve neu berechnet und propagiert."]);
        exit;
    }

    // =========================================================================
    // FRONTEND-AKTIONEN
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
?>