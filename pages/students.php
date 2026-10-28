<?php
date_default_timezone_set('Asia/Manila');
session_start();

// --- Authentication Check ---
if (!isset($_SESSION['user_id']) || !($_SESSION['is_superuser'] ?? false) || !($_SESSION['is_admin'] ?? false)) {
    header('Location: ../html/login.html');
    exit;
}

// --- Database Connection ---
$db = require_once(__DIR__ . '/../php/db_connect.php');

// --- Configuration ---
define('UPLOAD_DIR_PATH', __DIR__ . '/../uploads/'); 
define('ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/gif']);
define('MAX_FILE_SIZE', 2 * 1024 * 1024); // 2 MB limit

// --- [NEW] Get Filter Values ---
$filter_dept = $_GET['department'] ?? '';
$filter_year = $_GET['year'] ?? '';
$filter_section = $_GET['section'] ?? '';
$filter_status = $_GET['status'] ?? '';

// --- Image Compression Function (UNCHANGED) ---
function compressImage($sourcePath, $destinationPath, $maxWidth = 100, $maxHeight = 100, $quality = 75) {
    $imageInfo = @getimagesize($sourcePath);
    if (!$imageInfo) { error_log("compressImage: Failed to get image info for $sourcePath"); return false; }
    $mime = $imageInfo['mime']; $originalWidth = $imageInfo[0]; $originalHeight = $imageInfo[1];
    $image = null;
    switch ($mime) {
        case 'image/jpeg': $image = @imagecreatefromjpeg($sourcePath); break;
        case 'image/png':
            $image = @imagecreatefrompng($sourcePath);
             if ($image) {
                 $bg = imagecreatetruecolor($originalWidth, $originalHeight);
                 imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
                 imagealphablending($bg, TRUE);
                 imagecopy($bg, $image, 0, 0, 0, 0, $originalWidth, $originalHeight);
                 imagedestroy($image); $image = $bg;
             }
            break;
        case 'image/gif': $image = @imagecreatefromgif($sourcePath); break;
        default: error_log("compressImage: Unsupported image type: $mime"); return false;
    }
    if (!$image) { error_log("compressImage: Failed to create image resource from $sourcePath"); return false; }
    $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
    $newWidth = max(1, intval($originalWidth * $ratio)); $newHeight = max(1, intval($originalHeight * $ratio));
    $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
    if (!$resizedImage) { error_log("compressImage: Failed to create true color image resource."); imagedestroy($image); return false; }
    if (!imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight)) {
        error_log("compressImage: Failed imagecopyresampled."); imagedestroy($image); imagedestroy($resizedImage); return false;
    }
    $success = imagejpeg($resizedImage, $destinationPath, $quality);
    imagedestroy($image); imagedestroy($resizedImage);
    if (!$success) { error_log("compressImage: Failed to save JPEG to $destinationPath"); }
    else { error_log("compressImage: Successfully compressed image to $destinationPath"); }
    return $success;
}

// --- Message Variables ---
$message = '';
$messageType = '';
$initializationError = null;

// --- Check Upload Directory (UNCHANGED) ---
if (!is_dir(UPLOAD_DIR_PATH)) {
    // ... (your existing directory check logic) ...
}
// --- End Directory Check ---

if ($initializationError) { $message = $initializationError; $messageType = 'danger'; }
else {
    // --- Handle Session Messages (from registration, status updates, etc.) ---
    if (isset($_SESSION['form_message'])) {
        $message = $_SESSION['form_message'];
        $messageType = $_SESSION['form_message_type'] ?? 'info';
        unset($_SESSION['form_message']);
        unset($_SESSION['form_message_type']);
    }

    // --- Handle INDIVIDUAL Photo Update (UNCHANGED) ---
    elseif ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_photo'])) {
        $student_id_to_update = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
        if ($student_id_to_update && isset($_FILES['student_photo_update']) && $_FILES['student_photo_update']['error'] == UPLOAD_ERR_OK) {
            $photo = $_FILES['student_photo_update']; $tempPath = $photo['tmp_name'];
            if (!in_array($photo['type'], ALLOWED_TYPES)) { $message = "Error: Invalid file type."; $messageType="danger"; }
            elseif ($photo['size'] > MAX_FILE_SIZE) { $message = "Error: File too large."; $messageType="danger"; }
            else {
                $uniqueFilename = uniqid('student_', true) . '.jpeg'; // Save as jpeg
                $destination = UPLOAD_DIR_PATH . $uniqueFilename;
                $oldPhotoPath = null;
                 try { $stmtOld = $db->prepare("SELECT photo_path FROM students WHERE id = ?"); $stmtOld->execute([$student_id_to_update]); $oldPhotoPath = $stmtOld->fetchColumn(); }
                 catch (PDOException $e) { error_log("Err fetch old photo: ".$e->getMessage()); }

                if (compressImage($tempPath, $destination, 100, 100, 75)) { // Compress here
                    $new_photo_path = 'uploads/' . $uniqueFilename; // Relative path for DB
                    try {
                        $updateStmt = $db->prepare("UPDATE students SET photo_path = ? WHERE id = ?");
                        $updateStmt->execute([$new_photo_path, $student_id_to_update]);
                        $message = "Success! Photo updated."; $messageType = "success";
                        if ($oldPhotoPath && $oldPhotoPath !== $new_photo_path && file_exists(UPLOAD_DIR_PATH . basename($oldPhotoPath))) {
                             if (!@unlink(UPLOAD_DIR_PATH . basename($oldPhotoPath))) { error_log("Failed delete old: " . $oldPhotoPath); }
                             else { error_log("Deleted old photo: " . $oldPhotoPath); }
                         }
                    } catch (PDOException $e) { $message = "DB Error update photo: " . $e->getMessage(); $messageType = "danger"; if (file_exists($destination)) { @unlink($destination); } }
                } else { $message = "Error: Failed to process image update."; $messageType = "danger"; }
            }
        } elseif ($student_id_to_update && isset($_FILES['student_photo_update']) && $_FILES['student_photo_update']['error'] != UPLOAD_ERR_NO_FILE) { $message = "Upload Error: " . $_FILES['student_photo_update']['error']; $messageType = "danger"; }
        elseif (!$student_id_to_update) { $message = "Error: Student ID missing."; $messageType = "danger"; }
    }
} // End $initializationError check

// --- Registration Form Display Logic (UNCHANGED) ---
$showRegistrationForm = false; $registrationUid = $_SESSION['registration_rfid_uid'] ?? null;
if (isset($_GET['mode']) && $_GET['mode'] === 'register' && $registrationUid) {
    $showRegistrationForm = true;
}
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $messageType === 'danger' && isset($_POST['rfid_uid'])) {
    $showRegistrationForm = true;
    $registrationUid = $_POST['rfid_uid'];
}

// --- Fetch Departments List ---
$departments = [];
try {
    $deptQuery = "SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name ASC";
    $departments = $db->query($deptQuery)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = "Error fetching departments: " . $e->getMessage();
    $messageType = "danger";
}

// --- [MODIFIED] Fetch Student List with Filters ---
$students = [];
try {
    $params = [];
    $whereClauses = [];

    // Base query
    $studentsQuery = "
        SELECT 
            s.id, 
            s.student_number, 
            s.year_section, 
            s.rfid_uid, 
            s.photo_path,
            s.status,
            CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name, s.suffix) AS full_name,
            d.name AS department_name
        FROM students s
        LEFT JOIN departments d ON s.department_id = d.id
    ";

    // Add filters to WHERE clause
    if (!empty($filter_dept)) {
        $whereClauses[] = "s.department_id = ?";
        $params[] = $filter_dept;
    }
    if (!empty($filter_status)) {
        $whereClauses[] = "s.status = ?";
        $params[] = $filter_status;
    }
    if (!empty($filter_year)) {
        $whereClauses[] = "s.year_section LIKE ?";
        $params[] = $filter_year . '%'; // e.g., "1st Year%"
    }
    if (!empty($filter_section)) {
        $whereClauses[] = "s.year_section LIKE ?";
        $params[] = '% - ' . $filter_section; // e.g., "% - A"
    }

    if (!empty($whereClauses)) {
        $studentsQuery .= " WHERE " . implode(' AND ', $whereClauses);
    }
    
    $studentsQuery .= " ORDER BY s.last_name, s.first_name ASC";
    
    $stmt = $db->prepare($studentsQuery);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $message = "Error fetching student list: " . $e->getMessage();
    $messageType = "danger";
    error_log($message);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CMU RFID Portal - Students</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/boxicons@latest/css/boxicons.min.css" rel="stylesheet">
    <link href="../css/dashboard.css" rel="stylesheet">
    <style>
       :root {
           --primary-color: #1a73e8; --secondary-color: #f8f9fa; --accent-color: #0d47a1;
           --text-color: #202124; --sidebar-width: 250px;
       }
       body { font-family: 'Segoe UI', Arial, sans-serif; background: var(--secondary-color); margin: 0; padding: 0; color: var(--text-color); }
       .sidebar { position: fixed; top:0; left:0; width: var(--sidebar-width); height: 100vh; background: linear-gradient(180deg, var(--accent-color) 0%, var(--primary-color) 100%); color: white; padding: 1.5rem; box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1); z-index: 1000; overflow-y: auto;}
       .sidebar h2 { font-size: 1.5rem; margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(255, 255, 255, 0.1); white-space: nowrap; }
       .sidebar ul { list-style: none; padding: 0; margin: 0; } .sidebar li { margin-bottom: 0.5rem; }
       .sidebar a { color: white; text-decoration: none; display: flex; align-items: center; padding: 0.75rem 1rem; border-radius: 8px; transition: all 0.3s ease; }
       .sidebar a:hover { background: rgba(255, 255, 255, 0.1); } .sidebar a.active { background: rgba(255, 255, 255, 0.2); font-weight: bold; }
       .sidebar i { margin-right: 10px; font-size: 1.2rem; }
       .content-wrapper { margin-left: var(--sidebar-width); padding: 2rem; width: calc(100% - var(--sidebar-width)); min-height: 100vh;}
       .students-table, .register-form, .department-form, .filter-form { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 2rem;}
       #scan-prompt { display: none; padding: 1rem; background-color: #e2f3ff; border: 1px solid #b6d4fe; border-radius: 8px; margin-bottom: 1rem; text-align: center; }
       #registration-form-container { display: <?php echo $showRegistrationForm ? 'block' : 'none'; ?>; }
       .student-photo-list { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; margin-right: 10px; border: 1px solid #eee;}
       .student-photo-placeholder { width: 40px; height: 40px; border-radius: 50%; background-color: #e9ecef; display: inline-flex; align-items: center; justify-content: center; margin-right: 10px; color: #6c757d; font-size: 1.2rem; }
       .photo-upload-form { display: flex; align-items: center; gap: 5px; }
       .photo-upload-form .form-control-sm { max-width: 150px; flex-grow: 1; }
       .photo-upload-form button { flex-shrink: 0; }

       /* Status Badge Colors */
        .badge.bg-success { color: white !important; }
        .badge.bg-danger { color: white !important; }
        .badge.bg-warning { color: black !important; }
        .badge.bg-secondary { color: white !important; }
   </style>
</head>
<body>
   <div class="sidebar">
        <h2><i class='bx bxs-shield'></i>CMU Portal</h2>
        <ul>
            <li><a href="../html/dashboard.php" <?php if(basename($_SERVER['PHP_SELF']) == 'dashboard.php') echo 'class="active"'; ?>><i class='bx bxs-dashboard'></i>Dashboard</a></li>
            <li><a href="logs.php" <?php if(basename($_SERVER['PHP_SELF']) == 'logs.php') echo 'class="active"'; ?>><i class='bx bx-history'></i>All Security Logs</a></li>
            <li><a href="students.php" <?php if(basename($_SERVER['PHP_SELF']) == 'students.php') echo 'class="active"'; ?>><i class='bx bxs-user-detail'></i>Students</a></li>
            <hr style="border:none;border-top:1px solid rgba(255,255,255,0.1);margin:12px 0;">
            <li><a href="../php/logout.php"><i class='bx bx-log-out'></i>Logout</a></li>
        </ul>
    </div>

    <div class="content-wrapper">
        <header class="mb-4"> <div class="d-flex justify-content-between align-items-center">
                <h1 class="h2 mb-0">Student Management</h1>
                <div id="currentDateTime" class="text-primary text-nowrap"></div>
            </div>
        </header>

        <div class="mb-4 d-flex flex-wrap gap-2">
            <button id="start-registration-btn" class="btn btn-primary" <?php echo $showRegistrationForm ? 'style="display:none;"' : ''; ?>>
                <i class='bx bx-user-plus me-2'></i>Register New Student
            </button>
             <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#department-form-container" aria-expanded="false" aria-controls="department-form-container">
                <i class='bx bx-buildings me-2'></i>Manage Departments
            </button>
            
            <button class="btn btn-warning text-dark" type="button" data-bs-toggle="modal" data-bs-target="#promoteStudentsModal">
                <i class='bx bx-rocket me-2'></i>Start New School Year
            </button>
        </div>
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType ?: 'info'; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <div class="collapse" id="department-form-container">
            <div class="department-form p-4">
                <h2 class="h4 mb-3">Add New Department</h2>
                <form action="../php/add_department.php" method="POST">
                    <div class="input-group">
                        <input type="text" class="form-control" name="department_name" placeholder="e.g., College of Engineering" required>
                        <button class="btn btn-primary" type="submit">Add Department</button>
                    </div>
                </form>
            </div>
        </div>


        <div id="scan-prompt">
             <p class="mb-1 fw-bold">Please tap the new RFID tag on the reader device...</p>
            <div class="spinner-border spinner-border-sm text-primary" role="status"> <span class="visually-hidden">Loading...</span> </div>
            <small class="text-muted d-block">Waiting for device...</small>
        </div>

        <div id="registration-form-container" style="<?php echo ($showRegistrationForm && !$initializationError) ? 'display:block;' : 'display:none;'; ?>">
            <div class="register-form p-4">
                <h2 class="h4 mb-3">Register New Student</h2>
                <form action="../php/register-student.php" method="POST" enctype="multipart/form-data" id="student-register-form">
                    <div class="mb-3">
                        <label for="rfid_uid_display" class="form-label">RFID UID (Scanned)</label>
                        <input type="text" class="form-control" id="rfid_uid_display" value="<?php echo htmlspecialchars($registrationUid ?? ''); ?>" readonly disabled>
                        <input type="hidden" id="rfid_uid" name="rfid_uid" value="<?php echo htmlspecialchars($registrationUid ?? ''); ?>">
                    </div>
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label for="last_name" class="form-label">Last Name*</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" required>
                        </div>
                         <div class="col-md-4">
                            <label for="first_name" class="form-label">First Name*</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" required>
                        </div>
                        <div class="col-md-2">
                            <label for="middle_name" class="form-label">M.I.</label>
                            <input type="text" class="form-control" id="middle_name" name="middle_name" placeholder="(Optional)">
                        </div>
                         <div class="col-md-1">
                            <label for="suffix" class="form-label">Suffix</label>
                            <input type="text" class="form-control" id="suffix" name="suffix" placeholder="e.g. Jr.">
                        </div>

                        <div class="col-md-6">
                            <label for="student_number" class="form-label">Student Number*</label>
                            <input type="text" class="form-control" id="student_number" name="student_number" required>
                        </div>
                        <div class="col-md-6">
                            <label for="student_photo" class="form-label">Student Photo (Optional)</label>
                            <input class="form-control" type="file" id="student_photo" name="student_photo" accept="image/jpeg, image/png">
                        </div>

                        <div class="col-md-4">
                            <label for="department_id" class="form-label">Department*</label>
                            <select class="form-select" id="department_id" name="department_id" required>
                                <option value="" selected disabled>-- Select Department --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label for="reg_year" class="form-label">Year*</label>
                            <select class="form-select" id="reg_year" required>
                                <option value="" selected disabled>-- Select Year --</option>
                                <option value="1st Year">1st Year</option>
                                <option value="2nd Year">2nd Year</option>
                                <option value="3rd Year">3rd Year</option>
                                <option value="4th Year">4th Year</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="reg_section" class="form-label">Section*</label>
                            <select class="form-select" id="reg_section" required>
                                <option value="" selected disabled>-- Select Section --</option>
                                <?php
                                // Generate options A through K
                                foreach (range('A', 'K') as $char) {
                                    echo "<option value=\"$char\">Section $char</option>";
                                }
                                ?>
                            </select>
                        </div>
                        </div>

                    <input type="hidden" id="year_section" name="year_section" value="">
                    
                    <button type="submit" class="btn btn-primary mt-3">Register Student</button>
                    <button type="button" id="cancel-registration-btn" class="btn btn-secondary mt-3">Cancel</button>
                </form>
            </div>
        </div>

        <div class="filter-form p-4">
            <h2 class="h4 mb-3">Filter Students</h2>
            <form action="students.php" method="GET">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="filter_dept" class="form-label">Department</label>
                        <select class="form-select" id="filter_dept" name="department">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>" <?php if ($filter_dept == $dept['id']) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="filter_year" class="form-label">Year</label>
                        <select class="form-select" id="filter_year" name="year">
                            <option value="">All Years</option>
                            <option value="1st Year" <?php if ($filter_year == '1st Year') echo 'selected'; ?>>1st Year</option>
                            <option value="2nd Year" <?php if ($filter_year == '2nd Year') echo 'selected'; ?>>2nd Year</option>
                            <option value="3rd Year" <?php if ($filter_year == '3rd Year') echo 'selected'; ?>>3rd Year</option>
                            <option value="4th Year" <?php if ($filter_year == '4th Year') echo 'selected'; ?>>4th Year</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="filter_section" class="form-label">Section</label>
                        <select class="form-select" id="filter_section" name="section">
                            <option value="">All Sections</option>
                            <?php foreach (range('A', 'K') as $char): ?>
                                <option value="<?php echo $char; ?>" <?php if ($filter_section == $char) echo 'selected'; ?>>
                                    Section <?php echo $char; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="filter_status" class="form-label">Status</label>
                        <select class="form-select" id="filter_status" name="status">
                            <option value="">All Statuses</option>
                            <option value="active" <?php if ($filter_status == 'active') echo 'selected'; ?>>Active</option>
                            <option value="suspended" <?php if ($filter_status == 'suspended') echo 'selected'; ?>>Suspended</option>
                            <option value="alumni" <?php if ($filter_status == 'alumni') echo 'selected'; ?>>Alumni (Archived)</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                    </div>
                </div>
            </form>
        </div>
        <div class="students-table mt-4">
             <h2 class="h4 p-3 mb-0 border-bottom">Registered Students</h2>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th>Student</th>
                            <th>Student Number</th>
                            <th>Department</th>
                            <th>Year/Section</th>
                            <th>RFID UID</th>
                            <th>Status</th>
                            <th>Update Photo</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($students)): ?>
                            <?php foreach($students as $row): ?>
                                <?php
                                    // Determine status badge color
                                    $status_class = 'bg-secondary'; // Default (inactive)
                                    if ($row['status'] == 'active') $status_class = 'bg-success';
                                    if ($row['status'] == 'suspended') $status_class = 'bg-warning text-dark';
                                    if ($row['status'] == 'alumni') $status_class = 'bg-danger';
                                ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <?php
                                                $imgSrc = null;
                                                $photoPath = $row['photo_path'] ?? null;
                                                if (!empty($photoPath)) {
                                                    $imgWebPath = '../' . htmlspecialchars($photoPath);
                                                    $imgFilePath = UPLOAD_DIR_PATH . basename($photoPath);
                                                    if (file_exists($imgFilePath)) {
                                                        $imgSrc = $imgWebPath . '?t=' . time();
                                                    } else { error_log("Photo file not found for student ID {$row['id']}: " . $imgFilePath); }
                                                }
                                            ?>
                                            <?php if ($imgSrc): ?>
                                                <img src="<?php echo $imgSrc; ?>" alt="Photo" class="student-photo-list">
                                            <?php else: ?>
                                                 <span class="student-photo-placeholder"><i class='bx bx-user'></i></span>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($row['full_name'] ?? 'Unknown'); ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['student_number'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($row['department_name'] ?? 'N/A'); ?></td>
                                    
                                    <td class="align-middle">
                                        <?php
                                        // --- Logic to pre-select dropdowns ---
                                        $currentYear = '';
                                        $currentSection = '';
                                        if ($row['year_section'] && strpos($row['year_section'], ' - ') !== false) {
                                            list($currentYear, $currentSection) = explode(' - ', $row['year_section'], 2);
                                        } else {
                                            $currentYear = $row['year_section']; 
                                        }
                                        ?>
                                        
                                        <form action="../php/update_student_year_section.php" method="POST" class="d-flex align-items-center">
                                            <input type="hidden" name="student_id" value="<?php echo $row['id']; ?>">
                                            
                                            <select name="year" class="form-select form-select-sm me-2" style="width: 120px;">
                                                <option value="1st Year" <?php if ($currentYear == '1st Year') echo 'selected'; ?>>1st Year</option>
                                                <option value="2nd Year" <?php if ($currentYear == '2nd Year') echo 'selected'; ?>>2nd Year</option>
                                                <option value="3rd Year" <?php if ($currentYear == '3rd Year') echo 'selected'; ?>>3rd Year</option>
                                                <option value="4th Year" <?php if ($currentYear == '4th Year') echo 'selected'; ?>>4th Year</option>
                                            </select>
                                            
                                            <select name="section" class="form-select form-select-sm me-2" style="width: 120px;">
                                                <?php
                                                foreach (range('A', 'K') as $char) {
                                                    $selected = ($currentSection == $char) ? 'selected' : '';
                                                    echo "<option value=\"$char\" $selected>Section $char</option>";
                                                }
                                                ?>
                                            </select>
                                            
                                            <button type="submit" class="btn btn-primary btn-sm" title="Update Year/Section">Update</button>
                                        </form>
                                    </td>
                                    <td><code class="bg-light px-2 py-1 rounded"><?php echo htmlspecialchars($row['rfid_uid'] ?? ''); ?></code></td>
                                    <td>
                                        <span class="badge <?php echo $status_class; ?>">
                                            <?php echo htmlspecialchars(ucfirst($row['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                         <form action="students.php" method="POST" enctype="multipart/form-data" class="photo-upload-form">
                                             <input type="hidden" name="update_photo" value="1">
                                             <input type="hidden" name="student_id" value="<?php echo $row['id']; ?>">
                                             <input class="form-control form-control-sm" type="file" name="student_photo_update" accept="image/jpeg, image/png" required title="Select new photo">
                                             <button type="submit" class="btn btn-outline-primary btn-sm py-1 px-2" title="Upload Photo">
                                                 <i class='bx bx-upload'></i>
                                             </button>
                                         </form>
                                    </td>
                                    <td>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                Set Status
                                            </button>
                                            <ul class="dropdown-menu">
                                                <?php if ($row['status'] != 'active'): ?>
                                                    <li><form action="../php/update_student_status.php" method="POST" class="d-inline">
                                                        <input type="hidden" name="student_id" value="<?php echo $row['id']; ?>">
                                                        <input type="hidden" name="new_status" value="active">
                                                        <button type="submit" class="dropdown-item">Set Active</button>
                                                    </form></li>
                                                <?php endif; ?>
                                                <?php if ($row['status'] != 'suspended'): ?>
                                                    <li><form action="../php/update_student_status.php" method="POST" class="d-inline">
                                                        <input type="hidden" name="student_id" value="<?php echo $row['id']; ?>">
                                                        <input type="hidden" name="new_status" value="suspended">
                                                        <button type="submit" class="dropdown-item">Set Suspended</button>
                                                    </form></li>
                                                <?php endif; ?>
                                                <?php if ($row['status'] != 'alumni'): ?>
                                                    <li><form action="../php/update_student_status.php" method="POST" class="d-inline">
                                                        <input type="hidden" name="student_id" value="<?php echo $row['id']; ?>">
                                                        <input type="hidden" name="new_status" value="alumni">
                                                        <button type="submit" class="dropdown-item">Set Alumni (Archive)</button>
                                                    </form></li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr> <td colspan="8" class="text-center py-4">No students found matching filters.</td> </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div> 
    
    <div class="modal fade" id="promoteStudentsModal" tabindex="-1" aria-labelledby="promoteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="promoteModalLabel">Confirm New School Year</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to start a new school year? This action will:</p>
                    <ul>
                        <li>Promote all <strong>Active 1st Years</strong> to <strong>2nd Year</strong>.</li>
                        <li>Promote all <strong>Active 2nd Years</strong> to <strong>3rd Year</strong>.</li>
                        <li>Promote all <strong>Active 3rd Years</strong> to <strong>4th Year</strong>.</li>
                        <li>Change all <strong>Active 4th Years</strong> to <strong>Alumni</strong>.</li>
                    </ul>
                    <p class="text-danger fw-bold">This action cannot be undone!</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form action="../php/promote_students.php" method="POST">
                        <button type="submit" class="btn btn-warning text-dark">Yes, Promote All Students</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // --- JavaScript for Registration Form Year/Section ---
        const regYear = document.getElementById('reg_year');
        const regSection = document.getElementById('reg_section');
        const hiddenYearSection = document.getElementById('year_section');
        const regForm = document.getElementById('student-register-form');

        function updateHiddenYearSection() {
            if (regYear && regSection && regYear.value && regSection.value) {
                // Combines the values, e.g., "1st Year - A"
                hiddenYearSection.value = regYear.value + ' - ' + regSection.value;
            } else {
                hiddenYearSection.value = '';
            }
        }

        if (regYear) regYear.addEventListener('change', updateHiddenYearSection);
        if (regSection) regSection.addEventListener('change', updateHiddenYearSection);
        
        // --- AJAX Form Submission for Registration ---
        if (regForm) {
            regForm.addEventListener('submit', async function(e) {
                e.preventDefault(); // Stop normal form submission
                
                // [IMPORTANT] Update the hidden field right before submit
                updateHiddenYearSection(); 

                const form = e.target;
                const formData = new FormData(form);
                const registerButton = form.querySelector('button[type="submit"]');
                registerButton.disabled = true;
                registerButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Registering...';

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'Accept': 'application/json' // We expect JSON back
                        }
                    });

                    const result = await response.json();

                    if (result.success) {
                        // Success! Redirect to the students page (which will show the session message)
                        window.location.href = 'students.php?reg_success=1';
                    } else {
                        // Show error message
                        alert('Registration Failed: ' + (result.message || 'Unknown error.'));
                        registerButton.disabled = false;
                        registerButton.innerHTML = 'Register Student';
                    }
                } catch (error) {
                    console.error('Submission Error:', error);
                    alert('An error occurred. Please check the console and try again.');
                    registerButton.disabled = false;
                    registerButton.innerHTML = 'Register Student';
                }
            });
        }
    </script>

    <script>
        // --- (Your existing JS for polling, cancel, and date/time is fine) ---
        const startRegBtn = document.getElementById('start-registration-btn');
        const scanPrompt = document.getElementById('scan-prompt');
        const regFormContainer = document.getElementById('registration-form-container');
        const cancelRegBtn = document.getElementById('cancel-registration-btn');
        const rfidUidDisplay = document.getElementById('rfid_uid_display');
        const rfidUidHidden = document.getElementById('rfid_uid');

        let pollingInterval = null;
        const POLL_INTERVAL_MS = 1500;

        async function startRegistration() {
            startRegBtn.style.display = 'none'; // Hide register button
            scanPrompt.style.display = 'block';
            regFormContainer.style.display = 'none';

            try {
                // Tell server to expect a scan for registration
                const response = await fetch('../php/set_registration_mode.php', { method: 'POST' });
                const result = await response.json();
                if (result.success) {
                    // Start polling to see if the ESP32 sent a UID
                    pollingInterval = setInterval(checkRegistrationStatus, POLL_INTERVAL_MS);
                } else {
                    alert('Error starting registration: ' + (result.message || 'Unknown error'));
                    resetRegistrationUI();
                }
            } catch (error) {
                console.error('Start Reg Error:', error);
                alert('Network error starting registration.');
                resetRegistrationUI();
            }
        }

        async function checkRegistrationStatus() {
            try {
                // Check if the server has received a UID from the ESP32
                const response = await fetch('../php/check_registration_uid.php');
                const result = await response.json();

                if (result.status === 'uid_received' && result.uid) {
                    clearInterval(pollingInterval); pollingInterval = null; // Stop polling
                    scanPrompt.style.display = 'none'; // Hide prompt
                    rfidUidDisplay.value = result.uid; // Show UID in form
                    rfidUidHidden.value = result.uid; // Set hidden input value
                    regFormContainer.style.display = 'block'; // Show the form
                } else if (result.status !== 'waiting') {
                    console.warn('Unexpected registration status:', result);
                } else {
                     console.log('Waiting for RFID scan...'); // Keep polling
                }
            } catch (error) {
                 console.error('Check Reg Error:', error);
             }
        }

        function cancelRegistration() {
             if (pollingInterval) { clearInterval(pollingInterval); pollingInterval = null; } // Stop polling
             fetch('../php/clear_registration_uid.php', { method: 'POST' });
             resetRegistrationUI();
        }

        function resetRegistrationUI() {
             scanPrompt.style.display = 'none';
             regFormContainer.style.display = 'none';
             startRegBtn.style.display = 'block'; 
             startRegBtn.disabled = false;
        }

        if(startRegBtn) { startRegBtn.addEventListener('click', startRegistration); }
        if(cancelRegBtn) { cancelRegBtn.addEventListener('click', cancelRegistration); }

        function updateDateTime() {
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' };
            const dateTimeElement = document.getElementById('currentDateTime');
            if(dateTimeElement) { dateTimeElement.textContent = now.toLocaleDateString('en-US', options); }
        }

        document.addEventListener('DOMContentLoaded', () => {
             updateDateTime();
             setInterval(updateDateTime, 1000);
        });
    </script>
</body>
</html>