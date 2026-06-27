<?php
// index.php
$db = new SQLite3('rcs_portal.db');

$now = date('Y-m-d H:i');
$filter_station = isset($_GET['station']) ? $_GET['station'] : '';
$time_filter = isset($_GET['time_filter']) ? $_GET['time_filter'] : 'current';
$start_filter = isset($_GET['start_filter']) ? $_GET['start_filter'] : '';
$end_filter = isset($_GET['end_filter']) ? $_GET['end_filter'] : '';

$stations_res = $db->query("SELECT DISTINCT station_a AS st FROM disturbances UNION SELECT DISTINCT station_b AS st FROM disturbances WHERE station_b IS NOT NULL ORDER BY st ASC");

$query_str = "
    SELECT d1.* FROM disturbances d1
    WHERE d1.id = (SELECT MAX(d2.id) FROM disturbances d2 WHERE d2.incident_id = d1.incident_id)
";

if (!empty($filter_station)) {
    $query_str .= " AND (d1.station_a = :station OR d1.station_b = :station)";
}

if ($time_filter === 'current') {
    $query_str .= " AND d1.start_time <= :now AND d1.end_time >= :now";
} elseif ($time_filter === 'custom' && !empty($start_filter) && !empty($end_filter)) {
    $start_f = str_replace('T', ' ', $start_filter);
    $end_f = str_replace('T', ' ', $end_filter);
    $query_str .= " AND d1.start_time <= :end_filter AND d1.end_time >= :start_filter";
}

$query_str .= " ORDER BY d1.start_time ASC";
$stmt = $db->prepare($query_str);

if (!empty($filter_station)) $stmt->bindValue(':station', $filter_station, SQLITE3_TEXT);
if ($time_filter === 'current') $stmt->bindValue(':now', $now, SQLITE3_TEXT);
if ($time_filter === 'custom' && !empty($start_filter) && !empty($end_filter)) {
    $stmt->bindValue(':start_filter', $start_f, SQLITE3_TEXT);
    $stmt->bindValue(':end_filter', $end_f, SQLITE3_TEXT);
}

$results = $stmt->execute();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>RCS Betriebslagemonitor</title>
    <style>
        body { font-family: Arial, sans-serif; background: #e6e6e6; margin: 0; padding: 20px; color: #fff; }
        
        .filter-panel { background: #333; padding: 15px; margin-bottom: 20px; border-radius: 4px; display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-panel label { font-size: 12px; font-weight: bold; display: block; margin-bottom: 5px; color: #fff; }
        .filter-panel select, .filter-panel input, .filter-panel button { padding: 6px; border: 1px solid #555; background: #222; color: #fff; border-radius: 3px; }
        .filter-panel button { background: #eb0000; font-weight: bold; cursor: pointer; border: none; padding: 6px 15px; }
        .filter-panel button:hover { background: #c50000; }

        .monitor-box { max-width: 900px; margin: 0 auto 25px auto; box-shadow: 0 4px 10px rgba(0,0,0,0.3); font-size: 16px; font-weight: bold; }
        .monitor-header { height: 24px; background: #cccccc; color: #333333; font-size: 12px; font-weight: normal; display: flex; align-items: center; justify-content: space-between; padding: 0 8px; border-bottom: 1px solid #999; }
        .monitor-header .left-dots { display: flex; gap: 4px; align-items: center; }
        .monitor-header .dot { width: 8px; height: 8px; border-radius: 50%; background: #555; }
        
        .monitor-body { padding: 15px 20px 25px 20px; position: relative; }
        
        .type-info { background: #1a2a6c; }
        .type-construction { background: #d96b00; }
        .type-disruption { background: #d30000; }

        .main-title { font-size: 21px; margin: 0 0 5px 0; line-height: 1.3; padding-left: 45px; position: relative; }
        
        .icon { position: absolute; left: 0; top: 2px; width: 30px; height: 30px; border: 2px solid #fff; display: flex; align-items: center; justify-content: center; box-sizing: border-box; }
        .type-info .icon::before { content: "i"; font-family: serif; font-size: 20px; }
        .type-construction .icon::before { content: "🚧"; font-size: 16px; }
        .type-disruption .icon::before { content: "!"; font-size: 20px; }

        /* Betroffene Linien Sub-Header Leiste */
        .lines-badge { font-size: 14px; color: #eee; font-weight: normal; margin-bottom: 15px; padding-left: 45px; opacity: 0.9; }

        .info-row { margin: 8px 0; padding-left: 10px; font-weight: normal; font-size: 15px; }
        .info-label { font-weight: bold; border-left: 3px solid #fff; padding-left: 8px; margin-right: 5px; }
        
        .link-bar { background: #cccccc; color: #1a2a6c; padding: 8px 12px; margin-top: 20px; font-size: 16px; font-weight: bold; border-left: 4px solid #d30000; }
        .type-info .link-bar { border-left-color: #1a2a6c; }
        .type-construction .link-bar { border-left-color: #d96b00; }

        .no-data { color: #333; text-align: center; padding: 20px; font-weight: normal; }
    </style>
</head>
<body>

<div class="filter-panel">
    <form method="GET" action="index.php" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; width: 100%;">
        <div>
            <label for="station">Betriebsstelle:</label>
<select id="station" name="station">
    <option value="">-- Alle --</option>
    <?php while($st = $stations_res->fetchArray(SQLITE3_ASSOC)): if(empty($st['st'])) continue; ?>
        <option value="<?php echo htmlspecialchars($st['st']); ?>" <?php if($filter_station === $st['st']) echo 'selected'; ?>>
            <?php echo htmlspecialchars($st['st']); ?>
        </option>
    <?php endwhile; ?> </select>
        </div>

        <div>
            <label for="time_filter">Zeitraum:</label>
            <select id="time_filter" name="time_filter" onchange="this.form.submit()">
                <option value="current" <?php if($time_filter === 'current') echo 'selected'; ?>>Aktuelle Meldungen</option>
                <option value="all" <?php if($time_filter === 'all') echo 'selected'; ?>>Gesamtes Jahr / Archiv</option>
                <option value="custom" <?php if($time_filter === 'custom') echo 'selected'; ?>>Zeitfenster wählen...</option>
            </select>
        </div>

        <?php if($time_filter === 'custom'): ?>
            <div>
                <label for="start_filter">Von:</label>
                <input type="datetime-local" id="start_filter" name="start_filter" value="<?php echo htmlspecialchars($start_filter); ?>">
            </div>
            <div>
                <label for="end_filter">Bis:</label>
                <input type="datetime-local" id="end_filter" name="end_filter" value="<?php echo htmlspecialchars($end_filter); ?>">
            </div>
            <div>
                <button type="submit">Filter anwenden</button>
            </div>
        <?php endif; ?>
    </form>
</div>

<?php 
$has_entries = false;
while ($row = $results->fetchArray(SQLITE3_ASSOC)): 
    $has_entries = true;
    
    $type_labels = ['info' => 'Vorinformation', 'construction' => 'Bauarbeiten', 'disruption' => 'Störung'];
    $header_title = $type_labels[$row['type']];
    
    if ($row['location_type'] === 'line') {
        $location_text = "auf der Strecke " . htmlspecialchars($row['station_a']) . " - " . htmlspecialchars($row['station_b']);
    } else {
        $location_text = "im Bahnhof " . htmlspecialchars($row['station_a']);
    }
    
    $status_text = ($row['status'] === 'interrupted') ? 'unterbrochen.' : 'eingeschränkt.';

    $display_start = date('d.m.Y, H:i', strtotime($row['start_time']));
    $display_end = date('d.m.Y, H:i', strtotime($row['end_time']));
?>

<div class="monitor-box">
    <div class="monitor-header">
        <div class="left-dots">
            <div class="dot"></div><div class="dot"></div><div class="dot"></div>
            <span><?php echo $header_title; ?> im Bahnverkehr</span>
        </div>
        <div><span>Informationen: sbb.ch/railinfo</span></div>
    </div>

    <div class="monitor-body type-<?php echo $row['type']; ?>">
        <h2 class="main-title">
            <div class="icon"></div>
            <?php echo $header_title; ?>: Der Bahnverkehr <?php echo $location_text; ?> ist <?php echo $status_text; ?>
        </h2>
        
        <?php if(!empty($row['lines_affected'])): ?>
            <div class="lines-badge">Betroffene Linien: <?php echo htmlspecialchars($row['lines_affected']); ?></div>
        <?php endif; ?>

        <div class="info-row"><span class="info-label">Grund:</span><?php echo htmlspecialchars($row['reason']); ?></div>
        
        <?php if(!empty($row['impact'])): ?>
            <div class="info-row"><span class="info-label">Auswirkungen:</span><?php echo htmlspecialchars($row['impact']); ?></div>
        <?php endif; ?>

        <div class="info-row"><span class="info-label">Dauer:</span><?php echo $display_start; ?> - <?php echo $display_end; ?></div>

        <div class="link-bar">Verbindung im Online-Fahrplan prüfen.</div>
    </div>
</div>

<?php endwhile; ?>

<?php if (!$has_entries): ?>
    <div class="no-data">Aktuell liegen keine Meldungen für die gewählten Kriterien vor.</div>
<?php endif; ?>

</body>
</html>