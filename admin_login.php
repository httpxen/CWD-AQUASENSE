<?php
include 'db/db.php';
session_name('AdminSession'); // Separate session for admins (shared with SuperAdmin for simplicity)
session_start();

require_once 'config/env.php';

date_default_timezone_set('Asia/Manila');

// Session timeout duration (30 minutes)
$timeout_duration = 1800;

// Check for timeout
if (isset($_SESSION['STAFF_LAST_ACTIVITY']) && (time() - $_SESSION['STAFF_LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: superadmin_login.php?message=Session expired, please log in again.");
    exit();
}
$_SESSION['STAFF_LAST_ACTIVITY'] = time();

$error = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email)) {
        $error = "Email is required.";
    } elseif (empty($password)) {
        $error = "Password is required.";
    } else {
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
        $sql = "SELECT staff_id, name, email, role, password FROM staff WHERE email = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($result && mysqli_num_rows($result) == 1) {
            $row = mysqli_fetch_assoc($result);

            if (password_verify($password, $row['password'])) {
                $_SESSION['staff_email'] = $row['email'];
                $_SESSION['staff_name'] = $row['name'];
                $_SESSION['staff_id'] = $row['staff_id'];
                $_SESSION['staff_role'] = $row['role'];
                $_SESSION['STAFF_LAST_ACTIVITY'] = time();

                // Role-based redirect (SuperAdmin to dedicated dashboard)
                if ($row['role'] == 'SuperAdmin') {
                    header("Location: superadmin/dashboard.php");
                } elseif ($row['role'] == 'Admin') {
                    header("Location: admin/dashboard.php");
                } elseif ($row['role'] == 'Employee') {
                    header("Location: employees/dashboard.php");
                }
                exit();
            } else {
                $error = "Invalid password.";
            }
        } else {
            $error = "No account found with that email.";
        }
        mysqli_stmt_close($stmt);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login | CWD AquaSense</title>
  <link rel="icon" type="image/png" href="assets/icons/AquaSense2.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  
  <!-- CLOUDFLARE TURNSTILE -->
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

    .logo-container {
      background: linear-gradient(135deg, #eff6ff, #dbeafe);
      border: 1px solid rgba(59, 130, 246, 0.1);
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

    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
    .fade-in-up {
      animation: fadeInUp 0.6s ease-out;
    }

    .svg-icon {
      transition: color 0.2s ease-in-out;
    }
    .group:focus-within .svg-icon {
      color: #3b82f6;
    }
    .password-toggle {
      cursor: pointer;
      transition: color 0.2s ease-in-out;
    }
    .password-toggle:hover {
      color: #3b82f6;
    }
    .group:focus-within .password-toggle {
      color: #3b82f6;
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
  </style>
</head>
<body class="bg-gray-50">
  <div class="min-h-screen flex flex-col justify-center py-8 sm:px-6 lg:px-8 relative overflow-hidden">
    
    <div class="sm:mx-auto sm:w-full sm:max-w-md relative z-10 fade-in-up">
      

      <!-- Header -->
      <div class="text-center mb-10 relative z-10">
        <div class="logo-container w-20 h-20 flex items-center justify-center mx-auto rounded-3xl shadow-lg mb-8 p-2 bg-gradient-to-br from-blue-100 to-indigo-100">
          <div class="bg-white rounded-2xl w-16 h-16 flex items-center justify-center shadow-md transform hover:scale-105 transition-transform duration-300">
            <img class="h-8 w-8" src="assets/icons/AquaSense.png" alt="CWD Logo">
          </div>
        </div>
        <div class="space-y-2 fade-in-up">
          <h1 class="text-2xl font-bold tracking-tight text-gray-900 sm:text-3xl">
            Calamba Water District
          </h1>
          <p class="text-base font-semibold text-blue-600 sm:text-lg">
                Admin Portal - AquaSense
          </p>
        </div>
      </div>

      <!-- Form Card -->
      <div class="card py-7 px-6 shadow-xl rounded-2xl fade-in-up">
        

        <form class="space-y-5" action="" method="POST">
          
          <!-- Email Field -->
          <div class="group">
            <label for="email" class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-blue-600 mr-2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
              </svg>
              Email
            </label>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-400 group-focus-within:text-blue-600 transition-colors">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                </svg>
              </div>
              <input 
                id="email"
                name="email" 
                type="email" 
                required 
                class="form-input appearance-none block w-full px-10 py-3 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm peer"
                placeholder="Enter your email"
                autocomplete="email"
                value="<?= htmlspecialchars($_POST['email'] ?? ''); ?>"
              >
            </div>
          </div>

          <!-- Password Field -->
          <div class="group">
            <label for="password" class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-blue-600 mr-2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
              </svg>
              Password
            </label>
            <div class="relative">
              <!-- Left Icon (Shield) -->
              <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 svg-icon text-gray-400 group-focus-within:text-blue-600 transition-colors">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                </svg>
              </div>
              <!-- Input -->
              <input 
                id="password"
                name="password" 
                type="password" 
                required 
                class="form-input appearance-none block w-full px-11 py-3 pr-10 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm peer"
                placeholder="Enter your password"
                autocomplete="current-password"
              >
              <!-- Toggle Icon (Eye) -->
              <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                <span id="togglePassword" class="password-toggle text-gray-400 cursor-pointer" onclick="togglePassword()" aria-label="Show password">
                  <!-- Hide Eye (Default - with slash) -->
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                  </svg>
                </span>
              </div>
            </div>
          </div>

          <!-- Forgot Password -->
          <div class="flex items-center justify-end pt-1">
            <div class="text-sm">
              <a href="admin_forgot_password.php" class="font-semibold text-blue-600 hover:text-blue-700 transition-colors duration-150 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 mr-1 text-blue-600">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" />
                </svg>
                Forgot password?
              </a>
            </div>
          </div>

          <!-- CLOUDFLARE TURNSTILE -->
          <div class="mt-5">
            <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($_ENV['TURNSTILE_SITE_KEY'] ?? '') ?>" data-theme="light"></div>
            <p class="mt-1 text-xs text-gray-500">This site is protected by Cloudflare Turnstile.</p>
          </div>

          <!-- Submit Button -->
          <div>
            <button
              type="submit"
              class="btn-primary group relative w-full flex justify-center items-center py-3 px-6 border border-transparent text-sm font-bold rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
            >
              <span class="absolute left-4">
                <i class="fas fa-arrow-right text-blue-100 group-hover:text-white transition-colors text-sm"></i>
              </span>
              <span class="relative">Admin Sign In</span>
            </button>
          </div>
        </form>

      </div>

      <!-- Footer (Moved below the form card) -->
      <div class="mt-10 text-center">
        <p class="text-xs text-gray-500 leading-5">
          <i class="fas fa-shield-alt text-blue-600 mr-1"></i>
          <span class="font-medium">Protected by</span> Calamba Water District Security
        </p>
        <p class="text-xs text-gray-400 mt-1">© 2025 CWD AquaSense</p>
      </div>
    </div>
  </div>

  <!-- TOAST CONTAINER -->
  <div id="toastContainer" class="toast-container"></div>

  <!-- LOGIN ERROR -->
  <?php if (!empty($error)): ?>
    <script>
      document.addEventListener('DOMContentLoaded', () => {
        showToast(<?= json_encode($error) ?>, 'error');
      });
    </script>
  <?php endif; ?>

  <!-- SESSION EXPIRED / OTHER SUCCESS MESSAGE -->
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

    // Password Toggle Functionality
    function togglePassword() {
      const passwordInput = document.getElementById('password');
      const toggleIcon = document.getElementById('togglePassword');
      const showSvg = `
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
        </svg>
      `;
      const hideSvg = `
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
        </svg>
      `;

      if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleIcon.innerHTML = showSvg;
        toggleIcon.setAttribute('aria-label', 'Hide password');
      } else {
        passwordInput.type = 'password';
        toggleIcon.innerHTML = hideSvg;
        toggleIcon.setAttribute('aria-label', 'Show password');
      }
    }

    // Enhanced loading overlay
    document.querySelector('form').addEventListener('submit', function(e) {
      const button = e.target.querySelector('button[type="submit"]');
      const originalText = button.innerHTML;
      
      // Disable button and show loading
      button.disabled = true;
      button.innerHTML = `
        <i class="fas fa-spinner fa-spin mr-2"></i>
        Signing in...
      `;
      
      // Re-enable after 3 seconds or on page change
      setTimeout(() => {
        button.disabled = false;
        button.innerHTML = originalText;
      }, 3000);
    });

    // Smooth focus animations
    document.querySelectorAll('.form-input').forEach((input, index) => {
      input.addEventListener('focus', function() {
        this.parentElement.style.transform = 'translateY(-1px)';
        this.parentElement.classList.add('border-blue-500');
      });
      
      input.addEventListener('blur', function() {
        this.parentElement.style.transform = 'translateY(0)';
        this.parentElement.classList.remove('border-blue-500');
      });
      
      // Staggered entrance
      setTimeout(() => {
        input.parentElement.style.opacity = '1';
        input.parentElement.style.transform = 'translateY(0)';
      }, index * 100);
    });

    // Form validation enhancement
    document.querySelector('form').addEventListener('input', function() {
      const email = document.getElementById('email');
      const password = document.getElementById('password');
      const submitBtn = this.querySelector('button[type="submit"]');
      
      if (email.value && password.value) {
        submitBtn.classList.add('bg-blue-700', 'hover:bg-blue-800');
      } else {
        submitBtn.classList.remove('bg-blue-700', 'hover:bg-blue-800');
      }
    });
  </script>
</body>
</html>