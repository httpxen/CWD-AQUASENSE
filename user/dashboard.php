<?php
include 'session_check.php'; // Include the separated session check

function e($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }

$user_id = $_SESSION['user_id'];

// ADD COLUMN IF NOT EXISTS for complaint_comments
$check_col_sql = "SHOW COLUMNS FROM complaint_comments LIKE 'is_read'";
$check_col_result = mysqli_query($conn, $check_col_sql);
if (mysqli_num_rows($check_col_result) == 0) {
    $add_col_sql = "ALTER TABLE complaint_comments ADD COLUMN `is_read` TINYINT(1) DEFAULT 0 AFTER `created_at`";
    mysqli_query($conn, $add_col_sql);
}

// ADD COLUMN IF NOT EXISTS for complaint_assignments
$check_assign_col_sql = "SHOW COLUMNS FROM complaint_assignments LIKE 'is_read'";
$check_assign_col_result = mysqli_query($conn, $check_assign_col_sql);
if (mysqli_num_rows($check_assign_col_result) == 0) {
    $add_assign_col_sql = "ALTER TABLE complaint_assignments ADD COLUMN `is_read` TINYINT(1) DEFAULT 0 AFTER `status`";
    mysqli_query($conn, $add_assign_col_sql);
}

// MARK AS READ HANDLER
if (isset($_POST['mark_read'])) {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $type = $_POST['type'] ?? 'comment';
    if ($id > 0) {
        $table = $type === 'assignment' ? 'complaint_assignments' : 'complaint_comments';
        $id_col = $type === 'assignment' ? 'id' : 'comment_id';
        $extra = $type === 'comment' ? "AND commenter_type = 'staff'" : '';
        $update_sql = "UPDATE {$table} SET is_read = 1 WHERE {$id_col} = ? {$extra}";
        $update_stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "i", $id);
        $success = mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
        if ($success) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'msg' => 'Failed to mark as read.']);
        }
    } else {
        echo json_encode(['success' => false, 'msg' => 'Invalid ID.']);
    }
    exit;
}

// Fetch user data
$user_query = "SELECT first_name, last_name, username, email, profile_picture FROM users WHERE id = ?";
$stmt = mysqli_prepare($conn, $user_query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$user_result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($user_result);
mysqli_stmt_close($stmt); // Close once

// Helper function for scalar queries
function scalar($conn, $sql, $types, $params) {
    $st = mysqli_prepare($conn, $sql);
    if ($types !== '') mysqli_stmt_bind_param($st, $types, ...$params);
    mysqli_stmt_execute($st);
    $rs = mysqli_stmt_get_result($st);
    $val = (int)mysqli_fetch_row($rs)[0];
    mysqli_stmt_close($st); // Close inside function
    return $val;
}

// KPIs
$baseWhere = "FROM complaints WHERE user_id = ? AND status IN ('Pending', 'In Progress')";
$kpi_total = scalar($conn, "SELECT COUNT(*) $baseWhere", "i", [$user_id]);
$kpi_pending = scalar($conn, "SELECT COUNT(*) FROM complaints WHERE user_id = ? AND status='Pending'", "i", [$user_id]);
$kpi_progress = scalar($conn, "SELECT COUNT(*) FROM complaints WHERE user_id = ? AND status='In Progress'", "i", [$user_id]);
$kpi_resolved = scalar($conn, "SELECT COUNT(*) FROM complaints WHERE user_id = ? AND (status='Resolved' OR status='Closed')", "i", [$user_id]);

// Average resolution time
$avg_sql = "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) AS avg_hrs FROM complaints WHERE user_id = ? AND (status='Resolved' OR status='Closed')";
$avg_stmt = mysqli_prepare($conn, $avg_sql);
mysqli_stmt_bind_param($avg_stmt, "i", $user_id);
mysqli_stmt_execute($avg_stmt);
$avg_res = mysqli_stmt_get_result($avg_stmt);
$avg_resolution_hours = mysqli_fetch_assoc($avg_res)['avg_hrs'];
$avg_resolution_hours = is_null($avg_resolution_hours) ? 0 : round((float)$avg_resolution_hours, 1);
mysqli_stmt_close($avg_stmt); // Close once

// Fetch notifications: Recent UNREAD staff comments and assignments
$notif_sql = "
    SELECT 
        'comment' as type,
        cc.comment_id as id, 
        cc.complaint_id, 
        cc.comment as message, 
        cc.created_at as timestamp,
        c.category, 
        c.description, 
        c.status,
        s.name AS staff_name
    FROM complaint_comments cc
    JOIN complaints c ON cc.complaint_id = c.complaint_id
    LEFT JOIN staff s ON cc.commenter_id = s.staff_id
    WHERE c.user_id = ? AND cc.commenter_type = 'staff' AND cc.is_read = 0
    
    UNION ALL
    
    SELECT 
        'assignment' as type,
        ca.id as id,
        ca.complaint_id, 
        CONCAT('Your complaint has been assigned to ', s.name) as message, 
        ca.assigned_at as timestamp,
        c.category, 
        c.description, 
        c.status,
        s.name AS staff_name
    FROM complaint_assignments ca
    JOIN complaints c ON ca.complaint_id = c.complaint_id
    JOIN staff s ON ca.staff_id = s.staff_id
    WHERE c.user_id = ? AND ca.is_read = 0
    
    ORDER BY timestamp DESC 
    LIMIT 10
";
$notif_stmt = mysqli_prepare($conn, $notif_sql);
mysqli_stmt_bind_param($notif_stmt, "ii", $user_id, $user_id);
mysqli_stmt_execute($notif_stmt);
$notif_res = mysqli_stmt_get_result($notif_stmt);
$notifications = [];
while ($row = mysqli_fetch_assoc($notif_res)) {
    if ($row['type'] === 'comment') {
        $row['message'] = "From {$row['staff_name']}: " . $row['message'];
    }
    $notifications[] = $row;
}
mysqli_stmt_close($notif_stmt);
$notif_count = count($notifications);

// Fetch announcements (similar to admin side, but filter for Active/Upcoming only, and no staff_name needed for customer view)
$announcements_query = "SELECT * FROM announcements ORDER BY start_date DESC";
$announcements_result = mysqli_query($conn, $announcements_query);
if (!$announcements_result) die("Query failed: " . mysqli_error($conn));
$ongoing = [];
$upcoming = [];
$now = new DateTime();
while ($row = mysqli_fetch_assoc($announcements_result)) {
    $start_str = $row['start_date'] . ($row['start_time'] ? ' ' . $row['start_time'] : ' 00:00:00');
    $end_str = $row['end_date'] . ($row['end_time'] ? ' ' . $row['end_time'] : ' 23:59:59');
    $start = new DateTime($start_str);
    $end = new DateTime($end_str);
    
    // Skip expired
    if ($end < $now) {
        continue;
    }
    
    // Set formatted fields
    $row['formatted_start'] = date('M j, Y g:i A', strtotime($start_str));
    $row['formatted_end'] = date('M j, Y g:i A', strtotime($end_str));
    
    // NEW: Flag for timed events (for allDay logic in JS)
    $row['has_time'] = !empty($row['start_time']) || !empty($row['end_time']);
    
    // Tweak formatted_range for clarity (hide hours if full-day)
    if (!$row['has_time']) {
        $row['formatted_range'] = date('M j', strtotime($row['start_date'])) . ($row['start_date'] !== $row['end_date'] ? ' – ' . date('M j, Y', strtotime($row['end_date'])) : '');
    } else {
        $row['formatted_range'] = date('M j, Y g:i A', strtotime($start_str)) . ' – ' . date('M j, Y g:i A', strtotime($end_str));
    }
    
    // Now set status and push
    if ($now >= $start && $now <= $end) {
        $row['status'] = 'Ongoing';
        $ongoing[] = $row;
    } else {
        $row['status'] = 'Upcoming';
        $upcoming[] = $row;
    }
}

// Ensure sorting within groups (though query already DESC, for safety)
usort($ongoing, function($a, $b) {
    return strtotime($b['start_date']) - strtotime($a['start_date']);
});
usort($upcoming, function($a, $b) {
    return strtotime($b['start_date']) - strtotime($a['start_date']);
});

// Merge: Ongoing first, then Upcoming
$announcements = array_merge($ongoing, $upcoming);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Dashboard | CWD AquaSense</title>
    <link rel="icon" type="image/png" href="../assets/icons/AquaSense2.png" />
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <link rel="stylesheet" href="css/dashboard.css" />
    
    <!-- SWEETALERT2 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- FullCalendar CDN for Calendar (optional, for announcements calendar) -->
    <link href='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css' rel='stylesheet' />
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js'></script>

    <style>
        #calendar {
            font-family: 'Inter', sans-serif;
        }
        .fc-theme-standard .fc-toolbar-chunk .fc-button-group .fc-button {
            background-color: #f3f4f6;
            border-color: #d1d5db;
            color: #374151;
            transition: all 0.2s;
        }
        .fc-theme-standard .fc-toolbar-chunk .fc-button-group .fc-button:hover {
            background-color: #e5e7eb;
            border-color: #9ca3af;
        }
        .fc-theme-standard .fc-toolbar-chunk .fc-button-group .fc-button.fc-button-active {
            background-color: #3b82f6;
            border-color: #2563eb;
            color: white;
        }
        .fc-daygrid-day-number {
            color: #374151;
            font-weight: 500;
        }
        .fc-daygrid-day.fc-day-today {
            background-color: #eff6ff !important;
            border-color: #3b82f6 !important;
        }
        .fc-event {
            border: none !important;
            border-radius: 6px !important;
            font-size: 0.7rem !important;  /* Smaller text */
            font-weight: 600 !important;
            padding: 2px 4px !important;
            margin-bottom: 2px !important;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
            cursor: pointer;
            transition: transform 0.1s, box-shadow 0.1s;
            max-height: 1.5em;  /* Prevent overflow */
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }
        .fc-event:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15) !important;
        }
        .fc-timegrid-col-header-cell .fc-timegrid-col-header-cell-cushion {
            font-weight: 600;
            color: #1f2937;
            background-color: #f9fafb;
            padding: 8px;
            border-radius: 4px;
        }
        .fc-timegrid-event {
            border-radius: 6px !important;
            font-size: 0.75rem !important;
            padding: 2px 4px !important;
        }
        .fc-timegrid-slot {
            height: 1.5rem;
        }
        .fc-timegrid-col {
            border-left: 1px solid #e5e7eb;
        }
        .fc .fc-daygrid-day-bg,
        .fc .fc-timegrid-slot-lane {
            background-color: #f9fafb;
        }
        .fc .fc-today-button {
            background-color: #3b82f6 !important;
            border-color: #2563eb !important;
            color: white !important;
        }

        /* Cleaner calendar cells */
        .fc-daygrid-day { height: 80px; }  /* Even rows */
        .fc-daygrid-event-dot { display: none; }  /* Hide dots, use colors instead */
        .fc .fc-daygrid-day-number { font-size: 0.875rem; font-weight: 600; }

        /* Mobile: Compact more */
        @media (max-width: 768px) {
            .fc-daygrid-day { height: 60px; }
            .fc-event { font-size: 0.65rem !important; }
            #calendar { font-size: 0.85em; }
        }

        /* Notification Dropdown Styles */
        #notificationDropdown {
            right: 0;
        }
        .notification-item {
            transition: background-color 0.2s;
        }
        .notification-item:hover {
            background-color: #f9fafb;
        }
        .status-badge-inprogress { @apply bg-blue-50 text-blue-700 border border-blue-200; }
        .status-badge-resolved { @apply bg-green-50 text-green-700 border border-green-200; }
        .status-badge-closed { @apply bg-gray-100 text-gray-700 border border-gray-200; }
    </style>
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
                            <p class="text-xs text-gray-500">Customer Portal</p>
                        </div>
                    </div>
                </div>
                <nav class="flex-1 py-2 px-4 space-y-2">
                    <a href="dashboard.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-blue-600 bg-blue-50 border border-blue-200 transition duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                        </svg>
                        Dashboard
                    </a>
                    <a href="complaints.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition duration-200">
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

        <div class="flex-1">
            <header class="header-2025 sticky top-0 z-20">
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4"></div>
                        <div class="flex items-center space-x-4">
                            <div class="relative" id="notificationContainer">
                                <button class="p-2 text-gray-600 hover:text-gray-900 transition-all duration-200 rounded-full hover:bg-gray-100 group" id="notificationBtn">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0M3.124 7.5A8.969 8.969 0 0 1 5.292 3m13.416 0a8.969 8.969 0 0 1 2.168 4.5" />
                                    </svg>
                                    <div id="notificationBadge" class="notification-badge" style="display: <?php echo $notif_count > 0 ? 'block' : 'none'; ?>;">
                                        <?php echo $notif_count; ?>
                                    </div>
                                </button>
                                <!-- Notification Dropdown -->
                                <div id="notificationDropdown" class="hidden absolute right-0 top-full mt-2 w-80 bg-white rounded-xl shadow-lg border border-gray-100 py-2 z-30 max-h-96 overflow-y-auto">
                                    <div id="notificationList">
                                        <?php if (empty($notifications)): ?>
                                            <div class="p-4 text-center text-gray-500">
                                                <i class="fas fa-bell-slash text-2xl mb-2 block"></i>
                                                <p class="text-sm">No updates from Admin yet.</p>
                                            </div>
                                        <?php else: ?>
                                            <?php foreach ($notifications as $notif): ?>
                                                <?php
                                                $status_badge_class = '';
                                                if ($notif['status'] === 'In Progress') $status_badge_class = 'status-badge-inprogress';
                                                elseif ($notif['status'] === 'Resolved') $status_badge_class = 'status-badge-resolved';
                                                elseif ($notif['status'] === 'Closed') $status_badge_class = 'status-badge-closed';
                                                $message_snippet = substr($notif['message'], 0, 80) . (strlen($notif['message']) > 80 ? '...' : '');
                                                ?>
                                                <a href="complaints.php?complaint_id=<?php echo (int)$notif['complaint_id']; ?>" 
                                                   class="notification-item block p-4 border-b border-gray-100 last:border-b-0"
                                                   data-id="<?php echo (int)$notif['id']; ?>"
                                                   data-type="<?php echo e($notif['type']); ?>">
                                                    <div class="flex justify-between items-start">
                                                        <div class="flex-1 min-w-0">
                                                            <h4 class="text-sm font-semibold text-gray-900 truncate mb-1">Complaint #<?php echo (int)$notif['complaint_id']; ?> - <?php echo e($notif['category']); ?></h4>
                                                            <p class="text-xs text-gray-600 mb-2 line-clamp-2 italic"><?php echo e($message_snippet); ?></p>
                                                            <span class="inline-block px-2 py-1 text-xs font-medium rounded-full <?php echo $status_badge_class; ?>"><?php echo e($notif['status']); ?></span>
                                                        </div>
                                                        <div class="ml-2 flex-shrink-0">
                                                            <small class="text-xs text-gray-500 block text-right"><?php echo date('M j, Y', strtotime($notif['timestamp'])); ?></small>
                                                            <small class="text-xs text-gray-400 block text-right"><?php echo date('g:i A', strtotime($notif['timestamp'])); ?></small>
                                                        </div>
                                                    </div>
                                                </a>
                                            <?php endforeach; ?>
                                            <?php if (count($notifications) >= 10): ?>
                                                <div class="p-4 text-center border-t border-gray-100">
                                                    <a href="complaints.php" class="text-sm text-blue-600 hover:underline">View All Updates</a>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

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

            <main class="p-6 space-y-6">
                <!-- KPIs Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-6">
                    <div class="card p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Total Complaints</p>
                                <p class="text-3xl font-bold text-gray-900"><?php echo $kpi_total; ?></p>
                            </div>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 text-red-500">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                            </svg>
                        </div>
                    </div>
                    <div class="card p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Pending Complaints</p>
                                <p class="text-3xl font-bold text-gray-900"><?php echo $kpi_pending; ?></p>
                            </div>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 text-yellow-500">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                    </div>
                    <div class="card p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">In Progress</p>
                                <p class="text-3xl font-bold text-gray-900"><?php echo $kpi_progress; ?></p>
                            </div>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 text-blue-600">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 00 13.803-3.7M4.031 9.865a8.25 8.25 0 01 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                            </svg>
                        </div>
                    </div>
                    <div class="card p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Resolved/Closed</p>
                                <p class="text-3xl font-bold text-gray-900"><?php echo $kpi_resolved; ?></p>
                            </div>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 text-green-600">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0Z" />
                            </svg>
                        </div>
                    </div>
                    <div class="card p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Avg. Resolution Time</p>
                                <p class="text-3xl font-bold text-gray-900"><?php echo e($avg_resolution_hours); ?> hrs</p>
                            </div>
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 text-gray-900">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0Z" />
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Announcements & Calendar Tabbed Section -->
                <div class="card">
                    <div class="border-b border-gray-200">
                        <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                            <button id="announcements-tab" class="tab-button flex-1 py-4 px-1 border-b-2 font-medium text-sm rounded-t-lg focus:outline-none transition-colors <?php echo !empty($announcements) ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-2 text-blue-600">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 0 8.835-2.535m0 0A23.74 23.74 0 0 0 18.795 3m.38 1.125a23.91 23.91 0 0 1 1.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 0 0 1.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 0 1 0 3.46" />
                                </svg>
                                Announcements
                            </button>
                            <button id="calendar-tab" class="tab-button flex-1 py-4 px-1 border-b-2 font-medium text-sm rounded-t-lg focus:outline-none transition-colors <?php echo empty($announcements) ? 'border-purple-500 text-purple-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-2 text-purple-600">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                                </svg>
                                Calendar
                            </button>
                        </nav>
                    </div>
                    <!-- Announcements Tab Content -->
                    <div id="announcements-content" class="tab-content <?php echo empty($announcements) ? 'hidden' : ''; ?>">
                        <?php if (!empty($announcements)): ?>
                            <div class="divide-y divide-gray-200 max-h-96 overflow-y-auto">
                                <?php foreach ($announcements as $ann): ?>
                                    <div class="p-6 hover:bg-gray-50 transition-colors">
                                        <div class="flex items-start space-x-4">
                                            <?php if ($ann['image_path']): ?>
                                                <img src="../<?php echo e($ann['image_path']); ?>" alt="Announcement Image" onclick="viewImage(this.src)" class="w-16 h-16 rounded-lg object-cover flex-shrink-0 border border-gray-200 cursor-pointer hover:opacity-80 transition-opacity">
                                            <?php else: ?>
                                                <div class="w-16 h-16 bg-gray-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                                    <i class="fas fa-bullhorn text-gray-400 text-xl"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div class="flex-1 min-w-0">
                                                <h3 class="text-sm font-semibold text-gray-900 mb-1"><?php echo e($ann['title']); ?></h3>
                                                <p class="text-sm text-gray-600 mb-2 line-clamp-2"><?php echo e($ann['description']); ?></p>
                                                <?php if ($ann['affected_areas']): ?>
                                                    <p class="text-xs text-blue-600 mb-2">Affected: <?php echo e($ann['affected_areas']); ?></p>
                                                <?php endif; ?>
                                                <div class="flex items-center justify-between text-xs text-gray-500">
                                                    <span title="<?php echo e($ann['formatted_range']); ?>"><?php echo e($ann['formatted_range']); ?></span>
                                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?php 
                                                        echo $ann['status'] === 'Ongoing' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; 
                                                    ?>">
                                                        <?php echo e($ann['status']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="p-6 text-center">
                                <i class="fas fa-bullhorn text-gray-400 text-4xl mb-4"></i>
                                <h3 class="text-lg font-semibold text-gray-900 mb-2">No Announcements</h3>
                                <p class="text-gray-500">Check back later for updates.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <!-- Calendar Tab Content -->
                    <div id="calendar-content" class="tab-content <?php echo !empty($announcements) ? 'hidden' : ''; ?>">
                        <div class="p-6">
                            <?php if (empty($announcements)): ?>
                                <div class="text-center py-8">
                                    <i class="fas fa-calendar-times text-gray-400 text-4xl mb-4"></i>
                                    <h3 class="text-lg font-semibold text-gray-900 mb-2">No Scheduled Events</h3>
                                    <p class="text-gray-500">Your service area is running smoothly. Check back for updates.</p>
                                </div>
                            <?php endif; ?>
                            <div id='calendar'></div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Image View Modal -->
    <div id="imageModal" class="hidden fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50 p-4">
        <div class="relative bg-white rounded-xl shadow-lg max-w-4xl w-full max-h-[90vh] overflow-hidden">
            <button onclick="closeModal('imageModal')" class="absolute top-4 right-4 z-10 text-white hover:text-gray-200 text-2xl font-bold">
                <i class="fas fa-times"></i>
            </button>
            <img id="imageModalImg" src="" alt="Full Image" class="w-full h-[90vh] object-contain mx-auto">
        </div>
    </div>

    <button id="mobileMenuToggle" class="fixed top-4 left-4 z-40 p-2 rounded-lg text-gray-600 bg-white shadow-lg md:hidden">
        <i class="fas fa-bars text-lg"></i>
    </button>
    <div id="profileDropdownMenu" class="hidden absolute right-6 top-20 w-48 bg-white rounded-xl shadow-lg border border-gray-100 py-2 z-30"></div>

    <!-- SWEETALERT WELCOME MODAL -->
    <script>
        <?php if (isset($_SESSION['welcome_message'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Welcome!',
                html: '<p class="text-lg font-medium text-gray-700"><?= $_SESSION['welcome_message'] ?></p>',
                showConfirmButton: true,
                confirmButtonText: 'Continue',
                confirmButtonColor: '#3b82f6',
                allowOutsideClick: false,
                timer: 4000,
                timerProgressBar: true,
                customClass: {
                    popup: 'animate__animated animate__fadeInDown animate__faster',
                    confirmButton: 'btn-primary px-6 py-2 rounded-lg font-medium'
                },
                buttonsStyling: false
            }).then(() => {});

            <?php unset($_SESSION['welcome_message']); ?>
        <?php endif; ?>
    </script>

    <script>
        let calendarInstance = null; // Global reference to calendar instance

        // Notification Dropdown Functionality
        const notificationBtn = document.getElementById('notificationBtn');
        const notificationDropdown = document.getElementById('notificationDropdown');
        const notificationContainer = document.getElementById('notificationContainer');
        const notificationBadge = document.getElementById('notificationBadge');
        const notificationList = document.getElementById('notificationList');

        notificationBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            this.style.transform = 'scale(0.95)';
            setTimeout(() => this.style.transform = 'scale(1)', 150);
            if (notificationDropdown.classList.contains('hidden')) {
                notificationDropdown.classList.remove('hidden');
                // Position dropdown
                const rect = notificationBtn.getBoundingClientRect();
                notificationDropdown.style.right = '0';
                notificationDropdown.style.top = `${rect.bottom + 8}px`;
            } else {
                notificationDropdown.classList.add('hidden');
            }
        });

        // Mark notification as read on click
        notificationList.addEventListener('click', function(e) {
            const notificationItem = e.target.closest('.notification-item');
            if (notificationItem) {
                e.preventDefault();
                const id = parseInt(notificationItem.dataset.id);
                const type = notificationItem.dataset.type;
                const href = notificationItem.getAttribute('href');
                if (id && type && href) {
                    // AJAX to mark as read
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `mark_read=1&id=${id}&type=${type}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Remove item from list
                            notificationItem.remove();
                            // Update count
                            let currentCount = parseInt(notificationBadge.textContent) - 1;
                            notificationBadge.textContent = currentCount;
                            if (currentCount <= 0) {
                                notificationBadge.style.display = 'none';
                                // Show no notifications if list empty
                                if (notificationList.children.length === 0) {
                                    notificationList.innerHTML = `
                                        <div class="p-4 text-center text-gray-500">
                                            <i class="fas fa-bell-slash text-2xl mb-2 block"></i>
                                            <p class="text-sm">No updates from Admin yet.</p>
                                        </div>
                                    `;
                                }
                            }
                            // Navigate after marking
                            window.location.href = href;
                        } else {
                            // If fail, still navigate
                            window.location.href = href;
                            if (data.msg) {
                                Swal.fire({
                                    icon: 'warning',
                                    title: 'Notice',
                                    text: data.msg,
                                    timer: 2000,
                                    showConfirmButton: false
                                });
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error marking as read:', error);
                        // Still navigate on error
                        window.location.href = href;
                    });
                } else {
                    window.location.href = href;
                }
            }
        });

        // Hide dropdown on outside click
        document.addEventListener('click', function(e) {
            if (!notificationContainer.contains(e.target)) {
                notificationDropdown.classList.add('hidden');
            }
        });

        // Tab switching functionality
        document.getElementById('announcements-tab').addEventListener('click', function() {
            document.getElementById('announcements-content').classList.remove('hidden');
            document.getElementById('calendar-content').classList.add('hidden');
            this.classList.add('border-blue-500', 'text-blue-600');
            this.classList.remove('border-transparent', 'text-gray-500', 'border-purple-500', 'text-purple-600');
            document.getElementById('calendar-tab').classList.add('border-transparent', 'text-gray-500');
            document.getElementById('calendar-tab').classList.remove('border-purple-500', 'text-purple-600', 'border-blue-500', 'text-blue-600');
        });

        document.getElementById('calendar-tab').addEventListener('click', function() {
            document.getElementById('calendar-content').classList.remove('hidden');
            document.getElementById('announcements-content').classList.add('hidden');
            this.classList.add('border-purple-500', 'text-purple-600');
            this.classList.remove('border-transparent', 'text-gray-500', 'border-blue-500', 'text-blue-600');
            document.getElementById('announcements-tab').classList.add('border-transparent', 'text-gray-500');
            document.getElementById('announcements-tab').classList.remove('border-blue-500', 'text-blue-600', 'border-purple-500', 'text-purple-600');
            
            // Trigger calendar resize if instance exists
            if (calendarInstance) {
                setTimeout(() => {
                    calendarInstance.updateSize();
                }, 100); // Small delay to ensure the tab is visible
            }
        });

        // Image view function
        function viewImage(src) {
            document.getElementById('imageModalImg').src = src;
            document.getElementById('imageModal').classList.remove('hidden');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
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
                <a href="../logout.php" class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors">
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

        // FullCalendar for Announcements
        document.addEventListener('DOMContentLoaded', function() {
            const calendarEl = document.getElementById('calendar');
            calendarInstance = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',  // Stick to month—hide week views
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: ''  // Remove view switcher to prevent clutter (no timeGridWeek)
                },
                displayEventTime: false,  // Hide times in month view for simplicity
                dayMaxEvents: 2,  // Show max 2 per day, + "more" link
                moreLinkText: 'more',  // Cleaner "more" button
                eventDisplay: 'block',
                height: 'auto',  // Auto-height for mobile, no fixed 600px
                dayMaxEventRows: 3,  // Limit rows per day to avoid overflow
                
                // Day styling (enhance readability)
                dayCellClassNames: function(arg) {
                    let classes = ['border-gray-200 hover:bg-gray-50 transition-colors p-1 rounded'];
                    if (arg.isToday) {
                        classes.push('bg-blue-50 border-2 border-blue-200');
                    }
                    if (arg.isWeekend) {
                        classes.push('bg-gray-50 opacity-80');  // Softer weekends
                    }
                    return classes;
                },
                dayHeaderClassNames: 'font-semibold text-gray-700 bg-gray-50 py-2 border-b border-gray-200',
                dayCellDidMount: function(info) {
                    info.el.style.height = '80px';  // Fixed height per cell for even grid, easier scan
                },
                
                // Events from PHP (update sa PHP part below for allDay logic)
                events: <?php echo json_encode(array_map(function($ann) {
                    $hasTime = !empty($ann['start_time']) || !empty($ann['end_time']);  // NEW: Detect if timed
                    $start_iso = $ann['start_date'] . ($hasTime ? 'T' . $ann['start_time'] : '');
                    $end_iso = $ann['end_date'] . ($hasTime ? 'T' . $ann['end_time'] : '');
                    $color = $ann['status'] === 'Ongoing' || stripos($ann['title'], 'Emergency') !== false ? '#10b981' : '#f59e0b';  // Green for emergency/ongoing, orange else
                    return [
                        'title' => $ann['title'],  // Keep short
                        'start' => $start_iso,
                        'end' => $end_iso,
                        'allDay' => !$hasTime,  // NEW: Auto all-day if no time—goes to top row
                        'description' => $ann['description'],
                        'affected_areas' => $ann['affected_areas'],
                        'status' => $ann['status'],
                        'image_path' => $ann['image_path'] ? '../' . $ann['image_path'] : null,
                        'backgroundColor' => $color,
                        'borderColor' => '#ffffff',
                        'textColor' => '#ffffff',
                        'classNames' => ['text-xs font-medium rounded shadow-sm px-1 py-0.5', $hasTime ? 'border-l-4 border-white' : '']  // Extra border for timed events
                    ];
                }, $announcements)); ?>,
                
                eventClick: function(info) {
                    // Enhanced popup—more concise
                    const areas = info.event.extendedProps.affected_areas ? `<p class="text-xs text-blue-600 mt-1">Affected: ${info.event.extendedProps.affected_areas}</p>` : '';
                    const img = info.event.extendedProps.image_path ? `<img src="${info.event.extendedProps.image_path}" alt="Img" class="mt-2 w-full max-w-xs rounded border">` : '';
                    Swal.fire({
                        title: `<span class="text-lg">${info.event.title}</span>`,
                        html: `
                            <div class="text-sm text-gray-700 mb-2">${info.event.extendedProps.description}</div>
                            ${areas}
                            <p class="text-xs text-gray-500 mt-2">Status: <span class="font-semibold text-${info.event.extendedProps.status === 'Ongoing' ? 'green' : 'yellow'}-600">${info.event.extendedProps.status}</span></p>
                            ${img}
                        `,
                        icon: 'info',
                        confirmButtonText: 'Got it',
                        confirmButtonColor: '#3b82f6',
                        width: '400px'  // Smaller popup for quick read
                    });
                }
            });
            calendarInstance.render();

            // If calendar tab is initially visible (no announcements), trigger updateSize just in case
            if (!document.getElementById('calendar-content').classList.contains('hidden')) {
                setTimeout(() => {
                    calendarInstance.updateSize();
                }, 100);
            }
        });
    </script>
</body>
</html>

<?php
mysqli_close($conn);
?>