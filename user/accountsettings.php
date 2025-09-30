<?php
include '../db/db.php';
session_start();

// ---------------------------
// Session timeout (30 minutes)
// ---------------------------
$timeout_duration = 1800;
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php?message=Please log in to access account settings.");
    exit();
}
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: ../login.php?message=Session expired, please log in again.");
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

$user_id = $_SESSION['user_id'];

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

$alerts = []; // [ ['type' => 'success'|'error'|'info', 'msg' => '...'] ]

// ---------------------------
// Fetch user info
// ---------------------------
$user_query = "SELECT id, username, first_name, middle_name, last_name, email, password, profile_picture, created_at FROM users WHERE id = ?";
$stmt = mysqli_prepare($conn, $user_query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
if (!$user) {
    // Safety: if user missing, logout
    session_unset();
    session_destroy();
    header("Location: ../login.php?message=Account not found.");
    exit();
}

// ---------------------------
// Handle POST actions
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $alerts[] = ['type' => 'error', 'msg' => 'Invalid request token. Please refresh the page and try again.'];
    } else {
        $action = $_POST['action'] ?? '';

        // ---------------------------
        // Update profile details
        // ---------------------------
        if ($action === 'update_profile') {
            $first_name  = sanitize($_POST['first_name'] ?? '');
            $middle_name = sanitize($_POST['middle_name'] ?? '');
            $last_name   = sanitize($_POST['last_name'] ?? '');
            $username    = sanitize($_POST['username'] ?? '');
            $email       = sanitize($_POST['email'] ?? '');

            // Basic validations
            if ($first_name === '' || $last_name === '' || $username === '' || $email === '') {
                $alerts[] = ['type' => 'error', 'msg' => 'Please fill out First name, Last name, Username, and Email.'];
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $alerts[] = ['type' => 'error', 'msg' => 'Please enter a valid email address.'];
            } elseif (!preg_match('/^[A-Za-z0-9_\.\-]{3,30}$/', $username)) {
                $alerts[] = ['type' => 'error', 'msg' => 'Username must be 3-30 characters and can include letters, numbers, dot, dash, or underscore.'];
            } else {
                // Check if username or email already used by another account
                $check_sql = "SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ? LIMIT 1";
                $chk = mysqli_prepare($conn, $check_sql);
                mysqli_stmt_bind_param($chk, "ssi", $username, $email, $user_id);
                mysqli_stmt_execute($chk);
                $dupe = mysqli_stmt_get_result($chk);
                if (mysqli_fetch_assoc($dupe)) {
                    $alerts[] = ['type' => 'error', 'msg' => 'Username or Email is already taken by another account.'];
                } else {
                    $upd_sql = "UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, username = ?, email = ? WHERE id = ?";
                    $upd = mysqli_prepare($conn, $upd_sql);
                    mysqli_stmt_bind_param($upd, "sssssi", $first_name, $middle_name, $last_name, $username, $email, $user_id);
                    if (mysqli_stmt_execute($upd)) {
                        // Refresh $user
                        $user['first_name'] = $first_name;
                        $user['middle_name'] = $middle_name;
                        $user['last_name'] = $last_name;
                        $user['username'] = $username;
                        $user['email'] = $email;
                        $alerts[] = ['type' => 'success', 'msg' => 'Profile updated successfully.'];
                    } else {
                        $alerts[] = ['type' => 'error', 'msg' => 'Failed to update profile. Please try again.'];
                    }
                }
            }
        }

        // ---------------------------
        // Change password
        // ---------------------------
        if ($action === 'change_password') {
            $current_password = $_POST['current_password'] ?? '';
            $new_password     = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            if ($current_password === '' || $new_password === '' || $confirm_password === '') {
                $alerts[] = ['type' => 'error', 'msg' => 'Please complete all password fields.'];
            } elseif (strlen($new_password) < 8) {
                $alerts[] = ['type' => 'error', 'msg' => 'New password must be at least 8 characters long.'];
            } elseif ($new_password !== $confirm_password) {
                $alerts[] = ['type' => 'error', 'msg' => 'New password and confirmation do not match.'];
            } else {
                // Verify current password
                $hash = $user['password'];
                if (!password_verify($current_password, $hash)) {
                    $alerts[] = ['type' => 'error', 'msg' => 'Your current password is incorrect.'];
                } else {
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $psql = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE id = ?");
                    mysqli_stmt_bind_param($psql, "si", $new_hash, $user_id);
                    if (mysqli_stmt_execute($psql)) {
                        $alerts[] = ['type' => 'success', 'msg' => 'Password changed successfully.'];
                    } else {
                        $alerts[] = ['type' => 'error', 'msg' => 'Failed to change password. Please try again.'];
                    }
                }
            }
        }

        // ---------------------------
        // Profile picture upload
        // ---------------------------
        if ($action === 'upload_avatar' && isset($_FILES['profile_picture'])) {
            $file = $_FILES['profile_picture'];
            if ($file['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (!isset($allowed[$mime])) {
                    $alerts[] = ['type' => 'error', 'msg' => 'Invalid image type. Please upload JPG, PNG, or WEBP.'];
                } elseif ($file['size'] > 3 * 1024 * 1024) {
                    $alerts[] = ['type' => 'error', 'msg' => 'Image too large. Max 3MB.'];
                } else {
                    $ext = $allowed[$mime];
                    $safeName = 'avatar_u' . $user_id . '_' . time() . '.' . $ext;
                    $uploadDir = realpath(__DIR__ . '/../Uploads');
                    if ($uploadDir === false) {
                        // Try to create uploads directory if it doesn't exist
                        $tryPath = __DIR__ . '/../Uploads';
                        if (!is_dir($tryPath)) {
                            @mkdir($tryPath, 0775, true);
                        }
                        $uploadDir = realpath($tryPath);
                    }
                    if ($uploadDir !== false) {
                        $dest = $uploadDir . DIRECTORY_SEPARATOR . $safeName;
                        if (move_uploaded_file($file['tmp_name'], $dest)) {
                            $relativePath = '../Uploads/' . $safeName;
                            $up = mysqli_prepare($conn, "UPDATE users SET profile_picture = ? WHERE id = ?");
                            mysqli_stmt_bind_param($up, "si", $relativePath, $user_id);
                            if (mysqli_stmt_execute($up)) {
                                $user['profile_picture'] = $relativePath;
                                $alerts[] = ['type' => 'success', 'msg' => 'Profile picture updated.'];
                            } else {
                                $alerts[] = ['type' => 'error', 'msg' => 'Failed to save avatar path.'];
                            }
                        } else {
                            $alerts[] = ['type' => 'error', 'msg' => 'Failed to move uploaded file.'];
                        }
                    } else {
                        $alerts[] = ['type' => 'error', 'msg' => 'Upload directory not available.'];
                    }
                }
            } else {
                $alerts[] = ['type' => 'error', 'msg' => 'Upload failed. Please try again.'];
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Account Settings | CWD AquaSense</title>
    <link rel="icon" type="image/png" href="../assets/icons/AquaSense2.png" />
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <style>
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .sidebar { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
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
        .header-2025 { backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); background: rgba(255,255,255,0.85); border-bottom: 1px solid rgba(255,255,255,0.2); box-shadow: 0 1px 3px 0 rgba(0,0,0,0.05); }
        html { scroll-behavior: smooth; }
        /* Modal Styles */
        .modal { display: none; position: fixed; z-index: 50; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.8); }
        .modal-content { margin: auto; display: block; max-width: 90%; max-height: 90%; width: auto; height: auto; border-radius: 8px; }
        .modal-close { position: absolute; top: 15px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; cursor: pointer; }
        .modal-close:hover { color: #bbb; }
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
                            <p class="text-xs text-gray-500">Customer Portal</p>
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
                    <a href="complaints.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="complaints-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        My Complaints
                    </a>
                    <a href="feedback.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="feedback-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 0 1 .778-.332 48.294 48.294 0 0 0 5.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                        </svg>
                        Give Feedback
                    </a>
                    <a href="accountsettings.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-blue-600 bg-blue-50 border border-blue-200 transition-all duration-200 hover:bg-blue-100">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="profile-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                        Profile
                    </a>
                </nav>

                <!-- User Info & Logout -->
                <div class="p-4 border-t border-gray-100">
                    <div class="flex items-center space-x-3 mb-4">
                        <div class="avatar-glow" onclick="openModal('<?php echo htmlspecialchars($user['profile_picture'] ?: 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($user['first_name'] . ' ' . $user['last_name'])); ?>')">
                            <img src="<?php echo htmlspecialchars($user['profile_picture'] ?: 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($user['first_name'] . ' ' . $user['last_name'])); ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover"/>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></p>
                            <p class="text-xs text-gray-500">@<?php echo htmlspecialchars($user['username']); ?></p>
                        </div>
                    </div>
                    <a href="../logout.php" class="w-full flex items-center justify-center px-4 py-2 text-sm font-medium text-red-600 bg-red-50 rounded-lg hover:bg-red-100 transition-colors">
                        <i class="fas fa-sign-out-alt mr-2"></i>
                        Sign Out
                    </a>
                </div>
            </div>
        </div>

        <!-- Main -->
        <div class="flex-1 ml-64">
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
                                <i class="fas fa-bell text-lg"></i>
                                <div class="notification-badge">3</div>
                            </button>
                            <!-- Profile Dropdown - 2025 Style -->
                            <div class="flex items-center space-x-3 p-2 profile-card hover:bg-gray-50 rounded-xl transition-all duration-200 group cursor-pointer relative" id="profileDropdown">
                                <!-- Avatar with Glow Effect -->
                                <div class="avatar-glow" onclick="openModal('<?php echo htmlspecialchars($user['profile_picture'] ?: 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($user['first_name'] . ' ' . $user['last_name'])); ?>')">
                                    <img src="<?php echo htmlspecialchars($user['profile_picture'] ?: 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($user['first_name'] . ' ' . $user['last_name'])); ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover"/>
                                    <!-- Online Status Ring -->
                                    <div class="absolute -bottom-0.5 -right-0.5 w-4 h-4 bg-green-500 border-2 border-white rounded-full animate-gentle-pulse"></div>
                                </div>
                                <!-- User Info (Desktop Only) -->
                                <div class="hidden md:block">
                                    <p class="text-sm font-semibold text-gray-900 truncate max-w-32"><?php echo htmlspecialchars($user['first_name']); ?></p>
                                    <p class="text-xs text-gray-500 truncate max-w-32">@<?php echo htmlspecialchars($user['username']); ?></p>
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

                <!-- Grid -->
                <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                    <!-- Left: Profile Overview & Avatar -->
                    <section class="xl:col-span-1 card p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Profile Overview</h2>
                        <div class="flex items-center space-x-4 mb-6">
                            <div class="avatar-glow" onclick="openModal('<?php echo htmlspecialchars($user['profile_picture'] ?: 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($user['first_name'] . ' ' . $user['last_name'])); ?>')">
                                <img src="<?php echo htmlspecialchars($user['profile_picture'] ?: 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($user['first_name'] . ' ' . $user['last_name'])); ?>" alt="Avatar" class="w-20 h-20 rounded-full object-cover"/>
                                <i class="fas fa-expand absolute bottom-1 right-1 text-white bg-black bg-opacity-50 rounded-full p-1 text-xs" style="display: block;"></i>
                            </div>
                            <div>
                                <p class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></p>
                                <p class="text-gray-500 text-sm">Member since <?php echo date('F j, Y', strtotime($user['created_at'])); ?></p>
                            </div>
                        </div>

                        <form method="post" enctype="multipart/form-data" class="space-y-3">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>" />
                            <input type="hidden" name="action" value="upload_avatar" />
                            <label class="block">
                                <span class="text-sm font-medium text-gray-700">Change Avatar</span>
                                <input type="file" name="profile_picture" id="profile_picture_input" accept="image/*" class="mt-1 block w-full text-sm text-gray-700 bg-white border border-gray-200 rounded-lg p-2" />
                            </label>
                            <!-- Preview Image -->
                            <div id="preview-container" class="hidden flex flex-col items-center space-y-2">
                                <img id="preview" class="w-20 h-20 rounded-full object-cover border-2 border-gray-200" alt="Preview" />
                                <p class="text-xs text-gray-500 text-center">Preview</p>
                            </div>
                            <button class="w-full btn-primary rounded-lg px-4 py-2 font-medium">Upload</button>
                            <p class="text-xs text-gray-500">Accepted: JPG, PNG, WEBP. Max 3MB.</p>
                        </form>
                    </section>

                    <!-- Right: Forms -->
                    <section class="xl:col-span-2 space-y-6">
                        <!-- Update Profile Details -->
                        <div class="card p-6">
                            <h2 class="text-lg font-semibold text-gray-900 mb-4">Personal Information</h2>
                            <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>" />
                                <input type="hidden" name="action" value="update_profile" />

                                <label class="block">
                                    <span class="text-sm font-medium text-gray-700">First Name <span class="text-red-500">*</span></span>
                                    <input type="text" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" class="mt-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" required />
                                </label>
                                <label class="block">
                                    <span class="text-sm font-medium text-gray-700">Middle Name</span>
                                    <input type="text" name="middle_name" value="<?php echo htmlspecialchars($user['middle_name']); ?>" class="mt-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" />
                                </label>
                                <label class="block">
                                    <span class="text-sm font-medium text-gray-700">Last Name <span class="text-red-500">*</span></span>
                                    <input type="text" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" class="mt-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" required />
                                </label>
                                <div></div>

                                <label class="block md:col-span-1">
                                    <span class="text-sm font-medium text-gray-700">Username <span class="text-red-500">*</span></span>
                                    <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" class="mt-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" required />
                                    <span class="text-xs text-gray-500">3–30 chars, letters, numbers, dot, dash, underscore.</span>
                                </label>
                                <label class="block md:col-span-1">
                                    <span class="text-sm font-medium text-gray-700">Email <span class="text-red-500">*</span></span>
                                    <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" class="mt-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" required />
                                </label>

                                <div class="md:col-span-2 flex justify-end pt-2">
                                    <button type="submit" class="btn-primary rounded-lg px-5 py-2 font-medium">Save Changes</button>
                                </div>
                            </form>
                        </div>

                        <!-- Change Password -->
                        <div class="card p-6">
                            <h2 class="text-lg font-semibold text-gray-900 mb-4">Change Password</h2>
                            <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>" />
                                <input type="hidden" name="action" value="change_password" />

                                <label class="block md:col-span-2">
                                    <span class="text-sm font-medium text-gray-700">Current Password</span>
                                    <input type="password" name="current_password" class="mt-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" required />
                                </label>
                                <label class="block">
                                    <span class="text-sm font-medium text-gray-700">New Password</span>
                                    <input type="password" name="new_password" class="mt-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" minlength="8" required />
                                    <span class="text-xs text-gray-500">At least 8 characters.</span>
                                </label>
                                <label class="block">
                                    <span class="text-sm font-medium text-gray-700">Confirm New Password</span>
                                    <input type="password" name="confirm_password" class="mt-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" minlength="8" required />
                                </label>

                                <div class="md:col-span-2 flex justify-end pt-2">
                                    <button type="submit" class="btn-primary rounded-lg px-5 py-2 font-medium">Update Password</button>
                                </div>
                            </form>
                        </div>
                    </section>
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

    <!-- Image Modal -->
    <div id="imageModal" class="modal">
        <span class="modal-close" onclick="closeModal()">&times;</span>
        <img class="modal-content" id="modalImage" />
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
                    <i class="fas fa-user mr-3 text-blue-500"></i>
                    My Profile
                </a>
                <div class="border-t border-gray-100 my-1"></div>
                <a href="../logout.php" class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors">
                    <i class="fas fa-sign-out-alt mr-3"></i>
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

        // Add hover effects to profile dropdown
        profileDropdown.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-1px)';
        });
        profileDropdown.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });

        // Add loading animation to buttons
        document.querySelectorAll('button').forEach(button => {
            button.addEventListener('click', function() {
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

        // Profile picture preview
        document.getElementById('profile_picture_input').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const preview = document.getElementById('preview');
            const previewContainer = document.getElementById('preview-container');

            if (file && file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    previewContainer.classList.remove('hidden');
                };
                reader.readAsDataURL(file);
            } else {
                previewContainer.classList.add('hidden');
            }
        });

        // Image Modal Functions
        function openModal(imageSrc) {
            const modal = document.getElementById('imageModal');
            const modalImg = document.getElementById('modalImage');
            modal.style.display = 'block';
            modalImg.src = imageSrc;
        }

        function closeModal() {
            document.getElementById('imageModal').style.display = 'none';
        }

        // Close modal when clicking outside the image
        document.getElementById('imageModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
    </script>
</body>
</html>

<?php
// Cleanup
if (isset($stmt)) { mysqli_stmt_close($stmt); }
mysqli_close($conn);
?>