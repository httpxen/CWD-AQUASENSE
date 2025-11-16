<?php
session_name('AdminSession');
session_start();
include '../db/db.php';

// Session timeout (30 minutes)
$timeout_duration = 1800;
if (isset($_SESSION['STAFF_LAST_ACTIVITY']) && (time() - $_SESSION['STAFF_LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: ../admin_login.php?message=Session expired. Please log in again.");
    exit();
}

// Required session variables
if (!isset($_SESSION['staff_email']) || !isset($_SESSION['staff_role']) || !isset($_SESSION['staff_id'])) {
    header("Location: ../admin_login.php?message=Please log in.");
    exit();
}

// Update last activity
$_SESSION['STAFF_LAST_ACTIVITY'] = time();

// Role check: Must be Employee
if ($_SESSION['staff_role'] !== 'Employee') {
    header("Location: ../admin_login.php?message=Access denied. Employee role required.");
    exit();
}

// Set staff ID for use in queries
$staff_id = $_SESSION['staff_id'];
?>