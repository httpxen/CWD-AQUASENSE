<?php
session_name('AdminSession'); // Separate session for admins
session_start();

// Include database connection (adjust path if needed; assuming it's the same as in session_check.php)
require_once 'db/db.php';

if (isset($_SESSION['staff_id'])) {
    $staff_id = $_SESSION['staff_id'];
    
    // Set last activity to NULL to mark as offline
    $update_offline_query = "UPDATE staff SET last_login = NULL WHERE staff_id = ?";
    $update_stmt = mysqli_prepare($conn, $update_offline_query);
    mysqli_stmt_bind_param($update_stmt, "i", $staff_id);
    mysqli_stmt_execute($update_stmt);
    mysqli_stmt_close($update_stmt);
    
    mysqli_close($conn);
}

// Destroy the session
session_unset();
session_destroy();

// Redirect to admin login page with a success message
header("Location: admin_login.php?message=You have been logged out successfully.");
exit();
?>