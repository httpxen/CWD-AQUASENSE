<?php
date_default_timezone_set('Asia/Manila');
include 'session_check.php';

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
        return '../' . $profile_picture;
    }
    return 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($name);
}

$alerts = [];

// ---------------------------
// Fetch current staff info
// ---------------------------
$staff_id = $_SESSION['staff_id'];
$staff_query = "SELECT staff_id, name, profile_picture, email, role, created_at FROM staff WHERE staff_id = ?";
$stmt = mysqli_prepare($conn, $staff_query);
mysqli_stmt_bind_param($stmt, "i", $staff_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$staff  = mysqli_fetch_assoc($result);

if (!$staff) {
    session_unset();
    session_destroy();
    header("Location: admin_login.php?message=Account not found.");
    exit();
}

// ---------------------------
// Search & Filters
// ---------------------------
$search        = sanitize($_GET['search']  ?? '');
$action_filter = sanitize($_GET['action']  ?? '');
$entity_filter = sanitize($_GET['entity']  ?? '');

$where  = [];
$params = [];
$types  = "";

if ($search !== '') {
    $where[]  = "(al.details LIKE ? OR s.name LIKE ? OR al.entity_type LIKE ?)";
    $like     = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types   .= "sss";
}
if ($action_filter !== '') {
    $where[]  = "al.action = ?";
    $params[] = $action_filter;
    $types   .= "s";
}
if ($entity_filter !== '') {
    $where[]  = "al.entity_type = ?";
    $params[] = $entity_filter;
    $types   .= "s";
}

$where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// ---------------------------
// Fetch Audit Logs
// ---------------------------
$logs_query = "
    SELECT al.*, s.name as staff_name 
    FROM audit_logs al 
    LEFT JOIN staff s ON al.staff_id = s.staff_id 
    $where_clause
    ORDER BY al.created_at DESC
    LIMIT 1000
";

$stmt_logs = mysqli_prepare($conn, $logs_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt_logs, $types, ...$params);
}
mysqli_stmt_execute($stmt_logs);
$logs_result = mysqli_stmt_get_result($stmt_logs);

$raw_logs = [];
while ($row = mysqli_fetch_assoc($logs_result)) {
    $raw_logs[] = $row;
}

// ---------------------------
// Group assign logs that fired
// at the same time for the same
// complaint into a single row
// ---------------------------
$logs = [];
$assign_groups = []; // key: "staff_id|entity_id|minute"

foreach ($raw_logs as $row) {
    if ($row['action'] === 'assign' && $row['entity_type'] === 'complaint') {
        // Group by staff + entity + minute (within 60 seconds = same batch)
        $minute_key = date('YmdHi', strtotime($row['created_at']));
        $group_key  = ($row['staff_id'] ?? 'x') . '|' . $row['entity_id'] . '|' . $minute_key;

        if (isset($assign_groups[$group_key])) {
            // Merge assignee into existing group
            $idx  = $assign_groups[$group_key];
            $newv = json_decode($row['new_values'] ?? '{}', true);
            $name = $newv['assigned_to'] ?? '';
            if ($name && is_string($name)) {
                $logs[$idx]['_assignees'][] = $name;
            }
        } else {
            $newv = json_decode($row['new_values'] ?? '{}', true);
            $name = $newv['assigned_to'] ?? '';
            $row['_assignees'] = ($name && is_string($name)) ? [$name] : [];
            $assign_groups[$group_key] = count($logs);
            $logs[] = $row;
        }
    } else {
        $logs[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Audit Logs | CWD AquaSense Admin</title>
    <link rel="icon" type="image/png" href="../assets/icons/AquaSense2.png" />
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <link rel="stylesheet" href="css/dashboard.css">
    <style>
        .log-details { 
            max-height: 220px; 
            overflow-y: auto; 
            line-height: 1.5; 
        }
        .json-pre { 
            font-size: 0.8rem; 
            background: #f8fafc; 
            padding: 10px; 
            border-radius: 6px; 
            white-space: pre-wrap;
            font-family: ui-monospace, monospace;
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex">

        <!-- ==================== SIDEBAR ==================== -->
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
                    <a href="ticket_assigns.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="mr-3 w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 0 1 0 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 0 1 0-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375Z" />
                        </svg>
                        Ticket Assigns
                    </a>
                    <a href="manage_staff.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="profile-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                        </svg>
                        View Staff
                    </a>
                    <a href="manage_user.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="users-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                        </svg>
                        View Users
                    </a>
                    <a href="view_feedback.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="feedback-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                        </svg>
                        View Feedback
                    </a>
                    <a href="announcements.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="announcement-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 0 8.835-2.535m0 0A23.74 23.74 0 0 0 18.795 3m.38 1.125a23.91 23.91 0 0 1 1.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 0 0 1.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 0 1 0 3.46" />
                        </svg>
                        Announcement Section
                    </a>
                    <a href="audit_logs.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-blue-600 bg-blue-50 border border-blue-200 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="mr-3 w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0 1 18 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3 1.5 1.5 3-3.75" />
                        </svg>
                        Audit Logs
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

        <!-- ==================== MAIN CONTENT ==================== -->
        <div class="flex-1">
            <header class="header-2025 sticky top-0 z-20">
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4">
                            
                        </div>

                        <div class="flex items-center space-x-3 p-2 profile-card hover:bg-gray-50 rounded-xl transition-all duration-200 group cursor-pointer relative" id="profileDropdown">
                            <div class="relative avatar-glow">
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
            </header>

            <main class="p-6 space-y-6">
                <!-- Alerts -->
                <?php if (!empty($alerts)): ?>
                    <?php foreach ($alerts as $a): ?>
                        <div class="status <?php echo $a['type'] === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : ($a['type'] === 'error' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-blue-50 text-blue-700 border border-blue-200'); ?>">
                            <div class="flex items-start">
                                <i class="mr-2 mt-0.5 <?php echo $a['type'] === 'success' ? 'fa-solid fa-circle-check' : ($a['type'] === 'error' ? 'fa-solid fa-circle-exclamation' : 'fa-solid fa-circle-info'); ?>"></i>
                                <p class="text-sm font-medium"><?php echo htmlspecialchars($a['msg']); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Search & Filter -->
                <div class="bg-white p-5 rounded-xl shadow-sm border border-gray-200">
                    <form method="GET" class="flex flex-wrap gap-4 items-center">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Search staff, details, or entity..." 
                               class="flex-1 px-5 py-3 border border-gray-300 rounded-xl focus:outline-none focus:border-blue-500">
                        
                        <select name="action" class="px-5 py-3 border border-gray-300 rounded-xl focus:outline-none">
                            <option value="">All Actions</option>
                            <option value="create" <?php if ($action_filter === 'create') echo 'selected'; ?>>Create</option>
                            <option value="update" <?php if ($action_filter === 'update') echo 'selected'; ?>>Update</option>
                            <option value="assign" <?php if ($action_filter === 'assign') echo 'selected'; ?>>Assign</option>
                            <option value="delete" <?php if ($action_filter === 'delete') echo 'selected'; ?>>Delete</option>
                        </select>

                        <select name="entity" class="px-5 py-3 border border-gray-300 rounded-xl focus:outline-none">
                            <option value="">All Entities</option>
                            <option value="complaint"    <?php if ($entity_filter === 'complaint')    echo 'selected'; ?>>Complaint</option>
                            <option value="announcement" <?php if ($entity_filter === 'announcement') echo 'selected'; ?>>Announcement</option>
                        </select>

                        <button type="submit" class="px-8 py-3 bg-blue-600 text-white rounded-xl hover:bg-blue-700 transition flex items-center gap-2">
                            <i class="fas fa-search"></i> Filter
                        </button>
                        <a href="audit_logs.php" class="px-8 py-3 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition">Clear</a>
                    </form>
                </div>

                <!-- Audit Logs Table -->
                <div class="bg-white shadow-sm border border-gray-200 rounded-xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500">Timestamp</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500">Staff</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500">Action</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500">Entity</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500">Entity ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500">Details</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($logs)): ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                            No audit logs found yet.<br>
                                            <small>Actions on complaints and announcements will appear here.</small>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($logs as $log):
                                        $raw_details_raw = $log['details'] ?? '-';
                                        $raw_details     = is_array($raw_details_raw) ? json_encode($raw_details_raw) : (string) $raw_details_raw;
                                        $display_details = htmlspecialchars($raw_details);
                                        $entity_type    = $log['entity_type'] ?? '';
                                        $action         = $log['action'] ?? '';

                                        // ============================================================
                                        // DISPLAY: COMPLAINT — assign action (show who was assigned)
                                        // ============================================================
                                        if ($entity_type === 'complaint' && $action === 'assign') {
                                            $newv = !empty($log['new_values']) ? json_decode($log['new_values'], true) : [];
                                            $oldv = !empty($log['old_values']) ? json_decode($log['old_values'], true) : [];

                                            // Use pre-grouped assignee list if available
                                            $assignees = !empty($log['_assignees'])
                                                ? $log['_assignees']
                                                : ((!empty($newv['assigned_to']) && is_string($newv['assigned_to'])) ? [$newv['assigned_to']] : []);

                                            $assignees_str = implode(', ', array_unique($assignees));

                                            $assign_html  = "<div class='text-sm space-y-1'>";
                                            $assign_html .= "<div><strong class='text-purple-700'>Complaint Assigned</strong></div>";

                                            if ($assignees_str) {
                                                $assign_html .= "<div>• <strong>Assigned To:</strong> <span class='text-green-600 font-semibold'>" . htmlspecialchars($assignees_str) . "</span></div>";
                                            }

                                            if (!empty($newv['status'])) {
                                                $status_val = is_array($newv['status']) ? implode(', ', $newv['status']) : (string) $newv['status'];
                                                $assign_html .= "<div>• <strong>Status set to:</strong> <span class='text-blue-600'>" . htmlspecialchars($status_val) . "</span></div>";
                                            }

                                            // Fallback if nothing parsed
                                            if (!$assignees_str && $raw_details !== '-') {
                                                $raw_str = is_array($raw_details) ? json_encode($raw_details) : (string) $raw_details;
                                                $assign_html .= "<div class='text-gray-500'>" . htmlspecialchars($raw_str) . "</div>";
                                            }

                                            $assign_html .= "</div>";
                                            $display_details = $assign_html;

                                        // ============================================================
                                        // DISPLAY: COMPLAINT — status updates with old/new values
                                        // ============================================================
                                        } elseif ($entity_type === 'complaint' && !empty($log['old_values']) && !empty($log['new_values'])) {
                                            $oldv = json_decode($log['old_values'], true);
                                            $newv = json_decode($log['new_values'], true);

                                            $changes_html = "<div class='text-sm space-y-1'>";
                                            $has_change   = false;

                                            if (isset($newv['status']) && ($oldv['status'] ?? '') !== $newv['status']) {
                                                $changes_html .= "<div><strong class='text-blue-700'>Status Changed:</strong><br>";
                                                $changes_html .= "• From <span class='text-red-600 font-medium'>" . htmlspecialchars($oldv['status'] ?? '—') .
                                                                 "</span> → <span class='text-green-600 font-semibold'>" .
                                                                 htmlspecialchars($newv['status']) . "</span></div>";
                                                $has_change = true;
                                            }

                                            if (!empty($newv['action_due']) && ($oldv['action_due'] ?? '') !== $newv['action_due']) {
                                                $changes_html .= "<div>• <strong>Due Date:</strong> " . htmlspecialchars($newv['action_due']) . "</div>";
                                                $has_change = true;
                                            }

                                            if (!empty($newv['resolved_at']) && ($oldv['resolved_at'] ?? '') !== $newv['resolved_at']) {
                                                $changes_html .= "<div>• <strong>Resolved At:</strong> " .
                                                                 (function($ts) {
                                                                     $dt = new DateTime($ts, new DateTimeZone('UTC'));
                                                                     $dt->setTimezone(new DateTimeZone('Asia/Manila'));
                                                                     return $dt->format('M j, Y g:i A');
                                                                 })($newv['resolved_at']) . "</div>";
                                                $has_change = true;
                                            }

                                            if (!empty($newv['comment'])) {
                                                $changes_html .= "<div class='mt-2'><strong class='text-indigo-700'>Staff Comment:</strong><br>";
                                                $changes_html .= "<span class='italic text-gray-700 bg-gray-50 p-2 rounded block mt-1 border-l-4 border-indigo-400'>" .
                                                                 nl2br(htmlspecialchars($newv['comment'])) . "</span></div>";
                                                $has_change = true;
                                            }

                                            $changes_html .= "</div>";
                                            if ($has_change) $display_details = $changes_html;

                                        // ============================================================
                                        // DISPLAY: ANNOUNCEMENT — create / update / delete
                                        // ============================================================
                                        } elseif ($entity_type === 'announcement') {

                                            if ($action === 'create' && !empty($log['new_values'])) {
                                                $newv         = json_decode($log['new_values'], true);
                                                $ann_html     = "<div class='text-sm space-y-1'>";
                                                $ann_html    .= "<div><strong class='text-green-700'>Announcement Created</strong></div>";
                                                if (!empty($newv['title']))
                                                    $ann_html .= "<div>• <strong>Title:</strong> " . htmlspecialchars($newv['title']) . "</div>";
                                                if (!empty($newv['start_date'])) {
                                                    $start_str = $newv['start_date'] . (!empty($newv['start_time']) ? ' ' . $newv['start_time'] : '');
                                                    $ann_html .= "<div>• <strong>Start:</strong> " . date('M j, Y g:i A', strtotime($start_str)) . "</div>";
                                                }
                                                if (!empty($newv['end_date'])) {
                                                    $end_str = $newv['end_date'] . (!empty($newv['end_time']) ? ' ' . $newv['end_time'] : '');
                                                    $ann_html .= "<div>• <strong>End:</strong> " . date('M j, Y g:i A', strtotime($end_str)) . "</div>";
                                                }
                                                if (!empty($newv['affected_areas']))
                                                    $ann_html .= "<div>• <strong>Affected Areas:</strong> " . htmlspecialchars($newv['affected_areas']) . "</div>";
                                                $ann_html    .= "</div>";
                                                $display_details = $ann_html;

                                            } elseif ($action === 'update' && !empty($log['old_values']) && !empty($log['new_values'])) {
                                                $oldv      = json_decode($log['old_values'], true);
                                                $newv      = json_decode($log['new_values'], true);
                                                $ann_html  = "<div class='text-sm space-y-1'>";
                                                $ann_html .= "<div><strong class='text-blue-700'>Announcement Updated</strong></div>";
                                                $has_ann_change = false;

                                                if (($oldv['title'] ?? '') !== ($newv['title'] ?? '')) {
                                                    $ann_html .= "<div>• <strong>Title:</strong> <span class='line-through text-red-400'>" . htmlspecialchars($oldv['title'] ?? '—') . "</span> → <span class='text-green-600'>" . htmlspecialchars($newv['title'] ?? '') . "</span></div>";
                                                    $has_ann_change = true;
                                                }
                                                if (($oldv['start_date'] ?? '') !== ($newv['start_date'] ?? '')) {
                                                    $ann_html .= "<div>• <strong>Start Date:</strong> <span class='line-through text-red-400'>" . htmlspecialchars($oldv['start_date'] ?? '—') . "</span> → <span class='text-green-600'>" . htmlspecialchars($newv['start_date'] ?? '') . "</span></div>";
                                                    $has_ann_change = true;
                                                }
                                                if (($oldv['end_date'] ?? '') !== ($newv['end_date'] ?? '')) {
                                                    $ann_html .= "<div>• <strong>End Date:</strong> <span class='line-through text-red-400'>" . htmlspecialchars($oldv['end_date'] ?? '—') . "</span> → <span class='text-green-600'>" . htmlspecialchars($newv['end_date'] ?? '') . "</span></div>";
                                                    $has_ann_change = true;
                                                }
                                                if (($oldv['affected_areas'] ?? '') !== ($newv['affected_areas'] ?? '')) {
                                                    $ann_html .= "<div>• <strong>Affected Areas:</strong> <span class='line-through text-red-400'>" . htmlspecialchars($oldv['affected_areas'] ?? '—') . "</span> → <span class='text-green-600'>" . htmlspecialchars($newv['affected_areas'] ?? '') . "</span></div>";
                                                    $has_ann_change = true;
                                                }
                                                if (!$has_ann_change) {
                                                    $ann_html .= "<div class='text-gray-500'>Minor changes (description or image updated)</div>";
                                                }
                                                $ann_html       .= "</div>";
                                                $display_details = $ann_html;

                                            } elseif ($action === 'delete' && !empty($log['old_values'])) {
                                                $oldv        = json_decode($log['old_values'], true);
                                                $ann_html    = "<div class='text-sm space-y-1'>";
                                                $ann_html   .= "<div><strong class='text-red-700'>Announcement Deleted</strong></div>";
                                                if (!empty($oldv['title']))
                                                    $ann_html .= "<div>• <strong>Title:</strong> " . htmlspecialchars($oldv['title']) . "</div>";
                                                if (!empty($oldv['start_date']))
                                                    $ann_html .= "<div>• <strong>Start:</strong> " . htmlspecialchars($oldv['start_date']) . "</div>";
                                                if (!empty($oldv['end_date']))
                                                    $ann_html .= "<div>• <strong>End:</strong> " . htmlspecialchars($oldv['end_date']) . "</div>";
                                                $ann_html   .= "</div>";
                                                $display_details = $ann_html;
                                            }

                                        // ============================================================
                                        // FALLBACK: plain JSON details
                                        // ============================================================
                                        } elseif (str_starts_with(trim($raw_details), '{')) {
                                            $json = json_decode($raw_details, true);
                                            if (json_last_error() === JSON_ERROR_NONE) {
                                                $display_details = htmlspecialchars($json['message'] ?? $raw_details);
                                            }
                                        }
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">
                                            <?php
                                            $dt = new DateTime($log['created_at'], new DateTimeZone('UTC'));
                                            $dt->setTimezone(new DateTimeZone('Asia/Manila'));
                                            echo $dt->format('M j, Y g:i A');
                                            ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm font-medium">
                                            <?php echo htmlspecialchars($log['staff_name'] ?? 'System'); ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="px-3 py-1 text-xs font-semibold rounded-full 
                                                <?php
                                                echo $log['action'] === 'create' ? 'bg-green-100 text-green-800'  :
                                                    ($log['action'] === 'update' ? 'bg-blue-100 text-blue-800'   :
                                                    ($log['action'] === 'assign' ? 'bg-purple-100 text-purple-800':
                                                    ($log['action'] === 'delete' ? 'bg-red-100 text-red-800'     : 'bg-gray-100 text-gray-800')));
                                                ?>">
                                                <?php echo strtoupper(htmlspecialchars($log['action'])); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-sm">
                                            <?php
                                            $et = $log['entity_type'] ?? '';
                                            $et_label = ucfirst($et);
                                            $et_class = $et === 'announcement'
                                                ? 'bg-yellow-50 text-yellow-700 border border-yellow-200'
                                                : 'bg-gray-50 text-gray-700 border border-gray-200';
                                            echo "<span class='px-2 py-0.5 text-xs rounded-full font-medium $et_class'>$et_label</span>";
                                            ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500"><?php echo $log['entity_id'] ?? '-'; ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-600 log-details" title="<?php echo htmlspecialchars($raw_details); ?>">
                                            <?php echo $display_details; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Mobile Menu Toggle -->
    <button id="mobileMenuToggle" class="fixed top-4 left-4 z-40 p-2 rounded-lg text-gray-600 bg-white shadow-lg md:hidden">
        <i class="fas fa-bars text-lg"></i>
    </button>

    <!-- Profile Dropdown Menu -->
    <div id="profileDropdownMenu" class="hidden absolute right-6 top-20 w-48 bg-white rounded-xl shadow-lg border border-gray-100 py-2 z-30"></div>

    <script>
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('-translate-x-full');
            sidebar.classList.toggle('translate-x-0');
        });

        window.addEventListener('resize', function() {
            const sidebar = document.querySelector('.sidebar');
            if (window.innerWidth >= 768) {
                sidebar.classList.remove('-translate-x-full');
            } else {
                sidebar.classList.add('-translate-x-full');
            }
        });

        const profileDropdown     = document.getElementById('profileDropdown');
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
                profileDropdownMenu.style.top   = `${rect.bottom + 8}px`;
            } else {
                profileDropdownMenu.classList.add('hidden');
            }
        });

        document.addEventListener('click', function() {
            profileDropdownMenu.classList.add('hidden');
        });
    </script>
</body>
</html>

<?php
if (isset($stmt))      { mysqli_stmt_close($stmt); }
if (isset($stmt_logs)) { mysqli_stmt_close($stmt_logs); }
mysqli_close($conn);
?>