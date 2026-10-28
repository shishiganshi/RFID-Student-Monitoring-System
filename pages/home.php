<?php
session_start();
$mysqli = require_once(__DIR__ . '/../php/db.php');
if (!$mysqli) {
    die("Database connection failed");
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../html/login.html');
    exit;
}

// Fetch recent logs
$query = "SELECT l.*, u.name as user_name 
          FROM access_logs l 
          LEFT JOIN users u ON l.user_id = u.id 
          ORDER BY timestamp DESC 
          LIMIT 10";
$result = $mysqli->query($query);

// Get total counts
$totalUsers = $mysqli->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
$todayLogs = $mysqli->query("SELECT COUNT(*) FROM access_logs WHERE DATE(timestamp) = CURRENT_DATE")->fetch_row()[0];
$totalDenied = $mysqli->query("SELECT COUNT(*) FROM access_logs WHERE access_type = 'denied'")->fetch_row()[0];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin: 20px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .recent-activity {
            margin: 20px;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>
    <div class="dashboard-stats">
        <div class="stat-card">
            <h3>Total Users</h3>
            <p><?php echo $totalUsers; ?></p>
        </div>
        <div class="stat-card">
            <h3>Today's Logs</h3>
            <p><?php echo $todayLogs; ?></p>
        </div>
        <div class="stat-card">
            <h3>Access Denied</h3>
            <p><?php echo $totalDenied; ?></p>
        </div>
    </div>

    <div class="recent-activity">
        <h2>Recent Activity</h2>
        <table>
            <thead>
                <tr>
                    <th>Time</th>
                    <th>User</th>
                    <th>RFID UID</th>
                    <th>Access</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo date('Y-m-d H:i:s', strtotime($row['timestamp'])); ?></td>
                    <td><?php echo htmlspecialchars($row['user_name'] ?? 'Unknown'); ?></td>
                    <td><?php echo htmlspecialchars($row['rfid_uid']); ?></td>
                    <td><?php echo htmlspecialchars($row['access_type']); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
