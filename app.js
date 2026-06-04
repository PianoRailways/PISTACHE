let currentRouteId = '';

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
            <td><strong>${st.name} (${st.abbr})</strong></td>
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

// NEU: Clear-Funktion für eine Zeile
function clearRow(stationId) {
    const form = document.getElementById('timetable_form');
    if (!form) return;

    // Alle Felder für diese Station clearen
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

    // Auch die Delay-Felder clearen
    const delayArrField = document.getElementById(`delay_arr_${stationId}`);
    const delayDepField = document.getElementById(`delay_dep_${stationId}`);
    if (delayArrField) delayArrField.value = '';
    if (delayDepField) delayDepField.value = '';

    console.log(`Zeile für Station ${stationId} geleert`);
}

async function loadTrain() {
    const trainNumberInput = document.getElementById('train_number').value.trim();
    if (!trainNumberInput) return;
    
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
            
            // STS-Links setzen
            document.getElementById('sts_zid').value = data.train.sts_zid || '';
            document.getElementById('successor_sts_zid').value = data.train.successor_sts_zid || '';
            
            // Editor-Tabelle aufbauen
            buildEditorTable();
            
            // Tabellendaten mit Schleife befüllen
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
        // Schließt den Editor nach dem erfolgreichen Speichern
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
    const form = document.getElementById('timetable_form');
    const stations = routesConfig[currentRouteId].stations;
    const currentIndex = stations.findIndex(s => s.id === stationId);
    if (currentIndex === -1) return;

    // 1. Behandlung: Manuelle Änderung vs. Delay-Feld Änderung
    if (trigger === 'delay') {
        const prefix = type === 'arr' ? 'arrival' : 'departure';
        const istField = form.querySelector(`[name="stations[${stationId}][actual_${prefix}]"]`);
        const sollField = form.querySelector(`[name="stations[${stationId}][${prefix}]"]`);
        const delayField = document.getElementById(`delay_${type}_${stationId}`);
        
        if (istField && sollField && delayField) {
            istField.value = minutesToTime(timeToMinutes(sollField.value) + parseInt(delayField.value || 0, 10));
        }
    } else if (trigger === 'time') {
        // Manuelle Zeiteingabe: Delay neu berechnen, damit es zur Zeit passt
        const prefix = type === 'arr' ? 'arrival' : 'departure';
        const istField = form.querySelector(`[name="stations[${stationId}][actual_${prefix}]"]`);
        const sollField = form.querySelector(`[name="stations[${stationId}][${prefix}]"]`);
        const delayField = document.getElementById(`delay_${type}_${stationId}`);
        
        if (istField && sollField && delayField) {
            delayField.value = timeToMinutes(istField.value) - timeToMinutes(sollField.value);
        }
    }

    // 2. NEUER SCHRITT: Wenn Ankunftszeit geändert wurde, Abfahrtszeit validieren
    if (type === 'arr' && trigger === 'time') {
        const actualArrivalField = form.querySelector(`[name="stations[${stationId}][actual_arrival]"]`);
        const actualDepartureField = form.querySelector(`[name="stations[${stationId}][actual_departure]"]`);
        const sollDepartureField = form.querySelector(`[name="stations[${stationId}][departure]"]`);
        const delayDepartureField = document.getElementById(`delay_dep_${stationId}`);
        
        if (actualArrivalField && actualDepartureField) {
            const arrivalTime = timeToMinutes(actualArrivalField.value);
            const departureTime = timeToMinutes(actualDepartureField.value);
            
            // Wenn Abfahrtszeit gesetzt ist und vor Ankunftszeit liegt, Abfahrtszeit auf Ankunftszeit setzen
            if (arrivalTime !== null && departureTime !== null && departureTime < arrivalTime) {
                actualDepartureField.value = actualArrivalField.value;
                // Delay für Abfahrt neu berechnen
                if (sollDepartureField && delayDepartureField) {
                    delayDepartureField.value = timeToMinutes(actualDepartureField.value) - timeToMinutes(sollDepartureField.value);
                }
            }
        }
    }

    // 3. Kaskade erst ab der NÄCHSTEN Station starten
    propagateForward(currentIndex + 1);
}

async function renderGraph() {
    if (!currentRouteId || !routesConfig[currentRouteId]) return;
    
    const formData = new FormData();
    formData.append('action', 'get_all_data');
    formData.append('route_id', currentRouteId);

    const res = await fetch('', { method: 'POST', body: formData });
    const trains = await res.json();

    updateTrainList(trains);

    const canvas = document.getElementById('graphCanvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    const stations = routesConfig[currentRouteId].stations;
    if (!stations || stations.length === 0) return;

    const startMin = timeToMinutes(document.getElementById('graph_start').value) ?? 240;
    const endMin = timeToMinutes(document.getElementById('graph_end').value) ?? 720;
    const totalVisibleMinutes = endMin - startMin;

    const paddingTop = 60;
    const paddingBottom = 40;
    const paddingLeft = 100;
    const paddingRight = 100;
    
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

    // Raster: Stundenlinien
    ctx.strokeStyle = '#e2e8f0';
    ctx.lineWidth = 1;
    ctx.fillStyle = '#64748b';
    ctx.font = '10px sans-serif';
    
    const startHour = Math.floor(startMin / 60);
    const endHour = Math.ceil(endMin / 60);

    for (let h = startHour; h <= endHour; h++) {
        const y = getY(h * 60);
        if (y === null) continue;

        ctx.beginPath();
        ctx.moveTo(paddingLeft, y);
        ctx.lineTo(paddingLeft + graphWidth, y);
        ctx.stroke();
        
        ctx.textAlign = 'right';
        ctx.fillText(`${h}:00`, paddingLeft - 10, y + 4);
        ctx.textAlign = 'left';
        ctx.fillText(`${h}:00`, paddingLeft + graphWidth + 10, y + 4);
    }

    // Raster: Bahnhofslinien
    stations.forEach((st) => {
        const x = getX(st.km);
        const isDarkMode = (window.getComputedStyle(document.body).backgroundColor === 'rgb(15, 23, 42)');
        
        ctx.strokeStyle = isDarkMode ? '#334155' : '#cbd5e1';
        ctx.beginPath();
        ctx.moveTo(x, paddingTop);
        ctx.lineTo(x, paddingTop + graphHeight);
        ctx.stroke();

        ctx.fillStyle = isDarkMode ? '#f1f5f9' : '#1e293b';
        ctx.font = 'bold 12px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(st.abbr, x, paddingTop - 12);
        
        ctx.font = '9px sans-serif';
        ctx.fillStyle = isDarkMode ? '#94a3b8' : '#64748b';
        ctx.fillText(st.name, x, paddingTop - 28);
        ctx.fillText(`km ${st.km}`, x, paddingTop - 40);
    });

    // Zuglinien zeichnen
    trains.forEach((train) => {
        const baseColor = getTrainColor(train.train_number, train.name);
        
        // Alle Halte sammeln, die auf dieser Route existieren
        const validStops = train.stops
            .map(stop => {
                const st = stations.find(s => s.id === stop.station_id);
                return st ? { stop, km: st.km } : null;
            })
            .filter(item => item !== null);

        if (validStops.length < 2) return;

        // Chronologisch nach Zeit sortieren, damit die Fahrtrichtung vollkommen egal ist
        validStops.sort((a, b) => {
            const timeA = timeToMinutes(a.stop.departure || a.stop.arrival);
            const timeB = timeToMinutes(b.stop.departure || b.stop.arrival);
            return timeA - timeB;
        });

        // --- Punkte generieren für SOLL ---
        const sollPoints = [];
        validStops.forEach(item => {
            const x = getX(item.km);
            const arrMin = timeToMinutes(item.stop.arrival);
            const depMin = timeToMinutes(item.stop.departure);

            if (arrMin !== null) sollPoints.push({ x, y: getY(arrMin) });
            if (depMin !== null) sollPoints.push({ x, y: getY(depMin) });
        });

        // --- Punkte generieren für IST ---
        const istPoints = [];
        validStops.forEach(item => {
            const x = getX(item.km);
            const arrMin = timeToMinutes(item.stop.actual_arrival || item.stop.arrival);
            const depMin = timeToMinutes(item.stop.actual_departure || item.stop.departure);

            if (arrMin !== null) istPoints.push({ x, y: getY(arrMin) });
            if (depMin !== null) istPoints.push({ x, y: getY(depMin) });
        });

        // HILFSFUNKTION: Zeichnet eine durchgehende Punktkette
        function drawPointChain(points) {
            let started = false;
            ctx.beginPath();
            points.forEach(p => {
                if (p.y === null) {
                    // Punkt liegt ausserhalb des sichtbaren Zeitfensters -> Linie trennen
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

        // --- 1. SCHRITT: Soll-Fahrplan verblasst zeichnen ---
        ctx.strokeStyle = baseColor;
        ctx.globalAlpha = 0.5; 
        ctx.lineWidth = 1.5;
        ctx.setLineDash([4, 4]); 
        drawPointChain(sollPoints);

        // --- 2. SCHRITT: Tatsächlicher Fahrplan (Ist) zeichnen ---
        ctx.globalAlpha = 1.0; 
        ctx.lineWidth = 2.5;
        ctx.setLineDash([]); 
        drawPointChain(istPoints);

        // --- 3. SCHRITT: Zugnummer am ersten sichtbaren Punkt platzieren ---
        const firstVisibleIst = istPoints.find(p => p.y !== null);
        if (firstVisibleIst) {
            ctx.fillStyle = baseColor;
            ctx.font = 'bold 11px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText(train.train_number, firstVisibleIst.x, firstVisibleIst.y - 8);
        }
    });
    
    ctx.globalAlpha = 1.0;
    ctx.setLineDash([]);

// ==========================================
    // Gelbe/Rote "JETZT"-Zeitlinie (Synchronisiert mit STS)
    // ==========================================
    (function drawCurrentTimeLine() {
        const now = new Date();
        
        // 1. Hole die aktuelle PC-Zeit in Minuten (11:26 = 686 Minuten)
        const localTotalMinutes = now.getHours() * 60 + now.getMinutes();
        
        // 2. Addiere das STS-Offset (+180 Minuten für 14:26)
        const simOffset = -420; 
        
        let currentTotalMinutes = localTotalMinutes + simOffset;
        if (currentTotalMinutes < 0) currentTotalMinutes += 1440;
        currentTotalMinutes = currentTotalMinutes % 1440; // Verhindert Werte über 24 Std.

        // 3. Sichtbaren Bereich des Diagramms auslesen
        const startVal = document.getElementById('graph_start')?.value || "11:00";
        const endVal = document.getElementById('graph_end')?.value || "17:00";

        const [startH, startM] = startVal.split(':').map(Number);
        const [endH, endM] = endVal.split(':').map(Number);

        const startMinutes = startH * 60 + startM;
        const endMinutes = endH * 60 + endM;

        // 4. Prüfen, ob die STS-Zeit (14:26) im sichtbaren Fenster (11:00 - 17:00) liegt
        if (currentTotalMinutes >= startMinutes && currentTotalMinutes <= endMinutes) {
            const y = getY(currentTotalMinutes);

            if (y !== null) {
                ctx.save();
                ctx.beginPath();
                ctx.strokeStyle = '#FFDE15'; // Schönes STS-Gelb
                ctx.lineWidth = 2;
                ctx.setLineDash([6, 4]);
                
                // Zeichne die Linie von ganz links nach ganz rechts auf der korrekten Höhe (y)
                ctx.moveTo(paddingLeft, y);
                ctx.lineTo(paddingLeft + graphWidth, y);
                ctx.stroke();

                ctx.fillStyle = '#FFDE15';
                ctx.font = 'bold 11px sans-serif';
                ctx.textAlign = 'right';
                ctx.textBaseline = 'middle';
                
                // Uhrzeit exakt anhand der berechneten STS-Minuten anzeigen
                const displayHours = Math.floor(currentTotalMinutes / 60);
                const displayMinutes = currentTotalMinutes % 60;
                const timeString = `${String(displayHours).padStart(2, '0')}:${String(displayMinutes).padStart(2, '0')}`;
                
                // Text links neben die Linie platzieren
                ctx.fillText(timeString, paddingLeft - 10, y);
                
                ctx.restore();
            }
        }
    })();
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
    return '#64748b'; // Standardfarbe für alles andere
}
// Konfiguration: Zeitversatz zur Systemzeit in Minuten
const SIM_OFFSET_MINUTES = 180; // Prüfe hier, ob das wirklich -420 sein soll!

function getSimTime() {
    const now = new Date();
    // Die Berechnung ist korrekt, sie nimmt die aktuelle PC-Zeit und addiert den Offset
    const totalMinutes = (now.getHours() * 60) + now.getMinutes() + SIM_OFFSET_MINUTES;
    const simMinutes = (totalMinutes % 1440 + 1440) % 1440;
    
    const h = Math.floor(simMinutes / 60);
    const m = simMinutes % 60;
    
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

function setNow(stationId, fieldName) {
    const form = document.getElementById('timetable_form');
    // WICHTIG: Hier wird jetzt deine globale Funktion aufgerufen
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

function propagateForward(startIndex) {
    const form = document.getElementById('timetable_form');
    const stations = routesConfig[currentRouteId].stations;
    
    // Anker finden (letzte valide Zeit VOR dem Startpunkt)
    let lastDepartureTime = 0;
    // Wir suchen bei der Station kurz vor dem Startpunkt
    for (let i = startIndex - 1; i >= 0; i--) {
        const depVal = form.querySelector(`[name="stations[${stations[i].id}][actual_departure]"]`)?.value;
        const depTime = timeToMinutes(depVal);
        if (depTime !== null && !isNaN(depTime)) {
            lastDepartureTime = depTime;
            break;
        }
    }

    // Laufzeit-Basis finden
    let lastValidSollDep = null;
    for (let i = startIndex - 1; i >= 0; i--) {
        const depVal = form.querySelector(`[name="stations[${stations[i].id}][departure]"]`)?.value;
        if (depVal && depVal !== '--:--') {
            lastValidSollDep = timeToMinutes(depVal);
            break;
        }
    }

    // Durch die nachfolgenden Stationen iterieren
    for (let i = startIndex; i < stations.length; i++) {
        const st = stations[i];
        const sollArr = timeToMinutes(form.querySelector(`[name="stations[${st.id}][arrival]"]`)?.value);
        const sollDep = timeToMinutes(form.querySelector(`[name="stations[${st.id}][departure]"]`)?.value);

        if (sollArr === null && sollDep === null) continue;

        const arrIst = form.querySelector(`[name="stations[${st.id}][actual_arrival]"]`);
        const depIst = form.querySelector(`[name="stations[${st.id}][actual_departure]"]`);
        
        let newArr = lastDepartureTime;

        if (sollArr !== null) {
            const travel = (lastValidSollDep !== null) ? (sollArr - lastValidSollDep) : 0;
            newArr = lastDepartureTime + Math.round(travel * 0.9);
            if (newArr < lastDepartureTime) newArr = lastDepartureTime;
            
            if (arrIst) arrIst.value = minutesToTime(newArr);
            const dArr = document.getElementById(`delay_arr_${st.id}`);
            if (dArr) dArr.value = newArr - sollArr;
            lastDepartureTime = newArr;
        }

        if (sollDep !== null) {
            const newDep = (sollArr !== null) ? (newArr + Math.max(sollDep - sollArr, 0)) : (lastDepartureTime + Math.max(sollDep - (sollArr || lastValidSollDep || 0), 0));
            if (depIst) depIst.value = minutesToTime(newDep);
            const dDep = document.getElementById(`delay_dep_${st.id}`);
            if (dDep) dDep.value = newDep - sollDep;
            lastDepartureTime = newDep;
            lastValidSollDep = sollDep;
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