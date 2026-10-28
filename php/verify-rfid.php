<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Asia/Manila');
session_start();
header('Content-Type: application/json');

// --- Configuration ---
$adminRfidUid = getenv('ADMIN_RFID_UID') ?: '';
$dataDir = __DIR__ . '/../data';
$ip_file = $dataDir . '/esp32_ip.txt';
$captureRequestFile = $dataDir . '/capture_request.json';
$liveEventFile = $dataDir . '/live_event.json';
$stateFile = $dataDir . '/rfid_state.json'; // Your login script needs this

// ===================================================
// --- FUNCTION DEFINITIONS ---
// ===================================================
function updateESP32IP($ip) { global $ip_file; @file_put_contents($ip_file, $ip); }
function buildPhotoUrl($photo_path) {
    if (empty($photo_path)) { return null; }
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $script_dir = dirname($_SERVER['SCRIPT_NAME']);
    $base_path = dirname($script_dir);
    if ($base_path == '/' || $base_path == '\\') $base_path = '';
    $clean_path = ltrim(rtrim($photo_path, '/'), '/');
    return $protocol . '://' . $host . $base_path . '/' . $clean_path;
}
function triggerESPCamCapture($logId, $studentPhotoPath = null) {
    global $captureRequestFile, $dataDir;
    $captureRequest = [ 'access_log_id' => $logId, 'student_photo_path' => $studentPhotoPath, 'timestamp' => time() ];
    if (!is_dir($dataDir)) { @mkdir($dataDir, 0755, true); }
    if (@file_put_contents($captureRequestFile, json_encode($captureRequest)) === false) {
        error_log("Failed to write CAM capture request file: " . $captureRequestFile);
    } else { error_log("Capture request created for attendance/log ID: $logId"); }
}
function requestAlertClear() {
    $clearAlertUrl = 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/clear_alert_status.php';
    $clearResultContext = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 2]]);
    @file_get_contents($clearAlertUrl, false, $clearResultContext);
}
function getESP32State() {
    global $stateFile, $lastStateCheck;
    if ((microtime(true) - $lastStateCheck) < 0.5) { return null; }
    $lastStateCheck = microtime(true);
    if (!file_exists($stateFile)) { return null; }
    $content = @file_get_contents($stateFile);
    if (!$content) { return null; }
    $state = json_decode($content, true);
    if (!$state || !isset($state['timestamp']) || (time() - $state['timestamp']) > 5) {
        if (file_exists($stateFile)) { @unlink($stateFile); }
        return null;
    }
    return $state;
}
function writeLiveEvent($eventData) {
    global $liveEventFile, $dataDir;
    $eventData['timestamp'] = microtime(true); 
    if (!is_dir($dataDir)) { @mkdir($dataDir, 0777, true); }
    if (@file_put_contents($liveEventFile, json_encode($eventData), LOCK_EX) === false) {
        error_log("Failed to write live event file: " . $liveEventFile);
    } else { error_log("Live event file written for log ID: " . ($eventData['log_id'] ?? 'N/A')); }
}
// --- End Function Definitions ---

try {
    // 1. Database Connection
    $db = require_once('db_connect.php'); 

    // 2. Directory Checks
    if (!is_dir($dataDir)) { if (!@mkdir($dataDir, 0777, true)) { throw new Exception("CRITICAL: Data directory '$dataDir' does not exist and could not be created."); } }
    if (!is_writable($dataDir)) { throw new Exception("CRITICAL: Data directory '$dataDir' is NOT WRITABLE."); }
    
    // --- HANDLE GET REQUESTS (This is YOUR working login logic) ---
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $esp_ip = file_exists($ip_file) ? trim(file_get_contents($ip_file)) : "";
        
        if (isset($_SERVER['HTTP_X_RFID_SOURCE']) && $_SERVER['HTTP_X_RFID_SOURCE'] === 'ESP32') {
            updateESP32IP($_SERVER['REMOTE_ADDR']);
            echo json_encode(['status' => 'ESP32 IP updated', 'esp32_ip' => $esp_ip]);
            exit;
        }
        
        // This is what rfid-auth.js checks
        if (isset($_SESSION['user_id']) && ($_SESSION['is_superuser'] ?? false)) {
            echo json_encode([ 'success' => true, 'is_superuser' => true, 'message' => 'Admin session active.', 'session_id' => session_id(), 'esp32_ip' => $esp_ip, 'student_data' => $_SESSION['student_data'] ?? null ]);
            exit;
        }
        
        $state = getESP32State();
        if ($state && ($state['authorized'] ?? false)) {
            $isAdminScan = ($state['is_admin'] ?? false);
            $studentData = $state['student_data'] ?? null;
            if ($isAdminScan) {
                // Create the session
                $_SESSION['user_id'] = $studentData['id'] ?? 'admin_id';
                $_SESSION['is_superuser'] = true;
                $_SESSION['is_admin'] = true;
                $_SESSION['student_data'] = $studentData;
                if (file_exists($stateFile)) { @unlink($stateFile); }
                // Send the success signal to rfid-auth.js
                echo json_encode([ 'success' => true, 'is_superuser' => true, 'message' => 'Admin login successful.', 'session_id' => session_id(), 'esp3a_ip' => $esp_ip, 'student_data' => $studentData ]);
            } else {
                if (file_exists($stateFile)) { @unlink($stateFile); }
                echo json_encode([ 'success' => false, 'is_superuser' => false, 'message' => 'Student scan detected. Access restricted.', 'session_id' => session_id(), 'esp32_ip' => $esp_ip, 'student_data' => $studentData ]);
            }
            exit;
        } else {
            echo json_encode([ 'success' => false, 'is_superuser' => false, 'message' => 'Waiting for RFID scan...', 'esp32_ip' => $esp_ip, 'session_id' => session_id() ]);
        }
        exit;
    }

    // --- HANDLE POST REQUESTS (for RFID taps) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (!$data || !isset($data['rfid_uid'])) {
            http_response_code(400); throw new Exception('Invalid or missing rfid_uid in POST data');
        }

        updateESP32IP($_SERVER['REMOTE_ADDR']);
        $rfid_uid = $data['rfid_uid'];
        error_log("Received RFID verification request.");

        // 1. --- CHECK ADMIN RFID ---
        if ($adminRfidUid !== '' && hash_equals($adminRfidUid, (string)$rfid_uid)) {
            error_log("Admin RFID UID matched.");
            requestAlertClear(); 
            $adminData = [ 'id' => null, 'full_name' => 'Administrator', 'rfid_uid' => $rfid_uid, 'photo_path' => null ];
            // This is your login logic
            $state = [ 'authorized' => true, 'is_admin' => true, 'student_data' => $adminData, 'timestamp' => time() ];
            if (@file_put_contents($stateFile, json_encode($state)) === false) {
                error_log("Failed to write admin RFID state file: " . $stateFile);
            }
            // --- [NEW] Write to live feed for admin ---
            $adminData['photo_url'] = null; $adminData['department'] = 'System';
            writeLiveEvent([ 'success' => true, 'action' => 'login', 'log_id' => null, 'student_data' => $adminData ]);
            
            echo json_encode([ 'success' => true, 'message' => 'Admin scan recognized.', 'action' => 'login', 'student_data' => $adminData ]);
            exit; 
        }

        // 2. --- [FIXED] STUDENT check (This query now matches your DB) ---
        $sql = "SELECT s.id, s.student_number, s.year_section, s.rfid_uid, s.photo_path,
                       CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name, s.suffix) AS full_name,
                       d.name AS department
                FROM students s
                LEFT JOIN departments d ON s.department_id = d.id
                WHERE s.rfid_uid = ? AND s.status = 'active'";
        $stmt = $db->prepare($sql);
        $stmt->execute([$rfid_uid]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($student) { // --- Valid STUDENT RFID Found ---
            $student_id = $student['id'];
            requestAlertClear(); 
            $today_start = date('Y-m-d 00:00:00'); $today_end = date('Y-m-d 23:59:59');
            
            // --- [THIS IS THE FIX] ---
            // We ONLY look for logs with status = 'present'
            // This will now IGNORE 'questionable_fraud' logs.
            $checkStmt = $db->prepare("SELECT log_id FROM attendance_logs WHERE student_id = ? AND time_out IS NULL AND status = 'present' AND time_in BETWEEN ? AND ? ORDER BY time_in DESC LIMIT 1");
            // --- [END OF FIX] ---

            $checkStmt->execute([$student_id, $today_start, $today_end]);
            $existingLog = $checkStmt->fetch(PDO::FETCH_ASSOC);
            $action = null; $currentLogId = null;

            if ($existingLog) {
                $action = "logout";
                $currentLogId = $existingLog['log_id'];
                $updateStmt = $db->prepare("UPDATE attendance_logs SET time_out = NOW(), status = 'out' WHERE log_id = ?");
                if ($updateStmt->execute([$currentLogId])) {
                    triggerESPCamCapture($currentLogId, $student['photo_path']);
                }
            } else {
                $action = "login";
                $insertStmt = $db->prepare("INSERT INTO attendance_logs (student_id, time_in, status) VALUES (?, NOW(), 'present')");
                if ($insertStmt->execute([$student_id])) {
                    $currentLogId = $db->lastInsertId();
                    triggerESPCamCapture($currentLogId, $student['photo_path']);
                }
            }
            $student['photo_url'] = buildPhotoUrl($student['photo_path']);
            // --- [NEW] Write to live feed for student ---
            writeLiveEvent([ 'success' => true, 'action' => $action, 'log_id' => $currentLogId, 'student_data' => $student ]);
            
            echo json_encode([ 'success' => true, 'message' => ($action == "logout" ? 'Logged out' : 'Logged in'), 'action' => $action, 'student_data' => $student ]);
        } else { // --- Invalid RFID ---
            error_log("No active student or administrator matched the submitted RFID.");
            $deniedLogId = null;
            try {
                $logStmt = $db->prepare("INSERT INTO access_logs (user_id, rfid_uid, access_type) VALUES (?, ?, ?)");
                $logStmt->execute([null, $rfid_uid, 'denied']);
                $deniedLogId = $db->lastInsertId();
                if ($deniedLogId) { triggerESPCamCapture($deniedLogId, null); }
            } catch (PDOException $e) { error_log("Failed to insert DENIED log: " . $e->getMessage()); }
            
            // --- [NEW] Write to live feed for denied scan ---
            writeLiveEvent([ 'success' => false, 'action' => 'denied', 'log_id' => $deniedLogId,
                             'student_data' => [ 'full_name' => 'Access Denied', 'rfid_uid' => $rfid_uid,
                                                 'photo_url' => null, 'department' => 'Unknown' ] ]);

            echo json_encode(['success' => false, 'message' => 'Invalid RFID Tag']);
        }
    } else {
        throw new Exception("Invalid request method.");
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log("FATAL ERROR in verify-rfid.php: " . $e->getMessage());
    echo json_encode([ 
        'success' => false, 
        'message' => 'Server script error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
?>