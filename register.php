<?php
// register.php (Updated: Use separate class for admin alert)
include 'db/db.php';
session_start();

/** =========================
 *  CONFIG
 *  ========================= */
define('TERMS_VERSION', '2025-09-23'); // bump when Terms change

// Set timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Session timeout duration (30 minutes)
$timeout_duration = 1800;

// Check for timeout
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: login.php?message=Session expired, please log in again.");
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

$error = "";

// Include EmailService
require_once 'config/email.php';
// Include AdminNotification (new separate file for alerts)
require_once 'config/notification.php';

// Handle POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Basic sanitization (UI), use prepared statements for DB
    $username     = trim($_POST['username'] ?? '');
    $first_name   = trim($_POST['first_name'] ?? '');
    $middle_name  = trim($_POST['middle_name'] ?? '');
    $last_name    = trim($_POST['last_name'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $password     = $_POST['password'] ?? '';
    $confirm      = $_POST['confirm_password'] ?? '';
    $terms        = isset($_POST['terms']);

    $errors = [];

    // Required checks
    if (!$terms) {
        $errors[] = "You must agree to the Terms and Conditions.";
    }
    if ($password !== $confirm) {
        $errors[] = "Passwords do not match.";
    }
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    }
    if (empty($first_name) || empty($last_name)) {
        $errors[] = "First name and last name are required.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please provide a valid email address.";
    }

    // Uniqueness checks (prepared)
    if (empty($errors)) {
        // Username
        $stmt = $conn->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) $errors[] = "Username already exists.";
        $stmt->close();

        // Email
        $stmt = $conn->prepare("SELECT 1 FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) $errors[] = "Email already registered.";
        $stmt->close();
    }

    if (empty($errors)) {
        // Create account
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $terms_version = TERMS_VERSION; // Assign constant to variable for bind_param reference

        $stmt = $conn->prepare("
            INSERT INTO users
            (username, first_name, middle_name, last_name, email, password, created_at,
             accepted_terms_version, accepted_terms_at, accepted_terms_ip, accepted_terms_ua)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, NOW(), ?, ?)
        ");
        $stmt->bind_param(
            "sssssssss",
            $username, $first_name, $middle_name, $last_name, $email, $hashed_password,
            $terms_version, $ip, $ua
        );

        if ($stmt->execute()) {
            $stmt->close();
            
            // Send welcome email to new user (optional)
            $emailService = new EmailService();
            $emailService->sendWelcomeEmail($email, $username);
            
            // Send registration alert to admin (using separate class)
            $userDetails = [
                'username' => $username,
                'first_name' => $first_name,
                'middle_name' => $middle_name,
                'last_name' => $last_name,
                'email' => $email,
                'ip' => $ip,
                // 'user_agent' => $ua,  // Removed as per request
            ];
            $adminService = new AdminNotification();
            $adminService->sendRegistrationAlert($userDetails);
            
            header("Location: login.php?message=Account created successfully! Please log in.");
            exit();
        } else {
            $error = "Registration failed. Please try again.";
            $stmt->close();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Register | CWD AquaSense</title>
  <link rel="icon" type="image/png" href="assets/icons/AquaSense2.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    body { font-family:'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
    .form-input{transition:all .2s cubic-bezier(.4,0,.2,1);background:linear-gradient(#fff,#fff) padding-box,linear-gradient(to right,#3b82f6,#1d4ed8) border-box;}
    .form-input:focus{transform:translateY(-1px);box-shadow:0 10px 25px -3px rgba(0,0,0,.1),0 4px 6px -2px rgba(0,0,0,.05);}
    .btn-primary{transition:all .2s cubic-bezier(.4,0,.2,1);background:linear-gradient(135deg,#3b82f6 0%,#1d4ed8 100%);box-shadow:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -1px rgba(0,0,0,.06);}
    .btn-primary:hover{transform:translateY(-2px);box-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 10px 10px -5px rgba(0,0,0,.04);}
    .card{background:linear-gradient(145deg,#ffffff,#f8fafc);border:1px solid rgba(0,0,0,.05);}
    .logo-container{background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1px solid rgba(59,130,246,.1);}
    @keyframes fadeInUp{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
    .fade-in-up{animation:fadeInUp .6s ease-out;}
    .svg-icon{transition:color .2s;}
    .group:focus-within .svg-icon{color:#3b82f6;}
    .password-toggle{cursor:pointer;transition:color .2s;}
    .password-toggle:hover{color:#3b82f6;}
    .group:focus-within .password-toggle{color:#3b82f6;}
    .name-section{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;}
    @media (max-width:640px){.name-section{grid-template-columns:1fr;gap:.75rem;}}
    /* Additional for animations */
    .translate-y-0 { transform: translateY(0); }
    .translate-y-2 { transform: translateY(0.5rem); }
    .opacity-100 { opacity: 1; }
    .opacity-0 { opacity: 0; }
  </style>
</head>
<body class="bg-gray-50">
  <div class="min-h-screen flex flex-col justify-center py-8 sm:px-6 lg:px-8 relative overflow-hidden">
    <div class="absolute inset-0 bg-gradient-to-br from-blue-50/20 via-white/50 to-indigo-50/20"></div>
    <div class="sm:mx-auto sm:w-full sm:max-w-lg relative z-10 fade-in-up">
      <div class="text-center mb-8">
        <div class="logo-container w-16 h-16 flex items-center justify-center mx-auto rounded-2xl shadow-sm mb-6 p-1">
          <div class="bg-white rounded-xl w-14 h-14 flex items-center justify-center shadow-sm">
            <img class="h-7 w-7" src="assets/icons/CWD.png" alt="CWD Logo">
          </div>
        </div>
        <h1 class="text-3xl font-bold leading-9 tracking-tight text-gray-900 mb-2">Create Account</h1>
        <p class="text-sm text-gray-600 max-w-sm mx-auto">
          <span class="block font-medium">Calamba Water District</span>
          <span class="text-blue-600 font-semibold">AquaSense Management System</span>
        </p>
      </div>

      <div class="card py-7 px-6 shadow-xl rounded-2xl fade-in-up">
        <?php if (!empty($error)) : ?>
          <div class="mb-6 p-3.5 bg-gradient-to-r from-red-50 to-pink-50 border border-red-200/50 rounded-xl backdrop-blur-sm">
            <div class="flex items-start">
              <div class="flex-shrink-0 pt-0.5"><i class="fas fa-exclamation-circle text-red-400 text-base"></i></div>
              <div class="ml-3 flex-1">
                <p class="text-sm text-red-800 leading-5 font-medium"><?= nl2br(htmlspecialchars($error)); ?></p>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if (!empty($_GET['message'])) : ?>
          <div class="mb-6 p-3.5 bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200/50 rounded-xl backdrop-blur-sm">
            <div class="flex items-start">
              <div class="flex-shrink-0 pt-0.5"><i class="fas fa-check-circle text-green-400 text-base"></i></div>
              <div class="ml-3 flex-1">
                <p class="text-sm text-green-800 leading-5 font-medium"><?= htmlspecialchars($_GET['message']); ?></p>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <form class="space-y-5" action="" method="POST" novalidate>
          <!-- Username -->
          <div class="group">
            <label for="username" class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-blue-600 mr-2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
              </svg>
              Username
            </label>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-400 group-focus-within:text-blue-600 transition-colors">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                </svg>
              </div>
              <input id="username" name="username" type="text" required
                class="form-input appearance-none block w-full px-10 py-3 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm peer"
                placeholder="Enter your username" autocomplete="username"
                value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>">
            </div>
          </div>

          <!-- Name Section -->
          <div class="name-section">
            <div class="group">
              <label for="first_name" class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-blue-600 mr-2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                </svg>
                First Name
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-400 group-focus-within:text-blue-600 transition-colors">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                  </svg>
                </div>
                <input id="first_name" name="first_name" type="text" required
                  class="form-input appearance-none block w-full px-10 py-3 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm peer"
                  placeholder="Enter first name" autocomplete="given-name"
                  value="<?= isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : '' ?>">
              </div>
            </div>

            <div class="group">
              <label for="middle_name" class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-blue-600 mr-2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                </svg>
                Middle Name
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-400 group-focus-within:text-blue-600 transition-colors">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                  </svg>
                </div>
                <input id="middle_name" name="middle_name" type="text"
                  class="form-input appearance-none block w-full px-10 py-3 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm peer"
                  placeholder="Enter middle name (optional)" autocomplete="additional-name"
                  value="<?= isset($_POST['middle_name']) ? htmlspecialchars($_POST['middle_name']) : '' ?>">
              </div>
            </div>

            <div class="group">
              <label for="last_name" class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-blue-600 mr-2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                </svg>
                Last Name
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-400 group-focus-within:text-blue-600 transition-colors">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                  </svg>
                </div>
                <input id="last_name" name="last_name" type="text" required
                  class="form-input appearance-none block w-full px-10 py-3 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm peer"
                  placeholder="Enter last name" autocomplete="family-name"
                  value="<?= isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : '' ?>">
              </div>
            </div>
          </div>

          <!-- Email -->
          <div class="group">
            <label for="email" class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-blue-600 mr-2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
              </svg>
              Email Address
            </label>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-gray-400 group-focus-within:text-blue-600 transition-colors">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                </svg>
              </div>
              <input id="email" name="email" type="email" required
                class="form-input appearance-none block w-full px-10 py-3 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm peer"
                placeholder="Enter your email" autocomplete="email"
                value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
            </div>
          </div>

          <!-- Password -->
          <div class="group">
            <label for="password" class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-blue-600 mr-2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" />
              </svg>
              Password
            </label>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 svg-icon text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z"/></svg>
              </div>
              <input id="password" name="password" type="password" required
                class="form-input appearance-none block w-full px-11 py-3 pr-10 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm peer"
                placeholder="Create a password (8+ characters)" autocomplete="new-password"
                value="<?= isset($_POST['password']) ? htmlspecialchars($_POST['password']) : '' ?>">
              <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                <span id="togglePassword" class="password-toggle text-gray-400 cursor-pointer" onclick="togglePassword()" aria-label="Show password">
                  <svg id="passwordEyeIcon" xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                  </svg>
                </span>
              </div>
            </div>
          </div>

          <!-- Confirm Password -->
          <div class="group">
            <label for="confirm_password" class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-blue-600 mr-2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
              </svg>
              Confirm Password
            </label>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 svg-icon text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 10.5V6.75a4.5 4.5 0 0 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
              </div>
              <input id="confirm_password" name="confirm_password" type="password" required
                class="form-input appearance-none block w-full px-11 py-3 pr-10 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm peer"
                placeholder="Confirm your password" autocomplete="new-password"
                value="<?= isset($_POST['confirm_password']) ? htmlspecialchars($_POST['confirm_password']) : '' ?>">
              <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                <span id="toggleConfirmPassword" class="password-toggle text-gray-400 cursor-pointer" onclick="toggleConfirmPassword()" aria-label="Show password">
                  <svg id="confirmPasswordEyeIcon" xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                  </svg>
                </span>
              </div>
            </div>
            <!-- Password Mismatch Error Div -->
            <div id="passwordError" class="hidden w-full mt-2 p-3 bg-gradient-to-r from-red-50 to-pink-50 border border-red-200/50 rounded-xl backdrop-blur-sm transition-all duration-200 ease-in-out opacity-0 translate-y-2">
              <div class="flex items-center justify-center">
                <div class="flex-shrink-0 mr-3">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-red-400">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                  </svg>
                </div>
                <p class="text-sm text-red-800 leading-5 font-medium text-center">Passwords do not match.</p>
              </div>
            </div>
          </div>

          <!-- Terms Checkbox (with modal triggers and error div) -->
          <div class="space-y-2">
            <div class="flex items-start pt-1">
              <div class="flex-shrink-0 mt-0.5">
                <input id="terms" name="terms" type="checkbox" required
                  class="h-4 w-4 text-blue-600 border-2 border-gray-300 rounded focus:ring-blue-500 focus:ring-2 mt-1">
              </div>
              <div class="ml-3 flex-1">
                <label for="terms" class="block text-sm text-gray-700 font-medium select-none">
                  I agree to the
                  <button type="button" class="text-blue-600 hover:text-blue-700 underline" onclick="openTermsModal()">Terms and Conditions</button>
                  and
                  <button type="button" class="text-blue-600 hover:text-blue-700 underline" onclick="openPrivacyModal()">Privacy Notice</button>.
                </label>
              </div>
            </div>
            <!-- Terms Error Message Div - Full width, centered content -->
            <div id="termsError" class="hidden w-full p-3 bg-gradient-to-r from-red-50 to-pink-50 border border-red-200/50 rounded-xl backdrop-blur-sm transition-all duration-200 ease-in-out opacity-0 translate-y-2">
              <div class="flex items-center justify-center">
                <div class="flex-shrink-0 mr-3">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-red-400">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                  </svg>
                </div>
                <p class="text-sm text-red-800 leading-5 font-medium text-center">Please agree to the Terms and Conditions to continue.</p>
              </div>
            </div>
          </div>

          <!-- Submit -->
          <div>
            <button type="submit"
              class="btn-primary group relative w-full flex justify-center items-center py-3 px-6 border border-transparent text-sm font-bold rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
              <span class="absolute left-4">
                <i class="fas fa-user-plus text-blue-100 group-hover:text-white transition-colors text-sm"></i>
              </span>
              <span class="relative">Create Account</span>
            </button>
          </div>
        </form>

        <!-- Divider -->
        <div class="mt-6 relative">
          <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
          <div class="relative flex justify-center text-sm">
            <span class="px-3 bg-white text-gray-500 font-medium">Already have an account?</span>
          </div>
        </div>

        <!-- Login Link -->
        <div class="mt-5">
          <a href="login.php"
            class="w-full inline-flex justify-center items-center py-3 px-6 border-2 border-blue-200 rounded-xl shadow-sm bg-gradient-to-r from-blue-50 to-indigo-50 text-sm font-bold text-gray-700 hover:from-blue-100 hover:to-indigo-100 hover:border-blue-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all duration-200 group">
            <i class="fas fa-sign-in-alt mr-2 text-blue-600 group-hover:text-blue-700 transition-colors"></i>
            Sign In to AquaSense
          </a>
        </div>
      </div>

      <div class="mt-10 text-center">
        <p class="text-xs text-gray-500 leading-5">
          <i class="fas fa-shield-alt text-blue-600 mr-1"></i>
          <span class="font-medium">Protected by</span> Calamba Water District Security
        </p>
        <p class="text-xs text-gray-400 mt-1">© 2025 CWD AquaSense</p>
      </div>
    </div>
  </div>

  <!-- Terms Modal -->
  <div id="termsModal" class="fixed inset-0 hidden bg-black bg-opacity-40 z-50 items-center justify-center">
    <div class="bg-white rounded-2xl shadow-2xl w-11/12 max-w-2xl max-h-[80vh] overflow-hidden">
      <div class="px-6 py-4 border-b flex items-center justify-between">
        <h3 class="text-lg font-bold">Terms and Conditions (v<?= htmlspecialchars(TERMS_VERSION) ?>)</h3>
        <button class="text-gray-500" onclick="closeTermsModal()">✕</button>
      </div>
      <div class="p-6 overflow-y-auto space-y-4 text-sm">
        <p class="text-gray-700">This is a quick summary. Read the full terms on
          <a href="terms.php" target="_blank" class="text-blue-600 underline">Terms and Conditions</a>.
        </p>
        <ol class="list-decimal list-inside space-y-2 text-gray-700">
          <li><strong>Accounts</strong> – Provide accurate info; keep credentials secure.</li>
          <li><strong>Acceptable Use</strong> – No illegal/abusive use; don’t bypass security.</li>
          <li><strong>User Content</strong> – You own your content; limited license to operate the Service.</li>
          <li><strong>Privacy</strong> – See our <a href="privacy.php" target="_blank" class="text-blue-600 underline">Privacy Notice</a>.</li>
          <li><strong>Availability</strong> – Possible maintenance/downtime.</li>
          <li><strong>Security</strong> – Reasonable safeguards; report incidents.</li>
          <li><strong>Termination</strong> – We may suspend/terminate for violations.</li>
          <li><strong>Changes</strong> – We may update Terms; re-consent may be required.</li>
          <li><strong>Law</strong> – Governed by Philippine law (venue: Laguna).</li>
          <li><strong>Contact</strong> – support@cwd.example.ph</li>
        </ol>
      </div>
      <div class="px-6 py-4 border-t flex justify-end">
        <button class="px-4 py-2 rounded-lg bg-blue-600 text-white font-semibold" onclick="closeTermsModal()">I Understand</button>
      </div>
    </div>
  </div>

  <!-- Privacy Modal -->
  <div id="privacyModal" class="fixed inset-0 hidden bg-black bg-opacity-40 z-50 items-center justify-center">
    <div class="bg-white rounded-2xl shadow-2xl w-11/12 max-w-2xl max-h-[80vh] overflow-hidden">
      <div class="px-6 py-4 border-b flex items-center justify-between">
        <h3 class="text-lg font-bold">Privacy Notice (Summary)</h3>
        <button class="text-gray-500" onclick="closePrivacyModal()">✕</button>
      </div>
      <div class="p-6 overflow-y-auto space-y-3 text-sm text-gray-700">
        <p>We collect data (complaints, chatbot interactions, usage logs, surveys) to operate & improve AquaSense. Rights: access, correct, erase, withdraw consent, lodge complaints.</p>
        <ul class="list-disc list-inside">
          <li>Purpose: service delivery, analytics, incident resolution</li>
          <li>Retention: only as long as necessary/legal</li>
          <li>Security: HTTPS, encryption at rest (prod), RBAC, audit logs</li>
          <li>Contact: dpo@cwd.example.ph</li>
        </ul>
        <p>Full policy: <a href="privacy.php" target="_blank" class="text-blue-600 underline">Privacy Notice</a></p>
      </div>
      <div class="px-6 py-4 border-t flex justify-end">
        <button class="px-4 py-2 rounded-lg bg-blue-600 text-white font-semibold" onclick="closePrivacyModal()">Okay</button>
      </div>
    </div>
  </div>

  <script>
    // Eye icons SVG definitions
    const eyeClosed = `<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
    </svg>`;
    const eyeOpen = `<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
    </svg>`;

    // Password toggles with icon swap
    function togglePassword() {
      const el = document.getElementById('password');
      const toggleSpan = document.getElementById('togglePassword');
      const eyeIcon = document.getElementById('passwordEyeIcon');

      if (el.type === 'password') {
        el.type = 'text';
        eyeIcon.outerHTML = eyeOpen;
        toggleSpan.setAttribute('aria-label', 'Hide password');
      } else {
        el.type = 'password';
        eyeIcon.outerHTML = eyeClosed;
        toggleSpan.setAttribute('aria-label', 'Show password');
      }
    }

    function toggleConfirmPassword() {
      const el = document.getElementById('confirm_password');
      const toggleSpan = document.getElementById('toggleConfirmPassword');
      const eyeIcon = document.getElementById('confirmPasswordEyeIcon');

      if (el.type === 'password') {
        el.type = 'text';
        eyeIcon.outerHTML = eyeOpen;
        toggleSpan.setAttribute('aria-label', 'Hide password');
      } else {
        el.type = 'password';
        eyeIcon.outerHTML = eyeClosed;
        toggleSpan.setAttribute('aria-label', 'Show password');
      }
    }

    // Match border color on confirm + error message
    const passwordInput = document.getElementById('password');
    const confirmInput = document.getElementById('confirm_password');
    function validateMatch() {
      const p = passwordInput.value, c = confirmInput.value;
      const errorDiv = document.getElementById('passwordError');
      
      if (c && p !== c) {
        // Show error with animation
        errorDiv.classList.remove('hidden');
        errorDiv.classList.remove('opacity-0', 'translate-y-2');
        errorDiv.classList.add('opacity-100', 'translate-y-0');
      } else {
        // Hide error
        if (!errorDiv.classList.contains('hidden')) {
          errorDiv.classList.remove('opacity-100', 'translate-y-0');
          errorDiv.classList.add('opacity-0', 'translate-y-2');
          setTimeout(() => errorDiv.classList.add('hidden'), 200);
        }
      }
      
      confirmInput.classList.toggle('border-green-500', p === c && c);
      confirmInput.classList.toggle('border-red-500', p !== c && c);
    }
    passwordInput.addEventListener('input', validateMatch);
    confirmInput.addEventListener('input', validateMatch);

    // Submit button loader + terms required guard
    document.querySelector('form').addEventListener('submit', function(e){
      const termsChecked = document.getElementById('terms').checked;
      const errorDiv = document.getElementById('termsError');
      
      if(!termsChecked){
        e.preventDefault();
        // Show error with animation
        errorDiv.classList.remove('hidden');
        errorDiv.classList.remove('opacity-0', 'translate-y-2');
        errorDiv.classList.add('opacity-100', 'translate-y-0');
        
        // Auto-hide after 5s
        setTimeout(() => {
          errorDiv.classList.remove('opacity-100', 'translate-y-0');
          errorDiv.classList.add('opacity-0', 'translate-y-2');
          setTimeout(() => errorDiv.classList.add('hidden'), 200);
        }, 5000);
        
        // Listen for checkbox change to hide error (once per attempt)
        const hideOnCheck = function() {
          if (this.checked) {
            errorDiv.classList.remove('opacity-100', 'translate-y-0');
            errorDiv.classList.add('opacity-0', 'translate-y-2');
            setTimeout(() => errorDiv.classList.add('hidden'), 200);
            document.getElementById('terms').removeEventListener('change', hideOnCheck);
          }
        };
        document.getElementById('terms').addEventListener('change', hideOnCheck);
        
        return;
      }
      
      // If terms OK, proceed with loader
      const btn = e.target.querySelector('button[type="submit"]');
      const original = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Creating Account...';
      setTimeout(()=>{btn.disabled=false; btn.innerHTML=original;},3000);
    });

    // Modals
    function openTermsModal(){const m=document.getElementById('termsModal'); m.classList.remove('hidden'); m.classList.add('flex');}
    function closeTermsModal(){const m=document.getElementById('termsModal'); m.classList.add('hidden'); m.classList.remove('flex');}
    function openPrivacyModal(){const m=document.getElementById('privacyModal'); m.classList.remove('hidden'); m.classList.add('flex');}
    function closePrivacyModal(){const m=document.getElementById('privacyModal'); m.classList.add('hidden'); m.classList.remove('flex');}
  </script>
</body>
</html>