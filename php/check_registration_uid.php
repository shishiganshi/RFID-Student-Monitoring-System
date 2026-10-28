<?php
session_start();
header('Content-Type: application/json');

if (isset($_SESSION['registration_rfid_uid'])) {
    echo json_encode([
        'status' => 'uid_received',
        'uid' => $_SESSION['registration_rfid_uid']
    ]);
} else {
    // Check the file state too, in case session didn't write yet
    $stateFile = '../data/registration_state.json';
     if (file_exists($stateFile)) {
        $content = file_get_contents($stateFile);
        $state = json_decode($content, true);
        // If file indicates received but session not set yet (race condition)
        if ($state && isset($state['status']) && $state['status'] === 'uid_received' && isset($state['uid'])) {
             $_SESSION['registration_rfid_uid'] = $state['uid']; // Sync session
             echo json_encode([
                'status' => 'uid_received',
                'uid' => $state['uid']
             ]);
             exit; // Make sure we exit after sending response
        }
     }
    // Otherwise, still waiting
    echo json_encode(['status' => 'waiting']);
}
?>