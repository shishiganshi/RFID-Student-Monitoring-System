<?php
// Path: portal/php/get_registration_state.php
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

$regStateFile = __DIR__ . '/../data/registration_state.json';
$alertFile = __DIR__ . '/../data/manual_alert_trigger.json';

// --- Priority 1: Check for Manual Alert ---
if (file_exists($alertFile)) {
    $alertContent = @file_get_contents($alertFile);
    @unlink($alertFile); // Delete the file immediately after reading
    
    if ($alertContent) {
        $data = json_decode($alertContent, true);
        // Check if file is recent (within 10 seconds)
        if ($data && isset($data['timestamp']) && (time() - $data['timestamp']) < 10) {
            echo json_encode(['status' => 'manual_alert']);
            exit;
        }
    }
}

// --- Priority 2: Check for Registration Mode ---
if (file_exists($regStateFile)) {
    $regContent = @file_get_contents($regStateFile);
    if ($regContent) {
        $data = json_decode($regContent, true);
        // Check if file is recent (within 60 seconds)
        if ($data && isset($data['timestamp']) && (time() - $data['timestamp']) < 60) {
            if ($data['status'] == 'waiting_scan') {
                echo json_encode(['status' => 'waiting_scan']);
                exit;
            }
        } else {
            // Stale file, delete it
            @unlink($regStateFile);
        }
    }
}

// --- Default Response: Idle ---
echo json_encode(['status' => 'idle']);
exit;
?>