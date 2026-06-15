<?php
// index.php
date_default_timezone_set('Europe/Zurich');
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

// weils eine Vorwärtsschleife (Forward-Only) bei SQLite3 ist, sammeln wir die Ergebnisse 
// zuerst in ein Array, um die Anzahl vorab für den <title> bestimmen zu können.
$rows = [];
$disturbance_count = 0;

while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
    $rows[] = $row;
    // Zähle nur relevante, aktive Einschränkungen für den Tab-Badge
    if ($row['type'] === 'disruption' || $row['type'] === 'construction') {
        $disturbance_count++;
    }
}
$has_entries = !empty($rows);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="60">
    <title><?php echo $disturbance_count > 0 ? "({$disturbance_count}) " : ""; ?>RCS Betriebslagemonitor</title>
    <link id="favicon" rel="icon" type="image/png" href="../favicon.ico">
    <style>
        body { margin: 20px; background: #0f172a; color: #f8fafc; font-family: sans-serif; }
        
        .countdown-container {
            max-width: 930px;
            margin: 0 auto;
            height: 4px;
            background: #222;
            border-top-left-radius: 4px;
            border-top-right-radius: 4px;
            overflow: hidden;
        }

        .countdown-bar {
            height: 100%;
            background: #eb0000;
            width: 100%;
            animation: countdown 60s linear forwards;
        }

        @keyframes countdown {
            from { width: 100%; }
            to { width: 0%; }
        }
        
        footer {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            background: #222222;
            margin-top: 40px;
            border-top: 2px solid #eb0000;
            padding: 15px 0;
            box-shadow: 0 -4px 10px rgba(0, 0, 0, 0.3);
            z-index: 9999;
        }

        .footer-panel {
            max-width: 900px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
        }

        .footer-panel p {
            margin: 0;
            font-size: 16px;
            font-weight: bold;
            color: #ffffff;
        }

        .footer-panel a {
            color: #ffffff;
            text-decoration: none;
            background: #eb0000;
            padding: 6px 15px;
            border-radius: 3px;
            margin-left: 10px;
            transition: background 0.2s ease;
        }

        .footer-panel a:hover {
            background: #c50000;
        }
		
        .filter-panel { background: #1e293b; padding: 15px; margin-bottom: 20px; border-radius: 4px; display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .filter-panel label { font-size: 12px; font-weight: bold; display: block; margin-bottom: 5px; color: #fff; }
        .filter-panel select, .filter-panel input, .filter-panel button { padding: 6px; border: 1px solid #555; background: #222; color: #fff; border-radius: 3px; }
        .filter-panel button { background: #eb0000; font-weight: bold; cursor: pointer; border: none; padding: 6px 15px; }
        .filter-panel button:hover { background: #c50000; }

        .monitor-box { max-width: 900px; margin: 0 auto 25px auto; box-shadow: 0 4px 10px rgba(0,0,0,0.3); font-size: 16px; font-weight: bold; }
        .monitor-header { height: 24px; background: #cccccc; color: #333333; font-size: 12px; font-weight: normal; display: flex; align-items: center; justify-content: space-between; padding: 0 8px; border-bottom: 1px solid #999; }
        .monitor-header .left-dots { display: flex; gap: 4px; align-items: center; }
        .monitor-header .dot { width: 8px; height: 8px; border-radius: 50%; background: #555; }
        
        .monitor-body { padding: 15px 20px 25px 20px; position: relative; }
        
        .type-info, .type-resolved { background: #1a2a6c; }
        .type-construction { background: #d96b00; }
        .type-disruption { background: #d30000; }

        .main-title { font-size: 21px; margin: 0 0 15px 0; line-height: 1.3; padding-left: 45px; position: relative; }
        
        .icon { position: absolute; left: 0; top: 2px; width: 30px; height: 30px; border: 2px solid #fff; display: flex; align-items: center; justify-content: center; box-sizing: border-box; }
        .type-info .icon::before, .type-resolved .icon::before { content: "i"; font-family: serif; font-size: 20px; }
        .type-construction .icon::before { content: "🚧"; font-size: 16px; }
        .type-disruption .icon::before { content: "!"; font-size: 20px; }

        .info-row { margin: 8px 0; padding-left: 10px; font-weight: normal; font-size: 15px; }
        .info-label { font-weight: bold; border-left: 3px solid #fff; padding-left: 8px; margin-right: 5px; min-width: 140px; display: inline-block; box-sizing: border-box; }
        
        .link-bar { background: #cccccc; color: #1a2a6c; padding: 8px 12px; margin-top: 20px; font-size: 16px; font-weight: bold; border-left: 4px solid #d30000; }
        .type-info .link-bar, .type-resolved .link-bar { border-left-color: #1a2a6c; }
        .type-construction .link-bar { border-left-color: #d96b00; }

        .no-data { color: #333; text-align: center; padding: 20px; font-weight: normal; }
    </style>
    <script>
        setTimeout(function() {
            window.location.reload();
        }, 60000);
    </script>
</head>

<body>

<div class="countdown-container">
    <div class="countdown-bar"></div>
</div>

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
                <?php endwhile; ?>
            </select>
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
$fun_facts = [
    "Der Gotthard-Basistunnel ist mit 57 Kilometern der längste Eisenbahntunnel der Welt.",
    "Der Taktfahrplan sorgt in der Schweiz seit 1982 für lückenlose Anschlüsse.",
    "Im StellwerkSim gibt es über 100 Schweizer Stellwerke.",
    "In der Schweiz gibt es verschiedene Null-Punkte für die Kilometrierung der Strecken. In Olten ist keiner davon.",
];

$random_fact = $fun_facts[array_rand($fun_facts)];

foreach ($rows as $row): 
    $is_resolved = ($row['type'] === 'resolved');
    
    if ($is_resolved) {
        $header_title = 'Störung behoben';
    } else {
        $type_labels = ['info' => 'Vorinformation', 'construction' => 'Bauarbeiten', 'disruption' => 'Störung'];
        $header_title = $type_labels[$row['type']];
    }
    
    if ($row['location_type'] === 'line') {
        $location_text = "auf der Strecke " . htmlspecialchars($row['station_a']) . " – " . htmlspecialchars($row['station_b']);
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
        <div><span>rcs.stellwerksim.ch/blm</span></div>
    </div>

    <div class="monitor-body type-<?php echo $row['type']; ?>">
        <h2 class="main-title">
            <div class="icon"></div>
            <?php if ($is_resolved): ?>
                Störung behoben: <?php echo htmlspecialchars($row['reason']); ?>
            <?php else: ?>
                <?php echo $header_title; ?>: Der Bahnverkehr <?php echo $location_text; ?> ist <?php echo $status_text; ?>
            <?php endif; ?>
        </h2>
        
        <?php if (!$is_resolved && !empty($row['lines_affected'])): ?>
            <div class="info-row"><span class="info-label">Betroffene Linien:</span><?php echo htmlspecialchars($row['lines_affected']); ?></div>
        <?php endif; ?>

        <?php if (!$is_resolved): ?>
            <div class="info-row"><span class="info-label">Grund:</span><?php echo htmlspecialchars($row['reason']); ?></div>
        <?php endif; ?>
        
        <?php if (!empty($row['impact'])): ?>
            <div class="info-row"><span class="info-label">Auswirkungen:</span><?php echo htmlspecialchars($row['impact']); ?></div>
        <?php endif; ?>

        <div class="info-row"><span class="info-label">Dauer:</span><?php echo $display_start; ?> - <?php echo $display_end; ?></div>

        <div class="link-bar">Viel Spass beim Spielen!</div>
    </div>
</div>

<?php endforeach; ?>


<?php if (!$has_entries): ?>
    <style>
        .default-box { max-width: 900px; margin: 0 auto; box-shadow: 0 4px 10px rgba(0,0,0,0.3); font-family: Arial, sans-serif; }
        .default-header { height: 24px; background: #cccccc; color: #333333; font-size: 12px; display: flex; align-items: center; justify-content: flex-end; padding: 0 8px; }
        .default-top { background: #1a2a6c; padding: 30px 25px; text-align: left; }
        .default-top h1 { font-size: 28px; margin: 0; font-weight: bold; line-height: 1.3; color: #fff; }
        .default-bottom { background: #cccccc; color: #1a2a6c; padding: 30px 25px; display: flex; gap: 20px; align-items: flex-start; }
        .default-icon { font-size: 36px; line-height: 1; margin-top: -5px; }
        .default-text { font-size: 24px; font-weight: bold; line-height: 1.3; }
        .default-subtitle { font-size: 24px; color: #555; font-weight: normal; margin-bottom: 8px; }
    </style>

    <div class="default-box">
        <div class="default-header">
        <div><span>Informationen: <a href="https://rcs.stellwerksim.ch/blm" style="text-decoration: none; color: inherit;">rcs.stellwerksim.ch/blm</a></span></div>
        </div>
        
        <div class="default-top">
            <h1>Aktuell keine grösseren Störungen oder Bauarbeiten bekannt.<br>Wir wünschen ein gutes Spiel!</h1>
        </div>
        
        <div class="default-bottom">
            <div class="default-icon">💡</div>
            <div>
                <div class="default-subtitle">Schon gewusst?</div>
                <div class="default-text"><?php echo htmlspecialchars($random_fact); ?></div>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const count = <?php echo $disturbance_count; ?>;
    
    if (count > 0) {
        const favicon = document.getElementById('favicon');
        const canvas = document.createElement('canvas');
        canvas.width = 32;
        canvas.height = 32;
        const ctx = canvas.getContext('2d');

        // Basis-Quadrat für das Favicon zeichnen
        ctx.fillStyle = '#1e293b'; 
        ctx.fillRect(0, 0, 32, 32);
        
        // Text-Hintergrund "RCS"
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 12px sans-serif';
        ctx.fillText('RCS', 4, 20);

        // Roter Alarmkreis oben rechts
        ctx.beginPath();
        ctx.arc(24, 8, 8, 0, 2 * Math.PI);
        ctx.fillStyle = '#eb0000';
        ctx.fill();

        // Zahl in den Kreis schreiben
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 10px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(count > 9 ? '9+' : count, 24, 8);

        // Favicon austauschen
        favicon.href = canvas.toDataURL('image/png');
    }
});
</script>

</body>
<footer>
    <div class="footer-panel">
        <p>Betriebslage anpassen oder neue Störung erfassen?<a href="./report">Hier klicken</a></p>
        <p>StiTz-Nummer der Hotline: 7863</p>
    </div>
</footer>
</html>