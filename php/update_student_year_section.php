<?php
// Path: portal/php/update_student_year_section.php
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
    $year = $_POST['year'] ?? '';
    $section = $_POST['section'] ?? '';

    // Validate inputs
    $allowed_years = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
    $allowed_sections = range('A', 'K');

    if ($studentId && in_array($year, $allowed_years) && in_array($section, $allowed_sections)) {
        
        // Combine into the format "1st Year - A"
        $new_year_section = $year . ' - ' . $section;

        try {
            $stmt = $db->prepare("UPDATE students SET year_section = ? WHERE id = ?");
            $stmt->execute([$new_year_section, $studentId]);

            $_SESSION['form_message'] = 'Success! Student Year/Section updated.';
            $_SESSION['form_message_type'] = 'success';

        } catch (PDOException $e) {
            $_SESSION['form_message'] = 'Database Error: ' . $e->getMessage();
            $_SESSION['form_message_type'] = 'danger';
        }

    } else {
        $_SESSION['form_message'] = 'Error: Invalid data provided for Year/Section update.';
        $_SESSION['form_message_type'] = 'danger';
    }
}

// Redirect back to the students page
header('Location: ../pages/students.php');
exit;
?>