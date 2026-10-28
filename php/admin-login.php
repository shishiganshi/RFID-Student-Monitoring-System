<?php
session_start();
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$adminKey = $data['adminKey'] ?? '';

// Replace this with your secure admin key
$validAdminKey = "admin123"; 

if ($adminKey === $validAdminKey) {
    $_SESSION['user_id'] = 1;
    $_SESSION['is_superuser'] = true;
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid admin key']);
}