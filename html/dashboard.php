<?php
date_default_timezone_set('Asia/Manila');
session_start();

// Ensure the user is logged in and is a superuser
if (!isset($_SESSION['user_id']) || !($_SESSION['is_superuser'] ?? false)) {
    header('Location: login.html');
    exit;
}

// Establish database connection (Path: html/../php/db_connect.php)
$db = require_once(__DIR__ . '/../php/db_connect.php');
if (!$db) {
    die("Error: Could not connect to the database. Please check the configuration.");
}

// --- Fetch Dashboard Statistics ---
$totalStudents = $db->query("SELECT COUNT(*) FROM students WHERE status = 'active'")->fetchColumn();
$todayLogs = $db->query("SELECT COUNT(*) FROM attendance_logs WHERE DATE(time_in) = CURRENT_DATE")->fetchColumn();
$totalDenied = $db->query("SELECT COUNT(*) FROM access_logs WHERE access_type = 'denied'")->fetchColumn();

// --- Fetch the 5 most recent logs (Combined view) ---
// --- [MODIFIED] Query now fetches captured_image_path ---
$recentLogs = [];
try {
    $recentLogsQuery = "
        (SELECT
            att.log_id as log_id,
            att.time_in as raw_timestamp,
            DATE_FORMAT(att.time_in, '%b %d, %Y, %h:%i:%s %p') AS formatted_timestamp,
            CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name, s.suffix) as full_name,
            s.rfid_uid,
            CASE WHEN att.status LIKE '%questionable%' THEN 'questionable' ELSE 'granted' END as access_type,
            CASE WHEN att.time_out IS NULL THEN att.image_in_path ELSE att.image_out_path END as captured_image_path
        FROM attendance_logs att
        JOIN students s ON att.student_id = s.id
        )
        UNION ALL
        (SELECT
            al.id as log_id,
            al.timestamp as raw_timestamp,
            DATE_FORMAT(al.timestamp, '%b %d, %Y, %h:%i:%s %p') AS formatted_timestamp,
            '(Denied User)' as full_name,
            al.rfid_uid,
            al.access_type,
            al.captured_image_path
        FROM access_logs al
        WHERE al.access_type = 'denied'
        )
        ORDER BY raw_timestamp DESC
        LIMIT 5"; // <-- Set to 5
    $stmt = $db->query($recentLogsQuery);
    $recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($recentLogs as &$log) {
        $log['timestamp'] = $log['formatted_timestamp'];
    }
    unset($log);

} catch (PDOException $e) {
    error_log("Error fetching recent logs for dashboard: " . $e->getMessage());
    $dashboardError = "Could not load recent activity.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CMU RFID Portal - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/boxicons@latest/css/boxicons.min.css" rel="stylesheet">
    <link href="../css/dashboard.css" rel="stylesheet">
    <style>
        /* Minimal styles for dashboard elements, mostly rely on dashboard.css */
        .dashboard-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: white; border-radius: 12px; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .recent-activity { background: white; border-radius: 12px; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .badge.bg-success { color: white !important; }
        .badge.bg-danger { color: white !important; }
        .badge.bg-warning { color: black !important; }
        .badge.bg-info { color: white !important; }
        .badge.bg-secondary { color: white !important; }
        
        /* --- Live Panel CSS --- */
        #live-monitoring-panel {
            background-color: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
            overflow: hidden;
            transition: all 0.3s ease;
            border: 2px solid #dee2e6; /* Default idle border */
            padding: 1rem;
            min-height: 200px; 
            align-items: center;
        }
        #live-monitoring-panel.is-denied { border-color: #dc3545; }
        #live-monitoring-panel.is-granted { border-color: #198754; } 
        #live-monitoring-panel .live-photo {
            width: 150px; height: 150px; object-fit: cover;
            border-radius: 50%; border: 4px solid #eee;
        }
        #live-monitoring-panel .live-placeholder {
            width: 150px; height: 150px; border-radius: 50%;
            background-color: #e9ecef; display: flex;
            align-items: center; justify-content: center;
            color: #6c757d; font-size: 4rem; border: 4px solid #eee;
        }

        /* --- Image Modal CSS --- */
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0, 0, 0, 0.8); }
        .modal-content { margin: 10% auto; padding: 20px; background: #fff; border-radius: 8px; max-width: 90%; max-height: 80%; width: auto; height: auto; display: block; position: relative; }
        .modal-content img { max-width: 100%; max-height: 70vh; display: block; margin: 0 auto; }
        .modal-close { position: absolute; top: 15px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; transition: 0.3s; }
        .modal-close:hover, .modal-close:focus { color: #bbb; text-decoration: none; cursor: pointer; }
        .view-btn { padding: 0.1rem 0.5rem; font-size: 0.8rem; }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2><i class='bx bxs-shield'></i>CMU Portal</h2>
        <ul>
             <li><a href="dashboard.php" <?php if(basename($_SERVER['PHP_SELF']) == 'dashboard.php') echo 'class="active"'; ?>><i class='bx bxs-dashboard'></i>Dashboard</a></li>
            <li><a href="../pages/logs.php" <?php if(basename($_SERVER['PHP_SELF']) == 'logs.php') echo 'class="active"'; ?>><i class='bx bx-history'></i>All Security Logs</a></li>
            <li><a href="../pages/students.php" <?php if(basename($_SERVER['PHP_SELF']) == 'students.php') echo 'class="active"'; ?>><i class='bx bxs-user-detail'></i>Students</a></li>
            <hr style="border:none;border-top:1px solid rgba(255,255,255,0.1);margin:12px 0;">
            <li><a href="../php/logout.php"><i class='bx bx-log-out'></i>Logout</a></li>
        </ul>
    </div>

    <main class="content">
        <header class="mb-4">
             <div class="container-fluid px-0">
                 <div class="d-flex justify-content-between align-items-center">
                     <h1 class="h2 mb-0">Dashboard Overview</h1>
                     <div id="currentDateTime" class="text-primary text-nowrap"></div>
                 </div>
             </div>
        </header>
        <div class="container-fluid px-0">

            <div class="dashboard-stats">
                <div class="stat-card">
                    <div class="card-header"> <i class='bx bxs-user-account'></i> <h3>Total Students</h3> </div>
                    <p class="card-value"><?php echo htmlspecialchars($totalStudents ?? 0); ?></p>
                </div>
                <div class="stat-card">
                     <div class="card-header"> <i class='bx bx-calendar-check'></i> <h3>Today's Attendance Logs</h3> </div>
                    <p class="card-value"><?php echo htmlspecialchars($todayLogs ?? 0); ?></p>
                </div>
                <div class="stat-card denied"> 
                    <div class="card-header"> <i class='bx bxs-error'></i> <h3>Access Denied (Total)</h3> </div>
                    <p class="card-value"><?php echo htmlspecialchars($totalDenied ?? 0); ?></p>
                </div>
            </div>
            
            <div id="live-monitoring-panel" class="p-3">
                <div class="row w-100 align-items-center" id="live-panel-content">
                    <div class="col-md-2 text-center" id="live-photo-container">
                        <span class="live-placeholder"><i class='bx bx-time-five'></i></span>
                    </div>
                    <div class="col-md-5" id="live-details-container">
                        <h4 class="mb-0" id="live-name">Idle</h4>
                        <p class="mb-0 text-muted" id="live-department">Waiting for scan...</p>
                        <h5 class="mb-0" id="live-status-badge"></h5>
                    </div>
                    <div class="col-md-5 text-center" id="live-captured-image-container">
                        <span class="live-placeholder"><i class='bx bx-camera-off'></i></span>
                    </div>
                </div>
            </div>

            <div class="recent-activity">
                 <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h4 mb-0">Recent Activity</h2>
                    <a href="../pages/logs.php" class="btn btn-outline-primary btn-sm ms-auto">View All Logs</a>
                 </div>
                 <div class="table-responsive">
                    <table class="table table-hover table-sm" style="margin-bottom: 0;"> 
                        <thead style="background: var(--secondary-color);">
                            <tr>
                                <th>Time</th>
                                <th>Student Name</th>
                                <th>RFID UID</th>
                                <th>Status</th>
                                <th>Image</th>
                            </tr>
                        </thead>
                        <tbody id="recent-activity-body">
                            <?php if (isset($dashboardError)): ?>
                                <tr><td colspan="5" class="text-center text-danger"><?php echo htmlspecialchars($dashboardError); ?></td></tr>
                            <?php elseif (!empty($recentLogs)): ?>
                                <?php foreach($recentLogs as $row): ?>
                                <?php
                                    $status_class = 'bg-danger'; // Default Denied
                                    if ($row['access_type'] === 'granted') { $status_class = 'bg-success'; }
                                    elseif ($row['access_type'] === 'questionable') { $status_class = 'bg-info'; }
                                    elseif ($row['access_type'] === 'out') { $status_class = 'bg-secondary'; }
                                ?>
                                <tr id="dash-log-row-<?php echo $row['log_id']; ?>">
                                    <td><?php echo htmlspecialchars($row['timestamp'] ?? 'N/A'); ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <i class='bx bxs-user-circle me-2' style="font-size: 1.5rem; color: var(--primary-color);"></i>
                                            <?php echo htmlspecialchars($row['full_name'] ?? 'Unknown'); ?>
                                        </div>
                                    </td>
                                    <td><code class="bg-light px-2 py-1 rounded"><?php echo htmlspecialchars($row['rfid_uid'] ?? 'N/A'); ?></code></td>
                                    <td>
                                        <span class="badge <?php echo $status_class; ?>">
                                            <?php echo ucfirst(htmlspecialchars($row['access_type'] ?? 'Error')); ?>
                                        </span>
                                    </td>
                                    <td class="cell-image">
                                        <?php if (!empty($row['captured_image_path'])): ?>
                                            <button class="btn btn-primary btn-sm view-btn" data-image-path="../<?php echo htmlspecialchars($row['captured_image_path']); ?>">View</button>
                                        <?php else: ?>
                                            <span class="text-muted small" id="img-placeholder-dash-<?php echo $row['log_id']; ?>">(No image)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr id="no-logs-row">
                                    <td colspan="5" class="text-center py-3">No recent activity found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>
    
    <div id="image-modal" class="modal">
        <span class="modal-close" id="modal-close-btn">&times;</span>
        <div class="modal-content">
            <img src="" id="modal-image" alt="Captured Image">
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // --- [FIX] Consolidated all element selectors here ---
        const modal = document.getElementById('image-modal');
        const modalImage = document.getElementById('modal-image');
        const closeModalBtn = document.getElementById('modal-close-btn');
        const recentActivityBody = document.getElementById('recent-activity-body'); 
        const dateTimeElement = document.getElementById('currentDateTime');
        const livePanel = document.getElementById('live-monitoring-panel');
        const livePanelContent = document.getElementById('live-panel-content');
        
        // --- Image Modal Logic ---
        function showImageModal(imagePath) {
            if (modalImage && modal) { modalImage.src = imagePath; modal.style.display = 'block'; }
        }
        
        // --- [FIX] This is the one-and-only click listener for the modal ---
        // It uses event delegation, so it works for buttons added by PHP and JS.
        if (recentActivityBody) {
            recentActivityBody.addEventListener('click', (event) => {
                const target = event.target;
                if (target.classList.contains('view-btn') && target.dataset.imagePath) {
                    event.preventDefault();
                    showImageModal(target.dataset.imagePath);
                }
            });
        }
        if(closeModalBtn) { closeModalBtn.addEventListener('click', () => { modal.style.display = 'none'; }); }
        if(modal) { modal.addEventListener('click', (event) => { if (event.target === modal) { modal.style.display = 'none'; } }); }

        // --- Date/Time Logic (Unchanged) ---
        function updateDateTime() {
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' };
            if (dateTimeElement) {
                dateTimeElement.textContent = now.toLocaleDateString('en-US', options);
            }
        }
        updateDateTime();
        setInterval(updateDateTime, 1000);
        
        // --- Live Feed Logic ---
        let lastEventTimestamp = 0;
        let currentPanelState = 'idle'; 
        const POLL_LIVE_INTERVAL = 2000; 

        async function checkLiveEvent() {
            try {
                const response = await fetch('../php/get_live_event.php?t=' + Date.now());
                if (!response.ok) return;
                
                const event = await response.json();

                // --- CASE 1: Event is ACTIVE (Scan just happened) ---
                if (event && event.timestamp) {
                    if (event.timestamp > lastEventTimestamp) {
                        lastEventTimestamp = event.timestamp;
                        renderScanState(event); 
                        addNewLogToTable(event); 
                        currentPanelState = 'scan';
                    } 
                    else if (currentPanelState === 'scan' && event.captured_image_path) {
                        renderScanState(event); // Re-render to show image
                        updateDashboardTableRowImage(event.log_id, event.captured_image_path);
                    }
                } 
                // --- CASE 2: Event is INACTIVE (null) ---
                else {
                    if (currentPanelState !== 'idle') {
                        renderIdleState();
                        currentPanelState = 'idle';
                    }
                }
            } catch (error) {
                console.error("Live event poll error:", error);
            }
        }

        function renderIdleState() {
            livePanel.classList.remove('is-denied', 'is-granted');
            livePanel.style.borderColor = '#dee2e6';
            
            const idleHtml = `
                <div class="col-md-2 text-center" id="live-photo-container">
                    <span class="live-placeholder"><i class='bx bx-time-five'></i></span>
                </div>
                <div class="col-md-5" id="live-details-container">
                    <h4 class="mb-0" id="live-name">Idle</h4>
                    <p class="mb-0 text-muted" id="live-department">Waiting for scan...</p>
                    <h5 class="mb-0" id="live-status-badge"></h5>
                </div>
                <div class="col-md-5 text-center" id="live-captured-image-container">
                    <span class="live-placeholder"><i class='bx bx-camera-off'></i></span>
                </div>
            `;
            livePanelContent.innerHTML = idleHtml;
        }

        function renderScanState(event) {
            const student = event.student_data;
            const action = event.action;

            let photoHtml = '';
            let photoUrl = student.photo_path ? buildFullPhotoUrl(student.photo_path) : null; 
            if (photoUrl) {
                photoHtml = `<img src="${photoUrl}?t=${Date.now()}" alt="Registered Photo" class="live-photo">`;
            } else {
                let icon = (action === 'denied') ? 'bx-x-circle' : 'bx-user';
                photoHtml = `<span class="live-placeholder"><i class='bx ${icon}'></i></span>`;
            }

            let badgeClass = 'bg-secondary';
            let badgeText = action;
            if (action === 'login') { badgeClass = 'bg-success'; badgeText = 'Logged In'; }
            else if (action === 'logout') { badgeClass = 'bg-info'; badgeText = 'Logged Out'; }
            else if (action === 'denied') { badgeClass = 'bg-danger'; badgeText = 'Access Denied'; }
            
            const detailsHtml = `
                <h4 class="mb-0" id="live-name">${student.full_name || 'Unknown'}</h4>
                <p class="mb-0 text-muted" id="live-department">${student.department || ''}</p>
                <h5 class="mb-0" id="live-status-badge"><span class="badge ${badgeClass}">${badgeText}</span></h5>
            `;

            let capturedHtml = '';
            if (event.captured_image_path) {
                capturedHtml = `
                    <img src="${buildFullPhotoUrl(event.captured_image_path)}?t=${Date.now()}" alt="Captured Image" class="live-photo">
                    <small class="text-muted d-block mt-1">Captured Image</small>
                `;
            } else {
                capturedHtml = `
                    <div class="live-placeholder">
                        <div class="spinner-border spinner-border-sm" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <small class="text-muted d-block mt-1">Waiting for capture...</small>
                `;
            }
            
            livePanelContent.innerHTML = `
                <div class="col-md-2 text-center">${photoHtml}</div>
                <div class="col-md-5">${detailsHtml}</div>
                <div class="col-md-5 text-center">${capturedHtml}</div>
            `;
            
            livePanel.classList.toggle('is-denied', action === 'denied');
            livePanel.classList.toggle('is-granted', action !== 'denied');
            livePanel.style.borderColor = ''; 
        }

        function addNewLogToTable(event) {
            const student = event.student_data;
            const action = event.action;
            
            const noLogsRow = document.getElementById('no-logs-row');
            if (noLogsRow) {
                noLogsRow.remove();
            }

            const newRow = document.createElement('tr');
            newRow.id = `dash-log-row-${event.log_id}`;
            newRow.style.backgroundColor = '#fff3cd'; 
            setTimeout(() => newRow.style.backgroundColor = '', 2000);
            
            let badgeClass = 'bg-danger'; 
            let accessType = action;
            if (action === 'login') { badgeClass = 'bg-success'; accessType = 'granted'; }
            else if (action === 'logout') { badgeClass = 'bg-info'; accessType = 'out'; }
            
            const formattedTime = formatEventTimestamp(event.timestamp);

            let imageHtml = `<span class="text-muted small" id="img-placeholder-dash-${event.log_id}">(Waiting...)</span>`;
            if (event.captured_image_path) {
                imageHtml = `<button class="btn btn-primary btn-sm view-btn" data-image-path="${buildFullPhotoUrl(event.captured_image_path)}">View</button>`;
            }

            newRow.innerHTML = `
                <td>${formattedTime}</td>
                <td>
                    <div class="d-flex align-items-center">
                        <i class='bx bxs-user-circle me-2' style="font-size: 1.5rem; color: var(--primary-color);"></i>
                        ${student.full_name || 'Unknown'}
                    </div>
                </td>
                <td><code class="bg-light px-2 py-1 rounded">${student.rfid_uid || 'N/A'}</code></td>
                <td>
                    <span class="badge ${badgeClass}">
                        ${ucfirst(accessType)}
                    </span>
                </td>
                <td>${imageHtml}</td>
            `;
            
            if (recentActivityBody) {
                recentActivityBody.prepend(newRow);
                while (recentActivityBody.rows.length > 5) {
                    recentActivityBody.deleteRow(recentActivityBody.rows.length - 1);
                }
            }
        }
        
        // --- [FIX] This function no longer needs a manual event listener ---
        // The main listener at the top of the script handles all clicks.
        function updateDashboardTableRowImage(logId, imagePath) {
            const placeholder = document.getElementById(`img-placeholder-dash-${logId}`);
            if(placeholder) {
                const newButton = document.createElement('button');
                newButton.className = 'btn btn-primary btn-sm view-btn';
                newButton.dataset.imagePath = buildFullPhotoUrl(imagePath);
                newButton.textContent = 'View';
                placeholder.replaceWith(newButton); // Replace the span with the button
            }
        }

        // --- Helper Functions (Unchanged) ---
        function buildFullPhotoUrl(path) { 
            return '../' + path; 
        }
        function ucfirst(str) {
            return str.charAt(0).toUpperCase() + str.slice(1);
        }
        function formatEventTimestamp(unixTimestamp) {
            if (!unixTimestamp) return 'N/A';
            const date = new Date(unixTimestamp * 1000);
            const options = { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
            return date.toLocaleString('en-US', options).replace(',', '');
        }

        // --- Start Polling ---
        setInterval(checkLiveEvent, POLL_LIVE_INTERVAL);
    </script>
</body>
</html>