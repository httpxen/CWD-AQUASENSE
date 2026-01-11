<?php
include 'session_check.php'; // Handles DB, session validation, timeout, and updates STAFF_LAST_ACTIVITY

// Get staff_id from session (assuming set during login)
$staff_id = $_SESSION['staff_id'] ?? null;
if (!$staff_id) {
    header("Location: ../admin_login.php?message=Please log in as admin.");
    exit();
}

// Update last activity in database (repurposing last_login as last_activity for online status tracking)
$update_activity_query = "UPDATE staff SET last_login = NOW() WHERE staff_id = ?";
$update_stmt = mysqli_prepare($conn, $update_activity_query);
mysqli_stmt_bind_param($update_stmt, "i", $staff_id);
mysqli_stmt_execute($update_stmt);
mysqli_stmt_close($update_stmt);

// ---------------------------
// Include Email Service
// ---------------------------
require_once '../config/email.php';

// ---------------------------
// CSRF token
// --------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ---------------------------
// Helpers
// ---------------------------
function sanitize($value) {
    return trim($value ?? '');
}

function get_avatar_src($profile_picture, $name) {
    if ($profile_picture) {
        return '../' . $profile_picture;
    }
    return 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($name);
}

// ---------------------------
// Fetch current staff info
// ---------------------------
$current_staff_query = "SELECT staff_id, name, profile_picture, email, role, created_at FROM staff WHERE staff_id = ?";
$stmt = mysqli_prepare($conn, $current_staff_query);
mysqli_stmt_bind_param($stmt, "i", $staff_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$current_staff = mysqli_fetch_assoc($result);
if (!$current_staff) {
    session_unset();
    session_destroy();
    header("Location: ../admin_login.php?message=Account not found.");
    exit();
}

// Role check: Only SuperAdmin allowed
if ($current_staff['role'] !== 'SuperAdmin') {
    header("Location: ../admin_login.php?message=Access denied.");
    exit();
}
mysqli_stmt_close($stmt);

// ---------------------------
// Handle POST requests (AJAX)
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'msg' => 'Invalid CSRF token.']);
        exit();
    }

    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'get_staff') {
        $id = (int)($_POST['staff_id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'msg' => 'Invalid staff ID.']);
            exit();
        }
        $q = "SELECT staff_id, name, email, role, last_login FROM staff WHERE staff_id = ?";
        $stmt = mysqli_prepare($conn, $q);
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $staff = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if ($staff) {
            echo json_encode(['success' => true, 'staff' => $staff]);
        } else {
            echo json_encode(['success' => false, 'msg' => 'Staff not found.']);
        }
        exit();
    }

    elseif ($action === 'add') {
        $name = sanitize($_POST['name']);
        $email = sanitize($_POST['email']);
        $role = sanitize($_POST['role']);

        if (empty($name) || empty($email) || empty($role) || !in_array($role, ['SuperAdmin', 'Admin', 'Employee'])) {
            echo json_encode(['success' => false, 'msg' => 'Invalid data.']);
            exit();
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'msg' => 'Invalid email.']);
            exit();
        }

        $check_email = "SELECT staff_id FROM staff WHERE email = ?";
        $check_stmt = mysqli_prepare($conn, $check_email);
        mysqli_stmt_bind_param($check_stmt, "s", $email);
        mysqli_stmt_execute($check_stmt);
        if (mysqli_stmt_get_result($check_stmt)->num_rows > 0) {
            mysqli_stmt_close($check_stmt);
            echo json_encode(['success' => false, 'msg' => 'Email already in use.']);
            exit();
        }
        mysqli_stmt_close($check_stmt);

        // Generate temporary password (16 characters)
        $temp_password = bin2hex(random_bytes(8));
        $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);

        // Insert new staff
        $insert_query = "INSERT INTO staff (name, email, password, role, created_at, profile_picture) VALUES (?, ?, ?, ?, NOW(), NULL)";
        $insert_stmt = mysqli_prepare($conn, $insert_query);
        mysqli_stmt_bind_param($insert_stmt, "ssss", $name, $email, $hashed_password, $role);
        if (mysqli_stmt_execute($insert_stmt)) {
            $new_id = mysqli_insert_id($conn);

            // Send welcome email with temp password
            $emailService = new EmailService();
            $email_sent = $emailService->sendStaffWelcome($email, $name, $temp_password);

            $msg = 'Staff added successfully! ' . ($email_sent ? 'Welcome email sent with temporary credentials.' : 'Failed to send welcome email.');
            echo json_encode(['success' => true, 'msg' => $msg, 'staff_id' => $new_id]);
        } else {
            echo json_encode(['success' => false, 'msg' => 'Failed to add staff.']);
        }
        mysqli_stmt_close($insert_stmt);
        exit();
    }

    elseif ($action === 'edit') {
        $edit_id = (int)($_POST['staff_id'] ?? 0);
        $name = sanitize($_POST['name']);
        $email = sanitize($_POST['email']);
        $role = sanitize($_POST['role']);

        if ($edit_id <= 0 || empty($name) || empty($email) || empty($role)) {
            echo json_encode(['success' => false, 'msg' => 'Invalid data.']);
            exit();
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'msg' => 'Invalid email.']);
            exit();
        }

        $check_email = "SELECT staff_id FROM staff WHERE email = ? AND staff_id != ?";
        $check_stmt = mysqli_prepare($conn, $check_email);
        mysqli_stmt_bind_param($check_stmt, "si", $email, $edit_id);
        mysqli_stmt_execute($check_stmt);
        if (mysqli_stmt_get_result($check_stmt)->num_rows > 0) {
            mysqli_stmt_close($check_stmt);
            echo json_encode(['success' => false, 'msg' => 'Email already in use.']);
            exit();
        }
        mysqli_stmt_close($check_stmt);

        $update_fields = "name = ?, email = ?, role = ?";
        $params = [$name, $email, $role];
        $types = "sss";

        $new_profile_picture = null;
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $old_query = "SELECT profile_picture FROM staff WHERE staff_id = ?";
            $old_stmt = mysqli_prepare($conn, $old_query);
            mysqli_stmt_bind_param($old_stmt, "i", $edit_id);
            mysqli_stmt_execute($old_stmt);
            $old_result = mysqli_stmt_get_result($old_stmt);
            $old_staff = mysqli_fetch_assoc($old_result);
            if ($old_staff['profile_picture'] && file_exists('../' . $old_staff['profile_picture'])) {
                unlink('../' . $old_staff['profile_picture']);
            }
            mysqli_stmt_close($old_stmt);

            $upload_dir = '../assets/uploads/staff/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $file_name = uniqid() . '.' . $file_extension;
            $upload_path = $upload_dir . $file_name;
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                $new_profile_picture = 'assets/uploads/staff/' . $file_name;
                $update_fields .= ", profile_picture = ?";
                $params[] = $new_profile_picture;
                $types .= "s";
            }
        }

        $update_query = "UPDATE staff SET $update_fields WHERE staff_id = ?";
        $params[] = $edit_id;
        $types .= "i";
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, $types, ...$params);
        if (mysqli_stmt_execute($update_stmt)) {
            echo json_encode([
                'success' => true,
                'msg' => 'Staff updated successfully!',
                'profile_picture' => $new_profile_picture
            ]);
        } else {
            echo json_encode(['success' => false, 'msg' => 'Update failed.']);
        }
        mysqli_stmt_close($update_stmt);
        exit();
    }

    elseif ($action === 'delete') {
        $delete_id = (int)($_POST['staff_id'] ?? 0);
        if ($delete_id <= 0 || $delete_id === $staff_id) {
            echo json_encode(['success' => false, 'msg' => 'Cannot delete current user.']);
            exit();
        }

        $delete_assign = "DELETE FROM complaint_assignments WHERE staff_id = ?";
        $delete_assign_stmt = mysqli_prepare($conn, $delete_assign);
        mysqli_stmt_bind_param($delete_assign_stmt, "i", $delete_id);
        mysqli_stmt_execute($delete_assign_stmt);
        mysqli_stmt_close($delete_assign_stmt);

        $delete_query = "SELECT profile_picture FROM staff WHERE staff_id = ?";
        $delete_stmt = mysqli_prepare($conn, $delete_query);
        mysqli_stmt_bind_param($delete_stmt, "i", $delete_id);
        mysqli_stmt_execute($delete_stmt);
        $delete_result = mysqli_stmt_get_result($delete_stmt);
        $to_delete = mysqli_fetch_assoc($delete_result);
        if ($to_delete['profile_picture'] && file_exists('../' . $to_delete['profile_picture'])) {
            unlink('../' . $to_delete['profile_picture']);
        }
        mysqli_stmt_close($delete_stmt);

        $final_delete = "DELETE FROM staff WHERE staff_id = ?";
        $final_stmt = mysqli_prepare($conn, $final_delete);
        mysqli_stmt_bind_param($final_stmt, "i", $delete_id);
        if (mysqli_stmt_execute($final_stmt)) {
            echo json_encode(['success' => true, 'msg' => 'Staff deleted successfully!']);
        } else {
            echo json_encode(['success' => false, 'msg' => 'Delete failed.']);
        }
        mysqli_stmt_close($final_stmt);
        exit();
    } elseif ($action === 'update_activity') {
        $update_activity_query = "UPDATE staff SET last_login = NOW() WHERE staff_id = ?";
        $update_stmt = mysqli_prepare($conn, $update_activity_query);
        mysqli_stmt_bind_param($update_stmt, "i", $staff_id);
        $success = mysqli_stmt_execute($update_stmt);
        mysqli_stmt_close($update_stmt);
        echo json_encode(['success' => $success]);
        exit();
    } elseif ($action === 'get_all_staff') {
        $all_staff_query = "SELECT staff_id, name, profile_picture, email, role, created_at, last_login FROM staff ORDER BY CASE role WHEN 'SuperAdmin' THEN 1 WHEN 'Admin' THEN 2 WHEN 'Employee' THEN 3 END ASC, name ASC";
        $all_staff_result = mysqli_query($conn, $all_staff_query);
        $staff_list = [];
        while ($row = mysqli_fetch_assoc($all_staff_result)) {
            $is_online = !empty($row['last_login']) && strtotime($row['last_login']) > (time() - 300);
            $row['is_online'] = $is_online ? 'Online' : 'Offline';
            $row['status_class'] = $is_online ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
            $staff_list[] = $row;
        }
        echo json_encode(['success' => true, 'staff' => $staff_list]);
        exit();
    }

    echo json_encode(['success' => false, 'msg' => 'Invalid action.']);
    exit();
}

// ---------------------------
// Fetch all staff
// ---------------------------
$all_staff_query = "SELECT staff_id, name, profile_picture, email, role, created_at, last_login FROM staff ORDER BY CASE role WHEN 'SuperAdmin' THEN 1 WHEN 'Admin' THEN 2 WHEN 'Employee' THEN 3 END ASC, name ASC";
$all_staff_result = mysqli_query($conn, $all_staff_query);
$all_staff = [];
while ($row = mysqli_fetch_assoc($all_staff_result)) {
    $all_staff[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Manage Staff | CWD AquaSense SuperAdmin</title>
    <link rel="icon" type="image/png" href="../assets/icons/AquaSense2.png" />
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <link rel="stylesheet" href="css/manage_staff.css" />
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex">
        <!-- Sidebar -->
        <div class="sidebar w-64 bg-white shadow-lg fixed h-full z-30">
            <div class="flex flex-col h-full">
                <div class="p-6">
                    <div class="flex items-center space-x-3"> 
                        <img src="../assets/icons/AquaSense.png" alt="Logo" class="w-16 h-16 rounded-lg object-contain bg-white p-1">
                        <div class="flex-1">
                            <h1 class="text-xl font-bold text-gray-900">AquaSense</h1>
                            <p class="text-xs text-gray-500">SuperAdmin Portal</p>
                        </div>
                    </div>
                </div>
                <nav class="flex-1 py-2 px-4 space-y-2">
                    <a href="dashboard.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                        </svg>
                        Dashboard
                    </a>
                    <a href="manage_complaints.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        Manage Complaints
                    </a>
                    <a href="manage_staff.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-blue-600 bg-blue-50 border border-blue-200 transition duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                        </svg>
                        Manage Staff
                    </a>
                    <a href="manage_user.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                        </svg>
                        Manage Users
                    </a>
                    <a href="view_feedback.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 0 1 .778-.332 48.294 48.294 0 0 0 5.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                        </svg>
                        View Feedback
                    </a>
                    <a href="announcements.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 0 8.835-2.535m0 0A23.74 23.74 0 0 0 18.795 3m.38 1.125a23.91 23.91 0 0 1 1.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 0 0 1.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 0 1 0 3.46" />
                        </svg>
                        Announcement Section
                    </a>
                </nav>
                <div class="p-4 border-t border-gray-100">
                    <div class="flex items-center space-x-3 mb-4">
                        <div class="relative avatar-glow">
                            <img src="<?php echo htmlspecialchars(get_avatar_src($current_staff['profile_picture'], $current_staff['name'])); ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover"/>
                            <div class="absolute -bottom-0.5 -right-0.5 w-4 h-4 bg-green-500 border-2 border-white rounded-full animate-gentle-pulse"></div>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($current_staff['name']); ?></p>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($current_staff['role']); ?></p>
                        </div>
                    </div>
                    <a href="../admin_logout.php" class="w-full flex items-center justify-center px-4 py-2 text-sm font-medium text-red-600 bg-red-50 rounded-lg hover:bg-red-100 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-2 text-red-600">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                        </svg>
                        Sign Out
                    </a>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex-1">
            <header class="header-2025 sticky top-0 z-20">
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4"></div>
                        <div class="flex items-center space-x-4">
                            <!-- Profile Dropdown - SAME AS DASHBOARD -->
                            <div class="flex items-center space-x-3 p-2 profile-card hover:bg-gray-50 rounded-xl transition-all duration-200 group cursor-pointer relative" id="profileDropdown">
                                <div class="avatar-glow">
                                    <img src="<?php echo htmlspecialchars(get_avatar_src($current_staff['profile_picture'], $current_staff['name'])); ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover"/>
                                    <div class="absolute -bottom-0.5 -right-0.5 w-4 h-4 bg-green-500 border-2 border-white rounded-full animate-gentle-pulse"></div>
                                </div>
                                <div class="hidden md:block">
                                    <p class="text-sm font-semibold text-gray-900 truncate max-w-32"><?php echo htmlspecialchars($current_staff['name']); ?></p>
                                    <p class="text-xs text-gray-500 truncate max-w-32"><?php echo htmlspecialchars($current_staff['role']); ?></p>
                                </div>
                                <i class="fas fa-chevron-down text-gray-400 text-sm ml-1 transition-transform duration-200 group-hover:text-gray-600"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main class="p-6 space-y-6">
                <!-- Staff Table -->
                <div class="card p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-lg font-semibold text-gray-900">Staff Members</h2>
                        <button onclick="addStaff()" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors flex items-center space-x-2">
                            <i class="fas fa-envelope"></i>
                            <span>Invite Staff</span>
                        </button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Avatar</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200" id="staffTableBody">
                                <?php if (empty($all_staff)): ?>
                                    <tr><td colspan="7" class="px-6 py-4 text-center text-gray-500">No staff members found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($all_staff as $s): ?>
                                        <?php 
                                        $is_online = isset($s['last_login']) && $s['last_login'] && (strtotime($s['last_login']) > (time() - 300)); // 5 minutes threshold for "Online"
                                        ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <img src="<?php echo htmlspecialchars(get_avatar_src($s['profile_picture'], $s['name'])); ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover">
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($s['name']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($s['email']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-purple-100 text-purple-800">
                                                    <?php echo htmlspecialchars($s['role']); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $is_online ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                    <?php echo $is_online ? 'Online' : 'Offline'; ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo date('M j, Y', strtotime($s['created_at'])); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <button onclick="editStaff(<?php echo $s['staff_id']; ?>)" class="text-blue-600 hover:text-blue-900 mr-3">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button onclick="deleteStaff(<?php echo $s['staff_id']; ?>)" class="text-red-600 hover:text-red-900">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Mobile Menu Toggle -->
    <button id="mobileMenuToggle" class="fixed top-4 left-4 z-40 p-2 rounded-lg text-gray-600 bg-white shadow-lg md:hidden">
        <i class="fas fa-bars text-lg"></i>
    </button>

    <!-- Profile Dropdown Menu -->
    <div id="profileDropdownMenu" class="hidden absolute right-6 top-20 w-48 bg-white rounded-xl shadow-lg border border-gray-100 py-2 z-50"></div>

    <!-- Add/Edit Staff Modal -->
    <div id="staffModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-md w-full max-h-[90vh] overflow-y-auto">
            <div class="p-6">
                <h2 id="modalTitle" class="text-xl font-bold text-gray-900 mb-4">Edit Staff</h2>
                <form id="staffForm" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" id="formAction" value="edit">
                    <input type="hidden" name="staff_id" id="editStaffId" value="">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                        <input type="text" name="name" id="formName" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" disabled>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input type="email" name="email" id="formEmail" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500" disabled>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                        <select name="role" id="formRole" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Select Role</option>
                            <option value="SuperAdmin">SuperAdmin</option>
                            <option value="Admin">Admin</option>
                            <option value="Employee">Employee</option>
                        </select>
                    </div>
                    <div class="flex justify-end space-x-3 pt-4" id="buttonContainer">
                        <button type="button" id="cancelBtn" class="px-4 py-2 text-gray-600 bg-gray-200 rounded-lg hover:bg-gray-300 transition-colors">Cancel</button>
                        <button type="button" id="saveBtn" class="btn-primary px-4 py-2 rounded-lg flex items-center">
                            <span id="saveText">Save</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const csrfToken = '<?php echo $csrf_token; ?>';
        const currentStaffId = <?php echo json_encode($staff_id); ?>;

        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('-translate-x-full');
        });

        // Profile Dropdown (exact same sa dashboard)
        const profileDropdown = document.getElementById('profileDropdown');
        const profileDropdownMenu = document.getElementById('profileDropdownMenu');

        profileDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
            if (profileDropdownMenu.classList.contains('hidden')) {
                profileDropdownMenu.innerHTML = `
                    <a href="accountsettings.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 text-blue-500">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                        My Profile
                    </a>
                    <div class="border-t border-gray-100 my-1"></div>
                    <a href="../admin_logout.php" class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                        </svg>
                        Sign Out
                    </a>
                `;
                profileDropdownMenu.classList.remove('hidden');
                const rect = profileDropdown.getBoundingClientRect();
                profileDropdownMenu.style.top = `${rect.bottom + 8}px`;
                profileDropdownMenu.style.right = `${window.innerWidth - rect.right + 8}px`;
            } else {
                profileDropdownMenu.classList.add('hidden');
            }
        });

        document.addEventListener('click', function(e) {
            if (!profileDropdown.contains(e.target)) {
                profileDropdownMenu.classList.add('hidden');
            }
        });

        window.addEventListener('scroll', () => profileDropdownMenu.classList.add('hidden'));

        // Add New Staff
        function addStaff() {
            document.getElementById('modalTitle').textContent = 'Add New Staff';
            document.getElementById('formAction').value = 'add';
            document.getElementById('editStaffId').value = '';
            document.getElementById('formName').value = '';
            document.getElementById('formEmail').value = '';
            document.getElementById('formRole').value = '';
            document.getElementById('formName').disabled = false;
            document.getElementById('formEmail').disabled = false;
            document.getElementById('formRole').disabled = false;
            document.getElementById('staffModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            // Show buttons for add
            showButtons();
        }

        // Edit Staff
        function editStaff(id) {
            const formData = new FormData();
            formData.append('action', 'get_staff');
            formData.append('staff_id', id);
            formData.append('csrf_token', csrfToken);
            fetch('manage_staff.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('modalTitle').textContent = 'Edit Staff';
                    document.getElementById('formAction').value = 'edit';
                    document.getElementById('editStaffId').value = data.staff.staff_id;
                    document.getElementById('formName').value = data.staff.name;
                    document.getElementById('formEmail').value = data.staff.email;
                    document.getElementById('formRole').value = data.staff.role;
                    document.getElementById('formName').disabled = true;
                    document.getElementById('formEmail').disabled = true;
                    const roleSelect = document.getElementById('formRole');
                    roleSelect.disabled = (data.staff.staff_id == currentStaffId);  // Keep this for role restriction

                    // Hide buttons if editing self, show view-only mode
                    const buttonContainer = document.getElementById('buttonContainer');
                    if (data.staff.staff_id == currentStaffId) {
                        buttonContainer.style.display = 'none';
                        // Change title to indicate view-only
                        document.getElementById('modalTitle').textContent = 'Profile Information';
                    } else {
                        buttonContainer.style.display = 'flex';
                    }

                    document.getElementById('staffModal').classList.remove('hidden');
                    document.body.style.overflow = 'hidden';
                } else {
                    Swal.fire('Error!', data.msg || 'Failed to load staff data', 'error');
                }
            })
            .catch(err => {
                console.error(err);
                Swal.fire('Error!', 'Failed to load staff data', 'error');
            });
        }

        // Helper to show buttons (for add/edit others)
        function showButtons() {
            document.getElementById('buttonContainer').style.display = 'flex';
        }

        // Delete Staff
        function deleteStaff(id) {
            Swal.fire({
                title: 'Are you sure?',
                text: "You won't be able to revert this!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3b82f6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'delete');
                    formData.append('staff_id', id);
                    formData.append('csrf_token', csrfToken);
                    fetch('manage_staff.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('Deleted!', data.msg, 'success').then(() => {
                                removeStaffRow(id);
                            });
                        } else {
                            Swal.fire('Error!', data.msg || 'Failed to delete staff', 'error');
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        Swal.fire('Error!', 'Failed to delete staff', 'error');
                    });
                }
            });
        }

        // Close Modals
        document.getElementById('cancelBtn').onclick = () => {
            document.getElementById('staffModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        };

        // Close on backdrop
        document.getElementById('staffModal').addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.add('hidden');
                document.body.style.overflow = 'auto';
            }
        });

        // Submit Form - INSTANT UPDATE
        document.getElementById('saveBtn').addEventListener('click', submitForm);

        function submitForm() {
            const form = document.getElementById('staffForm');
            const formData = new FormData(form);
            const saveBtn = document.getElementById('saveBtn');
            const saveText = document.getElementById('saveText');
            const action = document.getElementById('formAction').value;
            const staffId = document.getElementById('editStaffId').value;

            // Manually append disabled fields for edit mode
            if (action === 'edit') {
                formData.append('name', document.getElementById('formName').value);
                formData.append('email', document.getElementById('formEmail').value);
            }

            saveBtn.classList.add('btn-loading');
            saveText.textContent = 'Saving...';

            fetch('manage_staff.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Success!', data.msg, 'success').then(() => {
                        document.getElementById('staffModal').classList.add('hidden');
                        document.body.style.overflow = 'auto';
                        form.reset();
                        // Refresh table for both add and edit
                        refreshStaffTable();
                    });
                } else {
                    Swal.fire('Error!', data.msg || 'Operation failed.', 'error');
                }
            })
            .catch(err => {
                console.error(err);
                Swal.fire('Error!', 'Network error. Please try again.', 'error');
            })
            .finally(() => {
                saveBtn.classList.remove('btn-loading');
                saveText.textContent = 'Save';
            });
        }

        // Refresh staff table
        function refreshStaffTable() {
            fetch('manage_staff.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=get_all_staff&csrf_token=${csrfToken}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const tbody = document.getElementById('staffTableBody');
                    if (data.staff.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="7" class="px-6 py-4 text-center text-gray-500">No staff members found.</td></tr>';
                        return;
                    }
                    tbody.innerHTML = data.staff.map(s => `
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <img src="${getAvatarSrc(s.profile_picture, s.name)}" alt="Avatar" class="w-10 h-10 rounded-full object-cover">
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">${escapeHtml(s.name)}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${escapeHtml(s.email)}</td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-purple-100 text-purple-800">
                                    ${escapeHtml(s.role)}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${s.status_class}">
                                    ${s.is_online}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                ${formatDate(s.created_at)}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <button onclick="editStaff(${s.staff_id})" class="text-blue-600 hover:text-blue-900 mr-3">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="deleteStaff(${s.staff_id})" class="text-red-600 hover:text-red-900">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    `).join('');
                }
            })
            .catch(console.error);
        }

        // Remove staff row (for delete)
        function removeStaffRow(id) {
            const row = document.querySelector(`tr td button[onclick="editStaff(${id})"]`)?.closest('tr');
            if (row) row.remove();
            // If no rows left, show empty message
            if (document.querySelector('#staffTableBody tr') === null) {
                document.getElementById('staffTableBody').innerHTML = '<tr><td colspan="7" class="px-6 py-4 text-center text-gray-500">No staff members found.</td></tr>';
            }
        }

        // Escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Format date
        function formatDate(dateStr) {
            const date = new Date(dateStr);
            const month = date.toLocaleString('default', { month: 'short' });
            return `${month} ${date.getDate()}, ${date.getFullYear()}`;
        }

        function getAvatarSrc(profile_picture, name) {
            if (profile_picture) {
                return '../' + profile_picture;
            }
            return 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' + encodeURIComponent(name);
        }

        // Heartbeat to update own activity every 30 seconds
        setInterval(() => {
            fetch('manage_staff.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=update_activity&csrf_token=${csrfToken}`
            }).catch(console.error);
        }, 30000);

        // Refresh staff list and statuses every 60 seconds
        setInterval(refreshStaffTable, 60000);
    </script>
</body>
</html>

<?php mysqli_close($conn); ?>