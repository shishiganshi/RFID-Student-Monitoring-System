<?php
// Path: portal/php/register-student.php
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');
session_start(); // Start session to set messages

$db = require_once('db_connect.php'); // Uses PDO

if (!extension_loaded('gd')) {
    error_log("GD library is not installed. Image processing will fail.");
    echo json_encode(['success' => false, 'message' => 'Server error: GD library not found.']);
    exit();
}

// --- Configuration (UNCHANGED) ---
define('STUDENT_UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_URL_PATH', 'uploads/');
define('IMG_MAX_DIMENSION', 150);
define('TARGET_FILESIZE_KB', 20);

// --- Image Processing Function (UNCHANGED) ---
function processAndSaveImage($uploadedFile, $uploadDir, $maxDimension, $targetFilesizeKB) {
    // ... (Your existing processAndSaveImage function is fine, no changes needed) ...
    $targetFilesizeBytes = $targetFilesizeKB * 1024;
    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        error_log("File upload error code: " . $uploadedFile['error']);
        return ['success' => false, 'message' => 'File upload error. Code: ' . $uploadedFile['error']];
    }
    $imageFileType = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
    $allowedTypes = ['jpg', 'jpeg', 'png'];
    if (!in_array($imageFileType, $allowedTypes)) {
        return ['success' => false, 'message' => 'Invalid file type. Only JPG, JPEG, PNG allowed.'];
    }
    $uniqueFileName = 'student_' . uniqid() . '.jpeg';
    $targetFilePath = $uploadDir . $uniqueFileName;
    $webPath = UPLOAD_URL_PATH . $uniqueFileName;
    $sourceImage = null;
    $tempPath = $uploadedFile['tmp_name'];
    $imageInfo = @getimagesize($tempPath);
    if (!$imageInfo) {
         error_log("Failed to get image info from temp file: " . $tempPath);
         return ['success' => false, 'message' => 'Could not read image file.'];
    }
    $mime = $imageInfo['mime'];
    if ($mime == 'image/jpeg') {
        $sourceImage = @imagecreatefromjpeg($tempPath);
    } elseif ($mime == 'image/png') {
        $sourceImage = @imagecreatefrompng($tempPath);
        if ($sourceImage) {
            $originalWidth = imagesx($sourceImage);
            $originalHeight = imagesy($sourceImage);
            $bg = imagecreatetruecolor($originalWidth, $originalHeight);
            imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255)); // White background
            imagealphablending($bg, TRUE);
            imagecopy($bg, $sourceImage, 0, 0, 0, 0, $originalWidth, $originalHeight);
            imagedestroy($sourceImage);
            $sourceImage = $bg; 
        }
    } else {
        error_log("Unsupported file type loaded: " . $mime);
        return ['success' => false, 'message' => 'Unsupported image type after loading.'];
    }
    if (!$sourceImage) {
        error_log("Failed to create image resource from uploaded file: " . $tempPath);
        return ['success' => false, 'message' => 'Failed to process image data.'];
    }
    $originalWidth = imagesx($sourceImage);
    $originalHeight = imagesy($sourceImage);
    $newWidth = $originalWidth;
    $newHeight = $originalHeight;
    if ($originalWidth > $maxDimension || $originalHeight > $maxDimension) {
        $ratio = $originalWidth / $originalHeight;
        if ($ratio > 1) { 
            $newWidth = $maxDimension;
            $newHeight = max(1, (int)($maxDimension / $ratio));
        } else {
            $newHeight = $maxDimension;
            $newWidth = max(1, (int)($maxDimension * $ratio));
        }
    }
    $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
    if (!$resizedImage) {
        error_log("Failed to create true color image resource.");
        imagedestroy($sourceImage);
        return ['success' => false, 'message' => 'Server error during image processing.'];
    }
    if (!imagecopyresampled($resizedImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight)) {
        error_log("Failed imagecopyresampled.");
        imagedestroy($sourceImage);
        imagedestroy($resizedImage);
        return ['success' => false, 'message' => 'Server error during image resizing.'];
    }
    $jpegQuality = 85; 
    $saved = false;
    while (!$saved && $jpegQuality >= 15) { 
        if (@imagejpeg($resizedImage, $targetFilePath, $jpegQuality)) {
            clearstatcache(); 
            $currentSize = @filesize($targetFilePath);
            if ($currentSize !== false && $currentSize <= $targetFilesizeBytes) {
                $saved = true;
                error_log("Image saved: " . $uniqueFileName . " Size: " . round($currentSize / 1024) . "KB Quality: " . $jpegQuality);
            } else {
                $jpegQuality -= 10;
                error_log("Image " . $uniqueFileName . " too large (" . ($currentSize ? round($currentSize / 1024) : 'unknown') . "KB), reducing quality to " . $jpegQuality);
                @unlink($targetFilePath);
            }
        } else {
            error_log("Failed to save image with quality " . $jpegQuality . " Path: " . $targetFilePath);
            break; 
        }
    }
    imagedestroy($sourceImage);
    imagedestroy($resizedImage);
    if ($saved) {
        return ['success' => true, 'path' => $webPath];
    } else {
        error_log("Failed to save optimized image after multiple attempts for " . $uniqueFileName . " Last quality: " . $jpegQuality);
        if (file_exists($targetFilePath)) { @unlink($targetFilePath); }
        return ['success' => false, 'message' => 'Failed to optimize image to target size. Try a smaller or less complex image.'];
    }
}

// --- Main Script Logic (MODIFIED) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Collect and sanitize form data
    $rfidUid = filter_input(INPUT_POST, 'rfid_uid', FILTER_SANITIZE_STRING);
    $studentId = filter_input(INPUT_POST, 'student_number', FILTER_SANITIZE_STRING);
    $firstName = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING);
    $middleName = filter_input(INPUT_POST, 'middle_name', FILTER_SANITIZE_STRING);
    $lastName = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING);
    $suffix = filter_input(INPUT_POST, 'suffix', FILTER_SANITIZE_STRING);
    $departmentId = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
    $yearSection = filter_input(INPUT_POST, 'year_section', FILTER_SANITIZE_STRING); // This will be "1st Year - A"

    // Clean optional fields
    $middleName = empty($middleName) ? null : $middleName;
    $suffix = empty($suffix) ? null : $suffix;

    $studentPhotoPath = null;

    // Basic validation
    if (empty($rfidUid) || empty($studentId) || empty($firstName) || empty($lastName) || empty($departmentId) || empty($yearSection)) {
        // This is an AJAX request, so we return JSON
        echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
        exit();
    }

    // --- Handle Image Upload (UNCHANGED) ---
    if (isset($_FILES['student_photo']) && $_FILES['student_photo']['error'] == UPLOAD_ERR_OK) {
        // ... (your existing image directory checks are fine) ...
        $uploadDir = STUDENT_UPLOAD_DIR;
        if (!is_dir($uploadDir)) { /* ... mkdir ... */ }
        if (!is_writable($uploadDir)) { /* ... error ... */ }

        $imageResult = processAndSaveImage($_FILES['student_photo'], $uploadDir, IMG_MAX_DIMENSION, TARGET_FILESIZE_KB);

        if ($imageResult['success']) {
            $studentPhotoPath = $imageResult['path'];
        } else {
            echo json_encode(['success' => false, 'message' => $imageResult['message']]);
            exit();
        }
    } else if (isset($_FILES['student_photo']) && $_FILES['student_photo']['error'] != UPLOAD_ERR_NO_FILE) {
        error_log("Photo upload error code: " . $_FILES['student_photo']['error']);
        echo json_encode(['success' => false, 'message' => 'Error during photo upload. Please try again.']);
        exit();
    }

    // --- Check for Duplicates (UNCHANGED) ---
    try {
        $checkStmt = $db->prepare("SELECT id FROM students WHERE rfid_uid = ? OR student_number = ?");
        $checkStmt->execute([$rfidUid, $studentId]);
        if ($checkStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Error: RFID UID or Student Number already exists.']);
            if ($studentPhotoPath && file_exists(STUDENT_UPLOAD_DIR . basename($studentPhotoPath))) {
                @unlink(STUDENT_UPLOAD_DIR . basename($studentPhotoPath));
            }
            exit();
        }
    } catch (PDOException $e) {
         error_log("Database error checking duplicates: " . $e->getMessage());
         echo json_encode(['success' => false, 'message' => 'Database error checking existing student.']);
         exit();
    }

    // --- Insert Student into Database (MODIFIED) ---
    try {
        $sql = "INSERT INTO students 
                    (rfid_uid, student_number, first_name, middle_name, last_name, suffix, department_id, year_section, photo_path, status) 
                VALUES 
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";
        $stmt = $db->prepare($sql);
        
        if ($stmt->execute([$rfidUid, $studentId, $firstName, $middleName, $lastName, $suffix, $departmentId, $yearSection, $studentPhotoPath])) {

            // --- Clear Registration State (UNCHANGED) ---
            unset($_SESSION['registration_rfid_uid']);
            $stateFile = __DIR__ . '/../data/registration_state.json';
            if (file_exists($stateFile)) { @unlink($stateFile); }
            $uidFile = __DIR__ . '/../data/registration_uid.txt';
            if (file_exists($uidFile)) { @unlink($uidFile); }

            // Set success message for session
            $_SESSION['form_message'] = 'Student registered successfully.';
            $_SESSION['form_message_type'] = 'success';
            
            // Return success, JS will handle redirect
            echo json_encode(['success' => true, 'message' => 'Student registered successfully.']);
        } else {
            error_log("Database insertion failed: " . implode(":", $stmt->errorInfo()));
            echo json_encode(['success' => false, 'message' => 'Failed to register student. Database error.']);
            if ($studentPhotoPath && file_exists(STUDENT_UPLOAD_DIR . basename($studentPhotoPath))) {
                 @unlink(STUDENT_UPLOAD_DIR . basename($studentPhotoPath));
            }
        }
    } catch (PDOException $e) {
        error_log("Database insertion error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error during registration.']);
        if ($studentPhotoPath && file_exists(STUDENT_UPLOAD_DIR . basename($studentPhotoPath))) {
             @unlink(STUDENT_UPLOAD_DIR . basename($studentPhotoPath));
        }
    }

} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}

// --- [REMOVED] The broken <script> tag that was here ---
?>