<?php
// Path: portal/php/add_department.php
session_start();
$db = require_once('db_connect.php');

// Auth check
if (!isset($_SESSION['user_id']) || !($_SESSION['is_superuser'] ?? false) || !($_SESSION['is_admin'] ?? false)) {
    $_SESSION['form_message'] = 'Error: You are not authorized.';
    $_SESSION['form_message_type'] = 'danger';
    header('Location: ../pages/students.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !empty($_POST['department_name'])) {
    $deptName = filter_input(INPUT_POST, 'department_name', FILTER_SANITIZE_STRING);

    try {
        $stmt = $db->prepare("INSERT INTO departments (name) VALUES (?)");
        $stmt->execute([$deptName]);
        
        $_SESSION['form_message'] = 'Success! Department "' . htmlspecialchars($deptName) . '" added.';
        $_SESSION['form_message_type'] = 'success';

    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Duplicate entry
            $_SESSION['form_message'] = 'Error: That department name already exists.';
        } else {
            $_SESSION['form_message'] = 'Database Error: ' . $e->getMessage();
        }
        $_SESSION['form_message_type'] = 'danger';
    }
} else {
    $_SESSION['form_message'] = 'Error: Department name cannot be empty.';
    $_SESSION['form_message_type'] = 'danger';
}

header('Location: ../pages/students.php');
exit;
?>