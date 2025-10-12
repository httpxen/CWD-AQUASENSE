<?php
// update_status.php
include 'session_check.php'; // Handles DB connection and session validation

// Session timeout check (consistent with manage_complaints.php)
$timeout_duration = 1800;
if (!isset($_SESSION['staff_id'])) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Please log in to access this page.'];
    header("Location: login.php");
    exit();
}
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Session expired.'];
    header("Location: login.php");
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

// CSRF check (use hash_equals for security against timing attacks)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid request. Please try again.'];
    header("Location: manage_complaints.php");
    exit();
}

// Sanitize and validate inputs
$complaint_id = (int)($_POST['complaint_id'] ?? 0);
$new_status = trim($_POST['status'] ?? '');
$action_due = !empty(trim($_POST['action_due'])) ? trim($_POST['action_due']) : null;

$ALLOWED_STATUSES = ['Pending', 'In Progress', 'Resolved', 'Closed'];
if ($complaint_id <= 0 || !in_array($new_status, $ALLOWED_STATUSES, true)) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid complaint or status. Please try again.'];
    header("Location: manage_complaints.php");
    exit();
}

// Validate complaint exists
$check_sql = "SELECT complaint_id FROM complaints WHERE complaint_id = ?";
$check_stmt = mysqli_prepare($conn, $check_sql);
mysqli_stmt_bind_param($check_stmt, "i", $complaint_id);
mysqli_stmt_execute($check_stmt);
if (mysqli_stmt_get_result($check_stmt)->num_rows === 0) {
    mysqli_stmt_close($check_stmt);
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Complaint not found.'];
    header("Location: manage_complaints.php");
    exit();
}
mysqli_stmt_close($check_stmt);

// Update the complaint status and action_due
$sql = "UPDATE complaints SET status = ?, action_due = ?, updated_at = NOW() WHERE complaint_id = ?";
$stmt = mysqli_prepare($conn, $sql);
$types = "ssi";
$params = [$new_status, $action_due, $complaint_id];
mysqli_stmt_bind_param($stmt, $types, ...$params);

if (mysqli_stmt_execute($stmt)) {
    mysqli_stmt_close($stmt);
    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Complaint status updated successfully.'];
} else {
    mysqli_stmt_close($stmt);
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Failed to update complaint status. Please try again.'];
}

header("Location: manage_complaints.php");
exit();
?>