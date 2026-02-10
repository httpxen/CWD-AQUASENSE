<?php
date_default_timezone_set('Asia/Manila'); // Set PHP default to Manila
include 'session_check.php'; // Handles DB connection ($conn), session validation, etc.
// === CRITICAL FIX: FORCE MYSQL TO USE MANILA TIME (+08:00) ===
if (isset($conn)) {
    mysqli_query($conn, "SET time_zone = '+08:00'");
}
function formatLocalDate($db_time, $format = 'M d, Y h:i A') {
    if (empty($db_time)) {
        return null;
    }
    if ($db_time === 'now') {
        $dt = new DateTime('now');
    } else {
        $dt = new DateTime($db_time);
    }
    return $dt->format($format);
}
// Get staff_id from session (assuming set during login)
$staff_id = $_SESSION['staff_id'] ?? null;
if (!$staff_id) {
    header("Location: ../admin_login.php?message=Please log in as admin.");
    exit();
}
// Fetch staff details early for role-based permissions
$staff_query = "SELECT name, profile_picture, role FROM staff WHERE staff_id = ?";
$stmt = mysqli_prepare($conn, $staff_query);
mysqli_stmt_bind_param($stmt, "i", $staff_id);
mysqli_stmt_execute($stmt);
$staff_result = mysqli_stmt_get_result($stmt);
$staff = mysqli_fetch_assoc($staff_result);
mysqli_stmt_close($stmt);
if (!$staff || !in_array($staff['role'], ['Admin', 'SuperAdmin'])) {
    header("Location: ../admin_login.php?message=Access denied.");
    exit();
}
// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
// Helpers
function e($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }
function get_avatar_src($profile_picture, $name) {
    if ($profile_picture) return '../' . $profile_picture;
    return 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($name);
}
// Constants
$ALLOWED_CATEGORIES = ['Billing','Water Quality','Service Interruption','Meter / Leakage','New Connection / Disconnection','Customer Service','Others'];
$ALLOWED_STATUSES = ['Pending', 'In Progress', 'Resolved', 'Closed'];
// === PDF EXPORT WITH MODAL ===
if (isset($_POST['export_pdf'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }
    $type = $_POST['date_type'] ?? '';
    $month = $_POST['month'] ?? '';
    $year = $_POST['year'] ?? '';
    $from = $_POST['from'] ?? '';
    $to = $_POST['to'] ?? '';
    $clauses = [];
    $params = [];
    $types = '';
    // Date Logic
    if ($type === 'month' && $month && $year) {
        $from = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
        $to = date('Y-m-t', strtotime($from));
        $clauses[] = "DATE(c.created_at) >= ? AND DATE(c.created_at) <= ?";
        $params[] = $from;
        $params[] = $to;
        $types .= "ss";
    } elseif ($type === 'range' && $from && $to && $from <= $to) {
        $clauses[] = "DATE(c.created_at) >= ? AND DATE(c.created_at) <= ?";
        $params[] = $from;
        $params[] = $to;
        $types .= "ss";
    } else {
        die("Invalid date range.");
    }
    $status = isset($_POST['status']) ? $_POST['status'] : '';
    $category = isset($_POST['category']) ? $_POST['category'] : '';
    $q = isset($_POST['q']) ? trim($_POST['q']) : '';
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
        $search_conditions = [];
        if (is_numeric($q)) {
            $search_conditions[] = "c.complaint_id = ?";
            $params[] = (int)$q;
            $types .= "i";
        }
        $search_conditions[] = "CONCAT(u.first_name, ' ', u.last_name) LIKE ?";
        $params[] = "%$q%";
        $types .= "s";
        $clauses[] = "(" . implode(" OR ", $search_conditions) . ")";
    }
    $where = $clauses ? "WHERE " . implode(" AND ", $clauses) : '';
    $sql = "
        SELECT c.complaint_id, c.category, c.description, c.status, c.action_due, c.created_at, c.updated_at, c.attachment_path, c.resolved_at,
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
        ORDER BY c.created_at DESC
    ";
    $stmt = mysqli_prepare($conn, $sql);
    if ($types) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    // PDF Generation
    require_once '../vendor/tecnickcom/tcpdf/tcpdf.php';
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('CWD AquaSense');
    $pdf->SetTitle('Complaints Report');
    $pdf->SetSubject('Complaints Export');
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(TRUE, 10);
    $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
    $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(10);
    $pdf->AddPage();
    // Title
    $pdf->SetFont('helvetica', 'B', 16);
    $title = 'Complaints Report ' . formatLocalDate('now', 'Y');
    $pdf->SetY(15);
    $pdf->Cell(0, 10, $title, 0, 1, 'C');
    $pdf->Ln(8);
    // Filters Info
    $pdf->SetFont('helvetica', '', 10);
    $filter_text = "Exported on: " . formatLocalDate('now') . "\n";
    if (isset($status) && $status) $filter_text .= "Status: $status\n";
    if (isset($category) && $category) $filter_text .= "Category: $category\n";
    if (isset($q) && $q) $filter_text .= "Search: $q\n";
    $filter_text .= "Total Records: " . count($rows);
    $pdf->MultiCell(0, 10, $filter_text, 0, 'L');
    $pdf->Ln(5);
    // Table Header
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(59, 130, 246);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetLineWidth(0.2);
    $header = ['ID', 'Category', 'Status', 'User', 'Assigned', 'Created', 'Due'];
    $widths = [15, 30, 25, 40, 30, 25, 25];
    $h = 8;
    foreach ($header as $i => $col) {
        $pdf->Cell($widths[$i], $h, $col, 1, 0, 'C', true);
    }
    $pdf->Ln();
    // Table Data
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(249, 250, 252);
    $fill = false;
    $h = 7;
    foreach ($rows as $row) {
        $pdf->Cell($widths[0], $h, $row['complaint_id'], 1, 0, 'C', $fill);
        $pdf->Cell($widths[1], $h, substr($row['category'] ?? '', 0, 18), 1, 0, 'L', $fill);
        $pdf->Cell($widths[2], $h, $row['status'] ?? '', 1, 0, 'C', $fill);
        $pdf->Cell($widths[3], $h, substr($row['user_name'] ?? 'N/A', 0, 25), 1, 0, 'L', $fill);
        $pdf->Cell($widths[4], $h, substr($row['staff_name'] ?? 'Unassigned', 0, 18), 1, 0, 'L', $fill);
        $pdf->Cell($widths[5], $h, formatLocalDate($row['created_at'] ?? 'now', 'M d'), 1, 0, 'C', $fill);
        $due = (!empty($row['action_due']) ? formatLocalDate($row['action_due'] . ' 00:00:00', 'M d') : '-');
        $pdf->Cell($widths[6], $h, $due, 1, 0, 'C', $fill);
        $pdf->Ln();
        $fill = !$fill;
    }
    $filename = 'complaints_report_' . formatLocalDate('now', 'Y') . '.pdf';
    $pdf->Output($filename, 'D');
    exit;
}
// === LIST VIEW ===
$status = isset($_GET['status']) ? $_GET['status'] : '';
$category = isset($_GET['category']) ? $_GET['category'] : '';
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$per_page = 10;
$offset = ($page - 1) * $per_page;
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
    $search_conditions = [];
    if (is_numeric($q)) {
        $search_conditions[] = "c.complaint_id = ?";
        $params[] = (int)$q;
        $types .= "i";
    }
    $search_conditions[] = "CONCAT(u.first_name, ' ', u.last_name) LIKE ?";
    $params[] = "%$q%";
    $types .= "s";
    $clauses[] = "(" . implode(" OR ", $search_conditions) . ")";
}
$where = $clauses ? "WHERE " . implode(" AND ", $clauses) : '';
$count_sql = "SELECT COUNT(*) AS cnt FROM complaints c LEFT JOIN users u ON c.user_id = u.id $where";
$count_stmt = mysqli_prepare($conn, $count_sql);
if ($types) mysqli_stmt_bind_param($count_stmt, $types, ...$params);
mysqli_stmt_execute($count_stmt);
$count_res = mysqli_stmt_get_result($count_stmt);
$total_rows = (int)mysqli_fetch_assoc($count_res)['cnt'];
mysqli_stmt_close($count_stmt);
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$list_sql = "
    SELECT c.complaint_id, c.category, c.description, c.status, c.action_due, c.created_at, c.updated_at, c.attachment_path, c.resolved_at, c.sentiment, c.location_address, c.location_lat, c.location_lng,
           CONCAT(u.first_name, ' ', u.last_name) AS user_name, u.email AS user_email, u.profile_picture AS user_profile_picture,
           s.name AS staff_name, s.role AS staff_role, s.profile_picture AS staff_profile_picture
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
    ORDER BY c.created_at DESC
    LIMIT ? OFFSET ?
";
$list_stmt = mysqli_prepare($conn, $list_sql);
$types_paged = $types . "ii";
$params_paged = array_merge($params, [$per_page, $offset]);
mysqli_stmt_bind_param($list_stmt, $types_paged, ...$params_paged);
mysqli_stmt_execute($list_stmt);
$list_res = mysqli_stmt_get_result($list_stmt);
// Use ALLOWED_CATEGORIES for filters (no need for unique from current page)
// Fetch comments
$complaint_ids = [];
mysqli_data_seek($list_res, 0);
while ($row = mysqli_fetch_assoc($list_res)) {
    $complaint_ids[] = $row['complaint_id'];
}
mysqli_data_seek($list_res, 0);
$comments = [];
if (!empty($complaint_ids)) {
    $ids_placeholder = str_repeat('?,', count($complaint_ids) - 1) . '?';
    $comments_sql = "
        SELECT cc.complaint_id, cc.comment_id AS id, cc.comment AS comment_text, cc.created_at, cc.commenter_type, cc.commenter_id,
               CASE
                   WHEN cc.commenter_type = 'staff' THEN CONCAT(s.name, ' (Staff)')
                   WHEN cc.commenter_type = 'user' THEN CONCAT(u.first_name, ' ', u.last_name, ' (Customer)')
               END AS commenter_name,
               s.profile_picture AS staff_profile_picture,
               s.role AS staff_role,
               u.profile_picture AS user_profile_picture
        FROM complaint_comments cc
        LEFT JOIN staff s ON cc.commenter_type = 'staff' AND cc.commenter_id = s.staff_id
        LEFT JOIN users u ON cc.commenter_type = 'user' AND cc.commenter_id = u.id
        WHERE cc.complaint_id IN ($ids_placeholder)
        ORDER BY cc.created_at ASC
    ";
    $comments_stmt = mysqli_prepare($conn, $comments_sql);
    mysqli_stmt_bind_param($comments_stmt, str_repeat('i', count($complaint_ids)), ...$complaint_ids);
    mysqli_stmt_execute($comments_stmt);
    $comments_res = mysqli_stmt_get_result($comments_stmt);
    while ($comment_row = mysqli_fetch_assoc($comments_res)) {
        $comments[$comment_row['complaint_id']][] = $comment_row;
    }
    mysqli_stmt_close($comments_stmt);
}
$staff_sql = "SELECT staff_id, name FROM staff ORDER BY name";
$staff_res = mysqli_query($conn, $staff_sql);
$staff_list = [];
while ($row = mysqli_fetch_assoc($staff_res)) {
    $staff_list[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Complaints | CWD AquaSense SuperAdmin</title>
    <link rel="icon" type="image/png" href="../assets/icons/AquaSense2.png">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="css/manage_complaints.css">
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex">
        <div class="sidebar w-64 bg-white shadow-lg fixed h-full z-30">
            <div class="flex flex-col h-full">
                <div class="p-6">
                    <div class="flex items-center space-x-3">
                        <img src="../assets/icons/AquaSense.png" alt="Logo" class="w-16 h-16 rounded-lg object-contain bg-white p-1">
                        <div class="flex-1">
                            <h1 class="text-xl font-bold text-gray-900">AquaSense</h1>
                            <p class="text-xs text-gray-500">SuperAdmin Portal</p>
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
                    <a href="manage_user.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="feedback-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                        </svg>
                        Manage Users
                    </a>
                    <a href="view_feedback.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="feedback-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01 .778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
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
        <div class="flex-1">
            <header class="header-2025 sticky top-0 z-20">
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4"></div>
                        <div class="flex items-center space-x-4">
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
            <div id="profileDropdownMenu" class="hidden absolute right-6 top-20 w-48 bg-white rounded-xl shadow-lg border border-gray-100 py-2 z-30">
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
            </div>
            <main class="p-4 space-y-6">
                <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-4 sm:p-6">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <form method="GET" action="manage_complaints.php" id="filterForm" class="flex flex-wrap items-center gap-2 flex-1">
                            <input type="hidden" name="page" value="1">
                            <div class="relative flex-1 max-w-md">
                                <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                                <input type="text" name="q" id="globalSearch" placeholder="Search by Complaint # or Customer Name..." class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-200" value="<?php echo e($q); ?>">
                            </div>
                            <div class="relative">
                                <select name="status" id="statusFilter" class="block appearance-none w-full bg-white border border-gray-300 hover:border-gray-400 px-4 py-2 pr-8 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" onchange="this.form.submit()">
                                    <option value="">All Status</option>
                                    <?php foreach ($ALLOWED_STATUSES as $status_option): ?>
                                        <option value="<?php echo e($status_option); ?>" <?php echo $status_option === $status ? 'selected' : ''; ?>><?php echo e($status_option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700">
                                    <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                        <path d="M9.293 12.95l.707.707L15.657 8l-1.414-1.414L10 10.828 5.757 6.586 4.343 8z" />
                                    </svg>
                                </div>
                            </div>
                            <div class="relative">
                                <select name="category" id="categoryFilter" class="block appearance-none w-full bg-white border border-gray-300 hover:border-gray-400 px-4 py-2 pr-8 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" onchange="this.form.submit()">
                                    <option value="">All Categories</option>
                                    <?php foreach ($ALLOWED_CATEGORIES as $cat): ?>
                                        <option value="<?php echo e($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>><?php echo e($cat); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700">
                                    <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                        <path d="M9.293 12.95l.707.707L15.657 8l-1.414-1.414L10 10.828 5.757 6.586 4.343 8z" />
                                    </svg>
                                </div>
                            </div>
                            <button type="submit" class="px-4 py-2 text-sm font-medium text-blue-600 bg-blue-50 hover:bg-blue-100 rounded-lg transition duration-200 border border-blue-200">Apply</button>
                        </form>
                        <div class="flex flex-wrap gap-2 items-center">
                            <button type="button" id="clearFilters" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition duration-200">Clear</button>
                            <button onclick="openExportModal()" class="px-4 py-2 text-sm font-medium text-blue-600 bg-blue-50 hover:bg-blue-100 rounded-lg transition duration-200 border border-blue-200">Export PDF</button>
                        </div>
                    </div>
                </div>
                <?php if ($total_rows === 0): ?>
                    <div class="text-center py-12 text-gray-500 bg-white rounded-lg border border-gray-200">
                        No complaints yet.
                    </div>
                <?php else: ?>
                    <div id="complaintsContainer" class="space-y-4">
                        <?php while ($row = mysqli_fetch_assoc($list_res)): ?>
                            <?php
                            $current_status = $row['status'] ?? 'Pending';
                            $sentiment = $row['sentiment'] ?? '';
                    
                            // Fetch assignments for history timeline
                            $assign_sql = "
                                SELECT ca.id, ca.assigned_at, ca.status AS assignment_status, s.name AS staff_name,
                                       s.role AS staff_role, s.profile_picture AS staff_profile_picture
                                FROM complaint_assignments ca
                                LEFT JOIN staff s ON s.staff_id = ca.staff_id
                                WHERE ca.complaint_id = ?
                                ORDER BY ca.assigned_at ASC
                            ";
                            $assign_stmt = mysqli_prepare($conn, $assign_sql);
                            mysqli_stmt_bind_param($assign_stmt, "i", $row['complaint_id']);
                            mysqli_stmt_execute($assign_stmt);
                            $assign_res = mysqli_stmt_get_result($assign_stmt);
                            $assignments = [];
                            while ($assign = mysqli_fetch_assoc($assign_res)) {
                                $assignments[] = $assign;
                            }
                            mysqli_stmt_close($assign_stmt);
                            // Status badge
                            $status_badge = 'bg-gray-100 text-gray-700';
                            if ($current_status === 'Pending') $status_badge = 'bg-yellow-50 text-yellow-700 border border-yellow-200';
                            if ($current_status === 'In Progress') $status_badge = 'bg-blue-50 text-blue-700 border border-blue-200';
                            if ($current_status === 'Resolved') $status_badge = 'bg-green-50 text-green-700 border border-green-200';
                            if ($current_status === 'Closed') $status_badge = 'bg-gray-100 text-gray-700 border border-gray-200';
                            // Sentiment badge
                            $sentiment_badge = 'bg-gray-50 text-gray-600 border border-gray-200';
                            if ($sentiment === 'Positive') $sentiment_badge = 'bg-green-50 text-green-700 border border-green-200';
                            if ($sentiment === 'Negative') $sentiment_badge = 'bg-red-50 text-red-700 border border-red-200';
                    
                            $sentiment_icon = '';
                            if ($sentiment === 'Positive') {
                                $sentiment_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 flex-shrink-0">
                                                        <path fill-rule="evenodd" d="M8.603 3.799A4.49 4.49 0 0 1 12 2.25c1.357 0 2.573.6 3.397 1.549a4.49 4.49 0 0 1 3.498 1.307 4.491 4.491 0 0 1 1.307 3.497A4.49 4.49 0 0 1 21.75 12a4.49 4.49 0 0 1-1.549 3.397 4.491 4.491 0 0 1-1.307 3.497 4.491 4.491 0 0 1-3.497 1.307A4.49 4.49 0 0 1 12 21.75a4.49 4.49 0 0 1-3.397-1.549 4.49 4.49 0 0 1-3.498-1.306 4.491 4.491 0 0 1-1.307-3.498A4.49 4.49 0 0 1 2.25 12c0-1.357.6-2.573 1.549-3.397a4.49 4.49 0 0 1 1.307-3.497 4.49 4.49 0 0 1 3.497-1.307Zm7.007 6.387a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z" clip-rule="evenodd" />
                                                   </svg>';
                            } elseif ($sentiment === 'Negative') {
                                $sentiment_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 flex-shrink-0">
                                                        <path fill-rule="evenodd" d="M5.47 5.47a.75.75 0 011.06 0L12 10.94l5.47-5.47a.75.75 0 111.06 1.06L13.06 12l5.47 5.47a.75.75 0 11-1.06 1.06L12 13.06l-5.47 5.47a.75.75 0 01-1.06-1.06L10.94 12 5.47 6.53a.75.75 0 010-1.06z" clip-rule="evenodd" />
                                                   </svg>';
                            }
                            // Customer display
                            $customer_display = '
                            <div class="flex items-center space-x-2">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-400 flex-shrink-0">
                                  <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                                <img src="' . e(get_avatar_src($row['user_profile_picture'], $row['user_name'])) . '" alt="User Avatar" class="w-6 h-6 rounded-full object-cover">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">' . e($row['user_name']) . '</p>
                                    <p class="text-xs text-gray-500">' . e($row['user_email']) . '</p>
                                </div>
                                <span class="status-badge bg-green-50 text-green-700 border border-green-200 text-xs px-1 py-0.5">Customer</span>
                            </div>';
                            // Assigned display
                            $assigned_badge = 'bg-purple-50 text-purple-700 border border-purple-200 inline-block';
                            $assigned_text = !empty($row['staff_name']) ? $row['staff_name'] . ($row['staff_role'] ? ' (' . $row['staff_role'] . ')' : '') : 'Unassigned';
                    
                            $assigned_display = '';
                            if (!empty($row['staff_name'])) {
                                $avatar_src = get_avatar_src($row['staff_profile_picture'], $row['staff_name']);
                                $assigned_display = '
                                <div class="flex items-center space-x-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-400 flex-shrink-0">
                                      <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                                    </svg>
                                    <img src="' . e($avatar_src) . '" alt="Staff Avatar" class="w-6 h-6 rounded-full object-cover">
                                    <div class="flex items-center space-x-2">
                                        <div>
                                            <p class="text-sm font-medium text-gray-900">' . e($row['staff_name']) . '</p>
                                            <p class="text-xs text-gray-500">' . e($row['staff_role'] ?? 'N/A') . '</p>
                                        </div>
                                        <span class="status-badge ' . $assigned_badge . ' text-xs px-1 py-0.5">Assigned</span>
                                    </div>
                                </div>';
                            } else {
                                $assigned_display = '
                                <div class="flex items-center space-x-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-400 flex-shrink-0">
                                      <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12Z" />
                                    </svg>
                                    <span class="text-gray-400 italic text-sm">Unassigned</span>
                                </div>';
                            }
                            // Due date display
                            $due_display = '';
                            if ($row['action_due']):
                                $current_date = date('Y-m-d');
                                $due_date = $row['action_due'];
                                $days_until_due = (strtotime($due_date) - strtotime($current_date)) / (60 * 60 * 24);
                        
                                $due_class = 'bg-green-50 text-green-700 border border-green-200';
                                if ($days_until_due <= 0) {
                                    $due_class = 'bg-red-50 text-red-700 border border-red-200 animate-pulse';
                                } elseif ($days_until_due <= 3) {
                                    $due_class = 'bg-yellow-50 text-yellow-700 border border-yellow-200';
                                }
                                $due_display = '
                                <span class="status-badge inline-block ' . $due_class . ' px-2 py-1 text-xs font-medium flex items-center">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3 mr-1 flex-shrink-0">
                                      <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                    </svg>
                                    Due: ' . e(formatLocalDate($due_date . ' 00:00:00', 'M d, Y')) . '
                                </span>';
                            endif;
                            // Category display
                            $category_badge = 'bg-gray-50 text-gray-600 border border-gray-200';
                            $category_display = '
                            <div class="flex items-center space-x-2">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 28" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-400 flex-shrink-0">
                                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 3.75H6.912a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H15M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859M12 3v8.25m0 0-3-3m3 3 3-3" />
                                </svg>
                                <p class="text-sm font-medium text-gray-800">' . e($row['category']) . '</p>
                                <span class="status-badge ' . $category_badge . ' text-xs px-1 py-0.5">Category</span>
                            </div>';
                            // Build status history for timeline
                            $status_history = [];
                            $status_history[] = [
                                'timestamp' => $row['created_at'],
                                'status' => 'Pending',
                                'event' => 'Complaint Created',
                                'details' => 'Initial status: Pending'
                            ];
                            $previous_status = 'Pending';
                            foreach ($assignments as $assign) {
                                $assign_status = $assign['assignment_status'] ?? 'Pending';
                                if ($assign_status !== $previous_status) {
                                    $status_history[] = [
                                        'timestamp' => $assign['assigned_at'],
                                        'status' => $assign_status,
                                        'event' => 'Status Changed',
                                        'details' => 'Status changed from ' . $previous_status . ' to ' . $assign_status
                                    ];
                                }
                                $status_history[] = [
                                    'timestamp' => $assign['assigned_at'],
                                    'status' => $assign_status,
                                    'event' => 'Assigned to Staff',
                                    'details' => [
                                        'staff_name' => $assign['staff_name'],
                                        'staff_role' => $assign['staff_role'] ?? 'Administrator',
                                        'staff_profile_picture' => get_avatar_src($assign['staff_profile_picture'], $assign['staff_name'])
                                    ]
                                ];
                                $previous_status = $assign_status;
                            }
                            $last_assignment_status = end($assignments)['assignment_status'] ?? 'Pending';
                            if ($current_status !== $last_assignment_status) {
                                $change_timestamp = !empty($row['resolved_at']) && in_array($current_status, ['Resolved', 'Closed']) ? $row['resolved_at'] : $row['updated_at'];
                                $status_history[] = [
                                    'timestamp' => $change_timestamp,
                                    'status' => $current_status,
                                    'event' => 'Status Changed',
                                    'details' => 'Status changed from ' . $last_assignment_status . ' to ' . $current_status
                                ];
                            }
                            // Add comments to history
                            $complaint_comments = $comments[$row['complaint_id']] ?? [];
                            foreach ($complaint_comments as $comment) {
                                $profile_picture = $comment['commenter_type'] === 'staff' ? get_avatar_src($comment['staff_profile_picture'], $comment['commenter_name']) : get_avatar_src($comment['user_profile_picture'], $comment['commenter_name']);
                                $status_history[] = [
                                    'timestamp' => $comment['created_at'],
                                    'status' => $current_status,
                                    'event' => 'Comment Added',
                                    'details' => [
                                        'commenter_name' => $comment['commenter_name'],
                                        'commenter_type' => $comment['commenter_type'],
                                        'commenter_id' => $comment['commenter_id'],
                                        'comment_text' => $comment['comment_text'],
                                        'profile_picture' => $profile_picture,
                                        'role' => ($comment['commenter_type'] === 'staff' ? ($comment['staff_role'] ?? 'Staff') : 'Customer'),
                                        'comment_id' => $comment['id']
                                    ]
                                ];
                            }
                    
                            usort($status_history, function($a, $b) {
                                return strtotime($a['timestamp']) - strtotime($b['timestamp']);
                            });
                            // Resolved badge
                            $resolved_display = '';
                            if (in_array($current_status, ['Resolved', 'Closed']) && !empty($row['resolved_at'])) {
                                $resolved_display = '
                                <span class="resolved-badge inline-flex items-center gap-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="resolved-icon">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                    </svg>
                                    Resolved on ' . e(formatLocalDate($row['resolved_at'], 'M d, Y')) . '
                                </span>';
                            }
                            ?>
                            <div class="complaint-card" data-status="<?php echo e($current_status); ?>" data-category="<?php echo e($row['category']); ?>" data-description="<?php echo e(strtolower($row['description'])); ?>" data-user="<?php echo e(strtolower($row['user_name'] ?? '')); ?>" data-complaint-id="<?php echo (int)$row['complaint_id']; ?>" data-action-due="<?php echo e($row['action_due'] ?? ''); ?>" data-resolved-at="<?php echo !empty($row['resolved_at']) ? e(date('Y-m-d', strtotime($row['resolved_at']))) : ''; ?>">
                                <button type="button" class="w-full p-4 flex justify-between items-center bg-gray-50 hover:bg-gray-100 focus:outline-none" onclick="this.nextElementSibling.classList.toggle('hidden'); this.querySelector('svg').classList.toggle('rotate-180');">
                                    <div class="flex items-center space-x-3">
                                        <h3 class="text-base font-semibold text-gray-900">Complaint #<?php echo (int)$row['complaint_id']; ?> - <?php echo e($row['category']); ?></h3>
                                        <span id="status-badge-<?php echo (int)$row['complaint_id']; ?>" class="status-badge inline-block <?php echo $status_badge; ?>"><?php echo e($current_status); ?></span>
                                    </div>
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-500 transition-transform duration-200">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </button>
                                <div class="hidden p-4 space-y-4">
                                    <div class="complaint-header">
                                        <div class="complaint-meta">
                                            <?php echo $category_display; ?>
                                            <?php echo $customer_display; ?>
                                            <?php echo $assigned_display; ?>
                                        </div>
                                        <div class="text-right flex flex-wrap justify-end items-center gap-2">
                                            <?php if ($row['action_due']): ?>
                                                <?php echo $due_display; ?>
                                            <?php endif; ?>
                                            <?php if (!empty($sentiment)): ?>
                                                <span class="sentiment-badge inline-flex items-center gap-1 <?php echo $sentiment_badge; ?>">
                                                    <?php echo $sentiment_icon; ?>
                                                    <?php echo e($sentiment); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php echo $resolved_display; ?>
                                        </div>
                                    </div>
                            
                                    <div class="complaint-description">
                                        <h4 class="text-sm font-medium text-gray-700 mb-1">Description</h4>
                                        <p class="text-sm text-gray-600 bg-gray-50 p-3 rounded-md"><?php echo nl2br(e($row['description'])); ?></p>
                                        <?php if (!empty($row['attachment_path'])): ?>
                                            <div class="mt-3">
                                                <a href="../Uploads/complaints/<?php echo e($row['attachment_path']); ?>" target="_blank" class="text-blue-600 hover:underline text-sm inline-flex items-center">
                                                    <i class="fas fa-paperclip mr-1"></i>View Attachment
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="complaint-actions flex gap-2">
                                        <button onclick="openAssignModal(<?php echo (int)$row['complaint_id']; ?>)" class="bg-green-50 text-green-600 hover:bg-green-100 px-3 py-1.5 rounded-lg text-sm font-medium border border-green-200 transition-colors flex items-center">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 mr-1">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z" />
                                            </svg>
                                            Assign
                                        </button>
                                        <button onclick="openStatusModal(<?php echo (int)$row['complaint_id']; ?>)" class="bg-yellow-50 text-yellow-600 hover:bg-yellow-100 px-3 py-1.5 rounded-lg text-sm font-medium border border-yellow-200 transition-colors flex items-center">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 mr-1">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                            </svg>
                                            Update Status
                                        </button>
                                    </div>
                                    <?php if (!empty($row['location_address']) || (!empty($row['location_lat']) && !empty($row['location_lng']))): ?>
                                    <div class="location-section">
                                        <h4 class="text-sm font-medium text-gray-700 mb-1">Location</h4>
                                        <?php if (!empty($row['location_address'])): ?>
                                        <p class="text-sm text-gray-600 mb-2 bg-gray-50 p-2 rounded-md"><?php echo nl2br(e($row['location_address'])); ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($row['location_lat']) && !empty($row['location_lng'])): ?>
                                        <button type="button" class="view-map-btn inline-flex items-center px-3 py-1.5 text-sm font-medium text-blue-600 bg-blue-50 rounded-lg hover:bg-blue-100 transition-colors border border-blue-200" data-lat="<?php echo e($row['location_lat']); ?>" data-lng="<?php echo e($row['location_lng']); ?>" data-address="<?php echo e($row['location_address']); ?>">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 mr-1">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0Z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 1 1115 0z" />
                                            </svg>
                                            View on Map
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-700 mb-2">History Timeline</h4>
                                        <div class="relative border-l-2 border-gray-200 ml-6">
                                            <?php foreach ($status_history as $event): ?>
                                                <div class="mb-6 ml-6">
                                                    <?php
                                                    $dot_class = 'bg-gray-100';
                                                    $icon_class = 'text-gray-600';
                                                    $icon_path = 'M4.5 12.75l6 6 9-13.5'; // Default check
                                                    $event_status = $event['status'] ?? 'Pending';
                                            
                                                    if ($event['event'] === 'Complaint Created') {
                                                        $dot_class = 'bg-yellow-100';
                                                        $icon_class = 'text-yellow-600';
                                                        $icon_path = 'M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621 0 1.125.504 1.125 1.125V18a2.25 2.25 0 0 1-2.25 2.25M16.5 7.5V18a2.25 2.25 0 0 0 2.25 2.25M16.5 7.5V4.875c0-.621-.504-1.125-1.125-1.125H4.125C3.504 3.75 3 4.254 3 4.875V18a2.25 2.25 0 0 0 2.25 2.25h13.5M6 7.5h3v3H6v-3Z';
                                                    } elseif ($event['event'] === 'Status Changed') {
                                                        $dot_class = $event_status === 'In Progress' ? 'bg-blue-100' : ($event_status === 'Resolved' ? 'bg-green-100' : ($event_status === 'Closed' ? 'bg-gray-100' : 'bg-yellow-100'));
                                                        $icon_class = $event_status === 'In Progress' ? 'text-blue-600' : ($event_status === 'Resolved' ? 'text-green-600' : ($event_status === 'Closed' ? 'text-gray-600' : 'text-yellow-600'));
                                                        $icon_path = 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z';
                                                    } elseif ($event['event'] === 'Assigned to Staff') {
                                                        $dot_class = 'bg-purple-100';
                                                        $icon_class = 'text-purple-600';
                                                        $icon_path = 'M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12Z';
                                                    } elseif ($event['event'] === 'Comment Added') {
                                                        $dot_class = 'bg-indigo-100';
                                                        $icon_class = 'text-indigo-600';
                                                        $icon_path = 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-4l-4 4z';
                                                    }
                                                    ?>
                                                    <div class="absolute w-6 h-6 rounded-full flex items-center justify-center -left-3 <?php echo $dot_class; ?>">
                                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 <?php echo $icon_class; ?>">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="<?php echo $icon_path; ?>" />
                                                        </svg>
                                                    </div>
                                                    <div class="mt-3 bg-white p-3 rounded-lg shadow-sm border border-gray-100">
                                                        <p class="text-xs text-gray-400"><?php echo formatLocalDate($event['timestamp']); ?></p>
                                                        <p class="text-sm font-medium text-gray-900"><?php echo e($event['event']); ?></p>
                                                
                                                        <?php if ($event['event'] === 'Assigned to Staff' && isset($event['details']['staff_name'])): ?>
                                                            <div class="flex items-center mt-1 space-x-2">
                                                                <img src="<?php echo e($event['details']['staff_profile_picture']); ?>" alt="Staff Avatar" class="w-5 h-5 rounded-full object-cover">
                                                                <div>
                                                                    <p class="text-xs text-gray-900"><?php echo e($event['details']['staff_name']); ?></p>
                                                                    <p class="text-xs text-gray-500"><?php echo e($event['details']['staff_role']); ?></p>
                                                                </div>
                                                            </div>
                                                        <?php elseif ($event['event'] === 'Comment Added' && isset($event['details']['commenter_name'])): ?>
                                                            <div class="flex items-center mt-1 space-x-2">
                                                                <img src="<?php echo e($event['details']['profile_picture']); ?>" alt="Commenter Avatar" class="w-5 h-5 rounded-full object-cover">
                                                                <div>
                                                                    <p class="text-xs text-gray-900"><?php echo e($event['details']['commenter_name']); ?></p>
                                                                    <p class="text-xs text-gray-500"><?php echo e($event['details']['role']); ?></p>
                                                                </div>
                                                            </div>
                                                            <div class="flex flex-col">
                                                                <p class="text-sm text-gray-700 mt-2 italic bg-gray-50 p-2 rounded-md border-l-4 border-indigo-500"><?php echo nl2br(e($event['details']['comment_text'])); ?></p>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($event['event'] !== 'Comment Added'): ?>
                                                            <p class="text-xs text-gray-500 mt-1"><?php echo is_array($event['details']) ? 'Status: ' . e($event_status) : e($event['details']); ?></p>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php endif; ?>
                <?php if ($total_pages > 1): ?>
                    <div id="pagination" class="p-6 border-t border-gray-200 bg-gray-50 rounded-lg">
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
            </main>
        </div>
    </div>
    <button id="mobileMenuToggle" class="fixed top-4 left-4 z-40 p-2 rounded-lg text-gray-600 bg-white shadow-lg md:hidden">
        <i class="fas fa-bars text-lg"></i>
    </button>
    <div id="exportModal" class="modal">
        <div class="bg-white w-11/12 max-w-md rounded-2xl p-6 shadow-2xl">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Export as PDF</h3>
                <button onclick="closeExportModal()" class="text-gray-500 hover:text-gray-800"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST" id="exportForm">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                <input type="hidden" name="status" value="<?php echo e($status); ?>">
                <input type="hidden" name="category" value="<?php echo e($category); ?>">
                <input type="hidden" name="q" value="<?php echo e($q); ?>">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Date Range</label>
                        <div class="flex gap-4">
                            <label class="flex items-center">
                                <input type="radio" name="date_type" value="month" checked onchange="toggleDateOption()" class="mr-2">
                                Month/Year
                            </label>
                            <label class="flex items-center">
                                <input type="radio" name="date_type" value="range" onchange="toggleDateOption()" class="mr-2">
                                Custom Range
                            </label>
                        </div>
                    </div>
                    <div id="monthOption" class="date-option active space-y-2">
                        <select name="month" id="exportMonth" class="w-full border border-gray-200 rounded-lg px-3 py-2" onchange="validateExportForm()">
                            <option value="">Month</option>
                            <?php
                            $months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                            for ($m = 1; $m <= 12; $m++) {
                                $selected = (date('n') == $m) ? 'selected' : '';
                                echo "<option value='$m' $selected>{$months[$m-1]}</option>";
                            }
                            ?>
                        </select>
                        <select name="year" id="exportYear" class="w-full border border-gray-200 rounded-lg px-3 py-2" onchange="validateExportForm()">
                            <option value="">Year</option>
                            <?php for ($y = date('Y'); $y >= 2020; $y--) {
                                $selected = (date('Y') == $y) ? 'selected' : '';
                                echo "<option value='$y' $selected>$y</option>";
                            } ?>
                        </select>
                    </div>
                    <div id="rangeOption" class="date-option space-y-2" style="display:none;">
                        <input type="date" name="from" id="exportFrom" class="w-full border border-gray-200 rounded-lg px-3 py-2" onchange="validateExportForm()">
                        <input type="date" name="to" id="exportTo" class="w-full border border-gray-200 rounded-lg px-3 py-2" onchange="validateExportForm()">
                    </div>
                    <div class="flex justify-end space-x-2 mt-6">
                        <button type="button" onclick="closeExportModal()" class="px-4 py-2 border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50">Cancel</button>
                        <button type="submit" name="export_pdf" id="generatePdfBtn"
                                class="px-4 py-2 rounded-lg text-sm font-medium bg-blue-600 text-white opacity-50 cursor-not-allowed pointer-events-none transition-all"
                                disabled>
                            Generate PDF
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <div id="assignModal" class="modal">
        <div class="bg-white w-11/12 max-w-md rounded-2xl p-6 shadow-2xl">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Assign Complaint</h3>
            <form id="assignForm">
                <input type="hidden" name="complaint_id" id="assignComplaintId">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                <div class="space-y-4">
                    <select name="staff_id" id="assignStaffSelect" class="w-full border border-gray-200 rounded-lg px-3 py-2" required>
                        <option value="">Select Staff</option>
                        <?php foreach ($staff_list as $s): ?>
                            <option value="<?php echo (int)$s['staff_id']; ?>"><?php echo e($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="flex justify-end space-x-2">
                        <button type="button" onclick="closeAssignModal()" class="px-4 py-2 border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Assign</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <div id="statusModal" class="modal">
        <div class="bg-white w-11/12 max-w-md rounded-2xl p-6 shadow-2xl">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Update Status</h3>
            <form id="statusForm">
                <input type="hidden" name="complaint_id" id="statusComplaintId">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                <div class="space-y-4">
                    <select name="status" class="w-full border border-gray-200 rounded-lg px-3 py-2" onchange="toggleDateFields(this)" required>
                        <?php foreach ($ALLOWED_STATUSES as $s): ?>
                            <option value="<?php echo e($s); ?>"><?php echo e($s); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="resolvedDateField" style="display:none;">
                        <label class="block text-sm font-medium text-gray-700">Resolved Date</label>
                        <input type="date" name="resolved_at" class="w-full border border-gray-200 rounded-lg px-3 py-2">
                    </div>
                    <div id="dueDateField" style="display:none;">
                        <label class="block text-sm font-medium text-gray-700">Due Date</label>
                        <input type="date" name="action_due" class="w-full border border-gray-200 rounded-lg px-3 py-2">
                    </div>
            
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Add Comment (Optional)</label>
                        <textarea name="comment_text" rows="3" class="w-full border border-gray-200 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="Mag-comment ka rito para ma-update ang customer (e.g., 'Nag-progress na po ang issue, expected resolution by Friday.')"></textarea>
                        <p class="text-xs text-gray-500 mt-1">Makikita ito ng customer sa kanilang dashboard.</p>
                    </div>
                    <div class="flex justify-end space-x-2">
                        <button type="button" onclick="closeStatusModal()" class="px-4 py-2 border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Update & Comment</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <div id="mapModal" class="fixed inset-0 z-50 hidden bg-black/50 flex items-center justify-center p-4 overflow-auto">
        <div class="bg-white rounded-2xl max-w-4xl w-full max-h-[90vh] flex flex-col shadow-2xl">
            <div class="p-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-900">Location on Map</h3>
                <button id="closeMapModal" class="text-gray-400 hover:text-gray-600 text-2xl font-bold">&times;</button>
            </div>
            <div id="modalMap" class="flex-1 relative" style="min-height: 400px;"></div>
            <div class="p-4 border-t border-gray-200 text-center text-sm text-gray-500">
                <p>Zoom and pan to explore. Click &times; to close.</p>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('-translate-x-full');
            sidebar.classList.toggle('translate-x-0');
        });
        // Profile Dropdown
        const profileDropdown = document.getElementById('profileDropdown');
        const profileDropdownMenu = document.getElementById('profileDropdownMenu');
        profileDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
            profileDropdownMenu.classList.toggle('hidden');
            const rect = profileDropdown.getBoundingClientRect();
            profileDropdownMenu.style.right = '1.5rem';
            profileDropdownMenu.style.top = `${rect.bottom + 8}px`;
        });
        document.addEventListener('click', function(e) {
            if (!profileDropdown.contains(e.target)) {
                profileDropdownMenu.classList.add('hidden');
            }
        });
        // Clear Filters
        document.getElementById('clearFilters').addEventListener('click', function() {
            document.getElementById('filterForm').reset();
            document.getElementById('filterForm').submit();
        });
        // Export Modal functions
        function toggleDateOption() {
            const type = document.querySelector('input[name="date_type"]:checked').value;
            document.getElementById('monthOption').style.display = type === 'month' ? 'block' : 'none';
            document.getElementById('rangeOption').style.display = type === 'range' ? 'block' : 'none';
            validateExportForm();
        }
        function validateExportForm() {
            const type = document.querySelector('input[name="date_type"]:checked').value;
            const btn = document.getElementById('generatePdfBtn');
            let valid = false;
            if (type === 'month') {
                const month = document.getElementById('exportMonth').value;
                const year = document.getElementById('exportYear').value;
                valid = month && year;
            } else {
                const from = document.getElementById('exportFrom').value;
                const to = document.getElementById('exportTo').value;
                valid = from && to && from <= to;
            }
            if (valid) {
                btn.disabled = false;
                btn.classList.remove('opacity-50', 'cursor-not-allowed', 'pointer-events-none');
                btn.classList.add('hover:bg-blue-700');
            } else {
                btn.disabled = true;
                btn.classList.add('opacity-50', 'cursor-not-allowed', 'pointer-events-none');
                btn.classList.remove('hover:bg-blue-700');
            }
        }
        function openExportModal() { document.getElementById('exportModal').classList.add('show'); }
        function closeExportModal() { document.getElementById('exportModal').classList.remove('show'); }
        function openAssignModal(id) {
            document.getElementById('assignComplaintId').value = id;
            document.getElementById('assignStaffSelect').value = '';
            document.getElementById('assignModal').classList.add('show');
        }
        function closeAssignModal() { document.getElementById('assignModal').classList.remove('show'); }
        function openStatusModal(id) {
            document.getElementById('statusComplaintId').value = id;
            const card = document.querySelector(`[data-complaint-id="${id}"]`);
            if (!card) {
                console.error('Card not found for id:', id);
                return;
            }
            const currentStatus = card.dataset.status;
            const select = document.querySelector('#statusForm select[name="status"]');
            select.value = currentStatus;
    
            const resolvedInput = document.querySelector('#statusForm input[name="resolved_at"]');
            const dueInput = document.querySelector('#statusForm input[name="action_due"]');
    
            if (['Resolved', 'Closed'].includes(currentStatus)) {
                resolvedInput.value = card.dataset.resolvedAt || '';
            } else {
                resolvedInput.value = '';
            }
            if (currentStatus === 'In Progress') {
                dueInput.value = card.dataset.actionDue || '';
            } else {
                dueInput.value = '';
            }
    
            toggleDateFields(select);
            // Clear comment textarea
            document.querySelector('#statusForm textarea[name="comment_text"]').value = '';
            document.getElementById('statusModal').classList.add('show');
        }
        function closeStatusModal() { document.getElementById('statusModal').classList.remove('show'); }
        function toggleDateFields(select) {
            const resolvedField = document.getElementById('resolvedDateField');
            const dueField = document.getElementById('dueDateField');
    
            resolvedField.style.display = 'none';
            dueField.style.display = 'none';
            const value = select.value;
            if (['Resolved', 'Closed'].includes(value)) {
                resolvedField.style.display = 'block';
                const resolvedInput = document.querySelector('input[name="resolved_at"]');
                if (!resolvedInput.value) {
                    resolvedInput.value = new Date().toISOString().split('T')[0];
                }
            } else if (value === 'In Progress') {
                dueField.style.display = 'block';
                const dueInput = document.querySelector('input[name="action_due"]');
                if (!dueInput.value) {
                    dueInput.value = new Date().toISOString().split('T')[0];
                }
            }
        }
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', e => { if (e.target === modal) modal.classList.remove('show'); });
        });
        // AJAX Assignment
        document.getElementById('assignForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const complaintId = document.getElementById('assignComplaintId').value;
            const staffSelect = document.getElementById('assignStaffSelect');
            const selectedStaff = staffSelect.options[staffSelect.selectedIndex].text;
            try {
                const response = await fetch('assign_complaint.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    Swal.fire({
                        title: 'Success!',
                        text: data.msg,
                        icon: 'success',
                        confirmButtonColor: '#3b82f6'
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        title: 'Error!',
                        text: data.msg,
                        icon: 'error',
                        confirmButtonColor: '#3b82f6'
                    });
                }
            } catch (error) {
                Swal.fire({
                    title: 'Error!',
                    text: 'Failed to assign complaint. Please try again.',
                    icon: 'error',
                    confirmButtonColor: '#3b82f6'
                });
            }
        });
        // Updated AJAX Status Update with Comment
        document.getElementById('statusForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const complaintId = document.getElementById('statusComplaintId').value;
            const newStatus = document.querySelector('#statusForm select[name="status"]').value;
            const resolvedAt = document.querySelector('#statusForm input[name="resolved_at"]').value;
            const commentText = document.querySelector('#statusForm textarea[name="comment_text"]').value.trim();
            if (commentText) {
                formData.append('comment_text', commentText);
            }
            try {
                const response = await fetch('update_status.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                const data = await response.json();
                if (data.success) {
                    Swal.fire({
                        title: 'Success!',
                        text: data.msg,
                        icon: 'success',
                        confirmButtonColor: '#3b82f6'
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        title: 'Error!',
                        text: data.msg,
                        icon: 'error',
                        confirmButtonColor: '#3b82f6'
                    });
                }
            } catch (error) {
                Swal.fire({
                    title: 'Error!',
                    text: 'Failed to update status. Please try again.',
                    icon: 'error',
                    confirmButtonColor: '#3b82f6'
                });
            }
        });
        // Map Modal Functionality
        let modalMap = null;
        const customIcon = L.icon({
            iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-blue.png',
            shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
            iconSize: [25, 41],
            iconAnchor: [12, 41],
            popupAnchor: [1, -34]
        });
        function openMapModal(lat, lng, address = '') {
            const modal = document.getElementById('mapModal');
            if (!modal) {
                console.error('Modal element not found!');
                return;
            }
            modal.classList.remove('hidden');
            setTimeout(() => {
                if (!modalMap) {
                    if (typeof L === 'undefined') {
                        console.error('Leaflet not loaded!');
                        return;
                    }
                    try {
                        modalMap = L.map('modalMap').setView([lat, lng], 15);
                        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                        }).addTo(modalMap);
                        const marker = L.marker([lat, lng], {icon: customIcon}).addTo(modalMap);
                        let popupContent = `<b>Customer Location</b><br>`;
                        if (address) {
                            popupContent += `Address: ${address}<br>`;
                        }
                        marker.bindPopup(popupContent).openPopup();
                    } catch (err) {
                        console.error('Map init error:', err);
                    }
                } else {
                    modalMap.setView([lat, lng], 15);
                    modalMap.eachLayer(function(layer) {
                        if (layer instanceof L.Marker) {
                            modalMap.removeLayer(layer);
                        }
                    });
                    const marker = L.marker([lat, lng], {icon: customIcon}).addTo(modalMap);
                    let popupContent = `<b>Customer Location</b><br>`;
                    if (address) {
                        popupContent += `Address: ${address}<br>`;
                    }
                    marker.bindPopup(popupContent).openPopup();
                }
            }, 100);
        }
        function closeMapModal() {
            const modal = document.getElementById('mapModal');
            if (modal) {
                modal.classList.add('hidden');
            }
            if (modalMap) {
                modalMap.remove();
                modalMap = null;
            }
        }
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.view-map-btn');
            if (btn) {
                const lat = parseFloat(btn.dataset.lat);
                const lng = parseFloat(btn.dataset.lng);
                const address = btn.dataset.address || '';
                if (isNaN(lat) || isNaN(lng)) {
                    alert('Invalid location data. Please refresh the page.');
                    return;
                }
                openMapModal(lat, lng, address);
            }
        });
        const closeBtn = document.getElementById('closeMapModal');
        if (closeBtn) {
            closeBtn.addEventListener('click', closeMapModal);
        }
        const mapModalEl = document.getElementById('mapModal');
        if (mapModalEl) {
            mapModalEl.addEventListener('click', function(e) {
                if (e.target === mapModalEl) {
                    closeMapModal();
                }
            });
        }
        document.addEventListener('DOMContentLoaded', () => {
            // Clear search on page refresh (reload)
            if ('performance' in window && performance.getEntriesByType('navigation').length > 0) {
                const nav = performance.getEntriesByType('navigation')[0];
                if (nav.type === 'reload') {
                    const url = new URL(window.location.href);
                    if (url.searchParams.has('q')) {
                        url.searchParams.delete('q');
                        window.history.replaceState(null, '', url.toString());
                        document.getElementById('globalSearch').value = '';
                        document.getElementById('filterForm').submit();
                    }
                }
            }
            toggleDateOption();
            validateExportForm();
        });
    </script>
</body>
</html>
<?php mysqli_close($conn); ?>