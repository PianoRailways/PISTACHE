<?php
$generated_php_code = "";
$route_id = $_POST['route_id'] ?? 'meine_strecke';
$route_name = $_POST['route_name'] ?? 'Meine Strecke';
$raw_json = $_POST['json_data'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($raw_json)) {
    $data = json_decode(trim($raw_json), true);

    if (json_last_error() === JSON_ERROR_NONE && isset($data['stations'])) {
        $stations = $data['stations'];

        // Geografische Sortierung nach Kilometrierung (Distance)
        usort($stations, function ($a, $b) {
            return floatval($a['distance']) <=> floatval($b['distance']);
        });

        $php_lines = [];
        $php_lines[] = "    '" . addslashes($route_id) . "' => [";
        $php_lines[] = "        'id' => '" . addslashes($route_id) . "',";
        $php_lines[] = "        'name' => '" . addslashes($route_name) . "',";
        $php_lines[] = "        'stations' => [";

        foreach ($stations as $st) {
            $stationName = trim($st['stationName'] ?? '');
            $distance = floatval($st['distance'] ?? 0.0);

            // Hilfs-Betriebsstellen (z.B. CHI1xx) herausfiltern
            if (empty($stationName) || strpos($stationName, 'xx') !== false) {
                continue;
            }

            // PHP-Array-Zeile für diese Station generieren
            $php_lines[] = "            ['id' => '{$stationName}', 'name' => '{$stationName}', 'abbr' => '{$stationName}', 'km' => {$distance}],";
        }

        $php_lines[] = "        ]";
        $php_lines[] = "    ],";

        $generated_php_code = implode("\n", $php_lines);
    } else {
        $generated_php_code = "// Fehler: Ungültiges JSON-Format oder keine Stationen gefunden. (" . json_last_error_msg() . ")";
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>PHP Route Array Generator</title>
    <style>
        body { background: #121212; color: #e0e0e0; font-family: sans-serif; padding: 20px; }
        textarea { width: 100%; height: 200px; background: #1e1e1e; color: #00ff00; border: 1px solid #333; font-family: monospace; padding: 10px; box-sizing: border-box; }
        .output-box { background: #1a237e; color: #ffffff; height: 350px; border: 1px solid #3f51b5; }
        input, button { background: #2d2d2d; color: #fff; border: 1px solid #444; padding: 10px 20px; border-radius: 4px; font-weight: bold; }
        input { background: #1e1e1e; font-weight: normal; padding: 8px; }
        button { background: #2563eb; border: none; cursor: pointer; }
        button:hover { background: #1d4ed8; }
        .config-bar { display: flex; gap: 20px; margin-bottom: 20px; align-items: center; }
        h3 { margin-top: 25px; color: #fff; }
    </style>
</head>
<body>

    <h2>PHP Route Array Generator</h2>
    <p>Füge das JSON ein, definiere die IDs für deine Routen-Konfiguration und generiere den fertigen PHP-Code.</p>

    <form method="POST">
        <textarea name="json_data" placeholder="Hier das JSON mit den 'stations' einfügen..."><?= htmlspecialchars($raw_json) ?></textarea>
        
        <br><br>
        
        <div class="config-bar">
            <div>
                <label>Routen-ID (Array-Key): </label>
                <input type="text" name="route_id" value="<?= htmlspecialchars($route_id) ?>" required>
            </div>
            <div>
                <label>Anzeigename (Name): </label>
                <input type="text" name="route_name" value="<?= htmlspecialchars($route_name) ?>" required>
            </div>
            
            <button type="submit">PHP Code generieren</button>
        </div>
    </form>

    <?php if (!empty($generated_php_code)): ?>
        <h3>Generierter PHP-Code:</h3>
        <textarea class="output-box" readonly onclick="this.select();"><?= htmlspecialchars($generated_php_code) ?></textarea>
        <p style="font-size: 12px; color: #aaa; margin-top: 5px;">💡 Ein Klick in das blaue Feld markiert den gesamten PHP-Block, sodass du ihn direkt mit Strg+C kopieren kannst.</p>
    <?php endif; ?>

</body>
</html>