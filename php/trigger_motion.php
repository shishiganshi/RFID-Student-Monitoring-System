<?php
// Path: portal/php/trigger_motion.php (For automated motion sensor calls)
header('Content-Type: application/json');
date_default_timezone_set('Asia/Malina');
$db = require_once('db_connect.php'); // Uses PDO

// --- Configuration ---
$captureRequestFile = __DIR__ . '/../data/capture_request.json';
$dataDir = __DIR__ . '/../data';
define('COOLDOWN_SECONDS', 10); // Cooldown to prevent spamming the alert status

// --- Function to create capture request ---
// This tells the ESP32-CAM to take an intruder photo.
function triggerESPCamCaptureForIntruder() {
    global $captureRequestFile, $dataDir;
    $captureRequest = [
        'access_log_id' => 0, // 0 for intruder captures
        'student_photo_path' => null, // No student photo to compare
        'timestamp' => time()
    ];
    if (!is_dir($dataDir)) { @mkdir($dataDir, 0755, true); }
    if (@file_put_contents($captureRequestFile, json_encode($captureRequest)) === false) {
        error_log("[trigger_motion] Failed to write CAM capture request file: " . $captureRequestFile);
        return false;
    } else {
        error_log("[trigger_motion] Intruder capture request created.");
        return true;
    }
}

// --- Main Logic ---
$success = false;
$message = 'Motion event processed.';

try {
    // 1. Check current status and prevent spamming (Crucial for fixing your stuck LED issue)
    $checkStmt = $db->prepare("SELECT state_value, updated_at FROM global_state WHERE state_key = 'intruder_alert_status' LIMIT 1");
    $checkStmt->execute();
    $result = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($result && $result['state_value'] === 'active') {
        $lastUpdate = strtotime($result['updated_at']);
        // If the alert is already active and was updated less than 10s ago (likely noise), ignore.
        if ((time() - $lastUpdate) < COOLDOWN_SECONDS) {
            error_log("[trigger_motion] Alert already ACTIVE and within cooldown. Ignoring trigger.");
            // We must still trigger the camera to capture a new photo if motion continues.
             if (triggerESPCamCaptureForIntruder()) {
                 echo json_encode(['success' => true, 'message' => 'Alert already active, photo re-triggered.']);
             } else {
                 echo json_encode(['success' => true, 'message' => 'Alert active, failed to re-trigger CAM.']);
             }
            exit;
        }
    }
    
    // 2. Set alert status to 'active' in the database
    $updateStmt = $db->prepare("INSERT INTO global_state (state_key, state_value) VALUES ('intruder_alert_status', 'active') ON DUPLICATE KEY UPDATE state_value = 'active'");

    if ($updateStmt->execute()) {
        error_log("[trigger_motion] Intruder alert status set to ACTIVE in DB.");

        // 3. Trigger the ESP32-CAM capture
        if (triggerESPCamCaptureForIntruder()) {
            $success = true;
            $message = 'Motion alert activated and CAM triggered.';
        } else {
            $message = 'Alert activated but failed to trigger CAM.';
            if (!headers_sent()) { http_response_code(500); }
        }
    } else {
        error_log("[trigger_motion] Failed to update alert status in DB.");
        $message = 'Server error: Could not update alert status.';
        if (!headers_sent()) { http_response_code(500); }
    }

} catch (PDOException $e) {
    error_log("[trigger_motion] DB Error: " . $e->getMessage());
    $message = 'Server error: Database operation failed during motion trigger.';
    if (!headers_sent()) { http_response_code(500); }
}

echo json_encode(['success' => $success, 'message' => $message]);
?>