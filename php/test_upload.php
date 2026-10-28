<?php
// Path: portal/php/test_upload.php
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

// --- [IMPORTANT] We will save to your 'scans' folder ---
$upload_dir = __DIR__ . '/../uploads/scans/';
$file_name = 'SANITY_TEST_' . date('Y-m-d_H-i-s') . '.jpg';
$destination = $upload_dir . $file_name;

$response = [];

// Check if directory exists and is writable
if (!is_dir($upload_dir)) {
    if (!@mkdir($upload_dir, 0777, true)) {
        http_response_code(500);
        $response = ['success' => false, 'message' => "Error: Directory does not exist and could not be created. Check permissions. Path: $upload_dir"];
        error_log($response['message']);
        echo json_encode($response);
        exit;
    }
}
if (!is_writable($upload_dir)) {
    http_response_code(500);
    $response = ['success' => false, 'message' => "Error: Directory is not writable. Check permissions. Path: $upload_dir"];
    error_log($response['message']);
    echo json_encode($response);
    exit;
}

// Check if a file was uploaded
if (isset($_FILES['imageFile']) && $_FILES['imageFile']['error'] == UPLOAD_ERR_OK) {
    if (move_uploaded_file($_FILES['imageFile']['tmp_name'], $destination)) {
        $response = ['success' => true, 'message' => "File saved successfully to $destination"];
        error_log($response['message']);
        echo json_encode($response);
    } else {
        http_response_code(500);
        $response = ['success' => false, 'message' => "Error: Could not move uploaded file."];
        error_log($response['message']);
        echo json_encode($response);
    }
} else {
    http_response_code(400);
    $upload_error = $_FILES['imageFile']['error'] ?? 'No file found';
    $response = ['success' => false, 'message' => "Error: No file uploaded or upload error. Code: $upload_error"];
    error_log($response['message']);
    echo json_encode($response);
}
?>