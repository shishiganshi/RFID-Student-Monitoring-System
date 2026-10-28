<?php
$db = require_once('db_connect.php');
header('Content-Type: application/json');

try {
    // The query assumes you have a 'users' table, not a 'students' table for names.
    // I've adjusted it to your 'students' table schema.
    $query = "SELECT l.*, s.full_name as student_name 
              FROM access_logs l 
              LEFT JOIN students s ON l.user_id = s.id 
              ORDER BY l.timestamp DESC 
              LIMIT 50";
              
    $stmt = $db->query($query);
    
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'logs' => $logs
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server Error: ' . $e->getMessage()
    ]);
}