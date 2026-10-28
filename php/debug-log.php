<?php
header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$logFile = '../logs/rfid_debug.log';
$data = json_decode(file_get_contents('php://input'), true);

// Log request details
$log = date('Y-m-d H:i:s') . "\n";
$log .= "Request Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
$log .= "Raw Input: " . file_get_contents('php://input') . "\n";
$log .= "Session Data: " . print_r($_SESSION, true) . "\n";
$log .= "----------------------------------------\n";

// Append to log file
file_put_contents($logFile, $log, FILE_APPEND);
echo "Logged";