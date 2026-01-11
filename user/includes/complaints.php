<?php
date_default_timezone_set('Asia/Manila');

// === FIXED: FORCE MYSQL TO USE MANILA TIME (+08:00) ===
if (isset($conn)) {
    mysqli_query($conn, "SET time_zone = '+08:00'");
}

function formatLocalDate($db_time, $format = 'M d, Y h:i A') {
    if (empty($db_time)) {
        return null;
    }
    try {
        if ($db_time === 'now') {
            $dt = new DateTime('now');
        } else {
            $dt = new DateTime($db_time);
        }
        return $dt->format($format);
    } catch (Exception $e) {
        return $db_time; // Fallback
    }
}

// Helper for Avatar
function get_avatar_src($profile_picture, $name) {
    if ($profile_picture) {
        return '../' . $profile_picture;
    }
    return 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($name);
}

// Collect unique categories for filters
$unique_categories = [];
$unique_statuses = ['Pending', 'In Progress', 'Resolved', 'Closed'];

if (isset($list_res) && isset($total_rows) && $total_rows > 0) {
    mysqli_data_seek($list_res, 0); // Reset pointer
    while ($row = mysqli_fetch_assoc($list_res)) {
        if (!in_array($row['category'], $unique_categories)) {
            $unique_categories[] = $row['category'];
        }
    }
    mysqli_data_seek($list_res, 0); // Reset again for loop
}
?>

<div class="space-y-6">
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-4 sm:p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="relative flex-1 max-w-md">
                <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                <input type="text" id="globalSearch" placeholder="Search complaints..." class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-200">
            </div>
            
            <div class="flex flex-wrap gap-2 items-center">
                <div class="relative">
                    <select id="statusFilter" class="block appearance-none w-full bg-white border border-gray-300 hover:border-gray-400 px-4 py-2 pr-8 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">All Status</option>
                        <?php foreach ($unique_statuses as $status): ?>
                            <option value="<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($status); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700">
                        <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                            <path d="M9.293 12.95l.707.707L15.657 8l-1.414-1.414L10 10.828 5.757 6.586 4.343 8z" />
                        </svg>
                    </div>
                </div>
                
                <div class="relative">
                    <select id="categoryFilter" class="block appearance-none w-full bg-white border border-gray-300 hover:border-gray-400 px-4 py-2 pr-8 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">All Categories</option>
                        <?php foreach ($unique_categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700">
                        <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                            <path d="M9.293 12.95l.707.707L15.657 8l-1.414-1.414L10 10.828 5.757 6.586 4.343 8z" />
                        </svg>
                    </div>
                </div>
                
                <button id="clearFilters" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition duration-200">Clear</button>
            </div>
        </div>
    </div>

    <?php if ($total_rows === 0): ?>
        <div class="text-center py-12 text-gray-500 bg-white rounded-lg border border-gray-200">
            No complaints yet. Create your first one above.
        </div>
    <?php else: ?>
        <div id="complaintsContainer" class="space-y-4">
            <?php 
            mysqli_data_seek($list_res, 0); // Ensure pointer is at start
            while ($row = mysqli_fetch_assoc($list_res)): 
                // Fetch lat/lng for this complaint since not in main SELECT
                $loc_sql = "SELECT location_lat, location_lng FROM complaints WHERE complaint_id = ?";
                $stmt_loc = mysqli_prepare($conn, $loc_sql);
                mysqli_stmt_bind_param($stmt_loc, "i", $row['complaint_id']);
                mysqli_stmt_execute($stmt_loc);
                $loc_res = mysqli_stmt_get_result($stmt_loc);
                $loc = mysqli_fetch_assoc($loc_res);
                $lat = $loc['location_lat'] ?? null;
                $lng = $loc['location_lng'] ?? null;
                mysqli_stmt_close($stmt_loc);

                // Safe access to sentiment
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

                // Fetch comments for history timeline
                $comment_sql = "
                    SELECT cc.comment_id AS id, cc.created_at, cc.commenter_type, cc.commenter_id, cc.comment,
                           CASE 
                               WHEN cc.commenter_type = 'staff' THEN CONCAT(s.name, ' (Staff)')
                               WHEN cc.commenter_type = 'user' THEN CONCAT(u.first_name, ' ', u.last_name, ' (Customer)')
                           END AS commenter_name,
                           s.role AS staff_role, s.profile_picture AS staff_profile_picture,
                           u.profile_picture AS user_profile_picture
                    FROM complaint_comments cc
                    LEFT JOIN staff s ON cc.commenter_type = 'staff' AND cc.commenter_id = s.staff_id
                    LEFT JOIN users u ON cc.commenter_type = 'user' AND cc.commenter_id = u.id
                    WHERE cc.complaint_id = ?
                    ORDER BY cc.created_at ASC
                ";
                $comment_stmt = mysqli_prepare($conn, $comment_sql);
                mysqli_stmt_bind_param($comment_stmt, "i", $row['complaint_id']);
                mysqli_stmt_execute($comment_stmt);
                $comment_res = mysqli_stmt_get_result($comment_stmt);
                $comments = [];
                while ($comment = mysqli_fetch_assoc($comment_res)) {
                    $comments[] = $comment;
                }
                mysqli_stmt_close($comment_stmt);

                // Status badge
                $status_badge = 'bg-gray-100 text-gray-700';
                if ($row['status'] === 'Pending') $status_badge = 'bg-yellow-50 text-yellow-700 border border-yellow-200';
                if ($row['status'] === 'In Progress') $status_badge = 'bg-blue-50 text-blue-700 border border-blue-200';
                if ($row['status'] === 'Resolved') $status_badge = 'bg-green-50 text-green-700 border border-green-200';
                if ($row['status'] === 'Closed') $status_badge = 'bg-gray-100 text-gray-700 border border-gray-200';

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

                // Assigned display
                $assigned_badge = 'bg-purple-50 text-purple-700 border border-purple-200 inline-block';
                $assigned_display = '';
                if (!empty($row['staff_name'])) {
                    $avatar_src = get_avatar_src($row['staff_profile_picture'], $row['staff_name']);
                    $assigned_display = '
                    <div class="flex items-center space-x-2">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-400 flex-shrink-0">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                        </svg>
                        <img src="' . htmlspecialchars($avatar_src) . '" alt="Staff Avatar" class="w-6 h-6 rounded-full object-cover">
                        <div class="flex items-center space-x-2">
                            <div>
                                <p class="text-sm font-medium text-gray-900">' . htmlspecialchars($row['staff_name']) . '</p>
                                <p class="text-xs text-gray-500">' . htmlspecialchars($row['staff_role'] ?? 'N/A') . '</p>
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
                    // Use formatLocalDate to ensure timezone is correct
                    $due_local = formatLocalDate($row['action_due'], 'Y-m-d');
                    $days_until_due = (strtotime($due_local) - strtotime($current_date)) / (60 * 60 * 24);
                    
                    $due_class = 'bg-green-50 text-green-700 border border-green-200';
                    if ($days_until_due < 0) {
                        $due_class = 'bg-red-50 text-red-700 border border-red-200 animate-pulse';
                    } elseif ($days_until_due <= 3) {
                        $due_class = 'bg-yellow-50 text-yellow-700 border border-yellow-200';
                    }
                    $due_display = '
                    <span class="status-badge inline-block ' . $due_class . ' px-2 py-1 text-xs font-medium flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-3 h-3 mr-1 flex-shrink-0">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                        Due: ' . htmlspecialchars(formatLocalDate($row['action_due'], 'M d, Y')) . '
                    </span>';
                endif;

                // Category display
                $category_badge = 'bg-gray-50 text-gray-600 border border-gray-200';
                $category_display = '
                <div class="flex items-center space-x-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 28" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-400 flex-shrink-0">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M9 3.75H6.912a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H15M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859M12 3v8.25m0 0-3-3m3 3 3-3" />
                    </svg>
                    <p class="text-sm font-medium text-gray-800">' . htmlspecialchars($row['category']) . '</p>
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
                    if ($assign['assignment_status'] !== $previous_status) {
                        $status_history[] = [
                            'timestamp' => $assign['assigned_at'],
                            'status' => $assign['assignment_status'],
                            'event' => 'Status Changed',
                            'details' => 'Status changed from ' . $previous_status . ' to ' . $assign['assignment_status']
                        ];
                    }
                    $status_history[] = [
                        'timestamp' => $assign['assigned_at'],
                        'status' => $assign['assignment_status'],
                        'event' => 'Assigned to Staff',
                        'details' => [
                            'staff_name' => $assign['staff_name'],
                            'staff_role' => $assign['staff_role'] ?? 'Administrator',
                            'staff_profile_picture' => get_avatar_src($assign['staff_profile_picture'], $assign['staff_name'])
                        ]
                    ];
                    $previous_status = $assign['assignment_status'];
                }

                $last_assignment_status = end($assignments)['assignment_status'] ?? 'Pending';
                $expected_sequence = ['Pending', 'Assigned', 'In Progress', 'Resolved', 'Closed'];
                $current_index = array_search($last_assignment_status, $expected_sequence);
                if ($current_index !== false && $row['status'] !== $last_assignment_status) {
                    for ($i = $current_index + 1; $i < count($expected_sequence); $i++) {
                        if ($expected_sequence[$i] === 'Closed' && $row['status'] === 'Closed') {
                            $status_history[] = [
                                'timestamp' => $row['updated_at'],
                                'status' => 'Closed',
                                'event' => 'Status Changed',
                                'details' => 'Status changed from Resolved to Closed'
                            ];
                            break;
                        } elseif ($expected_sequence[$i] === $row['status']) {
                            $status_history[] = [
                                'timestamp' => $row['updated_at'],
                                'status' => $row['status'],
                                'event' => 'Status Changed',
                                'details' => 'Status changed from ' . $expected_sequence[$i - 1] . ' to ' . $row['status']
                            ];
                            break;
                        }
                    }
                }

                foreach ($comments as $comment) {
                    $profile_picture = $comment['commenter_type'] === 'staff' ? get_avatar_src($comment['staff_profile_picture'], $comment['commenter_name']) : get_avatar_src($comment['user_profile_picture'], $comment['commenter_name']);
                    $status_history[] = [
                        'timestamp' => $comment['created_at'],
                        'status' => $row['status'], 
                        'event' => 'Comment Added',
                        'details' => [
                            'commenter_name' => $comment['commenter_name'],
                            'commenter_type' => $comment['commenter_type'],
                            'comment_text' => $comment['comment'],
                            'profile_picture' => $profile_picture,
                            'role' => $comment['staff_role'] ?? 'Customer'
                        ]
                    ];
                }

                usort($status_history, function($a, $b) {
                    return strtotime($a['timestamp']) - strtotime($b['timestamp']);
                });
            ?>
                <div class="complaint-card" data-status="<?php echo htmlspecialchars($row['status']); ?>" data-category="<?php echo htmlspecialchars($row['category']); ?>" data-description="<?php echo htmlspecialchars(strtolower($row['description'])); ?>">
                    <button type="button" class="w-full p-4 flex justify-between items-center bg-gray-50 hover:bg-gray-100 focus:outline-none" onclick="this.nextElementSibling.classList.toggle('hidden'); this.querySelector('svg').classList.toggle('rotate-180');">
                        <div class="flex items-center space-x-3">
                            <h3 class="text-base font-semibold text-gray-900">Complaint #<?php echo (int)$row['complaint_id']; ?> - <?php echo htmlspecialchars($row['category']); ?></h3>
                            <span class="status-badge inline-block <?php echo $status_badge; ?>"><?php echo htmlspecialchars($row['status']); ?></span>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-500 transition-transform duration-200">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                        </svg>
                    </button>

                    <div class="hidden p-4 space-y-4">
                        <div class="complaint-header">
                            <div class="complaint-meta">
                                <?php echo $category_display; ?>
                                <?php echo $assigned_display; ?>
                            </div>
                            <div class="text-right flex flex-wrap justify-end items-center gap-2">
                            <?php if ($row['action_due']): ?>
                                <?php echo $due_display; ?>
                            <?php endif; ?>
                            <?php if (!empty($sentiment)): ?>
                                <span class="sentiment-badge inline-flex items-center gap-1 <?php echo $sentiment_badge; ?>">
                                    <?php echo $sentiment_icon; ?>
                                    <?php echo htmlspecialchars($sentiment); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        </div>
                        <div class="complaint-description">
                            <h4 class="text-sm font-medium text-gray-700 mb-1">Description</h4>
                            <p class="text-sm text-gray-600 bg-gray-50 p-3 rounded-md"><?php echo nl2br(htmlspecialchars($row['description'])); ?></p>
                            <?php if (!empty($row['attachment_path'])): ?>
                                <div class="mt-3">
                                    <a href="../Uploads/complaints/<?php echo htmlspecialchars($row['attachment_path']); ?>" target="_blank" class="text-blue-600 hover:underline text-sm inline-flex items-center">
                                        <i class="fas fa-paperclip mr-1"></i>View Attachment
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($row['location_address'])): ?>
                        <div class="location-section bg-gray-50 p-3 rounded-md">
                            <h4 class="text-sm font-medium text-gray-700 mb-2 flex items-center">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 mr-2 text-blue-600">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                                </svg>
                                Location
                            </h4>
                            <p class="text-sm text-gray-600 mb-3"><?php echo htmlspecialchars($row['location_address']); ?></p>
                            <?php if($lat && $lng): ?>
                            <button onclick="openMapModal(<?php echo (int)$row['complaint_id']; ?>, <?php echo (float)$lat; ?>, <?php echo (float)$lng; ?>, '<?php echo htmlspecialchars(addslashes($row['location_address'])); ?>')" class="bg-blue-50 hover:bg-blue-100 text-blue-700 px-3 py-1 rounded-lg text-sm font-medium flex items-center transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 mr-1">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
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
                                        // Determine dot color and icon based on event
                                        $dot_class = 'bg-gray-100';
                                        $icon_class = 'text-gray-600';
                                        $icon_path = 'M4.5 12.75l6 6 9-13.5'; // Default check
                                        if ($event['event'] === 'Complaint Created') {
                                            $dot_class = 'bg-yellow-100';
                                            $icon_class = 'text-yellow-600';
                                            $icon_path = 'M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621 0 1.125.504 1.125 1.125V18a2.25 2.25 0 0 1-2.25 2.25M16.5 7.5V18a2.25 2.25 0 0 0 2.25 2.25M16.5 7.5V4.875c0-.621-.504-1.125-1.125-1.125H4.125C3.504 3.75 3 4.254 3 4.875V18a2.25 2.25 0 0 0 2.25 2.25h13.5M6 7.5h3v3H6v-3Z';
                                        } elseif ($event['event'] === 'Status Changed') {
                                            $dot_class = $event['status'] === 'In Progress' ? 'bg-blue-100' : ($event['status'] === 'Resolved' ? 'bg-green-100' : 'bg-gray-100');
                                            $icon_class = $event['status'] === 'In Progress' ? 'text-blue-600' : ($event['status'] === 'Resolved' ? 'text-green-600' : 'text-gray-600');
                                            $icon_path = 'M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12Z';
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
                                            <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($event['event']); ?></p>
                                            <?php if ($event['event'] === 'Assigned to Staff' && isset($event['details']['staff_name'])): ?>
                                                <div class="flex items-center mt-1 space-x-2">
                                                    <img src="<?php echo htmlspecialchars($event['details']['staff_profile_picture']); ?>" alt="Staff Avatar" class="w-5 h-5 rounded-full object-cover">
                                                    <div>
                                                        <p class="text-xs text-gray-900"><?php echo htmlspecialchars($event['details']['staff_name']); ?></p>
                                                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($event['details']['staff_role']); ?></p>
                                                    </div>
                                                </div>
                                            <?php elseif ($event['event'] === 'Comment Added' && isset($event['details']['commenter_name'])): ?>
                                                <div class="flex items-center mt-1 space-x-2">
                                                    <img src="<?php echo htmlspecialchars($event['details']['profile_picture']); ?>" alt="Commenter Avatar" class="w-5 h-5 rounded-full object-cover">
                                                    <div>
                                                        <p class="text-xs text-gray-900"><?php echo htmlspecialchars($event['details']['commenter_name']); ?></p>
                                                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($event['details']['role']); ?></p>
                                                    </div>
                                                </div>
                                                <p class="text-sm text-gray-700 mt-2 italic bg-gray-50 p-2 rounded-md border-l-4 border-indigo-500"><?php echo nl2br(htmlspecialchars($event['details']['comment_text'])); ?></p>
                                            <?php endif; ?>
                                            <?php if ($event['event'] !== 'Comment Added'): ?>
                                                <p class="text-xs text-gray-500 mt-1"><?php echo is_array($event['details']) ? 'Status: ' . htmlspecialchars($event['status']) : htmlspecialchars($event['details']); ?></p>
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
                $base = 'complaints.php?' . http_build_query($qs);
                ?>
                <a href="<?php echo $base . '&page=1'; ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 text-sm <?php echo $page == 1 ? 'pointer-events-none opacity-50' : ''; ?>">« First</a>
                <a href="<?php echo $base . '&page=' . max(1, $page - 1); ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 text-sm <?php echo $page == 1 ? 'pointer-events-none opacity-50' : ''; ?>">‹ Prev</a>
                <span class="text-sm text-gray-600">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                <a href="<?php echo $base . '&page=' . min($total_pages, $page + 1); ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 text-sm <?php echo $page == $total_pages ? 'pointer-events-none opacity-50' : ''; ?>">Next ›</a>
                <a href="<?php echo $base . '&page=' . $total_pages; ?>" class="px-3 py-2 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 text-sm <?php echo $page == $total_pages ? 'pointer-events-none opacity-50' : ''; ?>">Last »</a>
            </div>
        </div>
    <?php endif; ?>

    <div id="mapModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50 p-4" onclick="closeMapModal()">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-5xl max-h-[90vh] overflow-hidden relative" onclick="event.stopPropagation()">
            <div class="p-6 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-xl font-semibold text-gray-900 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-2 text-blue-600">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                    </svg>
                    Complaint Location on Map
                </h3>
                <button onclick="closeMapModal()" class="text-gray-400 hover:text-gray-600 p-1 rounded-full hover:bg-gray-100 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div id="modalMap" style="height: 70vh; width: 100%;"></div>
        </div>
    </div>

    <p class="text-xs text-gray-400 mt-4">
        Note: When CWD assigns your ticket to a staff member, you’ll see the assignee’s name, role, and profile here.
        Status changes to <em>In Progress</em>, then <em>Resolved</em> or <em>Closed</em> when finished.
    </p>
</div>

<style>
    .complaint-card { 
        background: white; 
        border: 1px solid #e5e7eb; 
        border-radius: 0.75rem; 
        margin-bottom: 1rem; 
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1); 
        transition: all 0.2s ease; 
        overflow: hidden;
    }
    .complaint-card.hidden {
        display: none;
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
        word-break: break-word;
    }
    .location-section {
        border-left: 3px solid #3b82f6;
        padding-left: 1rem;
    }
    .status-badge, .sentiment-badge { 
        border-radius: 0.5rem; 
        padding: 0.25rem 0.5rem; 
        font-size: 0.75rem; 
        font-weight: 600; 
        border-width: 1px; 
    }
    .rotate-180 {
        transform: rotate(180deg);
    }
</style>

<script>
    // Client-side Filtering Logic
    document.addEventListener('DOMContentLoaded', function() {
        const container = document.getElementById('complaintsContainer');
        const cards = container ? container.querySelectorAll('.complaint-card') : [];
        const searchInput = document.getElementById('globalSearch');
        const statusFilter = document.getElementById('statusFilter');
        const categoryFilter = document.getElementById('categoryFilter');
        const clearBtn = document.getElementById('clearFilters');

        function filterCards() {
            const searchTerm = searchInput.value.toLowerCase();
            const selectedStatus = statusFilter.value;
            const selectedCategory = categoryFilter.value;

            cards.forEach(card => {
                const status = card.dataset.status.toLowerCase();
                const category = card.dataset.category.toLowerCase();
                const description = card.dataset.description;

                const matchesSearch = description.includes(searchTerm);
                const matchesStatus = !selectedStatus || status === selectedStatus.toLowerCase();
                const matchesCategory = !selectedCategory || category === selectedCategory.toLowerCase();

                if (matchesSearch && matchesStatus && matchesCategory) {
                    card.classList.remove('hidden');
                } else {
                    card.classList.add('hidden');
                }
            });
        }

        // Event Listeners
        if (searchInput) searchInput.addEventListener('input', filterCards);
        if (statusFilter) statusFilter.addEventListener('change', filterCards);
        if (categoryFilter) categoryFilter.addEventListener('change', filterCards);
        if (clearBtn) clearBtn.addEventListener('click', function() {
            searchInput.value = '';
            statusFilter.value = '';
            categoryFilter.value = '';
            filterCards();
        });

        // Initial filter
        filterCards();
    });

    // Map Modal Functions
    let currentMap;
    function openMapModal(complaintId, lat, lng, address) {
        const modal = document.getElementById('mapModal');
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden'; // Prevent body scroll

        const mapContainer = document.getElementById('modalMap');
        if (!currentMap) {
            currentMap = L.map('modalMap').setView([lat, lng], 16);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(currentMap);
        } else {
            currentMap.setView([lat, lng], 16);
            // Remove existing markers
            currentMap.eachLayer(function (layer) {
                if (layer instanceof L.Marker) {
                    currentMap.removeLayer(layer);
                }
            });
        }
        
        // Custom blue marker
        const customIcon = L.icon({
            iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-blue.png',
            shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
            iconSize: [25, 41],
            iconAnchor: [12, 41],
            popupAnchor: [1, -34],
            shadowSize: [41, 41]
        });

        L.marker([lat, lng], { icon: customIcon }).addTo(currentMap)
            .bindPopup(`<b>Complaint #${complaintId}</b><br>${address}`)
            .openPopup();
        
        // Force map resize check
        setTimeout(() => {
            currentMap.invalidateSize();
        }, 100);
    }

    function closeMapModal() {
        const modal = document.getElementById('mapModal');
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto'; // Restore body scroll
    }
</script>