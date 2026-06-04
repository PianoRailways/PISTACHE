<?php
$didok = file_exists('didok_cache.json') ? json_decode(file_get_contents('didok_cache.json'), true) : [];
$finalOutput = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $routeName = $_POST['route_name'] ?? 'Route';
    $routeId = $_POST['route_id'] ?? 'id';
    $routeKey = $_POST['route_key'] ?? 'key';
    
    $rows = explode("\n", trim($_POST['input']));
    $finalOutput = "    '$routeKey' => [\n";
    $finalOutput .= "        'id' => '$routeId',\n";
    $finalOutput .= "        'name' => '$routeName',\n";
    $finalOutput .= "        'stations' => [\n";

    foreach ($rows as $row) {
        $parts = preg_split('/\s+/', trim($row));
        if (count($parts) >= 2) {
            $abbr = strtoupper($parts[0]);
            $km = (float)$parts[1];
            $name = $didok[$abbr] ?? 'Unbekannt';
            $finalOutput .= "            ['id' => '$abbr', 'name' => '$name', 'abbr' => '$abbr', 'km' => $km],\n";
        }
    }
    $finalOutput .= "        ]\n    ],";
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
    <h2>Route Generator</h2>
    <form method="post">
        <input type="text" name="route_key" placeholder="Route Key (z.B. gotthard-sud)" required>
        <input type="text" name="route_id" placeholder="Route ID (z.B. gotthard-sud)" required>
        <input type="text" name="route_name" placeholder="Name" required><br><br>
        <textarea name="input" placeholder="BEL 150.9&#10;GIU 154.0"></textarea><br>
        <button type="submit">Generieren</button>
    </form>

    <?php if ($finalOutput): ?>
        <h3>Resultat:</h3>
        <pre id="output"><?php echo htmlspecialchars($finalOutput); ?></pre>
        <button onclick="copyToClipboard()">Code kopieren</button>
    <?php endif; ?>

    <script>
    function copyToClipboard() {
        const text = document.getElementById('output').innerText;
        navigator.clipboard.writeText(text).then(() => alert('In Zwischenablage kopiert!'));
    }
    </script>
</body>
</html>