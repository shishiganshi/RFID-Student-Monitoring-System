<?php
// Path: portal/php/update_student_status.php
session_start();
$db = require_once('db_connect.php');

// Auth check
if (!isset($_SESSION['user_id']) || !($_SESSION['is_superuser'] ?? false) || !($_SESSION['is_admin'] ?? false)) {
    $_SESSION['form_message'] = 'Error: You are not authorized.';
    $_SESSION['form_message_type'] = 'danger';
    header('Location: ../pages/students.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $studentId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
    $newStatus = $_POST['new_status'] ?? ''; // Get status

    // Validate status
    $allowed_statuses = ['active', 'inactive', 'suspended', 'alumni'];
    if ($studentId && in_array($newStatus, $allowed_statuses)) {
        
        try {
            $stmt = $db->prepare("UPDATE students SET status = ? WHERE id = ?");
            $stmt->execute([$newStatus, $studentId]);

            $_SESSION['form_message'] = 'Success! Student status updated to ' . htmlspecialchars($newStatus) . '.';
            $_SESSION['form_message_type'] = 'success';

        } catch (PDOException $e) {
            $_SESSION['form_message'] = 'Database Error: ' . $e->getMessage();
            $_SESSION['form_message_type'] = 'danger';
        }

    } else {
        $_SESSION['form_message'] = 'Error: Invalid data provided.';
        $_SESSION['form_message_type'] = 'danger';
    }
}

header('Location: ../pages/students.php');
exit;
?>