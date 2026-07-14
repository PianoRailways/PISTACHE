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

function allowsEarlyDeparture($flags) {
    return !empty($flags) && preg_match('/\b(D|A)\b/i', trim($flags));
}

function getEffectiveAmGleisDelay(array $timetable_entry, int $incoming_delay, bool $allowEarlyDeparture = false) {
    if (!$allowEarlyDeparture) {
        $incoming_delay = max(0, $incoming_delay);
    }

    $scheduled_departure = $timetable_entry['departure'] ?? '';
    $current_departure = $timetable_entry['actual_departure'] ?? '';

    $scheduled_minutes = timeToMinutes($scheduled_departure);
    $current_minutes = timeToMinutes($current_departure);

    if ($scheduled_minutes === null || $current_minutes === null) {
        return $incoming_delay;
    }

    $current_delay = $current_minutes - $scheduled_minutes;

    if ($allowEarlyDeparture && $incoming_delay < 0) {
        return $incoming_delay;
    }

    if (!$allowEarlyDeparture) {
        $current_delay = max(0, $current_delay);
    }

    return max($incoming_delay, $current_delay);
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

    $trimmedFlags = trim($flags);

    // D = Durchfahrt: Frühere Abfahrten sind hier erlaubt, daher keine zusätzliche Verzögerung erzwingen.
    if (preg_match('/\bD\b/i', $trimmedFlags)) {
        return $current_delay;
    }

    if (!preg_match('/^(X|V|C[4-7]?)(\d+)/i', $trimmedFlags, $matches)) {
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
        ORDER BY tt.sequence_index ASC
        LIMIT 1
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
 * Wird vom STS-Plugin aufgerufen und propagiert Verspätung für die aktuelle Fahrt
 * über alle noch folgenden Halte des gleichen Zuges.
 * Dabei werden angewendet:
 * - 7% Fahrtzeit-Reserve
 * - Standzeitabbau (außer bei R-Flag)
 * - Dispo-Kriterium Schutz
 * 
 * Hinweis: Diese Funktion arbeitet nur für den aktuellen Zug (train_id),
 * Nachfolgezüge werden hier nicht automatisch angepasst.
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

        // 🔒 SCHUTZ: Dispo-Kriterium prüfen
        if (preg_match('/^(X|V|C[4-7]?)\((\d+)\)/i', trim($flags))) {
            error_log("🔒 GESCHÜTZT (Dispo): $stationId → wird übersprungen");
            continue;
        }

        $istArrMin = $timeToMin($stop['actual_arrival']);
        $istDepMin = $timeToMin($stop['actual_departure']);
        $hasActualDepartureOnly = ($istArrMin === null && $istDepMin !== null);
        $allowEarlyDeparture = allowsEarlyDeparture($flags);
        $currentDepartureDelay = ($istDepMin !== null && $sollDepMin !== null) ? ($istDepMin - $sollDepMin) : null;

        // ========== ANKUNFT BERECHNEN ==========
        if ($hasActualDepartureOnly) {
            if ($sollArrMin !== null && $sollDepMin !== null) {
                $scheduledStandzeit = max(0, $sollDepMin - $sollArrMin);
                $istArrMin = $istDepMin - $scheduledStandzeit;
                if (!$allowEarlyDeparture) {
                    $istArrMin = max(0, $istArrMin);
                }
            } else {
                $istArrMin = $istDepMin;
            }
        } elseif ($prevDepMin !== null && $prevSollDepMin !== null && $istArrMin === null) {
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
            $remainingDelay = $arrivalDelay - $actualBraking;
            if (!$allowEarlyDeparture) {
                $remainingDelay = max(0, $remainingDelay);
            } elseif ($sollArrMin === null && $currentDepartureDelay !== null && $currentDepartureDelay < 0) {
                $remainingDelay = $currentDepartureDelay;
            }
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
            write_log("    🔒 GESCHÜTZT: Halt $station_id ist mit ! markiert → Übersprungen.");
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
    // PLUGIN-UPDATE MIT 7%-RESERVE PROPAGATION (ÜBERSCHREIBT ZUKÜNFTIGE IST-DATEN)
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

        // 1. Suche den aktuellen Zug
        $stmt = $db->prepare("SELECT id, route_id, train_number, sts_zid FROM trains WHERE sts_zid = ?");
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

        write_log("DEBUG: Suche Station mit Kürzel: '$plugin_abbr' im gesamten Fahrplan");
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
            write_log("FEHLER: Konnte Plugin-Kürzel '$plugin_abbr' nirgendwo zuordnen.");
            echo json_encode(['success' => false, 'message' => "Station '$station_abbr' unbekannt."]);
            exit;
        }

        // 3. Soll-Zeiten holen (NEUER CODE: sequence_index berücksichtigen)
        // Mit sequence_index: Nimm den ersten Halt an dieser Station (sequence_index=0)
        $stmt = $db->prepare("\n            SELECT id, arrival, departure, actual_departure, flags \n            FROM timetable \n            WHERE train_id = ? AND station_id = ? \n            ORDER BY sequence_index ASC \n            LIMIT 1\n        ");
        $stmt->execute([$train_id, $station_id]);
        $timetable_entry = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$timetable_entry) {
            echo json_encode(['success' => false, 'message' => 'Kein Soll-Eintrag vorhanden.']);
            exit;
        }

        // SCHUTZ: Prüfe ob die aktuelle Station mit "!" markiert ist
        $flags = $timetable_entry['flags'] ?? '';
        if (strpos($flags, '!') !== false) {
            write_log("🔒 GESCHÜTZT: Zug $train_num an $station_abbr ist mit ! markiert → Plugin-Update ignoriert");
            echo json_encode(['success' => true, 'message' => "Halt ist fixiert (!), Plugin-Update ignoriert."]);
            exit;
        }

        // 4. Ist-Zeiten berechnen
        $am_gleis = $_POST['am_gleis'] ?? $input_data['am_gleis'] ?? '0';
        $sichtbar = $_POST['sichtbar'] ?? $input_data['sichtbar'] ?? 'true';
        $am_gleis_flag = ($am_gleis === '1' || $am_gleis === true);
        $sichtbar_flag = ($sichtbar === 'true' || $sichtbar === '1' || $sichtbar === true);

        $effective_delay = $delay;
        
        // ========== VERFRÜHUNGS-REGEL FÜR ABFAHRT ==========
        // Nur bei D (Durchfahrt) oder A (verfrühte Abfahrt erlaubt) darf Abfahrt verfrüht werden
        // Bei Halt (kein D/A Flag) wird Abfahrtsverspätung auf 0 begrenzt
        $flags = $timetable_entry['flags'] ?? '';
        $allowEarlyDeparture = allowsEarlyDeparture($flags);
        
        if (!$allowEarlyDeparture && $delay < 0) {
            write_log("⏸️ ABFAHRTS-SCHUTZ: Halt ohne D/A Flag darf nicht verfrüht werden. Abfahrtsverspätung wird von $delay auf 0 begrenzt.");
            $effective_delay = 0;
        }
        // =======================================

        if ($am_gleis_flag) {
            $effective_delay = getEffectiveAmGleisDelay($timetable_entry, $delay, $allowEarlyDeparture);
            $actual_arrival = null; 
            $actual_departure = addMinutes($timetable_entry['departure'], $effective_delay);
            if ($effective_delay > $delay) {
                write_log("⏳ AM GLEIS: Zug $train_num an $station_abbr - Abfahrtsverspätung wird von +$delay auf +$effective_delay Min angehoben");
            } else {
                write_log("⏳ AM GLEIS: Zug $train_num an $station_abbr - nur Abfahrt wird geändert (+$effective_delay Min)");
            }
        } else {
            // VORLAUF-SZENARIO: sichtbar=false + am_gleis=false → NUR Abgangsverspätung
            if (!$sichtbar_flag && !$am_gleis_flag) {
                write_log("📍 VORLAUF-VORAB: Zug $train_num an $station_abbr - NUR Abfahrtsverspätung wird gesetzt (+$effective_delay Min, Ankunft unbekannt)");
                $actual_arrival = null;
                $actual_departure = addMinutes($timetable_entry['departure'], $effective_delay);
            } else {
                // Standard Vorlauf (sichtbar=true, am_gleis=false) → An- und Abfahrt
                $actual_arrival = addMinutes($timetable_entry['arrival'], $effective_delay);
                $actual_departure = addMinutes($timetable_entry['departure'], $effective_delay);
                write_log("📍 VORLAUF: Zug $train_num an $station_abbr - An- und Abfahrt werden geändert (+$effective_delay Min)");
            }
        }

        // 5. ZUKÜNFTIGE IST-DATEN SELEKTIV ZURÜCKSETZEN (Geschützte Halte auslassen!)
        if (!$am_gleis_flag) {
            $stmtStops = $db->prepare(" 
                SELECT id, station_id, flags FROM timetable WHERE train_id = ? 
                ORDER BY CASE WHEN arrival != '' THEN arrival ELSE departure END ASC, sequence_index ASC, id ASC
            ");
            $stmtStops->execute([$train_id]);
            $all_stops = $stmtStops->fetchAll(PDO::FETCH_ASSOC);

            $current_found = false;
            $stops_to_clear = [];

            foreach ($all_stops as $s) {
                if ($s['station_id'] == $station_id) {
                    $current_found = true;
                    continue; // Aktuelle Station überspringen, updaten wir gleich gezielt
                }
                if ($current_found) {
                    // Wenn der Folgehalt geschützt ist (!), darf er NICHT auf NULL zurückgesetzt werden!
                    if (strpos($s['flags'] ?? '', '!') !== false) {
                        write_log("🔒 BEREINIGUNG: Folgehalt ID {$s['id']} ist geschützt (!) und wird nicht zurückgesetzt.");
                        continue;
                    }
                    $stops_to_clear[] = $s['id'];
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
                write_log("🧹 BEREINIGUNG: " . count($stops_to_clear) . " ungeschützte Folgehalte für neue Propagation zurückgesetzt.");
            }
        } else {
            write_log("ℹ️ AM GLEIS: Vorab-Löschung der IST-Daten übersprungen.");
        }

        // 6. Aktuelle Station in DB schreiben (NEUE CODE: Nutze die fetched 'id' um sicherzugehen)
        if ($am_gleis_flag) {
            // Bei "Am Gleis" bleibt der eventuell vorhandene alte Ist-Ankunftswert unberührt
            $stmtUpdate = $db->prepare("UPDATE timetable SET actual_departure = ?, track = ?, remarks = ? WHERE id = ?");
            $stmtUpdate->execute([$actual_departure, $track_number ?? '', "+" . $effective_delay . " (Am Gleis)", $timetable_entry['id']]);
        } else {
            $stmtUpdate = $db->prepare("UPDATE timetable SET actual_arrival = ?, actual_departure = ?, track = ?, remarks = ? WHERE id = ?");
            $stmtUpdate->execute([$actual_arrival, $actual_departure, $track_number ?? '', "+" . $delay, $timetable_entry['id']]);
        }
        
        write_log("🎉 DB-UPDATE: Zug $train_num an $station_abbr auf +" . ($am_gleis_flag ? $effective_delay : $delay) . " Min gesetzt.");
        write_log("DEBUG: Starte Propagation der aktuellen Fahrt von Zug $train_num bis zum Ende des Fahrplans.");

        // 7. Freie Fahrt für die 7%-Reserve-Propagation über die bereinigten Folgehalte
        propagateTravelTimeWithReserve($db, $train_id);

        // 8. Verspätungs-Kaskade auf den verknüpften Folgezug über ZID
        $current_sts_zid = $train['sts_zid'] ?? null;
        if (!empty($current_sts_zid) && function_exists('recalculateDelayCascade')) {
            $stmtLastStop = $db->prepare(" 
                SELECT departure, arrival, actual_departure, actual_arrival FROM timetable 
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
        
        // 9. Audit-Log
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

        echo json_encode(['success' => true, 'message' => "Aktuelle Fahrt bis zum Ende des Fahrplans neu berechnet und propagiert."]);
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
?>