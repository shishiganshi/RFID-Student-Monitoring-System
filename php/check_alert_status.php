<?php
// Path: portal/php/check_alert_status.php
header('Content-Type: application/json');
$db = require_once('db_connect.php'); // Uses PDO

$alertStatus = 'inactive'; // Default status

try {
    // --- ** NEW: Query global_state table ---
    $stmt = $db->prepare("SELECT state_value FROM global_state WHERE state_key = 'intruder_alert_status' LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result && $result['state_value'] === 'active') {
        $alertStatus = 'active';
    }
    // If no row found or value is not 'active', it remains 'inactive'

} catch (PDOException $e) {
    error_log("[check_alert_status] DB Error: " . $e->getMessage());
    // Keep default status 'inactive' on error
}

echo json_encode(['status' => $alertStatus]);
?>