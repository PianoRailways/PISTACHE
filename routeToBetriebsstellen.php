<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Route zu RCS Converter</title>
    <style>
        body {
            font-family: sans-serif;
            max-width: 900px;
            margin: 40px auto;
            padding: 0 20px;
            color: #333;
            line-height: 1.5;
        }
        .container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 15px;
        }
        label {
            font-weight: bold;
            display: block;
            margin-bottom: 5px;
        }
        textarea {
            width: 100%;
            height: 350px;
            font-family: monospace;
            padding: 10px;
            box-sizing: border-box;
            border: 1px solid #ccc;
            border-radius: 4px;
            resize: vertical;
        }
        .actions {
            margin-top: 10px;
        }
        button {
            background-color: #007612;
            color: white;
            border: none;
            padding: 10px 20px;
            font-size: 15px;
            cursor: pointer;
            border-radius: 4px;
            margin-right: 10px;
        }
        button:hover {
            background-color: #00500c;
        }
        button.secondary {
            background-color: #4a5568;
        }
        button.secondary:hover {
            background-color: #2d3748;
        }
        .error {
            color: #c00;
            margin-top: 10px;
            font-weight: bold;
        }
        .success-badge {
            color: #007612;
            font-weight: bold;
            margin-left: 10px;
            display: inline-block;
        }
    </style>
</head>
<body>

    <h2>Route zu Betriebsstellenconfig (RCS) Converter</h2>
    
    <div class="container">
        <div>
            <label for="input">PHP-Array der Route:</label>
            <textarea id="input" placeholder="'flp' => [ ... ]" oninput="liveConvert()"></textarea>
        </div>
        <div>
            <label for="output">Generiertes JSON (TXT-Format, generiert aus RCS-Route):</label>
            <textarea id="output" readonly placeholder='{"aids": [], "stations": []}'></textarea>
        </div>
    </div>

    <div class="actions">
        <button onclick="copyToClipboard()">In Zwischenablage kopieren</button>
        <button class="secondary" onclick="downloadJson()">Als Datei herunterladen</button>
        <span id="status" class="success-badge"></span>
    </div>
    
    <div id="error" class="error"></div>

    <script>
        let currentJson = '';
        let currentRouteName = 'Export';

        function liveConvert() {
            const input = document.getElementById('input').value;
            const outputField = document.getElementById('output');
            const errorDiv = document.getElementById('error');
            
            errorDiv.innerText = '';
            
            if (!input.trim()) {
                outputField.value = '';
                currentJson = '';
                return;
            }

            try {
                const nameMatch = input.match(/'name'\s*=>\s*['"]([^'"]+)['"]/);
                currentRouteName = nameMatch ? nameMatch[1] : 'Export';

                const stationRegex = /\[\s*'id'\s*=>\s*['"]([^'"]+)['"]\s*,\s*'name'\s*=>\s*['"]([^'"]+)['"]\s*,\s*'abbr'\s*=>\s*['"]([^'"]+)['"]\s*,\s*'km'\s*=>\s*([^,\s\]]+)/g;
                
                let stations = [];
                let match;

                while ((match = stationRegex.exec(input)) !== null) {
                    const abbr = match[3];
                    const km = match[4];

                    stations.push({
                        stationName: abbr,
                        platforms: [],
                        distance: parseFloat(km).toFixed(1)
                    });
                }

                if (stations.length === 0) {
                    outputField.value = '';
                    currentJson = '';
                    return;
                }

                const result = {
                    aids: ["0"],
                    stations: stations
                };

                currentJson = JSON.stringify(result, null, 2);
                outputField.value = currentJson;

            } catch (e) {
                errorDiv.innerText = 'Fehler beim Parsen: ' + e.message;
            }
        }

        function copyToClipboard() {
            const outputField = document.getElementById('output');
            if (!outputField.value) return;

            outputField.select();
            navigator.clipboard.writeText(outputField.value).then(() => {
                showStatus('Kopiert!');
            });
        }

        function downloadJson() {
            if (!currentJson) return;

            const blob = new Blob([currentJson], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `Betriebsstellenconfig (aus RCS) - ${currentRouteName}.txt`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            
            showStatus('Datei heruntergeladen!');
        }

        function showStatus(text) {
            const statusSpan = document.getElementById('status');
            statusSpan.innerText = text;
            setTimeout(() => {
                statusSpan.innerText = '';
            }, 2000);
        }
    </script>

</body>
</html>