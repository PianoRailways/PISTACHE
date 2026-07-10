/**
 * Canvas Hover Tooltip System (vollständig korrigiert)
 * Zeigt bei Mouseover über einer Zuglinie ein Popup mit:
 * - Zugnummer
 * - Fahrplanausschnitt des aktuellen Streckenabschnitts (Station, Soll/Ist-Zeiten, Flags)
 */

class CanvasTooltip {
    constructor(canvasElement) {
        this.canvas = canvasElement;
        this.ctx = canvasElement.getContext('2d');
        
        // Popup DOM
        this.popup = null;
        this.isVisible = false;
        
        // Hit-Testing-Cache
        this.trainSegments = [];
        this.hitThreshold = 5; // Pixel-Toleranz zum Treffen
        
        // Dark/Light Mode Detektor
        this.isDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
        
        // Mousemove-Handler
        this.boundMouseMove = (e) => this.handleMouseMove(e);
        this.boundMouseLeave = () => this.hidePopup();
        
        // Listener für Mode-Wechsel
        this.boundColorSchemeChange = (e) => {
            this.isDarkMode = e.matches;
            if (this.isVisible && this.popup) {
                this.applyModeStyles();
            }
        };
        
        this.attachListeners();
    }

    /**
     * Registriere Canvas-Listener
     */
    attachListeners() {
        this.canvas.addEventListener('mousemove', this.boundMouseMove);
        this.canvas.addEventListener('mouseleave', this.boundMouseLeave);
        
        const darkModeQuery = window.matchMedia('(prefers-color-scheme: dark)');
        darkModeQuery.addEventListener('change', this.boundColorSchemeChange);
    }

    /**
     * Detach Listener
     */
    detachListeners() {
        this.canvas.removeEventListener('mousemove', this.boundMouseMove);
        this.canvas.removeEventListener('mouseleave', this.boundMouseLeave);
        
        const darkModeQuery = window.matchMedia('(prefers-color-scheme: dark)');
        darkModeQuery.removeEventListener('change', this.boundColorSchemeChange);
    }

    /**
     * Cache alle sichtbaren Zuglinien-Segmente
     */
    cacheTrainSegments(trainSegments) {
        this.trainSegments = trainSegments || [];
    }

    /**
     * Handle Mousemove: Berechnet Koordinaten und führt präzisen Hit-Test durch
     */
    handleMouseMove(event) {
        if (!this.trainSegments || this.trainSegments.length === 0) {
            this.hidePopup();
            return;
        }

        const rect = this.canvas.getBoundingClientRect();
        const computedStyle = window.getComputedStyle(this.canvas);
        
        // CSS-Rahmen und Padding abziehen
        const borderLeft = parseFloat(computedStyle.borderLeftWidth) || 0;
        const borderTop = parseFloat(computedStyle.borderTopWidth) || 0;
        const paddingLeft = parseFloat(computedStyle.paddingLeft) || 0;
        const paddingTop = parseFloat(computedStyle.paddingTop) || 0;
        
        let mouseX = event.clientX - rect.left - borderLeft - paddingLeft;
        let mouseY = event.clientY - rect.top - borderTop - paddingTop;
        
        // Skalierung ausgleichen, falls CSS-Größe von interner Canvas-Größe abweicht
        const contentWidth = rect.width - borderLeft - parseFloat(computedStyle.borderRightWidth) - paddingLeft - parseFloat(computedStyle.paddingRight);
        const contentHeight = rect.height - borderTop - parseFloat(computedStyle.borderBottomWidth) - paddingTop - parseFloat(computedStyle.paddingBottom);
        
        if (contentWidth > 0 && contentHeight > 0) {
            mouseX *= (this.canvas.width / contentWidth);
            mouseY *= (this.canvas.height / contentHeight);
        }

        const hit = this.findHitTrain(mouseX, mouseY);

        if (hit) {
            this.showPopup(hit.train, hit.index, event.clientX, event.clientY);
        } else {
            this.hidePopup();
        }
    }

    /**
     * Prüfe, welches Segment der Maus am nächsten liegt
     */
    findHitTrain(mouseX, mouseY) {
        let bestHit = null;
        let minDistance = this.hitThreshold;

        for (const segment of this.trainSegments) {
            if (!segment || !segment.points || segment.points.length < 2) continue;
            
            const points = segment.points;
            
            for (let i = 0; i < points.length - 1; i++) {
                const p1 = points[i];
                const p2 = points[i + 1];

                if (!p1 || !p2) continue;

                const distToLine = this.distanceToLineSegment(
                    mouseX, mouseY,
                    p1.x, p1.y,
                    p2.x, p2.y
                );
                
                if (distToLine < minDistance) {
                    minDistance = distToLine;
                    bestHit = {
                        train: segment.train,
                        index: i // Speichert den exakten Linienabschnitt
                    };
                }
            }
        }
        return bestHit;
    }

    /**
     * Berechne kürzeste Entfernung von Punkt zu einem Liniensegment
     */
    distanceToLineSegment(px, py, x1, y1, x2, y2) {
        const A = px - x1;
        const B = py - y1;
        const C = x2 - x1;
        const D = y2 - y1;

        const dot = A * C + B * D;
        const lenSq = C * C + D * D;

        let param = -1;
        if (lenSq !== 0) param = dot / lenSq;

        let xx, yy;
        if (param < 0) {
            xx = x1;
            yy = y1;
        } else if (param > 1) {
            xx = x2;
            yy = y2;
        } else {
            xx = x1 + param * C;
            yy = y1 + param * D;
        }

        const dx = px - xx;
        const dy = py - yy;
        return Math.hypot(dx, dy);
    }

    /**
     * Erstelle und zeigt das Popup
     */
    showPopup(train, segmentIndex, clientX, clientY) {
        if (!this.popup) {
            this.popup = document.createElement('div');
            this.popup.id = 'canvas-tooltip';
            document.body.appendChild(this.popup);
        }

        this.popup.innerHTML = this.formatPopupContent(train, segmentIndex);
        this.applyModeStyles();

        const offsetX = 15;
        const offsetY = 10;
        this.popup.style.left = (clientX + offsetX) + 'px';
        this.popup.style.top = (clientY + offsetY) + 'px';
        this.popup.style.display = 'block';

        this.isVisible = true;
    }

    /**
     * Wende Mode-spezifische Styles an
     */
    applyModeStyles() {
        if (!this.popup) return;

        const commonStyles = `
            position: fixed;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 13px;
            pointer-events: none;
            z-index: 1000;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.25);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            transition: background 0.15s, color 0.15s, border-color 0.15s;
            min-width: 520px;
        `;

        if (this.isDarkMode) {
            this.popup.style.cssText = `
                ${commonStyles}
                background: rgba(22, 28, 45, 0.96);
                color: #f1f5f9;
                border: 1px solid #0ea5e9;
            `;
        } else {
            this.popup.style.cssText = `
                ${commonStyles}
                background: rgba(255, 255, 255, 0.96);
                color: #1e293b;
                border: 1px solid #3b82f6;
            `;
        }
    }

    /**
     * Formatiert den Tabelleninhalt für den aktuellen Streckenabschnitt
     */
    formatPopupContent(train, segmentIndex) {
        if (!train || !train.stops) return '';

        const stations = routesConfig[currentRouteId]?.stations || [];
        const allStations = Object.values(routesConfig).flatMap(r => r.stations || []);

        // 1. Rekonstruktion des exakten validStops-Arrays analog zu app.js
        const validStops = train.stops
            .map(stop => {
                const st = stations.find(s => s.id === stop.station_id);
                return st ? { stop, km: st.km } : null;
            })
            .filter(item => item !== null);

        validStops.sort((a, b) => {
            const timeA = this.timeToMinutes(a.stop.departure || a.stop.arrival);
            const timeB = this.timeToMinutes(b.stop.departure || b.stop.arrival);
            return timeA - timeB;
        });

        const normalizeDelayFromMinutes = (istMin, sollMin) => {
            if (istMin === null || sollMin === null) return null;

            let diff = istMin - sollMin;
            if (diff < -720) diff += 1440;
            if (diff > 720) diff -= 1440;

            return diff;
        };

        const derivedDelayByStop = new Map();
        let previousIstDeparture = null;
        let previousSollDeparture = null;

        validStops.forEach((item) => {
            const stop = item.stop;
            const sollArrMin = this.timeToMinutes(stop.arrival);
            const sollDepMin = this.timeToMinutes(stop.departure);
            const actualArrMin = this.timeToMinutes(stop.actual_arrival);
            const actualDepMin = this.timeToMinutes(stop.actual_departure);

            let istArrMin = actualArrMin;
            if (istArrMin === null) {
                if (previousIstDeparture !== null && previousSollDeparture !== null && sollArrMin !== null) {
                    const sollTravelMinutes = Math.max(0, sollArrMin - previousSollDeparture);
                    istArrMin = previousIstDeparture + sollTravelMinutes;
                } else if (previousIstDeparture !== null) {
                    istArrMin = previousIstDeparture;
                } else {
                    istArrMin = sollArrMin;
                }
            }

            const arrivalDelay = normalizeDelayFromMinutes(istArrMin, sollArrMin);

            let istDepMin = actualDepMin;
            if (istDepMin === null) {
                if (sollDepMin !== null) {
                    let sollStandzeit = 0;
                    if (sollArrMin !== null) {
                        sollStandzeit = Math.max(0, sollDepMin - sollArrMin);
                    }

                    const hasRFlag = /R/i.test(stop.flags || '');
                    const minStandzeit = hasRFlag ? 2 : 0;
                    const availableBraking = Math.max(0, sollStandzeit - minStandzeit);
                    const actualBraking = Math.min(Math.max(0, arrivalDelay || 0), availableBraking);
                    const remainingDelay = Math.max(0, (arrivalDelay || 0) - actualBraking);

                    istDepMin = sollDepMin + remainingDelay;
                } else if (istArrMin !== null) {
                    istDepMin = istArrMin;
                }
            }

            const departureDelay = normalizeDelayFromMinutes(istDepMin, sollDepMin);

            derivedDelayByStop.set(stop, {
                arrivalDelay,
                departureDelay
            });

            if (istDepMin !== null) {
                previousIstDeparture = istDepMin;
            }
            previousSollDeparture = sollDepMin !== null ? sollDepMin : sollArrMin;
        });

        // 2. Erstellung des exakten Point-Index-Mappings
        const pointMapping = [];
        validStops.forEach((item, idx) => {
            const arrMin = this.timeToMinutes(item.stop.actual_arrival || item.stop.arrival);
            const depMin = this.timeToMinutes(item.stop.actual_departure || item.stop.departure);

            if (arrMin !== null) pointMapping.push({ stopIndex: idx, type: 'arr' });
            if (depMin !== null) pointMapping.push({ stopIndex: idx, type: 'dep' });
        });

        // 3. Zuordnung der beiden Stationen über das ermittelte Segment
        let stopA = null;
        let stopB = null;

        const p1Map = pointMapping[segmentIndex];
        const p2Map = pointMapping[segmentIndex + 1];

        if (p1Map && p2Map) {
            if (p1Map.stopIndex === p2Map.stopIndex) {
                // Zug steht am selben Bahnhof (Segment zwischen AN- und AB-Zeit)
                stopA = validStops[p1Map.stopIndex]?.stop;
                stopB = validStops[p1Map.stopIndex + 1]?.stop; 
            } else {
                // Zug fährt zwischen zwei Bahnhöfen
                stopA = validStops[p1Map.stopIndex]?.stop;
                stopB = validStops[p2Map.stopIndex]?.stop;
            }
        } else if (p1Map) {
            stopA = validStops[p1Map.stopIndex]?.stop;
            stopB = validStops[p1Map.stopIndex + 1]?.stop;
        }

        let html = `
            <div style="font-weight: bold; margin-bottom: 8px; font-size: 14px; border-bottom: 1px solid ${this.isDarkMode ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)'}; padding-bottom: 4px;">
                Zug ${train.train_number}
            </div>
        `;

        if (stopA || stopB) {
            const getStationCode = (stop) => {
                if (!stop) return '-';
                const config = stations.find(s => s.id === stop.station_id) ||
                               allStations.find(s => s.id === stop.station_id);
                return config?.abbr || config?.code || config?.short_name || config?.name || stop.station_id;
            };

            const formatTimeValue = (value) => value || '-';

            const formatDelayValue = (delay) => {
                if (delay === null || delay === undefined) return '<span style="opacity: 0.45;">-</span>';
                if (delay === 0) return '<span style="color: #94a3b8;">+0</span>';
                const color = delay > 0 ? '#ef4444' : '#22c55e';
                const sign = delay > 0 ? '+' : '';
                return `<span style="color: ${color}; font-weight: bold;">${sign}${delay}</span>`;
            };

            const formatTextCell = (value, extraStyle = '') => {
                if (!value) {
                    return `<td style="padding: 4px 6px; opacity: 0.55; ${extraStyle}">-</td>`;
                }
                return `<td style="padding: 4px 6px; ${extraStyle}">${value}</td>`;
            };

            const formatTimeCell = (soll, ist) => {
                const sollText = formatTimeValue(soll);
                const istText = formatTimeValue(ist);
                return `<td style="padding: 4px 6px; white-space: nowrap;">${sollText}</td>
                        <td style="padding: 4px 6px; white-space: nowrap;">${istText}</td>
                        <td style="padding: 4px 6px; white-space: nowrap; text-align: center;">${formatDelayValue(null)}</td>`;
            };

            const gridColor = this.isDarkMode ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.05)';
            const computeDelayMinutes = (sollTime, istTime) => {
                const sollMin = this.timeToMinutes(sollTime);
                const istMin = this.timeToMinutes(istTime);

                if (sollMin === null || istMin === null) return null;

                let diff = istMin - sollMin;
                if (diff < -720) diff += 1440;
                if (diff > 720) diff -= 1440;

                return diff;
            };

            html += `
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 12px;">
                    <thead>
                        <tr style="border-bottom: 1px solid ${this.isDarkMode ? 'rgba(255,255,255,0.15)' : 'rgba(0,0,0,0.1)'}; opacity: 0.7; font-size: 11px;">
                            <th style="padding: 4px 6px;">Station</th>
                            <th style="padding: 4px 6px;">SOLL-AN</th>
                            <th style="padding: 4px 6px;">IST-AN</th>
                            <th style="padding: 4px 6px;">ΔAN</th>
                            <th style="padding: 4px 6px;">SOLL-ab</th>
                            <th style="padding: 4px 6px;">IST-ab</th>
                            <th style="padding: 4px 6px;">Δab</th>
                            <th style="padding: 4px 6px;">Flags</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            // Filtert Duplikate aus, falls am Anfang/Ende der Strecke nur eine Station greift
            const displayStops = [stopA, stopB].filter((v, i, a) => v && a.indexOf(v) === i);

            displayStops.forEach((stop) => {
                html += `<tr style="border-bottom: 1px solid ${gridColor};">`;
                html += `<td style="font-weight: bold; padding: 6px 6px; max-width: 110px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${getStationCode(stop)}</td>`;
                const arrivalDelay = computeDelayMinutes(stop.arrival, stop.actual_arrival);
                let departureDelay = computeDelayMinutes(stop.departure, stop.actual_departure);

                if (departureDelay === null && stop.departure) {
                    const arrivalBaseDelay = arrivalDelay || 0;
                    const sollArrMin = this.timeToMinutes(stop.arrival);
                    const sollDepMin = this.timeToMinutes(stop.departure);

                    if (sollDepMin !== null) {
                        let sollStandzeit = 0;
                        if (sollArrMin !== null) {
                            sollStandzeit = Math.max(0, sollDepMin - sollArrMin);
                        }

                        const hasRFlag = /R/i.test(stop.flags || '');
                        const minStandzeit = hasRFlag ? 2 : 0;
                        const availableBraking = Math.max(0, sollStandzeit - minStandzeit);
                        const actualBraking = Math.min(Math.max(0, arrivalBaseDelay), availableBraking);
                        const remainingDelay = Math.max(0, arrivalBaseDelay - actualBraking);

                        departureDelay = remainingDelay;
                    }
                }

                html += `<td style="padding: 4px 6px; white-space: nowrap;">${formatTimeValue(stop.arrival)}</td>`;
                html += `<td style="padding: 4px 6px; white-space: nowrap;">${formatTimeValue(stop.actual_arrival)}</td>`;
                html += `<td style="padding: 4px 6px; white-space: nowrap; text-align: center;">${formatDelayValue(arrivalDelay)}</td>`;

                html += `<td style="padding: 4px 6px; white-space: nowrap;">${formatTimeValue(stop.departure)}</td>`;
                html += `<td style="padding: 4px 6px; white-space: nowrap;">${formatTimeValue(stop.actual_departure)}</td>`;
                html += `<td style="padding: 4px 6px; white-space: nowrap; text-align: center;">${formatDelayValue(departureDelay)}</td>`;

                const flagsText = [stop.flags, stop.remarks].filter(Boolean).join(' · ');
                html += formatTextCell(flagsText, 'font-size: 11px; opacity: 0.85; max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;');
                html += `</tr>`;
            });

            html += `
                    </tbody>
                </table>
            `;
        } else {
            html += `<div style="color: #94a3b8; font-size: 12px;">Kein Streckenabschnitt ermittelbar</div>`;
        }

        return html;
    }

    /**
     * Berechnet die Differenz zwischen zwei Zeitstrings in Minuten
     */
    getDelayMinutes(sollTime, istTime) {
        const sollMin = this.timeToMinutes(sollTime);
        const istMin = this.timeToMinutes(istTime);
        if (sollMin === null || istMin === null) return 0;

        let diff = istMin - sollMin;

        // Zeitangaben sind im Fahrplan oft auf denselben 24h-Rahmen bezogen.
        // Damit ein Vergleich über Mitternacht nicht als -1430 Minuten erscheint,
        // normalisieren wir auf den plausibelsten Tagesabstand.
        if (diff < -720) diff += 1440;
        if (diff > 720) diff -= 1440;

        return diff;
    }

    /**
     * Konvertiere Zeit "HH:MM" zu Minuten
     */
    timeToMinutes(timeStr) {
        if (!timeStr) return null;
        const parts = timeStr.split(':');
        if (parts.length !== 2) return null;
        const h = parseInt(parts[0], 10);
        const m = parseInt(parts[1], 10);
        if (isNaN(h) || isNaN(m)) return null;
        return h * 60 + m;
    }

    /**
     * Verstecke Popup
     */
    hidePopup() {
        if (this.popup) {
            this.popup.style.display = 'none';
        }
        this.isVisible = false;
    }

    /**
     * Cleanup
     */
    destroy() {
        this.detachListeners();
        if (this.popup) {
            this.popup.remove();
        }
    }
}