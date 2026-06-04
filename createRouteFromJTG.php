<?php
$didok = file_exists('didok_cache.json') ? json_decode(file_get_contents('didok_cache.json'), true) : [];

$finalOutput = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $xmlInput = $_POST['xml_input'] ?? '';
    $routeKey = $_POST['route_key'] ?? 'neue-route';
    $routeId = $_POST['route_id'] ?? 'neue-route-id';
    $routeName = $_POST['route_name'] ?? 'Neue Route';
    
    try {
        $xml = new SimpleXMLElement($xmlInput);
        $stations = [];

        foreach ($xml->stations->station as $s) {
            $abbr = (string)$s['name'];
            $stations[] = [
                'id' => $abbr,
                'name' => $didok[$abbr] ?? 'Unbekannt',
                'abbr' => $abbr,
                'km' => (float)$s['pos']
            ];
        }

        $finalOutput = "    '$routeKey' => [\n";
        $finalOutput .= "        'id' => '$routeId',\n";
        $finalOutput .= "        'name' => '$routeName',\n";
        $finalOutput .= "        'stations' => [\n";
        foreach ($stations as $st) {
            $finalOutput .= "            ['id' => '{$st['id']}', 'name' => '{$st['name']}', 'abbr' => '{$st['abbr']}', 'km' => {$st['km']}],\n";
        }
        $finalOutput .= "        ]\n";
        $finalOutput .= "    ]";
        
    } catch (Exception $e) {
        $finalOutput = "Fehler: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Route Generator</title>
    <style>
        body { background: #121212; color: #e0e0e0; font-family: sans-serif; padding: 20px; }
        textarea, input { background: #1e1e1e; color: #fff; border: 1px solid #333; padding: 8px; border-radius: 4px; }
        textarea { width: 100%; height: 200px; font-family: monospace; }
        pre { background: #000; padding: 15px; border-radius: 5px; border: 1px solid #333; color: #00ff00; }
        button { background: #333; color: #fff; border: none; padding: 10px 20px; cursor: pointer; border-radius: 4px; margin-top: 10px; }
        button:hover { background: #555; }
    </style>
</head>
<body>
    <h1>JTG zu PHP-Route</h1>
    <form method="post">
        Route Key: <input type="text" name="route_key" required><br>
        Route ID: <input type="text" name="route_id" required><br>
        Route Name: <input type="text" name="route_name" required><br><br>
        XML-Inhalt:<br>
        <textarea name="xml_input"></textarea><br>
        <button type="submit">Generieren</button>
    </form>

    <?php if ($finalOutput): ?>
        <h3>Copy & Paste:</h3>
        <pre id="output"><?php echo htmlspecialchars($finalOutput); ?></pre>
        <button onclick="copyToClipboard()">Code kopieren</button>
    <?php endif; ?>

    <script>
    function copyToClipboard() {
        const text = document.getElementById('output').innerText;
        navigator.clipboard.writeText(text).then(() => {
            //alert('Code in die Zwischenablage kopiert!');
        });
    }
    </script>
</body>
</html>