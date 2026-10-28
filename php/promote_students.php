<?php
// Path: portal/php/promote_students.php
session_start();
$db = require_once('db_connect.php');

// Auth check
if (!isset($_SESSION['user_id']) || !($_SESSION['is_superuser'] ?? false) || !($_SESSION['is_admin'] ?? false)) {
    $_SESSION['form_message'] = 'Error: You are not authorized.';
    $_SESSION['form_message_type'] = 'danger';
    header('Location: ../pages/students.php');
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pages/students.php');
    exit;
}

$totalPromoted = 0;
$totalGraduated = 0;

try {
    // Use a transaction to ensure all updates succeed or fail together
    $db->beginTransaction();

    // 1. Graduate 4th Year Students (Set to Alumni)
    // We only update 'active' students
    $stmt4 = $db->prepare(
        "UPDATE students 
         SET status = 'alumni', year_section = 'Alumni' 
         WHERE year_section LIKE '4th Year%' AND status = 'active'"
    );
    $stmt4->execute();
    $totalGraduated = $stmt4->rowCount();

    // 2. Promote 3rd Year to 4th Year
    $stmt3 = $db->prepare(
        "UPDATE students 
         SET year_section = REPLACE(year_section, '3rd Year', '4th Year') 
         WHERE year_section LIKE '3rd Year%' AND status = 'active'"
    );
    $stmt3->execute();
    $totalPromoted += $stmt3->rowCount();

    // 3. Promote 2nd Year to 3rd Year
    $stmt2 = $db->prepare(
        "UPDATE students 
         SET year_section = REPLACE(year_section, '2nd Year', '3rd Year') 
         WHERE year_section LIKE '2nd Year%' AND status = 'active'"
    );
    $stmt2->execute();
    $totalPromoted += $stmt2->rowCount();

    // 4. Promote 1st Year to 2nd Year
    $stmt1 = $db->prepare(
        "UPDATE students 
         SET year_section = REPLACE(year_section, '1st Year', '2nd Year') 
         WHERE year_section LIKE '1st Year%' AND status = 'active'"
    );
    $stmt1->execute();
    $totalPromoted += $stmt1->rowCount();

    // If all queries were successful, commit the changes
    $db->commit();

    $_SESSION['form_message'] = "Success! New school year started. " . 
                                $totalPromoted . " students were promoted and " . 
                                $totalGraduated . " students were graduated to Alumni.";
    $_SESSION['form_message_type'] = 'success';

} catch (PDOException $e) {
    // An error occurred, roll back the changes
    $db->rollBack();
    
    $_SESSION['form_message'] = 'Database Error: ' . $e->getMessage();
    $_SESSION['form_message_type'] = 'danger';
    error_log("Student Promotion Error: " . $e->getMessage());
}

// Redirect back to the students page
header('Location: ../pages/students.php');
exit;
?>