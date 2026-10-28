<?php
// Path: portal/php/trigger_intruder_alert.php
header('Content-Type: application/json');

define('ALERT_STATUS_FILE', __DIR__ . '/../data/intruder_alert.json');
$dataDir = dirname(ALERT_STATUS_FILE);

// Ensure data directory exists
if (!is_dir($dataDir)) {
    if (!@mkdir($dataDir, 0755, true)) {
        http_response_code(500);
        error_log("Failed to create data directory for alert: " . $dataDir);
        echo json_encode(['success' => false, 'message' => 'Server error: Cannot create data directory.']);
        exit;
    }
}

// Set Alert Status File
$alertData = [
    'status' => 'active',
    'last_event_id' => 'manual_trigger', // Indicate it was triggered manually
    'timestamp' => time()
];

if (file_put_contents(ALERT_STATUS_FILE, json_encode($alertData))) {
    error_log("Intruder alert status MANUALLY set to ACTIVE.");
    echo json_encode(['success' => true, 'message' => 'Intruder alert manually activated.']);
} else {
    http_response_code(500);
    error_log("Failed to write manual intruder alert status file.");
    echo json_encode(['success' => false, 'message' => 'Server error: Could not activate alert.']);
}
?>