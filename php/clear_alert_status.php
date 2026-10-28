<?php
// Path: portal/php/clear_alert_status.php
header('Content-Type: application/json');
$db = require_once('db_connect.php'); // Uses PDO

$rfid_uid_to_verify = $_GET['rfid_uid'] ?? null;
$adminRfidUid = getenv('ADMIN_RFID_UID') ?: '';

if (empty($rfid_uid_to_verify)) {
     http_response_code(400); // Bad Request
     error_log("[clear_alert_status] Missing rfid_uid parameter.");
     echo json_encode(['success' => false, 'message' => 'Missing RFID UID parameter.']);
     exit;
}

$cleared = false;
$message = 'Alert status unchanged. Tag not authorized to clear.';
$isValidTag = false;

try {
    // --- ** MODIFIED: Check BOTH students AND admins table *** ---
    
    // 1. Check students table
    $checkStmt = $db->prepare("SELECT id FROM students WHERE rfid_uid = ? AND status = 'active' LIMIT 1");
    $checkStmt->execute([$rfid_uid_to_verify]);
    if ($checkStmt->fetch()) {
        $isValidTag = true;
    } else {
        // 2. If not a student, allow the locally configured administrator tag.
        if ($adminRfidUid !== '' && hash_equals($adminRfidUid, (string)$rfid_uid_to_verify)) {
             $isValidTag = true;
        }
    }
    // --- ** END MODIFICATION *** ---


    if ($isValidTag) {
        // --- Tag is valid (Student or Admin), clear the alert ---
        $updateStmt = $db->prepare("INSERT INTO global_state (state_key, state_value) VALUES ('intruder_alert_status', 'inactive') ON DUPLICATE KEY UPDATE state_value = 'inactive'");

        if ($updateStmt->execute()) {
            $cleared = true;
            $message = 'Alert status cleared by valid RFID.';
            error_log("[clear_alert_status] Intruder alert status cleared by an authorized RFID.");
        } else {
            $message = 'Server error: Could not update alert status in DB.';
            error_log("[clear_alert_status] Failed to update alert status in DB.");
        }
    } else {
        // --- RFID not valid or not active ---
        $message = 'Invalid or inactive RFID provided. Alert not cleared.';
        error_log("[clear_alert_status] Attempt to clear alert with an invalid or inactive RFID.");
    }

} catch (PDOException $e) {
    error_log("[clear_alert_status] DB Error: " . $e->getMessage());
    $message = 'Server error: Database operation failed.';
    if (!headers_sent()) { http_response_code(500); }
}

// Respond based on whether the operation intended to clear was successful
if ($cleared) {
    echo json_encode(['success' => true, 'message' => $message]);
} else {
    echo json_encode(['success' => false, 'message' => $message]);
}
?>