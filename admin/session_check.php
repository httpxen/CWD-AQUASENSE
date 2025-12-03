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

// Update last activity (only if session is valid)
$_SESSION['STAFF_LAST_ACTIVITY'] = time();

// Role check (e.g., only Admin can access full dashboard)
if ($_SESSION['staff_role'] != 'Admin') {
    header("Location: ../admin_login.php?message=Access denied. Admin role required.");
    exit();
}
?>