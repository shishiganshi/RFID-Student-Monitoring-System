<?php
session_start();
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, TRUE); // Convert JSON input to an array

$rfid_uid = $input['rfid_uid'] ?? null;

if ($rfid_uid) {
    $_SESSION['registration_rfid_uid'] = $rfid_uid;

    // Update the state file as well, helpful for polling consistency
    $stateFile = '../data/registration_state.json';
    $stateData = [
        'status' => 'uid_received',
        'uid' => $rfid_uid,
        'timestamp' => time()
    ];
     if (!is_dir('../data')) {
        mkdir('../data', 0755, true);
     }
    file_put_contents($stateFile, json_encode($stateData)); // Overwrite or create

    error_log("Received an RFID for registration.");
    echo json_encode(['success' => true, 'message' => 'UID received for registration.']);

} else {
    http_response_code(400); // Bad Request
    error_log("Failed to receive registration UID. Input: " . $inputJSON);
    echo json_encode(['success' => false, 'message' => 'Invalid data: RFID UID missing.']);
}
?>