<?php
// assign_complaint.php
include 'session_check.php';

header('Content-Type: application/json');

// CSRF Check
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !isset($_POST['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    echo json_encode(['success' => false, 'msg' => 'Invalid request. Please try again.']);
    exit();
}

// Validate complaint_id
if (!isset($_POST['complaint_id']) || !is_numeric($_POST['complaint_id'])) {
    echo json_encode(['success' => false, 'msg' => 'Invalid complaint ID.']);
    exit();
}

$complaint_id = (int)$_POST['complaint_id'];

// Validate staff_id[] array — must be a non-empty array of integers
if (
    !isset($_POST['staff_id']) ||
    !is_array($_POST['staff_id']) ||
    empty($_POST['staff_id'])
) {
    echo json_encode(['success' => false, 'msg' => 'Please select at least one staff member.']);
    exit();
}

// Sanitize each staff ID
$raw_staff_ids = $_POST['staff_id'];
$staff_ids = [];
foreach ($raw_staff_ids as $sid) {
    $sid = (int)$sid;
    if ($sid > 0) {
        $staff_ids[] = $sid;
    }
}

if (empty($staff_ids)) {
    echo json_encode(['success' => false, 'msg' => 'No valid staff selected.']);
    exit();
}

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

// Get old assignment for audit trail
$old_assign_sql = "SELECT s.name AS old_staff_name
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

// Loop through each selected staff and insert assignment
$assigned_names = [];
$failed_ids = [];

foreach ($staff_ids as $staff_id) {
    // Validate each staff member exists
    $staff_check_sql = "SELECT staff_id, name FROM staff WHERE staff_id = ?";
    $staff_check_stmt = mysqli_prepare($conn, $staff_check_sql);
    mysqli_stmt_bind_param($staff_check_stmt, "i", $staff_id);
    mysqli_stmt_execute($staff_check_stmt);
    $assigned_staff = mysqli_fetch_assoc(mysqli_stmt_get_result($staff_check_stmt));
    mysqli_stmt_close($staff_check_stmt);

    if (!$assigned_staff) {
        $failed_ids[] = $staff_id;
        continue;
    }

    // Insert assignment
    $assign_sql = "INSERT INTO complaint_assignments (complaint_id, staff_id) VALUES (?, ?)";
    $assign_stmt = mysqli_prepare($conn, $assign_sql);
    mysqli_stmt_bind_param($assign_stmt, "ii", $complaint_id, $staff_id);

    if (mysqli_stmt_execute($assign_stmt)) {
        $assigned_names[] = $assigned_staff['name'];

        // Audit log per staff
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
    } else {
        $failed_ids[] = $staff_id;
    }

    mysqli_stmt_close($assign_stmt);
}

// If at least one assignment succeeded, update complaint status to In Progress
if (!empty($assigned_names)) {
    $update_sql = "UPDATE complaints SET status = 'In Progress', updated_at = NOW() WHERE complaint_id = ?";
    $update_stmt = mysqli_prepare($conn, $update_sql);
    mysqli_stmt_bind_param($update_stmt, "i", $complaint_id);
    mysqli_stmt_execute($update_stmt);
    mysqli_stmt_close($update_stmt);

    $names_list = implode(', ', array_map('htmlspecialchars', $assigned_names));

    if (!empty($failed_ids)) {
        echo json_encode([
            'success' => true,
            'msg' => "Assigned to: {$names_list}. Some staff IDs could not be found: " . implode(', ', $failed_ids)
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'msg' => "Complaint successfully assigned to: {$names_list}!"
        ]);
    }
} else {
    echo json_encode(['success' => false, 'msg' => 'Failed to assign complaint. No valid staff could be processed.']);
}

mysqli_close($conn);
exit();
?>