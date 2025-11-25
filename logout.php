<?php
ob_start();
include 'db/db.php';
session_name('CustomerSession');
session_start();

if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("UPDATE users SET remember_token = NULL, token_expiry = NULL, is_active_session = 0 WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
}

session_unset();
session_destroy();
setcookie('remember_me', '', time() - 3600, '/');

ob_end_clean();
header("Location: login.php?message=You have been logged out successfully.");
exit();
?>