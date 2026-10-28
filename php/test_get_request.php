<?php
// Path: portal/php/test_get_request.php
header('Content-Type: application/json');

// --- Build the full upload URL ---
$host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
// This is the URL of the file you already created
$upload_url = 'http://' . $host . '/portal/php/test_upload.php';

// --- Always send a capture command ---
$response = [
    'status' => 'capture',
    'log_id' => 'SANITY_TEST_' . time(), // A unique test ID
    'upload_url' => $upload_url
];

error_log("[Sanity Test] Sending capture command to CAM. Upload URL: $upload_url");
echo json_encode($response);
?>