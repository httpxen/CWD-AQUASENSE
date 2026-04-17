<?php
// assign_complaint.php
include 'session_check.php';

header('Content-Type: application/json');

// CSRF Check
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'msg' => 'Invalid request. Please try again.']);
    exit();
}

if (!isset($_POST['complaint_id']) || !isset($_POST['staff_id']) || empty(trim($_POST['staff_id']))) {
    echo json_encode(['success' => false, 'msg' => 'Invalid input.']);
    exit();
}

$complaint_id = (int)$_POST['complaint_id'];
$staff_id = (int)$_POST['staff_id'];

// Get current staff (the one who is assigning)
$current_staff_id = $_SESSION['staff_id'] ?? null;
if (!$current_staff_id) {
    echo json_encode(['success' => false, 'msg' => 'Session error. Please login again.']);
    exit();
}

// Validate complaint exists
$check_sql = "SELECT complaint_id, status FROM complaints WHERE complaint_id = ?";
$check_stmt = mysqli_prepare($conn, $check_sql);
mysqli_stmt_bind_param($check_stmt, "i", $complaint_id);
mysqli_stmt_execute($check_stmt);
$complaint = mysqli_fetch_assoc(mysqli_stmt_get_result($check_stmt));
mysqli_stmt_close($check_stmt);

if (!$complaint) {
    echo json_encode(['success' => false, 'msg' => 'Complaint not found.']);
    exit();
}

// Validate staff exists
$staff_check_sql = "SELECT staff_id, name FROM staff WHERE staff_id = ?";
$staff_check_stmt = mysqli_prepare($conn, $staff_check_sql);
mysqli_stmt_bind_param($staff_check_stmt, "i", $staff_id);
mysqli_stmt_execute($staff_check_stmt);
$assigned_staff = mysqli_fetch_assoc(mysqli_stmt_get_result($staff_check_stmt));
mysqli_stmt_close($staff_check_stmt);

if (!$assigned_staff) {
    echo json_encode(['success' => false, 'msg' => 'Staff member not found.']);
    exit();
}

// ====================== GET OLD ASSIGNMENT (for audit) ======================
$old_assign_sql = "SELECT s.name as old_staff_name 
                   FROM complaint_assignments ca 
                   LEFT JOIN staff s ON ca.staff_id = s.staff_id 
                   WHERE ca.complaint_id = ? 
                   ORDER BY ca.id DESC LIMIT 1";
$old_stmt = mysqli_prepare($conn, $old_assign_sql);
mysqli_stmt_bind_param($old_stmt, "i", $complaint_id);
mysqli_stmt_execute($old_stmt);
$old_assign = mysqli_fetch_assoc(mysqli_stmt_get_result($old_stmt));
mysqli_stmt_close($old_stmt);

$old_staff_name = $old_assign['old_staff_name'] ?? 'Unassigned';

// ====================== INSERT NEW ASSIGNMENT ======================
$assign_sql = "INSERT INTO complaint_assignments (complaint_id, staff_id) VALUES (?, ?)";
$assign_stmt = mysqli_prepare($conn, $assign_sql);
mysqli_stmt_bind_param($assign_stmt, "ii", $complaint_id, $staff_id);

if (mysqli_stmt_execute($assign_stmt)) {
    
    // Optional: Auto-update status to 'In Progress'
    $update_sql = "UPDATE complaints SET status = 'In Progress', updated_at = NOW() WHERE complaint_id = ?";
    $update_stmt = mysqli_prepare($conn, $update_sql);
    mysqli_stmt_bind_param($update_stmt, "i", $complaint_id);
    mysqli_stmt_execute($update_stmt);
    mysqli_stmt_close($update_stmt);

    // ====================== AUDIT LOG ======================
    $details = "Assigned complaint #{$complaint_id} to {$assigned_staff['name']}";
    if ($old_staff_name !== 'Unassigned' && $old_staff_name !== $assigned_staff['name']) {
        $details .= " (Re-assigned from {$old_staff_name})";
    }

    $old_values = json_encode([
        'assigned_to' => $old_staff_name,
        'status'      => $complaint['status']
    ]);

    $new_values = json_encode([
        'assigned_to' => $assigned_staff['name'],
        'status'      => 'In Progress'
    ]);

    $audit_sql = "INSERT INTO audit_logs 
        (staff_id, action, entity_type, entity_id, details, old_values, new_values, created_at) 
        VALUES (?, 'assign', 'complaint', ?, ?, ?, ?, NOW())";

    $audit_stmt = mysqli_prepare($conn, $audit_sql);
    mysqli_stmt_bind_param($audit_stmt, "iisss", 
        $current_staff_id, 
        $complaint_id, 
        $details, 
        $old_values, 
        $new_values
    );
    mysqli_stmt_execute($audit_stmt);
    mysqli_stmt_close($audit_stmt);

    mysqli_stmt_close($assign_stmt);
    
    echo json_encode(['success' => true, 'msg' => 'Complaint assigned successfully to ' . htmlspecialchars($assigned_staff['name']) . '!']);
} else {
    mysqli_stmt_close($assign_stmt);
    echo json_encode(['success' => false, 'msg' => 'Failed to assign complaint. Please try again.']);
}

mysqli_close($conn);
exit();
?>