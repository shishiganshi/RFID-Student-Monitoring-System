<?php
require_once('db_connect.php');

function executeSQLFile($conn, $file) {
    try {
        // Read SQL file
        $sql = file_get_contents($file);
        
        // Split into individual statements
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        
        // Begin transaction
        $conn->begin_transaction();
        
        foreach($statements as $statement) {
            if(trim($statement) != '') {
                if(!$conn->query($statement)) {
                    throw new Exception("Error executing statement: " . $conn->error);
                }
            }
        }
        
        // Commit transaction
        $conn->commit();
        echo "Database update completed successfully!\n";
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        echo "Error updating database: " . $e->getMessage() . "\n";
    }
}

// Execute the update
executeSQLFile($conn, '../sql/update_schema.sql');

$conn->close();
