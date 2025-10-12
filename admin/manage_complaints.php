<?php
include 'session_check.php'; // Assuming this handles DB connection and basic session

// Session timeout (30 minutes)
$timeout_duration = 1800;
if (!isset($_SESSION['staff_id'])) {
    header("Location: login.php?message=Please log in to access complaints.");
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

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Helpers
function e($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }
function get_avatar_src($profile_picture, $name) {
    if ($profile_picture) {
        return '../' . $profile_picture;
    }
    return 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($name);
}

// Constants
$ALLOWED_CATEGORIES = [
    'Billing',
    'Water Quality',
    'Service Interruption',
    'Meter / Leakage',
    'New Connection / Disconnection',
    'Customer Service',
    'Others'
];
$ALLOWED_STATUSES = ['Pending', 'In Progress', 'Resolved', 'Closed'];

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === '1') {
    $status = $_GET['status'] ?? '';
    $category = $_GET['category'] ?? '';
    $q = trim($_GET['q'] ?? '');

    $clauses = [];
    $params = [];
    $types = '';

    if ($status && in_array($status, $ALLOWED_STATUSES, true)) {
        $clauses[] = "c.status = ?";
        $params[] = $status;
        $types .= "s";
    }
    if ($category && in_array($category, $ALLOWED_CATEGORIES, true)) {
        $clauses[] = "c.category = ?";
        $params[] = $category;
        $types .= "s";
    }
    if ($q !== '') {
        $clauses[] = "(c.description LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
        $types .= "ss";
    }

    $where = $clauses ? "WHERE " . implode(" AND ", $clauses) : '';
    $sql = "
        SELECT c.complaint_id, c.category, c.description, c.status, c.sentiment, c.action_due, c.created_at, c.updated_at, c.attachment_path,
               CONCAT(u.first_name, ' ', u.last_name) AS user_name, u.email AS user_email,
               s.name AS staff_name
        FROM complaints c
        LEFT JOIN users u ON c.user_id = u.id
        LEFT JOIN (
            SELECT ca1.*
            FROM complaint_assignments ca1
            JOIN (
                SELECT complaint_id, MAX(id) AS max_id
                FROM complaint_assignments
                GROUP BY complaint_id
            ) latest ON latest.max_id = ca1.id
        ) ca ON ca.complaint_id = c.complaint_id
        LEFT JOIN staff s ON s.staff_id = ca.staff_id
        $where
        ORDER BY c.complaint_id ASC
    ";
    $stmt = mysqli_prepare($conn, $sql);
    if ($types) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=complaints_export_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Complaint ID', 'Category', 'Description', 'Status', 'Sentiment', 'Action Due', 'Attachment', 'User Name', 'User Email', 'Assigned Staff', 'Created At', 'Updated At']);
    while ($row = mysqli_fetch_assoc($res)) {
        fputcsv($out, [
            $row['complaint_id'],
            $row['category'],
            $row['description'],
            $row['status'],
            $row['sentiment'] ?? '',
            $row['action_due'] ?? '',
            $row['attachment_path'] ?? '',
            $row['user_name'] ?? '',
            $row['user_email'] ?? '',
            $row['staff_name'] ?? '',
            $row['created_at'],
            $row['updated_at']
        ]);
    }
    fclose($out);
    mysqli_stmt_close($stmt);
    exit;
}

// Filters, search, pagination
$status = $_GET['status'] ?? '';
$category = $_GET['category'] ?? '';
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Build WHERE
$clauses = [];
$params = [];
$types = '';

if ($status && in_array($status, $ALLOWED_STATUSES, true)) {
    $clauses[] = "c.status = ?";
    $params[] = $status;
    $types .= "s";
}
if ($category && in_array($category, $ALLOWED_CATEGORIES, true)) {
    $clauses[] = "c.category = ?";
    $params[] = $category;
    $types .= "s";
}
if ($q !== '') {
    $clauses[] = "(c.description LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $types .= "ss";
}
$where = $clauses ? "WHERE " . implode(" AND ", $clauses) : '';

// Count total
$count_sql = "SELECT COUNT(*) AS cnt FROM complaints c LEFT JOIN users u ON c.user_id = u.id $where";
$count_stmt = mysqli_prepare($conn, $count_sql);
if ($types) mysqli_stmt_bind_param($count_stmt, $types, ...$params);
mysqli_stmt_execute($count_stmt);
$count_res = mysqli_stmt_get_result($count_stmt);
$total_rows = (int)mysqli_fetch_assoc($count_res)['cnt'];
mysqli_stmt_close($count_stmt);
$total_pages = max(1, (int)ceil($total_rows / $per_page));

// Fetch list
$list_sql = "
    SELECT c.complaint_id, c.category, c.description, c.status, c.sentiment, c.action_due, c.created_at, c.updated_at, c.attachment_path,
           CONCAT(u.first_name, ' ', u.last_name) AS user_name, u.email AS user_email, u.profile_picture,
           s.name AS staff_name
    FROM complaints c
    LEFT JOIN users u ON c.user_id = u.id
    LEFT JOIN (
        SELECT ca1.*
        FROM complaint_assignments ca1
        JOIN (
            SELECT complaint_id, MAX(id) AS max_id
            FROM complaint_assignments
            GROUP BY complaint_id
        ) latest ON latest.max_id = ca1.id
    ) ca ON ca.complaint_id = c.complaint_id
    LEFT JOIN staff s ON s.staff_id = ca.staff_id
    $where
    ORDER BY c.complaint_id ASC
    LIMIT ? OFFSET ?
";
$list_stmt = mysqli_prepare($conn, $list_sql);
$types_paged = $types . "ii";
$params_paged = array_merge($params, [$per_page, $offset]);
mysqli_stmt_bind_param($list_stmt, $types_paged, ...$params_paged);
mysqli_stmt_execute($list_stmt);
$list_res = mysqli_stmt_get_result($list_stmt);

// Fetch staff for assignment dropdown (in modals)
$staff_sql = "SELECT staff_id, name FROM staff ORDER BY name";
$staff_res = mysqli_query($conn, $staff_sql);
$staff_list = [];
while ($row = mysqli_fetch_assoc($staff_res)) {
    $staff_list[] = $row;
}

// Fetch staff info for header
$staff_query = "SELECT name, profile_picture, role FROM staff WHERE staff_id = ?";
$stmt = mysqli_prepare($conn, $staff_query);
mysqli_stmt_bind_param($stmt, "i", $staff_id);
mysqli_stmt_execute($stmt);
$staff_result = mysqli_stmt_get_result($stmt);
$staff = mysqli_fetch_assoc($staff_result);
mysqli_stmt_close($stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Complaints | CWD AquaSense Admin</title>
    <link rel="icon" type="image/png" href="../assets/icons/AquaSense2.png">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .sidebar { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); width: 256px; }
        .card { background: linear-gradient(145deg, #ffffff, #f8fafc); border: 1px solid rgba(0,0,0,0.05); border-radius: 1rem; }
        .btn-primary { background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); color: white; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); }
        .status-badge, .sentiment-badge { border-radius: 0.5rem; padding: 0.25rem 0.5rem; font-size: 0.75rem; font-weight: 600; border-width: 1px; }
        .avatar-glow { position: relative; cursor: pointer; }
        .avatar-glow::before { content: ''; position: absolute; top: -2px; left: -2px; right: -2px; bottom: -2px; background: linear-gradient(45deg, #3b82f6, #8b5cf6, #06b6d4, #3b82f6); border-radius: 50%; z-index: -1; opacity: 0; transition: opacity 0.3s ease; }
        .avatar-glow:hover::before { opacity: 1; }
        .notification-badge { position: absolute; top: -2px; right: -2px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; display: flex; align-items: center; justify-content: center; font-weight: 600; box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3); }
        .group:hover .fa-chevron-down { transform: rotate(180deg); transition: transform 0.2s ease; }
        @keyframes gentle-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .animate-gentle-pulse { animation: gentle-pulse 2s infinite; }
        .profile-card { transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
        .profile-card:hover { transform: translateY(-1px); box-shadow: 0 4px 12px -2px rgba(0, 0, 0, 0.08); }
        .dashboard-icon, .complaints-icon, .feedback-icon { width: 24px; height: 24px; }
        .header-2025 { backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); background: rgba(255,255,255,0.85); border-bottom: 1px solid rgba(255,255,255,0.2); box-shadow: 0 1px 3px 0 rgba(0,0,0,0.05); margin-left: 256px; width: calc(100% - 256px); }
        html { scroll-behavior: smooth; }
        main { margin-left: 256px; padding: 1.5rem; }
        @media (max-width: 767px) {
            .header-2025 { margin-left: 0; width: 100%; }
            main { margin-left: 0; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.translate-x-0 { transform: translateX(0); }
        }
        /* Card Styles for Complaints List */
        .complaint-card { 
            background: white; 
            border: 1px solid #e5e7eb; 
            border-radius: 0.75rem; 
            padding: 1.5rem; 
            margin-bottom: 1rem; 
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1); 
            transition: all 0.2s ease; 
        }
        .complaint-card:hover { 
            box-shadow: 0 4px 12px -2px rgba(0, 0, 0, 0.08); 
            transform: translateY(-1px); 
        }
        .complaint-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: flex-start; 
            margin-bottom: 1rem; 
            flex-wrap: wrap; 
            gap: 1rem; 
        }
        .complaint-meta { 
            display: flex; 
            flex-direction: column; 
            gap: 0.5rem; 
        }
        .complaint-description { 
            margin-bottom: 1rem; 
            line-height: 1.5; 
            color: #374151; 
            max-height: 4.5em; /* Approx 3 lines */
            overflow: hidden; 
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            word-break: break-word;
        }
        .complaint-description.full { max-height: none; -webkit-line-clamp: unset; }
        .toggle-description { 
            color: #3b82f6; 
            font-size: 0.875rem; 
            cursor: pointer; 
            text-decoration: underline; 
        }
        .complaint-footer { 
            display: flex; 
            flex-wrap: wrap; 
            gap: 0.5rem; 
            align-items: center; 
            justify-content: space-between; 
        }
        .complaint-actions { 
            display: flex; 
            gap: 0.5rem; 
        }
        .complaint-actions a, .complaint-actions button { 
            padding: 0.25rem 0.5rem; 
            border-radius: 0.375rem; 
            font-size: 0.75rem; 
            text-decoration: none; 
            transition: all 0.2s ease; 
        }
        /* Modal */
        .modal { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,.5); z-index: 50; }
        .modal.show { display: flex; }
        /* Filters */
        .filter-group { transition: all 0.2s ease; }
        .filter-group:hover { box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
    </style>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex">
        <!-- Sidebar -->
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

                <!-- Navigation -->
                <nav class="flex-1 py-2 px-4 space-y-2">
                    <a href="dashboard.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="dashboard-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                        </svg>
                        Dashboard
                    </a>
                    <a href="manage_complaints.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-blue-600 bg-blue-50 border border-blue-200 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="complaints-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        Manage Complaints
                    </a>
                    <a href="manage_staff.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="feedback-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                        </svg>
                        Manage Staff
                    </a>
                    <a href="view_feedback.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="feedback-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                        </svg>
                        View Feedback
                    </a>
                </nav>

                <!-- Staff Info & Logout -->
                <div class="p-4 border-t border-gray-100">
                    <div class="flex items-center space-x-3 mb-4">
                        <div class="relative avatar-glow">
                            <img src="<?php echo e(get_avatar_src($staff['profile_picture'], $staff['name'])); ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover"/>
                            <div class="absolute -bottom-0.5 -right-0.5 w-4 h-4 bg-green-500 border-2 border-white rounded-full animate-gentle-pulse"></div>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900"><?php echo e($staff['name']); ?></p>
                            <p class="text-xs text-gray-500"><?php echo e($staff['role']); ?></p>
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

        <!-- Main Content -->
        <div class="flex-1">
            <header class="header-2025 sticky top-0 z-20">
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4">

                        </div>
                        <div class="flex items-center space-x-4">
                            <button class="relative p-2 text-gray-600 hover:text-gray-900 transition-all duration-200 rounded-full hover:bg-gray-100 group" id="notificationBtn">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0M3.124 7.5A8.969 8.969 0 0 1 5.292 3m13.416 0a8.969 8.969 0 0 1 2.168 4.5" />
                                </svg>
                                <div class="notification-badge">3</div>
                            </button>

                            <div class="flex items-center space-x-3 p-2 profile-card hover:bg-gray-50 rounded-xl transition-all duration-200 group cursor-pointer relative" id="profileDropdown">
                                <div class="avatar-glow">
                                    <img src="<?php echo e(get_avatar_src($staff['profile_picture'], $staff['name'])); ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover"/>
                                    <div class="absolute -bottom-0.5 -right-0.5 w-4 h-4 bg-green-500 border-2 border-white rounded-full animate-gentle-pulse"></div>
                                </div>
                                <div class="hidden md:block">
                                    <p class="text-sm font-semibold text-gray-900 truncate max-w-32"><?php echo e($staff['name']); ?></p>
                                    <p class="text-xs text-gray-500 truncate max-w-32"><?php echo e($staff['role']); ?></p>
                                </div>
                                <i class="fas fa-chevron-down text-gray-400 text-sm ml-1 transition-transform duration-200 group-hover:text-gray-600"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main class="p-6 space-y-6">
                <!-- Filters -->
                <div class="card p-6">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between space-y-4 md:space-y-0">
                        <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-4">
                            <select class="filter-group border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent" id="statusFilter">
                                <option value="">All Status</option>
                                <?php foreach ($ALLOWED_STATUSES as $s): ?>
                                    <option value="<?php echo e($s); ?>" <?php echo $status === $s ? 'selected' : ''; ?>><?php echo e($s); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select class="filter-group border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent" id="categoryFilter">
                                <option value="">All Categories</option>
                                <?php foreach ($ALLOWED_CATEGORIES as $cat): ?>
                                    <option value="<?php echo e($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>><?php echo e($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" class="filter-group border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent" id="searchInput" placeholder="Search description or user..." value="<?php echo e($q); ?>">
                        </div>
                        <div class="flex space-x-2">
                            <a href="?status=&category=&q=&export=1" class="btn-primary px-4 py-2 rounded-lg text-sm font-medium flex items-center">
                                <i class="fas fa-download mr-2"></i>Export CSV
                            </a>
                            <button onclick="applyFilters()" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">Apply Filters</button>
                        </div>
                    </div>
                </div>

                    <!-- Complaints Cards -->
                    <div class="card overflow-hidden">
                        <div class="flex items-center justify-between p-6 border-b border-gray-200">
                            <h2 class="text-lg font-semibold text-gray-900">All Complaints (<?php echo (int)$total_rows; ?>)</h2>
                            <p class="text-xs text-gray-500">Showing <?php echo min($per_page, $total_rows - $offset); ?> of <?php echo $total_rows; ?> results</p>
                        </div>
                        <div class="p-6 space-y-4">
                            <?php if ($total_rows === 0): ?>
                                <div class="text-center py-12 text-gray-500">No complaints found. Start by monitoring new submissions.</div>
                            <?php else: ?>
                                <?php while ($row = mysqli_fetch_assoc($list_res)): ?>
                                    <?php
                                    $status_badge = 'bg-gray-100 text-gray-700';
                                    if ($row['status'] === 'Pending') $status_badge = 'bg-yellow-50 text-yellow-700 border border-yellow-200';
                                    if ($row['status'] === 'In Progress') $status_badge = 'bg-blue-50 text-blue-700 border border-blue-200';
                                    if ($row['status'] === 'Resolved') $status_badge = 'bg-green-50 text-green-700 border border-green-200';
                                    if ($row['status'] === 'Closed') $status_badge = 'bg-gray-100 text-gray-700 border border-gray-200';

                                    $sentiment_badge = 'bg-gray-50 text-gray-600 border border-gray-200';
                                    if ($row['sentiment'] === 'Positive') $sentiment_badge = 'bg-green-50 text-green-700 border border-green-200';
                                    if ($row['sentiment'] === 'Negative') $sentiment_badge = 'bg-red-50 text-red-700 border border-red-200';

                                    // Dynamic Due Date Badge Logic (for Admin)
                                    $due_display = '';
                                    if ($row['action_due']): 
                                        $current_date = date('Y-m-d'); // Current date (October 06, 2025 context)
                                        $due_date = $row['action_due'];
                                        $days_until_due = (strtotime($due_date) - strtotime($current_date)) / (60 * 60 * 24);
                                        
                                        $due_class = 'bg-green-50 text-green-700 border border-green-200'; // Default: Green
                                        if ($days_until_due <= 0) {
                                            $due_class = 'bg-red-50 text-red-700 border border-red-200 animate-pulse'; // Red + pulse if overdue
                                        } elseif ($days_until_due <= 3) {
                                            $due_class = 'bg-yellow-50 text-yellow-700 border border-yellow-200'; // Yellow if near
                                        }
                                        $due_display = '
                                        <span class="status-badge inline-block ' . $due_class . ' px-2 py-1 text-xs font-medium flex items-center">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3 mr-1 flex-shrink-0">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                            </svg>
                                            Due: ' . e(date('M d, Y', strtotime($due_date))) . '
                                        </span>';
                                    endif;
                                    ?>
                                    <div class="complaint-card">
                                        <div class="complaint-header">
                                            <div class="complaint-meta">
                                                <div class="flex items-center space-x-2">
                                                    <span class="font-mono text-sm font-semibold text-gray-700">#<?php echo (int)$row['complaint_id']; ?></span>
                                                    <span class="status-badge inline-block <?php echo $status_badge; ?>"><?php echo e($row['status']); ?></span>
                                                </div>
                                                <!-- Category with SVG and Badge -->
                                                <div class="flex items-center space-x-2 mt-1">
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-400 flex-shrink-0">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 3.75H6.912a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H15M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859M12 3v8.25m0 0-3-3m3 3 3-3" />
                                                    </svg>
                                                    <p class="text-sm font-medium text-gray-800"><?php echo e($row['category']); ?></p>
                                                    <span class="status-badge bg-gray-50 text-gray-600 border border-gray-200 text-xs px-1 py-0.5">Category</span>
                                                </div>
                                                <!-- Customer Information with SVG -->
                                                <div class="flex items-center space-x-2 mt-1">
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-400 flex-shrink-0">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                                    </svg>
                                                    <img src="<?php echo e(get_avatar_src($row['profile_picture'], $row['user_name'] ?? 'User')); ?>" alt="User Avatar" class="w-6 h-6 rounded-full">
                                                    <div>
                                                        <p class="text-sm font-medium text-gray-900 flex items-center">
                                                            <?php echo e($row['user_name'] ?? 'Anonymous'); ?>
                                                            <?php if (!empty($row['user_name'])): ?>
                                                                <span class="status-badge inline-block bg-green-50 text-green-700 border border-green-200 px-1 py-0.5 text-xs font-medium ml-1">Customer</span>
                                                            <?php endif; ?>
                                                        </p>
                                                        <p class="text-xs text-gray-500"><?php echo e($row['user_email'] ?? 'N/A'); ?></p>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <?php echo $due_display; ?>
                                                <?php if (!empty($row['sentiment'])): ?>
                                                    <span class="sentiment-badge inline-block <?php echo $sentiment_badge; ?>"><?php echo e($row['sentiment']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="complaint-description" id="desc-<?php echo (int)$row['complaint_id']; ?>">
                                            <?php echo e($row['description']); ?>
                                        </div>
                                        <?php if (strlen($row['description']) > 150): ?>
                                            <button class="toggle-description" onclick="toggleDescription(<?php echo (int)$row['complaint_id']; ?>)">Read more</button>
                                        <?php endif; ?>
                                        <div class="complaint-footer">
                                            <div class="flex flex-wrap gap-2">
                                                <?php if (!empty($row['attachment_path'])): ?>
                                                    <a href="../Uploads/complaints/<?php echo e($row['attachment_path']); ?>" target="_blank" class="text-blue-600 hover:text-blue-900 text-xs inline-flex items-center">
                                                        <i class="fas fa-paperclip mr-1"></i>Attachment
                                                    </a>
                                                <?php endif; ?>
                                                <?php if (!empty($row['staff_name'])): ?>
                                                    <span class="text-xs text-gray-600">Assigned: <?php echo e($row['staff_name']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-xs text-gray-400">Unassigned</span>
                                                <?php endif; ?>
                                                <span class="text-xs text-gray-500">Created: <?php echo e(date('M d, Y', strtotime($row['created_at']))); ?></span>
                                                <span class="text-xs text-gray-500">Updated: <?php echo e(date('M d, Y', strtotime($row['updated_at']))); ?></span>
                                            </div>
                                            <div class="complaint-actions">
                                                <a href="view_complaint.php?id=<?php echo (int)$row['complaint_id']; ?>" class="bg-blue-50 text-blue-600 hover:bg-blue-100 px-2 py-1 rounded">
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3 inline">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                                    </svg>
                                                    View
                                                </a>
                                                <button onclick="openAssignModal(<?php echo (int)$row['complaint_id']; ?>)" class="bg-green-50 text-green-600 hover:bg-green-100 px-2 py-1 rounded">
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3 inline mr-1">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z" />
                                                    </svg>
                                                    Assign
                                                </button>
                                                <button onclick="openStatusModal(<?php echo (int)$row['complaint_id']; ?>)" class="bg-yellow-50 text-yellow-600 hover:bg-yellow-100 px-2 py-1 rounded">
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3 inline mr-1">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                                    </svg>
                                                    Update
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; mysqli_stmt_close($list_stmt); ?>
                            <?php endif; ?>
                        </div>

                        <!-- Pagination (rest remains the same) -->
                        <?php if ($total_pages > 1): ?>
                            <div class="p-6 border-t border-gray-200 bg-gray-50">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <?php
                                    $qs = $_GET;
                                    unset($qs['page']);
                                    $base = 'manage_complaints.php?' . http_build_query($qs);
                                    ?>
                                    <a href="<?php echo $base . '&page=1'; ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 text-sm <?php echo $page == 1 ? 'pointer-events-none opacity-50' : ''; ?>">« First</a>
                                    <a href="<?php echo $base . '&page=' . max(1, $page - 1); ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 text-sm <?php echo $page == 1 ? 'pointer-events-none opacity-50' : ''; ?>">‹ Prev</a>
                                    <span class="text-sm text-gray-600">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                                    <a href="<?php echo $base . '&page=' . min($total_pages, $page + 1); ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 text-sm <?php echo $page == $total_pages ? 'pointer-events-none opacity-50' : ''; ?>">Next ›</a>
                                    <a href="<?php echo $base . '&page=' . $total_pages; ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 text-sm <?php echo $page == $total_pages ? 'pointer-events-none opacity-50' : ''; ?>">Last »</a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="p-6 border-t border-gray-200 bg-gray-50">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <?php
                                $qs = $_GET;
                                unset($qs['page']);
                                $base = 'manage_complaints.php?' . http_build_query($qs);
                                ?>
                                <a href="<?php echo $base . '&page=1'; ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 text-sm <?php echo $page == 1 ? 'pointer-events-none opacity-50' : ''; ?>">« First</a>
                                <a href="<?php echo $base . '&page=' . max(1, $page - 1); ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 text-sm <?php echo $page == 1 ? 'pointer-events-none opacity-50' : ''; ?>">‹ Prev</a>
                                <span class="text-sm text-gray-600">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                                <a href="<?php echo $base . '&page=' . min($total_pages, $page + 1); ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 text-sm <?php echo $page == $total_pages ? 'pointer-events-none opacity-50' : ''; ?>">Next ›</a>
                                <a href="<?php echo $base . '&page=' . $total_pages; ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 text-sm <?php echo $page == $total_pages ? 'pointer-events-none opacity-50' : ''; ?>">Last »</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Mobile Menu Toggle -->
    <button id="mobileMenuToggle" class="fixed top-4 left-4 z-40 p-2 rounded-lg text-gray-600 bg-white shadow-lg md:hidden">
        <i class="fas fa-bars text-lg"></i>
    </button>

    <!-- Profile Dropdown -->
    <div id="profileDropdownMenu" class="hidden absolute right-6 top-20 w-48 bg-white rounded-xl shadow-lg border border-gray-100 py-2 z-30"></div>

    <!-- Assign Modal -->
    <div id="assignModal" class="modal">
        <div class="bg-white w-11/12 max-w-md rounded-2xl p-6 shadow-2xl">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Assign Staff</h3>
                <button onclick="closeAssignModal()" class="text-gray-500 hover:text-gray-800"><i class="fas fa-times"></i></button>
            </div>
            <form id="assignForm" method="POST" action="assign_complaint.php">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                <input type="hidden" name="complaint_id" id="assignComplaintId">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Select Staff</label>
                        <select name="staff_id" class="w-full border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500" required>
                            <option value="">Choose staff member</option>
                            <?php foreach ($staff_list as $s): ?>
                                <option value="<?php echo (int)$s['staff_id']; ?>"><?php echo e($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex justify-end space-x-2">
                        <button type="button" onclick="closeAssignModal()" class="px-4 py-2 border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50">Cancel</button>
                        <button type="submit" class="btn-primary px-4 py-2 rounded-lg text-sm font-medium">Assign</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Status Update Modal -->
    <div id="statusModal" class="modal">
        <div class="bg-white w-11/12 max-w-md rounded-2xl p-6 shadow-2xl">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Update Status</h3>
                <button onclick="closeStatusModal()" class="text-gray-500 hover:text-gray-800"><i class="fas fa-times"></i></button>
            </div>
            <form id="statusForm" method="POST" action="update_status.php">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                <input type="hidden" name="complaint_id" id="statusComplaintId">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">New Status</label>
                        <select name="status" class="w-full border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500" required>
                            <?php foreach ($ALLOWED_STATUSES as $s): ?>
                                <option value="<?php echo e($s); ?>"><?php echo e($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Action Due Date (Optional)</label>
                        <input type="date" name="action_due" class="w-full border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="flex justify-end space-x-2">
                        <button type="button" onclick="closeStatusModal()" class="px-4 py-2 border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50">Cancel</button>
                        <button type="submit" class="btn-primary px-4 py-2 rounded-lg text-sm font-medium">Update</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

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

        // Profile dropdown
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

        function hideProfileDropdown() { profileDropdownMenu.classList.add('hidden'); }
        document.addEventListener('click', hideProfileDropdown);

        // Notification
        document.getElementById('notificationBtn').addEventListener('click', function(e) {
            e.stopPropagation();
            this.style.transform = 'scale(0.95)';
            setTimeout(() => { this.style.transform = 'scale(1)'; }, 150);
            alert('Notifications feature coming soon! 🔔');
        });

        // Filters
        function applyFilters() {
            const status = document.getElementById('statusFilter').value;
            const category = document.getElementById('categoryFilter').value;
            const q = document.getElementById('searchInput').value;
            let url = 'manage_complaints.php?';
            if (status) url += `status=${encodeURIComponent(status)}&`;
            if (category) url += `category=${encodeURIComponent(category)}&`;
            if (q) url += `q=${encodeURIComponent(q)}&`;
            url += 'page=1';
            window.location.href = url;
        }

        // Toggle Description
        function toggleDescription(id) {
            const desc = document.getElementById('desc-' + id);
            const button = event.target;
            if (desc.classList.contains('full')) {
                desc.classList.remove('full');
                button.textContent = 'Read more';
            } else {
                desc.classList.add('full');
                button.textContent = 'Read less';
            }
        }

        // Modals
        function openAssignModal(id) {
            document.getElementById('assignComplaintId').value = id;
            document.getElementById('assignModal').classList.add('show');
        }
        function closeAssignModal() { document.getElementById('assignModal').classList.remove('show'); }

        function openStatusModal(id) {
            document.getElementById('statusComplaintId').value = id;
            document.getElementById('statusModal').classList.add('show');
        }
        function closeStatusModal() { document.getElementById('statusModal').classList.remove('show'); }

        // Close modals on outside click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) this.classList.remove('show');
            });
        });
    </script>
</body>
</html>

<?php mysqli_close($conn); ?>