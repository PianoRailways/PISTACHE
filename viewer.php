<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>STS: Bildfahrplan Live</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #0f172a; color: #f8fafc; font-family: sans-serif; }
        
        .viewer-container {
            display: flex;
            flex-direction: column;
            width: 100vw;
            height: 100vh;
            padding: 10px;
            gap: 10px;
        }
        
        .viewer-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 5px 10px;
            background: #1e293b;
            border-radius: 4px;
            font-size: 10px;
            border: 1px solid #334155;
        }
        
        .viewer-header select, 
        .viewer-header input,
        .viewer-header button {
            background: #334155;
            color: #fff;
            border: 1px solid #475569;
            padding: 3px 7px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
        }
        
        .viewer-header button:hover {
            background: #64748b;
        }
        
        canvas {
            flex: 1;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 4px;
            display: block;
        }
        
        .time-info {
            color: #94a3b8;
            font-size: 12px;
        }
    </style>
</head>
<body>

<div class="viewer-container">
    <div class="viewer-header">
        <div>
            <strong>Strecke:</strong>
            <select id="route_select" onchange="onRouteChanged()"></select>
        </div>
        
        <div style="display: flex; gap: 15px; align-items: center;">
            <label>
                <strong>Von:</strong>
                <input type="time" id="graph_start" value="11:45" onchange="loadAndRenderGraph()">
            </label>
            <label>
                <strong>Bis:</strong>
                <input type="time" id="graph_end" value="16:00" onchange="loadAndRenderGraph()">
            </label>
            <label>
                <strong>STS-Offset (Min):</strong>
                <input type="number" id="sts_offset" value="0" min="-600" max="600" 
                    style="width: 50px;" onchange="loadAndRenderGraph()">
            </label>
        </div>
        
        <div style="display: flex; gap: 10px; align-items: center;">
            <button onclick="loadAndRenderGraph()" style="background: #10b981;">Refresh</button>
            <a href="./" style="color: #0ea5e9; text-decoration: none; font-size: 12px;">← Zurück</a>
        </div>
    </div>
    
    <canvas id="graphCanvas" width="1600" height="900"></canvas>
</div>

<script>
var routesConfig = {};
var currentRouteId = '';
var activeTrains = [];

// Kopiere die notwendigen Funktionen aus app.js
const colorMap = {
    'autumn': '#92400e',
    'red': '#dc2626',
    'OrangeSBB': '#f97316',
    'violet': '#8b5cf6',
    'orange': '#fb923c',
    'sky': '#0ea5e9',
    'green': '#22c55e',
    'magenta': '#d946ef',
    'night': '#1e293b',
    'red125': '#991b1b',
    'peach': '#fdba74'
};

const colorRules = [
    { type: 'contains', key: 'name', filter: 'Bierlisi', color: 'autumn' },
    { type: 'startsWith', key: 'name', filter: 'EC', color: 'red' },
    { type: 'startsWith', key: 'name', filter: 'ICE', color: 'red' },
    { type: 'startsWith', key: 'name', filter: 'IC', color: 'red' },
    { type: 'startsWith', key: 'name', filter: 'RJ', color: 'red' },
    { type: 'startsWith', key: 'name', filter: 'CNL', color: 'red' },
    { type: 'startsWith', key: 'name', filter: 'S', color: 'OrangeSBB' },
    { type: 'startsWith', key: 'name', filter: 'RoLa', color: 'violet' },
    { type: 'startsWith', key: 'name', filter: 'R', color: 'OrangeSBB' },
    { type: 'startsWith', key: 'name', filter: 'RE', color: 'orange' },
    { type: 'contains', key: 'name', filter: 'TEC', color: 'sky' },
    { type: 'contains', key: 'name', filter: 'TC', color: 'sky' },
    { type: 'startsWith', key: 'name', filter: 'MR', color: 'sky' },
    { type: 'contains', key: 'name', filter: 'INV', color: 'green' },
    { type: 'contains', key: 'name', filter: 'LIS', color: 'green' },
    { type: 'greaterThan', key: 'number', filter: 96999, color: 'magenta' },
    { type: 'greaterThan', key: 'number', filter: 95999, color: 'night' },
    { type: 'greaterThan', key: 'number', filter: 89999, color: 'OrangeSBB' },
    { type: 'greaterThan', key: 'number', filter: 87999, color: 'sky' },
    { type: 'greaterThan', key: 'number', filter: 87599, color: 'red125' },
    { type: 'greaterThan', key: 'number', filter: 59299, color: 'sky' },
    { type: 'greaterThan', key: 'number', filter: 55999, color: 'red' },
    { type: 'greaterThan', key: 'number', filter: 53999, color: 'green' },
    { type: 'greaterThan', key: 'number', filter: 50199, color: 'sky' },
    { type: 'greaterThan', key: 'number', filter: 49999, color: 'peach' },
    { type: 'greaterThan', key: 'number', filter: 44999, color: 'sky' },
    { type: 'greaterThan', key: 'number', filter: 42999, color: 'violet' },
    { type: 'greaterThan', key: 'number', filter: 39999, color: 'sky' },
    { type: 'greaterThan', key: 'number', filter: 36999, color: 'green' },
    { type: 'greaterThan', key: 'number', filter: 35999, color: 'green' },
    { type: 'greaterThan', key: 'number', filter: 29999, color: 'green' },
    { type: 'greaterThan', key: 'number', filter: 28999, color: 'sky' },
    { type: 'greaterThan', key: 'number', filter: 27999, color: 'green' },
    { type: 'greaterThan', key: 'number', filter: 27000, color: 'magenta' },
    { type: 'greaterThan', key: 'number', filter: 10999, color: 'OrangeSBB' },
    { type: 'greaterThan', key: 'number', filter: 9899, color: 'red' },
    { type: 'greaterThan', key: 'number', filter: 9849, color: 'OrangeSBB' },
    { type: 'greaterThan', key: 'number', filter: 9000, color: 'red' },
    { type: 'greaterThan', key: 'number', filter: 4099, color: 'OrangeSBB' },
    { type: 'lessThan', key: 'number', filter: 4100, color: 'red' }
];

function timeToMinutes(timeStr) {
    if (!timeStr) return null;
    const parts = timeStr.split(':');
    if (parts.length < 2) return null;
    const h = parseInt(parts[0], 10);
    const m = parseInt(parts[1], 10);
    return h * 60 + m;
}

function minutesToTime(totalMinutes) {
    if (totalMinutes === null || isNaN(totalMinutes)) return '';
    const positiveMinutes = (totalMinutes % 1440 + 1440) % 1440;
    const h = Math.floor(positiveMinutes / 60);
    const m = positiveMinutes % 60;
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

function getTrainColor(trainNumber, trainName) {
    const num = parseInt(trainNumber, 10);
    const name = trainName || "";

    for (const rule of colorRules) {
        if (rule.key === 'name') {
            if (rule.type === 'contains' && name.includes(rule.filter)) return colorMap[rule.color];
            if (rule.type === 'startsWith' && name.startsWith(rule.filter)) return colorMap[rule.color];
        } else if (rule.key === 'number') {
            if (rule.type === 'greaterThan' && num > rule.filter) return colorMap[rule.color];
            if (rule.type === 'lessThan' && num < rule.filter) return colorMap[rule.color];
        }
    }
    return '#64748b';
}

// renderGraph() aus app.js (kopiert & minimal angepasst für viewer)
function renderGraph() {
    if (!currentRouteId || !routesConfig[currentRouteId]) return;
    
    const canvas = document.getElementById('graphCanvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    const stations = routesConfig[currentRouteId].stations;
    if (!stations || stations.length === 0) return;

    const startMin = timeToMinutes(document.getElementById('graph_start').value) ?? 240;
    const endMin = timeToMinutes(document.getElementById('graph_end').value) ?? 720;
    const totalVisibleMinutes = endMin - startMin;

    const paddingTop = 50;
    const paddingBottom = 90;
    const paddingLeft = 50;
    const paddingRight = 50;
    
    const graphWidth = canvas.width - paddingLeft - paddingRight;
    const graphHeight = canvas.height - paddingTop - paddingBottom;

    const minKm = stations[0].km;
    const maxKm = stations[stations.length - 1].km;
    const totalKm = maxKm - minKm;

    function getX(km) {
        return paddingLeft + ((km - minKm) / totalKm) * graphWidth;
    }

    function getY(minutes) {
        if (minutes < startMin || minutes > endMin) return null;
        return paddingTop + ((minutes - startMin) / totalVisibleMinutes) * graphHeight;
    }

    // Raster zeichnen (Zeit und Stationen)
    ctx.lineWidth = 1;
    ctx.fillStyle = '#64748b';
    ctx.font = '10px sans-serif';
    
    const first5Min = Math.ceil(startMin / 5) * 5;

    for (let m = first5Min; m <= endMin; m += 5) {
        const y = getY(m);
        if (y === null) continue;

        if (m % 60 === 0) {
            ctx.strokeStyle = '#e2e8f0';
            ctx.lineWidth = 1;
            ctx.setLineDash([]);
        } else {
            ctx.strokeStyle = '#334155';
            ctx.lineWidth = 0.75;
            ctx.setLineDash([4, 4]);
        }

        ctx.beginPath();
        ctx.moveTo(paddingLeft, y);
        ctx.lineTo(paddingLeft + graphWidth, y);
        ctx.stroke();
        
        let timeString = '';
        if (m % 60 === 0) {
            timeString = `${Math.floor(m / 60)}:00`;
        } else {
            timeString = `:${String(m % 60).padStart(2, '0')}`;
        }

        ctx.textAlign = 'right';
        ctx.fillText(timeString, paddingLeft - 10, y + 4);
        ctx.textAlign = 'left';
        ctx.fillText(timeString, paddingLeft + graphWidth + 10, y + 4);
    }

    ctx.setLineDash([]);

    // Bahnhöfe
    stations.forEach((st) => {
        const x = getX(st.km);
        
        ctx.strokeStyle = '#334155';
        ctx.beginPath();
        ctx.moveTo(x, paddingTop);
        ctx.lineTo(x, paddingTop + graphHeight);
        ctx.stroke();

        ctx.fillStyle = '#f1f5f9';
        ctx.font = 'bold 14px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(st.abbr, x, paddingTop - 12);
        
        ctx.font = '10px sans-serif';
        ctx.fillStyle = '#94a3b8';
        ctx.fillText(st.name, x, paddingTop - 28);
        ctx.fillText(`km ${st.km}`, x, paddingTop - 40);
    });

    // Zuglinien
    activeTrains.forEach((train) => {
        const baseColor = getTrainColor(train.train_number, train.name);
        
        const validStops = train.stops
            .map(stop => {
                const st = stations.find(s => s.id === stop.station_id);
                return st ? { stop, km: st.km } : null;
            })
            .filter(item => item !== null);

        if (validStops.length < 2) return;

        validStops.sort((a, b) => {
            const timeA = timeToMinutes(a.stop.departure || a.stop.arrival);
            const timeB = timeToMinutes(b.stop.departure || b.stop.arrival);
            return timeA - timeB;
        });

        const sollPoints = [];
        validStops.forEach(item => {
            const x = getX(item.km);
            const arrMin = timeToMinutes(item.stop.arrival);
            const depMin = timeToMinutes(item.stop.departure);

            if (arrMin !== null) sollPoints.push({ x, y: getY(arrMin) });
            if (depMin !== null) sollPoints.push({ x, y: getY(depMin) });
        });

        const istPoints = [];
        validStops.forEach(item => {
            const x = getX(item.km);
            const arrMin = timeToMinutes(item.stop.actual_arrival || item.stop.arrival);
            const depMin = timeToMinutes(item.stop.actual_departure || item.stop.departure);

            if (arrMin !== null) istPoints.push({ x, y: getY(arrMin) });
            if (depMin !== null) istPoints.push({ x, y: getY(depMin) });
        });

        function drawPointChain(points) {
            let started = false;
            ctx.beginPath();
            points.forEach(p => {
                if (p.y === null) {
                    started = false; 
                    return;
                }
                if (!started) {
                    ctx.moveTo(p.x, p.y);
                    started = true;
                } else {
                    ctx.lineTo(p.x, p.y);
                }
            });
            ctx.stroke();
        }

        ctx.strokeStyle = baseColor;
        ctx.globalAlpha = 0.5; 
        ctx.lineWidth = 1.5;
        ctx.setLineDash([4, 4]); 
        drawPointChain(sollPoints);

        ctx.globalAlpha = 1.0; 
        ctx.lineWidth = 2.5;
        ctx.setLineDash([]); 
        drawPointChain(istPoints);

        const firstVisibleIst = istPoints.find(p => p.y !== null);
        if (firstVisibleIst) {
            ctx.fillStyle = baseColor;
            ctx.font = 'bold 13px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText(train.train_number, firstVisibleIst.x, firstVisibleIst.y - 8);
        }
    });
    
    ctx.globalAlpha = 1.0;
    ctx.setLineDash([]);

    // JETZT-Linie
    (function drawCurrentTimeLine() {
        const now = new Date();
        const localTotalMinutes = now.getHours() * 60 + now.getMinutes();
        
        const offsetInput = document.getElementById('sts_offset');
        const simOffset = offsetInput ? parseInt(offsetInput.value || 0, 10) : 0; 
        
        let currentTotalMinutes = localTotalMinutes + simOffset;
        currentTotalMinutes = ((currentTotalMinutes % 1440) + 1440) % 1440;

        if (currentTotalMinutes >= startMin && currentTotalMinutes <= endMin) {
            const y = getY(currentTotalMinutes);

            if (y !== null) {
                ctx.save();
                ctx.beginPath();
                ctx.strokeStyle = '#FFDE15'; 
                ctx.lineWidth = 2;
                ctx.setLineDash([6, 4]);
                
                ctx.moveTo(paddingLeft, y);
                ctx.lineTo(paddingLeft + graphWidth, y);
                ctx.stroke();

                ctx.fillStyle = '#FFDE15';
                ctx.font = 'bold 12px sans-serif';
                ctx.textAlign = 'right';
                ctx.textBaseline = 'middle';
                
                const displayHours = Math.floor(currentTotalMinutes / 60);
                const displayMinutes = currentTotalMinutes % 60;
                const timeString = `${String(displayHours).padStart(2, '0')}:${String(displayMinutes).padStart(2, '0')}`;
                
                ctx.fillText(timeString, paddingLeft - 10, y);
                ctx.restore();
            }
        }
    })();
}

function onRouteChanged() {
    const selector = document.getElementById('route_select');
    currentRouteId = selector.value;
    loadAndRenderGraph();
}

async function loadAndRenderGraph() {
    const routeSelect = document.getElementById('route_select');
    if (!routeSelect || !routeSelect.value) return;
    
    currentRouteId = routeSelect.value; 
    
    const formData = new FormData();
    formData.append('action', 'get_all_data');
    formData.append('route_id', currentRouteId);

    try {
        const res = await fetch('timetable.php', { method: 'POST', body: formData });
        activeTrains = await res.json();
        renderGraph();
    } catch (e) {
        console.error("Fehler beim Laden:", e);
    }
}

async function initViewer() {
    const formData = new FormData();
    formData.append('action', 'get_routes');
    
    try {
        const res = await fetch('timetable.php', { method: 'POST', body: formData });
        routesConfig = await res.json();
        
        const selector = document.getElementById('route_select');
        let firstId = null;

        for (const [id, route] of Object.entries(routesConfig)) {
            if (!firstId) firstId = id;
            
            const opt = document.createElement('option');
            opt.value = id;
            opt.innerText = `${route.name}`;
            selector.appendChild(opt);
        }
        
        if (firstId) {
            currentRouteId = firstId;
            selector.value = firstId;
        }
        
        await loadAndRenderGraph();
        
        // Auto-Refresh alle 30 Sekunden
        setInterval(() => {
            loadAndRenderGraph();
        }, 30000);

    } catch (e) {
        console.error("Fehler beim Initialisieren:", e);
    }
}

window.onload = initViewer;
</script>

</body>
</html>