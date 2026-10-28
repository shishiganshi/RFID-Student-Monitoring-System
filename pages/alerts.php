<?php
session_start();
if (!isset($_SESSION['user_id']) || !($_SESSION['is_superuser'] ?? false)) {
    header('Location: ../html/login.html');
    exit;
}

$db = require_once(__DIR__ . '/db_connect.php');

// Assumes you have an 'alerts' table
$result = $db->query("SELECT id, rfid_uid, message, timestamp FROM alerts ORDER BY timestamp DESC LIMIT 50");
?>
<!DOCTYPE html><html><head><meta charset='utf-8'><title>Alerts</title>
<style> .unauth{background:#ffe6e6;color:#b30000;padding:8px;border-radius:6px;} table{width:100%;border-collapse:collapse;} th,td{padding:8px;border-bottom:1px solid #eee;} th{background:#ffdddd;} </style>
<script> setTimeout(()=>{ location.reload(); },15000);</script>
</head><body>
<h2>⚠ Security Alerts (Unauthorized RFID)</h2>
<?php if ($result && $result->rowCount() > 0): ?>
<table><tr><th>ID</th><th>RFID Tag</th><th>Message</th><th>Time</th></tr>
<?php while($row = $result->fetch(PDO::FETCH_ASSOC)) { ?>
<tr class="unauth"><td><?= $row['id'] ?></td><td><?= $row['rfid_uid'] ?></td><td><?= $row['message'] ?></td><td><?= $row['timestamp'] ?></td></tr>
<?php } ?></table>
<?php else: ?>
  <div style="background:#eaf6ea;padding:12px;border-radius:6px;color:#006600;">✅ No unauthorized RFID scans detected.</div>
<?php endif; ?>
</body></html>