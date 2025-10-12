<?php
session_start();

// Destroy the session
session_unset();
session_destroy();

// Redirect to admin login page with a success message
header("Location: admin_login.php?message=You have been logged out successfully.");
exit();
?>