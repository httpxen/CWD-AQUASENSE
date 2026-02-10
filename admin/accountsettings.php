<?php
include 'session_check.php'; // From Step 2

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
// Fetch staff info using email from session
// ---------------------------
$staff_email = $_SESSION['staff_email'];
$staff_query = "SELECT staff_id, name, profile_picture, email, role, created_at FROM staff WHERE email = ?";
$stmt = mysqli_prepare($conn, $staff_query);
mysqli_stmt_bind_param($stmt, "s", $staff_email);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$staff = mysqli_fetch_assoc($result);
if (!$staff) {
    // Safety: if staff missing, logout
    session_unset();
    session_destroy();
    header("Location: login.php?message=Account not found.");
    exit();
}
$staff_id = $staff['staff_id'];
mysqli_stmt_close($stmt);

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
            $name = sanitize($_POST['name'] ?? '');
            $email = sanitize($_POST['email'] ?? '');

            // Basic validations
            if ($name === '' || $email === '') {
                $alerts[] = ['type' => 'error', 'msg' => 'Please fill out Name and Email.'];
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $alerts[] = ['type' => 'error', 'msg' => 'Please enter a valid email address.'];
            } else {
                // Check if email already used by another account
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
                        // Refresh $staff
                        $staff['name'] = $name;
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
                $pass_query = "SELECT password FROM staff WHERE staff_id = ?";
                $stmt_pass = mysqli_prepare($conn, $pass_query);
                mysqli_stmt_bind_param($stmt_pass, "i", $staff_id);
                mysqli_stmt_execute($stmt_pass);
                $pass_result = mysqli_stmt_get_result($stmt_pass);
                $stored_pass = mysqli_fetch_assoc($pass_result)['password'];
                if (!password_verify($current_password, $stored_pass)) {
                    $alerts[] = ['type' => 'error', 'msg' => 'Your current password is incorrect.'];
                } else {
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $psql = mysqli_prepare($conn, "UPDATE staff SET password = ? WHERE staff_id = ?");
                    mysqli_stmt_bind_param($psql, "si", $new_hash, $staff_id);
                    if (mysqli_stmt_execute($psql)) {
                        $alerts[] = ['type' => 'success', 'msg' => 'Password changed successfully.'];
                    } else {
                        $alerts[] = ['type' => 'error', 'msg' => 'Failed to change password. Please try again.'];
                    }
                    mysqli_stmt_close($psql);
                }
                mysqli_stmt_close($stmt_pass);
            }
        }

        // ---------------------------
        // Profile picture upload
        // ---------------------------
        if ($action === 'upload_avatar' && isset($_FILES['profile_picture'])) {
            $file = $_FILES['profile_picture'];
            if ($file['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if (!$finfo) {
                    $alerts[] = ['type' => 'error', 'msg' => 'Fileinfo extension not available.'];
                } else {
                    $mime = finfo_file($finfo, $file['tmp_name']);
                    finfo_close($finfo);

                    if (!isset($allowed[$mime])) {
                        $alerts[] = ['type' => 'error', 'msg' => 'Invalid image type. Please upload JPG, PNG, WEBP, or GIF.'];
                    } elseif ($file['size'] > 3 * 1024 * 1024) {
                        $alerts[] = ['type' => 'error', 'msg' => 'Image too large. Max 3MB.'];
                    } else {
                        $ext = $allowed[$mime];
                        $safeName = 'avatar_s' . $staff_id . '_' . time() . '.' . $ext;
                        $uploadDir = '../assets/uploads/staff/';
                        if (!is_dir($uploadDir)) {
                            if (!mkdir($uploadDir, 0755, true)) {
                                $alerts[] = ['type' => 'error', 'msg' => 'Failed to create upload directory. Check permissions.'];
                            }
                        }
                        $dest = $uploadDir . $safeName;
                        if (move_uploaded_file($file['tmp_name'], $dest)) {
                            $relativePath = 'assets/uploads/staff/' . $safeName;
                            // Delete old image if exists
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
                            $alerts[] = ['type' => 'error', 'msg' => 'Failed to move uploaded file. Check directory permissions.'];
                        }
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
    <title>Account Settings | CWD AquaSense Admin</title>
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
                            <p class="text-xs text-gray-500">Admin Portal</p>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <nav class="flex-1 py-2 px-4 space-y-2">
                    <a href="dashboard.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 dashboard-icon mr-3 flex-shrink-0">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                        </svg>
                        Dashboard
                    </a>
                    <a href="manage_complaints.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 complaints-icon mr-3 flex-shrink-0">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        Manage Complaints
                    </a>
                    <a href="manage_staff.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 profile-icon mr-3 flex-shrink-0">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                        </svg>
                        View Staff
                    </a>
                    <a href="manage_user.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 profile-icon mr-3 flex-shrink-0">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                        </svg>
                        View Users
                    </a>
                    <a href="view_feedback.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 feedback-icon mr-3 flex-shrink-0">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 9.75a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375m-13.5 3.01c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.184-4.183a1.14 1.14 0 0 1 .778-.332 48.294 48.294 0 0 0 5.83-.498c1.585-.233 2.708-1.626 2.708-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                        </svg>
                        View Feedback
                    </a>
                    <a href="announcements.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 announcement-icon mr-3 flex-shrink-0">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 0 8.835-2.535m0 0A23.74 23.74 0 0 0 18.795 3m.38 1.125a23.91 23.91 0 0 1 1.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 0 0 1.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 0 1 0 3.46" />
                        </svg>
                        Announcement Section
                    </a>
                </nav>

                <!-- Staff Info & Logout -->
                <div class="p-4 border-t border-gray-100">
                    <div class="flex items-center space-x-3 mb-4">
                        <div class="relative avatar-glow" onclick="openModal('<?php echo htmlspecialchars(get_avatar_src($staff['profile_picture'], $staff['name'])); ?>')">
                            <img src="<?php echo htmlspecialchars(get_avatar_src($staff['profile_picture'], $staff['name'])); ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover"/>
                            <div class="absolute -bottom-0.5 -right-0.5 w-4 h-4 bg-green-500 border-2 border-white rounded-full animate-gentle-pulse"></div>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($staff['name']); ?></p>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($staff['role']); ?></p>
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
                            <!-- Profile Dropdown - 2025 Style -->
                            <div class="flex items-center space-x-3 p-2 profile-card hover:bg-gray-50 rounded-xl transition-all duration-200 group cursor-pointer relative" id="profileDropdown">
                                <!-- Avatar with Glow Effect -->
                                <div class="avatar-glow" onclick="openModal('<?php echo htmlspecialchars(get_avatar_src($staff['profile_picture'], $staff['name'])); ?>')">
                                    <img src="<?php echo htmlspecialchars(get_avatar_src($staff['profile_picture'], $staff['name'])); ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover"/>
                                    <!-- Online Status Ring -->
                                    <div class="absolute -bottom-0.5 -right-0.5 w-4 h-4 bg-green-500 border-2 border-white rounded-full animate-gentle-pulse"></div>
                                </div>
                                <!-- User Info (Desktop Only) -->
                                <div class="hidden md:block">
                                    <p class="text-sm font-semibold text-gray-900 truncate max-w-32"><?php echo htmlspecialchars($staff['name']); ?></p>
                                    <p class="text-xs text-gray-500 truncate max-w-32"><?php echo htmlspecialchars($staff['role']); ?></p>
                                </div>
                                <!-- Subtle Chevron -->
                                <i class="fas fa-chevron-down text-gray-400 text-sm ml-1 transition-transform duration-200 group-hover:text-gray-600"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <main class="p-6 space-y-6">
                <!-- Grid -->
                <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                    <!-- Left: Profile Overview & Avatar -->
                    <section class="xl:col-span-1 card p-6">
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Profile Overview</h2>
                        <div class="flex items-center space-x-4 mb-6">
                            <div class="avatar-glow relative" onclick="openModal('<?php echo htmlspecialchars(get_avatar_src($staff['profile_picture'], $staff['name'])); ?>')">
                                <img src="<?php echo htmlspecialchars(get_avatar_src($staff['profile_picture'], $staff['name'])); ?>" alt="Avatar" class="w-20 h-20 rounded-full object-cover"/>
                                <i class="fas fa-expand absolute bottom-1 right-1 text-white bg-black bg-opacity-50 rounded-full p-1 text-xs" style="display: block;"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xl font-bold text-gray-900 truncate"><?php echo htmlspecialchars($staff['name']); ?></p>
                                <p class="text-sm text-gray-600 mt-1">Role: <?php echo htmlspecialchars($staff['role']); ?></p>
                                <p class="text-xs text-gray-500 mt-1">Member since <?php echo date('F j, Y', strtotime($staff['created_at'])); ?></p>
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
                                <p class="text-xs text-gray-500">JPG, PNG, WEBP, GIF. Max 3MB. Recommended: 400x400px.</p>
                                <input type="file" name="profile_picture" id="profile_picture_input" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden" />
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

                                <label class="block">
                                    <span class="text-sm font-medium text-gray-700">Full Name <span class="text-red-500">*</span></span>
                                    <input type="text" name="name" value="<?php echo htmlspecialchars($staff['name']); ?>" class="mt-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" required />
                                </label>
                                <label class="block">
                                    <span class="text-sm font-medium text-gray-700">Email Address <span class="text-red-500">*</span></span>
                                    <input type="email" name="email" value="<?php echo htmlspecialchars($staff['email']); ?>" class="mt-1 block w-full border border-gray-200 rounded-lg p-2.5 focus:ring-2 focus:ring-blue-500 focus:outline-none" required />
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

        // Drag-and-drop functionality for upload zone
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
mysqli_close($conn);
?>