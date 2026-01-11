<?php
// admin/includes/notifications.php
if (!isset($conn)) {
    die("Database connection not available.");
}

// Get current staff
$staff_id = $_SESSION['staff_id'];

// Get last_login
$last_login_query = "SELECT last_login FROM staff WHERE staff_id = ?";
$stmt = mysqli_prepare($conn, $last_login_query);
mysqli_stmt_bind_param($stmt, "i", $staff_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$staff_row = mysqli_fetch_assoc($result);
$last_login = $staff_row['last_login'] ?? null;

// Count new pending complaints since last login
if ($last_login) {
    $new_count_query = "SELECT COUNT(*) as new_count FROM complaints WHERE status = 'Pending' AND created_at > ?";
    $stmt = mysqli_prepare($conn, $new_count_query);
    mysqli_stmt_bind_param($stmt, "s", $last_login);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $new_count = mysqli_fetch_assoc($result)['new_count'];
} else {
    $new_count_query = "SELECT COUNT(*) as new_count FROM complaints WHERE status = 'Pending'";
    $result = mysqli_query($conn, $new_count_query);
    $new_count = mysqli_fetch_assoc($result)['new_count'];
}

// Fetch list of new complaints
$notifications = [];
if ($new_count > 0) {
    $notif_query = "SELECT c.complaint_id, c.category, c.created_at, 
                           CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) as user_name
                    FROM complaints c
                    LEFT JOIN users u ON c.user_id = u.id
                    WHERE c.status = 'Pending'";
    
    if ($last_login) {
        $notif_query .= " AND c.created_at > ?";
    }
    
    $notif_query .= " ORDER BY c.created_at DESC LIMIT 10";
    
    if ($last_login) {
        $stmt = mysqli_prepare($conn, $notif_query);
        mysqli_stmt_bind_param($stmt, "s", $last_login);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
    } else {
        $result = mysqli_query($conn, $notif_query);
    }

    while ($row = mysqli_fetch_assoc($result)) {
        $notifications[] = $row;
    }
}
?>