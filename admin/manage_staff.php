<?php
// Set Philippine timezone sa pinaka-una (Hostinger default UTC kaya kailangan 'to)
date_default_timezone_set('Asia/Manila');

include 'session_check.php'; // Handles DB, session validation, timeout, and updates STAFF_LAST_ACTIVITY

// Get staff_id from session
$staff_id = $_SESSION['staff_id'] ?? null;
if (!$staff_id) {
    header("Location: ../admin_login.php?message=Please log in as admin.");
    exit();
}

// Update last activity in database (repurposing last_login as last_activity for online status tracking)
$update_activity_query = "UPDATE staff SET last_login = NOW() WHERE staff_id = ?";
$update_stmt = mysqli_prepare($conn, $update_activity_query);
mysqli_stmt_bind_param($update_stmt, "i", $staff_id);
mysqli_stmt_execute($update_stmt);
mysqli_stmt_close($update_stmt);

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
if (!function_exists('sanitize')) {
    function sanitize($value) {
        return trim($value ?? '');
    }
}

function get_avatar_src($profile_picture, $name) {
    if ($profile_picture) {
        return '../' . htmlspecialchars($profile_picture);
    }
    return 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($name);
}

$alerts = [];

// ---------------------------
// Fetch current staff info
// ---------------------------
$current_staff_query = "SELECT staff_id, name, profile_picture, email, role, created_at FROM staff WHERE staff_id = ?";
$stmt = mysqli_prepare($conn, $current_staff_query);
mysqli_stmt_bind_param($stmt, "i", $staff_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$current_staff = mysqli_fetch_assoc($result);
if (!$current_staff) {
    session_unset();
    session_destroy();
    header("Location: ../admin_login.php?message=Account not found.");
    exit();
}
mysqli_stmt_close($stmt);

// ---------------------------
// Handle AJAX requests first
// ---------------------------
// Get single staff data for view
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_staff') {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit;
    }
    $staff_id_get = (int)($_POST['staff_id'] ?? 0);
    if ($staff_id_get <= 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid staff ID.']);
        exit;
    }
    $query = "
        SELECT 
            staff_id, name, profile_picture, email, role, created_at, last_login,
            CASE 
                WHEN last_login IS NOT NULL AND UNIX_TIMESTAMP(last_login) > UNIX_TIMESTAMP(NOW()) - 300 THEN 'Online'
                ELSE 'Offline'
            END as status
        FROM staff WHERE staff_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $staff_id_get);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $staff = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    if (!$staff) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Staff not found.']);
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'staff' => $staff]);
    exit;
}

// Get all staff data for refresh (with search, filters, and pagination applied)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_all_staff') {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit;
    }
    $search = trim($_POST['search'] ?? '');
    $role_filter = $_POST['role'] ?? 'all';
    $status_filter = $_POST['status'] ?? 'all';
    $page = max(1, (int)($_POST['page'] ?? 1));
    $per_page = 10;
    $offset = ($page - 1) * $per_page;

    // Build WHERE conditions and params
    $where_conditions = [];
    $where_params = [];
    if ($search !== '') {
        $like = "%$search%";
        $where_conditions[] = "(name LIKE ? OR email LIKE ? OR role LIKE ?)";
        $where_params = array_merge($where_params, [$like, $like, $like]);
    }
    if ($role_filter !== 'all') {
        $where_conditions[] = "role = ?";
        $where_params[] = $role_filter;
    }
    if ($status_filter !== 'all') {
        $where_conditions[] = "(CASE WHEN last_login IS NOT NULL AND UNIX_TIMESTAMP(last_login) > UNIX_TIMESTAMP(NOW()) - 300 THEN 'Online' ELSE 'Offline' END = ?)";
        $where_params[] = $status_filter;
    }
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    // Staff query with role-based ordering
    $staff_query = "
        SELECT 
            staff_id, name, 
            email, role, profile_picture, created_at, last_login,
            CASE 
                WHEN last_login IS NOT NULL AND UNIX_TIMESTAMP(last_login) > UNIX_TIMESTAMP(NOW()) - 300 THEN 'Online'
                ELSE 'Offline'
            END as is_online,
            CASE 
                WHEN last_login IS NOT NULL AND UNIX_TIMESTAMP(last_login) > UNIX_TIMESTAMP(NOW()) - 300 THEN 'bg-green-100 text-green-800'
                ELSE 'bg-red-100 text-red-800'
            END as status_class
        FROM staff $where_clause 
        ORDER BY 
            CASE role 
                WHEN 'SuperAdmin' THEN 1
                WHEN 'Admin' THEN 2
                WHEN 'Employee' THEN 3
                ELSE 4
            END ASC,
            created_at DESC 
        LIMIT ? OFFSET ?";

    $full_params = array_merge($where_params, [$per_page, $offset]);
    $params_types = str_repeat('s', count($where_params)) . 'ii';

    $stmt = mysqli_prepare($conn, $staff_query);
    $staff_list = [];
    if ($stmt) {
        $bind_params = [$stmt, $params_types];
        foreach ($full_params as $key => $value) {
            $bind_params[] =& $full_params[$key];
        }
        call_user_func_array('mysqli_stmt_bind_param', $bind_params);
        mysqli_stmt_execute($stmt);
        $staff_result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($staff_result)) {
            $staff_list[] = $row;
        }
        mysqli_stmt_close($stmt);
    }

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'staff' => $staff_list]);
    exit;
}

// Handle update_activity for heartbeat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_activity') {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit;
    }
    $staff_id = $_SESSION['staff_id'] ?? null;
    if ($staff_id) {
        $update_activity_query = "UPDATE staff SET last_login = NOW() WHERE staff_id = ?";
        $update_stmt = mysqli_prepare($conn, $update_activity_query);
        mysqli_stmt_bind_param($update_stmt, "i", $staff_id);
        mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
    }
    http_response_code(200);
    exit;
}

// ---------------------------
// Fetch staff with pagination, search, and filters (main page load)
// ---------------------------
$per_page = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;
$search = trim($_GET['search'] ?? '');
$role_filter = $_GET['role'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';

// Build WHERE conditions and params (same as AJAX)
$where_conditions = [];
$where_params = [];
if ($search !== '') {
    $like = "%$search%";
    $where_conditions[] = "(name LIKE ? OR email LIKE ? OR role LIKE ?)";
    $where_params = array_merge($where_params, [$like, $like, $like]);
}
if ($role_filter !== 'all') {
    $where_conditions[] = "role = ?";
    $where_params[] = $role_filter;
}
if ($status_filter !== 'all') {
    $where_conditions[] = "(CASE WHEN last_login IS NOT NULL AND UNIX_TIMESTAMP(last_login) > UNIX_TIMESTAMP(NOW()) - 300 THEN 'Online' ELSE 'Offline' END = ?)";
    $where_params[] = $status_filter;
}
$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Staff query
$staff_query = "
    SELECT 
        staff_id, name, 
        email, role, profile_picture, created_at, last_login,
        CASE 
            WHEN last_login IS NOT NULL AND UNIX_TIMESTAMP(last_login) > UNIX_TIMESTAMP(NOW()) - 300 THEN 'Online'
            ELSE 'Offline'
        END as status 
    FROM staff $where_clause 
    ORDER BY 
        CASE role 
            WHEN 'SuperAdmin' THEN 1
            WHEN 'Admin' THEN 2
            WHEN 'Employee' THEN 3
            ELSE 4
        END ASC,
        created_at DESC 
    LIMIT ? OFFSET ?";

$full_params = array_merge($where_params, [$per_page, $offset]);
$params_types = str_repeat('s', count($where_params)) . 'ii';

$stmt = mysqli_prepare($conn, $staff_query);
$staff_list = [];
if ($stmt) {
    $bind_params = [$stmt, $params_types];
    foreach ($full_params as $key => $value) {
        $bind_params[] =& $full_params[$key];
    }
    call_user_func_array('mysqli_stmt_bind_param', $bind_params);
    mysqli_stmt_execute($stmt);
    $staff_result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($staff_result)) {
        $staff_list[] = $row;
    }
    mysqli_stmt_close($stmt);
}

// Total count
$total_query = "SELECT COUNT(*) as total FROM staff $where_clause";
$stmt_total = mysqli_prepare($conn, $total_query);
$total_staff = 0;
if ($stmt_total) {
    if (!empty($where_params)) {
        $types_count = str_repeat('s', count($where_params));
        $bind_params_count = [$stmt_total, $types_count];
        foreach ($where_params as $key => $value) {
            $bind_params_count[] =& $where_params[$key];
        }
        call_user_func_array('mysqli_stmt_bind_param', $bind_params_count);
    }
    mysqli_stmt_execute($stmt_total);
    $total_result = mysqli_stmt_get_result($stmt_total);
    $total_staff = mysqli_fetch_assoc($total_result)['total'];
    mysqli_stmt_close($stmt_total);
}
$total_pages = ceil($total_staff / $per_page);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>View Staff | CWD AquaSense Admin</title>
    <link rel="icon" type="image/png" href="../assets/icons/AquaSense2.png" />
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <link rel="stylesheet" href="css/manage_staff.css" />
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
                    <a href="manage_staff.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-blue-600 bg-blue-50 border border-blue-200 transition duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                        </svg>
                        View Staff
                    </a>
                    <a href="manage_user.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                        </svg>
                        View Users
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
                            <img src="<?php echo get_avatar_src($current_staff['profile_picture'], $current_staff['name']); ?>" alt="<?= htmlspecialchars($current_staff['name']) ?>'s avatar" class="w-10 h-10 rounded-full object-cover"/>
                            <div class="absolute -bottom-0.5 -right-0.5 w-4 h-4 bg-green-500 border-2 border-white rounded-full animate-gentle-pulse"></div>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($current_staff['name']); ?></p>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($current_staff['role']); ?></p>
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
                                    <img src="<?php echo get_avatar_src($current_staff['profile_picture'], $current_staff['name']); ?>" alt="<?= htmlspecialchars($current_staff['name']) ?>'s avatar" class="w-10 h-10 rounded-full object-cover"/>
                                    <div class="absolute -bottom-0.5 -right-0.5 w-4 h-4 bg-green-500 border-2 border-white rounded-full animate-gentle-pulse"></div>
                                </div>
                                <div class="hidden md:block">
                                    <p class="text-sm font-semibold text-gray-900 truncate max-w-32"><?php echo htmlspecialchars($current_staff['name']); ?></p>
                                    <p class="text-xs text-gray-500 truncate max-w-32"><?php echo htmlspecialchars($current_staff['role']); ?></p>
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

                <!-- Staff Table -->
                <div class="card p-6">
                    <div class="flex flex-col md:flex-row justify-between items-center mb-6">
                        <h2 class="text-lg font-semibold text-gray-900">View Staff</h2>
                        <div class="flex items-center space-x-2 mt-4 md:mt-0">
                            <form id="searchForm" action="" method="GET" class="flex items-center space-x-2">
                                <input type="hidden" name="page" value="1">
                                <input type="text" id="searchInput" name="search" class="pl-10 pr-4 py-2 rounded-lg border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Search staff..." value="<?php echo htmlspecialchars($search); ?>">
                                <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                <?php if ($search): ?>
                                    <button type="button" id="clearSearch" class="text-gray-400 hover:text-gray-600 mr-2">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                <?php endif; ?>
                                <select name="role" id="roleFilter" class="px-3 py-2 rounded-lg border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="all" <?= $role_filter === 'all' ? 'selected' : '' ?>>All Roles</option>
                                    <option value="SuperAdmin" <?= $role_filter === 'SuperAdmin' ? 'selected' : '' ?>>SuperAdmin</option>
                                    <option value="Admin" <?= $role_filter === 'Admin' ? 'selected' : '' ?>>Admin</option>
                                    <option value="Employee" <?= $role_filter === 'Employee' ? 'selected' : '' ?>>Employee</option>
                                </select>
                                <select name="status" id="statusFilter" class="px-3 py-2 rounded-lg border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                                    <option value="Online" <?= $status_filter === 'Online' ? 'selected' : '' ?>>Online</option>
                                    <option value="Offline" <?= $status_filter === 'Offline' ? 'selected' : '' ?>>Offline</option>
                                </select>
                                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Apply</button>
                            </form>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200" style="table-layout: fixed;">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Avatar</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200" id="staffTableBody">
                                <?php if (empty($staff_list)): ?>
                                    <tr>
                                        <td colspan="7" class="px-3 py-4 text-center text-gray-500">No staff members found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($staff_list as $staff): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-3 py-4 whitespace-nowrap">
                                                <img src="<?php echo get_avatar_src($staff['profile_picture'], $staff['name']); ?>" alt="<?= htmlspecialchars($staff['name']) ?>'s avatar" class="w-10 h-10 rounded-full object-cover">
                                            </td>
                                            <td class="px-3 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($staff['name']); ?>
                                            </td>
                                            <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo htmlspecialchars($staff['email']); ?>
                                            </td>
                                            <td class="px-3 py-4 whitespace-nowrap">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-purple-100 text-purple-800">
                                                    <?php echo htmlspecialchars($staff['role']); ?>
                                                </span>
                                            </td>
                                            <td class="px-3 py-4 whitespace-nowrap">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $staff['status'] === 'Online' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                    <?php echo $staff['status']; ?>
                                                </span>
                                            </td>
                                            <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo date('M j, Y', strtotime($staff['created_at'])); ?>
                                            </td>
                                            <td class="px-3 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <button class="view-btn text-blue-600 hover:text-blue-900 mr-3" data-id="<?php echo $staff['staff_id']; ?>" title="View Details">
                                                    <i class="fas fa-eye"></i>
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
                                Showing <?= count($staff_list) ?> of <?= $total_staff ?> staff members
                            </div>
                            <div class="flex space-x-2">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($role_filter) ?>&status=<?= urlencode($status_filter) ?>" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Previous</a>
                                <?php endif; ?>
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                    <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($role_filter) ?>&status=<?= urlencode($status_filter) ?>" class="px-4 py-2 text-sm font-medium <?= $i === $page ? 'text-white bg-blue-600' : 'text-gray-700 bg-white' ?> border border-gray-300 rounded-lg hover:bg-gray-50"><?= $i ?></a>
                                <?php endfor; ?>
                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&role=<?= urlencode($role_filter) ?>&status=<?= urlencode($status_filter) ?>" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Next</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- View Modal -->
    <div id="viewModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div class="p-6">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-xl font-bold text-gray-900">Staff Details</h3>
                    <button type="button" id="closeViewIcon" class="text-gray-400 hover:text-gray-600 transition-colors">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div id="staffDetails">
                    <!-- Content will be populated here -->
                </div>
                <div class="flex justify-end mt-8 pt-4 border-t border-gray-200">
                    <button type="button" id="closeView" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium">Close</button>
                </div>
            </div>
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
        const csrfToken = '<?php echo $csrf_token; ?>';
        const currentSearch = '<?php echo htmlspecialchars($search); ?>';
        const currentRole = '<?php echo json_encode($role_filter); ?>';
        const currentStatus = '<?php echo json_encode($status_filter); ?>';
        const currentPage = <?php echo $page; ?>;

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

        const form = document.getElementById('searchForm');
        const clearSearch = document.getElementById('clearSearch');
        if (clearSearch) {
            clearSearch.addEventListener('click', () => {
                form.querySelector('input[name="search"]').value = '';
                form.querySelector('select[name="role"]').value = 'all';
                form.querySelector('select[name="status"]').value = 'all';
                form.submit();
            });
        }

        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                form.submit();
            }
        });

        document.getElementById('roleFilter').addEventListener('change', () => form.submit());
        document.getElementById('statusFilter').addEventListener('change', () => form.submit());

        // Helper functions
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function getAvatarSrc(profile_picture, name) {
            if (profile_picture) {
                return '../' + profile_picture.replace(/^\.\.\/*/, '');
            }
            return 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' + encodeURIComponent(name);
        }

        function formatDate(dateStr) {
            if (!dateStr) return '—';
            const date = new Date(dateStr + 'Z'); // Treat as UTC from DB
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        function formatDateTime(dateStr) {
            if (!dateStr) return 'Never';
            const date = new Date(dateStr + 'Z'); // Treat as UTC from DB
            return date.toLocaleString('en-US', { 
                month: 'short', 
                day: 'numeric', 
                year: 'numeric', 
                hour: '2-digit', 
                minute: '2-digit',
                hour12: true 
            });
        }

        // View functionality
        async function loadStaffDetails(id) {
            const formData = new FormData();
            formData.append('action', 'get_staff');
            formData.append('staff_id', id);
            formData.append('csrf_token', csrfToken);
            try {
                const res = await fetch('', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    const staff = data.staff;
                    const avatarSrc = getAvatarSrc(staff.profile_picture, staff.name);
                    const status = staff.status;
                    const statusClass = status === 'Online' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
                    const statusIcon = status === 'Online' ? 'text-green-500' : 'text-red-500';
                    const detailsHtml = `
                        <div class="space-y-6">
                            <!-- Staff Header -->
                            <div class="flex items-start space-x-4 p-4 bg-blue-50 rounded-xl">
                                <img src="${avatarSrc}" alt="${escapeHtml(staff.name)}'s avatar" class="w-20 h-20 rounded-full object-cover flex-shrink-0 shadow-md">
                                <div class="flex-1 min-w-0">
                                    <h4 class="text-2xl font-bold text-gray-900 truncate">${escapeHtml(staff.name)}</h4>
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full ${statusClass}">
                                        <i class="fas fa-circle mr-1 ${statusIcon}"></i>
                                        ${status}
                                    </span>
                                </div>
                            </div>

                            <!-- Details Grid -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="flex items-center space-x-3 p-3 bg-gray-50 rounded-lg">
                                    <i class="fas fa-envelope text-blue-500 w-5 flex-shrink-0"></i>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Email</p>
                                        <p class="text-sm text-gray-900 truncate" title="${escapeHtml(staff.email)}">${escapeHtml(staff.email)}</p>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-3 p-3 bg-gray-50 rounded-lg">
                                    <i class="fas fa-user-tag text-purple-500 w-5 flex-shrink-0"></i>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Role</p>
                                        <p class="text-sm text-gray-900">${escapeHtml(staff.role)}</p>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-3 p-3 bg-gray-50 rounded-lg">
                                    <i class="fas fa-calendar-check text-purple-500 w-5 flex-shrink-0"></i>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Account Created</p>
                                        <p class="text-sm text-gray-900">${formatDate(staff.created_at)}</p>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-3 p-3 bg-gray-50 rounded-lg">
                                    <i class="fas fa-clock text-orange-500 w-5 flex-shrink-0"></i>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Last Active</p>
                                        <p class="text-sm text-gray-900">${formatDateTime(staff.last_login)}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    document.getElementById('staffDetails').innerHTML = detailsHtml;
                    document.getElementById('viewModal').classList.remove('hidden');
                } else {
                    Swal.fire('Error!', data.message, 'error');
                }
            } catch (err) {
                Swal.fire('Error!', 'Failed to load staff data', 'error');
            }
        }

        // Event delegation for view buttons (since table can be refreshed)
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.view-btn');
            if (btn) {
                const id = btn.dataset.id;
                loadStaffDetails(id);
            }
        });

        // Close view modal
        document.getElementById('closeView').addEventListener('click', () => {
            document.getElementById('viewModal').classList.add('hidden');
        });

        document.getElementById('closeViewIcon').addEventListener('click', () => {
            document.getElementById('viewModal').classList.add('hidden');
        });

        document.getElementById('viewModal').addEventListener('click', (e) => {
            if (e.target.id === 'viewModal') {
                document.getElementById('viewModal').classList.add('hidden');
            }
        });

        // Heartbeat to update own activity every 30 seconds
        setInterval(() => {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=update_activity&csrf_token=${csrfToken}`
            }).catch(console.error);
        }, 30000);

        // Refresh staff list and statuses every 60 seconds
        setInterval(() => {
            const formData = new FormData();
            formData.append('action', 'get_all_staff');
            formData.append('search', currentSearch);
            formData.append('role', currentRole);
            formData.append('status', currentStatus);
            formData.append('page', currentPage.toString());
            formData.append('csrf_token', csrfToken);
            fetch('', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const tbody = document.getElementById('staffTableBody');
                    if (data.staff.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="7" class="px-3 py-4 text-center text-gray-500">No staff members found.</td></tr>';
                        return;
                    }
                    tbody.innerHTML = data.staff.map(s => `
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-4 whitespace-nowrap">
                                <img src="${getAvatarSrc(s.profile_picture, s.name)}" alt="Avatar" class="w-10 h-10 rounded-full object-cover">
                            </td>
                            <td class="px-3 py-4 whitespace-nowrap text-sm font-medium text-gray-900">${escapeHtml(s.name)}</td>
                            <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-500">${escapeHtml(s.email)}</td>
                            <td class="px-3 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-purple-100 text-purple-800">
                                    ${escapeHtml(s.role)}
                                </span>
                            </td>
                            <td class="px-3 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${s.status_class}">
                                    ${s.is_online}
                                </span>
                            </td>
                            <td class="px-3 py-4 whitespace-nowrap text-sm text-gray-500">
                                ${formatDate(s.created_at)}
                            </td>
                            <td class="px-3 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <button class="view-btn text-blue-600 hover:text-blue-900 mr-3" data-id="${s.staff_id}" title="View Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                    `).join('');
                }
            })
            .catch(console.error);
        }, 60000);
    </script>
</body>
</html>

<?php
mysqli_close($conn);
?>