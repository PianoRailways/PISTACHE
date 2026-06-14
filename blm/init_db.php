<?php
// init_db.php
$db = new SQLite3('rcs_portal.db');

// Tabelle erstellen falls nicht existent
$db->exec("CREATE TABLE IF NOT EXISTS disturbances (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    incident_id TEXT NOT NULL,
    location_type TEXT NOT NULL,
    station_a TEXT NOT NULL,
    station_b TEXT,
    lines_affected TEXT, /* Neue Spalte für betroffene Linien */
    type TEXT NOT NULL,
    status TEXT NOT NULL,
    reason TEXT NOT NULL,
    impact TEXT,
    start_time TEXT NOT NULL,
    end_time TEXT NOT NULL,
    created_at TEXT NOT NULL
)");

// Sicherheits-Check: Spalte nachträglich hinzufügen, falls Tabelle schon existierte
$result = $db->query("PRAGMA table_info(disturbances)");
$has_lines = false;
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    if ($row['name'] === 'lines_affected') {
        $has_lines = true;
    }
}
if (!$has_lines) {
    $db->exec("ALTER TABLE disturbances ADD COLUMN lines_affected TEXT");
}

echo "Datenbank-Schema ist auf dem neuesten Stand.";
?>