/**
 * Canvas Hover Tooltip System (erweitert mit Fahrplan-Ausschnitt und Light/Dark-Mode)
 * * Zeigt bei Mouseover über einer Zuglinie ein Popup mit:
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
     * Cache alle sichtbaren Zuglinien-Segmente.
     * Muss beim Leeren des Graphen mit [] aufgerufen werden.
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
        
        // 1. CSS-Rahmen und Padding abziehen
        const borderLeft = parseFloat(computedStyle.borderLeftWidth) || 0;
        const borderTop = parseFloat(computedStyle.borderTopWidth) || 0;
        const paddingLeft = parseFloat(computedStyle.paddingLeft) || 0;
        const paddingTop = parseFloat(computedStyle.paddingTop) || 0;
        
        let mouseX = event.clientX - rect.left - borderLeft - paddingLeft;
        let mouseY = event.clientY - rect.top - borderTop - paddingTop;
        
        // 2. Skalierung ausgleichen (falls CSS-Größe von interner Canvas-Größe abweicht)
        const contentWidth = rect.width - borderLeft - parseFloat(computedStyle.borderRightWidth) - paddingLeft - parseFloat(computedStyle.paddingRight);
        const contentHeight = rect.height - borderTop - parseFloat(computedStyle.borderBottomWidth) - paddingTop - parseFloat(computedStyle.paddingBottom);
        
        if (contentWidth > 0 && contentHeight > 0) {
            mouseX *= (this.canvas.width / contentWidth);
            mouseY *= (this.canvas.height / contentHeight);
        }

        // HINWEIS: Falls dein Graf ein internes Zeichen-Padding nutzt (z.B. GRAPH_PADDING = 40),
        // muss dieses hier abgezogen werden, um im selben Koordinatenraum zu liegen:
        // mouseX -= 40;
        // mouseY -= 40;

        // 3. Hit-Test im bereinigten Koordinatenraum ausführen
        const hit = this.findHitTrain(mouseX, mouseY);

        if (hit) {
            // Nutzen der stabilen Viewport-Koordinaten (clientX/Y) für die Platzierung des DOM-Elements
            this.showPopup(hit.train, hit.index, event.clientX, event.clientY);
        } else {
            this.hidePopup();
        }
    }

    /**
     * Prüfe, welches Segment der Maus am nächsten liegt (Minimalabstand)
     */
    findHitTrain(mouseX, mouseY) {
        let bestHit = null;
        let minDistance = this.hitThreshold;

        for (const segment of this.trainSegments) {
            if (!segment || !segment.points || segment.points.length < 2) continue;
            
            const points = segment.points;
            
            // Schleife läuft bis length - 1, da wir immer Paare (i und i+1) prüfen
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
                        index: i // Speichert das exakte Teilstück des Fahrplans
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

        // Positionierung relativ zum Viewport
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
            min-width: 280px;
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

        // Extraktion der beiden Stationen des aktuellen Linienabschnitts
        const stopA = train.stops[segmentIndex];
        const stopB = train.stops[segmentIndex + 1];

        let html = `
            <div style="font-weight: bold; margin-bottom: 8px; font-size: 14px; border-bottom: 1px solid ${this.isDarkMode ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)'}; padding-bottom: 4px;">
                Zug ${train.train_number}
            </div>
        `;

        if (stopA || stopB) {
            const stations = routesConfig[currentRouteId]?.stations || [];
            const allStations = Object.values(routesConfig).flatMap(r => r.stations || []);

            // Hilfsfunktion zur Ermittlung der Betriebsstellen-Abkürzung (z.B. DS100)
            const getStationCode = (stop) => {
                if (!stop) return '—';
                const config = stations.find(s => s.id === stop.station_id) ||
                               allStations.find(s => s.id === stop.station_id);
                return config?.code || config?.short_name || config?.name || stop.station_id;
            };

            // Formatierung der Soll/Ist-Zellen inklusive farblicher Kennzeichnung bei Abweichung
            const formatTimeCell = (soll, ist) => {
                if (!soll && !ist) return '<td style="padding: 4px 6px; opacity: 0.5;">—</td>';
                let cell = `<td style="padding: 4px 6px;">${soll || '—'}`;
                if (ist && ist !== soll) {
                    const delay = this.getDelayMinutes(soll, ist);
                    const color = delay > 0 ? '#ef4444' : '#22c55e'; // Rot bei Verspätung, Grün bei Vorzeitigkeit
                    cell += ` <span style="color: ${color}; font-size: 11px; font-weight: bold;">(${ist})</span>`;
                }
                cell += `</td>`;
                return cell;
            };

            const gridColor = this.isDarkMode ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.05)';

            html += `
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 12px;">
                    <thead>
                        <tr style="border-bottom: 1px solid ${this.isDarkMode ? 'rgba(255,255,255,0.15)' : 'rgba(0,0,0,0.1)'}; opacity: 0.7; font-size: 11px;">
                            <th style="padding: 4px 6px;">Station</th>
                            <th style="padding: 4px 6px;">AN (Ist)</th>
                            <th style="padding: 4px 6px;">AB (Ist)</th>
                            <th style="padding: 4px 6px;">Flags</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            // Zeige die zwei Stationen des aktuellen Abschnitts an
            [stopA, stopB].forEach((stop) => {
                if (!stop) return;
                html += `<tr style="border-bottom: 1px solid ${gridColor};">`;
                html += `<td style="font-weight: bold; padding: 6px 6px; max-width: 90px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${getStationCode(stop)}</td>`;
                html += formatTimeCell(stop.arrival, stop.actual_arrival);
                html += formatTimeCell(stop.departure, stop.actual_departure);
                html += `<td style="padding: 6px 6px; font-size: 11px; opacity: 0.8; max-width: 60px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${stop.flags || stop.remarks || '—'}</td>`;
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
        return istMin - sollMin;
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