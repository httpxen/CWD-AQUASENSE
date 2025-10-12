<?php
include '../db/db.php';
session_start();

/**
 * complaints.php
 * Customer-facing ticketing page for CWD AquaSense
 * - Create complaint (category + description + attachment)
 * - List with filters, search, pagination
 * - CSV export
 * - KPIs (totals + avg resolution time)
 * Security: prepared statements, CSRF token, HTML escaping
 */

// -------- Session guard --------
$timeout_duration = 1800;
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php?message=Please log in to access complaints.");
    exit();
}
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: ../login.php?message=Session expired, please log in again.");
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

// -------- Fetch logged-in user --------
$user_id = $_SESSION['user_id'];
$user_query = "SELECT first_name, last_name, username, email, profile_picture FROM users WHERE id = ?";
$stmt = mysqli_prepare($conn, $user_query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$user_result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($user_result);

// -------- CSRF helpers --------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_check($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// -------- Utility: safe output --------
function e($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }

// -------- Constants --------
$ALLOWED_CATEGORIES = [
    'Billing',
    'Water Quality',
    'Service Interruption',
    'Meter / Leakage',
    'New Connection / Disconnection',
    'Customer Service',
    'Others'
];
$ALLOWED_STATUSES = ['Pending','In Progress','Resolved','Closed'];

// -------- Handle Create Complaint (POST) --------
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_check($token)) {
        $flash = ['type' => 'error', 'msg' => 'Invalid session token. Please refresh and try again.'];
    } else {
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $attachment_path = null;

        if (!in_array($category, $ALLOWED_CATEGORIES, true)) {
            $flash = ['type' => 'error', 'msg' => 'Please choose a valid category.'];
        } elseif (strlen($description) < 10) {
            $flash = ['type' => 'error', 'msg' => 'Description must be at least 10 characters.'];
        } else {
            // Handle file upload
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../Uploads/complaints/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $file_name = uniqid() . '_' . basename($_FILES['attachment']['name']);
                $file_path = $upload_dir . $file_name;
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                $max_size = 5 * 1024 * 1024; // 5MB
                if (in_array($_FILES['attachment']['type'], $allowed_types) && $_FILES['attachment']['size'] <= $max_size) {
                    if (move_uploaded_file($_FILES['attachment']['tmp_name'], $file_path)) {
                        $attachment_path = $file_name;
                    } else {
                        $flash = ['type' => 'error', 'msg' => 'Failed to upload attachment. Please try again.'];
                    }
                } else {
                    $flash = ['type' => 'error', 'msg' => 'Invalid file type or size. Allowed: JPG, PNG, GIF, PDF, DOC, DOCX (max 5MB).'];
                }
            }

            if (!$flash) {
                $sql = "INSERT INTO complaints (user_id, category, description, status, action_due, attachment_path) VALUES (?, ?, ?, 'Pending', NULL, ?)";
                $ins = mysqli_prepare($conn, $sql);
                mysqli_stmt_bind_param($ins, "isss", $user_id, $category, $description, $attachment_path);
                if (mysqli_stmt_execute($ins)) {
                    $flash = ['type' => 'success', 'msg' => 'Your complaint has been submitted. You can track it below.'];
                } else {
                    $flash = ['type' => 'error', 'msg' => 'Unable to save your complaint. Please try again.'];
                    if ($attachment_path) {
                        unlink($file_path);
                    }
                }
                mysqli_stmt_close($ins);
            }
        }
    }
}

// -------- Handle CSV Export --------
if (isset($_GET['export']) && $_GET['export'] === '1') {
    $status = $_GET['status'] ?? '';
    $category = $_GET['category'] ?? '';
    $q = trim($_GET['q'] ?? '');

    $clauses = ["c.user_id = ?"];
    $params = [$user_id];
    $types = "i";

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
        $clauses[] = "c.description LIKE ?";
        $params[] = "%$q%";
        $types .= "s";
    }

    $where = "WHERE ".implode(" AND ", $clauses);
    $sql = "
      SELECT c.complaint_id, c.category, c.description, c.status, c.sentiment, c.action_due, c.created_at, c.updated_at, c.attachment_path,
             s.name AS staff_name
      FROM complaints c
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
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=complaints_export_'.date('Ymd_His').'.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Complaint ID','Category','Description','Status','Sentiment','Action Due','Attachment','Assigned Staff','Created At','Updated At']);
    while ($row = mysqli_fetch_assoc($res)) {
        fputcsv($out, [
            $row['complaint_id'],
            $row['category'],
            $row['description'],
            $row['status'],
            $row['sentiment'] ?? '',
            $row['action_due'] ?? '',
            $row['attachment_path'] ?? '',
            $row['staff_name'] ?? '',
            $row['created_at'],
            $row['updated_at']
        ]);
    }
    fclose($out);
    mysqli_stmt_close($stmt);
    exit;
}

// -------- Filters, search, pagination --------
$status = $_GET['status'] ?? '';
$category = $_GET['category'] ?? '';
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Build WHERE
$clauses = ["c.user_id = ?"];
$params = [$user_id];
$types = "i";

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
    $clauses[] = "c.description LIKE ?";
    $params[] = "%$q%";
    $types .= "s";
}
$where = "WHERE ".implode(" AND ", $clauses);

// Count total for pagination
$count_sql = "SELECT COUNT(*) AS cnt FROM complaints c $where";
$count_stmt = mysqli_prepare($conn, $count_sql);
mysqli_stmt_bind_param($count_stmt, $types, ...$params);
mysqli_stmt_execute($count_stmt);
$count_res = mysqli_stmt_get_result($count_stmt);
$total_rows = (int)mysqli_fetch_assoc($count_res)['cnt'];
mysqli_stmt_close($count_stmt);
$total_pages = max(1, (int)ceil($total_rows / $per_page));

// Fetch complaints list
$list_sql = "
  SELECT c.complaint_id, c.category, c.description, c.status, c.sentiment, c.action_due, c.created_at, c.updated_at, c.attachment_path,
         s.name AS staff_name, s.role AS staff_role, s.profile_picture AS staff_profile_picture
  FROM complaints c
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complaints | CWD AquaSense</title>
    <link rel="icon" type="image/png" href="../assets/icons/AquaSense2.png">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/complaints.css">
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
                            <p class="text-xs text-gray-500">Customer Portal</p>
                        </div>
                    </div>
                </div>
                <!-- Navigation -->
                <nav class="flex-1 py-2 px-4 space-y-2">
                    <a href="dashboard.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                        </svg>
                        Dashboard
                    </a>
                    <a href="complaints.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-blue-600 bg-blue-50 border border-blue-200 transition duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        My Complaints
                    </a>
                    <a href="feedback.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 0 1 .778-.332 48.294 48.294 0 0 0 5.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                        </svg>
                        Give Feedback
                    </a>
                    <a href="chatbot.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
                        </svg>
                        Chatbot
                    </a>
                </nav>
                <!-- User Info & Logout -->
                <div class="p-4 border-t border-gray-100">
                    <div class="flex items-center space-x-3 mb-4">
                        <div class="relative avatar-glow">
                            <img src="<?php echo e($user['profile_picture'] ?: 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode(($user['first_name']??'').' '.($user['last_name']??''))); ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover"/>
                            <div class="absolute -bottom-0.5 -right-0.5 w-4 h-4 bg-green-500 border-2 border-white rounded-full animate-gentle-pulse"></div>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900"><?php echo e(($user['first_name']??'').' '.($user['last_name']??'')); ?></p>
                            <p class="text-xs text-gray-500">@<?php echo e($user['username']??''); ?></p>
                        </div>
                    </div>
                    <a href="../logout.php" class="w-full flex items-center justify-center px-4 py-2 text-sm font-medium text-red-600 bg-red-50 rounded-lg hover:bg-red-100 transition-colors">
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
                                    <img src="<?php echo e($user['profile_picture'] ?: 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode(($user['first_name']??'').' '.($user['last_name']??''))); ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover"/>
                                    <div class="absolute -bottom-0.5 -right-0.5 w-4 h-4 bg-green-500 border-2 border-white rounded-full animate-gentle-pulse"></div>
                                </div>
                                <div class="hidden md:block">
                                    <p class="text-sm font-semibold text-gray-900 truncate max-w-32"><?php echo e($user['first_name']??''); ?></p>
                                    <p class="text-xs text-gray-500 truncate max-w-32">@<?php echo e($user['username']??''); ?></p>
                                </div>
                                <i class="fas fa-chevron-down text-gray-400 text-sm ml-1 transition-transform duration-200 group-hover:text-gray-600"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </header>
            <!-- Main Dashboard Content -->
            <main class="p-4 space-y-4">
                <?php if ($flash): ?>
                    <div class="rounded-xl p-4 <?php echo $flash['type']==='success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
                        <i class="fas <?php echo $flash['type']==='success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> mr-2"></i>
                        <?php echo e($flash['msg']); ?>
                    </div>
                <?php endif; ?>
                <!-- Tabs Section -->
                <section class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
                    <!-- Tab Headers -->
                    <div class="border-b border-gray-200">
                        <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                            <button id="submitTab" class="tab-btn border-blue-500 text-blue-600 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm relative" data-tab="submit" aria-selected="true">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-1 inline">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 13.5 3 3m0 0 3-3m-3 3v-6m1.06-4.19-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" />
                                </svg>
                                Submit New
                            </button>
                            <button id="viewTab" class="tab-btn border-transparent text-gray-500 hover:text-gray-700 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm relative" data-tab="view" aria-selected="false">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-1 inline">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M9 16.5v.75m3-3v3M15 12v5.25m-4.5-15H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                                </svg>
                                View Complaints (<?php echo (int)$total_rows; ?>)
                            </button>
                        </nav>
                    </div>
                    <!-- Submit Tab Content -->
                    <div id="submitTabContent" class="p-4">
                        <?php if ($total_rows === 0): ?>
                        <?php endif; ?>
                        <?php include 'includes/submit-complaint.php'; ?>
                    </div>
                    <!-- View Tab Content -->
                    <div id="viewTabContent" class="p-5 hidden">
                        <?php include 'includes/complaints.php'; ?>
                    </div>
                </section>
            </main>
        </div>
    </div>
    <!-- Mobile Menu Toggle -->
    <button id="mobileMenuToggle" class="fixed top-4 left-4 z-40 p-2 rounded-lg text-gray-600 bg-white shadow-lg md:hidden">
        <i class="fas fa-bars text-lg"></i>
    </button>
    <!-- Profile Dropdown -->
    <div id="profileDropdownMenu" class="hidden absolute right-6 top-20 w-48 bg-white rounded-xl shadow-lg border border-gray-100 py-2 z-30"></div>
    <!-- Track Modal -->
    <div id="trackModal" class="modal">
        <div class="bg-white w-11/12 max-w-xl rounded-2xl p-5 shadow-2xl">
            <div class="flex items-center justify-between mb-2">
                <h3 class="text-base font-semibold text-gray-900">Track Complaint</h3>
                <button onclick="closeTrackModal()" class="text-gray-500 hover:text-gray-800"><i class="fas fa-times"></i></button>
            </div>
            <div id="trackBody" class="space-y-2 text-sm text-gray-800">
                <!-- Filled by JS -->
            </div>
            <div class="mt-4 text-right">
                <button onclick="closeTrackModal()" class="px-4 py-2 rounded-xl border border-gray-200 text-gray-700 hover:bg-gray-50">Close</button>
            </div>
        </div>
    </div>
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('-translate-x-full');
            sidebar.classList.toggle('translate-x-0');
        });
        // Sidebar collapse on mobile
        if (window.innerWidth < 768) {
            document.querySelector('.sidebar').classList.add('-translate-x-full');
        }
        // Responsive sidebar
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
        // Profile dropdown functionality
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
                <a href="../logout.php" class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 text-red-600">
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
        document.addEventListener('click', function() { hideProfileDropdown(); });
        // Notification (placeholder)
        document.getElementById('notificationBtn').addEventListener('click', function(e) {
            e.stopPropagation();
            this.style.transform = 'scale(0.95)';
            setTimeout(() => { this.style.transform = 'scale(1)'; }, 150);
            alert('Notifications feature coming soon! 🔔');
        });
        // Track modal
        function openTrackModal(id, data) {
            const modal = document.getElementById('trackModal');
            const body = document.getElementById('trackBody');
            let attachmentHtml = '';
            if (data.attachment_path) {
                attachmentHtml = `
                    <div class="mt-3">
                        <span class="text-gray-500">Attachment</span>
                        <div class="text-gray-900 font-medium">
                            <a href="../Uploads/complaints/${escapeHtml(data.attachment_path)}" target="_blank" class="text-blue-600 hover:underline">
                                <i class="fas fa-download mr-1"></i>View
                            </a>
                        </div>
                    </div>
                `;
            }
            body.innerHTML = `
                <div class="grid grid-cols-2 gap-3">
                    <div><span class="text-gray-500">Ticket #</span><div class="font-semibold text-gray-900">#${escapeHtml(String(data.id))}</div></div>
                    <div><span class="text-gray-500">Status</span><div class="font-semibold">${escapeHtml(data.status || '—')}</div></div>
                    <div><span class="text-gray-500">Category</span><div class="font-semibold">${escapeHtml(data.category || '—')}</div></div>
                    <div><span class="text-gray-500">Assigned</span><div class="font-semibold">${escapeHtml(data.assigned || '—')}</div></div>
                    <div><span class="text-gray-500">Action Due</span><div class="font-medium">${escapeHtml(data.action_due || '—')}</div></div>
                    <div><span class="text-gray-500">Created</span><div class="font-medium">${escapeHtml(data.created_at || '—')}</div></div>
                </div>
                <div class="mt-3">
                    <span class="text-gray-500">Description</span>
                    <div class="text-gray-900 whitespace-pre-wrap border border-gray-100 rounded-xl p-3 bg-gray-50">${escapeHtml(data.description || '')}</div>
                </div>
                <div class="mt-3">
                    <span class="text-gray-500">Sentiment</span>
                    <div class="text-gray-900 font-medium">${escapeHtml(data.sentiment || 'N/A')}</div>
                </div>
                ${attachmentHtml}
            `;
            modal.classList.add('show');
        }
        function closeTrackModal() { document.getElementById('trackModal').classList.remove('show'); }
        function escapeHtml(s) {
            return String(s)
              .replaceAll('&','&amp;')
              .replaceAll('<','&lt;')
              .replaceAll('>','&gt;')
              .replaceAll('"','&quot;')
              .replaceAll("'","&#039;");
        }
        // Tab switching
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const tab = this.dataset.tab;
                // Close mobile sidebar if open
                const sidebar = document.querySelector('.sidebar');
                if (window.innerWidth < 768) {
                    sidebar.classList.add('-translate-x-full');
                    sidebar.classList.remove('translate-x-0');
                }
                // Update buttons
                document.querySelectorAll('.tab-btn').forEach(b => {
                    b.setAttribute('aria-selected', 'false');
                    b.classList.remove('border-blue-500', 'text-blue-600');
                    b.classList.add('border-transparent', 'text-gray-500');
                });
                this.setAttribute('aria-selected', 'true');
                this.classList.add('border-blue-500', 'text-blue-600');
                this.classList.remove('border-transparent', 'text-gray-500');
                // Switch content
                document.querySelectorAll('[id$="TabContent"]').forEach(content => {
                    content.classList.add('hidden');
                });
                const targetContent = document.getElementById(tab + 'TabContent');
                if (targetContent) {
                    targetContent.classList.remove('hidden');
                } else {
                    console.error(`Tab content for ${tab} not found`);
                }
            });
        });
    </script>
</body>
</html>
<?php
// Clean up
mysqli_stmt_close($stmt);
mysqli_close($conn);
?>