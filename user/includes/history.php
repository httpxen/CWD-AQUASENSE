<?php
// includes/history.php

$history_clauses = ["user_id = ?"];
$history_params = [$user_id];
$history_types = "i";

$history_where = "WHERE " . implode(" AND ", $history_clauses);

$history_sql = "
  SELECT complaint_id, category, description, status, sentiment, action_due, created_at, updated_at, attachment_path
  FROM complaints
  $history_where
  ORDER BY created_at DESC
";
$history_stmt = mysqli_prepare($conn, $history_sql);
mysqli_stmt_bind_param($history_stmt, $history_types, ...$history_params);
mysqli_stmt_execute($history_stmt);
$history_res = mysqli_stmt_get_result($history_stmt);
$history_total = mysqli_num_rows($history_res);
?>

<div class="space-y-4">
    <?php if ($history_total === 0): ?>
        <div class="text-center py-10 text-gray-500 bg-white rounded-lg border border-gray-200 shadow-sm">
            <p class="text-sm font-medium">No complaint history available yet.</p>
            <p class="text-sm">Submit a new complaint to start tracking.</p>
        </div>
    <?php else: ?>
        <!-- History List -->
        <div class="space-y-4">
            <?php while ($complaint = mysqli_fetch_assoc($history_res)): ?>
                <?php
                // Fetch assignments
                $assign_sql = "
                  SELECT ca.id, ca.assigned_at, ca.status AS assignment_status, s.name AS staff_name, 
                         s.role AS staff_role, s.profile_picture AS staff_profile_picture
                  FROM complaint_assignments ca
                  LEFT JOIN staff s ON s.staff_id = ca.staff_id
                  WHERE ca.complaint_id = ?
                  ORDER BY ca.assigned_at ASC
                ";
                $assign_stmt = mysqli_prepare($conn, $assign_sql);
                mysqli_stmt_bind_param($assign_stmt, "i", $complaint['complaint_id']);
                mysqli_stmt_execute($assign_stmt);
                $assign_res = mysqli_stmt_get_result($assign_stmt);
                $assignments = [];
                while ($assign = mysqli_fetch_assoc($assign_res)) {
                    $assignments[] = $assign;
                }
                mysqli_stmt_close($assign_stmt);

                // Status badge
                $status_badge = 'bg-gray-100 text-gray-700';
                if ($complaint['status'] === 'Pending') $status_badge = 'bg-yellow-100 text-yellow-800';
                if ($complaint['status'] === 'In Progress') $status_badge = 'bg-blue-100 text-blue-800';
                if ($complaint['status'] === 'Resolved') $status_badge = 'bg-green-100 text-green-800';
                if ($complaint['status'] === 'Closed') $status_badge = 'bg-gray-100 text-gray-700';

                // Sentiment badge
                $sentiment_badge = 'bg-gray-100 text-gray-600';
                $sentiment_icon = '';
                if ($complaint['sentiment'] === 'Positive') {
                    $sentiment_badge = 'bg-green-100 text-green-800';
                    $sentiment_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 mr-1"><path fill-rule="evenodd" d="M8.603 3.799A4.49 4.49 0 0 1 12 2.25c1.357 0 2.573.6 3.397 1.549a4.49 4.49 0 0 1 3.498 1.307 4.491 4.491 0 0 1 1.307 3.497A4.49 4.49 0 0 1 21.75 12a4.49 4.49 0 0 1-1.549 3.397 4.491 4.491 0 0 1-1.307 3.497 4.491 4.491 0 0 1-3.497 1.307A4.49 4.49 0 0 1 12 21.75a4.49 4.49 0 0 1-3.397-1.549 4.49 4.49 0 0 1-3.498-1.306 4.491 4.491 0 0 1-1.307-3.498A4.49 4.49 0 0 1 2.25 12c0-1.357.6-2.573 1.549-3.397a4.49 4.49 0 0 1 1.307-3.497 4.49 4.49 0 0 1 3.497-1.307Zm7.007 6.387a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z" clip-rule="evenodd" /></svg>';
                }
                if ($complaint['sentiment'] === 'Negative') {
                    $sentiment_badge = 'bg-red-100 text-red-800';
                    $sentiment_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 mr-1"><path fill-rule="evenodd" d="M5.47 5.47a.75.75 0 011.06 0L12 10.94l5.47-5.47a.75.75 0 111.06 1.06L13.06 12l5.47 5.47a.75.75 0 11-1.06 1.06L12 13.06l-5.47 5.47a.75.75 0 01-1.06-1.06L10.94 12 5.47 6.53a.75.75 0 010-1.06z" clip-rule="evenodd" /></svg>';
                }

                // Action due display
                $due_display = '';
                if ($complaint['action_due']) {
                    $current_date = date('Y-m-d');
                    $due_date = $complaint['action_due'];
                    $days_until_due = (strtotime($due_date) - strtotime($current_date)) / (60 * 60 * 24);
                    $due_class = 'bg-green-100 text-green-800';
                    if ($days_until_due <= 0) $due_class = 'bg-red-100 text-red-800 animate-pulse';
                    elseif ($days_until_due <= 3) $due_class = 'bg-yellow-100 text-yellow-800';
                    $due_display = '<span class="inline-flex items-center px-2.5 py-1 text-xs font-medium rounded-full ' . $due_class . '" title="Action due date"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 mr-1"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>' . htmlspecialchars(date('M d, Y', strtotime($due_date))) . '</span>';
                }

                // Attachment
                $attachment_html = '';
                if ($complaint['attachment_path']) {
                    $attachment_html = '<div class="mt-3"><a href="../Uploads/complaints/' . htmlspecialchars($complaint['attachment_path']) . '" target="_blank" class="text-blue-600 hover:underline text-sm inline-flex items-center"><i class="fas fa-paperclip mr-1"></i>View Attachment</a></div>';
                }

                // Build detailed status history
                $status_history = [];
                // Add creation event with initial Pending status
                $status_history[] = [
                    'timestamp' => $complaint['created_at'],
                    'status' => 'Pending',
                    'event' => 'Complaint Created',
                    'details' => 'Initial status: Pending'
                ];

                // Add assignment events with their statuses and detect status changes
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
                            'staff_profile_picture' => $assign['staff_profile_picture'] ? '../' . $assign['staff_profile_picture'] : 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($assign['staff_name'])
                        ]
                    ];
                    $previous_status = $assign['assignment_status'];
                }

                // Add intermediate status changes up to Resolved, then Closed
                $last_assignment_status = end($assignments)['assignment_status'] ?? 'Pending';
                $expected_sequence = ['Pending', 'Assigned', 'In Progress', 'Resolved', 'Closed'];
                $current_index = array_search($last_assignment_status, $expected_sequence);
                if ($current_index !== false && $complaint['status'] !== $last_assignment_status) {
                    for ($i = $current_index + 1; $i < count($expected_sequence); $i++) {
                        if ($expected_sequence[$i] === 'Closed' && $complaint['status'] === 'Closed') {
                            $status_history[] = [
                                'timestamp' => $complaint['updated_at'],
                                'status' => 'Closed',
                                'event' => 'Status Changed',
                                'details' => 'Status changed from Resolved to Closed'
                            ];
                            break;
                        } elseif ($expected_sequence[$i] === $complaint['status']) {
                            $status_history[] = [
                                'timestamp' => $complaint['updated_at'],
                                'status' => $complaint['status'],
                                'event' => 'Status Changed',
                                'details' => 'Status changed from ' . $expected_sequence[$i - 1] . ' to ' . $complaint['status']
                            ];
                            break;
                        }
                    }
                }

                // Sort status history by timestamp
                usort($status_history, function($a, $b) {
                    return strtotime($a['timestamp']) - strtotime($b['timestamp']);
                });
                ?>
                <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
                    <!-- Complaint Header (Collapsible Toggle) -->
                    <button type="button" class="w-full p-4 flex justify-between items-center bg-gray-50 hover:bg-gray-100 focus:outline-none" onclick="this.nextElementSibling.classList.toggle('hidden'); this.querySelector('svg').classList.toggle('rotate-180');">
                        <div class="flex items-center space-x-3">
                            <h3 class="text-base font-semibold text-gray-900">Complaint #<?php echo (int)$complaint['complaint_id']; ?> - <?php echo htmlspecialchars($complaint['category']); ?></h3>
                            <span class="inline-flex items-center px-2.5 py-1 text-xs font-medium rounded-full <?php echo $status_badge; ?>">
                                <?php echo $sentiment_icon; ?><?php echo htmlspecialchars($complaint['status']); ?>
                            </span>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-500 transition-transform duration-200">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                        </svg>
                    </button>

                    <!-- Complaint Details (Collapsible Content) -->
                    <div class="hidden p-4 space-y-4">
                        <div>
                            <h4 class="text-sm font-medium text-gray-700 mb-1">Description</h4>
                            <p class="text-sm text-gray-600 bg-gray-50 p-3 rounded-md"><?php echo nl2br(htmlspecialchars($complaint['description'])); ?></p>
                            <?php echo $attachment_html; ?>
                        </div>

                        <!-- Due Date Display -->
                        <?php if ($due_display): ?>
                            <div>
                                <h4 class="text-sm font-medium text-gray-700 mb-1">Due Date</h4>
                                <div class="flex flex-wrap gap-2">
                                    <?php echo $due_display; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div>
                            <h4 class="text-sm font-medium text-gray-700 mb-2">History Timeline</h4>
                            <div class="relative border-l-2 border-gray-200 ml-6">
                                <?php foreach ($status_history as $event): ?>
                                    <div class="mb-6 ml-6">
                                        <div class="absolute w-6 h-6 rounded-full flex items-center justify-center -left-3 <?php echo $event['status'] === 'Pending' ? 'bg-yellow-100' : ($event['status'] === 'In Progress' ? 'bg-blue-100' : ($event['status'] === 'Resolved' ? 'bg-green-100' : 'bg-gray-100')); ?>">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 <?php echo $event['status'] === 'Pending' ? 'text-yellow-600' : ($event['status'] === 'In Progress' ? 'text-blue-600' : ($event['status'] === 'Resolved' ? 'text-green-600' : 'text-gray-600')); ?>">
                                                <?php if ($event['event'] === 'Complaint Created'): ?>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621 0 1.125.504 1.125 1.125V18a2.25 2.25 0 0 1-2.25 2.25M16.5 7.5V18a2.25 2.25 0 0 0 2.25 2.25M16.5 7.5V4.875c0-.621-.504-1.125-1.125-1.125H4.125C3.504 3.75 3 4.254 3 4.875V18a2.25 2.25 0 0 0 2.25 2.25h13.5M6 7.5h3v3H6v-3Z" />
                                                <?php elseif ($event['event'] === 'Assigned to Staff'): ?>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12Z" />
                                                <?php else: ?>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                                <?php endif; ?>
                                            </svg>
                                        </div>
                                        <div class="mt-3 bg-white p-3 rounded-lg shadow-sm border border-gray-100">
                                            <p class="text-xs text-gray-400"><?php echo date('M d, Y h:i A', strtotime($event['timestamp'])); ?></p>
                                            <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($event['event']); ?></p>
                                            <?php if ($event['event'] === 'Assigned to Staff' && isset($event['details']['staff_name'])): ?>
                                                <div class="flex items-center mt-1 space-x-2">
                                                    <img src="<?php echo htmlspecialchars($event['details']['staff_profile_picture']); ?>" alt="Staff Avatar" class="w-5 h-5 rounded-full object-cover">
                                                    <div>
                                                        <p class="text-xs text-gray-900"><?php echo htmlspecialchars($event['details']['staff_name']); ?></p>
                                                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($event['details']['staff_role']); ?></p>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <p class="text-xs text-gray-500 mt-1"><?php echo is_array($event['details']) ? 'Status: ' . htmlspecialchars($event['status']) : htmlspecialchars($event['details']); ?></p>
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
</div>

<?php
mysqli_stmt_close($history_stmt);
?>