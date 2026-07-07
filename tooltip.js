/**
 * Canvas Hover Tooltip System (erweitert mit Light/Dark-Mode)
 * 
 * Zeigt bei Mouseover über einer Zuglinie ein Popup mit:
 * - Zugnummer
 * - Aktueller Position (nächste Station)
 * - Verspätung
 * - Soll/Ist-Zeit
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
        
        // Höre auf Mode-Wechsel
        const darkModeQuery = window.matchMedia('(prefers-color-scheme: dark)');
        darkModeQuery.addEventListener('change', this.boundColorSchemeChange);
    }

    /**
     * Detach Listener (falls nötig)
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
        this.trainSegments = trainSegments;
    }

    /**
     * Handle Mousemove: Hit-Test durchführen
     */
    handleMouseMove(event) {
        const rect = this.canvas.getBoundingClientRect();
        const mouseX = event.clientX - rect.left;
        const mouseY = event.clientY - rect.top;

        const hit = this.findHitTrain(mouseX, mouseY);

        if (hit) {
            this.showPopup(hit.train, hit.point, mouseX, mouseY);
        } else {
            this.hidePopup();
        }
    }

    /**
     * Prüfe, ob Maus über einer Zuglinie liegt
     */
    findHitTrain(mouseX, mouseY) {
        for (const segment of this.trainSegments) {
            const points = segment.points;
            
            for (let i = 0; i < points.length; i++) {
                const p1 = points[i];
                const p2 = points[i + 1];

                if (!p1 || !p2) continue;

                const distToP1 = Math.hypot(mouseX - p1.x, mouseY - p1.y);
                if (distToP1 < this.hitThreshold) {
                    return { train: segment.train, point: p1 };
                }

                const dist = this.distanceToLineSegment(
                    mouseX, mouseY,
                    p1.x, p1.y,
                    p2.x, p2.y
                );
                if (dist < this.hitThreshold) {
                    const d1 = Math.hypot(mouseX - p1.x, mouseY - p1.y);
                    const d2 = Math.hypot(mouseX - p2.x, mouseY - p2.y);
                    return {
                        train: segment.train,
                        point: d1 < d2 ? p1 : p2
                    };
                }
            }
        }
        return null;
    }

    /**
     * Berechne Entfernung Punkt zu Linie
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
     * Erstelle/zeige Popup mit Mode-spezifischen Styles
     */
    showPopup(train, point, mouseX, mouseY) {
        if (!this.popup) {
            this.popup = document.createElement('div');
            this.popup.id = 'canvas-tooltip';
            document.body.appendChild(this.popup);
        }

        const popupHTML = this.formatPopupContent(train, point);
        this.popup.innerHTML = popupHTML;

        // Styles basierend auf Dark/Light Mode
        this.applyModeStyles();

        // Position
        const offsetX = 15;
        const offsetY = 10;
        this.popup.style.left = (mouseX + offsetX) + 'px';
        this.popup.style.top = (mouseY + offsetY) + 'px';
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
            padding: 12px 16px;
            border-radius: 6px;
            font-size: 13px;
            pointer-events: none;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            transition: background 0.2s, color 0.2s, border 0.2s;
        `;

        if (this.isDarkMode) {
            this.popup.style.cssText = `
                ${commonStyles}
                background: rgba(30, 41, 59, 0.95);
                color: #f1f5f9;
                border: 1px solid #0ea5e9;
            `;
        } else {
            this.popup.style.cssText = `
                ${commonStyles}
                background: rgba(255, 255, 255, 0.95);
                color: #1e293b;
                border: 1px solid #3b82f6;
            `;
        }
    }

    /**
     * Formatiere Popup-Inhalt
     */
    formatPopupContent(train, point) {
        if (!train || !train.stops) return '';

        const nextStop = this.findNextStop(train, point);
        const delay = this.calculateDelay(train, nextStop);

        let html = `
            <div style="font-weight: bold; margin-bottom: 6px; font-size: 14px;">
                Zug ${train.train_number}
            </div>
        `;

        if (nextStop) {
            html += `
                <div style="margin-bottom: 4px;">
                    <strong>Station:</strong> ${nextStop.station_name || 'Unbekannt'}
                </div>
            `;

            if (nextStop.arrival || nextStop.actual_arrival) {
                html += `
                    <div style="margin-bottom: 2px; font-size: 12px;">
                        <strong>Soll Ank.:</strong> ${nextStop.arrival || '–'}
                    </div>
                `;
            }

            if (nextStop.actual_arrival) {
                html += `
                    <div style="margin-bottom: 2px; font-size: 12px;">
                        <strong>Ist Ank.:</strong> ${nextStop.actual_arrival}
                    </div>
                `;
            }

            if (delay !== null) {
                const delayColor = delay > 0 ? '#ef4444' : delay < 0 ? '#22c55e' : '#cbd5e1';
                const delaySign = delay > 0 ? '+' : '';
                const borderColor = this.isDarkMode ? 'rgba(255, 222, 21, 0.3)' : 'rgba(59, 130, 246, 0.2)';
                
                html += `
                    <div style="margin-top: 6px; padding-top: 6px; border-top: 1px solid ${borderColor};">
                        <span style="color: ${delayColor}; font-weight: bold;">
                            Verspätung: ${delaySign}${delay} min
                        </span>
                    </div>
                `;
            }
        } else {
            html += `<div style="color: #94a3b8; font-size: 12px;">Keine Stationen zugeordnet</div>`;
        }

        return html;
    }

    /**
     * Finde nächste Station zum aktuellen Punkt
     */
    findNextStop(train, point) {
        if (!train.stops) return null;

        const sortedStops = [...train.stops].sort((a, b) => {
            const timeA = this.timeToMinutes(a.departure || a.arrival);
            const timeB = this.timeToMinutes(b.departure || b.arrival);
            return timeA - timeB;
        });

        let closest = null;
        let minTimeDist = Infinity;

        for (const stop of sortedStops) {
            if (!stop.arrival && !stop.departure) continue;

            const stopTime = this.timeToMinutes(stop.departure || stop.arrival);
            if (stopTime === null) continue;

            if (!closest) {
                closest = stop;
            }
        }

        if (closest) {
            const stationId = closest.station_id;
            const stations = routesConfig[currentRouteId]?.stations || [];
            const station = stations.find(s => s.id === stationId) ||
                           Object.values(routesConfig)
                               .flatMap(r => r.stations || [])
                               .find(s => s.id === stationId);

            return {
                station_name: station?.name || `Station ${stationId}`,
                arrival: closest.arrival || null,
                actual_arrival: closest.actual_arrival || null,
                departure: closest.departure || null,
                actual_departure: closest.actual_departure || null
            };
        }

        return null;
    }

    /**
     * Berechne Verspätung in Minuten
     */
    calculateDelay(train, stop) {
        if (!stop) return null;

        const sollTime = stop.arrival || stop.departure;
        const istTime = stop.actual_arrival || stop.actual_departure;

        if (!sollTime || !istTime) return null;

        const sollMin = this.timeToMinutes(sollTime);
        const istMin = this.timeToMinutes(istTime);

        if (sollMin === null || istMin === null) return null;

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