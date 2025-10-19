<?php
session_name('AdminSession');
session_start();
include '../db/db.php'; // Adjust path if needed

// Check if staff session exists
if (!isset($_SESSION['staff_email']) || empty($_SESSION['staff_role'])) {
    header("Location: ../admin_login.php?message=Please log in as admin.");
    exit();
}

// Update last activity
$_SESSION['STAFF_LAST_ACTIVITY'] = time();

// Role check (e.g., only Admin can access full dashboard)
if ($_SESSION['staff_role'] != 'Admin') {
    header("Location: ../admin_login.php?message=Access denied. Admin role required.");
    exit();
}
?>