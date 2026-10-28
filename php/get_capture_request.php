<?php
// Path: portal/php/get_capture_request.php
header('Content-Type: application/json');

$captureRequestFile = __DIR__ . '/../data/capture_request.json';
$response = ['status' => 'idle']; // Default response

if (file_exists($captureRequestFile)) {
    $content = @file_get_contents($captureRequestFile);
    if ($content === false) {
        error_log("[get_capture_request] Failed to read capture request file: " . $captureRequestFile);
        @unlink($captureRequestFile);
    } else {
        $data = json_decode($content, true);

        // --- *** THE FIX IS HERE *** ---
        // We are now checking for 'access_log_id' (which your verify-rfid.php writes)
        if ($data && isset($data['timestamp']) && isset($data['access_log_id']) && (time() - $data['timestamp']) < 15) {
            
            $response = [
                'status' => 'capture',
                // --- [MODIFIED] Send 'log_id' as the key, but use the value from 'access_log_id'
                'log_id' => $data['access_log_id'], 
                'upload_url' => 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1') . '/portal/php/upload_scan_image.php'
            ];
            
            if (!@unlink($captureRequestFile)) {
                 error_log("[get_capture_request] Failed to delete capture request file after processing: " . $captureRequestFile);
            }
            error_log("[get_capture_request] Sent capture request to CAM for log ID: " . $data['access_log_id']);

        } else if (file_exists($captureRequestFile)) {
             if (!@unlink($captureRequestFile)) {
                 error_log("[get_capture_request] Failed to delete stale/invalid capture request file: " . $captureRequestFile);
             } else {
                 error_log("[get_capture_request] Deleted stale/invalid capture request file (it was missing 'access_log_id' or was too old).");
             }
        }
    }
}

echo json_encode($response);
?>