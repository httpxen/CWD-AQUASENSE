<?php
// update_status.php
include 'session_check.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'msg' => 'Invalid request.']);
    exit();
}

// Get staff_id
$staff_email = $_SESSION['staff_email'];
$staff_query = "SELECT staff_id FROM staff WHERE email = ?";
$stmt = mysqli_prepare($conn, $staff_query);
mysqli_stmt_bind_param($stmt, "s", $staff_email);
mysqli_stmt_execute($stmt);
$staff = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$staff_id = $staff['staff_id'] ?? null;
mysqli_stmt_close($stmt);

if (!$staff_id) {
    echo json_encode(['success' => false, 'msg' => 'Account not found.']);
    exit();
}

$complaint_id = (int)($_POST['complaint_id'] ?? 0);
$new_status   = trim($_POST['status'] ?? '');
$resolved_at_input = trim($_POST['resolved_at'] ?? '');
$action_due_input  = trim($_POST['action_due'] ?? '');
$comment_text = trim($_POST['comment_text'] ?? '');

$ALLOWED_STATUSES = ['Pending', 'In Progress', 'Resolved', 'Closed'];

if ($complaint_id <= 0 || !in_array($new_status, $ALLOWED_STATUSES, true)) {
    echo json_encode(['success' => false, 'msg' => 'Invalid input.']);
    exit();
}

// ====================== GET OLD VALUES ======================
$old_stmt = mysqli_prepare($conn, "SELECT status, action_due, resolved_at FROM complaints WHERE complaint_id = ?");
mysqli_stmt_bind_param($old_stmt, "i", $complaint_id);
mysqli_stmt_execute($old_stmt);
$old_data = mysqli_fetch_assoc(mysqli_stmt_get_result($old_stmt));
mysqli_stmt_close($old_stmt);

$old_status     = $old_data['status'] ?? 'Pending';
$old_action_due = $old_data['action_due'] ?? null;
$old_resolved   = $old_data['resolved_at'] ?? null;

// ====================== HANDLE DATES ======================
$resolved_at = null;
if (in_array($new_status, ['Resolved', 'Closed'])) {
    $resolved_at = !empty($resolved_at_input) 
        ? $resolved_at_input . ' ' . date('H:i:s') 
        : date('Y-m-d H:i:s');
}

$action_due = null;
if ($new_status === 'In Progress' && !empty($action_due_input)) {
    $action_due = $action_due_input;
}

// ====================== UPDATE COMPLAINT ======================
if ($new_status === 'In Progress') {
    $sql = "UPDATE complaints SET status = ?, action_due = ?, resolved_at = NULL, updated_at = NOW() WHERE complaint_id = ?";
    $types = "ssi";
    $params = [$new_status, $action_due, $complaint_id];
} elseif (in_array($new_status, ['Resolved', 'Closed'])) {
    $sql = "UPDATE complaints SET status = ?, resolved_at = ?, action_due = NULL, updated_at = NOW() WHERE complaint_id = ?";
    $types = "ssi";
    $params = [$new_status, $resolved_at, $complaint_id];
} else {
    $sql = "UPDATE complaints SET status = ?, action_due = NULL, resolved_at = NULL, updated_at = NOW() WHERE complaint_id = ?";
    $types = "si";
    $params = [$new_status, $complaint_id];
}

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
$success = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

if (!$success) {
    echo json_encode(['success' => false, 'msg' => 'Failed to update status.']);
    exit();
}

// ====================== SAVE COMMENT (if any) ======================
$comment_id = null;
if (!empty($comment_text)) {
    $comment_sql = "INSERT INTO complaint_comments (complaint_id, commenter_type, commenter_id, comment) 
                    VALUES (?, 'staff', ?, ?)";
    $cstmt = mysqli_prepare($conn, $comment_sql);
    mysqli_stmt_bind_param($cstmt, "iis", $complaint_id, $staff_id, $comment_text);
    mysqli_stmt_execute($cstmt);
    $comment_id = mysqli_insert_id($conn);
    mysqli_stmt_close($cstmt);
}

// ====================== LOG TO AUDIT LOGS ======================
$details = "Updated complaint status from '{$old_status}' to '{$new_status}'";

if ($new_status === 'In Progress' && $action_due) {
    $details .= " | Due: {$action_due}";
}
if (in_array($new_status, ['Resolved', 'Closed']) && $resolved_at) {
    $details .= " | Resolved: " . date('M j, Y g:i A', strtotime($resolved_at));
}
if (!empty($comment_text)) {
    $details .= " | Comment added: " . substr($comment_text, 0, 150) . (strlen($comment_text) > 150 ? '...' : '');
}

$old_values = json_encode([
    'status'     => $old_status,
    'action_due' => $old_action_due,
    'resolved_at'=> $old_resolved
]);

$new_values = json_encode([
    'status'     => $new_status,
    'action_due' => $action_due,
    'resolved_at'=> $resolved_at,
    'comment'    => !empty($comment_text) ? $comment_text : null
]);

$audit_sql = "INSERT INTO audit_logs 
    (staff_id, action, entity_type, entity_id, details, old_values, new_values, created_at) 
    VALUES (?, 'update', 'complaint', ?, ?, ?, ?, NOW())";

$audit_stmt = mysqli_prepare($conn, $audit_sql);
mysqli_stmt_bind_param($audit_stmt, "iisss", 
    $staff_id, 
    $complaint_id, 
    $details, 
    $old_values, 
    $new_values
);
mysqli_stmt_execute($audit_stmt);
mysqli_stmt_close($audit_stmt);

// ====================== RESPONSE ======================
echo json_encode([
    'success' => true, 
    'msg' => 'Complaint status updated successfully.' . (!empty($comment_text) ? ' Comment added.' : '')
]);

mysqli_close($conn);
exit();
?>