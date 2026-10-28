<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

// Configuration
$stateFile = '../data/rfid_state.json';
$ipFile = '../data/esp32_ip.txt';

// Get the ESP32's IP address
$esp32_ip = $_SERVER['REMOTE_ADDR'];

// Handle POST request from ESP32
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    if ($data) {
        // Add timestamp and IP
        $data['timestamp'] = time();
        $data['esp32_ip'] = $esp32_ip;
        
        // Save state
        file_put_contents($stateFile, json_encode($data));
        file_put_contents($ipFile, $esp32_ip);
        
        echo json_encode([
            'status' => 'success',
            'message' => 'State updated',
            'timestamp' => $data['timestamp']
        ]);
        exit;
    }
}

// Handle GET request (status check)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (file_exists($stateFile)) {
        $state = json_decode(file_get_contents($stateFile), true);
        if ($state && (time() - $state['timestamp']) <= 5) {
            echo json_encode([
                'status' => 'success',
                'state' => $state,
                'esp32_ip' => $esp32_ip
            ]);
            exit;
        }
    }
    
    echo json_encode([
        'status' => 'error',
        'message' => 'No recent state available',
        'esp32_ip' => $esp32_ip
    ]);
    exit;
}

// Invalid method
echo json_encode([
    'status' => 'error',
    'message' => 'Invalid request method'
]);
?>
