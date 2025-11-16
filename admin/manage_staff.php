<?php
include 'session_check.php';

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
        return '../' . $profile_picture;
    }
    return 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($name);
}

// ---------------------------
// Handle GET alerts (legacy support)
// ---------------------------
$alerts = [];
if (isset($_GET['alert'])) {
    if ($_GET['alert'] === 'success_add') {
        $alerts[] = ['type' => 'success', 'msg' => 'Staff member added successfully.'];
    } elseif ($_GET['alert'] === 'success_edit') {
        $alerts[] = ['type' => 'success', 'msg' => 'Staff member updated successfully.'];
    } elseif ($_GET['alert'] === 'success_delete') {
        $alerts[] = ['type' => 'success', 'msg' => 'Staff member deleted successfully.'];
    } elseif ($_GET['alert'] === 'error') {
        $alerts[] = ['type' => 'error', 'msg' => 'An error occurred. Please try again.'];
    }
}

// ---------------------------
// AJAX: Get staff for edit
// ---------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_staff' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $q = "SELECT staff_id, name, email, role FROM staff WHERE staff_id = ?";
    $stmt = mysqli_prepare($conn, $q);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $staff = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if ($staff) {
        echo json_encode(['success' => true, 'staff' => $staff]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit();
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
    header("Location: login.php?message=Account not found.");
    exit();
}
mysqli_stmt_close($stmt);

// ---------------------------
// Handle POST requests (AJAX)
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');

    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'msg' => 'Invalid CSRF token.']);
        exit();
    }

    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'add') {
        $name = sanitize($_POST['name']);
        $email = sanitize($_POST['email']);
        $role = sanitize($_POST['role']);
        $password = $_POST['password'];

        if (empty($name) || empty($email) || empty($role) || empty($password)) {
            echo json_encode(['success' => false, 'msg' => 'All fields are required.']);
            exit();
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'msg' => 'Invalid email format.']);
            exit();
        }

        $check_email = "SELECT staff_id FROM staff WHERE email = ?";
        $check_stmt = mysqli_prepare($conn, $check_email);
        mysqli_stmt_bind_param($check_stmt, "s", $email);
        mysqli_stmt_execute($check_stmt);
        if (mysqli_stmt_get_result($check_stmt)->num_rows > 0) {
            mysqli_stmt_close($check_stmt);
            echo json_encode(['success' => false, 'msg' => 'Email already exists.']);
            exit();
        }
        mysqli_stmt_close($check_stmt);

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $profile_picture = NULL;

        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/staff/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $file_name = uniqid() . '.' . $file_extension;
            $upload_path = $upload_dir . $file_name;
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                $profile_picture = 'uploads/staff/' . $file_name;
            }
        }

        $insert_query = "INSERT INTO staff (name, profile_picture, email, role, password) VALUES (?, ?, ?, ?, ?)";
        $insert_stmt = mysqli_prepare($conn, $insert_query);
        mysqli_stmt_bind_param($insert_stmt, "sssss", $name, $profile_picture, $email, $role, $hashed_password);
        if (mysqli_stmt_execute($insert_stmt)) {
            $new_staff_id = mysqli_insert_id($conn);
            echo json_encode([
                'success' => true,
                'msg' => 'Staff added successfully!',
                'staff_id' => $new_staff_id,
                'profile_picture' => $profile_picture
            ]);
        } else {
            echo json_encode(['success' => false, 'msg' => 'Database error.']);
        }
        mysqli_stmt_close($insert_stmt);
        exit();
    }

    elseif ($action === 'edit') {
        $edit_id = (int)($_POST['staff_id'] ?? 0);
        $name = sanitize($_POST['name']);
        $email = sanitize($_POST['email']);
        $role = sanitize($_POST['role']);
        $password = $_POST['password'] ?? '';

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

        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $update_fields .= ", password = ?";
            $params[] = $hashed_password;
            $types .= "s";
        }

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

            $upload_dir = '../uploads/staff/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $file_name = uniqid() . '.' . $file_extension;
            $upload_path = $upload_dir . $file_name;
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                $new_profile_picture = 'uploads/staff/' . $file_name;
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
    }

    echo json_encode(['success' => false, 'msg' => 'Invalid action.']);
    exit();
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
        @keyframes gentle-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .animate-gentle-pulse { animation: gentle-pulse 2s infinite; }
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

        /* PROFESSIONAL TOAST - 5 SECONDS */
        .toast { 
            position: fixed; 
            bottom: 1.5rem; 
            right: 1rem; 
            z-index: 100; 
            animation: slideInBottom 0.4s ease-out; 
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(0, 0, 0, 0.08);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
            border-radius: 1rem;
            padding: 1rem 1.25rem;
            min-width: 340px;
            max-width: 460px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
            color: #1f2937;
            font-size: 0.925rem;
        }

        .toast-success { border-left: 4px solid #10b981; }
        .toast-error { border-left: 4px solid #ef4444; }

        .toast-icon {
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 0.9rem;
        }

        .toast-success .toast-icon {
            background: #d1fae5;
            color: #10b981;
        }

        .toast-error .toast-icon {
            background: #fee2e2;
            color: #ef4444;
        }

        .toast-progress {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            background: #10b981;
            border-radius: 0 0 1rem 1rem;
            animation: shrink 5s linear forwards;
        }

        .toast-error .toast-progress {
            background: #ef4444;
        }

        @keyframes slideInBottom { 
            from { transform: translateY(100%); opacity: 0; } 
            to { transform: translateY(0); opacity: 1; } 
        }

        @keyframes slideOutBottom { 
            to { transform: translateY(100%); opacity: 0; } 
        }

        @keyframes shrink {
            from { width: 100%; }
            to { width: 0%; }
        }

        .btn-loading { pointer-events: none; opacity: 0.7; }
        .btn-loading::after { content: ''; display: inline-block; width: 1em; height: 1em; border: 2px solid transparent; border-top-color: currentColor; border-radius: 50%; animation: spin 0.8s linear infinite; margin-left: 0.5rem; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Z-INDEX FIXES */
        #staffModal { z-index: 50; }
        #confirmModal, #deleteModal { z-index: 60; }
        #confirmModal .transform { animation: popIn 0.2s ease-out; }
        @keyframes popIn { from { transform: scale(0.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    </style>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex">
        <!-- SIDEBAR -->
        <div class="sidebar w-64 bg-white shadow-lg fixed h-full z-30">
            <div class="flex flex-col h-full">
                <div class="p-6">
                    <div class="flex items-center space-x-3"> 
                        <img src="../assets/icons/AquaSense.png" alt="Logo" class="w-16 h-16 rounded-lg object-contain bg-white p-1">
                        <div class="flex-1">
                            <h1 class="text-xl font-bold text-gray-900">AquaSense</h1>
                            <p class="text-xs text-gray-500">Admin Portal</p>
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
                <!-- Legacy Alerts -->
                <?php if (!empty($alerts)): ?>
                    <?php foreach ($alerts as $a): ?>
                        <div class="status <?php echo $a['type'] === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
                            <div class="flex items-start">
                                <i class="mr-2 mt-0.5 <?php echo $a['type'] === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-exclamation'; ?>"></i>
                                <p class="text-sm font-medium"><?php echo htmlspecialchars($a['msg']); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Staff Table -->
                <div class="card p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-lg font-semibold text-gray-900">Staff Members</h2>
                        <button onclick="openAddModal()" class="btn-primary flex items-center px-4 py-2 rounded-lg text-sm font-medium">
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
                            <tbody class="bg-white divide-y divide-gray-200" id="staffTableBody">
                                <?php if (empty($all_staff)): ?>
                                    <tr><td colspan="6" class="px-6 py-4 text-center text-gray-500">No staff members found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($all_staff as $s): ?>
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

    <!-- Toast Container -->
    <div id="toastContainer"></div>

    <!-- Confirmation Modal -->
    <div id="confirmModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-60 p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-sm w-full p-6 transform transition-all scale-95">
            <div class="flex items-center mb-4">
                <i class="fas fa-question-circle text-3xl text-blue-600 mr-3"></i>
                <h3 class="text-lg font-semibold text-gray-900">Confirm Action</h3>
            </div>
            <p class="text-sm text-gray-600 mb-6" id="confirmMessage">Are you sure?</p>
            <div class="flex justify-end space-x-3">
                <button id="cancelConfirm" class="px-4 py-2 text-gray-600 bg-gray-200 rounded-lg hover:bg-gray-300">Cancel</button>
                <button id="proceedConfirm" class="btn-primary px-4 py-2 rounded-lg">Proceed</button>
            </div>
        </div>
    </div>

    <!-- Add/Edit Staff Modal -->
    <div id="staffModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50 p-4 modal">
        <div class="bg-white rounded-xl shadow-2xl max-w-md w-full max-h-[90vh] overflow-y-auto">
            <div class="p-6">
                <h2 id="modalTitle" class="text-xl font-bold text-gray-900 mb-4">Add New Staff</h2>
                <form id="staffForm" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="staff_id" id="editStaffId" value="">
                    <input type="hidden" name="ajax" value="1">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                        <input type="text" name="name" id="formName" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input type="email" name="email" id="formEmail" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                        <select name="role" id="formRole" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="Admin">Admin</option>
                            <option value="Employee">Employee</option>
                            <option value="Manager">Manager</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" id="passwordLabel">Password</label>
                        <input type="password" name="password" id="formPassword" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Profile Picture</label>
                        <input type="file" name="profile_picture" accept="image/*" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                    </div>
                    <div class="flex justify-end space-x-3 pt-4">
                        <button type="button" id="cancelBtn" class="px-4 py-2 text-gray-600 bg-gray-200 rounded-lg hover:bg-gray-300 transition-colors">Cancel</button>
                        <button type="button" id="saveBtn" class="btn-primary px-4 py-2 rounded-lg flex items-center">
                            <span id="saveText">Save</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-60 p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-md w-full p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Confirm Delete</h3>
            <p class="text-sm text-gray-600 mb-6">This action cannot be undone.</p>
            <div class="flex justify-end space-x-3">
                <button id="cancelDelete" class="px-4 py-2 text-gray-600 bg-gray-200 rounded-lg hover:bg-gray-300">Cancel</button>
                <button id="proceedDelete" class="px-4 py-2 text-red-600 bg-red-50 rounded-lg hover:bg-red-100">Delete</button>
            </div>
        </div>
    </div>

    <script>
        const csrfToken = '<?php echo $csrf_token; ?>';
        let currentDeleteId = null;

        // Professional Toast - 5 Seconds
        function showToast(message, type = 'success', duration = 5000) {
            const toast = document.createElement('div');
            toast.className = `toast ${type === 'success' ? 'toast-success' : 'toast-error'}`;
            
            toast.innerHTML = `
                <div class="toast-icon">
                    <i class="fas ${type === 'success' ? 'fa-check' : 'fa-times'}"></i>
                </div>
                <span class="flex-1">${message}</span>
                <div class="toast-progress" style="animation-duration: ${duration}ms;"></div>
            `;

            const container = document.getElementById('toastContainer');
            container.appendChild(toast);

            setTimeout(() => {
                toast.style.animation = 'slideOutBottom 0.4s ease-out forwards';
                toast.addEventListener('animationend', () => toast.remove());
            }, duration);
        }

        // Open Add Modal
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add New Staff';
            document.getElementById('formAction').value = 'add';
            document.getElementById('editStaffId').value = '';
            document.getElementById('formPassword').required = true;
            document.getElementById('passwordLabel').textContent = 'Password';
            document.getElementById('staffForm').reset();
            document.getElementById('staffModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        // Edit Staff
        function editStaff(id) {
            fetch(`manage_staff.php?ajax=get_staff&id=${id}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('modalTitle').textContent = 'Edit Staff';
                        document.getElementById('formAction').value = 'edit';
                        document.getElementById('editStaffId').value = data.staff.staff_id;
                        document.getElementById('formName').value = data.staff.name;
                        document.getElementById('formEmail').value = data.staff.email;
                        document.getElementById('formRole').value = data.staff.role;
                        document.getElementById('formPassword').required = false;
                        document.getElementById('passwordLabel').textContent = 'Password (Leave blank to keep current)';
                        document.getElementById('staffModal').classList.remove('hidden');
                        document.body.style.overflow = 'hidden';
                    }
                });
        }

        // Delete Staff
        function deleteStaff(id) {
            currentDeleteId = id;
            document.getElementById('deleteModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        // Close Modals
        document.getElementById('cancelBtn').onclick = () => {
            document.getElementById('staffModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        };
        document.getElementById('cancelDelete').onclick = () => {
            document.getElementById('deleteModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        };

        // Close on backdrop
        document.querySelectorAll('.fixed.inset-0').forEach(m => {
            m.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.add('hidden');
                    document.body.style.overflow = 'auto';
                }
            });
        });

        // Save with Confirmation
        document.getElementById('saveBtn').addEventListener('click', () => {
            const action = document.getElementById('formAction').value;
            document.getElementById('confirmMessage').textContent = 
                `Are you sure you want to ${action === 'add' ? 'add' : 'update'} this staff member?`;
            
            document.getElementById('staffModal').classList.add('hidden');
            document.getElementById('confirmModal').classList.remove('hidden');
        });

        document.getElementById('cancelConfirm').onclick = () => {
            document.getElementById('confirmModal').classList.add('hidden');
            document.getElementById('staffModal').classList.remove('hidden');
        };

        document.getElementById('proceedConfirm').addEventListener('click', () => {
            document.getElementById('confirmModal').classList.add('hidden');
            submitForm();
        });

        // Submit Form - INSTANT UPDATE
        function submitForm() {
            const form = document.getElementById('staffForm');
            const formData = new FormData(form);
            const saveBtn = document.getElementById('saveBtn');
            const saveText = document.getElementById('saveText');
            const action = document.getElementById('formAction').value;
            const staffId = document.getElementById('editStaffId').value;

            saveBtn.classList.add('btn-loading');
            saveText.textContent = 'Saving...';

            fetch('manage_staff.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(data.msg, 'success');

                    document.getElementById('staffModal').classList.add('hidden');
                    document.body.style.overflow = 'auto';

                    if (action === 'add') {
                        const newStaff = {
                            staff_id: data.staff_id,
                            name: formData.get('name'),
                            email: formData.get('email'),
                            role: formData.get('role'),
                            created_at: new Date().toISOString().split('T')[0],
                            profile_picture: data.profile_picture
                        };
                        addStaffRow(newStaff);
                    } else if (action === 'edit') {
                        updateStaffRow(staffId, {
                            name: formData.get('name'),
                            email: formData.get('email'),
                            role: formData.get('role'),
                            profile_picture: data.profile_picture
                        });
                    }

                    form.reset();
                } else {
                    showToast(data.msg || 'Operation failed.', 'error');
                }
            })
            .catch(err => {
                console.error(err);
                showToast('Network error. Please try again.', 'error');
            })
            .finally(() => {
                saveBtn.classList.remove('btn-loading');
                saveText.textContent = 'Save';
            });
        }

        // Add new staff row
        function addStaffRow(staff) {
            const tbody = document.getElementById('staffTableBody');
            const avatar = staff.profile_picture 
                ? `../${staff.profile_picture}` 
                : `https://ui-avatars.com/api/?background=3b82f6&color=fff&name=${encodeURIComponent(staff.name)}`;

            const row = document.createElement('tr');
            row.className = 'hover:bg-gray-50';
            row.innerHTML = `
                <td class="px-6 py-4 whitespace-nowrap">
                    <img src="${avatar}" alt="Avatar" class="w-10 h-10 rounded-full object-cover">
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">${escapeHtml(staff.name)}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${escapeHtml(staff.email)}</td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-purple-100 text-purple-800">
                        ${escapeHtml(staff.role)}
                    </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    ${formatDate(staff.created_at)}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                    <button onclick="editStaff(${staff.staff_id})" class="text-blue-600 hover:text-blue-900 mr-3">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button onclick="deleteStaff(${staff.staff_id})" class="text-red-600 hover:text-red-900">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            `;
            tbody.insertBefore(row, tbody.firstChild);
        }

        // Update existing row
        function updateStaffRow(id, updates) {
            const row = document.querySelector(`tr td button[onclick="editStaff(${id})"]`)?.closest('tr');
            if (!row) return;

            const cells = row.cells;
            cells[0].querySelector('img').src = updates.profile_picture 
                ? `../${updates.profile_picture}` 
                : `https://ui-avatars.com/api/?background=3b82f6&color=fff&name=${encodeURIComponent(updates.name)}`;
            cells[1].textContent = escapeHtml(updates.name);
            cells[2].textContent = escapeHtml(updates.email);
            cells[3].querySelector('span').textContent = escapeHtml(updates.role);
        }

        // Remove staff row
        function removeStaffRow(id) {
            const row = document.querySelector(`tr td button[onclick="editStaff(${id})"]`)?.closest('tr');
            if (row) row.remove();
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

        // Delete with Confirmation - Instant Remove
        document.getElementById('proceedDelete').addEventListener('click', () => {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('staff_id', currentDeleteId);
            formData.append('csrf_token', csrfToken);
            formData.append('ajax', '1');

            fetch('manage_staff.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(data.msg, 'success');
                    removeStaffRow(currentDeleteId);
                    document.getElementById('deleteModal').classList.add('hidden');
                    document.body.style.overflow = 'auto';
                } else {
                    showToast(data.msg || 'Delete failed.', 'error');
                }
            })
            .catch(() => showToast('Network error.', 'error'));
        });
    </script>
</body>
</html>

<?php mysqli_close($conn); ?>