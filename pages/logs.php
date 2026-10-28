<?php
date_default_timezone_set('Asia/Manila');
session_start();

// [FIXED] Authentication Check: Allow if user is (superuser OR admin)
if (!isset($_SESSION['user_id']) || (!($_SESSION['is_superuser'] ?? false) && !($_SESSION['is_admin'] ?? false))) {
    header('Location: ../html/login.html');
    exit;
}
$db = require_once(__DIR__ . '/../php/db_connect.php'); 

$records_per_page = 20; 
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;
$offset = ($current_page - 1) * $records_per_page;
$filter_name = $_GET['search_name'] ?? '';


// --- [FIXED] This function uses the new robust DB structure ---
function fetchLogs($db, $limit, $offset, $filter_name) {
    $logs = [];
    $params = []; 
    
    // [FIXED] This baseQuery uses LEFT JOINs and properly handles all log types
    $baseQuery = "
        (SELECT
            att.log_id as log_id,
            att.time_in as raw_timestamp,
            DATE_FORMAT(att.time_in, '%b %d, %Y, %h:%i:%s %p') AS formatted_time_in,
            DATE_FORMAT(att.time_out, '%b %d, %Y, %h:%i:%s %p') AS formatted_time_out,
            s.rfid_uid,
            
            CASE 
                WHEN att.status = 'questionable_fraud' THEN 'fraudulent'
                WHEN att.status LIKE '%questionable%' THEN 'questionable' 
                ELSE 'granted' 
            END as access_type,
            
            att.status as attendance_status,
            CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name, s.suffix) as full_name,
            CASE WHEN att.time_out IS NULL THEN att.image_in_path ELSE att.image_out_path END as captured_image_path,
            'rfid_scan' as event_type,
            att.status as status_details
        FROM attendance_logs att
        LEFT JOIN students s ON att.student_id = s.id
        )
        UNION ALL
        (SELECT
            al.id as log_id,
            al.timestamp as raw_timestamp,
            DATE_FORMAT(al.timestamp, '%b %d, %Y, %h:%i:%s %p') AS formatted_time_in,
            NULL AS formatted_time_out,
            al.rfid_uid,
            al.access_type, -- This will be 'denied' or 'denied_status'
            NULL as attendance_status,
            
            COALESCE(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name, s.suffix), '(Denied User)') as full_name,
            
            al.captured_image_path,
            'rfid_scan' as event_type,
            al.status_details -- This will be 'suspended', 'alumni', etc.
            
        FROM access_logs al
        LEFT JOIN students s ON al.user_id = s.id 
        )
    "; 

    $whereClauses = [];
    // This filter logic you had is correct and works with the new query
    if (!empty($filter_name)) {
        $whereClauses[] = "full_name LIKE ?";
        $params[] = '%' . $filter_name . '%';
    }

    $sql = "SELECT * FROM (" . $baseQuery . ") AS combined_logs";
    if (!empty($whereClauses)) {
        $sql .= " WHERE " . implode(' AND ', $whereClauses);
    }
    $sql .= " ORDER BY raw_timestamp DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
         foreach ($logs as &$log) {
             // This prepares data for the _log_row_template.php file
             $log['timestamp'] = $log['formatted_time_in'];
             if (!isset($log['access_type'])) $log['access_type'] = 'unknown';
             if (!isset($log['formatted_time_out'])) $log['formatted_time_out'] = null;
         } unset($log);
    } catch (PDOException $e) {
        error_log("Error in fetchLogs: " . $e->getMessage());
        return [];
    }
    return $logs;
}

// --- [FIXED] This function now uses the robust DB structure ---
function countTotalLogs($db, $filter_name) {
    $total = 0;
    $params = [];
    
    // [FIXED] This baseQuery matches fetchLogs
    $baseQuery = "
        (SELECT CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name, s.suffix) as full_name 
         FROM attendance_logs att 
         LEFT JOIN students s ON att.student_id = s.id) 
        UNION ALL
        (SELECT COALESCE(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name, s.suffix), '(Denied User)') as full_name 
         FROM access_logs al 
         LEFT JOIN students s ON al.user_id = s.id)
    "; 
    
    $whereClauses = [];
    // This filter logic is correct
    if (!empty($filter_name)) {
        $whereClauses[] = "full_name LIKE ?";
        $params[] = '%' . $filter_name . '%';
    }
    $sql = "SELECT COUNT(*) FROM (" . $baseQuery . ") AS combined_logs";
    if (!empty($whereClauses)) {
        $sql .= " WHERE " . implode(' AND ', $whereClauses);
    }
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $total = $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error in countTotalLogs: " . $e->getMessage());
        return 0;
    }
    return $total;
}

$initialLogs = fetchLogs($db, $records_per_page, $offset, $filter_name);
$total_records = countTotalLogs($db, $filter_name);
$total_pages = ceil($total_records / $records_per_page);
if ($total_pages < 1) $total_pages = 1; 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CMU RFID Portal - All Logs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/boxicons@latest/css/boxicons.min.css" rel="stylesheet">
    <link href="../css/dashboard.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1a73e8; --secondary-color: #f8f9fa; --accent-color: #0d47a1;
            --text-color: #202124; --sidebar-width: 250px;
        }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: var(--secondary-color); margin: 0; padding: 0; color: var(--text-color); }
        .sidebar { position: fixed; width: var(--sidebar-width); height: 100vh; background: linear-gradient(180deg, var(--accent-color) 0%, var(--primary-color) 100%); color: white; padding: 1.5rem; box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1); z-index: 1000; }
        .sidebar h2 { font-size: 1.5rem; margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(255, 255, 255, 0.1); white-space: nowrap; }
        .sidebar ul { list-style: none; padding: 0; margin: 0; } .sidebar li { margin-bottom: 0.5rem; }
        .sidebar a { color: white; text-decoration: none; display: flex; align-items: center; padding: 0.75rem 1rem; border-radius: 8px; transition: all 0.3s ease; }
        .sidebar a:hover { background: rgba(255, 255, 255, 0.1); } .sidebar a.active { background: rgba(255, 255, 255, 0.2); font-weight: bold; }
        .sidebar i { margin-right: 10px; font-size: 1.2rem; }
        .content-wrapper { margin-left: var(--sidebar-width); padding: 2rem; width: calc(100% - var(--sidebar-width)); }
        .logs-table { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden; margin-bottom: 2rem; }
        .pagination-nav { margin-top: 1.5rem; display: flex; justify-content: center; }
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0, 0, 0, 0.8); }
        .modal-content { margin: 10% auto; padding: 20px; background: #fff; border-radius: 8px; max-width: 90%; max-height: 80%; width: auto; height: auto; display: block; position: relative; }
        .modal-content img { max-width: 100%; max-height: 70vh; display: block; margin: 0 auto; }
        .modal-close { position: absolute; top: 15px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; transition: 0.3s; }
        .modal-close:hover, .modal-close:focus { color: #bbb; text-decoration: none; cursor: pointer; }
        .badge { color: white !important; }
        .badge.bg-warning { color: black !important; }
        
        .header-search-form { /* max-width: 400px; */ } /* <-- Removed max-width */
        
        /* --- Live Panel Styles (Slightly larger) --- */
        #live-monitoring-panel {
            background-color: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
            overflow: hidden;
            display: none; 
            transition: all 0.3s ease;
            border: 2px solid var(--primary-color);
            min-height: 200px; /* Give panel breathing room */
            align-items: center; /* Vertically center */
        }
        #live-monitoring-panel.is-denied { border-color: #dc3545; }
        #live-monitoring-panel .live-photo {
            width: 150px; /* Was 100px */
            height: 150px; /* Was 100px */
            object-fit: cover;
            border-radius: 50%; 
            border: 4px solid #eee; /* Made border thicker */
        }
        #live-monitoring-panel .live-placeholder {
            width: 150px; /* Was 100px */
            height: 150px; /* Was 100px */
            border-radius: 50%;
            background-color: #e9ecef; 
            display: flex;
            align-items: center; 
            justify-content: center;
            color: #6c757d; 
            font-size: 4rem; /* Made icon bigger */
            border: 4px solid #eee; /* Made border thicker */
        }

        @media print {
            body { background: #fff; color: #000; }
            .sidebar, header, .pagination-nav, .header-search-form, #live-monitoring-panel, #image-modal { display: none !important; }
            .content-wrapper { margin-left: 0; padding: 0; width: 100%; }
            .logs-table { box-shadow: none; border: 1px solid #dee2e6; border-radius: 0; margin-bottom: 1rem; }
            .table-responsive { overflow: visible; }
            .table { font-size: 10pt; }
            .table th, .table td { padding: 0.4rem; }
            .badge { border: 1px solid #6c757d; color: #000 !important; background-color: #fff !important; }
            .view-btn { display: none; }
            .text-muted.small { display: block !important; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2><i class='bx bxs-shield'></i>CMU Portal</h2>
        <ul>
            <li><a href="../html/dashboard.php"><i class='bx bxs-dashboard'></i>Dashboard</a></li>
            <li><a href="logs.php" class="active"><i class='bx bx-history'></i>All Security Logs</a></li>
            <li><a href="students.php"><i class='bx bxs-user-detail'></i>Students</a></li>
            <hr style="border:none;border-top:1px solid rgba(255,255,255,0.1);margin:12px 0;">
            <li><a href="../php/logout.php"><i class='bx bx-log-out'></i>Logout</a></li>
        </ul>
    </div>

    <div class="content-wrapper"> 
        <header class="mb-4">
             <div class="container-fluid px-0">
                 <div class="d-flex justify-content-between align-items-center mb-3">
                     <h1 class="h2 mb-0">All Security Logs</h1>
                     <div class="d-flex align-items-center">
                        <button class="btn btn-sm btn-outline-secondary me-3" id="print-logs-btn" title="Print Logs">
                            <i class='bx bx-printer'></i> Print
                         </button>
                         <div id="currentDateTime" class="text-primary text-nowrap"></div>
                     </div>
                 </div>
                 <div class="row justify-content-end">
                     <div class="col-md-6 col-lg-5">
                         <form action="logs.php" method="GET" class="header-search-form">
                             <div class="input-group">
                                 <input type="text" class="form-control" name="search_name" placeholder="Search by name..." value="<?php echo htmlspecialchars($filter_name); ?>">
                                 <button class="btn btn-primary" type="submit"><i class='bx bx-search'></i></button>
                             </div>
                         </form>
                     </div>
                 </div>
             </div>
        </header>
        <div class="container-fluid px-0">
            <div id="live-monitoring-panel" class="p-3">
                <div class="row w-100 align-items-center">
                    <div class="col-md-2 text-center">
                        <div id="live-photo-container"></div>
                    </div>
                    <div class="col-md-5">
                        <h4 class="mb-0" id="live-name"></h4>
                        <p class="mb-0 text-muted" id="live-department"></p>
                        <h5 class="mb-0" id="live-status-badge"></h5>
                    </div>
                    <div class="col-md-5 text-center" id="live-captured-image-container">
                    </div>
                </div>
            </div>
            
            <div class="logs-table">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th>Time In / Event Time</th>
                                <th>Time Out</th>
                                <th>Event Type</th>
                                <th>Details</th>
                                <th>RFID UID</th>
                                <th>Status</th>
                                <th>Image</th>
                            </tr>
                        </thead>
                        <tbody id="logs-table-body">
                            <?php if (empty($initialLogs)): ?>
                                <tr id="no-logs-row">
                                    <td colspan="7" class="text-center py-4">No logs found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($initialLogs as $log): ?>
                                    <?php 
                                    // This include uses the $log variable from the loop
                                    include '_log_row_template.php'; 
                                    ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
             <?php if ($total_pages > 1): ?>
             <nav aria-label="Page navigation" class="pagination-nav">
                <ul class="pagination">
                    <?php if ($current_page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $current_page - 1; ?>&search_name=<?php echo urlencode($filter_name); ?>">...</a>
                        </li>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo ($i == $current_page) ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&search_name=<?php echo urlencode($filter_name); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($current_page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo $current_page + 1; ?>&search_name=<?php echo urlencode($filter_name); ?>">...</a>
                        </li>
                    <?php endif; ?>
                </ul>
             </nav>
             <?php endif; ?>
        </div>
    </div> 
    
    <div id="image-modal" class="modal">
        <span class="modal-close" id="modal-close-btn">&times;</span>
        <div class="modal-content">
            <img src="" id="modal-image" alt="Captured Image">
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // --- Image Modal, Date/Time, Print Button (All Unchanged) ---
        const modal = document.getElementById('image-modal');
        const modalImage = document.getElementById('modal-image');
        const closeModalBtn = document.getElementById('modal-close-btn');
        const logsTableBody = document.getElementById('logs-table-body');
        function showImageModal(imagePath) {
            if (modalImage && modal) { modalImage.src = imagePath; modal.style.display = 'block'; }
        }
        if (logsTableBody) {
            logsTableBody.addEventListener('click', (event) => {
                const target = event.target;
                if (target.classList.contains('view-btn') && target.dataset.imagePath) {
                    event.preventDefault(); // Prevent default
                    showImageModal(target.dataset.imagePath);
                }
            });
        }
        if(closeModalBtn) { closeModalBtn.addEventListener('click', () => { modal.style.display = 'none'; }); }
        if(modal) { modal.addEventListener('click', (event) => { if (event.target === modal) { modal.style.display = 'none'; } }); }
        function updateDateTime() {
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' };
            const dateTimeElement = document.getElementById('currentDateTime');
            if(dateTimeElement) { dateTimeElement.textContent = now.toLocaleString('en-US', options); }
        }
        updateDateTime(); setInterval(updateDateTime, 1000);
        const printBtn = document.getElementById('print-logs-btn');
        if (printBtn) { printBtn.addEventListener('click', () => { window.print(); }); }

        // --- [NEW/MODIFIED] Live Monitoring Polling Script ---
        const livePanel = document.getElementById('live-monitoring-panel');
        const livePhotoContainer = document.getElementById('live-photo-container');
        const liveName = document.getElementById('live-name');
        const liveDepartment = document.getElementById('live-department');
        const liveStatusBadge = document.getElementById('live-status-badge');
        const capturedImageContainer = document.getElementById('live-captured-image-container');
        
        let lastEventTimestamp = 0;
        const POLL_LIVE_INTERVAL = 2000; // 2 seconds

        // --- Helper function to format Unix timestamp ---
        function formatEventTimestamp(unixTimestamp) {
            if (!unixTimestamp) return 'N/A';
            const date = new Date(unixTimestamp * 1000);
            const options = { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
            // Format: Nov 05, 2025 06:26:47 AM (Removed comma)
            return date.toLocaleString('en-US', options).replace(',', '');
        }
        
        // --- [NEW] Function to build and prepend a new table row ---
        function addNewLogToTable(event, badgeClass, badgeText, imageHtml) {
            if (!logsTableBody) return;

            const student = event.student_data;
            // Create the new row
            const newRow = document.createElement('tr');
            // Give it the unique ID
            newRow.id = `log-row-${event.log_id}`; 
            newRow.style.backgroundColor = '#fff3cd'; // Highlight new row
            setTimeout(() => newRow.style.backgroundColor = '', 2000);

            let detailsHtml = '';
            // [FIXED] Use the student's full_name, even on 'denied'
            if (student && student.full_name && student.full_name !== 'Unknown') {
                 detailsHtml = `<div class="d-flex align-items-center"><i class="bx bxs-user-circle me-2 text-primary" style="font-size: 1.5rem;"></i> ${student.full_name}</div>`;
            } else {
                 detailsHtml = '<span class="text-danger">Unknown/Denied User</span>';
            }
            
            // On denied, we might have a specific reason
            if(event.action === 'denied') {
                detailsHtml = `<span class="text-danger">${student.full_name || 'Unknown/Denied User'}</span>`;
            }

            let rfidHtml = student.rfid_uid ? `<code class="bg-light px-2 py-1 rounded">${student.rfid_uid}</code>` : 'N/A';
            
            // --- [FIX] This needs to use the microtime timestamp ---
            const formattedTime = formatEventTimestamp(event.timestamp);
            let timeOutHtml = event.action === 'logout' ? formattedTime : '--';
            
            let timeInHtml = 'N/A';
            if(event.action === 'logout' && event.last_time_in_formatted) {
                timeInHtml = event.last_time_in_formatted; // Use the pre-formatted one if we can
            } else if (event.action !== 'logout') {
                timeInHtml = formattedTime; // This is the login/denied time
            } else {
                 // Failsafe for logout
                 timeInHtml = (event.last_time_in || 'N/A');
            }


            newRow.innerHTML = `
                <td class="cell-time-in">${timeInHtml}</td>
                <td class="cell-time-out">${timeOutHtml}</td>
                <td class="cell-event-type">RFID Scan</td>
                <td class="cell-details">${detailsHtml}</td>
                <td class="cell-rfid">${rfidHtml}</td>
                <td class="cell-status">
                    <span class="badge ${badgeClass}">${badgeText}</span>
                </td>
                <td class="cell-image">${imageHtml}</td>
            `;
            
            const noLogsRow = logsTableBody.querySelector('#no-logs-row');
            if (noLogsRow) {
                noLogsRow.remove();
            }

            logsTableBody.prepend(newRow);
        }
        
        // --- [NEW] This function checks if the live panel has an image and updates the table row ---
        function updateTableRowImage(logId, imagePath) {
            const placeholder = document.getElementById(`img-placeholder-${logId}`);
            if(placeholder) {
                const newButton = document.createElement('button');
                newButton.className = 'btn btn-primary btn-sm view-btn';
                newButton.dataset.imagePath = buildFullPhotoUrl(imagePath);
                newButton.textContent = 'View';
                
                // We must add the event listener MANUALLY here
                newButton.addEventListener('click', (e) => {
                    e.preventDefault();
                    showImageModal(newButton.dataset.imagePath);
                });
                
                placeholder.replaceWith(newButton); // Replace the span with the button
            }
        }

        // --- [MODIFIED] This function updates an existing "Present" row to "Logged Out" ---
        function updateRowForLogout(event) {
            const logId = event.log_id;
            // Try to find row by log ID
            let row = document.getElementById(`log-row-${logId}`);
            
            // If the row doesn't exist on the page, just add a new one (failsafe)
            if (!row) {
                console.warn(`Row ${logId} not found for logout. Adding as new row.`);
                const badgeClass = 'bg-info';
                const badgeText = 'Logged Out';
                let imageHtml = event.captured_image_path 
                    ? `<button class="btn btn-primary btn-sm view-btn" data-image-path="${buildFullPhotoUrl(event.captured_image_path)}">View</button>`
                    : `<span class="text-muted small" id="img-placeholder-${event.log_id}">(Waiting...)</span>`;
                addNewLogToTable(event, badgeClass, badgeText, imageHtml);
                return;
            }

            // Row exists, let's update it!
            row.style.backgroundColor = '#cfe2ff'; // Highlight for update
            setTimeout(() => row.style.backgroundColor = '', 2000);

            // --- [FIX] This needs to use the microtime timestamp ---
            const formattedTime = formatEventTimestamp(event.timestamp);

            // Update Time Out cell
            const timeOutCell = row.querySelector('.cell-time-out');
            if (timeOutCell) {
                timeOutCell.textContent = formattedTime;
            }
            
            // Update Status cell
            const statusCell = row.querySelector('.cell-status');
            if (statusCell) {
                statusCell.innerHTML = `<span class="badge bg-info">Logged Out</span>`;
            }

            // Update Image cell (to show logout image)
            const imageCell = row.querySelector('.cell-image');
            if (imageCell && event.captured_image_path) {
                const newButton = document.createElement('button');
                newButton.className = 'btn btn-primary btn-sm view-btn';
                newButton.dataset.imagePath = buildFullPhotoUrl(event.captured_image_path);
                newButton.textContent = 'View';
                
                newButton.addEventListener('click', (e) => {
                    e.preventDefault();
                    showImageModal(newButton.dataset.imagePath);
                });
                imageCell.innerHTML = ''; // Clear old content
                imageCell.appendChild(newButton); // Add new button
            }
        }
    
        // --- [MODIFIED] REVISED checkLiveEvent function ---
        async function revisedCheckLiveEvent() {
            try {
                // Added cache-busting
                const response = await fetch('../php/get_live_event.php?t=' + Date.now()); 
                if (!response.ok) return;
                const event = await response.json();
                
                if (!event || !event.timestamp) {
                    // [NEW] If event is null (expired), hide the panel
                    if (livePanel.style.display !== 'none') {
                        livePanel.style.display = 'none';
                         // Clear panel content on hide
                        capturedImageContainer.innerHTML = ''; 
                    }
                    return;
                }

                // --- Check for NEW LOG entry ---
                if (event.timestamp > lastEventTimestamp) {
                    lastEventTimestamp = event.timestamp;
                    const student = event.student_data;
                    const action = event.action;
                    
                    // --- 1. Populate Registered Info (Left Side) ---
                    let photoHtml = '';
                    
                    // --- [THIS IS THE FIX] ---
                    // We will ONLY use student.photo_path and build the URL manually.
                    // This avoids any bad data in student.photo_url.
                    let photoUrl = student.photo_path ? buildFullPhotoUrl(student.photo_path) : null;
                    // --- [END OF FIX] ---
                    
                    if (photoUrl) {
                        photoHtml = `<img src="${photoUrl}?t=${Date.now()}" alt="Registered Photo" class="live-photo">`;
                    } else {
                        let icon = (action === 'denied') ? 'bx-x-circle' : 'bx-user';
                        photoHtml = `<span class="live-placeholder"><i class='bx ${icon}'></i></span>`;
                    }
                    livePhotoContainer.innerHTML = photoHtml;
                    liveName.textContent = student.full_name || 'Unknown';
                    liveDepartment.textContent = student.department || '';
                    
                    // --- 2. Populate Status Badge ---
                    let badgeClass = 'bg-secondary';
                    let badgeText = action;
                    if (action === 'login') { badgeClass = 'bg-success'; badgeText = 'Logged In'; }
                    else if (action === 'logout') { badgeClass = 'bg-info'; badgeText = 'Logged Out'; }
                    else if (action === 'denied') { badgeClass = 'bg-danger'; badgeText = 'Access Denied'; }
                    liveStatusBadge.innerHTML = `<span class="badge ${badgeClass}">${badgeText}</span>`;
                    
                    // --- 3. Show the Panel ---
                    livePanel.style.display = 'block'; // Make sure panel is visible
                    livePanel.classList.toggle('is-denied', action === 'denied');
                    
                    // Clear captured image from previous event
                    capturedImageContainer.innerHTML = `<div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><small class="text-muted d-block mt-1">Waiting for image...</small>`;

                    // --- [MODIFIED] 4. Add to table (or update) ---
                    if (action === 'logout') {
                        updateRowForLogout(event);
                    } else {
                        // This is a login or denied, add a NEW row
                        let initialImageHtml = `<span class="text-muted small" id="img-placeholder-${event.log_id}">(Waiting...)</span>`;
                        // Check if image was sent *with* the event
                        if(event.captured_image_path) {
                            initialImageHtml = `<button class="btn btn-primary btn-sm view-btn" data-image-path="${buildFullPhotoUrl(event.captured_image_path)}">View</button>`;
                            // Update live panel immediately
                             capturedImageContainer.innerHTML = `
                                <img src="${buildFullPhotoUrl(event.captured_image_path)}?t=${Date.now()}" alt="Captured Image" class="live-photo">
                                <small class="text-muted d-block mt-1">Captured Image</small>
                            `;
                        }
                        addNewLogToTable(event, badgeClass, badgeText, initialImageHtml);
                    }
                }
                
                // --- Check for IMAGE UPDATE on existing event ---
                if (event.log_id && event.captured_image_path) {
                    // Update live panel
                    if (livePanel.style.display === 'block') {
                        const currentCapturedImg = capturedImageContainer.querySelector('img');
                        // Update if panel is showing a spinner
                        if (!currentCapturedImg) {
                            capturedImageContainer.innerHTML = `
                                <img src="${buildFullPhotoUrl(event.captured_image_path)}?t=${Date.now()}" alt="Captured Image" class="live-photo">
                                <small class="text-muted d-block mt-1">Captured Image</small>
                            `;
                        }
                    }
                    // Update table row
                    updateTableRowImage(event.log_id, event.captured_image_path);
                }

            } catch (error) {
                console.error("Live event poll error:", error);
            }
        }
        
        function buildFullPhotoUrl(path) { 
            if (!path) return null;
            if (path.startsWith('http')) return path; // Already a full URL
            return '../' + path; // Make it relative
        }
        
        // --- Run the Polling ---
        setInterval(revisedCheckLiveEvent, POLL_LIVE_INTERVAL);
        
        // --- [FIX] This needs to be a microtime-safe timestamp parser ---
        function formatEventTimestamp(microtime) {
            if (!microtime) return 'N/A';
            const date = new Date(microtime * 1000); // Convert seconds to milliseconds
            const options = { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
            return date.toLocaleString('en-US', options).replace(',', '');
        }
    </script>
</body>
</html>