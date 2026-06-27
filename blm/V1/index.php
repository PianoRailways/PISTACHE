<?php
// index.php
$db = new SQLite3('rcs_portal.db');

// Filter-Standardwerte setzen (Aktueller Zeitpunkt)
$now = date('Y-m-d H:i');
$filter_station = isset($_GET['station']) ? $_GET['station'] : '';
$time_filter = isset($_GET['time_filter']) ? $_GET['time_filter'] : 'current';

$start_filter = isset($_GET['start_filter']) ? $_GET['start_filter'] : '';
$end_filter = isset($_GET['end_filter']) ? $_GET['end_filter'] : '';

// Holen aller Bahnhöfe für das Dropdown
$stations_res = $db->query("SELECT DISTINCT station FROM disturbances ORDER BY station ASC");

// SQL Query aufbauen
$query_str = "SELECT * FROM disturbances WHERE 1=1";

if (!empty($filter_station)) {
    $query_str .= " AND station = :station";
}

if ($time_filter === 'current') {
    $query_str .= " AND start_time <= :now AND end_time >= :now";
} elseif ($time_filter === 'custom' && !empty($start_filter) && !empty($end_filter)) {
    $start_f = str_replace('T', ' ', $start_filter);
    $end_f = str_replace('T', ' ', $end_filter);
    $query_str .= " AND start_time <= :end_filter AND end_time >= :start_filter";
} // 'all' benötigt keine zeitliche Einschränkung

$query_str .= " ORDER BY start_time ASC";
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
        
        /* Filter Panel */
        .filter-panel { background: #333; padding: 15px; margin-bottom: 20px; border-radius: 4px; color: #fff; display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-panel label { font-size: 12px; font-weight: bold; display: block; margin-bottom: 5px; }
        .filter-panel select, .filter-panel input, .filter-panel button { padding: 6px; border: 1px solid #555; background: #222; color: #fff; border-radius: 3px; }
        .filter-panel button { background: #eb0000; font-weight: bold; cursor: pointer; border: none; padding: 6px 15px; }
        .filter-panel button:hover { background: #c50000; }

        /* SBB Monitor Style */
        .monitor-box { max-width: 900px; margin: 0 auto 25px auto; box-shadow: 0 4px 10px rgba(0,0,0,0.3); font-size: 16px; font-weight: bold; }
        
        /* Obere System-Leiste */
        .monitor-header { height: 24px; background: #cccccc; color: #333333; font-size: 12px; font-weight: normal; display: flex; align-items: center; justify-content: space-between; padding: 0 8px; border-bottom: 1px solid #999; }
        .monitor-header .left-dots { display: flex; gap: 4px; align-items: center; }
        .monitor-header .dot { width: 8px; height: 8px; border-radius: 50%; background: #555; }
        .monitor-header a { color: #333; text-decoration: underline; }

        /* Monitor Typen */
        .monitor-body { padding: 15px 20px 25px 20px; position: relative; }
        
        .type-strike { background: #d30000; } /* Kräftiges SBB-Rot */
        .type-construction { background: #1a2a6c; } /* Tiefes SBB-Infoline-Blau */

        /* Layout Inhalte */
        .main-title { font-size: 22px; margin: 0 0 15px 0; line-height: 1.3; padding-left: 45px; position: relative; }
        
        /* CSS-Icons anstelle von Bilddateien */
        .icon { position: absolute; left: 0; top: 2px; width: 30px; height: 30px; border: 2px solid #fff; display: flex; align-items: center; justify-content: center; box-sizing: border-box; }
        .icon-strike::before { content: "!"; font-size: 20px; }
        .icon-construction::before { content: "🚧"; font-size: 18px; }

        .info-row { margin: 8px 0; padding-left: 10px; font-weight: normal; font-size: 15px; }
        .info-label { font-weight: bold; border-left: 3px solid #fff; padding-left: 8px; margin-right: 5px; }
        
        /* Fahrplanprüfungs-Balken unten */
        .link-bar { background: #cccccc; color: #1a2a6c; padding: 8px 12px; margin-top: 20px; font-size: 16px; font-weight: bold; border-left: 4px solid #d30000; }
        .type-construction .link-bar { border-left-color: #1a2a6c; }

        .no-data { color: #333; text-align: center; padding: 4px; font-weight: normal; }
    </style>
    <script>
        // Blendet die exakte Datumsauswahl aus, wenn nicht "Zeitraum" gewählt ist
        function toggleCustomTime() {
            var filter = document.getElementById('time_filter').value;
            document.getElementById('custom_time_inputs').style.display = (filter === 'custom') ? 'inline-flex' : 'none';
        }
    </script>
</head>
<body>

<div class="filter-panel">
    <form method="GET" action="index.php" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; width: 100%;">
        <div>
            <label for="station">Bahnhof / Betriebsstelle:</label>
<select id="station" name="station">
    <option value="">-- Alle Bahnhöfe --</option>
    <?php while($st = $stations_res->fetchArray(SQLITE3_ASSOC)): ?>
        <option value="<?php echo htmlspecialchars($st['station']); ?>" <?php if($filter_station === $st['station']) echo 'selected'; ?>>
            <?php echo htmlspecialchars($st['station']); ?>
        </option>
    <?php endwhile; ?> </select>
        </div>

        <div>
            <label for="time_filter">Anzeigezeitraum:</label>
            <select id="time_filter" name="time_filter" onchange="toggleCustomTime()">
                <option value="current" <?php if($time_filter === 'current') echo 'selected'; ?>>Nur aktuell aktive Meldungen</option>
                <option value="all" <?php if($time_filter === 'all') echo 'selected'; ?>>Alle (Gesamtes Jahr / Archiv)</option>
                <option value="custom" <?php if($time_filter === 'custom') echo 'selected'; ?>>Spezifischer Zeitraum...</option>
            </select>
        </div>

        <div id="custom_time_inputs" style="display: <?php echo ($time_filter === 'custom') ? 'inline-flex' : 'none'; ?>; gap: 10px;">
            <div>
                <label for="start_filter">Von:</label>
                <input type="datetime-local" id="start_filter" name="start_filter" value="<?php echo htmlspecialchars($start_filter); ?>">
            </div>
            <div>
                <label for="end_filter">Bis:</label>
                <input type="datetime-local" id="end_filter" name="end_filter" value="<?php echo htmlspecialchars($end_filter); ?>">
            </div>
        </div>

        <div>
            <button type="submit">Monitor aktualisieren</button>
        </div>
    </form>
</div>

<?php 
$has_entries = false;
while ($row = $results->fetchArray(SQLITE3_ASSOC)): 
    $has_entries = true;
    $is_strike = ($row['type'] === 'strike');
    $body_class = $is_strike ? 'type-strike' : 'type-construction';
    $icon_class = $is_strike ? 'icon-strike' : 'icon-construction';
    
    // Formatierung der Datumsanzeige für die Ausgabe im SBB-Stil
    $display_start = date('d.m.Y, H:i', strtotime($row['start_time']));
    $display_end = date('d.m.Y, H:i', strtotime($row['end_time']));
?>

<div class="monitor-box">
    <div class="monitor-header">
        <div class="left-dots">
            <div class="dot"></div><div class="dot"></div><div class="dot"></div>
            <span><?php echo $is_strike ? 'Einschränkungen im Bahnverkehr' : 'Informationen'; ?></span>
        </div>
        <div>
            <span>Informationen zum Bahnverkehr: sbb.ch/railinfo</span>
        </div>
    </div>

    <div class="monitor-body <?php echo $body_class; ?>">
        <h2 class="main-title">
            <div class="icon <?php echo $icon_class; ?>"></div>
            <?php if($is_strike): ?>
                Fahrplanänderung: Der Bahnverkehr in <?php echo htmlspecialchars($row['station']); ?> ist eingeschränkt.
            <?php else: ?>
                Ankündigung Bauarbeiten: Der Bahnverkehr zwischen <?php echo htmlspecialchars($row['station']); ?> ist unterbrochen.
            <?php endif; ?>
        </h2>

        <div class="info-row">
            <span class="info-label">Grund:</span><?php echo htmlspecialchars($row['reason']); ?>
        </div>
        
        <?php if(!empty($row['impact'])): ?>
        <div class="info-row">
            <span class="info-label">Auswirkungen:</span><?php echo htmlspecialchars($row['impact']); ?>
        </div>
        <?php endif; ?>

        <div class="info-row">
            <span class="info-label">Dauer:</span><?php echo $display_start; ?> - <?php echo $display_end; ?>
        </div>

        <div class="link-bar">
            Verbindung im Online-Fahrplan prüfen.
        </div>
    </div>
</div>

<?php endwhile; ?>

<?php if (!$has_entries): ?>
    <div class="no-data">Aktuell liegen keine Fahrplanänderungen oder Störungen für die gewählten Filter vor.</div>
<?php endif; ?>

</body>
</html>