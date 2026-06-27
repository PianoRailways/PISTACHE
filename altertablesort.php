<?php
// Pfad zur Datenbank anpassen
$db = new PDO("sqlite:" . __DIR__ . "/dbs/fahrplan.sqlite");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1. Spalte hinzufügen (mit Fehlerbehandlung)
try {
    $db->exec("ALTER TABLE timetable ADD COLUMN stop_order INTEGER DEFAULT 0");
    echo "Spalte stop_order wurde hinzugefügt.<br>";
} catch (Exception $e) {
    echo "Spalte existierte bereits, fahre fort...<br>";
}

// 2. Halte sortieren und befüllen
$trains = $db->query("SELECT id FROM trains")->fetchAll(PDO::FETCH_COLUMN);

foreach ($trains as $train_id) {
    // Hol alle Halte in der aktuellen Reihenfolge (ID-basiert)
    $stmt = $db->prepare("SELECT id FROM timetable WHERE train_id = ? ORDER BY id ASC");
    $stmt->execute([$train_id]);
    $stops = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Zähle sie sauber durch
    $order = 1;
    foreach ($stops as $timetable_id) {
        $update = $db->prepare("UPDATE timetable SET stop_order = ? WHERE id = ?");
        $update->execute([$order, $timetable_id]);
        $order++;
    }
}
echo "Datenbank-Struktur und -Inhalte für stop_order wurden erfolgreich korrigiert.";
?>