<?php
// includes/complaints.php (Updated with Search & Filters, Sentiment Filter Removed)
// Assuming get_avatar_src function is available or include the helper
function get_avatar_src($profile_picture, $name) {
    if ($profile_picture) {
        return '../' . $profile_picture;
    }
    return 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($name);
}

// Collect unique categories for filters (server-side prep)
$unique_categories = [];
$unique_statuses = ['Pending', 'In Progress', 'Resolved', 'Closed']; // Hardcoded statuses, adjust if dynamic

if ($total_rows > 0) {
    mysqli_data_seek($list_res, 0); // Reset result pointer
    while ($row = mysqli_fetch_assoc($list_res)) {
        if (!in_array($row['category'], $unique_categories)) {
            $unique_categories[] = $row['category'];
        }
    }
    mysqli_data_seek($list_res, 0); // Reset again for loop
}
?>
<div class="space-y-6">
    <!-- Filters Header -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-4 sm:p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <!-- Search Bar -->
            <div class="relative flex-1 max-w-md">
                <svg class="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                <input type="text" id="globalSearch" placeholder="Search complaints..." class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition duration-200">
            </div>
            
            <!-- Filters -->
            <div class="flex flex-wrap gap-2 items-center">
                <!-- Status Filter -->
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
                
                <!-- Category Filter -->
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
                
                <!-- Clear Filters -->
                <button id="clearFilters" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition duration-200">Clear</button>
            </div>
        </div>
    </div>

    <?php if ($total_rows === 0): ?>
        <div class="text-center py-12 text-gray-500 bg-white rounded-lg border border-gray-200">
            No complaints yet. Create your first one above.
        </div>
    <?php else: ?>
        <!-- Results Container -->
        <div id="complaintsContainer" class="space-y-4">
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

                $assigned_badge = 'bg-purple-50 text-purple-700 border border-purple-200 inline-block';

                $assigned_text = !empty($row['staff_name']) ? $row['staff_name'] . ($row['staff_role'] ? ' (' . $row['staff_role'] . ')' : '') : 'Unassigned';

                $sentiment_icon = '';
                if ($row['sentiment'] === 'Positive') {
                    $sentiment_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 flex-shrink-0">
                                            <path fill-rule="evenodd" d="M8.603 3.799A4.49 4.49 0 0 1 12 2.25c1.357 0 2.573.6 3.397 1.549a4.49 4.49 0 0 1 3.498 1.307 4.491 4.491 0 0 1 1.307 3.497A4.49 4.49 0 0 1 21.75 12a4.49 4.49 0 0 1-1.549 3.397 4.491 4.491 0 0 1-1.307 3.497 4.491 4.491 0 0 1-3.497 1.307A4.49 4.49 0 0 1 12 21.75a4.49 4.49 0 0 1-3.397-1.549 4.49 4.49 0 0 1-3.498-1.306 4.491 4.491 0 0 1-1.307-3.498A4.49 4.49 0 0 1 2.25 12c0-1.357.6-2.573 1.549-3.397a4.49 4.49 0 0 1 1.307-3.497 4.49 4.49 0 0 1 3.497-1.307Zm7.007 6.387a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z" clip-rule="evenodd" />
                                       </svg>';
                } elseif ($row['sentiment'] === 'Negative') {
                    $sentiment_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 flex-shrink-0">
                                            <path fill-rule="evenodd" d="M5.47 5.47a.75.75 0 011.06 0L12 10.94l5.47-5.47a.75.75 0 111.06 1.06L13.06 12l5.47 5.47a.75.75 0 11-1.06 1.06L12 13.06l-5.47 5.47a.75.75 0 01-1.06-1.06L10.94 12 5.47 6.53a.75.75 0 010-1.06z" clip-rule="evenodd" />
                                       </svg>';
                }

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
                          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z" />
                        </svg>
                        <span class="text-gray-400 italic text-sm">Unassigned</span>
                    </div>';
                }

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
                        Due: ' . e(date('M d, Y', strtotime($due_date))) . '
                    </span>';
                endif;

                $category_badge = 'bg-gray-50 text-gray-600 border border-gray-200';
                $category_display = '
                <div class="flex items-center space-x-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 28" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-400 flex-shrink-0">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M9 3.75H6.912a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H15M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859M12 3v8.25m0 0-3-3m3 3 3-3" />
                    </svg>
                    <p class="text-sm font-medium text-gray-800">' . e($row['category']) . '</p>
                    <span class="status-badge ' . $category_badge . ' text-xs px-1 py-0.5">Category</span>
                </div>';
                ?>
                <div class="complaint-card" data-status="<?php echo htmlspecialchars($row['status']); ?>" data-category="<?php echo htmlspecialchars($row['category']); ?>" data-description="<?php echo htmlspecialchars(strtolower($row['description'])); ?>">
                    <div class="complaint-header">
                        <div class="complaint-meta">
                            <div class="flex items-center space-x-2">
                                <span class="font-mono text-sm font-semibold text-gray-700">#<?php echo (int)$row['complaint_id']; ?></span>
                                <span class="status-badge inline-block <?php echo $status_badge; ?>"><?php echo e($row['status']); ?></span>
                            </div>
                            <?php echo $category_display; ?>
                            <?php echo $assigned_display; ?>
                        </div>
                        <div class="text-right">
                            <?php echo $due_display; ?>
                            <?php if (!empty($row['sentiment'])): ?>
                                <span class="sentiment-badge inline-block <?php echo $sentiment_badge; ?> flex items-center gap-1"><?php echo $sentiment_icon; ?><?php echo e($row['sentiment']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="complaint-description">
                        <?php echo e($row['description']); ?>
                    </div>
                    <div class="complaint-footer">
                        <div class="flex flex-wrap gap-2">
                            <?php if (!empty($row['attachment_path'])): ?>
                                <a href="../uploads/complaints/<?php echo e($row['attachment_path']); ?>" target="_blank" class="text-blue-600 hover:text-blue-900 text-xs inline-flex items-center">
                                    <i class="fas fa-paperclip mr-1"></i>Attachment
                                </a>
                            <?php endif; ?>
                            <span class="text-xs text-gray-500">Created: <?php echo e(date('M d, Y', strtotime($row['created_at']))); ?></span>
                            <span class="text-xs text-gray-500">Updated: <?php echo e(date('M d, Y', strtotime($row['updated_at']))); ?></span>
                        </div>
                        <div class="complaint-actions">
                            <button onclick="openTrackModal(<?php echo (int)$row['complaint_id']; ?>, <?php echo json_encode([
                                'id'=>$row['complaint_id'],
                                'category'=>$row['category'],
                                'description'=>$row['description'],
                                'status'=>$row['status'],
                                'sentiment'=>$row['sentiment'],
                                'action_due'=>$row['action_due'] ? date('M d, Y', strtotime($row['action_due'])) : 'N/A',
                                'assigned'=>$assigned_text,
                                'attachment_path'=>$row['attachment_path'] ?? null,
                                'created_at'=>date('M d, Y h:i A', strtotime($row['created_at'])),
                                'updated_at'=>date('M d, Y h:i A', strtotime($row['updated_at'])),
                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)" class="bg-blue-50 text-blue-600 hover:bg-blue-100 px-2 py-1 rounded">
                                <i class="fas fa-route"></i> Track
                            </button>
                        </div>
                    </div>
                </div>
            <?php endwhile; mysqli_stmt_close($list_stmt); ?>
        </div>
    <?php endif; ?>

    <!-- Pagination -->
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
        padding: 1.5rem; 
        margin-bottom: 1rem; 
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1); 
        transition: all 0.2s ease; 
        opacity: 1;
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
    .complaint-actions button { 
        padding: 0.25rem 0.5rem; 
        border-radius: 0.375rem; 
        font-size: 0.75rem; 
        text-decoration: none; 
        transition: all 0.2s ease; 
    }
    .status-badge, .sentiment-badge { 
        border-radius: 0.5rem; 
        padding: 0.25rem 0.5rem; 
        font-size: 0.75rem; 
        font-weight: 600; 
        border-width: 1px; 
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
</script>