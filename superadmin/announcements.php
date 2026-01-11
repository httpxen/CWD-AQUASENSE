<?php
include 'session_check.php'; // From Step 2 (now fixed with session_name)

// Helper for flash messages
function setFlash($type, $msg) {
    if (!isset($_SESSION['flash_alerts'])) {
        $_SESSION['flash_alerts'] = [];
    }
    $_SESSION['flash_alerts'][] = ['type' => $type, 'msg' => $msg];
}

function getAndClearFlash() {
    $alerts = $_SESSION['flash_alerts'] ?? [];
    unset($_SESSION['flash_alerts']);
    return $alerts;
}

// AJAX handler for edit data (use ?get_edit=id)
if (isset($_GET['get_edit'])) {
    $edit_id = (int)$_GET['get_edit'];
    $edit_query = "SELECT * FROM announcements WHERE id = ?";
    $edit_stmt = mysqli_prepare($conn, $edit_query);
    mysqli_stmt_bind_param($edit_stmt, "i", $edit_id);
    mysqli_stmt_execute($edit_stmt);
    $edit_result = mysqli_stmt_get_result($edit_stmt);
    $edit_ann = mysqli_fetch_assoc($edit_result);
    mysqli_stmt_close($edit_stmt);
    header('Content-Type: application/json');
    if ($edit_ann) {
        echo json_encode(['success' => true, 'data' => $edit_ann]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Announcement not found']);
    }
    exit();
}

// ---------------------------
// CSRF token
// ---------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ---------------------------
// Helpers
// ---------------------------
function sanitize($value) {
    return trim($value ?? '');
}

function get_avatar_src($profile_picture, $name) {
    if ($profile_picture) {
        return '../' . $profile_picture; // Adjust for admin/ subfolder
    }
    return 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($name);
}

// Load flash alerts from session
$alerts = getAndClearFlash();

// Check for query param redirects (fallback if session not used)
if (isset($_GET['success'])) {
    $alerts[] = ['type' => 'success', 'msg' => 'Announcement created/updated/deleted successfully.'];
} elseif (isset($_GET['error'])) {
    $alerts[] = ['type' => 'error', 'msg' => $_GET['error_msg'] ?? 'An error occurred.'];
}

// ---------------------------
// Fetch staff info
// ---------------------------
$staff_id = $_SESSION['staff_id'];
$staff_query = "SELECT staff_id, name, profile_picture, email, role, created_at FROM staff WHERE staff_id = ?";
$stmt = mysqli_prepare($conn, $staff_query);
mysqli_stmt_bind_param($stmt, "i", $staff_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$staff = mysqli_fetch_assoc($result);
if (!$staff) {
    session_unset();
    session_destroy();
    header("Location: admin_login.php?message=Account not found.");
    exit();
}

// Role check: Only SuperAdmin allowed
if ($staff['role'] !== 'SuperAdmin') {
    header("Location: ../admin_login.php?message=Access denied.");
    exit();
}

// ---------------------------
// Handle CRUD Operations
// ---------------------------
$upload_dir = '../uploads/announcements/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        setFlash('error', 'Invalid CSRF token.');
        header('Location: announcements.php');
        exit();
    }

    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'create') {
        $title = sanitize($_POST['title']);
        $description = sanitize($_POST['description']);
        $start_date = sanitize($_POST['start_date']);
        $end_date = sanitize($_POST['end_date']);
        $start_time = sanitize($_POST['start_time'] ?? '');
        $end_time = sanitize($_POST['end_time'] ?? '');
        $affected_areas = sanitize($_POST['affected_areas']);

        // Handle null times
        $start_time = $start_time ? $start_time : null;
        $end_time = $end_time ? $end_time : null;

        $image_path = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif'])) {
                $new_filename = uniqid() . '.' . $file_extension;
                $image_path = 'uploads/announcements/' . $new_filename;
                if (!move_uploaded_file($_FILES['image']['tmp_name'], '../' . $image_path)) {
                    setFlash('error', 'Failed to upload image.');
                    header('Location: announcements.php');
                    exit();
                }
            } else {
                setFlash('error', 'Invalid image format.');
                header('Location: announcements.php');
                exit();
            }
        }

        $query = "INSERT INTO announcements (title, description, start_date, end_date, start_time, end_time, affected_areas, image_path, staff_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "sssssssss", $title, $description, $start_date, $end_date, $start_time, $end_time, $affected_areas, $image_path, $staff_id);
        if (mysqli_stmt_execute($stmt)) {
            setFlash('success', 'Announcement created successfully.');
        } else {
            setFlash('error', 'Failed to create announcement.');
            // Delete uploaded file if query fails
            if ($image_path && file_exists('../' . $image_path)) {
                unlink('../' . $image_path);
            }
        }
        mysqli_stmt_close($stmt);
        header('Location: announcements.php'); // PRG redirect
        exit();

    } elseif ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            setFlash('error', 'Invalid announcement ID.');
            header('Location: announcements.php');
            exit();
        }

        $title = sanitize($_POST['title']);
        $description = sanitize($_POST['description']);
        $start_date = sanitize($_POST['start_date']);
        $end_date = sanitize($_POST['end_date']);
        $start_time = sanitize($_POST['start_time'] ?? '');
        $end_time = sanitize($_POST['end_time'] ?? '');
        $affected_areas = sanitize($_POST['affected_areas']);

        // Handle null times
        $start_time = $start_time ? $start_time : null;
        $end_time = $end_time ? $end_time : null;

        $image_path = null;
        $old_image_path = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            // Get old image path
            $old_query = "SELECT image_path FROM announcements WHERE id = ?";
            $old_stmt = mysqli_prepare($conn, $old_query);
            mysqli_stmt_bind_param($old_stmt, "i", $id);
            mysqli_stmt_execute($old_stmt);
            $old_result = mysqli_stmt_get_result($old_stmt);
            $old_ann = mysqli_fetch_assoc($old_result);
            $old_image_path = $old_ann['image_path'] ?? null;
            mysqli_stmt_close($old_stmt);

            $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif'])) {
                $new_filename = uniqid() . '.' . $file_extension;
                $image_path = 'uploads/announcements/' . $new_filename;
                if (move_uploaded_file($_FILES['image']['tmp_name'], '../' . $image_path)) {
                    // Delete old image
                    if ($old_image_path && file_exists('../' . $old_image_path)) {
                        unlink('../' . $old_image_path);
                    }
                } else {
                    setFlash('error', 'Failed to upload new image.');
                    header('Location: announcements.php');
                    exit();
                }
            } else {
                setFlash('error', 'Invalid image format.');
                header('Location: announcements.php');
                exit();
            }
        }

        $query = "UPDATE announcements SET title = ?, description = ?, start_date = ?, end_date = ?, start_time = ?, end_time = ?, affected_areas = ?";
        $params = [$title, $description, $start_date, $end_date, $start_time, $end_time, $affected_areas];
        $types = "sssssss";
        if ($image_path) {
            $query .= ", image_path = ?";
            $params[] = $image_path;
            $types .= "s";
        }
        $query .= " WHERE id = ?";
        $params[] = $id;
        $types .= "i";

        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        if (mysqli_stmt_execute($stmt)) {
            setFlash('success', 'Announcement updated successfully.');
        } else {
            setFlash('error', 'Failed to update announcement.');
            // Delete new image if query fails
            if ($image_path && file_exists('../' . $image_path)) {
                unlink('../' . $image_path);
            }
        }
        mysqli_stmt_close($stmt);
        header('Location: announcements.php'); // PRG redirect
        exit();

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            setFlash('error', 'Invalid announcement ID.');
            header('Location: announcements.php');
            exit();
        }

        // Get image path
        $img_query = "SELECT image_path FROM announcements WHERE id = ?";
        $img_stmt = mysqli_prepare($conn, $img_query);
        mysqli_stmt_bind_param($img_stmt, "i", $id);
        mysqli_stmt_execute($img_stmt);
        $img_result = mysqli_stmt_get_result($img_stmt);
        $ann = mysqli_fetch_assoc($img_result);
        $image_path = $ann['image_path'] ?? null;
        mysqli_stmt_close($img_stmt);

        $query = "DELETE FROM announcements WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $id);
        if (mysqli_stmt_execute($stmt)) {
            if ($image_path && file_exists('../' . $image_path)) {
                unlink('../' . $image_path);
            }
            setFlash('success', 'Announcement deleted successfully.');
        } else {
            setFlash('error', 'Failed to delete announcement.');
        }
        mysqli_stmt_close($stmt);
        header('Location: announcements.php'); // PRG redirect
        exit();
    }
}

// ---------------------------
// Fetch announcements
// ---------------------------
$announcements_query = "SELECT a.*, s.name as staff_name 
                        FROM announcements a 
                        LEFT JOIN staff s ON a.staff_id = s.staff_id 
                        ORDER BY a.start_date DESC";
$announcements_result = mysqli_query($conn, $announcements_query);
if (!$announcements_result) die("Query failed: " . mysqli_error($conn));
$announcements = [];
$now = new DateTime();
while ($row = mysqli_fetch_assoc($announcements_result)) {
    $start_str = $row['start_date'] . ($row['start_time'] ? ' ' . $row['start_time'] : ' 00:00:00');
    $end_str = $row['end_date'] . ($row['end_time'] ? ' ' . $row['end_time'] : ' 23:59:59');
    $start = new DateTime($start_str);
    $end = new DateTime($end_str);
    $row['status'] = ($now >= $start && $now <= $end) ? 'Active' : (($start > $now) ? 'Upcoming' : 'Expired');
    $row['formatted_start'] = date('M j, Y g:i A', strtotime($start_str));
    $row['formatted_end'] = date('M j, Y g:i A', strtotime($end_str));
    $row['formatted_range'] = $row['formatted_start'] . ' – ' . $row['formatted_end'];
    if (!$row['start_time']) {
        $row['formatted_range'] = date('M j', strtotime($row['start_date'])) . ' – ' . date('M j, Y', strtotime($row['end_date']));
    }
    $announcements[] = $row;
}

// Fetch for edit modal if id provided
$edit_ann = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $edit_query = "SELECT * FROM announcements WHERE id = ?";
    $edit_stmt = mysqli_prepare($conn, $edit_query);
    mysqli_stmt_bind_param($edit_stmt, "i", $edit_id);
    mysqli_stmt_execute($edit_stmt);
    $edit_result = mysqli_stmt_get_result($edit_stmt);
    $edit_ann = mysqli_fetch_assoc($edit_result);
    mysqli_stmt_close($edit_stmt);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Announcements | CWD AquaSense SuperAdmin</title>
    <link rel="icon" type="image/png" href="../assets/icons/AquaSense2.png" />
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex">
        <!-- Sidebar (unchanged) -->
        <div class="sidebar w-64 bg-white shadow-lg fixed h-full z-30">
            <div class="flex flex-col h-full">
                <div class="p-6">
                    <div class="flex items-center space-x-3"> 
                        <img src="../assets/icons/AquaSense.png" alt="CWD AquaSense Logo" class="w-16 h-16 rounded-lg object-contain bg-white p-1 flex-shrink-0">
                        <div class="flex-1">
                            <h1 class="text-xl font-bold text-gray-900">AquaSense</h1>
                            <p class="text-xs text-gray-500">SuperAdmin Portal</p>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <nav class="flex-1 py-2 px-4 space-y-2">
                    <a href="dashboard.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="dashboard-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                        </svg>
                        Dashboard
                    </a>
                    <a href="manage_complaints.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="complaints-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        Manage Complaints
                    </a>
                    <a href="manage_staff.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="profile-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                        </svg>
                        Manage Staff
                    </a>
                    <a href="manage_user.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="users-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                        </svg>
                        Manage Users
                    </a>
                    <a href="view_feedback.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="feedback-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01 .778-.332 48.294 48.294 0 0 1 5.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 1 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                        </svg>
                        View Feedback
                    </a>
                    <a href="announcements.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-blue-600 bg-blue-50 border border-blue-200 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="announcement-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 0 8.835-2.535m0 0A23.74 23.74 0 0 0 18.795 3m.38 1.125a23.91 23.91 0 0 1 1.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 0 0 1.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 0 1 0 3.46" />
                        </svg>
                        Announcement Section
                    </a>
                </nav>

                <!-- Staff Info & Logout -->
                <div class="p-4 border-t border-gray-100">
                    <div class="flex items-center space-x-3 mb-4">
                        <div class="relative avatar-glow">
                            <img src="<?php echo htmlspecialchars(get_avatar_src($staff['profile_picture'], $staff['name'])); ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover"/>
                            <div class="absolute -bottom-0.5 -right-0.5 w-4 h-4 bg-green-500 border-2 border-white rounded-full animate-gentle-pulse"></div>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($staff['name']); ?></p>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($staff['role']); ?></p>
                        </div>
                    </div>
                    <a href="../admin_logout.php" class="w-full flex items-center justify-center px-4 py-2 text-sm font-medium text-red-600 bg-red-50 rounded-lg hover:bg-red-100 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-2 text-red-600">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                        </svg>
                        Sign Out
                    </a>
                </div>
            </div>
        </div>

        <!-- Main (unchanged except for the table and modals below) -->
        <div class="flex-1">
            <header class="header-2025 sticky top-0 z-20">
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between">
                        <!-- Left: Clean & Minimal -->
                        <div class="flex items-center space-x-4">
                        </div>
                        <!-- Right: Essential Actions -->
                        <div class="flex items-center space-x-4">
                            <!-- Profile Dropdown -->
                            <div class="flex items-center space-x-3 p-2 profile-card hover:bg-gray-50 rounded-xl transition-all duration-200 group cursor-pointer relative" id="profileDropdown">
                                <div class="avatar-glow">
                                    <img src="<?php echo htmlspecialchars(get_avatar_src($staff['profile_picture'], $staff['name'])); ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover"/>
                                    <div class="absolute -bottom-0.5 -right-0.5 w-4 h-4 bg-green-500 border-2 border-white rounded-full animate-gentle-pulse"></div>
                                </div>
                                <div class="hidden md:block">
                                    <p class="text-sm font-semibold text-gray-900 truncate max-w-32"><?php echo htmlspecialchars($staff['name']); ?></p>
                                    <p class="text-xs text-gray-500 truncate max-w-32"><?php echo htmlspecialchars($staff['role']); ?></p>
                                </div>
                                <i class="fas fa-chevron-down text-gray-400 text-sm ml-1 transition-transform duration-200 group-hover:text-gray-600"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main class="p-6 space-y-6">
                <!-- Header with Add Button -->
                <div class="flex items-center justify-end">
                    <button onclick="openModal('createModal')" class="px-4 py-2 text-sm font-medium text-blue-600 bg-blue-50 hover:bg-blue-100 rounded-lg transition duration-200 border border-blue-200 flex items-center space-x-2">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5v6m3-3H9m4.06-7.19-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" />
                        </svg>
                        <span>Add New Announcement</span>
                    </button>
                </div>

                <!-- Announcements Table -->
                <div class="bg-white shadow-sm border border-gray-200 rounded-xl overflow-hidden">
                    <table class="min-w-full divide-y divide-gray-200 w-full table-auto">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date & Time</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created By</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Image</th>
                                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($announcements)): ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-8 text-center text-gray-500">No announcements found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($announcements as $ann): ?>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="px-3 py-4 text-sm font-medium text-gray-900 sm:whitespace-nowrap">
                                            <?php echo htmlspecialchars($ann['title']); ?>
                                        </td>
                                        <td class="px-3 py-4 text-sm text-gray-600" title="<?php echo htmlspecialchars($ann['description']); ?>">
                                            <?php echo htmlspecialchars(substr($ann['description'], 0, 100)) . (strlen($ann['description']) > 100 ? '...' : ''); ?>
                                        </td>
                                        <td class="px-3 py-4 text-sm text-gray-500 sm:whitespace-nowrap" title="<?php echo htmlspecialchars($ann['formatted_range']); ?>">
                                            <?php echo htmlspecialchars($ann['formatted_range']); ?>
                                        </td>
                                        <td class="px-3 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php 
                                                echo $ann['status'] === 'Active' ? 'bg-green-100 text-green-800' : 
                                                     ($ann['status'] === 'Upcoming' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'); 
                                            ?>">
                                                <?php echo htmlspecialchars($ann['status']); ?>
                                            </span>
                                        </td>
                                        <td class="px-3 py-4 text-sm text-gray-500 sm:whitespace-nowrap sm:truncate max-w-xs">
                                            <?php echo htmlspecialchars($ann['staff_name']); ?>
                                        </td>
                                        <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php if ($ann['image_path']): ?>
                                                <img src="../<?php echo htmlspecialchars($ann['image_path']); ?>" alt="Announcement Image" onclick="viewImage(this.src)" class="w-8 h-8 rounded object-cover border border-gray-200 cursor-pointer hover:opacity-80 transition-opacity">
                                            <?php else: ?>
                                                <span class="text-gray-400 text-xs">No image</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-3 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                            <button onclick="openModal('editModal', <?php echo $ann['id']; ?>)" class="text-blue-600 hover:text-blue-900 p-1 rounded hover:bg-blue-50 transition-colors" title="Edit">
                                                <i class="fas fa-edit text-sm"></i>
                                            </button>
                                            <form method="POST" class="delete-form inline-block" style="display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $ann['id']; ?>">
                                                <button type="submit" class="text-red-600 hover:text-red-900 p-1 rounded hover:bg-red-50 transition-colors" title="Delete">
                                                    <i class="fas fa-trash text-sm"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </main>
        </div>
    </div>

    <!-- Create/Edit Modal (unchanged) -->
    <div id="createModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-xl shadow-lg max-w-md w-full max-h-screen overflow-y-auto">
            <div class="p-6">
                <h2 class="text-xl font-bold text-gray-900 mb-4">Add New Announcement</h2>
                <form method="POST" enctype="multipart/form-data" id="createForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                            <input type="text" name="title" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Enter title">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                            <textarea name="description" required rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Enter description"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                            <input type="date" name="start_date" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Start Time (optional)</label>
                            <input type="time" name="start_time" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                            <input type="date" name="end_date" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">End Time (optional)</label>
                            <input type="time" name="end_time" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Affected Areas</label>
                            <input type="text" name="affected_areas" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Enter affected areas (optional)">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Image</label>
                            <input type="file" name="image" accept="image/*" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                    <div class="flex justify-end space-x-3 mt-6">
                        <button type="button" onclick="closeModal('createModal')" class="px-4 py-2 text-gray-600 bg-gray-200 rounded-lg hover:bg-gray-300">Cancel</button>
                        <button type="submit" class="px-4 py-2 text-white bg-blue-600 rounded-lg hover:bg-blue-700">Create</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Modal (similar structure, populated via JS/PHP) -->
    <div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-xl shadow-lg max-w-md w-full max-h-screen overflow-y-auto">
            <div class="p-6">
                <h2 class="text-xl font-bold text-gray-900 mb-4">Edit Announcement</h2>
                <form method="POST" enctype="multipart/form-data" id="editForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="editId">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                            <input type="text" name="title" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="editTitle">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                            <textarea name="description" required rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="editDescription"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                            <input type="date" name="start_date" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="editStartDate">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Start Time (optional)</label>
                            <input type="time" name="start_time" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="editStartTime">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                            <input type="date" name="end_date" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="editEndDate">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">End Time (optional)</label>
                            <input type="time" name="end_time" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="editEndTime">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Affected Areas</label>
                            <input type="text" name="affected_areas" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" id="editAffectedAreas">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Image (leave empty to keep current)</label>
                            <input type="file" name="image" accept="image/*" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                    <div class="flex justify-end space-x-3 mt-6">
                        <button type="button" onclick="closeModal('editModal')" class="px-4 py-2 text-gray-600 bg-gray-200 rounded-lg hover:bg-gray-300">Cancel</button>
                        <button type="submit" class="px-4 py-2 text-white bg-blue-600 rounded-lg hover:bg-blue-700">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Image View Modal -->
    <div id="imageModal" class="hidden fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50 p-4">
        <div class="relative max-w-2xl w-full max-h-[80vh] mx-auto overflow-hidden">
            <button onclick="closeModal('imageModal')" class="absolute top-4 right-4 z-10 text-white hover:text-gray-200 text-2xl font-bold bg-black bg-opacity-50 rounded-full w-10 h-10 flex items-center justify-center">
                <i class="fas fa-times"></i>
            </button>
            <img id="imageModalImg" src="" alt="Full Image" class="w-full h-full object-contain">
        </div>
    </div>

    <!-- Mobile Menu Toggle -->
    <button id="mobileMenuToggle" class="fixed top-4 left-4 z-40 p-2 rounded-lg text-gray-600 bg-white shadow-lg md:hidden">
        <i class="fas fa-bars text-lg"></i>
    </button>

    <!-- Profile Dropdown Menu -->
    <div id="profileDropdownMenu" class="hidden absolute right-6 top-20 w-48 bg-white rounded-xl shadow-lg border border-gray-100 py-2 z-30"></div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('-translate-x-full');
            sidebar.classList.toggle('translate-x-0');
        });

        // Responsive sidebar
        window.addEventListener('resize', function() {
            const sidebar = document.querySelector('.sidebar');
            if (window.innerWidth >= 768) {
                sidebar.classList.remove('-translate-x-full');
            } else {
                sidebar.classList.add('-translate-x-full');
            }
        });

        // Profile dropdown (same as dashboard)
        const profileDropdown = document.getElementById('profileDropdown');
        const profileDropdownMenu = document.getElementById('profileDropdownMenu');

        profileDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
            if (profileDropdownMenu.classList.contains('hidden')) {
                profileDropdownMenu.innerHTML = `
                    <a href="accountsettings.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 text-blue-500">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                        My Profile
                    </a>
                    <div class="border-t border-gray-100 my-1"></div>
                    <a href="../admin_logout.php" class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                        </svg>
                        Sign Out
                    </a>
                `;
                profileDropdownMenu.classList.remove('hidden');
                const rect = profileDropdown.getBoundingClientRect();
                profileDropdownMenu.style.right = '1.5rem';
                profileDropdownMenu.style.top = `${rect.bottom + 8}px`;
            } else {
                profileDropdownMenu.classList.add('hidden');
            }
        });

        document.addEventListener('click', function() {
            profileDropdownMenu.classList.add('hidden');
        });

        // Handle delete confirmations with SweetAlert2
        document.addEventListener('submit', function(e) {
            if (e.target.classList.contains('delete-form')) {
                e.preventDefault();
                const form = e.target;
                Swal.fire({
                    title: 'Are you sure you want to delete this announcement?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'Yes, delete it!'
                }).then((result) => {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            }
        });

        // Modal functions
        function openModal(modalId, editId = null) {
            const modal = document.getElementById(modalId);
            modal.classList.remove('hidden');
            if (modalId === 'editModal' && editId) {
                // Fetch edit data via AJAX
                fetch(`?get_edit=${editId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.data) {
                            document.getElementById('editId').value = data.data.id;
                            document.getElementById('editTitle').value = data.data.title;
                            document.getElementById('editDescription').value = data.data.description;
                            document.getElementById('editStartDate').value = data.data.start_date;
                            document.getElementById('editEndDate').value = data.data.end_date;
                            document.getElementById('editStartTime').value = data.data.start_time || '';
                            document.getElementById('editEndTime').value = data.data.end_time || '';
                            document.getElementById('editAffectedAreas').value = data.data.affected_areas || '';
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Oops...',
                                text: data.message || 'Failed to load announcement data.'
                            });
                            closeModal('editModal');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Oops...',
                            text: 'Error loading announcement data.'
                        });
                        closeModal('editModal');
                    });
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            // Reset form
            if (modalId === 'createModal' || modalId === 'editModal') {
                const form = document.getElementById(modalId === 'createModal' ? 'createForm' : 'editForm');
                form.reset();
            }
        }

        // Image view function
        function viewImage(src) {
            document.getElementById('imageModalImg').src = src;
            document.getElementById('imageModal').classList.remove('hidden');
        }

        // Close modals on outside click
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.fixed.inset-0');
            modals.forEach(modal => {
                if (event.target === modal) {
                    closeModal(modal.id);
                }
            });
        }

        // Show alerts with SweetAlert2
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (!empty($alerts)): ?>
                <?php foreach ($alerts as $a): ?>
                    Swal.fire({
                        icon: '<?php echo $a['type']; ?>',
                        title: '<?php echo ucfirst($a['type']); ?>',
                        text: '<?php echo htmlspecialchars($a['msg']); ?>',
                        timer: 3000,
                        showConfirmButton: false
                    });
                <?php endforeach; ?>
            <?php endif; ?>

            // If edit from URL
            <?php if ($edit_ann): ?>
                document.getElementById('editId').value = '<?php echo $edit_ann['id']; ?>';
                document.getElementById('editTitle').value = '<?php echo htmlspecialchars($edit_ann['title']); ?>';
                document.getElementById('editDescription').value = '<?php echo htmlspecialchars($edit_ann['description']); ?>';
                document.getElementById('editStartDate').value = '<?php echo $edit_ann['start_date']; ?>';
                document.getElementById('editEndDate').value = '<?php echo $edit_ann['end_date']; ?>';
                document.getElementById('editStartTime').value = '<?php echo htmlspecialchars($edit_ann['start_time'] ?? ''); ?>';
                document.getElementById('editEndTime').value = '<?php echo htmlspecialchars($edit_ann['end_time'] ?? ''); ?>';
                document.getElementById('editAffectedAreas').value = '<?php echo htmlspecialchars($edit_ann['affected_areas'] ?? ''); ?>';
                openModal('editModal', <?php echo $edit_ann['id']; ?>);
            <?php endif; ?>
        });
    </script>

</body>
</html>

<?php
// Cleanup
if (isset($stmt)) { mysqli_stmt_close($stmt); }
mysqli_close($conn);
?>