<?php
// update_status.php
include 'session_check.php'; // Handles DB connection and session validation

// Set JSON header for AJAX responses
header('Content-Type: application/json');

// Session timeout check (consistent with manage_complaints.php)
$timeout_duration = 1800;
if (!isset($_SESSION['staff_id'])) {
    echo json_encode(['success' => false, 'msg' => 'Please log in to access this page.']);
    exit();
}
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    echo json_encode(['success' => false, 'msg' => 'Session expired.']);
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

// CSRF check
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'msg' => 'Invalid request. Please try again.']);
    exit();
}

// Sanitize and validate inputs
$complaint_id = (int)($_POST['complaint_id'] ?? 0);
$new_status = trim($_POST['status'] ?? '');
$resolved_at_input = trim($_POST['resolved_at'] ?? '');
$comment_text = trim($_POST['comment_text'] ?? '');

$ALLOWED_STATUSES = ['Pending', 'In Progress', 'Resolved', 'Closed'];
if ($complaint_id <= 0 || !in_array($new_status, $ALLOWED_STATUSES, true)) {
    echo json_encode(['success' => false, 'msg' => 'Invalid complaint or status. Please try again.']);
    exit();
}

// Handle resolved_at: Default to NOW() if Resolved/Closed and no input provided
$resolved_at = null;
if (in_array($new_status, ['Resolved', 'Closed'], true)) {
    if (!empty($resolved_at_input)) {
        // Input is date only (YYYY-MM-DD), add current time for DATETIME
        $resolved_at = $resolved_at_input . ' ' . date('H:i:s');
    } else {
        // Default to current datetime
        $resolved_at = date('Y-m-d H:i:s');
    }
}

// Validate complaint exists
$check_sql = "SELECT complaint_id FROM complaints WHERE complaint_id = ?";
$check_stmt = mysqli_prepare($conn, $check_sql);
mysqli_stmt_bind_param($check_stmt, "i", $complaint_id);
mysqli_stmt_execute($check_stmt);
if (mysqli_stmt_get_result($check_stmt)->num_rows === 0) {
    mysqli_stmt_close($check_stmt);
    echo json_encode(['success' => false, 'msg' => 'Complaint not found.']);
    exit();
}
mysqli_stmt_close($check_stmt);

// Update the complaint status and resolved_at (only if Resolved/Closed)
if (in_array($new_status, ['Resolved', 'Closed'], true)) {
    $sql = "UPDATE complaints SET status = ?, resolved_at = ?, updated_at = NOW() WHERE complaint_id = ?";
    $types = "ssi";
    $params = [$new_status, $resolved_at, $complaint_id];
} else {
    $sql = "UPDATE complaints SET status = ?, resolved_at = NULL, updated_at = NOW() WHERE complaint_id = ?";
    $types = "si";
    $params = [$new_status, $complaint_id];
}

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);

$success = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

if ($success) {
    // Save comment if provided (use 'comment' column as per schema)
    if (!empty($comment_text)) {
        $comment_sql = "INSERT INTO complaint_comments (complaint_id, commenter_type, commenter_id, comment) VALUES (?, 'staff', ?, ?)";
        $comment_stmt = mysqli_prepare($conn, $comment_sql);
        mysqli_stmt_bind_param($comment_stmt, "iis", $complaint_id, $_SESSION['staff_id'], $comment_text);
        $comment_success = mysqli_stmt_execute($comment_stmt);
        mysqli_stmt_close($comment_stmt);
        
        if (!$comment_success) {
            // Comment save failed, but status update succeeded - log error if needed
            error_log("Failed to save comment for complaint $complaint_id");
            echo json_encode(['success' => true, 'msg' => 'Status updated, but failed to add comment.']);
            exit();
        }
    }

    echo json_encode(['success' => true, 'msg' => 'Complaint status updated successfully.' . (!empty($comment_text) ? ' Comment added.' : '')]);
} else {
    echo json_encode(['success' => false, 'msg' => 'Failed to update complaint status. Please try again.']);
}

mysqli_close($conn);
exit();
?>