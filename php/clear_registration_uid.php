<?php
session_start();
header('Content-Type: application/json');

// Clear the session variable
unset($_SESSION['registration_rfid_uid']);

// Also delete or clear the state file
$stateFile = '../data/registration_state.json';
if (file_exists($stateFile)) {
    unlink($stateFile); // Delete the file
    // OR update status: file_put_contents($stateFile, json_encode(['status' => 'idle']));
}

echo json_encode(['success' => true, 'message' => 'Registration state cleared.']);
?>