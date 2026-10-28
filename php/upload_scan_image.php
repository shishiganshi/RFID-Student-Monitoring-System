<?php
// Path: portal/php/upload_scan_image.php
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');
$db = require_once('db_connect.php'); // Uses PDO

// --- [FIXED] Configuration: Save to 'uploads/scans/' ---
define('UPLOAD_DIR', __DIR__ . '/../uploads/scans/');
define('UPLOAD_URL_PATH', 'uploads/scans/'); // Relative path for DB

// --- Input Validation ---
$log_id = $_POST['access_log_id'] ?? null; // CAM sends 'access_log_id'

if (empty($log_id)) {
    error_log("[upload_scan] Error: Missing log ID (expected 'access_log_id')");
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing log ID']);
    exit;
}
if (!isset($_FILES['imageFile']) || $_FILES['imageFile']['error'] != UPLOAD_ERR_OK) {
    $upload_error = $_FILES['imageFile']['error'] ?? 'No file uploaded';
    error_log("[upload_scan] Error: Image file upload error. Log ID: $log_id. Code: $upload_error");
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Image file upload error. Code: ' . $upload_error]);
    exit;
}

// --- Ensure Upload Directory Exists ---
if (!is_dir(UPLOAD_DIR)) {
    // We add 'true' to mkdir to create parent 'uploads' folder if it's also missing
    if (!@mkdir(UPLOAD_DIR, 0777, true)) { 
        http_response_code(500);
        error_log("[upload_scan] Failed to create upload directory: " + UPLOAD_DIR);
        echo json_encode(['success' => false, 'message' => 'Server error: Could not create directory.']);
        exit;
    }
}
if (!is_writable(UPLOAD_DIR)) {
     error_log("[upload_scan] Directory NOT WRITABLE: " . UPLOAD_DIR);
     http_response_code(500);
     echo json_encode(['success' => false, 'message' => 'Server error: Upload directory not writable.']);
     exit;
}


// --- Define Filenames ---
$photo = $_FILES['imageFile'];
$fileExtension = 'jpg'; // Assume JPEG from CAM
$uniqueFilename = 'scan_log_' . $log_id . '_' . uniqid() . '.' . $fileExtension;
$destination = UPLOAD_DIR . $uniqueFilename;
$web_path = UPLOAD_URL_PATH . $uniqueFilename; // Path to store in DB

error_log("[upload_scan] Received file for Log ID: $log_id. Saving to: $destination");

// --- Image Processing (Resize/Compress) ---
$max_width = 640; 
$original_image = @imagecreatefromjpeg($photo['tmp_name']);
if (!$original_image) {
    error_log("[upload_scan] Failed to create image from uploaded JPEG. Log ID: $log_id");
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: Failed to process image.']);
    exit;
}
$original_width = imagesx($original_image); $original_height = imagesy($original_image);
if ($original_width > $max_width) {
    $ratio = $max_width / $original_width;
    $new_width = $max_width; $new_height = intval($original_height * $ratio);
} else {
    $new_width = $original_width; $new_height = $original_height;
}
$resized_image = imagecreatetruecolor($new_width, $new_height);
imagecopyresampled($resized_image, $original_image, 0, 0, 0, 0, $new_width, $new_height, $original_width, $original_height);
$save_success = imagejpeg($resized_image, $destination, 85); 
imagedestroy($original_image); imagedestroy($resized_image);

if (!$save_success) {
    error_log("[upload_scan] FAILED to save processed image to: $destination for Log ID: $log_id");
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: Could not save processed image.']);
    exit;
}
error_log("[upload_scan] Processed image SAVED to: $destination for Log ID: $log_id");
// --- End Image Processing ---

// --- [REMOVED] Facial Scoring Logic ---

// --- [FIXED] Update the CORRECT log table ---
try {
    $checkStmt = $db->prepare("SELECT time_in, time_out FROM attendance_logs WHERE log_id = ?");
    $checkStmt->execute([$log_id]);
    $logEntry = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($logEntry) {
        // --- IT'S A VALID STUDENT (attendance_logs) ---
        $isLogin = ($logEntry['time_out'] === null);
        if ($isLogin) {
            $updateStmt = $db->prepare("UPDATE attendance_logs SET image_in_path = ?, status = 'present' WHERE log_id = ?");
            $updateStmt->execute([$web_path, $log_id]);
            $log_status = 'present';
        } else {
            $updateStmt = $db->prepare("UPDATE attendance_logs SET image_out_path = ?, status = 'out' WHERE log_id = ?");
            $updateStmt->execute([$web_path, $log_id]);
            $log_status = 'out';
        }
        error_log("[upload_scan] DB updated attendance_log ID: $log_id. Status: $log_status");
        updateLiveEventWithCapture($log_id, $web_path); 
        echo json_encode([ 'success' => true, 'message' => 'Image processed and attendance log updated.', 'path' => $web_path, 'log_status' => $log_status ]);
    } else {
        // --- IT'S A DENIED SCAN (access_logs) ---
        $checkDeniedStmt = $db->prepare("SELECT id FROM access_logs WHERE id = ? AND access_type = 'denied'");
        $checkDeniedStmt->execute([$log_id]);
        $deniedLogEntry = $checkDeniedStmt->fetch(PDO::FETCH_ASSOC);

        if ($deniedLogEntry) {
            $updateDeniedStmt = $db->prepare("UPDATE access_logs SET captured_image_path = ? WHERE id = ?");
            $updateDeniedStmt->execute([$web_path, $log_id]);
            error_log("[upload_scan] DB updated access_log (DENIED) ID: $log_id.");
            updateLiveEventWithCapture($log_id, $web_path);
            echo json_encode([ 'success' => true, 'message' => 'Image processed and denied log updated.', 'path' => $web_path, 'log_status' => 'denied' ]);
        } else {
            throw new Exception("Log entry with ID $log_id not found in attendance_logs OR access_logs.");
        }
    }
} catch (Exception $e) { 
    http_response_code(500);
    error_log("[upload_scan] DB/Logic Error updating Log ID $log_id: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'DB or Logic Error updating log.']);
}

// --- [NEW] This function updates the live JSON file ---
function updateLiveEventWithCapture($log_id, $captured_image_path) {
    $liveEventFile = __DIR__ . '/../data/live_event.json';
    try {
        if (file_exists($liveEventFile)) {
            $jsonContent = @file_get_contents($liveEventFile);
            if ($jsonContent === false) { return; }
            $eventData = json_decode($jsonContent, true);
            if (isset($eventData['log_id']) && $eventData['log_id'] == $log_id) {
                $eventData['captured_image_path'] = $captured_image_path;
                if (@file_put_contents($liveEventFile, json_encode($eventData), LOCK_EX) !== false) {
                    error_log("[updateLiveEvent] Successfully added captured image to live_event.json for log ID $log_id");
                }
            }
        }
    } catch (Exception $e) {
        error_log("[updateLiveEvent] Error: " . $e->getMessage());
    }
}
?>