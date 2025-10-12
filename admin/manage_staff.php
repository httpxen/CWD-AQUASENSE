<?php
include 'session_check.php'; // From Step 2

// ---------------------------
// Session timeout (30 minutes)
// ---------------------------
$timeout_duration = 1800;
if (!isset($_SESSION['staff_id'])) {
    header("Location: login.php?message=Please log in to access the dashboard.");
    exit();
}
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: login.php?message=Session expired, please log in again.");
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

$staff_id = $_SESSION['staff_id'];

// ---------------------------
// CSRF token
// ---------------------------
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
        return '../' . $profile_picture; // Adjust for admin/ subfolder
    }
    return 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($name);
}

$alerts = []; // [ ['type' => 'success'|'error'|'info', 'msg' => '...'] ]

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
    // Safety: if staff missing, logout
    session_unset();
    session_destroy();
    header("Location: login.php?message=Account not found.");
    exit();
}
if (isset($stmt)) { mysqli_stmt_close($stmt); }

// ---------------------------
// Handle POST requests
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $alerts[] = ['type' => 'error', 'msg' => 'Invalid CSRF token.'];
    } else {
        $action = sanitize($_POST['action'] ?? '');

        if ($action === 'add') {
            $name = sanitize($_POST['name']);
            $email = sanitize($_POST['email']);
            $role = sanitize($_POST['role']);
            $password = $_POST['password'];

            if (empty($name) || empty($email) || empty($role) || empty($password)) {
                $alerts[] = ['type' => 'error', 'msg' => 'All fields are required.'];
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $alerts[] = ['type' => 'error', 'msg' => 'Invalid email format.'];
            } else {
                // Check if email exists
                $check_email = "SELECT staff_id FROM staff WHERE email = ?";
                $check_stmt = mysqli_prepare($conn, $check_email);
                mysqli_stmt_bind_param($check_stmt, "s", $email);
                mysqli_stmt_execute($check_stmt);
                if (mysqli_stmt_get_result($check_stmt)->num_rows > 0) {
                    $alerts[] = ['type' => 'error', 'msg' => 'Email already exists.'];
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $profile_picture = NULL;

                    // Handle profile picture upload
                    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                        $upload_dir = '../uploads/staff/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
                        $file_name = uniqid() . '.' . $file_extension;
                        $upload_path = $upload_dir . $file_name;
                        if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                            $profile_picture = 'uploads/staff/' . $file_name;
                        } else {
                            $alerts[] = ['type' => 'error', 'msg' => 'Failed to upload profile picture.'];
                        }
                    }

                    if (empty($alerts)) {
                        $insert_query = "INSERT INTO staff (name, profile_picture, email, role, password) VALUES (?, ?, ?, ?, ?)";
                        $insert_stmt = mysqli_prepare($conn, $insert_query);
                        mysqli_stmt_bind_param($insert_stmt, "sssss", $name, $profile_picture, $email, $role, $hashed_password);
                        if (mysqli_stmt_execute($insert_stmt)) {
                            $alerts[] = ['type' => 'success', 'msg' => 'Staff member added successfully.'];
                        } else {
                            $alerts[] = ['type' => 'error', 'msg' => 'Failed to add staff member.'];
                        }
                        if (isset($insert_stmt)) { mysqli_stmt_close($insert_stmt); }
                    }
                }
                if (isset($check_stmt)) { mysqli_stmt_close($check_stmt); }
            }
        } elseif ($action === 'edit') {
            $edit_id = (int)($_POST['staff_id'] ?? 0);
            $name = sanitize($_POST['name']);
            $email = sanitize($_POST['email']);
            $role = sanitize($_POST['role']);
            $password = $_POST['password'] ?? '';

            if (empty($name) || empty($email) || empty($role) || $edit_id <= 0) {
                $alerts[] = ['type' => 'error', 'msg' => 'Invalid input.'];
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $alerts[] = ['type' => 'error', 'msg' => 'Invalid email format.'];
            } else {
                // Check if email exists for another staff
                $check_email = "SELECT staff_id FROM staff WHERE email = ? AND staff_id != ?";
                $check_stmt = mysqli_prepare($conn, $check_email);
                mysqli_stmt_bind_param($check_stmt, "si", $email, $edit_id);
                mysqli_stmt_execute($check_stmt);
                if (mysqli_stmt_get_result($check_stmt)->num_rows > 0) {
                    $alerts[] = ['type' => 'error', 'msg' => 'Email already exists.'];
                } else {
                    $update_fields = "name = ?, email = ?, role = ?";
                    $params = [$name, $email, $role];
                    $types = "sss";

                    if (!empty($password)) {
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $update_fields .= ", password = ?";
                        $params[] = $hashed_password;
                        $types .= "s";
                    }

                    // Handle profile picture update
                    $profile_picture_update = false;
                    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                        // Delete old picture if exists
                        $old_query = "SELECT profile_picture FROM staff WHERE staff_id = ?";
                        $old_stmt = mysqli_prepare($conn, $old_query);
                        mysqli_stmt_bind_param($old_stmt, "i", $edit_id);
                        mysqli_stmt_execute($old_stmt);
                        $old_result = mysqli_stmt_get_result($old_stmt);
                        $old_staff = mysqli_fetch_assoc($old_result);
                        if ($old_staff['profile_picture'] && file_exists('../' . $old_staff['profile_picture'])) {
                            unlink('../' . $old_staff['profile_picture']);
                        }
                        if (isset($old_stmt)) { mysqli_stmt_close($old_stmt); }

                        $upload_dir = '../uploads/staff/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
                        $file_name = uniqid() . '.' . $file_extension;
                        $upload_path = $upload_dir . $file_name;
                        if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                            $new_profile_picture = 'uploads/staff/' . $file_name;
                            $update_fields .= ", profile_picture = ?";
                            $params[] = $new_profile_picture;
                            $types .= "s";
                            $profile_picture_update = true;
                        } else {
                            $alerts[] = ['type' => 'error', 'msg' => 'Failed to upload profile picture.'];
                        }
                    }

                    if (empty($alerts)) {
                        $update_query = "UPDATE staff SET $update_fields WHERE staff_id = ?";
                        $params[] = $edit_id;
                        $types .= "i";
                        $update_stmt = mysqli_prepare($conn, $update_query);
                        mysqli_stmt_bind_param($update_stmt, $types, ...$params);
                        if (mysqli_stmt_execute($update_stmt)) {
                            $alerts[] = ['type' => 'success', 'msg' => 'Staff member updated successfully.'];
                        } else {
                            $alerts[] = ['type' => 'error', 'msg' => 'Failed to update staff member.'];
                        }
                        if (isset($update_stmt)) { mysqli_stmt_close($update_stmt); }
                    }
                }
                if (isset($check_stmt)) { mysqli_stmt_close($check_stmt); }
            }
        } elseif ($action === 'delete') {
            $delete_id = (int)($_POST['staff_id'] ?? 0);
            if ($delete_id <= 0 || $delete_id === $staff_id) {
                $alerts[] = ['type' => 'error', 'msg' => 'Cannot delete self or invalid ID.'];
            } else {
                // Delete assignments first
                $delete_assign = "DELETE FROM complaint_assignments WHERE staff_id = ?";
                $delete_assign_stmt = mysqli_prepare($conn, $delete_assign);
                mysqli_stmt_bind_param($delete_assign_stmt, "i", $delete_id);
                mysqli_stmt_execute($delete_assign_stmt);
                if (isset($delete_assign_stmt)) { mysqli_stmt_close($delete_assign_stmt); }

                // Delete staff and old picture
                $delete_query = "SELECT profile_picture FROM staff WHERE staff_id = ?";
                $delete_stmt = mysqli_prepare($conn, $delete_query);
                mysqli_stmt_bind_param($delete_stmt, "i", $delete_id);
                mysqli_stmt_execute($delete_stmt);
                $delete_result = mysqli_stmt_get_result($delete_stmt);
                $to_delete = mysqli_fetch_assoc($delete_result);
                if ($to_delete['profile_picture'] && file_exists('../' . $to_delete['profile_picture'])) {
                    unlink('../' . $to_delete['profile_picture']);
                }
                if (isset($delete_stmt)) { mysqli_stmt_close($delete_stmt); }

                $final_delete = "DELETE FROM staff WHERE staff_id = ?";
                $final_stmt = mysqli_prepare($conn, $final_delete);
                mysqli_stmt_bind_param($final_stmt, "i", $delete_id);
                if (mysqli_stmt_execute($final_stmt)) {
                    $alerts[] = ['type' => 'success', 'msg' => 'Staff member deleted successfully.'];
                } else {
                    $alerts[] = ['type' => 'error', 'msg' => 'Failed to delete staff member.'];
                }
                if (isset($final_stmt)) { mysqli_stmt_close($final_stmt); }
            }
        }
    }
}

// ---------------------------
// Fetch all staff
// ---------------------------
$all_staff_query = "SELECT staff_id, name, profile_picture, email, role, created_at FROM staff ORDER BY created_at DESC";
$all_staff_result = mysqli_query($conn, $all_staff_query);
$all_staff = [];
while ($row = mysqli_fetch_assoc($all_staff_result)) {
    $all_staff[] = $row;
}

// Fetch editing staff if ID in URL
$editing_staff = null;
$edit_id = (int)($_GET['edit'] ?? 0);
if ($edit_id > 0) {
    $edit_query = "SELECT * FROM staff WHERE staff_id = ?";
    $edit_stmt = mysqli_prepare($conn, $edit_query);
    mysqli_stmt_bind_param($edit_stmt, "i", $edit_id);
    mysqli_stmt_execute($edit_stmt);
    $edit_result = mysqli_stmt_get_result($edit_stmt);
    $editing_staff = mysqli_fetch_assoc($edit_result);
    if (!$editing_staff) {
        $alerts[] = ['type' => 'error', 'msg' => 'Staff member not found.'];
    }
    if (isset($edit_stmt)) { mysqli_stmt_close($edit_stmt); }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Manage Staff | CWD AquaSense Admin</title>
    <link rel="icon" type="image/png" href="../assets/icons/AquaSense2.png" />
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <style>
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .sidebar { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); width: 256px; }
        .card { background: linear-gradient(145deg, #ffffff, #f8fafc); border: 1px solid rgba(0,0,0,0.05); border-radius: 1rem; }
        .btn-primary { background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); color: white; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); }
        .status { border-radius: 0.5rem; padding: 0.75rem 1rem; }
        .avatar-glow { position: relative; cursor: pointer; }
        .avatar-glow::before { content: ''; position: absolute; top: -2px; left: -2px; right: -2px; bottom: -2px; background: linear-gradient(45deg, #3b82f6, #8b5cf6, #06b6d4, #3b82f6); border-radius: 50%; z-index: -1; opacity: 0; transition: opacity 0.3s ease; }
        .avatar-glow:hover::before { opacity: 1; }
        .notification-badge { position: absolute; top: -2px; right: -2px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 10px; display: flex; align-items: center; justify-content: center; font-weight: 600; box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3); }
        .group:hover .fa-chevron-down { transform: rotate(180deg); transition: transform 0.2s ease; }
        @keyframes gentle-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .animate-gentle-pulse { animation: gentle-pulse 2s infinite; }
        .profile-card { transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
        .profile-card:hover { transform: translateY(-1px); box-shadow: 0 4px 12px -2px rgba(0, 0, 0, 0.08); }
        .dashboard-icon { width: 24px; height: 24px; }
        .complaints-icon { width: 24px; height: 24px; }
        .feedback-icon { width: 24px; height: 24px; }
        .profile-icon { width: 24px; height: 24px; }
        .header-2025 { backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); background: rgba(255,255,255,0.85); border-bottom: 1px solid rgba(255,255,255,0.2); box-shadow: 0 1px 3px 0 rgba(0,0,0,0.05); margin-left: 256px; width: calc(100% - 256px); }
        html { scroll-behavior: smooth; }
        main { margin-left: 256px; padding: 1.5rem; }
        @media (max-width: 767px) {
            .header-2025 { margin-left: 0; width: 100%; }
            main { margin-left: 0; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.translate-x-0 { transform: translateX(0); }
        }
        .modal { transition: opacity 0.3s ease; }
    </style>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex">
        <!-- Sidebar -->
        <div class="sidebar w-64 bg-white shadow-lg fixed h-full z-30">
            <div class="flex flex-col h-full">
                <div class="p-6">
                    <div class="flex items-center space-x-3"> 
                        <img src="../assets/icons/AquaSense.png" alt="CWD AquaSense Logo" class="w-16 h-16 rounded-lg object-contain bg-white p-1 flex-shrink-0">
                        <div class="flex-1">
                            <h1 class="text-xl font-bold text-gray-900">AquaSense</h1>
                            <p class="text-xs text-gray-500">Admin Portal</p>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <nav class="flex-1 py-2 px-4 space-y-2">
                    <a href="dashboard.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="dashboard-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                        </svg>
                        Dashboard
                    </a>
                    <a href="manage_complaints.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="complaints-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        Manage Complaints
                    </a>
                    <a href="manage_staff.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-blue-600 bg-blue-50 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="profile-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                        </svg>
                        Manage Staff
                    </a>
                    <a href="view_feedback.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="feedback-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 0 1 .778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                        </svg>
                        View Feedback
                    </a>
                </nav>

                <!-- Staff Info & Logout -->
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

        <!-- Main -->
        <div class="flex-1">
            <header class="header-2025 sticky top-0 z-20">
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between">
                        <!-- Left: Clean & Minimal -->
                        <div class="flex items-center space-x-4">

                        </div>
                        <!-- Right: Essential Actions Only -->
                        <div class="flex items-center space-x-4">
                            <!-- Notification Button -->
                            <button class="relative p-2 text-gray-600 hover:text-gray-900 transition-all duration-200 rounded-full hover:bg-gray-100 group" id="notificationBtn">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0M3.124 7.5A8.969 8.969 0 0 1 5.292 3m13.416 0a8.969 8.969 0 0 1 2.168 4.5" />
                                </svg>
                                <div class="notification-badge">3</div>
                            </button>

                            <!-- Profile Dropdown - 2025 Style -->
                            <div class="flex items-center space-x-3 p-2 profile-card hover:bg-gray-50 rounded-xl transition-all duration-200 group cursor-pointer relative" id="profileDropdown">
                                <!-- Avatar with Glow Effect -->
                                <div class="avatar-glow">
                                    <img src="<?php echo htmlspecialchars(get_avatar_src($current_staff['profile_picture'], $current_staff['name'])); ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover"/>
                                    <!-- Online Status Ring -->
                                    <div class="absolute -bottom-0.5 -right-0.5 w-4 h-4 bg-green-500 border-2 border-white rounded-full animate-gentle-pulse"></div>
                                </div>
                                <!-- User Info (Desktop Only) -->
                                <div class="hidden md:block">
                                    <p class="text-sm font-semibold text-gray-900 truncate max-w-32"><?php echo htmlspecialchars($current_staff['name']); ?></p>
                                    <p class="text-xs text-gray-500 truncate max-w-32"><?php echo htmlspecialchars($current_staff['role']); ?></p>
                                </div>
                                <!-- Subtle Chevron -->
                                <i class="fas fa-chevron-down text-gray-400 text-sm ml-1 transition-transform duration-200 group-hover:text-gray-600"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main class="p-6 space-y-6">
                <!-- Alerts -->
                <?php if (!empty($alerts)): ?>
                    <?php foreach ($alerts as $a): ?>
                        <div class="status <?php echo $a['type'] === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : ($a['type'] === 'error' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-blue-50 text-blue-700 border border-blue-200'); ?>">
                            <div class="flex items-start">
                                <i class="mr-2 mt-0.5 <?php echo $a['type'] === 'success' ? 'fa-solid fa-circle-check' : ($a['type'] === 'error' ? 'fa-solid fa-circle-exclamation' : 'fa-solid fa-circle-info'); ?>"></i>
                                <p class="text-sm font-medium"><?php echo htmlspecialchars($a['msg']); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Add/Edit Modal -->
                <div id="staffModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50 p-4 modal">
                    <div class="bg-white rounded-xl shadow-2xl max-w-md w-full max-h-[90vh] overflow-y-auto">
                        <div class="p-6">
                            <h2 id="modalTitle" class="text-xl font-bold text-gray-900 mb-4"><?php echo $editing_staff ? 'Edit Staff' : 'Add New Staff'; ?></h2>
                            <form id="staffForm" method="POST" enctype="multipart/form-data" class="space-y-4">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="action" value="<?php echo $editing_staff ? 'edit' : 'add'; ?>">
                                <?php if ($editing_staff): ?>
                                    <input type="hidden" name="staff_id" value="<?php echo $editing_staff['staff_id']; ?>">
                                <?php endif; ?>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                                    <input type="text" name="name" value="<?php echo $editing_staff ? htmlspecialchars($editing_staff['name']) : ''; ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                                    <input type="email" name="email" value="<?php echo $editing_staff ? htmlspecialchars($editing_staff['email']) : ''; ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                                    <select name="role" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        <option value="Admin" <?php echo ($editing_staff && $editing_staff['role'] === 'Admin') ? 'selected' : ''; ?>>Admin</option>
                                        <option value="Support" <?php echo ($editing_staff && $editing_staff['role'] === 'Support') ? 'selected' : ''; ?>>Support</option>
                                        <option value="Manager" <?php echo ($editing_staff && $editing_staff['role'] === 'Manager') ? 'selected' : ''; ?>>Manager</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Password <?php echo $editing_staff ? '(Leave blank to keep current)' : ''; ?></label>
                                    <input type="password" name="password" <?php echo $editing_staff ? '' : 'required'; ?> class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div class="flex justify-end space-x-3 pt-4">
                                    <button type="button" id="cancelBtn" class="px-4 py-2 text-gray-600 bg-gray-200 rounded-lg hover:bg-gray-300 transition-colors">Cancel</button>
                                    <button type="submit" class="btn-primary px-4 py-2 rounded-lg">Save</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Staff Table -->
                <div class="card p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-lg font-semibold text-gray-900">Staff Members</h2>
                        <button onclick="openModal()" class="btn-primary flex items-center px-4 py-2 rounded-lg text-sm font-medium">
                            <i class="fas fa-plus mr-2"></i>Add New Staff
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
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($all_staff)): ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">No staff members found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($all_staff as $s): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <img src="<?php echo htmlspecialchars(get_avatar_src($s['profile_picture'], $s['name'])); ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover">
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($s['name']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo htmlspecialchars($s['email']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-purple-100 text-purple-800">
                                                    <?php echo htmlspecialchars($s['role']); ?>
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

    <!-- Profile Dropdown -->
    <div id="profileDropdownMenu" class="hidden absolute right-6 top-20 w-48 bg-white rounded-xl shadow-lg border border-gray-100 py-2 z-30"></div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-md w-full p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Confirm Delete</h3>
            <p class="text-sm text-gray-600 mb-6">Are you sure you want to delete this staff member? This action cannot be undone.</p>
            <div class="flex justify-end space-x-3">
                <button id="cancelDelete" class="px-4 py-2 text-gray-600 bg-gray-200 rounded-lg hover:bg-gray-300">Cancel</button>
                <form id="deleteForm" method="POST" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" id="deleteStaffId" name="staff_id" value="">
                    <button type="submit" class="px-4 py-2 text-red-600 bg-red-50 rounded-lg hover:bg-red-100">Delete</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('-translate-x-full');
            sidebar.classList.toggle('translate-x-0');
        });

        // Sidebar initial state on mobile
        if (window.innerWidth < 768) {
            document.querySelector('.sidebar').classList.add('-translate-x-full');
        }

        // Responsive sidebar
        window.addEventListener('resize', function() {
            const sidebar = document.querySelector('.sidebar');
            if (window.innerWidth >= 768) {
                sidebar.classList.remove('-translate-x-full');
                sidebar.classList.add('translate-x-0');
            } else {
                sidebar.classList.remove('translate-x-0');
                sidebar.classList.add('-translate-x-full');
            }
        });

        // Profile dropdown functionality
        const profileDropdown = document.getElementById('profileDropdown');
        const profileDropdownMenu = document.getElementById('profileDropdownMenu');

        profileDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
            if (profileDropdownMenu.classList.contains('hidden')) {
                showProfileDropdown();
            } else {
                hideProfileDropdown();
            }
        });

        function showProfileDropdown() {
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
            
            // Position the dropdown
            const rect = profileDropdown.getBoundingClientRect();
            profileDropdownMenu.style.right = '1.5rem';
            profileDropdownMenu.style.top = `${rect.bottom + 8}px`;
        }

        function hideProfileDropdown() {
            profileDropdownMenu.classList.add('hidden');
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function() {
            hideProfileDropdown();
        });

        // Notification button
        document.getElementById('notificationBtn').addEventListener('click', function(e) {
            e.stopPropagation();
            this.style.transform = 'scale(0.95)';
            setTimeout(() => {
                this.style.transform = 'scale(1)';
            }, 150);
            alert('Notifications feature coming soon! 🔔');
        });

        // Modal functions
        function openModal() {
            const modal = document.getElementById('staffModal');
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            const modal = document.getElementById('staffModal');
            modal.classList.add('hidden');
            document.body.style.overflow = 'auto';
            // Reset URL if editing
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.pathname);
            }
        }

        document.getElementById('cancelBtn').addEventListener('click', closeModal);

        // Edit staff - redirect to load data
        function editStaff(id) {
            window.location.href = `manage_staff.php?edit=${id}`;
        }

        // Delete functions
        function deleteStaff(id) {
            document.getElementById('deleteStaffId').value = id;
            document.getElementById('deleteModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        document.getElementById('cancelDelete').addEventListener('click', function() {
            document.getElementById('deleteModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        });

        // Close modals on outside click
        document.querySelectorAll('.fixed.inset-0').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.add('hidden');
                    document.body.style.overflow = 'auto';
                }
            });
        });

        // Form submission loading
        document.getElementById('staffForm').addEventListener('submit', function() {
            const btn = this.querySelector('button[type="submit"]');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
            btn.disabled = true;
        });

        document.getElementById('deleteForm').addEventListener('submit', function() {
            const btn = this.querySelector('button[type="submit"]');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Deleting...';
            btn.disabled = true;
        });

        // Auto-open edit modal if edit param present
        <?php if ($edit_id > 0 && $editing_staff): ?>
            openModal();
        <?php endif; ?>

        // Add hover effects to profile dropdown
        profileDropdown.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-1px)';
        });
        profileDropdown.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });

        // Add loading animation to buttons
        document.querySelectorAll('button:not(#cancelBtn):not(#cancelDelete)').forEach(button => {
            button.addEventListener('click', function(e) {
                if (!this.classList.contains('loading') && !this.id.includes('notificationBtn')) {
                    const originalText = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Loading...';
                    this.classList.add('loading');
                    
                    setTimeout(() => {
                        this.innerHTML = originalText;
                        this.classList.remove('loading');
                    }, 1500);
                }
            });
        });
    </script>
</body>
</html>

<?php
// Cleanup
mysqli_close($conn);
?>