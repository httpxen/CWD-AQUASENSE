<?php
// session_check.php
include '../db/db.php';
session_name('CustomerSession'); 
session_start();

$timeout_duration = 1800;

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php?message=Please log in to access the dashboard.");
    exit();
}

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: ../login.php?message=Session expired, please log in again.");
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();
?>