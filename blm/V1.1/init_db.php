<?php
// init_db.php
$db = new SQLite3('rcs_portal.db');

$query = "CREATE TABLE IF NOT EXISTS disturbances (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    incident_id TEXT NOT NULL,  /* Gleiche ID für alle Versionen einer Störung */
    location_type TEXT NOT NULL, /* 'station' oder 'line' */
    station_a TEXT NOT NULL,
    station_b TEXT,             /* Nur bei Strecke gefüllt */
    type TEXT NOT NULL,         /* 'info' (Blau), 'construction' (Orange), 'disruption' (Rot) */
    status TEXT NOT NULL,       /* 'restricted' (eingeschränkt), 'interrupted' (unterbrochen) */
    reason TEXT NOT NULL,
    impact TEXT,
    start_time TEXT NOT NULL,
    end_time TEXT NOT NULL,
    created_at TEXT NOT NULL    /* Zeitstempel der Versionierung */
)";

$db->exec($query);
echo "Datenbank erfolgreich für Versionierung aktualisiert.";
?>