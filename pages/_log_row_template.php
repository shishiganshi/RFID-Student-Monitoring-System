<?php
// Path: portal/pages/_log_row_template.php
// [MODIFIED] To handle 'questionable_fraud' and 'denied_status'

date_default_timezone_set('Asia/Manila');

if (!isset($log)) {
    echo '<tr><td colspan="7">Error: Log data missing for this row.</td></tr>';
    return;
}

$row_log_id = htmlspecialchars($log['log_id'] ?? $log['id'] ?? 'unknown');

// Bools to help logic
$is_attendance = ($log['event_type'] === 'rfid_scan' && isset($log['attendance_status'])); 
$is_intruder = ($log['event_type'] === 'intruder_alert');
$is_unknown_denial = ($log['access_type'] === 'denied');
$is_status_denial = ($log['access_type'] === 'denied_status');
$is_fraud = ($log['access_type'] === 'fraudulent');

$status_class = ''; $status_text = ''; $detailsHtml = 'N/A';
$rfidHtml = 'N/A'; $imageHtml = 'N/A'; $timeOutHtml = '--';

$primaryTimestamp = htmlspecialchars($log['timestamp'] ?? 'N/A'); 

if ($is_attendance && !empty($log['formatted_time_out'])) {
    $timeOutHtml = htmlspecialchars($log['formatted_time_out']);
}
if (!empty($log['rfid_uid'])) {
    $rfidHtml = '<code class="bg-light px-2 py-1 rounded">' . htmlspecialchars($log['rfid_uid']) . '</code>';
}

// --- [NEW] Main Logic Block ---

if ($is_attendance || $is_status_denial) {
    // This is a KNOWN student (active, logged out, suspended, etc.)
    $detailsHtml = '<div class="d-flex align-items-center"><i class="bx bxs-user-circle me-2 text-primary" style="font-size: 1.5rem;"></i> ' . htmlspecialchars($log['full_name'] ?? 'Unknown Student') . '</div>';
    
    if ($is_fraud) {
        $status_class = 'bg-danger';
        $status_text = 'Fraudulent';
    } 
    elseif ($is_status_denial) {
        $status_class = 'bg-danger';
        $status_text = 'Denied (' . ucfirst($log['status_details'] ?? 'Restricted') . ')';
        $timeOutHtml = 'N/A';
    }
    elseif ($log['attendance_status'] === 'out') {
        $status_class = 'bg-info';
        $status_text = 'Out';
    }
    elseif ($log['attendance_status'] === 'present') {
        $status_class = 'bg-success';
        $status_text = 'Present';
    }
    else { // Questionable, etc.
        $status_class = 'bg-warning text-dark';
        $status_text = ucfirst(str_replace('_', ' ', $log['attendance_status'] ?? 'Questionable'));
    }

} 
elseif ($is_unknown_denial) {
    // This is a true UNKNOWN tag.
    $status_class = 'bg-danger';
    $status_text = 'Denied';
    $detailsHtml = '<span class="text-danger">Unknown/Denied User</span>';
    $timeOutHtml = 'N/A'; 
}
elseif ($is_intruder) {
    $status_class = 'bg-warning text-dark';
    $status_text = 'Intruder Alert';
    $detailsHtml = '<span class="text-warning">' . htmlspecialchars($log['full_name'] ?? '(Suspected/Motion)') . '</span>';
    $rfidHtml = 'N/A'; 
}
else {
    // Fallback
    $status_class = 'bg-secondary';
    $status_text = 'Unknown';
    $detailsHtml = htmlspecialchars($log['full_name'] ?? 'N/A');
    $timeOutHtml = 'N/A';
}

// --- Image Logic (Unchanged) ---
$has_image = !empty($log['captured_image_path']);
if ($has_image) {
    $imgPath = '../' . htmlspecialchars($log['captured_image_path']);
    $imageHtml = '<button class="btn btn-primary btn-sm view-btn" data-image-path="' . $imgPath . '">View</button>';
} else {
    $imageHtml = '<span class="text-muted small" id="img-placeholder-' . $row_log_id . '">(No image)</span>';
}
?>

<tr id="log-row-<?php echo $row_log_id; ?>">
    <td class="cell-time-in"><?php echo $primaryTimestamp; ?></td>
    <td class="cell-time-out"><?php echo $timeOutHtml; ?></td>
    <td class="cell-event-type"><?php echo ($is_intruder ? 'Camera Alert' : 'RFID Scan'); ?></td>
    <td class="cell-details"><?php echo $detailsHtml; ?></td>
    <td class="cell-rfid"><?php echo $rfidHtml; ?></td>
    <td class="cell-status">
        <span class="badge <?php echo $status_class; ?>">
            <?php echo $status_text; ?>
        </span>
    </td>
    <td class="cell-image"><?php echo $imageHtml; ?></td>
</tr>