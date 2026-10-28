<?php
date_default_timezone_set('Asia/Manila');

// --- Database Credentials ---
$host = getenv('DB_HOST') ?: 'localhost';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$database = getenv('DB_NAME') ?: 'cmu_rfid_portal';
$charset = 'utf8mb4';

// --- Create PDO Connection ---
try {
    // Data Source Name (DSN)
    $dsn = "mysql:host=$host;dbname=$database;charset=$charset";
    
    // PDO Options
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $db = new PDO($dsn, $username, $password, $options);

} catch (PDOException $e) {
    // In a real application, you would log this error, not show it to the user
    error_log("Database Connection Failed: " . $e->getMessage());
    die("Error: Could not connect to the database. Please try again later.");
}

// --- IMPORTANT: Return the Connection Object ---
return $db;