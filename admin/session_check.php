<?php
session_name('AdminSession');
session_start();
include '../db/db.php'; // Adjust path if needed

// Define timeout duration (in seconds, e.g., 30 minutes)
$timeout_duration = 1800; // Change this as needed (3600 for 1 hour)

// Check for session timeout
if (isset($_SESSION['STAFF_LAST_ACTIVITY']) && (time() - $_SESSION['STAFF_LAST_ACTIVITY'] > $timeout_duration)) {
    // Session expired due to inactivity
    session_unset();
    session_destroy();
    header("Location: ../admin_login.php?message=Session expired due to inactivity. Please log in again.");
    exit();
}

// Check if staff session exists
if (!isset($_SESSION['staff_email']) || empty($_SESSION['staff_role'])) {
    header("Location: ../admin_login.php?message=Please log in as admin.");
    exit();
}

// Fetch staff_id and update last_login in DB (for online status tracking) – NEW: Mirror SuperAdmin logic
$staff_email = $_SESSION['staff_email'];
$fetch_id_query = "SELECT staff_id FROM staff WHERE email = ?";
$stmt = mysqli_prepare($conn, $fetch_id_query);
mysqli_stmt_bind_param($stmt, "s", $staff_email);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$staff_row = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$staff_row) {
    session_unset();
    session_destroy();
    header("Location: ../admin_login.php?message=Invalid session. Please log in again.");
    exit();
}

$_SESSION['staff_id'] = $staff_row['staff_id']; // Set for convenience in other scripts

// Update last_login in DB – NEW: This keeps Admin "Online"
$update_activity_query = "UPDATE staff SET last_login = NOW() WHERE staff_id = ?";
$update_stmt = mysqli_prepare($conn, $update_activity_query);
mysqli_stmt_bind_param($update_stmt, "i", $_SESSION['staff_id']);
mysqli_stmt_execute($update_stmt);
mysqli_stmt_close($update_stmt);

// Update last activity in session (only if session is valid)
$_SESSION['STAFF_LAST_ACTIVITY'] = time();

// Role check (e.g., only Admin can access full dashboard)
if ($_SESSION['staff_role'] != 'Admin') {
    header("Location: ../admin_login.php?message=Access denied. Admin role required.");
    exit();
}
?>