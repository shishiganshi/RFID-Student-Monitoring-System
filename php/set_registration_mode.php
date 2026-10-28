<?php
session_start(); // Start session to potentially clear old UIDs
header('Content-Type: application/json');

// Clear any previous registration UID from the session
unset($_SESSION['registration_rfid_uid']);

// Use a simple file to signal the ESP32
$stateFile = '../data/registration_state.json';
$stateData = [
    'status' => 'waiting_scan',
    'timestamp' => time()
];

// Ensure the data directory exists
if (!is_dir('../data')) {
    mkdir('../data', 0755, true);
}

// Write the state file
if (file_put_contents($stateFile, json_encode($stateData))) {
    echo json_encode(['success' => true, 'message' => 'Registration mode activated.']);
} else {
    // Error handling: Could not write file
    error_log("Failed to write registration state file: " . $stateFile);
    echo json_encode(['success' => false, 'message' => 'Server error: Could not initiate registration mode.']);
}
?>