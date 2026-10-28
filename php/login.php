<?php
session_start();
$db = require 'db_connect.php'; // Use the correct connection file

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // Note: Your schema has an 'admins' table, not 'users'. Let's use that.
    $stmt = $db->prepare("SELECT * FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        // Login successful
        session_regenerate_id(true); // Security measure
        $_SESSION['user_id'] = $user['admin_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['is_superuser'] = $user['is_superuser'];
        
        if (!empty($_POST['remember'])) {
            setcookie("cmu_user", $user['username'], time() + (86400 * 30), "/"); // 30 days
        }
        
        header("Location: ../html/dashboard.php");
        exit;
    } else {
        // Use a generic error message for security
        echo "Invalid username or password.";
    }
} else {
    echo "Invalid request method.";
}