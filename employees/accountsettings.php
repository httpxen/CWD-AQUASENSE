<?php
include 'session_check.php'; // Employee session check

// ---------------------------
// Session timeout (30 minutes)
// ---------------------------
$timeout_duration = 1800;
if (!isset($_SESSION['staff_id'])) {
    header("Location: ../../admin_login.php?message=Please log in to access the dashboard.");
    exit();
}
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: ../../admin_login.php?message=Session expired, please log in again.");
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
        return '../../' . $profile_picture;   // relative to employees/
    }
    return 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($name);
}

$alerts = [];

// ---------------------------
// Fetch staff info (Employee only)
// ---------------------------
$staff_query = "SELECT staff_id, name, profile_picture, email, role, created_at 
                FROM staff 
                WHERE staff_id = ? AND role = 'Employee'";
$stmt = mysqli_prepare($conn, $staff_query);
mysqli_stmt_bind_param($stmt, "i", $staff_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$staff = mysqli_fetch_assoc($result);

if (!$staff) {
    session_unset();
    session_destroy();
    header("Location: ../../admin_login.php?message=Employee account not found.");
    exit();
}

// ---------------------------
// Handle POST actions
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $alerts[] = ['type' => 'error', 'msg' => 'Invalid request token. Please refresh and try again.'];
    } else {
        $action = $_POST['action'] ?? '';

        // ---------- Update profile ----------
        if ($action === 'update_profile') {
            $name  = sanitize($_POST['name'] ?? '');
            $email = sanitize($_POST['email'] ?? '');

            if ($name === '' || $email === '') {
                $alerts[] = ['type' => 'error', 'msg' => 'Please fill out Name and Email.'];
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $alerts[] = ['type' => 'error', 'msg' => 'Please enter a valid email address.'];
            } else {
                $check_sql = "SELECT staff_id FROM staff WHERE email = ? AND staff_id <> ? LIMIT 1";
                $chk = mysqli_prepare($conn, $check_sql);
                mysqli_stmt_bind_param($chk, "si", $email, $staff_id);
                mysqli_stmt_execute($chk);
                $dupe = mysqli_stmt_get_result($chk);
                if (mysqli_fetch_assoc($dupe)) {
                    $alerts[] = ['type' => 'error', 'msg' => 'Email is already taken by another account.'];
                } else {
                    $upd_sql = "UPDATE staff SET name = ?, email = ? WHERE staff_id = ?";
                    $upd = mysqli_prepare($conn, $upd_sql);
                    mysqli_stmt_bind_param($upd, "ssi", $name, $email, $staff_id);
                    if (mysqli_stmt_execute($upd)) {
                        $staff['name']  = $name;
                        $staff['email'] = $email;
                        $alerts[] = ['type' => 'success', 'msg' => 'Profile updated successfully.'];
                    } else {
                        $alerts[] = ['type' => 'error', 'msg' => 'Failed to update profile. Please try again.'];
                    }
                    mysqli_stmt_close($upd);
                }
                mysqli_stmt_close($chk);
            }
        }

        // ---------- Change password ----------
        if ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if ($current === '' || $new === '' || $confirm === '') {
                $alerts[] = ['type' => 'error', 'msg' => 'Please complete all password fields.'];
            } elseif (strlen($new) < 8) {
                $alerts[] = ['type' => 'error', 'msg' => 'New password must be at least 8 characters long.'];
            } elseif ($new !== $confirm) {
                $alerts[] = ['type' => 'error', 'msg' => 'New password and confirmation do not match.'];
            } else {
                $pass_q = "SELECT password FROM staff WHERE staff_id = ?";
                $stmt_p = mysqli_prepare($conn, $pass_q);
                mysqli_stmt_bind_param($stmt_p, "i", $staff_id);
                mysqli_stmt_execute($stmt_p);
                $res = mysqli_stmt_get_result($stmt_p);
                $stored = mysqli_fetch_assoc($res)['password'] ?? '';
                mysqli_stmt_close($stmt_p);

                if (!password_verify($current, $stored)) {
                    $alerts[] = ['type' => 'error', 'msg' => 'Your current password is incorrect.'];
                } else {
                    $hash = password_hash($new, PASSWORD_DEFAULT);
                    $up = mysqli_prepare($conn, "UPDATE staff SET password = ? WHERE staff_id = ?");
                    mysqli_stmt_bind_param($up, "si", $hash, $staff_id);
                    if (mysqli_stmt_execute($up)) {
                        $alerts[] = ['type' => 'success', 'msg' => 'Password changed successfully.'];
                    } else {
                        $alerts[] = ['type' => 'error', 'msg' => 'Failed to change password. Please try again.'];
                    }
                    mysqli_stmt_close($up);
                }
            }
        }

        // ---------- Upload avatar ----------
        if ($action === 'upload_avatar' && isset($_FILES['profile_picture'])) {
            $file = $_FILES['profile_picture'];
            if ($file['error'] === UPLOAD_ERR_OK) {
                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                    'image/gif'  => 'gif',
                ];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if (!$finfo) {
                    $alerts[] = ['type' => 'error', 'msg' => 'Fileinfo extension not available.'];
                } else {
                    $mime = finfo_file($finfo, $file['tmp_name']);
                    finfo_close($finfo);

                    if (!isset($allowed[$mime])) {
                        $alerts[] = ['type' => 'error', 'msg' => 'Invalid image type. JPG, PNG, WEBP, GIF only.'];
                    } elseif ($file['size'] > 3 * 1024 * 1024) {
                        $alerts[] = ['type' => 'error', 'msg' => 'Image too large. Max 3MB.'];
                    } else {
                        $ext = $allowed[$mime];
                        $safeName = 'avatar_s' . $staff_id . '_' . time() . '.' . $ext;
                        $uploadDir = '../assets/uploads/staff/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }
                        $dest = $uploadDir . $safeName;
                        if (move_uploaded_file($file['tmp_name'], $dest)) {
                            $relativePath = 'assets/uploads/staff/' . $safeName;

                            // delete old picture
                            if ($staff['profile_picture'] && file_exists('../' . $staff['profile_picture'])) {
                                unlink('../' . $staff['profile_picture']);
                            }

                            $up = mysqli_prepare($conn, "UPDATE staff SET profile_picture = ? WHERE staff_id = ?");
                            mysqli_stmt_bind_param($up, "si", $relativePath, $staff_id);
                            if (mysqli_stmt_execute($up)) {
                                $staff['profile_picture'] = $relativePath;
                                $alerts[] = ['type' => 'success', 'msg' => 'Profile picture updated.'];
                            } else {
                                $alerts[] = ['type' => 'error', 'msg' => 'Failed to save avatar path.'];
                            }
                            mysqli_stmt_close($up);
                        } else {
                            $alerts[] = ['type' => 'error', 'msg' => 'Failed to upload. Check folder permissions.'];
                        }
                    }
                }
            } else {
                $alerts[] = ['type' => 'error', 'msg' => 'Upload failed. Try again.'];
            }
        }
    }
}

// close the fetch statement
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings | CWD AquaSense</title>
    <link rel="icon" type="image/png" href="../../assets/icons/AquaSense2.png">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body{font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}
        .sidebar{width:256px;transition:all .3s cubic-bezier(.4,0,.2,1);}
        .card{background:linear-gradient(145deg,#fff,#f8fafc);border:1px solid rgba(0,0,0,.05);border-radius:1rem;}
        .btn-primary{background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;transition:all .2s cubic-bezier(.4,0,.2,1);}
        .btn-primary:hover{transform:translateY(-2px);box-shadow:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -2px rgba(0,0,0,.05);}
        .status{border-radius:.5rem;padding:.75rem 1rem;}
        .avatar-glow{position:relative;cursor:pointer;}
        .avatar-glow::before{content:'';position:absolute;top:-2px;left:-2px;right:-2px;bottom:-2px;background:linear-gradient(45deg,#3b82f6,#8b5cf6,#06b6d4,#3b82f6);border-radius:50%;z-index:-1;opacity:0;transition:opacity .3s ease;}
        .avatar-glow:hover::before{opacity:1;}
        .notification-badge{position:absolute;top:-2px;right:-2px;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;border-radius:50%;width:18px;height:18px;font-size:10px;display:flex;align-items:center;justify-content:center;font-weight:600;box-shadow:0 2px 4px rgba(239,68,68,.3);}
        .group:hover .fa-chevron-down{transform:rotate(180deg);transition:transform .2s ease;}
        @keyframes gentle-pulse{0%,100%{opacity:1}50%{opacity:.5}}
        .animate-gentle-pulse{animation:gentle-pulse 2s infinite;}
        .profile-card{transition:all .2s cubic-bezier(.4,0,.2,1);}
        .profile-card:hover{transform:translateY(-1px);box-shadow:0 4px 12px -2px rgba(0,0,0,.08);}
        .header-2025{backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);background:rgba(255,255,255,.85);border-bottom:1px solid rgba(255,255,255,.2);box-shadow:0 1px 3px 0 rgba(0,0,0,.05);margin-left:256px;width:calc(100% - 256px);}
        main{margin-left:256px;padding:1.5rem;}
        @media(max-width:767px){.header-2025{margin-left:0;width:100%;}main{margin-left:0;}.sidebar{transform:translateX(-100%);}.sidebar.translate-x-0{transform:translateX(0);}}
        .modal{display:none;position:fixed;z-index:50;left:0;top:0;width:100%;height:100%;overflow:auto;background-color:rgba(0,0,0,.8);}
        .modal-content{margin:auto;display:block;max-width:90%;max-height:90%;border-radius:8px;}
        .modal-close{position:absolute;top:15px;right:35px;color:#f1f1f1;font-size:40px;font-weight:bold;cursor:pointer;}
        .modal-close:hover{color:#bbb;}
        .upload-zone{border:2px dashed #d1d5db;border-radius:.5rem;padding:1.5rem;text-align:center;background:#f9fafb;transition:border-color .2s ease;cursor:pointer;}
        .upload-zone:hover{border-color:#3b82f6;}
        .upload-zone svg{width:2rem;height:2rem;color:#6b7280;margin-bottom:.5rem;}
        .upload-zone.drag-over {
            border-color: #3b82f6;
            background-color: #eff6ff;
            transform: scale(1.02);
        }
    </style>
</head>
<body class="bg-gray-50">
<div class="min-h-screen flex">

    <!-- ==================== SIDEBAR ==================== -->
    <div class="sidebar w-64 bg-white shadow-lg fixed h-full z-30">
        <div class="flex flex-col h-full">
            <div class="p-6">
                <div class="flex items-center space-x-3">
                    <img src="../../assets/icons/AquaSense.png" alt="Logo" class="w-16 h-16 rounded-lg object-contain bg-white p-1">
                    <div class="flex-1">
                        <h1 class="text-xl font-bold text-gray-900">AquaSense</h1>
                        <p class="text-xs text-gray-500">Employee Portal</p>
                    </div>
                </div>
            </div>

            <nav class="flex-1 py-2 px-4 space-y-2">
                <a href="dashboard.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/>
                    </svg>
                    Dashboard
                </a>

                <a href="manage_complaints.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                    </svg>
                    Manage Complaints
                </a>

                <a href="view_feedback.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0Zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 01.778-.332 48.294 48.294 0 005.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z"/>
                    </svg>
                    View Feedback
                </a>
            </nav>

            <div class="p-4 border-t border-gray-100">
                <div class="flex items-center space-x-3 mb-4">
                    <div class="relative avatar-glow" onclick="openModal('<?php echo htmlspecialchars(get_avatar_src($staff['profile_picture'], $staff['name'])); ?>')">
                        <img src="<?php echo htmlspecialchars(get_avatar_src($staff['profile_picture'], $staff['name'])); ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover">
                        <div class="absolute -bottom-0.5 -right-0.5 w-4 h-4 bg-green-500 border-2 border-white rounded-full animate-gentle-pulse"></div>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($staff['name']); ?></p>
                        <p class="text-xs text-gray-500">Employee</p>
                    </div>
                </div>

                <a href="../../admin_logout.php" class="w-full flex items-center justify-center px-4 py-2 text-sm font-medium text-red-600 bg-red-50 rounded-lg hover:bg-red-100 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-2 text-red-600">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/>
                    </svg>
                    Sign Out
                </a>
            </div>
        </div>
    </div>

    <!-- ==================== MAIN CONTENT ==================== -->
    <div class="flex-1">
        <header class="header-2025 sticky top-0 z-20">
            <div class="px-6 py-4 flex items-center justify-between">
                <div></div>
                <div class="flex items-center space-x-4">
                    <!-- Notification -->
                    <button class="relative p-2 text-gray-600 hover:text-gray-900 rounded-full hover:bg-gray-100 group" id="notificationBtn">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0M3.124 7.5A8.969 8.969 0 0 1 5.292 3m13.416 0a8.969 8.969 0 0 1 2.168 4.5"/>
                        </svg>
                        <div class="notification-badge">3</div>
                    </button>

                    <!-- Profile dropdown -->
                    <div class="flex items-center space-x-3 p-2 profile-card hover:bg-gray-50 rounded-xl transition-all duration-200 group cursor-pointer" id="profileDropdown">
                        <div class="avatar-glow" onclick="openModal('<?php echo htmlspecialchars(get_avatar_src($staff['profile_picture'], $staff['name'])); ?>')">
                            <img src="<?php echo htmlspecialchars(get_avatar_src($staff['profile_picture'], $staff['name'])); ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover">
                            <div class="absolute -bottom-0.5 -right-0.5 w-4 h-4 bg-green-500 border-2 border-white rounded-full animate-gentle-pulse"></div>
                        </div>
                        <div class="hidden md:block">
                            <p class="text-sm font-semibold text-gray-900 truncate max-w-32"><?php echo htmlspecialchars($staff['name']); ?></p>
                            <p class="text-xs text-gray-500 truncate max-w-32">Employee</p>
                        </div>
                        <i class="fas fa-chevron-down text-gray-400 text-sm ml-1 transition-transform duration-200 group-hover:text-gray-600"></i>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-6 space-y-6">

            <!-- Grid -->
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

                <!-- Left – Avatar -->
                <section class="xl:col-span-1 card p-6">
                    <h2 class="text-lg font-semibold text-gray-900 mb-4">Profile Overview</h2>
                    <div class="flex items-center space-x-4 mb-6">
                        <div class="avatar-glow relative" onclick="openModal('<?php echo htmlspecialchars(get_avatar_src($staff['profile_picture'], $staff['name'])); ?>')">
                            <img src="<?php echo htmlspecialchars(get_avatar_src($staff['profile_picture'], $staff['name'])); ?>" alt="Avatar" class="w-20 h-20 rounded-full object-cover"/>
                            <i class="fas fa-expand absolute top-1 right-1 text-white bg-black bg-opacity-50 rounded-full p-1 text-xs"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xl font-bold text-gray-900 truncate"><?php echo htmlspecialchars($staff['name']); ?></p>
                            <p class="text-sm text-gray-600 mt-1">Role: Employee</p>
                            <p class="text-xs text-gray-500 mt-1">Member since <?php echo date('F j, Y', strtotime($staff['created_at'])); ?></p>
                        </div>
                    </div>

                    <!-- Avatar upload form -->
                    <form method="post" enctype="multipart/form-data" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="upload_avatar">
                        <div class="upload-zone">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="mx-auto mb-2">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                            </svg>
                            <p class="text-sm font-medium text-gray-700 mb-1">Drop photo or <label for="profile_picture_input" class="text-blue-600 hover:text-blue-800 cursor-pointer">browse</label></p>
                            <p class="text-xs text-gray-500">JPG, PNG, WEBP, GIF. Max 3MB.</p>
                            <input type="file" name="profile_picture" id="profile_picture_input" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden">
                        </div>
                        <div id="preview-container" class="hidden flex flex-col items-center space-y-2">
                            <img id="preview" class="w-20 h-20 rounded-full object-cover border-2 border-gray-200" alt="Preview">
                            <p class="text-xs text-center text-gray-500">Preview</p>
                        </div>
                        <button type="submit" class="w-full btn-primary rounded-lg px-4 py-2 font-medium">Upload Avatar</button>
                    </form>
                </section>

                <!-- Right – Forms -->
                <section class="xl:col-span-2 space-y-6">

                    <!-- Personal info -->
                    <div class="card p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Personal Information</h2>
                        <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="update_profile">
                            <label class="block">
                                <span class="text-sm font-medium text-gray-700">Full Name <span class="text-red-500">*</span></span>
                                <input type="text" name="name" value="<?php echo htmlspecialchars($staff['name']); ?>" class="mt-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" required>
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-gray-700">Email Address <span class="text-red-500">*</span></span>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($staff['email']); ?>" class="mt-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" required>
                                <span class="text-xs text-gray-500">We'll never share your email.</span>
                            </label>
                            <div class="md:col-span-2 flex justify-end pt-2 space-x-3">
                                <button type="button" class="px-5 py-2 text-sm font-medium rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">Cancel</button>
                                <button type="submit" class="btn-primary rounded-lg px-5 py-2 font-medium">Save Changes</button>
                            </div>
                        </form>
                    </div>

                    <!-- Change password -->
                    <div class="card p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Change Password</h2>
                        <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="change_password">
                            <label class="block md:col-span-2">
                                <span class="text-sm font-medium text-gray-700">Current Password</span>
                                <input type="password" name="current_password" class="mt-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" required autocomplete="current-password">
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-gray-700">New Password <span class="text-red-500">*</span></span>
                                <input type="password" name="new_password" class="mt-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" minlength="8" required autocomplete="new-password">
                                <span class="text-xs text-gray-500">Min 8 characters.</span>
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-gray-700">Confirm New Password <span class="text-red-500">*</span></span>
                                <input type="password" name="confirm_password" class="mt-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" minlength="8" required autocomplete="new-password">
                            </label>
                            <div class="md:col-span-2 flex justify-end pt-2 space-x-3">
                                <button type="button" class="px-5 py-2 text-sm font-medium rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">Cancel</button>
                                <button type="submit" class="btn-primary rounded-lg px-5 py-2 font-medium">Update Password</button>
                            </div>
                        </form>
                    </div>

                </section>
            </div>
        </main>
    </div>
</div>

<!-- Mobile menu toggle -->
<button id="mobileMenuToggle" class="fixed top-4 left-4 z-40 p-2 rounded-lg text-gray-600 bg-white shadow-lg md:hidden">
    <i class="fas fa-bars text-lg"></i>
</button>

<!-- Profile dropdown menu -->
<div id="profileDropdownMenu" class="hidden absolute right-6 top-20 w-48 bg-white rounded-xl shadow-lg border border-gray-100 py-2 z-30"></div>

<!-- Image modal -->
<div id="imageModal" class="modal">
    <span class="modal-close" onclick="closeModal()">×</span>
    <img class="modal-content" id="modalImage">
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // Show SweetAlerts for PHP alerts
    <?php if (!empty($alerts)): ?>
        <?php foreach ($alerts as $a): ?>
            Swal.fire({
                title: '<?php echo ucfirst($a['type']); ?>!',
                text: '<?php echo addslashes($a['msg']); ?>',
                icon: '<?php echo $a['type']; ?>',
                confirmButtonColor: '#3b82f6',
                timer: <?php echo $a['type'] === 'success' ? '3000' : 'null'; ?>,
                timerProgressBar: <?php echo $a['type'] === 'success' ? 'true' : 'false'; ?>
            });
        <?php endforeach; ?>
    <?php endif; ?>

    // ---------- Mobile sidebar ----------
    document.getElementById('mobileMenuToggle').addEventListener('click', () => {
        const sb = document.querySelector('.sidebar');
        sb.classList.toggle('-translate-x-full');
        sb.classList.toggle('translate-x-0');
    });

    // ---------- Responsive sidebar ----------
    window.addEventListener('resize', () => {
        const sb = document.querySelector('.sidebar');
        if (window.innerWidth >= 768) {
            sb.classList.remove('-translate-x-full');
            sb.classList.add('translate-x-0');
        } else {
            sb.classList.remove('translate-x-0');
            sb.classList.add('-translate-x-full');
        }
    });
    // initial state
    if (window.innerWidth < 768) document.querySelector('.sidebar').classList.add('-translate-x-full');

    // ---------- Profile dropdown ----------
    const profileDropdown = document.getElementById('profileDropdown');
    const profileMenu      = document.getElementById('profileDropdownMenu');

    profileDropdown.addEventListener('click', e => {
        e.stopPropagation();
        profileMenu.classList.contains('hidden') ? showDropdown() : hideDropdown();
    });

    function showDropdown() {
        profileMenu.innerHTML = `
            <a href="accountsettings.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 text-blue-500">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                </svg>
                My Profile
            </a>
            <div class="border-t border-gray-100 my-1"></div>
            <a href="../../admin_logout.php" class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/>
                </svg>
                Sign Out
            </a>
        `;
        profileMenu.classList.remove('hidden');
        const rect = profileDropdown.getBoundingClientRect();
        profileMenu.style.right = '1.5rem';
        profileMenu.style.top   = `${rect.bottom + 8}px`;
    }

    function hideDropdown() {
        profileMenu.classList.add('hidden');
    }

    document.addEventListener('click', hideDropdown);

    // ---------- Notification ----------
    document.getElementById('notificationBtn').addEventListener('click', function(e) {
        e.stopPropagation();
        this.style.transform = 'scale(0.95)';
        setTimeout(() => this.style.transform = 'scale(1)', 150);
        Swal.fire({
            title: 'Coming Soon!',
            text: 'Notifications feature will be available shortly.',
            icon: 'info',
            confirmButtonColor: '#3b82f6',
            timer: 3000,
            timerProgressBar: true
        });
    });

    // ---------- Avatar preview ----------
    document.getElementById('profile_picture_input').addEventListener('change', e => {
        const file = e.target.files[0];
        const preview = document.getElementById('preview');
        const container = document.getElementById('preview-container');
        if (file && file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = ev => {
                preview.src = ev.target.result;
                container.classList.remove('hidden');
            };
            reader.readAsDataURL(file);
        } else {
            container.classList.add('hidden');
        }
    });

    // ---------- Drag-and-drop functionality for upload zone ----------
    const uploadZone = document.querySelector('.upload-zone');
    const fileInput = document.getElementById('profile_picture_input');

    uploadZone.addEventListener('dragover', (e) => {
        e.preventDefault(); // Prevent default to allow drop
        uploadZone.classList.add('drag-over');
    });

    uploadZone.addEventListener('dragleave', (e) => {
        e.preventDefault();
        uploadZone.classList.remove('drag-over');
    });

    uploadZone.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadZone.classList.remove('drag-over');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            const file = files[0];
            // Validate image type (matches your PHP accept)
            if (file.type.startsWith('image/') && (file.type === 'image/jpeg' || file.type === 'image/png' || file.type === 'image/webp' || file.type === 'image/gif')) {
                // File size check
                if (file.size > 3 * 1024 * 1024) { // 3MB
                    Swal.fire({
                        title: 'File Too Large',
                        text: 'Max 3MB allowed.',
                        icon: 'error',
                        timer: 2000,
                        showConfirmButton: false
                    });
                    return;
                }
                // Create DataTransfer to set files on input
                const dt = new DataTransfer();
                dt.items.add(file);
                fileInput.files = dt.files;
                
                // Trigger change event for preview
                const changeEvent = new Event('change', { bubbles: true });
                fileInput.dispatchEvent(changeEvent);
                
                // Optional: Show a quick success message
                Swal.fire({
                    title: 'File Ready!',
                    text: 'Your image is set for upload.',
                    icon: 'info',
                    timer: 1500,
                    showConfirmButton: false
                });
            } else {
                Swal.fire({
                    title: 'Invalid File',
                    text: 'Please drop a JPG, PNG, WEBP, or GIF image.',
                    icon: 'error',
                    timer: 2000,
                    showConfirmButton: false
                });
            }
        }
    });

    // Also make the entire upload-zone clickable to trigger browse (enhances UX)
    uploadZone.addEventListener('click', (e) => {
        if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'LABEL') {
            fileInput.click();
        }
    });

    // ---------- Modal ----------
    function openModal(src) {
        const modal = document.getElementById('imageModal');
        const img   = document.getElementById('modalImage');
        modal.style.display = 'block';
        img.src = src;
    }
    function closeModal() {
        document.getElementById('imageModal').style.display = 'none';
    }
    document.getElementById('imageModal').addEventListener('click', e => {
        if (e.target === this) closeModal();
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeModal();
    });
</script>
</body>
</html>
<?php
mysqli_close($conn);
?>