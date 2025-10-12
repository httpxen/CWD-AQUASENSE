<?php
include 'session_check.php'; // Handles DB connection and session

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

// Get complaint ID
$complaint_id = (int)($_GET['id'] ?? 0);
if ($complaint_id <= 0) {
    header("Location: manage_complaints.php?error=Invalid complaint ID");
    exit();
}

// Fetch complaint details
$sql = "
    SELECT c.*, CONCAT(u.first_name, ' ', u.last_name) AS user_name, u.email AS user_email, u.profile_picture,
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
    WHERE c.complaint_id = ?
";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $complaint_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$complaint = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$complaint) {
    header("Location: manage_complaints.php?error=Complaint not found");
    exit();
}

// Fetch staff for assignment dropdown
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

// Helpers
function e($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }
function get_avatar_src($profile_picture, $name) {
    if ($profile_picture) {
        return '../' . $profile_picture;
    }
    return 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($name);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Complaint #<?php echo (int)$complaint_id; ?> | CWD AquaSense Admin</title>
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
        .modal { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,.5); z-index: 50; }
        .modal.show { display: flex; }
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
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z" />
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
                        <div class="avatar-glow">
                            <img src="<?php echo e(get_avatar_src($staff['profile_picture'], $staff['name'])); ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover"/>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900"><?php echo e($staff['name']); ?></p>
                            <p class="text-xs text-gray-500"><?php echo e($staff['role']); ?></p>
                        </div>
                    </div>
                    <a href="logout.php" class="w-full flex items-center justify-center px-4 py-2 text-sm font-medium text-red-600 bg-red-50 rounded-lg hover:bg-red-100 transition-colors">
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
                            <a href="manage_complaints.php" class="text-gray-500 hover:text-gray-900">
                                <i class="fas fa-arrow-left mr-2"></i>
                            </a>
                            <h1 class="text-2xl font-bold text-gray-900">Complaint #<?php echo (int)$complaint_id; ?></h1>
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
                <div class="card p-6 max-w-4xl mx-auto">
                    <!-- Complaint Details -->
                    <div class="space-y-6">
                        <div class="flex justify-between items-start">
                            <div>
                                <h2 class="text-2xl font-bold text-gray-900"><?php echo e($complaint['category']); ?></h2>
                                <p class="text-sm text-gray-500">ID: #<?php echo (int)$complaint_id; ?> | Created: <?php echo e(date('M d, Y H:i', strtotime($complaint['created_at']))); ?> | Updated: <?php echo e(date('M d, Y H:i', strtotime($complaint['updated_at']))); ?></p>
                            </div>
                            <div class="text-right">
                                <span class="status-badge inline-block <?php 
                                    $status_badge = 'bg-gray-100 text-gray-700';
                                    if ($complaint['status'] === 'Pending') $status_badge = 'bg-yellow-50 text-yellow-700 border border-yellow-200';
                                    if ($complaint['status'] === 'In Progress') $status_badge = 'bg-blue-50 text-blue-700 border border-blue-200';
                                    if ($complaint['status'] === 'Resolved') $status_badge = 'bg-green-50 text-green-700 border border-green-200';
                                    if ($complaint['status'] === 'Closed') $status_badge = 'bg-gray-100 text-gray-700 border border-gray-200';
                                    echo $status_badge; 
                                ?>"><?php echo e($complaint['status']); ?></span>
                                <?php if ($complaint['action_due']): ?>
                                    <p class="text-sm text-gray-500">Due: <?php echo e(date('M d, Y', strtotime($complaint['action_due']))); ?></p>
                                <?php endif; ?>
                                <?php if ($complaint['sentiment']): ?>
                                    <span class="sentiment-badge inline-block <?php 
                                        $sentiment_badge = 'bg-gray-50 text-gray-600 border border-gray-200';
                                        if ($complaint['sentiment'] === 'Positive') $sentiment_badge = 'bg-green-50 text-green-700 border border-green-200';
                                        if ($complaint['sentiment'] === 'Negative') $sentiment_badge = 'bg-red-50 text-red-700 border border-red-200';
                                        echo $sentiment_badge; 
                                    ?>"><?php echo e($complaint['sentiment']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- User Info -->
                        <div class="flex items-center space-x-4 p-4 bg-gray-50 rounded-lg">
                            <img src="<?php echo e(get_avatar_src($complaint['profile_picture'], $complaint['user_name'] ?? 'User')); ?>" alt="User Avatar" class="w-12 h-12 rounded-full">
                            <div>
                                <p class="font-semibold text-gray-900"><?php echo e($complaint['user_name'] ?? 'Anonymous'); ?></p>
                                <p class="text-sm text-gray-600"><?php echo e($complaint['user_email'] ?? 'N/A'); ?></p>
                            </div>
                            <?php if ($complaint['staff_name']): ?>
                                <div class="ml-auto text-right">
                                    <p class="text-sm font-medium text-gray-900">Assigned: <?php echo e($complaint['staff_name']); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Description -->
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 mb-2">Description</h3>
                            <p class="text-gray-700 leading-relaxed"><?php echo nl2br(e($complaint['description'])); ?></p>
                        </div>

                        <!-- Attachment -->
                        <?php if ($complaint['attachment_path']): ?>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 mb-2">Attachment</h3>
                                <a href="../uploads/complaints/<?php echo e($complaint['attachment_path']); ?>" target="_blank" class="inline-flex items-center px-4 py-2 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100">
                                    <i class="fas fa-paperclip mr-2"></i> View Attachment
                                </a>
                            </div>
                        <?php endif; ?>

                        <!-- Actions -->
                        <div class="flex space-x-3 pt-4 border-t border-gray-200">
                            <button onclick="openAssignModal(<?php echo (int)$complaint_id; ?>)" class="bg-green-50 text-green-600 hover:bg-green-100 px-4 py-2 rounded-lg flex items-center">
                                <i class="fas fa-user-plus mr-2"></i> Assign Staff
                            </button>
                            <button onclick="openStatusModal(<?php echo (int)$complaint_id; ?>)" class="bg-yellow-50 text-yellow-600 hover:bg-yellow-100 px-4 py-2 rounded-lg flex items-center">
                                <i class="fas fa-edit mr-2"></i> Update Status
                            </button>
                            <a href="manage_complaints.php" class="bg-gray-50 text-gray-600 hover:bg-gray-100 px-4 py-2 rounded-lg flex items-center">
                                <i class="fas fa-arrow-left mr-2"></i> Back to List
                            </a>
                        </div>
                    </div>
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
                <h3 class="text-lg font-semibold text-gray-900">Assign Staff to #<?php echo (int)$complaint_id; ?></h3>
                <button onclick="closeAssignModal()" class="text-gray-500 hover:text-gray-800"><i class="fas fa-times"></i></button>
            </div>
            <form id="assignForm" method="POST" action="assign_complaint.php">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                <input type="hidden" name="complaint_id" value="<?php echo (int)$complaint_id; ?>">
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
                <h3 class="text-lg font-semibold text-gray-900">Update Status for #<?php echo (int)$complaint_id; ?></h3>
                <button onclick="closeStatusModal()" class="text-gray-500 hover:text-gray-800"><i class="fas fa-times"></i></button>
            </div>
            <form id="statusForm" method="POST" action="update_status.php">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                <input type="hidden" name="complaint_id" value="<?php echo (int)$complaint_id; ?>">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">New Status</label>
                        <select name="status" class="w-full border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500" required>
                            <?php 
                            $statuses = ['Pending', 'In Progress', 'Resolved', 'Closed'];
                            foreach ($statuses as $s): ?>
                                <option value="<?php echo e($s); ?>" <?php echo $complaint['status'] === $s ? 'selected' : ''; ?>><?php echo e($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Action Due Date (Optional)</label>
                        <input type="date" name="action_due" value="<?php echo $complaint['action_due'] ? date('Y-m-d', strtotime($complaint['action_due'])) : ''; ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500">
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
                <a href="logout.php" class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors">
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

        // Modal functions
        function openAssignModal(id) {
            document.getElementById('assignModal').classList.add('show');
        }
        function closeAssignModal() { document.getElementById('assignModal').classList.remove('show'); }

        function openStatusModal(id) {
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