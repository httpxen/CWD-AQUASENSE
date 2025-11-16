<?php
include 'session_check.php'; // DB connection + session

// Session timeout (30 mins)
$timeout_duration = 1800;
if (!isset($_SESSION['staff_id'])) {
    header("Location: login.php?message=Please log in.");
    exit();
}
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: login.php?message=Session expired.");
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

$staff_id = $_SESSION['staff_id'];

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

    $status = $_POST['status'] ?? '';
    $category = $_POST['category'] ?? '';
    $q = trim($_POST['q'] ?? '');

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
        SELECT c.complaint_id, c.category, c.description, c.status, c.sentiment, c.action_due, c.created_at, c.updated_at, c.attachment_path, c.resolved_at,
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
$title = 'Complaints Report ' . date('Y');
$pdf->SetY(15); // Start title a bit lower without logo
$pdf->Cell(0, 10, $title, 0, 1, 'C');
$pdf->Ln(8);

// Filters Info
$pdf->SetFont('helvetica', '', 10);
$filter_text = "Exported on: " . date('M d, Y h:i A') . "\n";
if (isset($status) && $status) $filter_text .= "Status: $status\n";
if (isset($category) && $category) $filter_text .= "Category: $category\n";
if (isset($q) && $q) $filter_text .= "Search: $q\n";
$filter_text .= "Total Records: " . count($rows);
$pdf->MultiCell(0, 10, $filter_text, 0, 'L');
$pdf->Ln(5);

// Table Header
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetFillColor(59, 130, 246); // Professional blue for header
$pdf->SetTextColor(255, 255, 255); // White text
$pdf->SetLineWidth(0.2); // Slightly thicker lines for clean borders
$header = ['ID', 'Category', 'Status', 'User', 'Assigned', 'Created', 'Due'];
$widths = [15, 30, 25, 40, 30, 25, 25];
$h = 8; // Header height
foreach ($header as $i => $col) {
    $pdf->Cell($widths[$i], $h, $col, 1, 0, 'C', true); // Full border for header
}
$pdf->Ln();

// Table Data with alternating highlights
$pdf->SetFont('helvetica', '', 8);
$pdf->SetTextColor(0, 0, 0); // Black text
$pdf->SetFillColor(249, 250, 252); // Light gray for alternating rows
$fill = false; // Start with white row
$h = 7; // Data row height
foreach ($rows as $row) {
    $pdf->Cell($widths[0], $h, $row['complaint_id'], 1, 0, 'C', $fill); // Full border
    $pdf->Cell($widths[1], $h, substr($row['category'] ?? '', 0, 18), 1, 0, 'L', $fill);
    $pdf->Cell($widths[2], $h, $row['status'] ?? '', 1, 0, 'C', $fill);
    $pdf->Cell($widths[3], $h, substr($row['user_name'] ?? 'N/A', 0, 25), 1, 0, 'L', $fill);
    $pdf->Cell($widths[4], $h, substr($row['staff_name'] ?? 'Unassigned', 0, 18), 1, 0, 'L', $fill);
    $pdf->Cell($widths[5], $h, date('M d', strtotime($row['created_at'] ?? 'now')), 1, 0, 'C', $fill);
    $due = (!empty($row['action_due']) ? date('M d', strtotime($row['action_due'])) : '-');
    $pdf->Cell($widths[6], $h, $due, 1, 0, 'C', $fill);
    $pdf->Ln();
    $fill = !$fill; // Alternate fill color
}

$filename = 'complaints_report_' . date('Y') . '.pdf';
$pdf->Output($filename, 'D');
exit;

}

// === LIST VIEW ===
$status = $_GET['status'] ?? '';
$category = $_GET['category'] ?? '';
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
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
    $clauses[] = "(c.description LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $types .= "ss";
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
    SELECT c.complaint_id, c.category, c.description, c.status, c.sentiment, c.action_due, c.created_at, c.updated_at, c.attachment_path, c.resolved_at,
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
    ORDER BY c.created_at DESC
    LIMIT ? OFFSET ?
";
$list_stmt = mysqli_prepare($conn, $list_sql);
$types_paged = $types . "ii";
$params_paged = array_merge($params, [$per_page, $offset]);
mysqli_stmt_bind_param($list_stmt, $types_paged, ...$params_paged);
mysqli_stmt_execute($list_stmt);
$list_res = mysqli_stmt_get_result($list_stmt);

$staff_sql = "SELECT staff_id, name FROM staff ORDER BY name";
$staff_res = mysqli_query($conn, $staff_sql);
$staff_list = [];
while ($row = mysqli_fetch_assoc($staff_res)) {
    $staff_list[] = $row;
}

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
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); }
        .status-badge, .sentiment-badge, .resolved-badge { border-radius: 0.5rem; padding: 0.25rem 0.5rem; font-size: 0.75rem; font-weight: 600; border-width: 1px; }
        .avatar-glow { position: relative; cursor: pointer; }
        .avatar-glow::before { content: ''; position: absolute; top: -2px; left: -2px; right: -2px; bottom: -2px; background: linear-gradient(45deg, #3b82f6, #8b5cf6, #06b6d4, #3b82f6); border-radius: 50%; z-index: -1; opacity: 0; transition: opacity 0.3s ease; }
        .avatar-glow:hover::before { opacity: 1; }
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
        .complaint-card { background: white; border: 1px solid #e5e7eb; border-radius: 0.75rem; padding: 1.5rem; margin-bottom: 1rem; box-shadow: 0 1px 3px 0 rgba(0,0,0,0.1); transition: all 0.2s ease; }
        .complaint-card:hover { box-shadow: 0 4px 12px -2px rgba(0,0,0,0.08); transform: translateY(-1px); }
        .complaint-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; flex-wrap: wrap; gap: 1rem; }
        .complaint-meta { display: flex; flex-direction: column; gap: 0.5rem; }
        .complaint-description { margin-bottom: 1rem; line-height: 1.5; color: #374151; max-height: 4.5em; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; word-break: break-word; }
        .complaint-description.full { max-height: none; -webkit-line-clamp: unset; }
        .toggle-description { color: #3b82f6; font-size: 0.875rem; cursor: pointer; text-decoration: underline; }
        .complaint-footer { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; justify-content: space-between; }
        .complaint-actions { display: flex; gap: 0.5rem; }
        .modal { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,.5); z-index: 50; }
        .modal.show { display: flex; }
        .date-option { display: none; }
        .date-option.active { display: block; }
        .resolved-badge { background-color: #d1fae5; color: #065f46; border-color: #34d399; display: flex; align-items: center; gap: 0.25rem; padding: 0.25rem 0.5rem; font-size: 0.75rem; font-weight: 600; border-width: 1px; border-radius: 0.5rem; }
        .resolved-badge .resolved-icon { width: 1rem; height: 1rem; stroke: currentColor; }
    </style>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex">
        <!-- Sidebar -->
        <div class="sidebar w-64 bg-white shadow-lg fixed h-full z-30">
            <div class="flex flex-col h-full">
                <div class="p-6">
                    <div class="flex items-center space-x-3"> 
                        <img src="../assets/icons/AquaSense.png" alt="Logo" class="w-16 h-16 rounded-lg object-contain bg-white p-1">
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
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01 .778-.332 48.294 48.294 0 0 1 5.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 1 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                        </svg>
                        View Feedback
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

        <!-- Main Content -->
        <div class="flex-1">
            <header class="header-2025 sticky top-0 z-20">
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4"></div>
                        <div class="flex items-center space-x-4">
                            <!-- Profile Dropdown -->
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

            <!-- Profile Dropdown Menu -->
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
                            <button onclick="openExportModal()" class="btn-primary px-4 py-2 rounded-lg text-sm font-medium flex items-center">
                                <i class="fas fa-file-pdf mr-2"></i>Export PDF
                            </button>
                            <button onclick="applyFilters()" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">Apply Filters</button>
                        </div>
                    </div>
                </div>

                <!-- Complaints List -->
                <div class="card overflow-hidden">
                    <div class="flex items-center justify-between p-6 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-900">All Complaints (<?php echo (int)$total_rows; ?>)</h2>
                        <p class="text-xs text-gray-500">Showing <?php echo min($per_page, $total_rows - $offset); ?> of <?php echo $total_rows; ?> results</p>
                    </div>
                    <div class="p-6 space-y-4">
                        <?php if ($total_rows === 0): ?>
                            <div class="text-center py-12 text-gray-500">No complaints found.</div>
                        <?php else: ?>
                            <?php while ($row = mysqli_fetch_assoc($list_res)): ?>
                                <?php
                                $status_badge = 'bg-gray-100 text-gray-700';
                                if ($row['status'] === 'Pending') $status_badge = 'bg-yellow-50 text-yellow-700 border border-yellow-200';
                                if ($row['status'] === 'In Progress') $status_badge = 'bg-blue-50 text-blue-700 border border-blue-200';
                                if ($row['status'] === 'Resolved' || $row['status'] === 'Closed') $status_badge = 'bg-green-50 text-green-700 border border-green-200';

                                $sentiment_badge = 'bg-gray-50 text-gray-600 border border-gray-200';
                                if ($row['sentiment'] === 'Positive') $sentiment_badge = 'bg-green-50 text-green-700 border border-green-200';
                                if ($row['sentiment'] === 'Negative') $sentiment_badge = 'bg-red-50 text-red-700 border border-red-200';

                                $sentiment_icon = '';
                                if ($row['sentiment'] === 'Positive') {
                                    $sentiment_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-3 h-3"><path d="M8.603 3.799A4.49 4.49 0 0 1 12 2.25c1.357 0 2.573.6 3.397 1.549a4.49 4.49 0 0 1 3.498 1.307 4.491 4.491 0 0 1 1.307 3.497A4.49 4.49 0 0 1 21.75 12a4.49 4.49 0 0 1-1.549 3.397 4.491 4.491 0 0 1-1.307 3.497 4.491 4.491 0 0 1-3.497 1.307A4.49 4.49 0 0 1 12 21.75a4.49 4.49 0 0 1-3.397-1.549 4.49 4.49 0 0 1-3.498-1.306 4.491 4.491 0 0 1-1.307-3.498A4.49 4.49 0 0 1 2.25 12c0-1.357.6-2.573 1.549-3.397a4.49 4.49 0 0 1 1.307-3.497 4.49 4.49 0 0 1 3.497-1.307Zm7.007 6.387a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z"/></svg>';
                                } elseif ($row['sentiment'] === 'Negative') {
                                    $sentiment_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-3 h-3"><path d="M5.47 5.47a.75.75 0 0 1 1.06 0L12 10.94l5.47-5.47a.75.75 0 0 1 1.06 1.06L13.06 12l5.47 5.47a.75.75 0 0 1-1.06 1.06L12 13.06l-5.47 5.47a.75.75 0 0 1-1.06-1.06L10.94 12 5.47 6.53a.75.75 0 0 1 0-1.06z"/></svg>';
                                }

                                $due_display = '';
                                if ($row['action_due']): 
                                    $current_date = date('Y-m-d');
                                    $due_date = $row['action_due'];
                                    $days_until_due = (strtotime($due_date) - strtotime($current_date)) / (60 * 60 * 24);
                                    $due_class = 'bg-green-50 text-green-700 border border-green-200';
                                    if ($days_until_due <= 0) $due_class = 'bg-red-50 text-red-700 border border-red-200 animate-pulse';
                                    elseif ($days_until_due <= 3) $due_class = 'bg-yellow-50 text-yellow-700 border border-yellow-200';
                                    $due_display = '<span class="status-badge inline-block ' . $due_class . ' px-2 py-1 text-xs font-medium flex items-center">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3 mr-1"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
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
                                            <div class="flex items-center space-x-2 mt-1">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-400">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 3.75H6.912a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H15M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859M12 3v8.25m0 0-3-3m3 3 3-3" />
                                                </svg>
                                                <p class="text-sm font-medium text-gray-800"><?php echo e($row['category']); ?></p>
                                                <span class="status-badge bg-gray-50 text-gray-600 border border-gray-200 text-xs px-1 py-0.5">Category</span>
                                            </div>
                                            <div class="flex items-center space-x-2 mt-1">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-400">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                                </svg>
                                                <img src="<?php echo e(get_avatar_src($row['profile_picture'], $row['user_name'] ?? 'User')); ?>" alt="User" class="w-6 h-6 rounded-full">
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
                                        <div class="text-right space-y-1">
                                            <?php echo $due_display; ?>
                                            <?php if (!empty($row['sentiment'])): ?>
                                                <span class="sentiment-badge inline-block <?php echo $sentiment_badge; ?> flex items-center gap-1"><?php echo $sentiment_icon; ?><?php echo e($row['sentiment']); ?></span>
                                            <?php endif; ?>
                                            <?php if (in_array($row['status'], ['Resolved', 'Closed']) && !empty($row['resolved_at'])): ?>
                                                <span class="resolved-badge inline-block">
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="resolved-icon">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                                    </svg>
                                                    Resolved on <?php echo e(date('M d, Y', strtotime($row['resolved_at']))); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="complaint-description" id="desc-<?php echo (int)$row['complaint_id']; ?>">
                                        <?php echo e($row['description']); ?>
                                    </div>
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
                                        </div>
                                        <div class="complaint-actions">
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
                            <?php endwhile; ?>
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
                                <a href="<?php echo $base . '&page=1'; ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 text-sm <?php echo $page == 1 ? 'pointer-events-none opacity-50' : ''; ?>">First</a>
                                <a href="<?php echo $base . '&page=' . max(1, $page - 1); ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 text-sm <?php echo $page == 1 ? 'pointer-events-none opacity-50' : ''; ?>">Prev</a>
                                <span class="text-sm text-gray-600">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                                <a href="<?php echo $base . '&page=' . min($total_pages, $page + 1); ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 text-sm <?php echo $page == $total_pages ? 'pointer-events-none opacity-50' : ''; ?>">Next</a>
                                <a href="<?php echo $base . '&page=' . $total_pages; ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 text-sm <?php echo $page == $total_pages ? 'pointer-events-none opacity-50' : ''; ?>">Last</a>
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

    <!-- Export Modal -->
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

                    <!-- Month/Year -->
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

                    <!-- Custom Range -->
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

    <!-- Assign Modal -->
    <div id="assignModal" class="modal">
        <div class="bg-white w-11/12 max-w-md rounded-2xl p-6 shadow-2xl">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Assign Complaint</h3>
            <form method="POST" action="assign_complaint.php">
                <input type="hidden" name="complaint_id" id="assignComplaintId">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                <div class="space-y-4">
                    <select name="staff_id" class="w-full border border-gray-200 rounded-lg px-3 py-2" required>
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

    <!-- Status Modal -->
    <div id="statusModal" class="modal">
        <div class="bg-white w-11/12 max-w-md rounded-2xl p-6 shadow-2xl">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Update Status</h3>
            <form method="POST" action="update_status.php">
                <input type="hidden" name="complaint_id" id="statusComplaintId">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                <div class="space-y-4">
                    <select name="status" class="w-full border border-gray-200 rounded-lg px-3 py-2" onchange="toggleResolvedDate(this)" required>
                        <?php foreach ($ALLOWED_STATUSES as $s): ?>
                            <option value="<?php echo e($s); ?>"><?php echo e($s); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="resolvedDateField" style="display:none;">
                        <label class="block text-sm font-medium text-gray-700">Resolved Date</label>
                        <input type="date" name="resolved_at" class="w-full border border-gray-200 rounded-lg px-3 py-2">
                    </div>
                    <div class="flex justify-end space-x-2">
                        <button type="button" onclick="closeStatusModal()" class="px-4 py-2 border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Update</button>
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

        // Export Modal
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

        document.addEventListener('DOMContentLoaded', () => {
            toggleDateOption();
            validateExportForm();
        });

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

        function openExportModal() { document.getElementById('exportModal').classList.add('show'); }
        function closeExportModal() { document.getElementById('exportModal').classList.remove('show'); }

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

        function toggleResolvedDate(select) {
            const field = document.getElementById('resolvedDateField');
            field.style.display = (select.value === 'Resolved' || select.value === 'Closed') ? 'block' : 'none';
        }

        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', e => { if (e.target === modal) modal.classList.remove('show'); });
        });
    </script>
</body>
</html>

<?php mysqli_close($conn); ?>