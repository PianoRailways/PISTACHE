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

// =========================================================================
// JAVASCRIPT HILFSFUNKTIONEN (Für Live-Berechnungen im Browser-Formular)
// =========================================================================
function timeToMinutes(timeStr) {
    if (!timeStr) return 0;
    const parts = timeStr.split(':');
    if (parts.length < 2) return 0;
    const h = parseInt(parts[0], 10);
    const m = parseInt(parts[1], 10);
    return isNaN(h) || isNaN(m) ? 0 : (h * 60 + m);
}

function minutesToTime(totalMinutes) {
    if (totalMinutes === null || isNaN(totalMinutes) || totalMinutes < 0) return '';
    const positiveMinutes = (totalMinutes % 1440 + 1440) % 1440;
    const h = Math.floor(positiveMinutes / 60);
    const m = positiveMinutes % 60;
    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

// Macht die Funktionen global verfügbar, damit In-Line HTML Eventhandler (onkeyup/oninput) sie sehen
window.timeToMinutes = timeToMinutes;
window.minutesToTime = minutesToTime;

function propagateForward(startIndex) {
    const table = document.getElementById('editor_table');
    if (!table) return;
    const rows = Array.from(table.querySelectorAll('tbody tr'));
    if (startIndex < 0 || startIndex >= rows.length) return;

    const baseRow = rows[startIndex];
    const sollDepIn = baseRow.querySelector('input[name$="[departure]"]');
    const istDepIn = baseRow.querySelector('input[name$="[actual_departure]"]');
    const sollArrIn = baseRow.querySelector('input[name$="[arrival]"]');
    const istArrIn = baseRow.querySelector('input[name$="[actual_arrival]"]');

    let currentDelay = 0;

    if (sollDepIn && sollDepIn.value && istDepIn && istDepIn.value) {
        currentDelay = timeToMinutes(istDepIn.value) - timeToMinutes(sollDepIn.value);
    } else if (sollArrIn && sollArrIn.value && istArrIn && istArrIn.value) {
        currentDelay = timeToMinutes(istArrIn.value) - timeToMinutes(sollArrIn.value);
    }

    for (let i = startIndex + 1; i < rows.length; i++) {
        const row = rows[i];

        const sArr = row.querySelector('input[name$="[arrival]"]');
        const iArr = row.querySelector('input[name$="[actual_arrival]"]');
        const dArr = row.querySelector('input[id^="delay_arr_"]');

        if (sArr && sArr.value) {
            const newArr = timeToMinutes(sArr.value) + currentDelay;
            if (iArr) iArr.value = minutesToTime(newArr);
            if (dArr) dArr.value = currentDelay;
        }

        const sDep = row.querySelector('input[name$="[departure]"]');
        const iDep = row.querySelector('input[name$="[actual_departure]"]');
        const dDep = row.querySelector('input[id^="delay_dep_"]');

        if (sDep && sDep.value) {
            const newDep = timeToMinutes(sDep.value) + currentDelay;
            if (iDep) iDep.value = minutesToTime(newDep);
            if (dDep) dDep.value = currentDelay;
        }
    }
}

function recalcRow(stationId, type, currentIndex) {
    const sollArrInput = document.querySelector(`input[name="stations[${stationId}][arrival]"]`);
    const istArrInput = document.querySelector(`input[name="stations[${stationId}][actual_arrival]"]`);
    const delayArrInput = document.getElementById(`delay_arr_${stationId}`);

    const sollDepInput = document.querySelector(`input[name="stations[${stationId}][departure]"]`);
    const istDepInput = document.querySelector(`input[name="stations[${stationId}][actual_departure]"]`);
    const delayDepInput = document.getElementById(`delay_dep_${stationId}`);

    const sollArr = sollArrInput && sollArrInput.value ? timeToMinutes(sollArrInput.value) : null;
    const istArr = istArrInput && istArrInput.value ? timeToMinutes(istArrInput.value) : null;
    const istArrDelay = delayArrInput && delayArrInput.value ? parseInt(delayArrInput.value, 10) : 0;

    const sollDep = sollDepInput && sollDepInput.value ? timeToMinutes(sollDepInput.value) : null;
    const istDep = istDepInput && istDepInput.value ? timeToMinutes(istDepInput.value) : null;
    const istDepDelay = delayDepInput && delayDepInput.value ? parseInt(delayDepInput.value, 10) : 0;

    if (type === 'arr' && (currentIndex === 'time' || currentIndex === undefined)) {
        if (sollArr !== null && istArr !== null) {
            if (delayArrInput) delayArrInput.value = istArr - sollArr;
        }
    } else if (type === 'arr' && currentIndex === 'delay') {
        if (sollArr !== null) {
            const newIstArr = sollArr + istArrDelay;
            if (istArrInput) istArrInput.value = minutesToTime(newIstArr);
        }
    } else if (type === 'dep' && (currentIndex === 'time' || currentIndex === undefined)) {
        if (sollDep !== null && istDep !== null) {
            if (delayDepInput) delayDepInput.value = istDep - sollDep;
        }
    } else if (type === 'dep' && currentIndex === 'delay') {
        if (sollDep !== null) {
            const newIstDep = sollDep + istDepDelay;
            if (istDepInput) istDepInput.value = minutesToTime(newIstDep);
        }
    }

    // Zeilenindex innerhalb der Tabelle dynamisch ermitteln
    const table = document.getElementById('editor_table');
    if (table) {
        const rows = Array.from(table.querySelectorAll('tbody tr'));
        const rowIndex = rows.findIndex(row => row.id === `row_${stationId}`);
        if (rowIndex !== -1) {
            propagateForward(rowIndex);
        }
    }
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
            ctx.strokeStyle = (window.getComputedStyle(document.body).backgroundColor === 'rgb(15, 23, 42)') ? '#334155' : '#cbd5e1';
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
            ctx.fillStyle = baseColor;
            ctx.font = 'bold 11px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText(train.train_number, firstVisibleIst.x, firstVisibleIst.y - 8);
        }
    });
    
    ctx.globalAlpha = 1.0;
    ctx.setLineDash([]);

    (function drawCurrentTimeLine() {
        const now = new Date();
        const localTotalMinutes = now.getHours() * 60 + now.getMinutes();
        
        const offsetInput = document.getElementById('sts_offset');
        const simOffset = offsetInput ? parseInt(offsetInput.value || 0, 10) : 0; 
        
        let currentTotalMinutes = localTotalMinutes + simOffset;
        currentTotalMinutes = ((currentTotalMinutes % 1440) + 1440) % 1440;

        const startVal = document.getElementById('graph_start')?.value || "11:00";
        const endVal = document.getElementById('graph_end')?.value || "17:00";

        const [startH, startM] = startVal.split(':').map(Number);
        const [endH, endM] = endVal.split(':').map(Number);

        const startMinutes = startH * 60 + startM;
        const endMinutes = endH * 60 + endM;

        if (currentTotalMinutes >= startMinutes && currentTotalMinutes <= endMinutes) {
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
                ctx.font = 'bold 11px sans-serif';
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
 * Kaskaden-Propagation für den FREIEN EDITOR
 * Iteriert innerhalb eines Zuges durch seine Haltestellen und berechnet Ankunfts-/Abfahrtsverspätung
 * WICHTIG: Nur innerhalb der Fahrt — keine Folgezug-Berechnung!
 */
function propagateForwardFree(startIndex) {
    const form = document.getElementById('free_timetable_form');
    const stations = freeEditorStations;
    
    if (stations.length === 0) return;
    
    let lastDepartureTime = 0;
    for (let i = startIndex - 1; i >= 0; i--) {
        const st = stations[i];
        const depInput = document.getElementById(`free_ist_dep_${st.id}`);
        const depTime = depInput ? timeToMinutes(depInput.value) : null;
        if (depTime !== null && !isNaN(depTime)) {
            lastDepartureTime = depTime;
            break;
        }
    }
    
    let lastValidSollDep = null;
    for (let i = startIndex - 1; i >= 0; i--) {
        const st = stations[i];
        const depVal = form.querySelector(`[name="stations[${st.id}][departure]"]`)?.value;
        if (depVal && depVal !== '--:--') {
            lastValidSollDep = timeToMinutes(depVal);
            break;
        }
    }
    
    for (let i = startIndex; i < stations.length; i++) {
        const st = stations[i];
        const sollArr = timeToMinutes(form.querySelector(`[name="stations[${st.id}][arrival]"]`)?.value);
        const sollDep = timeToMinutes(form.querySelector(`[name="stations[${st.id}][departure]"]`)?.value);
        
        if (sollArr === null && sollDep === null) continue;
        
        const arrIstInput = document.getElementById(`free_ist_arr_${st.id}`);
        const depIstInput = document.getElementById(`free_ist_dep_${st.id}`);
        const arrDelayInput = document.getElementById(`free_delay_arr_${st.id}`);
        const depDelayInput = document.getElementById(`free_delay_dep_${st.id}`);
        
        let newArr = lastDepartureTime;
        if (sollArr !== null) {
            const travel = (lastValidSollDep !== null) ? (sollArr - lastValidSollDep) : 0;
            newArr = lastDepartureTime + Math.round(travel * 0.9);
            if (newArr < lastDepartureTime) newArr = lastDepartureTime;
            
            if (arrIstInput) arrIstInput.value = minutesToTime(newArr);
            const arrDelay = sollArr !== null ? newArr - sollArr : 0;
            if (arrDelayInput) arrDelayInput.value = arrDelay;
            
            lastDepartureTime = newArr;
        }
        
        if (sollDep !== null) {
            const newDep = (sollArr !== null) ? (newArr + Math.max(sollDep - sollArr, 0)) : (lastDepartureTime + Math.max(sollDep - (sollArr || lastValidSollDep || 0), 0));
            
            if (depIstInput) depIstInput.value = minutesToTime(newDep);
            const depDelay = sollDep !== null ? newDep - sollDep : 0;
            if (depDelayInput) depDelayInput.value = depDelay;
            
            lastDepartureTime = newDep;
            lastValidSollDep = sollDep;
        }
    }
}

/**
 * Zweiweg-Sync für Free Editor: Ist-Zeit ↔ Verspätung
 */
function recalcRowFree(stationId, type, currentIndex) {
    const sollArrInput = document.querySelector(`#free_editor_tbody tr[data-station-id="${stationId}"] input[name$="[arrival]"]`);
    const istArrInput = document.getElementById(`free_ist_arr_${stationId}`);
    const delayArrInput = document.getElementById(`free_delay_arr_${stationId}`);

    const sollDepInput = document.querySelector(`#free_editor_tbody tr[data-station-id="${stationId}"] input[name$="[departure]"]`);
    const istDepInput = document.getElementById(`free_ist_dep_${stationId}`);
    const delayDepInput = document.getElementById(`free_delay_dep_${stationId}`);

    const sollArr = sollArrInput && sollArrInput.value ? timeToMinutes(sollArrInput.value) : null;
    const istArr = istArrInput && istArrInput.value ? timeToMinutes(istArrInput.value) : null;
    const istArrDelay = delayArrInput && delayArrInput.value ? parseInt(delayArrInput.value, 10) : 0;

    const sollDep = sollDepInput && sollDepInput.value ? timeToMinutes(sollDepInput.value) : null;
    const istDep = istDepInput && istDepInput.value ? timeToMinutes(istDepInput.value) : null;
    const istDepDelay = delayDepInput && delayDepInput.value ? parseInt(delayDepInput.value, 10) : 0;

    if (type === 'actual_arrival' || type === 'arrival') {
        if (sollArr !== null && istArr !== null) {
            if (delayArrInput) delayArrInput.value = istArr - sollArr;
        } else if (delayArrInput) {
            delayArrInput.value = '';
        }
    } else if (type === 'arrival_delay') {
        if (sollArr !== null) {
            const newIstArr = sollArr + istArrDelay;
            if (istArrInput) istArrInput.value = minutesToTime(newIstArr);
        }
    }

    if (type === 'actual_departure' || type === 'departure') {
        if (sollDep !== null && istDep !== null) {
            if (delayDepInput) delayDepInput.value = istDep - sollDep;
        } else if (delayDepInput) {
            delayDepInput.value = '';
        }
    } else if (type === 'departure_delay') {
        if (sollDep !== null) {
            const newIstDep = sollDep + istDepDelay;
            if (istDepInput) istDepInput.value = minutesToTime(newIstDep);
        }
    }
    
    if (freeEditorStations[currentIndex]) {
        if (sollArrInput) freeEditorStations[currentIndex].arrival = sollArrInput.value;
        if (istArrInput) freeEditorStations[currentIndex].actual_arrival = istArrInput.value;
        if (sollDepInput) freeEditorStations[currentIndex].departure = sollDepInput.value;
        if (istDepInput) freeEditorStations[currentIndex].actual_departure = istDepInput.value;
    }

    if (typeof propagateForwardFree === 'function') {
        propagateForwardFree(currentIndex);
    }
}

window.timeToMinutes = timeToMinutes;
window.recalcRowFree = recalcRowFree;

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