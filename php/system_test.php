<?php
header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing system components:\n\n";

// 1. Test Database Connection
echo "1. Database Connection Test:\n";
require_once('db_connect.php');
echo "✓ Database connection successful!\n\n";

// 2. Test Tables
echo "2. Database Tables Test:\n";
$tables = ['students', 'access_logs', 'admins', 'intruders'];
foreach ($tables as $table) {
    $result = $db->query("SELECT 1 FROM $table LIMIT 1");
    echo $result ? "✓ Table '$table' exists and is accessible\n" : "✗ Table '$table' issue\n";
}
echo "\n";

// 3. Test RFID Configuration
echo "3. RFID Configuration:\n";
$adminRfidUid = getenv('ADMIN_RFID_UID') ?: '';
if ($adminRfidUid === '') {
    echo "Admin RFID UID is not configured.\n";
} else {
    $stmt = $db->prepare("SELECT * FROM students WHERE rfid_uid = ?");
    $stmt->execute([$adminRfidUid]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $student ? "RFID tag found in database\n" : "RFID tag not found\n";
}
echo "\n";

// 4. Test File Permissions
echo "4. File Permissions Test:\n";
$criticalFiles = [
    '../html/dashboard.php',
    '../html/login.html',
    '../javascript/dashboard.js',
    '../javascript/rfid-auth.js',
    'verify-rfid.php'
];

foreach ($criticalFiles as $file) {
    echo is_readable($file) ? "✓ File '$file' is readable\n" : "✗ File '$file' is not readable\n";
}
