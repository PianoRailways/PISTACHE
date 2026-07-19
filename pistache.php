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

// =========================================================================
// HILFSFUNKTIONEN FÜR ZEITBERECHNUNGEN
// =========================================================================

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
// FLAG-ANALYSE
// =========================================================================

function allowsEarlyDeparture($flags) {
    return !empty($flags) && preg_match('/\b(D|A)\b/i', trim($flags));
}

function isHaltProtected($flags) {
    if (empty($flags)) return false;
    return preg_match('/!/', trim($flags)) ? true : false;
}

function hasDispoCriteria($flags) {
    if (empty($flags)) return false;
    return preg_match('/^(X|V|C[4-7]?)\((\d+)\)/i', trim($flags)) ? true : false;
}

// =========================================================================
// UNIFIED DELAY CALCULATION
// =========================================================================

/**
 * calculateEffectiveDelay()
 * 
 * Einheitliche Berechnung der effektiven Ist-Abfahrtsverspätung
 * an einem Halt, unter Berücksichtigung von:
 * - Eingehender Verspätung (oder Verfrühung)
 * - Geplanter Standzeit vs. verfügbarer Abbremsung
 * - D/A-Flag für Verfrühungen
 * 
 * @return int effektive Abfahrtsverspätung in Minuten
 */
function calculateEffectiveDelay(
    $incoming_delay,
    $soll_arrival_min,
    $soll_departure_min,
    $flags = ''
) {
    $allowEarly = allowsEarlyDeparture($flags);
    
    // Bei Verfrühung ohne D/A-Flag → auf 0 begrenzen
    if (!$allowEarly && $incoming_delay < 0) {
        return 0;
    }
    
    // Berechne verfügbare Abbremsung
    $soll_standzeit = ($soll_arrival_min !== null && $soll_departure_min !== null)
        ? max(0, $soll_departure_min - $soll_arrival_min)
        : 0;
    
    $hasRFlag = preg_match('/R/i', $flags) ? true : false;
    $min_standzeit = $hasRFlag ? 2 : 0;
    
    $available_braking = max(0, $soll_standzeit - $min_standzeit);
    $actual_braking = min(max(0, $incoming_delay), $available_braking);
    
    $effective_delay = max(0, $incoming_delay - $actual_braking);
    
    if (!$allowEarly) {
        $effective_delay = max(0, $effective_delay);
    }
    
    return $effective_delay;
}

// =========================================================================
// ROBUSTE ZUGSUCHE: ZID + FALLBACK-KETTE
// =========================================================================

/**
 * findTrain()
 * 
 * Robuste Zugsuche mit 3-stufigem Fallback:
 * 1. Direkter ZID-Match (normal case)
 * 2. Alte ZID in zid_mapping (nach ZID-Wechsel)
 * 3. Composite Key (Station + jüngster bekannter Zug dort) als letzter Ausweg
 * 
 * Wenn Stufe 3 erfolgreich ist, wird die neue ZID ins Feld geschrieben (smart update).
 */
function findTrain($db, $sts_zid, $station_abbr, $delay) {
    global $ROUTES;
    
    write_log("🔍 ZUGSUCHE START: ZID=$sts_zid, Station=$station_abbr");
    
    // ======== STUFE 1: Direkter ZID-Match ========
    $stmt = $db->prepare("SELECT id, route_id, train_number, sts_zid FROM trains WHERE sts_zid = ? LIMIT 1");
    $stmt->execute([$sts_zid]);
    $train = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($train) {
        write_log("   ✅ STUFE 1: ZID=$sts_zid direkt gefunden");
        return $train;
    }
    write_log("   ⚠️ STUFE 1: ZID=$sts_zid nicht direkt gefunden");
    
    // ======== STUFE 2: Alte ZID in zid_mapping Tabelle ========
    try {
        $stmt = $db->prepare("
            SELECT new_zid FROM zid_mapping 
            WHERE old_zid = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$sts_zid]);
        $mapping = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($mapping) {
            $new_zid = $mapping['new_zid'];
            write_log("   ℹ️ STUFE 2: ZID-Mapping gefunden: $sts_zid → $new_zid");
            
            $stmt = $db->prepare("SELECT id, route_id, train_number, sts_zid FROM trains WHERE sts_zid = ? LIMIT 1");
            $stmt->execute([$new_zid]);
            $train = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($train) {
                write_log("   ✅ STUFE 2: Zug mit neuer ZID=$new_zid gefunden");
                return $train;
            }
            write_log("   ⚠️ STUFE 2: Neue ZID $new_zid nicht in DB");
        }
    } catch (Exception $e) {
        write_log("   ⚠️ STUFE 2: zid_mapping-Tabelle existiert nicht oder Fehler");
    }
    
    // ======== STUFE 3: Composite-Key Fallback ========
    // Station + jüngster bekannter Zug
    
    // Finde Station-ID
    $station_id = null;
    $plugin_abbr = strtoupper(trim($station_abbr));
    
    foreach ($ROUTES as $rid => $route) {
        foreach ($route['stations'] as $st) {
            if (strtoupper(trim($st['abbr'])) === $plugin_abbr) {
                $station_id = $st['id'];
                break 2;
            }
        }
    }
    
    if (!$station_id) {
        write_log("   ❌ STUFE 3: Station $plugin_abbr nicht in routes.php");
        return null;
    }
    
    write_log("   📍 Station $plugin_abbr → ID: $station_id");
    
    // Alle Züge die diese Station anfahren (neueste zuerst)
    $stmt = $db->prepare("
        SELECT DISTINCT t.id, t.route_id, t.train_number, t.sts_zid
        FROM timetable tt
        JOIN trains t ON tt.train_id = t.id
        WHERE tt.station_id = ?
        ORDER BY t.id DESC
        LIMIT 10
    ");
    $stmt->execute([$station_id]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($candidates)) {
        write_log("   ❌ STUFE 3: Keine Züge für Station $station_id");
        return null;
    }
    
    write_log("   🔎 STUFE 3: " . count($candidates) . " Kandidat(en) an Station $station_id");
    
    // Wähle jenen mit aktuellem/jüngstem sts_zid
    $best_train = null;
    foreach ($candidates as $cand) {
        if (!empty($cand['sts_zid'])) {
            $best_train = $cand;
            break;
        }
        if ($best_train === null) {
            $best_train = $cand;
        }
    }
    
    if ($best_train) {
        write_log("   ✅ STUFE 3: Fallback erfolgreich");
        write_log("      Zug: {$best_train['train_number']} (Route: {$best_train['route_id']}, alte ZID: {$best_train['sts_zid']})");
        write_log("      SMART UPDATE: ZID {$best_train['sts_zid']} → $sts_zid");
        
        // Smart Update: Neue ZID speichern
        try {
            $stmt = $db->prepare("UPDATE trains SET sts_zid = ? WHERE id = ?");
            $stmt->execute([$sts_zid, $best_train['id']]);
            write_log("      ✅ ZID-Update erfolgreich");
        } catch (Exception $e) {
            write_log("      ⚠️ ZID-Update fehlgeschlagen: {$e->getMessage()}");
        }
        
        $best_train['sts_zid'] = $sts_zid;
        return $best_train;
    }
    
    write_log("   ❌ STUFE 3: Keine Kandidaten");
    return null;
}

// =========================================================================
// PLUGIN-UPDATE MIT PROPAGATION
// =========================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_raw = file_get_contents('php://input');
    $input_data = json_decode($input_raw, true);
    
    $action = $_POST['action'] ?? $input_data['action'] ?? '';

    // =========================================================================
    // plugin_update_delay - Dispatcher für Verspätungs-Updates vom STS-Plugin
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

        // Parse station_abbr
        if (preg_match('/^([A-Za-z]+)\s+(.+)$/', trim($raw_gleis), $matches)) {
            $station_abbr = strtoupper($matches[1]);
            $track_number = $matches[2];
        } elseif (preg_match('/^([A-Za-z]+)(\d.*)$/', trim($raw_gleis), $matches)) {
            $station_abbr = strtoupper($matches[1]);
            $track_number = $matches[2];
        } else {
            $station_abbr = strtoupper(trim($raw_gleis));
            $track_number = null;
        }

        // 1. Finde Zug (mit robustem Fallback)
        $train = findTrain($db, $sts_zid, $raw_gleis, $delay);
        if (!$train) {
            write_log("FEHLER: Zug konnte mit keiner Methode gefunden werden.");
            echo json_encode(['success' => false, 'message' => "Zug mit ZID $sts_zid nicht gefunden (alle Fallbacks fehlgeschlagen). Fahrplan ggfs. nicht geladen?"]);
            exit;
        }

        $train_id = $train['id'];
        $train_num = $train['train_number'];

        // 2. Finde Station
        $station_id = null;
        $plugin_abbr = strtoupper(trim($station_abbr));

        write_log("DEBUG: Suche Station mit Kürzel: '$plugin_abbr'");
        foreach ($ROUTES as $rid => $route) {
            foreach ($route['stations'] as $st) {
                if (strtoupper(trim($st['abbr'])) === $plugin_abbr) {
                    $station_id = $st['id'];
                    write_log("DEBUG: Station global in Route '$rid' gefunden (ID: $station_id).");
                    break 2;
                }
            }
        }

        if (!$station_id) {
            write_log("FEHLER: Station '$plugin_abbr' nicht gefunden.");
            echo json_encode(['success' => false, 'message' => "Station '$station_abbr' unbekannt."]);
            exit;
        }

        // 3. Hole Soll-Zeiten des aktuellen Halts
        $stmt = $db->prepare("
            SELECT id, arrival, departure, actual_departure, flags 
            FROM timetable 
            WHERE train_id = ? AND station_id = ? 
            ORDER BY sequence_index ASC 
            LIMIT 1
        ");
        $stmt->execute([$train_id, $station_id]);
        $timetable_entry = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$timetable_entry) {
            echo json_encode(['success' => false, 'message' => 'Kein Soll-Eintrag vorhanden.']);
            exit;
        }

        // 🔒 SCHUTZ: Prüfe ob Halt geschützt ist
        $flags = $timetable_entry['flags'] ?? '';
        if (isHaltProtected($flags)) {
            write_log("🔒 GESCHÜTZT: Zug $train_num an $station_abbr mit ! markiert → Update ignoriert");
            echo json_encode(['success' => true, 'message' => "Halt ist fixiert (!), Plugin-Update ignoriert."]);
            exit;
        }

        // 4. Parameter auslesen
        $am_gleis = $_POST['am_gleis'] ?? $input_data['am_gleis'] ?? '0';
        $sichtbar = $_POST['sichtbar'] ?? $input_data['sichtbar'] ?? 'true';
        $am_gleis_flag = ($am_gleis === '1' || $am_gleis === true);
        $sichtbar_flag = ($sichtbar === 'true' || $sichtbar === '1' || $sichtbar === true);

        // 5. Berechne effektive Verspätung
        $soll_arr_min = timeToMinutes($timetable_entry['arrival']);
        $soll_dep_min = timeToMinutes($timetable_entry['departure']);
        
        $effective_delay = calculateEffectiveDelay(
            $delay,
            $soll_arr_min,
            $soll_dep_min,
            $flags
        );

        if ($effective_delay !== $delay) {
            write_log("⏳ Delay-Anpassung: $delay → $effective_delay Min (Abbremsung berücksichtigt)");
        }

        // 6. Bestimme Ist-Zeiten
        if ($am_gleis_flag) {
            $actual_arrival = null;
            $actual_departure = addMinutes($timetable_entry['departure'], $effective_delay);
            write_log("⏳ AM GLEIS: Zug $train_num an $station_abbr - nur Abfahrt (+$effective_delay Min)");
        } else {
            if (!$sichtbar_flag && !$am_gleis_flag) {
                // Vorlauf ohne Sichtbarkeit: nur Abfahrt
                $actual_arrival = null;
                $actual_departure = addMinutes($timetable_entry['departure'], $effective_delay);
                write_log("📍 VORLAUF-VORAB: Zug $train_num an $station_abbr - nur Abfahrt (+$effective_delay Min)");
            } else {
                // Standard Vorlauf
                $actual_arrival = addMinutes($timetable_entry['arrival'], $effective_delay);
                $actual_departure = addMinutes($timetable_entry['departure'], $effective_delay);
                write_log("📍 VORLAUF: Zug $train_num an $station_abbr - An- und Abfahrt (+$effective_delay Min)");
            }
        }

        // 7. Lösche Folgehalte für neue Propagation (wenn nicht "Am Gleis")
        if (!$am_gleis_flag) {
            $stmtStops = $db->prepare("
                SELECT id, station_id, flags FROM timetable 
                WHERE train_id = ? 
                ORDER BY CASE WHEN arrival != '' THEN arrival ELSE departure END ASC, sequence_index ASC, id ASC
            ");
            $stmtStops->execute([$train_id]);
            $all_stops = $stmtStops->fetchAll(PDO::FETCH_ASSOC);

            $current_found = false;
            $stops_to_clear = [];

            foreach ($all_stops as $s) {
                if ($s['station_id'] == $station_id) {
                    $current_found = true;
                    continue;
                }
                if ($current_found) {
                    if (!isHaltProtected($s['flags'] ?? '')) {
                        $stops_to_clear[] = $s['id'];
                    }
                }
            }

            if (!empty($stops_to_clear)) {
                $clause = implode(',', array_fill(0, count($stops_to_clear), '?'));
                $stmtClear = $db->prepare("
                    UPDATE timetable 
                    SET actual_arrival = NULL, actual_departure = NULL 
                    WHERE id IN ($clause)
                ");
                $stmtClear->execute($stops_to_clear);
                write_log("🧹 Bereinigung: " . count($stops_to_clear) . " ungeschützte Folgehalte zurückgesetzt.");
            }
        }

        // 8. Update aktueller Halt
        if ($am_gleis_flag) {
            $stmtUpdate = $db->prepare("
                UPDATE timetable 
                SET actual_departure = ?, track = ?, remarks = ? 
                WHERE id = ?
            ");
            $stmtUpdate->execute([$actual_departure, $track_number ?? '', "+" . $effective_delay . " (Am Gleis)", $timetable_entry['id']]);
        } else {
            $stmtUpdate = $db->prepare("
                UPDATE timetable 
                SET actual_arrival = ?, actual_departure = ?, track = ?, remarks = ? 
                WHERE id = ?
            ");
            $stmtUpdate->execute([$actual_arrival, $actual_departure, $track_number ?? '', "+" . $effective_delay, $timetable_entry['id']]);
        }

        write_log("✓ DB-UPDATE: Zug $train_num an $station_abbr auf +$effective_delay Min gesetzt.");

        // 9. Propagiere mit 7%-Reserve
        propagateTravelTimeWithReserve($db, $train_id);

        // 10. Cascade auf Folgezug
        $current_sts_zid = $train['sts_zid'] ?? null;
        if (!empty($current_sts_zid)) {
            $stmtLastStop = $db->prepare("
                SELECT departure, arrival, actual_departure, actual_arrival 
                FROM timetable 
                WHERE train_id = ? 
                ORDER BY CASE WHEN departure != '' THEN departure ELSE arrival END DESC, sequence_index DESC, id DESC 
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

        echo json_encode(['success' => true, 'message' => "Fahrt bis zum Ende neu propagiert."]);
        exit;
    }

    // ... REST DER POST-AKTIONEN (save_live_zugliste, get_routes, etc.)
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
            $stmt2 = $db->prepare("SELECT station_id, track, arrival, departure, actual_arrival, actual_departure, flags, remarks FROM timetable WHERE train_id = ? ORDER BY sequence_index ASC, id ASC");
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

echo json_encode(['status' => 'Pistache API laeuft.', 'zeit' => date('H:i:s')]);

// =========================================================================
// HILFSFUNKTIONEN
// =========================================================================

/**
 * propagateTravelTimeWithReserve()
 * 
 * Propagiert Verspätung für die aktuelle Fahrt mit:
 * - 7% Fahrtzeit-Reserve
 * - Standzeitabbau
 * - Dispo-Kriterium-Schutz
 */
function propagateTravelTimeWithReserve($db, $trainId) {
    $stmt = $db->prepare("
        SELECT id, station_id, arrival, departure, actual_arrival, actual_departure, flags
        FROM timetable
        WHERE train_id = ?
        ORDER BY 
            CASE WHEN arrival != '' THEN arrival ELSE departure END ASC,
            sequence_index ASC,
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

        // 🔒 DISPO-KRITERIUM-SCHUTZ
        if (hasDispoCriteria($flags)) {
            write_log("🔒 GESCHÜTZT (Dispo): $stationId → wird übersprungen");
            continue;
        }

        // ========== ANKUNFT BERECHNEN ==========
        $istArrMin = $timeToMin($stop['actual_arrival']);
        
        if ($istArrMin === null && $prevDepMin !== null && $prevSollDepMin !== null) {
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
            $sollStandzeit = 0;
            if ($sollArrMin !== null) {
                $sollStandzeit = max(0, $sollDepMin - $sollArrMin);
            }

            $hasRFlag = preg_match('/R/i', $flags) ? true : false;
            $minStandzeit = $hasRFlag ? 2 : 0;

            $arrivalDelay = ($sollArrMin !== null) ? ($istArrMin - $sollArrMin) : 0;

            $availableBraking = max(0, $sollStandzeit - $minStandzeit);
            $actualBraking = min(max(0, $arrivalDelay), $availableBraking);

            $remainingDelay = $arrivalDelay - $actualBraking;
            if (!allowsEarlyDeparture($flags)) {
                $remainingDelay = max(0, $remainingDelay);
            }
            $istDepMin = $sollDepMin + $remainingDelay;

            write_log("📍 $stationId: Ank.Versp={$arrivalDelay}min, Abbr={$actualBraking}min, Restversp={$remainingDelay}min");
        } elseif ($istArrMin !== null) {
            $istDepMin = $istArrMin;
        } else {
            continue;
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

        if ($istDepMin !== null) {
            $prevDepMin = $istDepMin;
            $prevSollDepMin = $sollDepMin;
        }
    }
}

/**
 * recalculateDelayCascade()
 * 
 * Propagiert Verspätung auf Folgezug mit Standzeit-Schutz
 */
function recalculateDelayCascade($db, $current_sts_zid, $current_delay) {
    if ($current_delay <= 0) return;

    $stmt = $db->prepare("SELECT successor_sts_zid FROM trains WHERE sts_zid = ?");
    $stmt->execute([$current_sts_zid]);
    $successor_sts_zid = $stmt->fetchColumn();

    if (empty($successor_sts_zid)) return;
    
    $stmt = $db->prepare("SELECT id, train_number FROM trains WHERE sts_zid = ?");
    $stmt->execute([$successor_sts_zid]);
    $next_train = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$next_train) return;
    
    $next_train_id = $next_train['id'];
    $next_train_num = $next_train['train_number'];
    
    $stmtStops = $db->prepare("SELECT station_id, arrival, departure, actual_arrival, actual_departure, flags FROM timetable WHERE train_id = ? ORDER BY sequence_index ASC, id ASC");
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
            write_log("    🔒 GESCHÜTZT: Halt $station_id mit ! markiert → Übersprungen.");
            continue;
        }

        $act_arr_min = $soll_arr_min + $current_delay;
        $act_dep_min = max($act_arr_min + ($soll_dep_min - $soll_arr_min), $soll_dep_min + $current_delay);
        
        $act_arr = minutesToTime($act_arr_min);
        $act_dep = minutesToTime($act_dep_min);
        
        $stmtUp = $db->prepare("UPDATE timetable SET actual_arrival = ?, actual_departure = ?, remarks = ? WHERE train_id = ? AND station_id = ? ORDER BY sequence_index ASC LIMIT 1");
        $stmtUp->execute([$act_arr, $act_dep, "+" . ($act_dep_min - $soll_dep_min) . " Min (Kaskade)", $next_train_id, $station_id]);
    }
}

?>