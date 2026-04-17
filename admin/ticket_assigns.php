<?php
include 'session_check.php';

// ---------------------------
// CSRF Token
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

// ---------------------------
// Fetch current logged-in staff info
// ---------------------------
$staff_id = $_SESSION['staff_id'];
$staff_query = "SELECT staff_id, name, profile_picture, email, role FROM staff WHERE staff_id = ?";
$stmt = mysqli_prepare($conn, $staff_query);
mysqli_stmt_bind_param($stmt, "i", $staff_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$staff = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$staff) {
    session_unset();
    session_destroy();
    header("Location: admin_login.php?message=Account not found.");
    exit();
}

// ---------------------------
// Handle POST - Assign Staff Only
// ---------------------------
$alerts = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $csrf_token) {

    if (isset($_POST['assign_complaint'])) {
        $complaint_id = (int)$_POST['complaint_id'];
        $assign_staff_id = (int)$_POST['staff_id'];

        if ($assign_staff_id > 0) {
            $assign_sql = "INSERT INTO complaint_assignments (complaint_id, staff_id, status) 
                           VALUES (?, ?, 'Assigned')
                           ON DUPLICATE KEY UPDATE 
                               staff_id = VALUES(staff_id), 
                               assigned_at = CURRENT_TIMESTAMP, 
                               status = 'Assigned'";

            $stmt_assign = mysqli_prepare($conn, $assign_sql);
            mysqli_stmt_bind_param($stmt_assign, "ii", $complaint_id, $assign_staff_id);
            
            if (mysqli_stmt_execute($stmt_assign)) {
                // Automatically set complaint to In Progress when assigned
                mysqli_query($conn, "UPDATE complaints SET status = 'In Progress', updated_at = CURRENT_TIMESTAMP WHERE complaint_id = $complaint_id");
                
                $alerts[] = ['type' => 'success', 'msg' => 'Complaint successfully assigned to staff!'];
            } else {
                $alerts[] = ['type' => 'error', 'msg' => 'Failed to assign complaint.'];
            }
            mysqli_stmt_close($stmt_assign);
        } else {
            $alerts[] = ['type' => 'error', 'msg' => 'Please select a staff member to assign.'];
        }
    }
}

// ---------------------------
// FILTERS
// ---------------------------
$filter_admin = isset($_GET['filter_admin']) ? (int)$_GET['filter_admin'] : 0;
$filter_complainer = isset($_GET['filter_complainer']) ? (int)$_GET['filter_complainer'] : 0;

$where = [];
$params = [];
$types = "";

if ($filter_admin > 0) {
    $where[] = "ca.staff_id = ?";
    $params[] = $filter_admin;
    $types .= "i";
}

if ($filter_complainer > 0) {
    $where[] = "c.user_id = ?";
    $params[] = $filter_complainer;
    $types .= "i";
}

$where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// ---------------------------
// Fetch All Complaints with Current Assignment + Filter
// ---------------------------
$complaints_query = "
    SELECT 
        c.complaint_id, 
        c.category, 
        c.description, 
        c.status, 
        c.created_at,
        CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.middle_name,''), ' ', COALESCE(u.last_name,'')) as user_name,
        u.id as user_id,
        s.name as assigned_staff_name,
        s.role as assigned_staff_role,
        s.staff_id as assigned_staff_id,
        ca.assigned_at
    FROM complaints c
    LEFT JOIN users u ON c.user_id = u.id
    LEFT JOIN complaint_assignments ca ON c.complaint_id = ca.complaint_id
    LEFT JOIN staff s ON ca.staff_id = s.staff_id
    $where_clause
    ORDER BY c.created_at DESC";

$stmt = mysqli_prepare($conn, $complaints_query);

if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$complaints_result = mysqli_stmt_get_result($stmt);
$complaints = [];
while ($row = mysqli_fetch_assoc($complaints_result)) {
    $complaints[] = $row;
}
mysqli_stmt_close($stmt);

// Get all staff for dropdown (Assign + Filter)
$staff_result = mysqli_query($conn, "SELECT staff_id, name, role FROM staff ORDER BY name ASC");
$all_staff = mysqli_fetch_all($staff_result, MYSQLI_ASSOC);

// Get all users who filed complaints for filter
$users_query = "
    SELECT DISTINCT u.id, 
           CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.middle_name,''), ' ', COALESCE(u.last_name,'')) as full_name 
    FROM users u 
    JOIN complaints c ON u.id = c.user_id 
    ORDER BY full_name ASC";
$users_result = mysqli_query($conn, $users_query);
$all_complainants = mysqli_fetch_all($users_result, MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Ticket Management | CWD AquaSense Admin</title>
    <link rel="icon" type="image/png" href="../assets/icons/AquaSense2.png" />
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <link rel="stylesheet" href="css/dashboard.css">
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
                <a href="ticket_assigns.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-blue-600 bg-blue-50 border border-blue-200 transition-all duration-200">
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
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01 .778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                    </svg>
                    View Feedback
                </a>
                <a href="announcements.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="announcement-icon mr-3">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 0 8.835-2.535m0 0A23.74 23.74 0 0 0 18.795 3m.38 1.125a23.91 23.91 0 0 1 1.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 0 0 1.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 0 1 0 3.46" />
                    </svg>
                    Announcement Section
                </a>
                <a href="audit_logs.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
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
        <!-- Header -->
        <header class="header-2025 sticky top-0 z-20 bg-white border-b">
            <div class="px-6 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-4"></div>
                    <div class="flex items-center space-x-4">
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
            <?php if (!empty($alerts)): ?>
                <div class="space-y-3">
                    <?php foreach ($alerts as $a): ?>
                        <div class="flex items-center p-4 rounded-xl <?php echo $a['type'] === 'success' ? 'bg-green-50 text-green-700 border border-green-100' : 'bg-red-50 text-red-700 border border-red-100'; ?> animate-fade-in">
                            <i class="<?php echo $a['type'] === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'; ?> mr-3 text-lg"></i>
                            <p class="text-sm font-semibold"><?php echo $a['msg']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900">Ticket Assigns</h2>
                    <p class="text-sm text-gray-500">Assign complaints to technical staff</p>
                </div>

                <!-- FILTERS -->
                <div class="flex flex-wrap gap-3">
                    <form method="GET" class="flex flex-wrap gap-3 items-end">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Assigned To (Staff/Admin)</label>
                            <select name="filter_admin" class="border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-blue-500 min-w-[240px]">
                                <option value="">All Staff</option>
                                <?php foreach ($all_staff as $s): ?>
                                    <option value="<?= $s['staff_id'] ?>" <?= ($filter_admin == $s['staff_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['role']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Filed By (Complainant)</label>
                            <select name="filter_complainer" class="border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-blue-500 min-w-[240px]">
                                <option value="">All Complainants</option>
                                <?php foreach ($all_complainants as $u): ?>
                                    <option value="<?= $u['id'] ?>" <?= ($filter_complainer == $u['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($u['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg text-sm font-medium transition flex items-center">
                            <i class="fas fa-filter mr-2"></i> Apply Filter
                        </button>

                        <a href="ticket_assigns.php" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-5 py-2.5 rounded-lg text-sm font-medium transition">
                            Clear Filters
                        </a>
                    </form>
                </div>

                <a href="manage_complaints.php" class="inline-flex items-center px-4 py-2 text-sm font-medium text-blue-600 bg-blue-50 rounded-lg hover:bg-blue-100 transition-all">
                    <i class="fa-solid fa-arrow-left mr-2"></i> Back to Complaints
                </a>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                <?php if (empty($complaints)): ?>
                    <div class="col-span-full bg-white rounded-2xl p-12 text-center border-2 border-dashed border-gray-200">
                        <img src="../assets/icons/empty-box.png" alt="No data" class="w-20 h-20 mx-auto mb-4 opacity-20">
                        <p class="text-gray-500 font-medium">No complaints found matching your filter.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($complaints as $comp): ?>
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 hover:shadow-md transition-shadow duration-300 overflow-hidden flex flex-col">
                            <!-- Card Header -->
                            <div class="p-5 border-b border-gray-50 flex justify-between items-start bg-gray-50/50">
                                <div>
                                    <h3 class="text-xl font-bold text-gray-900">Complaint #<?= $comp['complaint_id'] ?></h3>
                                </div>
                                <span class="px-3 py-1.5 text-xs font-bold rounded-lg shadow-sm
                                    <?= $comp['status']=='Resolved' ? 'bg-green-500 text-white' : 
                                       ($comp['status']=='In Progress' ? 'bg-blue-500 text-white' : 
                                       ($comp['status']=='Closed' ? 'bg-gray-500 text-white' : 'bg-amber-500 text-white')) ?>">
                                    <?= htmlspecialchars($comp['status']) ?>
                                </span>
                            </div>

                            <!-- Card Body -->
                            <div class="p-5 flex-1 grid grid-cols-2 gap-4">
                                <div class="space-y-1">
                                    <p class="text-xs text-gray-400 font-medium uppercase">Category</p>
                                    <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($comp['category']) ?></p>
                                </div>
                                <div class="space-y-1">
                                    <p class="text-xs text-gray-400 font-medium uppercase">Filed By</p>
                                    <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($comp['user_name'] ?: 'Anonymous') ?></p>
                                </div>
                                <div class="space-y-1">
                                    <p class="text-xs text-gray-400 font-medium uppercase">Date Reported</p>
                                    <p class="text-sm text-gray-600"><?= date('M j, Y • g:i A', strtotime($comp['created_at'])) ?></p>
                                </div>
                                <div class="space-y-1">
                                    <p class="text-xs text-gray-400 font-medium uppercase">Assigned To</p>
                                    <?php if ($comp['assigned_staff_name']): ?>
                                        <div class="flex items-center gap-2">
                                            <div class="w-2 h-2 rounded-full bg-green-500"></div>
                                            <p class="text-sm font-bold text-gray-800"><?= htmlspecialchars($comp['assigned_staff_name']) ?></p>
                                        </div>
                                        <p class="text-xs text-gray-500"><?= htmlspecialchars($comp['assigned_staff_role']) ?></p>
                                    <?php else: ?>
                                        <div class="flex items-center gap-2 text-red-500">
                                            <i class="fa-solid fa-circle-exclamation text-[10px]"></i>
                                            <p class="text-sm font-bold">Unassigned</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Assign Staff Section -->
                            <div class="p-4 bg-gray-50 border-t border-gray-100 mt-auto">
                                <form method="POST" class="flex flex-col sm:flex-row gap-3">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <input type="hidden" name="complaint_id" value="<?= $comp['complaint_id'] ?>">

                                    <div class="flex-1">
                                        <select name="staff_id" class="w-full text-sm bg-white border border-gray-200 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 outline-none transition-all">
                                            <option value="">Choose Staff...</option>
                                            <?php foreach ($all_staff as $s): ?>
                                                <option value="<?= $s['staff_id'] ?>" 
                                                    <?= ($comp['assigned_staff_id'] == $s['staff_id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['role']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <button type="submit" name="assign_complaint" 
                                            class="bg-blue-600 text-white px-6 py-3 rounded-lg text-sm font-bold hover:bg-blue-700 transition shadow-sm active:scale-95 whitespace-nowrap">
                                        Assign Staff
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- Mobile Menu Toggle -->
<button id="mobileMenuToggle" class="fixed top-4 left-4 z-40 p-2 rounded-lg text-gray-600 bg-white shadow-lg md:hidden">
    <i class="fas fa-bars text-lg"></i>
</button>

<!-- Profile Dropdown Menu -->
<div id="profileDropdownMenu" class="hidden absolute right-6 top-20 w-48 bg-white rounded-xl shadow-lg border border-gray-100 py-2 z-50"></div>

<script>
// Mobile menu toggle
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

// Profile Dropdown
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
// Cleanup
mysqli_close($conn);
?>