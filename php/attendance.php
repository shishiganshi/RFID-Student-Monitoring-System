<?php
date_default_timezone_set('Asia/Manila');

// --- Use the consistent PDO connection ---
$db = require_once('db_connect.php'); // Changed from include 'db.php'

// --- Error Handling ---
if (!isset($db) || !$db instanceof PDO) {
    die("Error: Could not connect to the database. Please check db_connect.php.");
}

$attendanceLogs = []; // Initialize array

try {
    // --- Corrected SQL Query (Using PDO, correct columns) ---
    $query = "
        SELECT
            l.log_id,
            s.full_name,
            l.time_in,   -- Changed from scan_time
            l.time_out,  -- Added time_out
            l.status
        FROM attendance_logs l
        LEFT JOIN students s ON l.student_id = s.id -- Changed join condition to s.id
        ORDER BY l.time_in DESC
        LIMIT 200 -- Added a limit for performance
    "; // Removed triple quotes, removed rfid_tag, changed scan_time, added time_out

    $stmt = $db->query($query);
    $attendanceLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Log the error in a real application
    error_log("Error fetching attendance logs: " . $e->getMessage());
    $errorMessage = "Error retrieving attendance data.";
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Attendance Logs</title>
    <style> /* Basic styling */
        body { font-family: sans-serif; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        tr:nth-child(even) { background-color: #f9f9f9; }
    </style>
</head>
<body>
<h2>Attendance Logs</h2>

<?php if (isset($errorMessage)): ?>
    <p style="color: red;"><?php echo htmlspecialchars($errorMessage); ?></p>
<?php endif; ?>

<table>
    <thead>
        <tr>
            <th>Log ID</th>
            <th>Student</th>
            <th>Time In</th>
            <th>Time Out</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!empty($attendanceLogs)): ?>
            <?php foreach ($attendanceLogs as $row): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['log_id']); ?></td>
                <td><?php echo htmlspecialchars($row['full_name'] ?? 'UNKNOWN'); ?></td>
                <td><?php echo htmlspecialchars($row['time_in'] ? date('M d, Y, h:i:s A', strtotime($row['time_in'])) : 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($row['time_out'] ? date('M d, Y, h:i:s A', strtotime($row['time_out'])) : '--'); // Display '--' if null ?></td>
                <td><?php echo htmlspecialchars($row['status']); ?></td>
            </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="5">No attendance logs found.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>
</body>
</html>