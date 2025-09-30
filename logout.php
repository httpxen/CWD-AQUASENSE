<?php
session_start();

// Destroy the session
session_unset();
session_destroy();

// Redirect to login page with a success message
header("Location: login.php?message=You have been logged out successfully.");
exit();
?>