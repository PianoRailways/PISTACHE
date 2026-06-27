/**
 * PISTACHE Tooltip-System
 * Eigenständiges Modul für Zugs-Hover und Tooltip-Anzeige
 * 
 * Integration:
 *   1. <script src="tooltip.js"></script> laden
 *   2. trainLineCache.clear() aufrufen vor renderGraph()
 *   3. trainLineCache.add(trainNumber, istPoints, sollPoints, color) bei jedem Zug
 *   4. attachCanvasHoverListener(canvas) am Ende von renderGraph()
 */

// ========================================================================
// TOOLTIP-CACHE & ELEMENT
// ========================================================================

const trainLineCache = {
    data: [],
    
    add(trainNumber, istPoints, sollPoints, color) {
        this.data.push({
            trainNumber,
            istPoints,
            sollPoints,
            color
        });
    },
    
    clear() {
        this.data = [];
    },
    
    findByPoint(px, py, tolerance = 10) {
        for (const trainData of this.data) {
            if (isPointNearLine(px, py, trainData.istPoints, tolerance)) {
                return trainData;
            }
        }
        return null;
    }
};

// Tooltip-DOM-Element erstellen (einmalig)
const tooltipElement = (() => {
    const div = document.createElement('div');
    div.id = 'train_tooltip';
    div.style.cssText = `
        position: fixed;
        background: #1e293b;
        color: #f1f5f9;
        padding: 8px 12px;
        border: 1px solid #475569;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
        pointer-events: none;
        display: none;
        z-index: 9999;
        white-space: nowrap;
        box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    `;
    
    // Nur einmal in den DOM einfügen, wenn Dokument ready
    if (document.body) {
        document.body.appendChild(div);
    } else {
        document.addEventListener('DOMContentLoaded', () => {
            if (!div.parentElement) document.body.appendChild(div);
        });
    }
    
    return div;
})();

// ========================================================================
// HILFSFUNKTIONEN: PUNKT-ZU-LINIE DISTANZ
// ========================================================================

/**
 * Berechnet die kürzeste Distanz von einem Punkt zu einem Liniensegment
 * @param {number} px - Punkt X
 * @param {number} py - Punkt Y
 * @param {number} x1 - Segment Start X
 * @param {number} y1 - Segment Start Y
 * @param {number} x2 - Segment End X
 * @param {number} y2 - Segment End Y
 * @returns {number} Distanz in Pixeln
 */
function pointToSegmentDistance(px, py, x1, y1, x2, y2) {
    const dx = x2 - x1;
    const dy = y2 - y1;
    const len = Math.sqrt(dx * dx + dy * dy);
    
    // Wenn das Segment ein Punkt ist (x1==x2 && y1==y2)
    if (len === 0) {
        return Math.sqrt((px - x1) * (px - x1) + (py - y1) * (py - y1));
    }
    
    // Berechne t: Position des nächsten Punkts auf dem Segment (0...1)
    let t = ((px - x1) * dx + (py - y1) * dy) / (len * len);
    t = Math.max(0, Math.min(1, t));
    
    // Closest point on segment
    const closestX = x1 + t * dx;
    const closestY = y1 + t * dy;
    
    return Math.sqrt((px - closestX) * (px - closestX) + (py - closestY) * (py - closestY));
}

/**
 * Prüft, ob ein Punkt in der Nähe einer Zuglinie liegt
 * @param {number} px - Maus X
 * @param {number} py - Maus Y
 * @param {Array} points - Array von {x, y} Punkten
 * @param {number} tolerance - Pixel-Toleranz (default: 10)
 * @returns {boolean}
 */
function isPointNearLine(px, py, points, tolerance = 10) {
    if (!points || points.length < 2) return false;
    
    for (let i = 0; i < points.length - 1; i++) {
        const p1 = points[i];
        const p2 = points[i + 1];
        
        // Überspringe null-Punkte (außerhalb des Grafen)
        if (!p1 || !p2 || p1.y === null || p2.y === null) continue;
        
        const dist = pointToSegmentDistance(px, py, p1.x, p1.y, p2.x, p2.y);
        if (dist < tolerance) return true;
    }
    
    return false;
}

// ========================================================================
// TOOLTIP-ANZEIGE
// ========================================================================

/**
 * Zeigt Tooltip mit Zugnummer und Farbe
 * @param {string} trainNumber - Zugnummer (z.B. "421")
 * @param {string} color - Hex-Farbe (z.B. "#dc2626")
 * @param {number} x - Mouse X in Viewport
 * @param {number} y - Mouse Y in Viewport
 */
function showTrainTooltip(trainNumber, color, x, y) {
    tooltipElement.innerHTML = `
        <span style="color: ${color}; font-weight: bold; margin-right: 6px;">●</span>
        Zug <strong>${trainNumber}</strong>
    `;
    tooltipElement.style.left = (x + 12) + 'px';
    tooltipElement.style.top = (y - 28) + 'px';
    tooltipElement.style.display = 'block';
}

/**
 * Versteckt Tooltip
 */
function hideTrainTooltip() {
    tooltipElement.style.display = 'none';
}

// ========================================================================
// CANVAS-HOVER-LISTENER
// ========================================================================

/**
 * Registriert Mousemove und Mouseleave auf Canvas für Hover-Effekte
 * @param {HTMLCanvasElement} canvas - Das Grafen-Canvas
 */
function attachCanvasHoverListener(canvas) {
    if (!canvas) {
        console.warn('[Tooltip] Canvas nicht gefunden');
        return;
    }
    
    // Entferne alte Listener (falls vorhanden)
    const newCanvas = canvas.cloneNode(true);
    canvas.parentNode.replaceChild(newCanvas, canvas);
    canvas = newCanvas;
    
    canvas.addEventListener('mousemove', (e) => {
        const rect = canvas.getBoundingClientRect();
        const mouseX = e.clientX - rect.left;
        const mouseY = e.clientY - rect.top;
        
        // Suche nach Zuglinie unter der Maus
        const hoveredTrain = trainLineCache.findByPoint(mouseX, mouseY, 10);
        
        if (hoveredTrain) {
            canvas.style.cursor = 'pointer';
            showTrainTooltip(hoveredTrain.trainNumber, hoveredTrain.color, e.clientX, e.clientY);
        } else {
            canvas.style.cursor = 'default';
            hideTrainTooltip();
        }
    });
    
    canvas.addEventListener('mouseleave', () => {
        canvas.style.cursor = 'default';
        hideTrainTooltip();
    });
}

// ========================================================================
// EXPORT (für Module, falls nötig)
// ========================================================================

if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        trainLineCache,
        tooltipElement,
        pointToSegmentDistance,
        isPointNearLine,
        showTrainTooltip,
        hideTrainTooltip,
        attachCanvasHoverListener
    };
}