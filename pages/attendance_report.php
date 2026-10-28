<?php
date_default_timezone_set('Asia/Manila');
session_start();

// Authentication Check (ensure admin)
if (!isset($_SESSION['user_id']) || !($_SESSION['is_superuser'] ?? false) || !($_SESSION['is_admin'] ?? false)) {
    header('Location: ../html/login.html');
    exit;
}

// Database Connection
$db = require_once(__DIR__ . '/../php/db_connect.php'); // Uses PDO

// --- *** NEW: Pagination and Filtering Setup *** ---
$records_per_page = 20; // How many records per page
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;
$offset = ($current_page - 1) * $records_per_page;

// Get filter values from URL (GET parameters)
$filter_name = $_GET['search_name'] ?? '';
$filter_status = $_GET['status'] ?? ''; // 'present', 'out', 'questionable_in', 'questionable_out', or '' (all)
// --- *** END NEW *** ---


// --- *** MODIFIED: Function to fetch attendance logs with filters and pagination *** ---
function fetchAttendanceLogs($db, $limit, $offset, $filter_name, $filter_status) {
    $logs = [];
    $params = []; // For prepared statement bindings
    $whereClauses = [];

    // Base query
    $query = "
        SELECT
            att.log_id,
            att.time_in, -- Fetch raw time for calculation
            att.time_out, -- Fetch raw time for calculation
            DATE_FORMAT(att.time_in, '%b %d, %Y, %h:%i:%s %p') AS formatted_time_in,
            DATE_FORMAT(att.time_out, '%b %d, %Y, %h:%i:%s %p') AS formatted_time_out,
            att.status,
            s.full_name,
            s.student_number
        FROM attendance_logs att
        LEFT JOIN students s ON att.student_id = s.id
    ";

    // Build WHERE clause based on filters
    if (!empty($filter_name)) {
        $whereClauses[] = "s.full_name LIKE ?";
        $params[] = '%' . $filter_name . '%';
    }
    if (!empty($filter_status)) {
        $whereClauses[] = "att.status = ?";
        $params[] = $filter_status;
    }

    if (!empty($whereClauses)) {
        $query .= " WHERE " . implode(' AND ', $whereClauses);
    }

    $query .= " ORDER BY att.time_in DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    try {
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate duration
        foreach ($logs as &$log) {
            $duration = 'N/A';
            if ($log['time_in'] && $log['time_out']) { // Use raw times
                try {
                    $timeIn = new DateTime($log['time_in']);
                    $timeOut = new DateTime($log['time_out']);
                    if ($timeOut > $timeIn) {
                         $interval = $timeIn->diff($timeOut);
                         $duration = $interval->format('%h h %i m');
                    }
                } catch (Exception $e) {
                     error_log("Error parsing date for duration calculation: " . $e->getMessage());
                     $duration = 'Error';
                }
            }
            $log['duration'] = $duration;
        }
        unset($log); // Break reference

    } catch (PDOException $e) {
        error_log("Error fetching filtered attendance logs: " . $e->getMessage());
        $GLOBALS['errorMessage'] = "Error retrieving attendance data."; // Set global error message
    }
    return $logs;
}
// --- *** END MODIFIED *** ---


// --- *** NEW: Function to count total filtered attendance logs *** ---
function countTotalAttendanceLogs($db, $filter_name, $filter_status) {
    $total = 0;
    $params = [];
    $whereClauses = [];

    $query = "SELECT COUNT(*) FROM attendance_logs att LEFT JOIN students s ON att.student_id = s.id";

    if (!empty($filter_name)) {
        $whereClauses[] = "s.full_name LIKE ?";
        $params[] = '%' . $filter_name . '%';
    }
    if (!empty($filter_status)) {
        $whereClauses[] = "att.status = ?";
        $params[] = $filter_status;
    }

    if (!empty($whereClauses)) {
        $query .= " WHERE " . implode(' AND ', $whereClauses);
    }

    try {
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $total = $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error counting filtered attendance logs: " . $e->getMessage());
    }
    return $total;
}
// --- *** END NEW *** ---

// --- Fetch data for the current page ---
$initialAttendanceLogs = fetchAttendanceLogs($db, $records_per_page, $offset, $filter_name, $filter_status);
$total_records = countTotalAttendanceLogs($db, $filter_name, $filter_status);
$total_pages = ceil($total_records / $records_per_page);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CMU RFID Portal - Attendance Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/boxicons@latest/css/boxicons.min.css" rel="stylesheet">
    <link href="../css/dashboard.css" rel="stylesheet">
    <style>
/* Basic styles reused across pages */
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
        .attendance-table { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden; margin-bottom: 1rem; }
        .print-button-container { margin-top: 1rem; text-align: right; }
        .badge.bg-warning { color: #000 !important; }
        .badge.bg-secondary { color: #fff !important; }
        .badge.bg-success { color: #fff !important; }

        /* --- Styles for Filters and Pagination --- */
        .filter-form {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }
        .pagination-nav {
            margin-top: 1.5rem;
            display: flex;
            justify-content: center;
        }
        /* --- END Styles --- */

        /* --- *** ADDED RULES TO FIX FILTER LAYOUT *** --- */
        .filter-form .row {
            display: flex !important; /* Force flex display for row */
            flex-wrap: wrap !important; /* Allow wrapping */
            margin-right: -0.75rem !important; /* Restore Bootstrap gutter */
            margin-left: -0.75rem !important; /* Restore Bootstrap gutter */
        }
        .filter-form .row > [class*='col-'] {
            padding-right: 0.75rem !important; /* Restore Bootstrap gutter */
            padding-left: 0.75rem !important; /* Restore Bootstrap gutter */
            flex-shrink: 0 !important; /* Ensure columns don't shrink unexpectedly */
            flex-grow: 0 !important; /* Ensure columns take up their defined space */
            margin-bottom: 1rem !important; /* Add bottom margin for spacing when wrapped */
        }
        .filter-form .form-label {
             margin-bottom: 0.5rem !important; /* Standard Bootstrap margin */
        }
        /* --- *** END FIX *** --- */

        /* --- *** PRINT STYLES BLOCK *** --- */
        @media print {
            body {
                background: white !important;
                color: black !important;
                font-size: 10pt;
                margin: 0 !important;
                padding: 0 !important;
            }
            .sidebar, header, .print-button-container, .filter-form, .pagination-nav /* Hide pagination too */ {
                display: none !important;
            }
            .content-wrapper {
                margin-left: 0 !important;
                padding: 1cm !important;
                width: 100% !important;
                box-shadow: none !important;
                border: none !important;
            }
            .attendance-table {
                box-shadow: none !important;
                border: 1px solid #ccc !important;
                border-radius: 0 !important;
                margin: 0 !important;
                overflow: visible !important;
            }
            .table-responsive {
                 overflow: visible !important;
            }
            table {
                width: 100% !important;
                border-collapse: collapse !important;
                table-layout: auto !important;
            }
            th, td {
                border: 1px solid #ccc !important;
                padding: 4px 8px !important;
                color: black !important;
                background-color: white !important;
                word-wrap: break-word;
            }
            thead th {
                background-color: #eee !important;
                -webkit-print-color-adjust: exact !important;
                color-adjust: exact !important;
                 font-weight: bold;
            }
            tbody tr {
                page-break-inside: avoid !important;
            }
            /* Style for badge text in print (no background/border) */
            .badge {
                border: none !important;
                background-color: transparent !important;
                color: black !important;
                padding: 0 !important;
                font-size: inherit !important;
                font-weight: normal !important;
                display: inline !important;
                vertical-align: baseline !important;
                box-shadow: none !important;
                text-shadow: none !important;
            }
            h1.print-title {
                display: block !important;
                text-align: center;
                margin-bottom: 20px !important;
                font-size: 14pt !important;
                color: black !important;
            }
            h1.h2 { /* Hide the original screen title */
                display: none !important;
            }
            a { text-decoration: none; color: black !important; }
        }
        /* --- *** END PRINT BLOCK *** --- */

         /* --- Hide print title by default --- */
         h1.print-title {
             display: none;
         }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2><i class='bx bxs-shield'></i>CMU Portal</h2>
        <ul>
            <li><a href="../html/dashboard.php" <?php if(basename($_SERVER['PHP_SELF']) == 'dashboard.php') echo 'class="active"'; ?>><i class='bx bxs-dashboard'></i>Dashboard</a></li>
            <li><a href="logs.php" <?php if(basename($_SERVER['PHP_SELF']) == 'logs.php') echo 'class="active"'; ?>><i class='bx bx-history'></i>All Security Logs</a></li>
            <li><a href="attendance_report.php" <?php if(basename($_SERVER['PHP_SELF']) == 'attendance_report.php') echo 'class="active"'; ?>><i class='bx bx-time'></i>Attendance Report</a></li>
            <li><a href="students.php" <?php if(basename($_SERVER['PHP_SELF']) == 'students.php') echo 'class="active"'; ?>><i class='bx bxs-user-detail'></i>Students</a></li>
            <hr style="border:none;border-top:1px solid rgba(255,255,255,0.1);margin:12px 0;">
            <li><a href="../php/logout.php"><i class='bx bx-log-out'></i>Logout</a></li>
        </ul>
    </div>

    <div class="content-wrapper">
        <header class="mb-4">
             <div class="container-fluid px-0">
                 <div class="d-flex justify-content-between align-items-center">
                     <h1 class="h2 mb-0">Student Attendance Report</h1>
                     <div id="currentDateTime" class="text-primary text-nowrap"></div>
                 </div>
             </div>
        </header>

        <h1 class="print-title">Student Attendance Report - <?php echo date('F d, Y'); ?></h1>

        <div class="filter-form card card-body mb-4">
            <form method="GET" action="attendance_report.php">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-5 col-md-12">
                        <label for="search_name" class="form-label">Search by Name</label>
                        <input type="text" class="form-control form-control-sm" id="search_name" name="search_name" value="<?php echo htmlspecialchars($filter_name); ?>" placeholder="Enter student name...">
                    </div>
                    <div class="col-lg-5 col-md-6 col-sm-6">
                        <label for="status" class="form-label">Status</label>
                        <select id="status" name="status" class="form-select form-select-sm">
                            <option value="" <?php if ($filter_status == '') echo 'selected'; ?>>All Statuses</option>
                            <option value="present" <?php if ($filter_status == 'present') echo 'selected'; ?>>Present</option>
                            <option value="out" <?php if ($filter_status == 'out') echo 'selected'; ?>>Out</option>
                            <option value="questionable_in" <?php if ($filter_status == 'questionable_in') echo 'selected'; ?>>Questionable In</option>
                            <option value="questionable_out" <?php if ($filter_status == 'questionable_out') echo 'selected'; ?>>Questionable Out</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-12">
                        <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
                    </div>
                </div>
            </form>
        </div>
        <div class="container-fluid px-0">
            <div class="attendance-table">
                 <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th>Student Name</th>
                                <th>Student Number</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Duration</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="attendance-table-body">
                            <?php if (isset($errorMessage)): ?>
                                <tr><td colspan="6" class="text-center text-danger py-4"><?php echo htmlspecialchars($errorMessage); ?></td></tr>
                            <?php elseif (!empty($initialAttendanceLogs)): ?>
                                <?php foreach($initialAttendanceLogs as $log): ?>
                                    <?php
                                        // Determine status badge class
                                        $status_class = 'bg-secondary'; // Default for 'out'
                                        if ($log['status'] === 'present') {
                                            $status_class = 'bg-success';
                                        } elseif (str_contains($log['status'], 'questionable')) {
                                            $status_class = 'bg-warning text-dark';
                                        }
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($log['full_name'] ?? 'Unknown Student'); ?></td>
                                        <td><?php echo htmlspecialchars($log['student_number'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($log['formatted_time_in'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($log['formatted_time_out'] ?? '--'); ?></td>
                                        <td><?php echo htmlspecialchars($log['duration']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $status_class; ?>">
                                                <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $log['status']))); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr id="no-logs-row"><td colspan="6" class="text-center py-4">No attendance logs found matching filters</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($total_pages > 1): ?>
             <nav aria-label="Page navigation" class="pagination-nav">
                 <ul class="pagination pagination-sm">
                     <?php
                        $queryParams = $_GET; unset($queryParams['page']);
                        $queryString = http_build_query($queryParams);
                        if ($queryString) $queryString .= '&';

                        if ($current_page > 1): ?>
                            <li class="page-item"><a class="page-link" href="?<?php echo $queryString; ?>page=<?php echo $current_page - 1; ?>">Previous</a></li>
                        <?php else: ?>
                            <li class="page-item disabled"><span class="page-link">Previous</span></li>
                        <?php endif;

                        $start_page = max(1, $current_page - 2); $end_page = min($total_pages, $current_page + 2);
                        if ($start_page > 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?php if ($i == $current_page) echo 'active'; ?>"><a class="page-link" href="?<?php echo $queryString; ?>page=<?php echo $i; ?>"><?php echo $i; ?></a></li>
                        <?php endfor;
                         if ($end_page < $total_pages) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';

                        if ($current_page < $total_pages): ?>
                            <li class="page-item"><a class="page-link" href="?<?php echo $queryString; ?>page=<?php echo $current_page + 1; ?>">Next</a></li>
                        <?php else: ?>
                            <li class="page-item disabled"><span class="page-link">Next</span></li>
                        <?php endif;
                     ?>
                 </ul>
             </nav>
             <?php endif; ?>
            <div class="print-button-container mt-3 text-end">
                <button class="btn btn-secondary btn-sm" onclick="window.print();">
                    <i class='bx bx-printer me-1'></i> Print Report
                </button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Refresh the page every 30 seconds
        setInterval(() => { window.location.reload(); }, 30000);

        // Simple script for updating date/time
        function updateDateTime() {
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' };
            const dateTimeElement = document.getElementById('currentDateTime');
             if(dateTimeElement) { dateTimeElement.textContent = now.toLocaleDateString('en-US', options); }
        }
        updateDateTime();
        setInterval(updateDateTime, 1000);
    </script>
</body>
</html>