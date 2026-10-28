<?php
// Path: portal/php/sql_update.php
// Run this file ONCE in your browser to update your database table.

echo "<pre>";
echo "Connecting to database...\n";
$db = require_once('db_connect.php');
echo "Connected.\n\n";

try {
    echo "Attempting to add 'user_id' column to 'access_logs'...\n";
    $db->exec("ALTER TABLE `access_logs` ADD COLUMN `user_id` INT(11) NULL DEFAULT NULL AFTER `rfid_uid`");
    echo "'user_id' column added (or already exists).\n";
} catch (PDOException $e) {
    if ($e->getCode() == '42S21') { // Column already exists
        echo "Column 'user_id' already exists. Skipping.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

try {
    echo "Attempting to add 'status_details' column to 'access_logs'...\n";
    $db->exec("ALTER TABLE `access_logs` ADD COLUMN `status_details` VARCHAR(50) NULL DEFAULT NULL AFTER `access_type`");
    echo "'status_details' column added (or already exists).\n";
} catch (PDOException $e) {
    if ($e->getCode() == '42S21') { // Column already exists
        echo "Column 'status_details' already exists. Skipping.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

echo "\nDatabase update complete. You can delete this file now.";
echo "</pre>";
?>