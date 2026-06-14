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

function propagateForward(startIndex) {
    const form = document.getElementById('timetable_form');
    const stations = routesConfig[currentRouteId].stations;
    
    let lastDepartureTime = 0;
    for (let i = startIndex - 1; i >= 0; i--) {
        const depVal = form.querySelector(`[name="stations[${stations[i].id}][actual_departure]"]`)?.value;
        const depTime = timeToMinutes(depVal);
        if (depTime !== null && !isNaN(depTime)) {
            lastDepartureTime = depTime;
            break;
        }
    }

    let lastValidSollDep = null;
    for (let i = startIndex - 1; i >= 0; i--) {
        const depVal = form.querySelector(`[name="stations[${stations[i].id}][departure]"]`)?.value;
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

// ========================================================================
// PROPAGATION MIT 7%-RESERVE & STANDZEIT-BERÜCKSICHTIGUNG
// ========================================================================

/**
 * propagateTravelTimeWithReserve()
 * 
 * Propagiert die ganze Fahrt durch den Zug mit folgender Logik:
 * - Berechnet die SOLL-Fahrzeit zwischen zwei Haltestellen
 * - Anwendet 7% Reserve: Fahrzeit darf maximal um 7% kürzer sein
 * - Berücksichtigt die Standzeit (Arrival bis Departure) an jeder Station
 * - Propagiert die Ist-Zeiten über die ganze Fahrt
 */
function propagateTravelTimeWithReserve() {
    const form = document.getElementById('free_timetable_form');
    if (!form) return;

    // Durchgehe alle Stationen in Reihenfolge
    for (let i = 0; i < freeEditorStations.length; i++) {
        const currentStation = freeEditorStations[i];
        const stId = currentStation.id;

        // Lese Soll-Ankunft und Soll-Abfahrt
        const sollArrStr = form.querySelector(`[name="stations[${stId}][arrival]"]`)?.value;
        const sollDepStr = form.querySelector(`[name="stations[${stId}][departure]"]`)?.value;

        // Wenn keine Abfahrt/Ankunft: überspring
        if (!sollArrStr && !sollDepStr) continue;

        const sollArrMin = timeToMinutes(sollArrStr);
        const sollDepMin = timeToMinutes(sollDepStr);

        // ---  IST-Zeiten auslesen ---
        const istArrField = form.querySelector(`[name="stations[${stId}][actual_arrival]"]`);
        const istDepField = form.querySelector(`[name="stations[${stId}][actual_departure]"]`);
        let istArrMin = timeToMinutes(istArrField?.value);
        let istDepMin = timeToMinutes(istDepField?.value);

        // Wenn Ankunft fehlt, von vorheriger Abfahrt übernehmen
        if (istArrMin === null && i > 0) {
            const prevStation = freeEditorStations[i - 1];
            const prevDepField = form.querySelector(`[name="stations[${prevStation.id}][actual_departure]"]`);
            istArrMin = timeToMinutes(prevDepField?.value);
            if (istArrMin !== null && istArrField) {
                istArrField.value = minutesToTime(istArrMin);
            }
        }

        // Wenn Ankunft immer noch null, skip
        if (istArrMin === null) continue;

        // Standzeit berechnen (Soll-Soll)
        let standzeit = 0;
        if (sollArrMin !== null && sollDepMin !== null && sollDepMin >= sollArrMin) {
            standzeit = sollDepMin - sollArrMin;
        }

        // IST-Abfahrt berechnen
        if (istDepMin === null) {
            // Es gibt noch keine Ist-Abfahrt: wird aus Fahrzeit + Standzeit berechnet
            if (i > 0) {
                // Hole die vorige Soll-Abfahrt und Ist-Abfahrt
                const prevStation = freeEditorStations[i - 1];
                const prevSollDepStr = form.querySelector(`[name="stations[${prevStation.id}][departure]"]`)?.value;
                const prevIstDepField = form.querySelector(`[name="stations[${prevStation.id}][actual_departure]"]`);
                const prevIstDepMin = timeToMinutes(prevIstDepField?.value);

                if (prevIstDepMin !== null && prevSollDepStr) {
                    const prevSollDepMin = timeToMinutes(prevSollDepStr);

                    // Soll-Fahrzeit
                    const sollFahrtzeit = (sollArrMin !== null) 
                        ? (sollArrMin - prevSollDepMin) 
                        : (sollDepMin - prevSollDepMin);

                    if (sollFahrtzeit > 0) {
                        // 7% Reserve: Minimum-Fahrzeit = Sollfahrzeit * 0.93
                        const minFahrtzeit = Math.round(sollFahrtzeit * 0.93);

                        // Ist-Fahrzeit basierend auf bisher eingegeben Ist-Ankunft
                        const istFahrtzeit = istArrMin - prevIstDepMin;

                        // Verwende Max: entweder die eingegebene Ist-Fahrzeit oder Minimum (mit 7% Reserve)
                        const effektiveFahrtzeit = Math.max(istFahrtzeit, minFahrtzeit);

                        // Neue Ist-Ankunft berechnen
                        istArrMin = prevIstDepMin + effektiveFahrtzeit;
                        if (istArrField) {
                            istArrField.value = minutesToTime(istArrMin);
                        }
                    }
                }
            }

            // Jetzt Ist-Abfahrt = Ist-Ankunft + Standzeit
            istDepMin = istArrMin + standzeit;
            if (istDepField) {
                istDepField.value = minutesToTime(istDepMin);
            }
        }

        // Delay-Felder aktualisieren
        const delayArrField = document.getElementById(`delay_arr_${stId}`);
        const delayDepField = document.getElementById(`delay_dep_${stId}`);

        if (sollArrMin !== null && delayArrField) {
            delayArrField.value = istArrMin - sollArrMin;
        }
        if (sollDepMin !== null && delayDepField) {
            delayDepField.value = istDepMin - sollDepMin;
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
    
    // ÄNDERUNG: Propagiere die Fahrzeit mit 7%-Reserve vor dem Speichern
    propagateTravelTimeWithReserve();
    
    const formData = new FormData(document.getElementById('free_timetable_form'));
    formData.append('action', 'save_timetable');
    formData.append('train_id', trainId);
    
    try {
        const res = await fetch('', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            alert('Fahrplan gespeichert!');
            closeFreeEditor();
        } else {
            alert('Fehler beim Speichern: ' + (data.error || 'Unbekannter Fehler'));
        }
    } catch (err) {
        console.error('Fehler beim Speichern:', err);
        alert('Verbindungsfehler beim Speichern');
    }
}