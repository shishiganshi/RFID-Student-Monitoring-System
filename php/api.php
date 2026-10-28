<?php
include 'db.php';

// API endpoint for ESP32 POST { rfid }
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $rfid = $_POST['rfid'] ?? '';
    if (empty($rfid)) {
        http_response_code(400);
        echo json_encode(["status"=>"error","message"=>"No RFID provided"]);
        exit;
    }

    // Check student
    $stmt = $conn->prepare("SELECT student_id FROM students WHERE rfid_tag = ?");
    $stmt->bind_param("s", $rfid);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $student_id = $row['student_id'];
        $log = $conn->prepare("INSERT INTO attendance_logs (student_id, rfid_tag, status, verified) VALUES (?, ?, 'IN', 1)");
        $log->bind_param("is", $student_id, $rfid);
        $log->execute();
        echo json_encode(["status"=>"ok","message"=>"Logged","student_id"=>$student_id]);
    } else {
        // unauthorized
        $a = $conn->prepare("INSERT INTO alerts (rfid_tag, message) VALUES (?, 'Unauthorized RFID detected')");
        $a->bind_param("s", $rfid);
        $a->execute();

        $log = $conn->prepare("INSERT INTO attendance_logs (rfid_tag, status, verified) VALUES (?, 'UNAUTHORIZED', 0)");
        $log->bind_param("s", $rfid);
        $log->execute();

        echo json_encode(["status"=>"error","message"=>"Unauthorized"]);
    }
    exit;
}
echo json_encode(["status"=>"error","message"=>"Invalid method"]);
?>