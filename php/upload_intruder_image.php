<?php
// Path: portal/php/upload_intruder_image.php
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');
$db = require_once('db_connect.php'); // Uses PDO

// --- Configuration ---
// --- ** NEW: Use a separate directory for intruder images ---
define('INTRUDER_UPLOAD_DIR', __DIR__ . '/../uploads/intruders/');
define('INTRUDER_URL_PATH', 'uploads/intruders/'); // Relative path for DB

// --- Input Validation ---
if (!isset($_FILES['imageFile']) || $_FILES['imageFile']['error'] != UPLOAD_ERR_OK) {
    $upload_error = $_FILES['imageFile']['error'] ?? 'No file uploaded';
    error_log("[upload_intruder_image] Error: Image file upload error or missing. Code: $upload_error");
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Image file upload error. Code: ' . $upload_error]);
    exit;
}

// --- Ensure Upload Directory Exists ---
if (!is_dir(INTRUDER_UPLOAD_DIR)) {
    if (!@mkdir(INTRUDER_UPLOAD_DIR, 0777, true)) {
        http_response_code(500);
        error_log("[upload_intruder_image] Failed to create upload directory: " . INTRUDER_UPLOAD_DIR);
        echo json_encode(['success' => false, 'message' => 'Server error: Could not create directory.']);
        exit;
    }
}

// --- Define Filenames ---
$photo = $_FILES['imageFile'];
$fileExtension = 'jpg'; // Assume JPEG from CAM
// --- ** NEW: Use 'intruder_' prefix ---
$uniqueFilename = 'intruder_' . time() . '_' . uniqid() . '.' . $fileExtension;
$destination = INTRUDER_UPLOAD_DIR . $uniqueFilename;
$web_path = INTRUDER_URL_PATH . $uniqueFilename; // Path to store in DB

error_log("[upload_intruder_image] Received file: " . ($photo['name'] ?? 'N/A') . ", Temp path: " . $photo['tmp_name'] . ", Size: " . $photo['size'] . " bytes.");

// --- Simple Move (No Processing Needed for Intruder Images?) ---
// If you want to resize/compress intruder images too, add the logic from upload_scan_image here.
// For now, we'll just move the file as-is.
if (move_uploaded_file($photo['tmp_name'], $destination)) {
    error_log("[upload_intruder_image] Intruder image successfully SAVED to: $destination");

    // --- ** NEW: Insert record into intruder_logs ---
    try {
        $insertStmt = $db->prepare("INSERT INTO intruder_logs (timestamp, captured_image_path, alert_status) VALUES (NOW(), ?, 'new')");
        if ($insertStmt->execute([$web_path])) {
            $newLogId = $db->lastInsertId();
            error_log("[upload_intruder_image] Intruder log created (ID: $newLogId) with image path: " . $web_path);
            echo json_encode([
                'success' => true,
                'message' => 'Intruder image saved and logged.',
                'path' => $web_path,
                'log_id' => $newLogId
            ]);
        } else {
            error_log("[upload_intruder_image] Failed to insert intruder log into DB after saving image.");
            // Attempt to delete the saved image if DB insert fails
            @unlink($destination);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Image saved but DB log failed.']);
        }
    } catch (PDOException $e) {
        error_log("[upload_intruder_image] DB Error inserting intruder log: " . $e->getMessage());
        // Attempt to delete the saved image if DB insert fails
        @unlink($destination);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'DB Error inserting log.']);
    }

} else {
    error_log("[upload_intruder_image] FAILED to move uploaded file to: $destination");
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: Could not save uploaded image. Check permissions.']);
}
?>