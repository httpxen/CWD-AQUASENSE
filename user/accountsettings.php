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
    <link rel="stylesheet" href="css/accountsettings.css">
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
                    <a href="chatbot.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
                        </svg>
                        Chatbot
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
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-2 text-red-600">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                        </svg>
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
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0M3.124 7.5A8.969 8.969 0 0 1 5.292 3m13.416 0a8.969 8.969 0 0 1 2.168 4.5" />
                                </svg>
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
                            <div class="avatar-glow relative" onclick="openModal('<?php echo htmlspecialchars($user['profile_picture'] ?: 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($user['first_name'] . ' ' . $user['last_name'])); ?>')">
                                <img src="<?php echo htmlspecialchars($user['profile_picture'] ?: 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode($user['first_name'] . ' ' . $user['last_name'])); ?>" alt="Avatar" class="w-20 h-20 rounded-full object-cover"/>
                                <i class="fas fa-expand absolute bottom-1 right-1 text-white bg-black bg-opacity-50 rounded-full p-1 text-xs" style="display: block;"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xl font-bold text-gray-900 truncate"><?php echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?></p>
                                <p class="text-sm text-gray-600 mt-1">@<?php echo htmlspecialchars($user['username']); ?></p>
                                <p class="text-xs text-gray-500 mt-1">Member since <?php echo date('F j, Y', strtotime($user['created_at'])); ?></p>
                            </div>
                        </div>

                        <form method="post" enctype="multipart/form-data" class="space-y-3">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>" />
                            <input type="hidden" name="action" value="upload_avatar" />
                            <div class="upload-zone">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="mx-auto mb-2">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                </svg>
                                <p class="text-sm font-medium text-gray-700 mb-1">Drop your photo here, or <label for="profile_picture_input" class="text-blue-600 hover:text-blue-800 cursor-pointer">browse</label></p>
                                <p class="text-xs text-gray-500">JPG, PNG, WEBP. Max 3MB. Recommended: 400x400px.</p>
                                <input type="file" name="profile_picture" id="profile_picture_input" accept="image/jpeg,image/png,image/webp" class="hidden" />
                            </div>
                            <!-- Preview Image -->
                            <div id="preview-container" class="hidden flex flex-col items-center space-y-2">
                                <img id="preview" class="w-20 h-20 rounded-full object-cover border-2 border-gray-200" alt="Preview" />
                                <p class="text-xs text-gray-500 text-center">Preview</p>
                            </div>
                            <button type="submit" class="w-full btn-primary rounded-lg px-4 py-2 font-medium">Upload Avatar</button>
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

                                <div class="md:col-span-2">
                                    <label class="block mb-2">
                                        <span class="text-sm font-medium text-gray-700">Full Name <span class="text-red-500">*</span></span>
                                    </label>
                                    <div class="flex space-x-3">
                                        <input type="text" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" placeholder="First Name" class="flex-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" required />
                                        <input type="text" name="middle_name" value="<?php echo htmlspecialchars($user['middle_name']); ?>" placeholder="Middle Name" class="flex-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" />
                                        <input type="text" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" placeholder="Last Name" class="flex-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" required />
                                    </div>
                                </div>

                                <label class="block">
                                    <span class="text-sm font-medium text-gray-700">Username <span class="text-red-500">*</span></span>
                                    <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" class="mt-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" required />
                                    <span class="text-xs text-gray-500">3–30 characters (letters, numbers, ., -, _)</span>
                                </label>
                                <label class="block">
                                    <span class="text-sm font-medium text-gray-700">Email Address <span class="text-red-500">*</span></span>
                                    <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" class="mt-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" required />
                                    <span class="text-xs text-gray-500">We'll never share your email with anyone else.</span>
                                </label>

                                <div class="md:col-span-2 flex justify-end pt-2 space-x-3">
                                    <button type="button" class="px-5 py-2 text-sm font-medium rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 transition-colors">Cancel</button>
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
                                    <input type="password" name="current_password" class="mt-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" required autocomplete="current-password" />
                                    <span class="text-xs text-gray-500">Enter your current password to proceed.</span>
                                </label>
                                <label class="block">
                                    <span class="text-sm font-medium text-gray-700">New Password <span class="text-red-500">*</span></span>
                                    <input type="password" name="new_password" class="mt-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" minlength="8" required autocomplete="new-password" />
                                    <span class="text-xs text-gray-500">At least 8 characters, including uppercase, lowercase, and number.</span>
                                </label>
                                <label class="block">
                                    <span class="text-sm font-medium text-gray-700">Confirm New Password <span class="text-red-500">*</span></span>
                                    <input type="password" name="confirm_password" class="mt-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" minlength="8" required autocomplete="new-password" />
                                </label>

                                <div class="md:col-span-2 flex justify-end pt-2 space-x-3">
                                    <button type="button" class="px-5 py-2 text-sm font-medium rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 transition-colors">Cancel</button>
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
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 text-blue-500">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    My Profile
                </a>
                <div class="border-t border-gray-100 my-1"></div>
                <a href="../logout.php" class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3 text-red-600">
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