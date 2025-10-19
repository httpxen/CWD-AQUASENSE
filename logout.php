<?php
session_name('CustomerSession'); // Separate session for customers
session_start();

include 'db/db.php'; // Include database connection

// Update last_login to NULL or an old date for the user
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $update_query = "UPDATE users SET last_login = NULL WHERE id = ?";
    $stmt = mysqli_prepare($conn, $update_query);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

// Destroy the session
session_unset();
session_destroy();

// Redirect to login page with a success message
header("Location: login.php?message=You have been logged out successfully.");
exit();
?>