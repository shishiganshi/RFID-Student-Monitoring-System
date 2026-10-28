<?php
date_default_timezone_set('Asia/Manila');
// Path: portal/php/get_all_logs_ajax.php
session_start();
header('Content-Type: application/json');

// --- Authentication Check (Optional but Recommended) ---
if (!isset($_SESSION['user_id']) || !($_SESSION['is_superuser'] ?? false) || !($_SESSION['is_admin'] ?? false)) {
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// --- Database Connection ---
$db = require_once(__DIR__ . '/db_connect.php'); 

// --- Fetch Logs ---
$accessLogs = [];
try {
    // --- *** MODIFIED QUERY *** ---
    // We added "l.captured_image_path" to the SELECT statement
    $accessQuery = "SELECT 
                        l.id, 
                        DATE_FORMAT(l.timestamp, '%b %d, %Y, %h:%i:%s %p') AS formatted_timestamp, 
                        l.rfid_uid, 
                        l.access_type, 
                        s.full_name,
                        l.captured_image_path  -- <<< ADDED THIS LINE
                    FROM access_logs l
                    LEFT JOIN students s ON l.user_id = s.id
                    ORDER BY l.timestamp DESC LIMIT 100"; 
    $accessStmt = $db->query($accessQuery);
    $accessLogs = $accessStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($accessLogs as &$log) {
        $log['event_type'] = 'rfid_scan';
        $log['timestamp'] = $log['formatted_timestamp']; 
        // captured_image_path is now automatically included
    } unset($log);
} catch (PDOException $e) { error_log("AJAX Error fetching access logs: " . $e->getMessage()); }

$intruderLogs = []; // This part is fine, it already fetches the image path
try {
    $intruderQuery = "SELECT intruder_id, DATE_FORMAT(timestamp, '%b %d, %Y, %h:%i:%s %p') AS formatted_timestamp, captured_image_path, alert_status
                      FROM intruders
                      ORDER BY timestamp DESC LIMIT 100"; 
    $intruderStmt = $db->query($intruderQuery);
    $intruderLogs = $intruderStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($intruderLogs as &$log) {
        $log['event_type'] = 'intruder_alert'; 
        $log['access_type'] = 'intruder';
        $log['full_name'] = null; 
        $log['rfid_uid'] = null; 
        $log['id'] = $log['intruder_id'];
        $log['timestamp'] = $log['formatted_timestamp']; 
    } unset($log);
} catch (PDOException $e) { error_log("AJAX Error fetching intruder logs: " . $e->getMessage()); }

// --- Merge and Sort ---
$mergedLogs = array_merge($accessLogs, $intruderLogs);
usort($mergedLogs, function($a, $b) {
    $timeA = strtotime($a['timestamp']);
    $timeB = strtotime($b['timestamp']);
    if ($timeA !== false && $timeB !== false) {
        return $timeB <=> $timeA; 
    }
    return strcmp($b['timestamp'], $a['timestamp']); 
});
$mergedLogs = array_slice($mergedLogs, 0, 100);

// --- Send JSON Response ---
if ($mergedLogs !== false) { 
    echo json_encode(['success' => true, 'logs' => $mergedLogs]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to retrieve logs.']);
}
?>