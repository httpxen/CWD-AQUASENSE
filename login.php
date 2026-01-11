<?php
ob_start();
include 'db/db.php';
session_name('CustomerSession');
session_start();

require_once 'config/env.php';

date_default_timezone_set('Asia/Manila');

// ===================================================
// 1. AUTO LOGIN FROM "REMEMBER ME" COOKIE (if exists)
// ===================================================
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_me'])) {
    $token = $_COOKIE['remember_me'];

    $sql = "SELECT id, username FROM users 
            WHERE remember_token = ? AND token_expiry > NOW() 
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // Regenerate session ID for security
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['LAST_ACTIVITY'] = time();

        // Update last_login and is_active_session
        $update_stmt = $conn->prepare("UPDATE users SET last_login = NOW(), is_active_session = 1 WHERE id = ?");
        $update_stmt->bind_param("i", $user['id']);
        $update_stmt->execute();
        $update_stmt->close();

        // Optional: Extend token lifetime (sliding 30-day expiration)
        $new_token = bin2hex(random_bytes(32)); // 64 chars
        $new_expiry = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60)); // 30 days

        $update = $conn->prepare("UPDATE users SET remember_token = ?, token_expiry = ? WHERE id = ?");
        $update->bind_param("ssi", $new_token, $new_expiry, $user['id']);
        $update->execute();

        // Refresh cookie
        setcookie('remember_me', $new_token, [
            'expires' => time() + (30 * 24 * 60 * 60),
            'path' => '/',
            'secure' => true,      // Only HTTPS (set to false if testing locally without HTTPS)
            'httponly' => true,    // Blocks JavaScript access
            'samesite' => 'Lax'
        ]);

        ob_end_clean();
        header("Location: user/dashboard.php");
        exit();
    } else {
        // Invalid or expired token → clear cookie
        setcookie('remember_me', '', time() - 3600, '/');
    }
    $stmt->close();
}

// ===================================================
// 2. SESSION TIMEOUT (30 minutes of inactivity)
// ===================================================
$timeout_duration = 1800; // 30 minutes
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $update_stmt = $conn->prepare("UPDATE users SET is_active_session = 0 WHERE id = ?");
        $update_stmt->bind_param("i", $user_id);
        $update_stmt->execute();
        $update_stmt->close();
    }
    session_unset();
    session_destroy();
    setcookie('remember_me', '', time() - 3600, '/');
    ob_end_clean();
    header("Location: login.php?message=Session expired, please log in again.");
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

// ===================================================
// 3. NORMAL LOGIN PROCESS
// ===================================================
$error = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']); // This is your checkbox

    if (empty($username)) $error = "Username is required.";
    elseif (empty($password)) $error = "Password is required.";
    else {
        // ==================== CLOUDFLARE TURNSTILE VERIFICATION ====================
        $turnstile_response = $_POST['cf-turnstile-response'] ?? '';
        $turnstile_secret = $_ENV['TURNSTILE_SECRET_KEY'] ?? '';
        if (empty($turnstile_response)) {
            $error = "Please complete the security check.";
        } elseif (!empty($turnstile_secret)) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $verify = file_get_contents("https://challenges.cloudflare.com/turnstile/v0/siteverify", false, stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => 'Content-Type: application/x-www-form-urlencoded',
                    'content' => http_build_query([
                        'secret'   => $turnstile_secret,
                        'response' => $turnstile_response,
                        'remoteip' => $ip
                    ])
                ]
            ]));
            $result = json_decode($verify);

            if (!$result || !$result->success) {
                $error = "Security check failed. Please try again.";
            }
        } else {
            $error = "Security system not configured.";
        }
    }

    if (empty($error)) {
        $sql = "SELECT id, username, password FROM users WHERE username = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $row = $result->fetch_assoc();

            if (password_verify($password, $row['password'])) {
                // Success! Update last_login and is_active_session
                $update_stmt = $conn->prepare("UPDATE users SET last_login = NOW(), is_active_session = 1 WHERE id = ?");
                $update_stmt->bind_param("i", $row['id']);
                $update_stmt->execute();
                $update_stmt->close();

                // Regenerate session for security
                session_regenerate_id(true);

                $_SESSION['user_id'] = $row['id'];
                $_SESSION['username'] = $row['username'];
                $_SESSION['LAST_ACTIVITY'] = time();
                $_SESSION['welcome_message'] = htmlspecialchars($row['username']);

                // ==================== REMEMBER ME CHECKBOX ====================
                if ($remember) {
                    $token = bin2hex(random_bytes(32)); // 64-char secure random token
                    $expiry = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60)); // 30 days

                    $token_stmt = $conn->prepare("UPDATE users SET remember_token = ?, token_expiry = ? WHERE id = ?");
                    $token_stmt->bind_param("ssi", $token, $expiry, $row['id']);
                    $token_stmt->execute();

                    setcookie('remember_me', $token, [
                        'expires' => time() + (30 * 24 * 60 * 60),
                        'path' => '/',
                        'secure' => true,      // Change to false if you're testing on http://localhost
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ]);
                } else {
                    // User did NOT check "Remember me" → clear any old token
                    $conn->query("UPDATE users SET remember_token = NULL, token_expiry = NULL WHERE id = {$row['id']}");
                    setcookie('remember_me', '', time() - 3600, '/');
                }
                // ==============================================================

                ob_end_clean();
                header("Location: user/dashboard.php");
                exit();
            } else {
                $error = "Invalid password.";
            }
        } else {
            $error = "No account found with that username.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login | CWD AquaSense</title>
  <link rel="icon" type="image/png" href="assets/icons/AquaSense2.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

  <style>
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background-image: url('assets/icons/CWD.jpg');
      background-size: cover;
      background-position: center;
      background-attachment: fixed;
      background-repeat: no-repeat;
      min-height: 100vh;
      position: relative;
    }

    body::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(255, 255, 255, 0.90);
      backdrop-filter: blur(1px);
      z-index: 0;
    }

    .min-h-screen > * {
      position: relative;
      z-index: 1;
    }

    .card {
      background: rgba(255, 255, 255, 0.94) !important;
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border: 1px solid rgba(255, 255, 255, 0.4);
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
      border-radius: 1.5rem;
    }

    .form-input {
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
      background: linear-gradient(white, white) padding-box, 
                  linear-gradient(to right, #3b82f6, #1d4ed8) border-box;
      border: 2px solid transparent;
      border-radius: 0.75rem;
    }
    .form-input:focus {
      transform: translateY(-1px);
      box-shadow: 0 10px 25px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    }
    .btn-primary {
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
      background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }
    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    }

    @keyframes bounce {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-12px); }
    }
    .animate-bounce { animation: bounce 2s infinite; }

    @media (min-width: 1024px) {
      .character-desktop {
        position: absolute;
        right: 10%;
        top: 63%;
        transform: translateY(-50%);
        width: 350px;
        z-index: 10;
        filter: drop-shadow(0 10px 20px rgba(0,0,0,0.15));
      }
      .character-desktop img {
        width: 100%;
        height: auto;
      }
    }

    .toast-container {
      position: fixed;
      bottom: 1rem;
      right: 1rem;
      z-index: 9999;
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
      max-width: 420px;
    }
    .toast {
      animation: slideInRight 0.4s ease-out forwards;
      min-width: 300px;
      max-width: 100%;
    }
    @keyframes slideInRight {
      from { transform: translateX(100%); opacity: 0; }
      to { transform: translateX(0); opacity: 1; }
    }
    .toast-error {
      background: linear-gradient(#fef2f2, #fee2e2);
      border: 1px solid #fca5a5;
      color: #991b1b;
    }
    .toast-success {
      background: linear-gradient(#f0fdf4, #dcfce7);
      border: 1px solid #86efac;
      color: #166534;
    }

    .group:focus-within .svg-icon { color: #3b82f6; }
    .password-toggle { cursor: pointer; transition: color .2s; }
    .password-toggle:hover { color: #3b82f6; }
  </style>
</head>
<body class="bg-gray-50">
  <div class="min-h-screen flex flex-col justify-center py-8 sm:px-6 lg:px-8 relative overflow-hidden">
    
    <div class="hidden lg:block character-desktop">
      <img src="assets/icons/Login Here.png" alt="Login Here!" class="animate-bounce">
    </div>

    <div class="sm:mx-auto sm:w-full sm:max-w-md relative z-10">
      <div class="text-center mb-10">
        <a href="index.php" class="inline-block hover:opacity-80 transition-opacity duration-200">
            <div class="logo-container w-20 h-20 flex items-center justify-center mx-auto rounded-3xl shadow-lg mb-8 p-2 bg-gradient-to-br from-blue-100 to-indigo-100">
              <div class="bg-white rounded-2xl w-16 h-16 flex items-center justify-center shadow-md transform hover:scale-105 transition-transform duration-300">
                <img class="h-8 w-8" src="assets/icons/AquaSense.png" alt="CWD Logo">
              </div>
            </div>
        </a>
        <div class="space-y-2">
          <h1 class="text-2xl font-bold tracking-tight text-gray-900 sm:text-3xl">
            Calamba Water District
          </h1>
          <p class="text-base font-semibold text-blue-600 sm:text-lg">
            AquaSense Management System
          </p>
        </div>
      </div>

      <div class="card py-7 px-6 shadow-xl rounded-2xl">

        <?php if (!empty($_GET['reg_success'])): ?>
          <script>
            document.addEventListener('DOMContentLoaded', () => {
              const message = <?= json_encode(htmlspecialchars(urldecode($_GET['reg_success']))) ?>;
              showToast(message, 'success');
              document.getElementById('username').focus();
              
              // Remove ?reg_success from URL without refresh
              const cleanUrl = window.location.pathname + window.location.search.replace(/[?&]reg_success=[^&]*/g, '').replace(/^&/, '?');
              if (cleanUrl !== window.location.pathname) {
                history.replaceState({}, document.title, cleanUrl || window.location.pathname);
              } else {
                history.replaceState({}, document.title, window.location.pathname);
              }
            });
          </script>
        <?php endif; ?>

        <?php if (!empty($_GET['message'])): ?>
          <script>
            document.addEventListener('DOMContentLoaded', () => {
              const message = <?= json_encode(htmlspecialchars($_GET['message'])) ?>;
              showToast(message, 'success');
              
              // Clean URL
              const cleanUrl = window.location.pathname + window.location.search.replace(/[?&]message=[^&]*/g, '').replace(/^&/, '?');
              history.replaceState({}, document.title, cleanUrl || window.location.pathname);
            });
          </script>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
          <script>
            document.addEventListener('DOMContentLoaded', () => {
              showToast(<?= json_encode($error) ?>, 'error');
            });
          </script>
        <?php endif; ?>

        <form class="space-y-5" action="" method="POST">
          <div class="group">
            <label for="username" class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-blue-600 mr-2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
              </svg>
              Username
            </label>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-400 group-focus-within:text-blue-600 transition-colors">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                </svg>
              </div>
              <input 
                id="username"
                name="username" 
                type="text" 
                required 
                class="form-input block w-full px-10 py-3 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm peer"
                placeholder="Enter your username"
                autocomplete="username"
                value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
              >
            </div>
          </div>

          <div class="group">
            <label for="password" class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-blue-600 mr-2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
              </svg>
              Password
            </label>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 svg-icon text-gray-400">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                </svg>
              </div>
              <input 
                id="password"
                name="password" 
                type="password" 
                required 
                class="form-input block w-full px-11 py-3 pr-10 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm peer"
                placeholder="Enter your password"
                autocomplete="current-password"
              >
              <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                <span id="togglePassword" class="password-toggle text-gray-400" onclick="togglePasswordVisibility()">
                  <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                  </svg>
                </span>
              </div>
            </div>
          </div>

          <div class="flex items-center justify-between">
            <div class="flex items-center">
              <input id="remember" name="remember" type="checkbox" class="h-4 w-4 text-blue-600 border-2 border-gray-300 rounded focus:ring-blue-500">
              <label for="remember" class="ml-2 block text-sm text-gray-700">Remember me</label>
            </div>
            <div class="text-sm flex items-center">
              <a href="forgot_password.php" class="font-medium text-red-600 hover:text-red-500 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 mr-1">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" />
                </svg>
                Forgot password?
              </a>
            </div>
          </div>

          <div class="mt-5">
            <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($_ENV['TURNSTILE_SITE_KEY'] ?? '') ?>" data-theme="light"></div>
            <p class="mt-1 text-xs text-gray-500">This site is protected by Cloudflare Turnstile.</p>
          </div>

          <div>
            <button type="submit" class="btn-primary group relative w-full flex justify-center items-center py-3 px-6 border border-transparent text-sm font-bold rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
              <span class="absolute left-4"><i class="fas fa-sign-in-alt text-blue-100 group-hover:text-white transition-colors text-sm"></i></span>
              <span class="relative">Sign In</span>
            </button>
          </div>
        </form>

        <div class="mt-6 relative">
          <div class="absolute inset-0 flex items-center">
            <div class="w-full border-t border-gray-200"></div>
          </div>
          <div class="relative flex justify-center text-sm">
            <span class="px-3 bg-white text-gray-500 font-medium">New to AquaSense?</span>
          </div>
        </div>

        <div class="mt-5">
          <a href="register.php" class="w-full inline-flex justify-center items-center py-3 px-6 border-2 border-blue-200 rounded-xl shadow-sm bg-gradient-to-r from-blue-50 to-indigo-50 text-sm font-bold text-gray-700 hover:from-blue-100 hover:to-indigo-100 hover:border-blue-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all duration-200 group">
            <i class="fas fa-user-plus mr-2 text-blue-600 group-hover:text-blue-700 transition-colors"></i>
            Create Account
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

  <div id="toastContainer" class="toast-container"></div>

  <script>
    function showToast(message, type = 'error') {
      const container = document.getElementById('toastContainer');
      const toast = document.createElement('div');
      toast.className = `toast p-4 rounded-lg shadow-lg flex items-start gap-3 text-sm font-medium border ${type === 'error' ? 'toast-error' : 'toast-success'}`;

      toast.innerHTML = `
        <div class="flex-shrink-0 mt-0.5">
          ${type === 'error' 
            ? '<svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>'
            : '<svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
          }
        </div>
        <div class="flex-1">${message}</div>
        <button type="button" onclick="this.parentElement.remove()" class="ml-3 text-gray-500 hover:text-gray-700">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      `;
      container.appendChild(toast);
      setTimeout(() => toast.remove(), 7000);
    }

    function togglePasswordVisibility() {
      const input = document.getElementById('password');
      const icon = document.getElementById('togglePassword').querySelector('svg');
      const isPassword = input.type === 'password';
      input.type = isPassword ? 'text' : 'password';

      const showPath = `M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z`;
      const hidePath = `M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88`;

      icon.innerHTML = `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="${isPassword ? showPath : hidePath}" />`;
    }
  </script>
</body>
</html>
<?php ob_end_flush(); ?>