<?php
$db = require 'db_connect.php'; // Use the correct PDO connection

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fullname = $_POST['fullname'] ?? '';
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'guard'; // Default role

    if (empty($username) || empty($password) || empty($fullname)) {
        die("Please fill in all required fields.");
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    
    try {
        // Assumes you have an 'admins' table, not 'users'
        $stmt = $db->prepare("INSERT INTO admins (username, password, is_superuser, email, full_name) VALUES (?, ?, ?, ?, ?)");
        
        // Convert role to is_superuser
        $is_superuser = ($role === 'admin') ? 1 : 0;
        
        $stmt->execute([$username, $hash, $is_superuser, $email, $fullname]);
        
        header("Location: ../html/login.html");
        exit;
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Integrity constraint violation (duplicate)
            echo "Error: This username or email already exists.";
        } else {
            echo "Error: " . $e->getMessage();
        }
    }
} else {
    echo "Invalid request.";
}
?>