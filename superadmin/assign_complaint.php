<?php
// assign_complaint.php - SuperAdmin Version
include 'session_check.php'; // Handles DB connection and session validation

header('Content-Type: application/json');

// CSRF check (use hash_equals for security)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'msg' => 'Invalid request. Please try again.']);
    exit();
}

// Fetch staff_id and role using email from session
$staff_email = $_SESSION['staff_email'];
$staff_query = "SELECT staff_id, role FROM staff WHERE email = ?";
$stmt = mysqli_prepare($conn, $staff_query);
mysqli_stmt_bind_param($stmt, "s", $staff_email);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$staff = mysqli_fetch_assoc($result);
if (!$staff) {
    echo json_encode(['success' => false, 'msg' => 'Account not found.']);
    exit();
}
$staff_id = $staff['staff_id'];
$staff_role = $staff['role'];

// Role check: Only Admin or SuperAdmin allowed
$ALLOWED_ROLES = ['Admin', 'SuperAdmin'];
if (!in_array($staff_role, $ALLOWED_ROLES, true)) {
    echo json_encode(['success' => false, 'msg' => 'Access denied.']);
    exit();
}
mysqli_stmt_close($stmt);

if (!isset($_POST['complaint_id']) || !isset($_POST['staff_id']) || empty(trim($_POST['staff_id']))) {
    echo json_encode(['success' => false, 'msg' => 'Invalid input. Please try again.']);
    exit();
}

$complaint_id = (int)$_POST['complaint_id'];
$assigned_staff_id = (int)$_POST['staff_id'];

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

// Validate staff exists
$staff_check_sql = "SELECT staff_id FROM staff WHERE staff_id = ?";
$staff_check_stmt = mysqli_prepare($conn, $staff_check_sql);
mysqli_stmt_bind_param($staff_check_stmt, "i", $assigned_staff_id);
mysqli_stmt_execute($staff_check_stmt);
if (mysqli_stmt_get_result($staff_check_stmt)->num_rows === 0) {
    mysqli_stmt_close($staff_check_stmt);
    echo json_encode(['success' => false, 'msg' => 'Staff member not found.']);
    exit();
}
mysqli_stmt_close($staff_check_stmt);

// Insert new assignment (allows re-assignment by adding new record; queries use latest)
$assign_sql = "INSERT INTO complaint_assignments (complaint_id, staff_id) VALUES (?, ?)";
$assign_stmt = mysqli_prepare($conn, $assign_sql);
mysqli_stmt_bind_param($assign_stmt, "ii", $complaint_id, $assigned_staff_id);
if (mysqli_stmt_execute($assign_stmt)) {
    // Optional: Auto-update status to 'In Progress' on assignment
    $update_sql = "UPDATE complaints SET status = 'In Progress', updated_at = NOW() WHERE complaint_id = ?";
    $update_stmt = mysqli_prepare($conn, $update_sql);
    mysqli_stmt_bind_param($update_stmt, "i", $complaint_id);
    mysqli_stmt_execute($update_stmt);
    mysqli_stmt_close($update_stmt);
    
    mysqli_stmt_close($assign_stmt);
    echo json_encode(['success' => true, 'msg' => 'Complaint assigned successfully!']);
} else {
    mysqli_stmt_close($assign_stmt);
    echo json_encode(['success' => false, 'msg' => 'Failed to assign complaint. Please try again.']);
}

mysqli_close($conn);
?>