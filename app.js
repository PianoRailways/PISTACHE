let currentRouteId = '';
let canvasTooltip = null; // Global-Variable

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

function switchRoute() {
    const selector = document.getElementById('route_select');
    if (!selector) return;
    
    currentRouteId = selector.value;
    document.getElementById('editor_panel').classList.add('hidden');
    buildEditorTable();
    renderGraph();
}

function buildEditorTable() {
    if (!routesConfig[currentRouteId]) return;
    const stations = routesConfig[currentRouteId].stations;
    const tbody = document.querySelector('#editor_table tbody');
    if (!tbody) return;
    
    tbody.innerHTML = '';

    stations.forEach(st => {
        const tr = document.createElement('tr');
        tr.id = `row_${st.id}`;
        
        tr.innerHTML = `
            <td>${st.name} <strong>(${st.abbr})</strong></td>
            <td><input type="text" name="stations[${st.id}][track]" size="3"></td>
            
            <td>
                <input type="time" name="stations[${st.id}][arrival]" oninput="recalcRow('${st.id}', 'arr')">
            </td>
            <td>
                <div style="display:flex; gap:5px; align-items:center;">
                    <input type="time" name="stations[${st.id}][actual_arrival]" oninput="recalcRow('${st.id}', 'arr', 'time')">
                    <input type="number" id="delay_arr_${st.id}" style="width:40px;" oninput="recalcRow('${st.id}', 'arr', 'delay')">
                    <button type="button" onclick="setNow('${st.id}', 'actual_arrival')">Jetzt</button>
                </div>
            </td>
            
            <td><input type="time" name="stations[${st.id}][departure]" oninput="recalcRow('${st.id}', 'dep')"></td>
            <td>
                <div style="display:flex; gap:5px; align-items:center;">
                    <input type="time" name="stations[${st.id}][actual_departure]" oninput="recalcRow('${st.id}', 'dep', 'time')">
                    <input type="number" id="delay_dep_${st.id}" style="width:40px;" oninput="recalcRow('${st.id}', 'dep', 'delay')">
                    <button type="button" onclick="setNow('${st.id}', 'actual_departure')">Jetzt</button>
                </div>
            </td>
            <td><input type="text" name="stations[${st.id}][flags]" size="5" placeholder="z.B. X(1612)" title="X(Znr)=Kreuzung, V(Znr)=Zugfolge (+2'), C(Znr)=Anschluss (+3'), C4-C7=Anschluss variabel"></td>
            <td><input type="text" name="stations[${st.id}][remarks]" size="10"></td>
            <td><button type="button" style="background-color: #d9534f; color: white; padding: 4px 8px;" onclick="clearRow('${st.id}')">Clr</button></td>
        `;
        tbody.appendChild(tr);
    });
}

function clearRow(stationId) {
    const form = document.getElementById('timetable_form');
    if (!form) return;

    const fields = [
        `stations[${stationId}][track]`,
        `stations[${stationId}][arrival]`,
        `stations[${stationId}][actual_arrival]`,
        `stations[${stationId}][departure]`,
        `stations[${stationId}][actual_departure]`,
        `stations[${stationId}][flags]`,
        `stations[${stationId}][remarks]`
    ];

    fields.forEach(fieldName => {
        const field = form.elements[fieldName];
        if (field) field.value = '';
    });

    const delayArrField = document.getElementById(`delay_arr_${stationId}`);
    const delayDepField = document.getElementById(`delay_dep_${stationId}`);
    if (delayArrField) delayArrField.value = '';
    if (delayDepField) delayDepField.value = '';

    console.log(`Zeile für Station ${stationId} geleert`);
}

async function loadTrain() {
    const trainNumberInput = document.getElementById('train_number').value.trim();
    if (!trainNumberInput) return;
    
    if (!currentRouteId) {
        alert('Bitte wähle eine Strecke aus oder nutze den "Freien Editor"');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'get_or_create_train');
    formData.append('train_number', trainNumberInput);
    formData.append('route_id', currentRouteId);
    
    try {
        const res = await fetch('', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.train) {
            document.getElementById('current_train_id').value = data.train.id;
            document.getElementById('current_train_num').innerText = data.train.train_number;
            
            document.getElementById('sts_zid').value = data.train.sts_zid || '';
            document.getElementById('successor_sts_zid').value = data.train.successor_sts_zid || '';
            
            buildEditorTable();
            
            if (data.timetable) {
                Object.keys(data.timetable).forEach(stId => {
                    const info = data.timetable[stId];
                    
                    const inputTrack = document.querySelector(`input[name="stations[${stId}][track]"]`);
                    if (inputTrack) inputTrack.value = info.track || '';
                    
                    const inputArr = document.querySelector(`input[name="stations[${stId}][arrival]"]`);
                    if (inputArr) inputArr.value = info.arrival || '';
                    
                    const inputDep = document.querySelector(`input[name="stations[${stId}][departure]"]`);
                    if (inputDep) inputDep.value = info.departure || '';
                    
                    const inputActArr = document.querySelector(`input[name="stations[${stId}][actual_arrival]"]`);
                    if (inputActArr) inputActArr.value = info.actual_arrival || '';
                    
                    const inputActDep = document.querySelector(`input[name="stations[${stId}][actual_departure]"]`);
                    if (inputActDep) inputActDep.value = info.actual_departure || '';
                    
                    const inputFlags = document.querySelector(`input[name="stations[${stId}][flags]"]`);
                    if (inputFlags) inputFlags.value = info.flags || '';
                    
                    const inputRemarks = document.querySelector(`input[name="stations[${stId}][remarks]"]`);
                    if (inputRemarks) inputRemarks.value = info.remarks || '';
                    
                    updateDelayFields(stId);
                });
            }
            
            document.getElementById('editor_panel').classList.remove('hidden');
        }
    } catch (err) {
        console.error("Fehler beim Laden des Zuges:", err);
    }
}

function updateDelayFields(stId) {
    const timeToMin = (tStr) => {
        if (!tStr) return null;
        const p = tStr.split(':');
        return p.length >= 2 ? parseInt(p[0]) * 60 + parseInt(p[1]) : null;
    };

    const arrSoll = document.querySelector(`input[name="stations[${stId}][arrival]"]`)?.value;
    const arrIst = document.querySelector(`input[name="stations[${stId}][actual_arrival]"]`)?.value;
    if (arrSoll && arrIst) {
        const diff = timeToMin(arrIst) - timeToMin(arrSoll);
        const dField = document.getElementById(`delay_arr_${stId}`);
        if (dField) dField.value = diff;
    }

    const depSoll = document.querySelector(`input[name="stations[${stId}][departure]"]`)?.value;
    const depIst = document.querySelector(`input[name="stations[${stId}][actual_departure]"]`)?.value;
    if (depSoll && depIst) {
        const diff = timeToMin(depIst) - timeToMin(depSoll);
        const dField = document.getElementById(`delay_dep_${stId}`);
        if (dField) dField.value = diff;
    }
}

async function saveTimetable(e) {
    e.preventDefault();
    const formData = new FormData(document.getElementById('timetable_form'));
    formData.append('action', 'save_timetable');
    formData.append('train_id', document.getElementById('current_train_id').value);

    const res = await fetch('', { method: 'POST', body: formData });
    const data = await res.json();
    
    if (data.success) {
        renderGraph();
        document.getElementById('editor_panel').classList.add('hidden');
    } else {
        alert('Fehler beim Speichern: ' + (data.error || 'Unbekannter Fehler'));
    }
}

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

function recalcRow(stationId, type, trigger) {
    const isFree = !document.getElementById('editor_panel').classList.contains('hidden') ? false : true;
    const form = isFree ? document.getElementById('free_timetable_form') : document.getElementById('timetable_form');
    
    const stations = isFree ? freeEditorStations : (routesConfig[currentRouteId]?.stations || []);
    const currentIndex = stations.findIndex(s => s.id === stationId);
    if (currentIndex === -1) return;

    const prefix = type === 'arr' ? 'arrival' : 'departure';
    const istField = form.querySelector(`[name="stations[${stationId}][actual_${prefix}]"]`);
    const sollField = form.querySelector(`[name="stations[${stationId}][${prefix}]"]`);
    const delayField = document.getElementById(`delay_${type}_${stationId}`);
    
    if (trigger === 'delay') {
        if (istField && sollField && delayField) {
            const sollMin = timeToMinutes(sollField.value);
            if (sollMin !== null) {
                istField.value = minutesToTime(sollMin + parseInt(delayField.value || 0, 10));
            }
        }
    } else if (trigger === 'time') {
        if (istField && sollField && delayField) {
            const sollMin = timeToMinutes(sollField.value);
            const istMin = timeToMinutes(istField.value);
            if (sollMin !== null && istMin !== null) {
                delayField.value = istMin - sollMin;
            }
        }
    }

    if (type === 'arr' && trigger === 'time') {
        const actualArrivalField = form.querySelector(`[name="stations[${stationId}][actual_arrival]"]`);
        const actualDepartureField = form.querySelector(`[name="stations[${stationId}][actual_departure]"]`);
        const sollDepartureField = form.querySelector(`[name="stations[${stationId}][departure]"]`);
        const delayDepartureField = document.getElementById(`delay_dep_${stationId}`);
        
        if (actualArrivalField && actualDepartureField) {
            const arrivalTime = timeToMinutes(actualArrivalField.value);
            const departureTime = timeToMinutes(actualDepartureField.value);
            
            if (arrivalTime !== null && departureTime !== null && departureTime < arrivalTime) {
                actualDepartureField.value = actualArrivalField.value;
                if (sollDepartureField && delayDepartureField) {
                    delayDepartureField.value = timeToMinutes(actualDepartureField.value) - timeToMinutes(sollDepartureField.value);
                }
            }
        }
    }

    if (!isFree && typeof propagateForward === 'function') {
        propagateForward(currentIndex + 1);
    } else if (isFree && typeof propagateTravelTimeWithReserve === 'function') {
        if (type === 'arr') {
            // Eigene Zeile: gerade eingetragener Wert bleibt, Standzeit→Abfahrt wird mitberechnet
            propagateTravelTimeWithReserve(currentIndex, true);
        } else {
            // Abfahrt wurde editiert: eigene Zeile bleibt unangetastet, ab der nächsten neu rechnen
            propagateTravelTimeWithReserve(currentIndex + 1, false);
        }
    }
}

async function renderGraph() {
    if (!currentRouteId || !routesConfig[currentRouteId]) return;
    
    const formData = new FormData();
    formData.append('action', 'get_all_data');
    formData.append('route_id', currentRouteId);

    const res = await fetch('', { method: 'POST', body: formData });
    let trains = await res.json();

    updateTrainList(trains);

    const canvas = document.getElementById('graphCanvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    let stations = routesConfig[currentRouteId].stations;
    if (!stations || stations.length === 0) return;

    // === SEGMENT-FILTERUNG (für Zuschauermodus) ===
    if (typeof currentSegmentFrom !== 'undefined' && typeof currentSegmentTo !== 'undefined') {
        let fromIndex = 0;
        let toIndex = stations.length - 1;
        
        if (currentSegmentFrom) {
            const idx = stations.findIndex(s => s.id === currentSegmentFrom);
            if (idx !== -1) fromIndex = idx;
        }
        
        if (currentSegmentTo) {
            const idx = stations.findIndex(s => s.id === currentSegmentTo);
            if (idx !== -1) toIndex = idx;
        }
        
        // Gefilterte Stations-Liste
        stations = stations.slice(fromIndex, toIndex + 1);
        
        // Auch Züge filtern
        trains.forEach(train => {
            const segmentStationIds = new Set(stations.map(s => s.id));
            train.stops = train.stops.filter(stop => segmentStationIds.has(stop.station_id));
        });
    }

    const startMin = timeToMinutes(document.getElementById('graph_start').value) ?? 240;
    const endMin = timeToMinutes(document.getElementById('graph_end').value) ?? 720;
    const totalVisibleMinutes = endMin - startMin;

    const paddingTop = 50;
    const paddingBottom = 40;
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

    // Raster: Stunden- und 5-Minuten-Linien
    ctx.lineWidth = 1;
    ctx.fillStyle = '#64748b';
    ctx.font = '10px sans-serif';
    
    // Runden auf das nächste 5-Minuten-Intervall im sichtbaren Bereich
    const first5Min = Math.ceil(startMin / 5) * 5;

    for (let m = first5Min; m <= endMin; m += 5) {
        const y = getY(m);
        if (y === null) continue;

        // Unterscheidung zwischen voller Stunde (markant, durchgezogen) und 5-Minuten-Takt (fein, gestrichelt)
        if (m % 60 === 0) {
            ctx.strokeStyle = '#e2e8f0'; // Helles Grau für Stunden
            ctx.lineWidth = 1;
            ctx.setLineDash([]); // Durchgezogene Linie für volle Stunden
        } else {
            // Sichtbares, aber dezentes Grau für Zwischenlinien im Darkmode angepasst
            ctx.strokeStyle = (window.getComputedStyle(document.body).backgroundColor === 'rgb(15, 23, 42)') ? '#334155' : '#cbd5e1';
            ctx.lineWidth = 0.75;
            ctx.setLineDash([4, 4]); // Gestrichelte Linie für die 5-Minuten-Schritte
        }

        // Horizontale Linie auf der exakten Höhe zeichnen
        ctx.beginPath();
        ctx.moveTo(paddingLeft, y);
        ctx.lineTo(paddingLeft + graphWidth, y);
        ctx.stroke();
        
        // Beschriftung formatieren
        let timeString = '';
        if (m % 60 === 0) {
            timeString = `${Math.floor(m / 60)}:00`; // Volle Stunde, z.B. "12:00"
        } else {
            timeString = `:${String(m % 60).padStart(2, '0')}`; // Nur Minuten, z.B. ":05"
        }

        // Text links und rechts auf der exakten Höhe platzieren
        ctx.textAlign = 'right';
        ctx.fillText(timeString, paddingLeft - 10, y + 4);
        ctx.textAlign = 'left';
        ctx.fillText(timeString, paddingLeft + graphWidth + 10, y + 4);
    }

    // Linienstil für die nachfolgenden Zeichnungen (Bahnhöfe, Züge, Zeitlinie) zurücksetzen
    ctx.setLineDash([]);

    // Raster: Bahnhofslinien
    stations.forEach((st, index) => {
        const x = getX(st.km);
        const isDarkMode = (window.getComputedStyle(document.body).backgroundColor === 'rgb(15, 23, 42)');
        
        ctx.strokeStyle = isDarkMode ? '#334155' : '#cbd5e1';
        ctx.beginPath();
        ctx.moveTo(x, paddingTop);
        ctx.lineTo(x, paddingTop + graphHeight);
        ctx.stroke();

        // Abbr. direkt über dem Canvas
        ctx.fillStyle = isDarkMode ? '#f1f5f9' : '#1e293b';
        ctx.font = 'bold 8px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(st.abbr, x, paddingTop - 5);
        
        // Name + km: alle OBEN, aber unterschiedlich hoch versetzt
        ctx.fillStyle = isDarkMode ? '#94a3b8' : '#64748b';
        ctx.font = '8px sans-serif';
        
        const isOddStation = index % 2 === 0; // 0,2,4... oben hoch / 1,3,5... oben tiefer
        
        let textY;
        if (isOddStation) {
            // Ungerade: weiter oben
            textY = paddingTop - 40;
        } else {
            // Gerade: etwas tiefer (aber immer noch oben)
            textY = paddingTop - 25;
        }
        
        // Kleine Linie vom Text zur Abbr
        ctx.strokeStyle = isDarkMode ? '#475569' : '#cbd5e1';
        ctx.lineWidth = 0.5;
        ctx.beginPath();
        ctx.moveTo(x, textY + 10);
        ctx.lineTo(x, paddingTop - 10);
        ctx.stroke();
        
        // Name
        ctx.fillText(st.name, x, textY);
        // km
        ctx.fillText(`km ${st.km}`, x, textY + 8);
    });

    // Sammle alle Zuglinien-Segmente für Hover-Tooltip
    const trainSegments = [];

    trains.forEach((train) => {
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
                ctx.font = 'bold 11px sans-serif';
                ctx.textAlign = 'left'; // Umstellung auf 'left' erleichtert die präzise Positionierung der Segmente
                
                // 1. Texte ermitteln
                const trainNum = train.train_number;
                let delayText = '';
                const firstStop = validStops[0];
                if (firstStop) {
                    const sollDep = timeToMinutes(firstStop.stop.departure || firstStop.stop.arrival);
                    const istDep = timeToMinutes(firstStop.stop.actual_departure || firstStop.stop.actual_arrival);
                    if (sollDep !== null && istDep !== null) {
                        const delay = istDep - sollDep;
                        if (delay !== 0) {
                            delayText = `${delay > 0 ? '+' : ''}${delay}`;
                        }
                    }
                }

                // 2. Breiten messen für die Zentrierung des Gesamtkonstrukts
                const trainNumWidth = ctx.measureText(trainNum).width;
                const spacing = 4; // Abstand zwischen Zugnummer und Verspätungs-Badge
                const paddingX = 4; // Innerer Abstand des Badges (links/rechts)
                const paddingY = 2; // Innerer Abstand des Badges (oben/unten)
                
                let delayWidth = 0;
                if (delayText) {
                    delayWidth = ctx.measureText(delayText).width;
                }

                // Gesamtbreite berechnen, um den Startpunkt (X) für eine zentrierte Ausrichtung zu finden
                const totalWidth = trainNumWidth + (delayText ? spacing + delayWidth + (paddingX * 2) : 0);
                const startX = firstVisibleIst.x - (totalWidth / 2);
                const textY = firstVisibleIst.y - 8;

                // 3. Zugnummer zeichnen
                ctx.fillStyle = baseColor;
                ctx.fillText(trainNum, startX, textY);

                // 4. Verspätungs-Badge zeichnen (falls Verspätung vorhanden)
                if (delayText) {
                    const badgeX = startX + trainNumWidth + spacing;
                    const badgeWidth = delayWidth + (paddingX * 2);
                    const badgeHeight = 14; // Passend zur 11px Schriftgröße
                    const badgeY = textY - 10; // Vertikale Ausrichtung des Badges

                    // Grauer Hintergrund
                    ctx.fillStyle = '#e0e0e0'; // Angenehmes Hellgrau, optional anpassbar
                    
                    // Zeichnet ein abgerundetes Rechteck für eine sauberere Optik
                    ctx.beginPath();
                    if (typeof ctx.roundRect === 'function') {
                        ctx.roundRect(badgeX, badgeY, badgeWidth, badgeHeight, 3);
                    } else {
                        ctx.rect(badgeX, badgeY, badgeWidth, badgeHeight);
                    }
                    ctx.fill();

                    // Verspätungstext über den grauen Hintergrund zeichnen
                    ctx.fillStyle = baseColor; // Schriftfarbe bleibt identisch
                    ctx.fillText(delayText, badgeX + paddingX, textY);
                }
        }

        // Cache für Tooltip Hit-Testing
        trainSegments.push({
            train: train,
            points: istPoints  // Nutze IST-Linie für Hover (sichtbare Linie)
        });
    });

    // Tooltip aktivieren
    if (!canvasTooltip && canvas) {
        canvasTooltip = new CanvasTooltip(canvas);
    }
    if (canvasTooltip) {
        canvasTooltip.cacheTrainSegments(trainSegments);
    }
    
    ctx.globalAlpha = 1.0;
    ctx.setLineDash([]);

// Gelbe/Rote "JETZT"-Zeitlinie innerhalb von renderGraph
(function drawCurrentTimeLine() {
    const basis = document.getElementById('time_basis')?.value || 'instanz1';
    let stsMinutes = 0;

    if (basis === 'manual') {
        const now = new Date();
        const pcMinutes = now.getHours() * 60 + now.getMinutes();
        const offsetInput = document.getElementById('sts_offset');
        const offset = offsetInput ? parseInt(offsetInput.value || 0, 10) : 0;
        stsMinutes = ((pcMinutes + offset) % 1440 + 1440) % 1440;
    } else {
        stsMinutes = getDynamicSTSTime(basis);
    }

    const y = paddingTop + ((stsMinutes - startMin) / totalVisibleMinutes) * graphHeight;

    if (y >= paddingTop && y <= paddingTop + graphHeight) {
        ctx.save();
        ctx.beginPath();
        ctx.strokeStyle = '#FFDE15'; 
        ctx.lineWidth = 2;
        ctx.setLineDash([6, 4]);
        
        ctx.moveTo(paddingLeft, y);
        ctx.lineTo(paddingLeft + graphWidth, y);
        ctx.stroke();

        ctx.fillStyle = '#FFDE15';
        ctx.font = 'bold 11px sans-serif';
        ctx.textAlign = 'right';
        ctx.textBaseline = 'middle';
        
        const displayHours = Math.floor(stsMinutes / 60);
        const displayMinutes = stsMinutes % 60;
        const timeString = `${String(displayHours).padStart(2, '0')}:${String(displayMinutes).padStart(2, '0')}`;
        
        ctx.fillText(timeString, paddingLeft - 10, y);
        ctx.restore();
    }
})();
}
function getDynamicSTSTime(instanz) {
    // Fixer Referenzpunkt aus deinem Live-Abgleich
    const refReal = new Date('2026-06-27T21:41:00');
    const now = new Date();
    
    // Vergangene Echtzeit-Minuten seit der Referenz
    const elapsedMinutes = Math.floor((now - refReal) / 60000);
    
    // STS-Instanzen laufen von 05:00 bis 21:00 Uhr = 16 Stunden (960 Minuten)
    const instanzDuration = 960; 
    const instanzStartMinutes = 300; // 05:00 Uhr in Tagesminuten
    
    // Deine gemessenen STS-Minuten seit Instanzstart (05:00) am Referenzpunkt:
    // Instanz 1 war um 16:41 Uhr (= 701 Min seit 05:00)
    // Instanz 2 war um 06:41 Uhr (= 101 Min seit 05:00)
    const refMinutesSinceStart = (instanz === 'instanz1') ? 701 : 101;
    
    // Aktuelle Minuten innerhalb des 16-Stunden-Rhythmus berechnen
    let currentMinutesSinceStart = (refMinutesSinceStart + elapsedMinutes) % instanzDuration;
    if (currentMinutesSinceStart < 0) {
        currentMinutesSinceStart += instanzDuration;
    }
    
    // Rückgabe als absolute Tagesminuten (z.B. 1001 für 16:41 Uhr)
    return instanzStartMinutes + currentMinutesSinceStart;
}

function updateTrainList(trains) {
    const listContainer = document.getElementById('active_train_list');
    if (!listContainer) return;
    listContainer.innerHTML = '';

    if (trains.length === 0) {
        listContainer.innerHTML = '<li style="font-weight:normal; color:#94a3b8; cursor:default; background:none; border:none;">Keine Züge erfasst</li>';
        return;
    }

    trains.forEach(train => {
        const li = document.createElement('li');
        li.setAttribute('onclick', `loadTrainByNumber(${train.train_number})`);
        li.innerHTML = `
            <span>Zug ${train.train_number}</span>
            <span class="delete-btn" onclick="deleteTrain(event, ${train.id})">×</span>
        `;
        listContainer.appendChild(li);
    });
}

function loadTrainByNumber(num) {
    const input = document.getElementById('train_number');
    if (input) {
        input.value = num;
        loadTrain();
    }
}

async function deleteTrain(event, trainId) {
    event.stopPropagation(); 
    if (!confirm('Zug und zugehörigen Fahrplan wirklich löschen?')) return;

    const formData = new FormData();
    formData.append('action', 'delete_train');
    formData.append('train_id', trainId);

    const res = await fetch('', { method: 'POST', body: formData });
    const data = await res.json();

    if (data.success) {
        if (document.getElementById('current_train_id').value == trainId) {
            document.getElementById('editor_panel').classList.add('hidden');
        }
        renderGraph(); 
    } else {
        alert('Fehler beim Löschen');
    }
}

function deleteCurrentTrain() {
    const trainId = document.getElementById('current_train_id').value;
    const trainNum = document.getElementById('current_train_num').innerText;

    if (!trainId) {
        alert('Kein Zug zum Löschen ausgewählt.');
        return;
    }

    if (confirm(`Möchtest du den Zug ${trainNum} wirklich unwiderruflich löschen?`)) {
        const formData = new FormData();
        formData.append('action', 'delete_train');
        formData.append('train_id', trainId);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(`Zug ${trainNum} wurde gelöscht.`);
                window.location.reload();
            } else {
                alert('Fehler beim Löschen: ' + (data.error || 'Unbekannter Fehler'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Verbindungsfehler beim Löschen des Zuges.');
        });
    }
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

function getSimTime() {
    const now = new Date();
    const offsetInput = document.getElementById('sts_offset');
    const simOffset = offsetInput ? parseInt(offsetInput.value || 0, 10) : 0;

    const totalMinutes = (now.getHours() * 60) + now.getMinutes() + simOffset;
    const simMinutes = ((totalMinutes % 1440) + 1440) % 1440;
    
    const h = Math.floor(simMinutes / 60);
    const m = simMinutes % 60;
    
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

function setNow(stationId, fieldName) {
    const form = document.getElementById('timetable_form');
    const timeString = getSimTime(); 
    
    const field = form.querySelector(`[name="stations[${stationId}][${fieldName}]"]`);
    if (field) {
        field.value = timeString;
        const type = fieldName.includes('arrival') ? 'arr' : 'dep';
        recalcRow(stationId, type, 'time');
    }
    
    saveTimetableAuto();
}

async function saveTimetableAuto() {
    const formData = new FormData(document.getElementById('timetable_form'));
    formData.append('action', 'save_timetable');
    formData.append('train_id', document.getElementById('current_train_id').value);

    try {
        const res = await fetch('', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            renderGraph();
            console.log("Auto-Save erfolgreich");
        }
    } catch (e) {
        console.error("Auto-Save Fehler:", e);
    }
}

/**
 * propagateForward() - Normal Editor mit Standzeitabbau
 * 
 * Propagiert Verspätung nach vorne mit:
 * - 7% Fahrtzeit-Reserve
 * - Standzeitabbau (außer bei R-Flag)
 * - Schutz vor Dispo-Kriterien
 */
function propagateForward(startIndex) {
    const form = document.getElementById('timetable_form');
    const stations = routesConfig[currentRouteId].stations;
    
    if (!form || !stations || startIndex < 0 || startIndex >= stations.length) {
        return;
    }

    // Starte von der vorherigen Station
    let prevDepMin = null;
    let prevSollDepMin = null;

    // Suche die letzte gültige Abfahrt vor startIndex
    for (let i = startIndex - 1; i >= 0; i--) {
        const sollDep = form.querySelector(`[name="stations[${stations[i].id}][departure]"]`)?.value;
        const istDep = form.querySelector(`[name="stations[${stations[i].id}][actual_departure]"]`)?.value;
        
        if (sollDep) prevSollDepMin = timeToMinutes(sollDep);
        if (istDep) prevDepMin = timeToMinutes(istDep);
        
        if (prevDepMin !== null) break;
    }

    // Propagiere ab startIndex
    for (let i = startIndex; i < stations.length; i++) {
        const st = stations[i];
        const stId = st.id;
        
        const sollArrStr = form.querySelector(`[name="stations[${stId}][arrival]"]`)?.value;
        const sollDepStr = form.querySelector(`[name="stations[${stId}][departure]"]`)?.value;
        const flags = form.querySelector(`[name="stations[${stId}][flags]"]`)?.value || '';

        if (!sollArrStr && !sollDepStr) continue;

        // 🔒 SCHUTZ: Dispo-Kriterium prüfen
        if (/^(X|V|C[4-7]?)\((\d+)\)/i.test(flags.trim())) {
            console.log(`🔒 GESCHÜTZT (Dispo): ${stId} → wird übersprungen`);
            continue;
        }

        const sollArrMin = timeToMinutes(sollArrStr);
        const sollDepMin = timeToMinutes(sollDepStr);
        const istArrField = form.querySelector(`[name="stations[${stId}][actual_arrival]"]`);
        const istDepField = form.querySelector(`[name="stations[${stId}][actual_departure]"]`);
        const delayArrField = document.getElementById(`delay_arr_${stId}`);
        const delayDepField = document.getElementById(`delay_dep_${stId}`);

        // ========== ANKUNFT BERECHNEN ==========
        let istArrMin = null;

        if (prevDepMin !== null && prevSollDepMin !== null) {
            // Soll-Fahrzeit
            let sollFahrtzeit = 0;
            if (sollArrMin !== null) {
                sollFahrtzeit = sollArrMin - prevSollDepMin;
            } else if (sollDepMin !== null) {
                sollFahrtzeit = sollDepMin - prevSollDepMin;
            }

            if (sollFahrtzeit > 0) {
                // 7% Reserve
                const minFahrtzeit = Math.round(sollFahrtzeit * 0.93);
                // Ist-Fahrzeit (aus Soll-Fahrzeit + Differenz der Abfahrten)
                const istFahrtzeit = Math.max(minFahrtzeit, sollFahrtzeit);
                
                istArrMin = prevDepMin + istFahrtzeit;
                if (istArrField) istArrField.value = minutesToTime(istArrMin);
            }
        }

        // Falls Ankunft immer noch nicht berechnet wurde
        if (istArrMin === null && prevDepMin !== null) {
            istArrMin = prevDepMin;
            if (istArrField) istArrField.value = minutesToTime(istArrMin);
        }

        // Update Ankunfts-Delay
        if (istArrMin !== null && sollArrMin !== null && delayArrField) {
            delayArrField.value = istArrMin - sollArrMin;
        }

        // ========== STANDZEIT-VERWALTUNG & ABFAHRT ==========
        if (istArrMin !== null && sollDepMin !== null) {
            // Soll-Standzeit
            let sollStandzeit = 0;
            if (sollArrMin !== null) {
                sollStandzeit = Math.max(0, sollDepMin - sollArrMin);
            }

            // R-Flag prüfen
            const hasRFlag = /R/i.test(flags);
            const minStandzeit = hasRFlag ? 2 : 0;

            // Verspätung bei Ankunft
            const arrivalDelay = (sollArrMin !== null) ? (istArrMin - sollArrMin) : 0;

            // Verfügbare Abbremsung
            const availableBraking = Math.max(0, sollStandzeit - minStandzeit);
            const actualBraking = Math.min(Math.max(0, arrivalDelay), availableBraking);

            // Ist-Abfahrt = Soll-Abfahrt + (Ankunftsversp. - Abbremsung)
            const remainingDelay = Math.max(0, arrivalDelay - actualBraking);
            const istDepMin = sollDepMin + remainingDelay;

            if (istDepField) istDepField.value = minutesToTime(istDepMin);
            if (delayDepField) delayDepField.value = istDepMin - sollDepMin;

            prevDepMin = istDepMin;
            prevSollDepMin = sollDepMin;

            // Debug-Ausgabe
            if (arrivalDelay > 0 || actualBraking > 0) {
                console.log(`📍 ${stId}: Ank.Versp=${arrivalDelay}min, Abbr=${actualBraking}min, Restversp=${remainingDelay}min`);
            }
        } else if (istArrMin !== null) {
            prevDepMin = istArrMin;
        }
    }
}

async function saveTrainLink() {
    const trainId = document.getElementById('current_train_id').value;
    const stsZid = document.getElementById('sts_zid').value.trim();
    const successorStsZid = document.getElementById('successor_sts_zid').value.trim();

    if (!trainId) {
        alert('Kein Zug geladen');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'save_train_link');
    formData.append('train_id', trainId);
    formData.append('sts_zid', stsZid);
    formData.append('successor_sts_zid', successorStsZid);

    try {
        const res = await fetch('', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            alert('Zugverkettung gespeichert!');
        } else {
            alert('Fehler beim Speichern: ' + (data.error || 'Unbekannter Fehler'));
        }
    } catch (err) {
        console.error('Fehler beim Speichern der Zugverkettung:', err);
        alert('Verbindungsfehler beim Speichern der Zugverkettung');
    }
}

// ========================================================================
// PROPAGATION MIT 7%-RESERVE & STANDZEIT-BERÜCKSICHTIGUNG
// ========================================================================

/**
 * propagateTravelTimeWithReserve() - Free Editor Version (Rewrite)
 * 
 * Rechnet die Fahrt ab startIndex GARANTIERT komplett neu durch,
 * unabhängig davon, was vorher in den Feldern der Folgestationen stand.
 * Der Zustand (Ist-Abfahrt/Soll-Abfahrt der Vorstation) wird explizit
 * durch die Schleife mitgeführt statt bei jeder Zeile neu aus dem DOM geraten.
 * 
 * @param {number} startIndex - ab welcher Station neu gerechnet wird
 * @param {boolean} preserveFirstArrival - true = die Ist-Ankunft an startIndex
 *   wurde gerade vom Nutzer selbst eingetragen und wird übernommen statt überschrieben
 */
function propagateTravelTimeWithReserve(startIndex = 0, preserveFirstArrival = false) {
    const form = document.getElementById('free_timetable_form');
    if (!form) return;

    const getVal = (stId, field) => form.querySelector(`[name="stations[${stId}][${field}]"]`)?.value || '';
    const setVal = (stId, field, val) => {
        const f = form.querySelector(`[name="stations[${stId}][${field}]"]`);
        if (f) f.value = val;
    };
    const setDelay = (stId, type, val) => {
        const f = document.getElementById(`delay_${type}_${stId}`);
        if (f) f.value = val;
    };

    // Ausgangsbasis: Ist-/Soll-Abfahrt der Station VOR startIndex
    let prevIstDepMin = null;
    let prevSollDepMin = null;
    if (startIndex > 0) {
        const prevSt = freeEditorStations[startIndex - 1];
        prevIstDepMin = timeToMinutes(getVal(prevSt.id, 'actual_departure'));
        prevSollDepMin = timeToMinutes(getVal(prevSt.id, 'departure'));
    }

    for (let i = startIndex; i < freeEditorStations.length; i++) {
        const st = freeEditorStations[i];
        const stId = st.id;

        const sollArrStr = getVal(stId, 'arrival');
        const sollDepStr = getVal(stId, 'departure');
        const flags = getVal(stId, 'flags');
        const sollArrMin = timeToMinutes(sollArrStr);
        const sollDepMin = timeToMinutes(sollDepStr);

        if (!sollArrStr && !sollDepStr) continue;

        // 🔒 Dispo-Kriterium: Zeile nicht anfassen, aber Kette ab ihrer (manuell/dispo
        // gesetzten) Ist-Abfahrt fortsetzen, damit die NÄCHSTE Station korrekt rechnet
        if (/^(X|V|C[4-7]?)\((\d+)\)/i.test(flags.trim())) {
            console.log(`🔒 GESCHÜTZT: Halt ${stId} hat Dispo-Kriterium (${flags}) → wird übersprungen`);
            const protIstDep = timeToMinutes(getVal(stId, 'actual_departure'));
            prevIstDepMin = protIstDep !== null ? protIstDep : sollDepMin;
            prevSollDepMin = sollDepMin;
            continue;
        }

        // ========== ANKUNFT ==========
        let istArrMin;
        if (i === startIndex && preserveFirstArrival) {
            // Das ist die Zeile, die der Nutzer gerade selbst editiert hat → Wert übernehmen
            istArrMin = timeToMinutes(getVal(stId, 'actual_arrival'));
            if (istArrMin === null) istArrMin = sollArrMin;
        } else if (prevIstDepMin !== null && prevSollDepMin !== null && sollArrMin !== null) {
            const sollFahrtzeit = sollArrMin - prevSollDepMin;
            if (sollFahrtzeit > 0) {
                // 7% Reserve: nie schneller als 93% der Soll-Fahrtzeit
                const minFahrtzeit = Math.round(sollFahrtzeit * 0.93);
                const istFahrtzeit = Math.max(minFahrtzeit, sollFahrtzeit);
                istArrMin = prevIstDepMin + istFahrtzeit;
            } else {
                istArrMin = prevIstDepMin;
            }
            setVal(stId, 'actual_arrival', minutesToTime(istArrMin));
        } else if (prevIstDepMin !== null) {
            istArrMin = prevIstDepMin;
            setVal(stId, 'actual_arrival', minutesToTime(istArrMin));
        } else {
            // Keine Vorgänger-Info vorhanden → bestehenden Wert beibehalten, sonst Soll
            istArrMin = timeToMinutes(getVal(stId, 'actual_arrival'));
            if (istArrMin === null) istArrMin = sollArrMin;
        }

        if (istArrMin === null) continue;

        // ========== STANDZEIT-VERWALTUNG ==========
        // Soll-Standzeit
        let sollStandzeit = 0;
        if (sollArrMin !== null && sollDepMin !== null && sollDepMin >= sollArrMin) {
            sollStandzeit = sollDepMin - sollArrMin;
        }

        // R-Flag prüfen (reservierte Standzeit)
        const hasRFlag = /R/i.test(flags);
        const minStandzeit = hasRFlag ? 2 : 0;

        // Verspätung bei Ankunft
        const arrivalDelay = (sollArrMin !== null) ? (istArrMin - sollArrMin) : 0;

        // Verfügbare Abbremsung = Soll-Standzeit - minimale Standzeit
        const availableBraking = Math.max(0, sollStandzeit - minStandzeit);
        const actualBraking = Math.min(Math.max(0, arrivalDelay), availableBraking);

        let istDepMin;
        if (sollDepMin !== null) {
            const remainingDelay = Math.max(0, arrivalDelay - actualBraking);
            istDepMin = sollDepMin + remainingDelay;
        } else {
            istDepMin = istArrMin + minStandzeit;
        }

        setVal(stId, 'actual_departure', minutesToTime(istDepMin));
        if (sollDepMin !== null) setDelay(stId, 'dep', istDepMin - sollDepMin);

        // Zustand für nächste Iteration mitgeben
        prevIstDepMin = istDepMin;
        prevSollDepMin = sollDepMin !== null ? sollDepMin : sollArrMin;

        if (arrivalDelay > 0 || actualBraking > 0) {
            console.log(`📍 ${stId}: Ank.Versp=${arrivalDelay}min, Abbr=${actualBraking}min, Restversp=${Math.max(0, arrivalDelay - actualBraking)}min`);
        }
    }
}

// ========================================================================
// FREIER EDITOR FUNKTIONEN
// ========================================================================
let freeEditorStations = [];

function initializeAllStationsList() {
    const datalist = document.getElementById('all_stations_list');
    const allAbbrs = new Set();
    
    for (const routeId in routesConfig) {
        const route = routesConfig[routeId];
        if (route.stations) {
            route.stations.forEach(st => {
                allAbbrs.add(st.abbr);
            });
        }
    }
    
    datalist.innerHTML = '';
    allAbbrs.forEach(abbr => {
        const option = document.createElement('option');
        option.value = abbr;
        datalist.appendChild(option);
    });
}

function activateFreeEditor() {
    freeEditorStations = [];
    document.getElementById('editor_panel').classList.add('hidden');
    document.getElementById('free_editor_panel').classList.remove('hidden');
    document.getElementById('free_train_number').value = '';
    document.getElementById('free_editor_train_id').value = '';
    document.getElementById('free_timetable_form').querySelector('tbody').innerHTML = '';
    document.getElementById('station_input').value = '';
    initializeAllStationsList();
}

function closeFreeEditor() {
    document.getElementById('free_editor_panel').classList.add('hidden');
    document.getElementById('route_select').value = '';
    document.getElementById('train_number').value = '';
    currentRouteId = '';
}

async function loadFreeEditorTrain() {
    const trainNum = document.getElementById('free_train_number').value.trim();
    if (!trainNum) {
        alert('Bitte gib eine Zugnummer ein');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'get_or_create_train');
    formData.append('train_number', trainNum);
    formData.append('route_id', 'free');
    
    try {
        const res = await fetch('', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.error) {
            alert('Fehler: ' + data.error);
            return;
        }
        
        if (data.train) {
            document.getElementById('free_editor_train_id').value = data.train.id;
            
            freeEditorStations = [];
            const tbody = document.getElementById('free_timetable_form').querySelector('tbody');
            tbody.innerHTML = '';
            
            if (data.timetable) {
                Object.keys(data.timetable).forEach(stId => {
                    const info = data.timetable[stId];
                    let stationName = stId;
                    let stationAbbr = stId;
                    
                    for (const routeId in routesConfig) {
                        const route = routesConfig[routeId];
                        if (route.stations) {
                            const found = route.stations.find(s => s.id === stId);
                            if (found) {
                                stationName = found.name;
                                stationAbbr = found.abbr;
                                break;
                            }
                        }
                    }
                    
                    freeEditorStations.push({
                        id: stId,
                        name: stationName,
                        abbr: stationAbbr,
                        track: info.track || '',
                        arrival: info.arrival || '',
                        departure: info.departure || '',
                        actual_arrival: info.actual_arrival || '',
                        actual_departure: info.actual_departure || '',
                        flags: info.flags || '',
                        remarks: info.remarks || ''
                    });
                });
                
                renderFreeEditorTable();
            }
        }
    } catch (err) {
        console.error('Fehler beim Laden:', err);
        alert('Verbindungsfehler beim Laden des Zuges');
    }
}

function addStationToFreeEditor() {
    const input = document.getElementById('station_input').value.trim().toUpperCase();
    if (!input) return;
    
    let stationFound = null;
    for (const routeId in routesConfig) {
        const route = routesConfig[routeId];
        if (route.stations) {
            const found = route.stations.find(s => s.abbr.toUpperCase() === input);
            if (found) {
                stationFound = found;
                break;
            }
        }
    }
    
    if (!stationFound) {
        alert(`Station mit Kürzel "${input}" nicht gefunden`);
        return;
    }
    
    if (freeEditorStations.find(st => st.id === stationFound.id)) {
        alert('Diese Station ist bereits im Fahrplan');
        return;
    }
    
    freeEditorStations.push({
        id: stationFound.id,
        name: stationFound.name,
        abbr: stationFound.abbr,
        track: '',
        arrival: '',
        departure: '',
        actual_arrival: '',
        actual_departure: '',
        flags: '',
        remarks: ''
    });
    
    renderFreeEditorTable();
    document.getElementById('station_input').value = '';
}

function renderFreeEditorTable() {
    const tbody = document.getElementById('free_editor_table').querySelector('tbody');
    tbody.innerHTML = '';

    freeEditorStations.forEach((st, index) => {
        const tr = document.createElement('tr');
        tr.id = `free_row_${st.id}`;
        
        tr.draggable = true;
        tr.style.cursor = 'move';
        
        tr.innerHTML = `
            <td style="padding: 5px; width: 200px;"><strong class="drag-handle">☰</strong> ${st.name} (${st.abbr})</td>
            <td style="width: 50px;"><input type="text" name="stations[${st.id}][track]" value="${st.track}" style="width: 40px;"></td>
            
            <td><input type="time" name="stations[${st.id}][arrival]" value="${st.arrival}" onchange="recalcRow('${st.id}', 'arr', 'time')"></td>
            <td><input type="time" name="stations[${st.id}][actual_arrival]" value="${st.actual_arrival}" onchange="recalcRow('${st.id}', 'arr', 'time')"></td>
            <td><input type="number" id="delay_arr_${st.id}" placeholder="0" style="width: 50px;" oninput="recalcRow('${st.id}', 'arr', 'delay')"></td>
            
            <td><input type="time" name="stations[${st.id}][departure]" value="${st.departure}" onchange="recalcRow('${st.id}', 'dep', 'time')"></td>
            <td><input type="time" name="stations[${st.id}][actual_departure]" value="${st.actual_departure}" onchange="recalcRow('${st.id}', 'dep', 'time')"></td>
            <td><input type="number" id="delay_dep_${st.id}" placeholder="0" style="width: 50px;" oninput="recalcRow('${st.id}', 'dep', 'delay')"></td>
            
            <td><input type="text" name="stations[${st.id}][flags]" value="${st.flags}" style="width: 80px;" placeholder="X(Znr)"></td>
            <td><input type="text" name="stations[${st.id}][remarks]" value="${st.remarks}" style="width: 100px;"></td>
            <td><button type="button" style="background-color: #d9534f; color: white; border:none; padding: 4px 8px; cursor:pointer;" 
                        onclick="removeStationFromFreeEditor('${st.id}')">Entf</button></td>
        `;

        tr.addEventListener('dragstart', (e) => {
            e.dataTransfer.setData('text/plain', index);
            tr.classList.add('dragging');
        });

        tr.addEventListener('dragend', () => {
            tr.classList.remove('dragging');
            reorderFreeEditorStationsArray();
        });

        tbody.appendChild(tr);
    });

    // Delay-Felder initial befüllen
    freeEditorStations.forEach(st => {
        updateDelayFields(st.id);
    });

    tbody.addEventListener('dragover', (e) => {
        e.preventDefault();
        const draggingRow = tbody.querySelector('.dragging');
        const afterElement = getDragAfterElement(tbody, e.clientY);
        if (afterElement == null) {
            tbody.appendChild(draggingRow);
        } else {
            tbody.insertBefore(draggingRow, afterElement);
        }
    });
}

function getDragAfterElement(tbody, y) {
    const draggableElements = [...tbody.querySelectorAll('tr:not(.dragging)')];

    return draggableElements.reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
            return { offset: offset, element: child };
        } else {
            return closest;
        }
    }, { offset: Number.NEGATIVE_INFINITY }).element;
}

function reorderFreeEditorStationsArray() {
    const tbody = document.getElementById('free_editor_table').querySelector('tbody');
    const newOrderedIds = [...tbody.querySelectorAll('tr')].map(tr => tr.id.replace('free_row_', ''));
    
    const reordered = [];
    newOrderedIds.forEach(id => {
        const found = freeEditorStations.find(st => st.id === id);
        if (found) reordered.push(found);
    });
    freeEditorStations = reordered;
}

function removeStationFromFreeEditor(stationId) {
    const row = document.getElementById(`free_row_${stationId}`);
    if (row) {
        const inputs = row.querySelectorAll('input');
        inputs.forEach(input => input.value = '');
        row.style.display = 'none';
    }

    freeEditorStations = freeEditorStations.filter(st => st.id !== stationId);
}

async function saveFreeTimetable(e) {
    e.preventDefault();
    
    const trainId = document.getElementById('free_editor_train_id').value;
    if (!trainId) {
        alert('Bitte lade oder erstelle erst einen Zug');
        return;
    }
    
    // Propagation läuft jetzt live bei jeder Eingabe (siehe recalcRow).
    // Ein erneuter Voll-Durchlauf hier würde manuell angepasste, spätere
    // Verspätungen wieder überschreiben – daher bewusst entfernt.
    
    const formData = new FormData(document.getElementById('free_timetable_form'));
    formData.append('action', 'save_timetable');
    formData.append('train_id', trainId);
    
    try {
        const res = await fetch('', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            //alert('Fahrplan gespeichert!');
            closeFreeEditor();
        } else {
            alert('Fehler beim Speichern: ' + (data.error || 'Unbekannter Fehler'));
        }
    } catch (err) {
        console.error('Fehler beim Speichern:', err);
        alert('Verbindungsfehler beim Speichern');
    }
}