<?php
// Path: portal/php/reject_log.php
date_default_timezone_set('Asia/Manila');
session_start();
header('Content-Type: application/json');

// Auth check (must be logged in)
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$db = require_once('db_connect.php'); 
$log_id = $_POST['log_id'] ?? null;

if (empty($log_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing Log ID.']);
    exit;
}

try {
    $db->beginTransaction();

    // 1. Get the log details (student_id and original status)
    $logStmt = $db->prepare("SELECT student_id, status FROM attendance_logs WHERE log_id = ?");
    $logStmt->execute([$log_id]);
    $log = $logStmt->fetch(PDO::FETCH_ASSOC);

    if (!$log) {
        throw new Exception('Log not found in attendance_logs.');
    }

    $student_id = $log['student_id'];
    $original_status = $log['status'];

    // 2. [FIX] Suspend the student in the 'students' table
    if ($student_id) {
        $suspendStmt = $db->prepare("UPDATE students SET status = 'suspended' WHERE id = ?");
        $suspendStmt->execute([$student_id]);
    }

    // 3. [FIX] Mark log as fraud AND revert state if it was an 'out' log
    if ($original_status === 'present') {
        // It was a fraudulent 'IN' tap. Just mark it.
        $updateLogStmt = $db->prepare("UPDATE attendance_logs SET status = 'questionable_fraud' WHERE log_id = ?");
        $updateLogStmt->execute([$log_id]);
        
    } elseif ($original_status === 'out') {
        // It was a fraudulent 'OUT' tap. REVERT IT.
        // Set time_out back to NULL and mark as fraud.
        // This keeps the student's *previous* 'present' status.
        $updateLogStmt = $db->prepare("UPDATE attendance_logs SET status = 'questionable_fraud', time_out = NULL WHERE log_id = ?");
        $updateLogStmt->execute([$log_id]);
    }

    $db->commit();
    
    // 4. [FROM YOUR FILE] Update the live_event.json file
    $liveEventFile = __DIR__ . '/../data/live_event.json';
    if (file_exists($liveEventFile)) {
        $jsonContent = @file_get_contents($liveEventFile);
        if ($jsonContent) {
            $eventData = json_decode($jsonContent, true);
            if (isset($eventData['log_id']) && $eventData['log_id'] == $log_id) {
                $eventData['action'] = 'denied'; 
                // Let's use a more descriptive name
                if(isset($eventData['student_data']['full_name'])) {
                    $eventData['student_data']['full_name'] = 'REJECTED: ' . $eventData['student_data']['full_name'];
                } else {
                    $eventData['student_data']['full_name'] = 'REJECTED';
                }
                @file_put_contents($liveEventFile, json_encode($eventData), LOCK_EX);
            }
        }
    }
    
    // 5. [FROM YOUR FILE] Create the manual alert trigger file for the ESP32
    $alertFile = __DIR__ . '/../data/manual_alert_trigger.json';
    $alertData = [
        'status' => 'alert',
        'timestamp' => time()
    ];
    @file_put_contents($alertFile, json_encode($alertData));

    echo json_encode(['success' => true, 'message' => 'Log rejected and student suspended.']);

} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    error_log("Failed to reject log: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>