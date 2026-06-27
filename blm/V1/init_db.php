<?php
// init_db.php
$db = new SQLite3('rcs_portal.db');

$query = "CREATE TABLE IF NOT EXISTS disturbances (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    station TEXT NOT NULL,
    type TEXT NOT NULL, -- 'strike' (Rot) oder 'construction' (Blau)
    reason TEXT NOT NULL,
    impact TEXT,
    start_time TEXT NOT NULL, -- Format: YYYY-MM-DD HH:MM
    end_time TEXT NOT NULL
)";

$db->exec($query);
echo "Datenbank erfolgreich initialisiert.";
?>