<?php
// Use the consistent connection script that returns the PDO object
$db = require_once('db_connect.php');
header('Content-Type: application/json');

try {
    // Get total students (fetchColumn is efficient for a single value)
    $total_students = $db->query("SELECT COUNT(*) FROM students")->fetchColumn();
    
    // Get today's access attempts
    $today_access = $db->query("SELECT COUNT(*) FROM access_logs WHERE DATE(timestamp) = CURDATE()")->fetchColumn();
    
    // Get denied access count
    $denied_access = $db->query("SELECT COUNT(*) FROM access_logs WHERE access_type = 'denied' AND DATE(timestamp) = CURDATE()")->fetchColumn();
    
    // Get intruder count
    $intruder_count = $db->query("SELECT COUNT(*) FROM intruders WHERE DATE(timestamp) = CURDATE()")->fetchColumn();
    
    // Get access data for chart
    $stmt = $db->query("SELECT DATE(timestamp) as date, COUNT(*) as count 
                         FROM access_logs 
                         GROUP BY DATE(timestamp) 
                         ORDER BY date DESC 
                         LIMIT 7");
    
    $access_chart_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $access_labels = array_column($access_chart_data, 'date');
    $access_data = array_column($access_chart_data, 'count');
    
    // Get department statistics
    $stmt = $db->query("SELECT department, COUNT(*) as count 
                         FROM students 
                         GROUP BY department");
                         
    $dept_chart_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $dept_labels = array_column($dept_chart_data, 'department');
    $dept_data = array_column($dept_chart_data, 'count');
    
    echo json_encode([
        'success' => true,
        'total_students' => $total_students,
        'today_access' => $today_access,
        'denied_access' => $denied_access,
        'intruder_count' => $intruder_count,
        'access_chart' => [
            'labels' => $access_labels,
            'data' => $access_data
        ],
        'department_chart' => [
            'labels' => $dept_labels,
            'data' => $dept_data
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server Error: ' . $e->getMessage()
    ]);
}