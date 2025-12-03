<?php
include 'session_check.php';

// ---------------------------
// Session timeout (30 minutes)
// ---------------------------
$timeout_duration = 1800;
if (!isset($_SESSION['staff_id'])) {
    header("Location: login.php?message=Please log in to access the dashboard.");
    exit();
}
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: login.php?message=Session expired, please log in again.");
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

$staff_id = $_SESSION['staff_id'];

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
        return '../' . htmlspecialchars($profile_picture);
    }
    return 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($name);
}

// ADD THIS FUNCTION: Format E.164 → Pretty Display
function formatPhoneDisplay($e164) {
    if (!$e164) return '—';
    return preg_replace('/^\+63(\d{3})(\d{3})(\d{4})$/', '+63 $1 $2 $3', $e164);
}

$alerts = [];

// ---------------------------
// Handle AJAX requests first
// ---------------------------
// Get user data for edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_user') {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit;
    }
    $user_id = (int)($_POST['user_id'] ?? 0);
    if ($user_id <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid user ID.']);
        exit;
    }
    $query = "SELECT id, username, first_name, middle_name, last_name, email, contact_number, profile_picture FROM users WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    if (!$user) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'user' => $user]);
    exit;
}

// Update user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_user') {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        if (isset($_POST['is_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
            exit;
        } else {
            $alerts[] = ['type' => 'error', 'msg' => 'Invalid CSRF token.'];
        }
    } else {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id <= 0) {
            if (isset($_POST['is_ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid user ID.']);
                exit;
            } else {
                $alerts[] = ['type' => 'error', 'msg' => 'Invalid user ID.'];
            }
        } else {
            $username = sanitize($_POST['username'] ?? '');
            $first_name = sanitize($_POST['first_name'] ?? '');
            $middle_name = sanitize($_POST['middle_name'] ?? '');
            $last_name = sanitize($_POST['last_name'] ?? '');
            $email = sanitize($_POST['email'] ?? '');
            $contact_number = sanitize($_POST['contact_number'] ?? '');
            $picture_path = null;
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $file_name = uniqid() . '_' . basename($_FILES['profile_picture']['name']);
                $target_path = $upload_dir . $file_name;
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_path)) {
                    $picture_path = 'uploads/' . $file_name;
                    // Delete old picture
                    $old_query = "SELECT profile_picture FROM users WHERE id = ?";
                    $stmt_old = mysqli_prepare($conn, $old_query);
                    mysqli_stmt_bind_param($stmt_old, "i", $user_id);
                    mysqli_stmt_execute($stmt_old);
                    $old_res = mysqli_stmt_get_result($stmt_old);
                    $old_user = mysqli_fetch_assoc($old_res);
                    mysqli_stmt_close($stmt_old);
                    if ($old_user && $old_user['profile_picture'] && file_exists('../' . $old_user['profile_picture'])) {
                        unlink('../' . $old_user['profile_picture']);
                    }
                } else {
                    if (isset($_POST['is_ajax'])) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Failed to upload image.']);
                        exit;
                    } else {
                        $alerts[] = ['type' => 'error', 'msg' => 'Failed to upload image.'];
                    }
                }
            }
            if ($picture_path) {
                $update_query = "UPDATE users SET username = ?, first_name = ?, middle_name = ?, last_name = ?, email = ?, contact_number = ?, profile_picture = ? WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, "sssssssi", $username, $first_name, $middle_name, $last_name, $email, $contact_number, $picture_path, $user_id);
            } else {
                $update_query = "UPDATE users SET username = ?, first_name = ?, middle_name = ?, last_name = ?, email = ?, contact_number = ? WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, "ssssssi", $username, $first_name, $middle_name, $last_name, $email, $contact_number, $user_id);
            }
            if (mysqli_stmt_execute($stmt)) {
                if (isset($_POST['is_ajax'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'User updated successfully.']);
                    exit;
                } else {
                    $alerts[] = ['type' => 'success', 'msg' => 'User updated successfully.'];
                }
            } else {
                if (isset($_POST['is_ajax'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Failed to update user.']);
                    exit;
                } else {
                    $alerts[] = ['type' => 'error', 'msg' => 'Failed to update user.'];
                }
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// ---------------------------
// Handle user deletion
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $msg = 'Invalid CSRF token.';
        $success = false;
    } else {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id <= 0) {
            $msg = 'Invalid user ID.';
            $success = false;
        } else {
            $delete_query = "DELETE FROM users WHERE id = ?";
            $stmt = mysqli_prepare($conn, $delete_query);
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            if (mysqli_stmt_execute($stmt)) {
                $msg = 'User deleted successfully.';
                $success = true;
            } else {
                $msg = 'Failed to delete user.';
                $success = false;
            }
            mysqli_stmt_close($stmt);
        }
    }
    if (isset($_POST['is_ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => $success, 'message' => $msg]);
        exit;
    } else {
        $alerts[] = ['type' => $success ? 'success' : 'error', 'msg' => $msg];
    }
}

// ---------------------------
// Fetch staff info
// ---------------------------
$staff_query = "SELECT staff_id, name, profile_picture, email, role, created_at FROM staff WHERE staff_id = ?";
$stmt = mysqli_prepare($conn, $staff_query);
mysqli_stmt_bind_param($stmt, "i", $staff_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$staff = mysqli_fetch_assoc($result);
if (!$staff) {
    session_unset();
    session_destroy();
    header("Location: login.php?message=Account not found.");
    exit();
}
mysqli_stmt_close($stmt);

// ---------------------------
// Fetch users with pagination and search
// ---------------------------
$per_page = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;
$search = trim($_GET['search'] ?? '');

// Build WHERE clause
$where_clause = '';
$search_params = [];
if ($search !== '') {
    $like = "%$search%";
    $where_clause = "WHERE username LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR contact_number LIKE ?";
    $search_params = [$like, $like, $like, $like, $like];
}

// Users query - UPDATED STATUS LOGIC
$users_query = "
    SELECT 
        id, username, 
        CONCAT(COALESCE(first_name, ''), ' ', COALESCE(middle_name, ''), ' ', COALESCE(last_name, '')) as full_name, 
        email, contact_number, profile_picture, created_at, last_login, token_expiry, is_active_session,
        CASE 
            WHEN is_active_session = 1 OR (token_expiry IS NOT NULL AND token_expiry > NOW()) THEN 'Active'
            ELSE 'Inactive'
        END as status 
    FROM users $where_clause 
    ORDER BY created_at DESC 
    LIMIT ? OFFSET ?";

$stmt = mysqli_prepare($conn, $users_query);
if ($search !== '') {
    $bind_types = str_repeat('s', count($search_params)) . 'ii';
    $bind_refs = array_merge($search_params, [$per_page, $offset]);
    array_unshift($bind_refs, $bind_types);
    call_user_func_array([$stmt, 'bind_param'], $bind_refs);
} else {
    mysqli_stmt_bind_param($stmt, "ii", $per_page, $offset);
}
mysqli_stmt_execute($stmt);
$users_result = mysqli_stmt_get_result($stmt);
$users = [];
while ($row = mysqli_fetch_assoc($users_result)) {
    $users[] = $row;
}
mysqli_stmt_close($stmt);

// Total count
$total_query = "SELECT COUNT(*) as total FROM users $where_clause";
$stmt = mysqli_prepare($conn, $total_query);
if ($search !== '') {
    $bind_types = str_repeat('s', count($search_params));
    $bind_refs = array_merge($search_params);
    array_unshift($bind_refs, $bind_types);
    call_user_func_array([$stmt, 'bind_param'], $bind_refs);
}
mysqli_stmt_execute($stmt);
$total_result = mysqli_stmt_get_result($stmt);
$total_users = mysqli_fetch_assoc($total_result)['total'];
$total_pages = ceil($total_users / $per_page);
mysqli_stmt_close($stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Manage Users | CWD AquaSense Admin</title>
    <link rel="icon" type="image/png" href="../assets/icons/AquaSense2.png" />
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <link rel="stylesheet" href="css/manage_user.css" />
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex">
        <!-- SIDEBAR -->
        <div class="sidebar w-64 bg-white shadow-lg fixed h-full z-30">
            <div class="flex flex-col h-full">
                <div class="p-6">
                    <div class="flex items-center space-x-3"> 
                        <img src="../assets/icons/AquaSense.png" alt="CWD AquaSense Logo" class="w-16 h-16 rounded-lg object-contain bg-white p-1 flex-shrink-0">
                        <div class="flex-1">
                            <h1 class="text-xl font-bold text-gray-900">AquaSense</h1>
                            <p class="text-xs text-gray-500">Admin Portal</p>
                        </div>
                    </div>
                </div>

                <nav class="flex-1 py-2 px-4 space-y-2">
                    <a href="dashboard.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                        </svg>
                        Dashboard
                    </a>
                    <a href="manage_complaints.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        Manage Complaints
                    </a>
                    <a href="manage_staff.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                        </svg>
                        Manage Staff
                    </a>
                    <a href="manage_user.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-blue-600 bg-blue-50 border border-blue-200 transition duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a2.375 2.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                        </svg>
                        Manage Users
                    </a>
                    <a href="view_feedback.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 0 1 .778-.332 48.294 48.294 0 0 0 5.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                        </svg>
                        View Feedback
                    </a>
                    <a href="announcements.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 0 8.835-2.535m0 0A23.74 23.74 0 0 0 18.795 3m.38 1.125a23.91 23.91 0 0 1 1.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 0 0 1.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 0 1 0 3.46" />
                        </svg>
                        Announcement Section
                    </a>
                </nav>

                <div class="p-4 border-t border-gray-100">
                    <div class="flex items-center space-x-3 mb-4">
                        <div class="relative avatar-glow">
                            <img src="<?php echo get_avatar_src($staff['profile_picture'], $staff['name']); ?>" alt="<?= htmlspecialchars($staff['name']) ?>'s avatar" class="w-10 h-10 rounded-full object-cover"/>
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

        <!-- Main -->
        <div class="flex-1">
            <header class="header-2025 sticky top-0 z-20">
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4"></div>
                        <div class="flex items-center space-x-4">
                            <div class="flex items-center space-x-3 p-2 profile-card hover:bg-gray-50 rounded-xl transition-all duration-200 group cursor-pointer relative" id="profileDropdown">
                                <div class="avatar-glow">
                                    <img src="<?php echo get_avatar_src($staff['profile_picture'], $staff['name']); ?>" alt="<?= htmlspecialchars($staff['name']) ?>'s avatar" class="w-10 h-10 rounded-full object-cover"/>
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
                <!-- Alerts -->
                <?php if (!empty($alerts)): ?>
                    <?php foreach ($alerts as $a): ?>
                        <div class="status <?php echo $a['type'] === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?> rounded-lg p-4">
                            <div class="flex items-start">
                                <i class="mr-2 mt-0.5 <?php echo $a['type'] === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'; ?>"></i>
                                <p class="text-sm font-medium"><?php echo htmlspecialchars($a['msg']); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Users Table -->
                <div class="card p-6">
                    <div class="flex flex-col md:flex-row justify-between items-center mb-6">
                        <h2 class="text-lg font-semibold text-gray-900">Manage Users</h2>
                        <div class="flex items-center space-x-4 mt-4 md:mt-0">
                            <form id="searchForm" action="" method="GET" class="relative flex items-center">
                                <input type="hidden" name="page" value="1">
                                <input type="text" id="searchInput" name="search" class="pl-10 pr-10 py-2 rounded-lg border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 w-64" placeholder="Search users..." value="<?php echo htmlspecialchars($search); ?>">
                                <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                <?php if ($search): ?>
                                    <button type="button" id="clearSearch" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                <?php endif; ?>
                            </form>
                            <a href="add_user.php" class="btn-primary flex items-center px-4 py-2 rounded-lg text-sm font-medium">
                                <i class="fas fa-plus mr-2"></i>Add New User
                            </a>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Avatar</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Username</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact Number</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Registered</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="8" class="px-6 py-4 text-center text-gray-500">No users found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <img src="<?php echo get_avatar_src($user['profile_picture'], $user['full_name']); ?>" alt="<?= htmlspecialchars($user['full_name'] ?: $user['username']) ?>'s avatar" class="w-10 h-10 rounded-full object-cover">
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($user['username']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars(trim($user['full_name']) ?: '—'); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo htmlspecialchars($user['email']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?= formatPhoneDisplay($user['contact_number']) ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $user['status'] === 'Active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                    <?php echo $user['status']; ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo date('M j, Y', strtotime($user['created_at'])); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <button class="edit-btn text-blue-600 hover:text-blue-900 mr-3" data-id="<?php echo $user['id']; ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="delete-btn text-red-600 hover:text-red-900" data-id="<?php echo $user['id']; ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="mt-6 flex justify-between items-center">
                            <div class="text-sm text-gray-500">
                                Showing <?= count($users) ?> of <?= $total_users ?> users
                            </div>
                            <div class="flex space-x-2">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Previous</a>
                                <?php endif; ?>
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>" class="px-4 py-2 text-sm font-medium <?= $i === $page ? 'text-white bg-blue-600' : 'text-gray-700 bg-white' ?> border border-gray-300 rounded-lg hover:bg-gray-50"><?= $i ?></a>
                                <?php endfor; ?>
                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Next</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg p-6 w-full max-w-md max-h-full overflow-y-auto">
            <h3 class="text-lg font-semibold mb-4">Edit User</h3>
            <form id="editForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" id="edit_user_id">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Username</label>
                    <input type="text" name="username" id="edit_username" class="w-full p-2 border border-gray-300 rounded" required>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">First Name</label>
                    <input type="text" name="first_name" id="edit_first_name" class="w-full p-2 border border-gray-300 rounded">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Middle Name</label>
                    <input type="text" name="middle_name" id="edit_middle_name" class="w-full p-2 border border-gray-300 rounded">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Last Name</label>
                    <input type="text" name="last_name" id="edit_last_name" class="w-full p-2 border border-gray-300 rounded">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                    <input type="email" name="email" id="edit_email" class="w-full p-2 border border-gray-300 rounded" required>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Contact Number (E.164)</label>
                    <input type="tel" name="contact_number" id="edit_contact_number" class="w-full p-2 border border-gray-300 rounded">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Profile Picture</label>
                    <input type="file" name="profile_picture" id="edit_profile_picture" class="w-full p-2 border border-gray-300 rounded" accept="image/*">
                    <div id="current_picture" class="mt-2"></div>
                </div>
                <div class="flex justify-end space-x-2">
                    <button type="button" id="cancelEdit" class="px-4 py-2 text-gray-600 bg-gray-200 rounded hover:bg-gray-300">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Update</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Mobile Menu Toggle -->
    <button id="mobileMenuToggle" class="fixed top-4 left-4 z-40 p-2 rounded-lg text-gray-600 bg-white shadow-lg md:hidden">
        <i class="fas fa-bars text-lg"></i>
    </button>

    <!-- Profile Dropdown -->
    <div id="profileDropdownMenu" class="hidden absolute right-6 top-20 w-48 bg-white rounded-xl shadow-lg border border-gray-100 py-2 z-30"></div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('-translate-x-full');
            sidebar.classList.toggle('translate-x-0');
        });

        if (window.innerWidth < 768) {
            document.querySelector('.sidebar').classList.add('-translate-x-full');
        }

        window.addEventListener('resize', function() {
            const sidebar = document.querySelector('.sidebar');
            if (window.innerWidth >= 768) {
                sidebar.classList.remove('-translate-x-full');
                sidebar.classList.add('translate-x-0');
            } else {
                sidebar.classList.remove('translate-x-0');
                sidebar.classList.add('-translate-x-full');
            }
        });

        const profileDropdown = document.getElementById('profileDropdown');
        const profileDropdownMenu = document.getElementById('profileDropdownMenu');

        profileDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
            if (profileDropdownMenu.classList.contains('hidden')) {
                showProfileDropdown();
            } else {
                hideProfileDropdown();
            }
        });

        function showProfileDropdown() {
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
        }

        function hideProfileDropdown() {
            profileDropdownMenu.classList.add('hidden');
        }

        document.addEventListener('click', hideProfileDropdown);

        const clearSearch = document.getElementById('clearSearch');
        if (clearSearch) {
            clearSearch.addEventListener('click', () => {
                window.location.href = '?page=1';
            });
        }

        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const val = this.value.trim();
                window.location.href = `?page=1&search=${encodeURIComponent(val)}`;
            }
        });

        // Edit functionality
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                const id = btn.dataset.id;
                const formData = new FormData();
                formData.append('action', 'get_user');
                formData.append('user_id', id);
                formData.append('csrf_token', '<?php echo $csrf_token; ?>');
                try {
                    const res = await fetch('', { method: 'POST', body: formData });
                    const data = await res.json();
                    if (data.success) {
                        const user = data.user;
                        document.getElementById('edit_user_id').value = user.id;
                        document.getElementById('edit_username').value = user.username;
                        document.getElementById('edit_first_name').value = user.first_name || '';
                        document.getElementById('edit_middle_name').value = user.middle_name || '';
                        document.getElementById('edit_last_name').value = user.last_name || '';
                        document.getElementById('edit_email').value = user.email;
                        document.getElementById('edit_contact_number').value = user.contact_number;
                        if (user.profile_picture) {
                            document.getElementById('current_picture').innerHTML = `<img src="../${user.profile_picture}" alt="Current" class="w-20 h-20 rounded-full object-cover"> <p class="text-sm text-gray-500">Current profile picture</p>`;
                        } else {
                            document.getElementById('current_picture').innerHTML = '';
                        }
                        document.getElementById('editModal').classList.remove('hidden');
                    } else {
                        Swal.fire('Error!', data.message, 'error');
                    }
                } catch (err) {
                    Swal.fire('Error!', 'Failed to load user data', 'error');
                }
            });
        });

        // Edit form submit
        document.getElementById('editForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            formData.append('is_ajax', '1');
            try {
                const res = await fetch('', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    Swal.fire('Updated!', 'User has been updated.', 'success').then(() => {
                        document.getElementById('editModal').classList.add('hidden');
                        location.reload();
                    });
                } else {
                    Swal.fire('Error!', data.message || 'Failed to update', 'error');
                }
            } catch (err) {
                Swal.fire('Error!', 'Failed to update user', 'error');
            }
        });

        // Cancel edit
        document.getElementById('cancelEdit').addEventListener('click', () => {
            document.getElementById('editModal').classList.add('hidden');
        });

        // Close modal on outside click
        document.getElementById('editModal').addEventListener('click', (e) => {
            if (e.target.id === 'editModal') {
                document.getElementById('editModal').classList.add('hidden');
            }
        });

        // Delete functionality
        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                const id = btn.dataset.id;
                const result = await Swal.fire({
                    title: 'Are you sure?',
                    text: 'This cannot be undone!',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3b82f6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Yes, delete it!'
                });
                if (result.isConfirmed) {
                    const formData = new URLSearchParams();
                    formData.append('action', 'delete_user');
                    formData.append('user_id', id);
                    formData.append('csrf_token', '<?php echo $csrf_token; ?>');
                    formData.append('is_ajax', '1');
                    try {
                        const res = await fetch('', { method: 'POST', body: formData });
                        const data = await res.json();
                        if (data.success) {
                            Swal.fire('Deleted!', 'User has been deleted.', 'success').then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire('Error!', data.message, 'error');
                        }
                    } catch (err) {
                        Swal.fire('Error!', 'Failed to delete user', 'error');
                    }
                }
            });
        });
    </script>
</body>
</html>

<?php
mysqli_close($conn);
?>