<?php
include 'db/db.php';
session_start();

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

$token = isset($_GET['token']) ? $_GET['token'] : '';
$error = '';
$success = '';
$showForm = true;

if (empty($token)) {
    $error = "Invalid or missing reset token.";
    $showForm = false;
} else {
    // Check if token is valid and not expired
    $check_sql = "SELECT id, username, reset_token, reset_token_expiry FROM users WHERE reset_token='$token' LIMIT 1";
    $check_result = mysqli_query($conn, $check_sql);
    
    if ($check_result && mysqli_num_rows($check_result) == 1) {
        $user = mysqli_fetch_assoc($check_result);
        
        if (strtotime($user['reset_token_expiry']) < time()) {
            $error = "This password reset link has expired. Please request a new one.";
            $showForm = false;
        }
    } else {
        $error = "Invalid password reset link.";
        $showForm = false;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && $showForm) {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validation
    $errors = [];
    
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }
    
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    }
    
    if (empty($errors)) {
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Update password and clear reset token
        $update_sql = "UPDATE users SET password='$hashed_password', reset_token=NULL, reset_token_expiry=NULL WHERE reset_token='$token'";
        
        if (mysqli_query($conn, $update_sql)) {
            $success = "Your password has been successfully reset! You can now log in with your new password.";
            $showForm = false;
        } else {
            $error = "Failed to reset password. Please try again.";
        }
    } else {
        $error = implode("<br>", $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password | CWD AquaSense</title>
  <link rel="icon" type="image/png" href="assets/icons/AquaSense2.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    }
    .form-input {
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
      background: linear-gradient(white, white) padding-box, 
                  linear-gradient(to right, #3b82f6, #1d4ed8) border-box;
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
    .card {
      background: linear-gradient(145deg, #ffffff, #f8fafc);
      border: 1px solid rgba(0, 0, 0, 0.05);
    }
    .logo-container {
      background: linear-gradient(135deg, #eff6ff, #dbeafe);
      border: 1px solid rgba(59, 130, 246, 0.1);
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
  </style>
</head>
<body class="bg-gray-50">
  <div class="min-h-screen flex flex-col justify-center py-8 sm:px-6 lg:px-8 relative overflow-hidden">
    
    <!-- Subtle Background Pattern -->
    <div class="absolute inset-0 bg-gradient-to-br from-blue-50/20 via-white/50 to-indigo-50/20"></div>
    
    <div class="sm:mx-auto sm:w-full sm:max-w-md relative z-10 fade-in-up">
      
      <!-- Header -->
      <div class="text-center mb-8">
        <div class="logo-container w-16 h-16 flex items-center justify-center mx-auto rounded-2xl shadow-sm mb-6 p-1">
          <div class="bg-white rounded-xl w-14 h-14 flex items-center justify-center shadow-sm">
            <img class="h-7 w-7" src="assets/icons/CWD.png" alt="CWD Logo">
          </div>
        </div>
        <h1 class="text-3xl font-bold leading-9 tracking-tight text-gray-900 mb-2">
          Reset Password
        </h1>
        <p class="text-sm text-gray-600 max-w-sm mx-auto">
          <span class="block font-medium">Calamba Water District</span>
          <span class="text-blue-600 font-semibold">AquaSense Management System</span>
        </p>
      </div>

      <!-- Form Card -->
      <div class="card py-7 px-6 shadow-xl rounded-2xl fade-in-up">
        
        <!-- Success Message -->
        <?php if (!empty($success)) : ?>
          <div class="text-center">
            <div class="mb-6 p-3.5 bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200/50 rounded-xl backdrop-blur-sm">
              <div class="flex items-start justify-center">
                <div class="flex-shrink-0 pt-0.5">
                  <i class="fas fa-check-circle text-green-400 text-base"></i>
                </div>
                <div class="ml-3 flex-1 text-center">
                  <p class="text-sm text-green-800 leading-5 font-medium"><?= htmlspecialchars($success); ?></p>
                </div>
              </div>
            </div>
            <div class="space-y-3">
              <a href="login.php" class="w-full inline-flex justify-center items-center py-3 px-6 border-2 border-green-200 rounded-xl shadow-sm bg-gradient-to-r from-green-50 to-emerald-50 text-sm font-bold text-green-700 hover:from-green-100 hover:to-emerald-100 hover:border-green-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-all duration-200 group">
                <i class="fas fa-sign-in-alt mr-2 text-green-600 group-hover:text-green-700 transition-colors"></i>
                Go to Sign In
              </a>
            </div>
          </div>
        <?php else : ?>
          
          <!-- Error Message -->
          <?php if (!empty($error)) : ?>
            <div class="mb-6 p-3.5 bg-gradient-to-r from-red-50 to-pink-50 border border-red-200/50 rounded-xl backdrop-blur-sm">
              <div class="flex items-start">
                <div class="flex-shrink-0 pt-0.5">
                  <i class="fas fa-exclamation-circle text-red-400 text-base"></i>
                </div>
                <div class="ml-3 flex-1">
                  <p class="text-sm text-red-800 leading-5 font-medium"><?= nl2br(htmlspecialchars($error)); ?></p>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($showForm) : ?>
            <form class="space-y-5" action="" method="POST">
              
              <!-- Password Field -->
              <div class="group">
                <label for="password" class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                  <i class="fas fa-key text-blue-600 text-sm mr-2"></i>
                  New Password
                </label>
                <div class="relative">
                  <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 svg-icon text-gray-400 group-focus-within:text-blue-600 transition-colors">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" />
                    </svg>
                  </div>
                  <input 
                    id="password"
                    name="password" 
                    type="password" 
                    required 
                    class="form-input appearance-none block w-full px-11 py-3 pr-10 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm peer"
                    placeholder="Create a new password (8+ characters)"
                    autocomplete="new-password"
                  >
                  <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                    <span id="togglePassword" class="password-toggle text-gray-400 cursor-pointer" onclick="togglePassword()" aria-label="Show password">
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                      </svg>
                    </span>
                  </div>
                </div>
              </div>

              <!-- Confirm Password Field -->
              <div class="group">
                <label for="confirm_password" class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                  <i class="fas fa-lock text-blue-600 text-sm mr-2"></i>
                  Confirm New Password
                </label>
                <div class="relative">
                  <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 svg-icon text-gray-400 group-focus-within:text-blue-600 transition-colors">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                    </svg>
                  </div>
                  <input 
                    id="confirm_password"
                    name="confirm_password" 
                    type="password" 
                    required 
                    class="form-input appearance-none block w-full px-11 py-3 pr-10 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm peer"
                    placeholder="Confirm your new password"
                    autocomplete="new-password"
                  >
                  <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                    <span id="toggleConfirmPassword" class="password-toggle text-gray-400 cursor-pointer" onclick="toggleConfirmPassword()" aria-label="Show password">
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                      </svg>
                    </span>
                  </div>
                </div>
              </div>

              <!-- Submit Button -->
              <div>
                <button
                  type="submit"
                  class="btn-primary group relative w-full flex justify-center items-center py-3 px-6 border border-transparent text-sm font-bold rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                >
                  <span class="absolute left-4">
                    <i class="fas fa-lock text-blue-100 group-hover:text-white transition-colors text-sm"></i>
                  </span>
                  <span class="relative">Reset Password</span>
                </button>
              </div>
            </form>

            <!-- Back to Login -->
            <div class="mt-6">
              <a 
                href="login.php"
                class="w-full inline-flex justify-center items-center py-3 px-6 border-2 border-gray-200 rounded-xl shadow-sm bg-white text-sm font-bold text-gray-700 hover:bg-gray-50 hover:border-gray-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all duration-200 group"
              >
                <i class="fas fa-arrow-left mr-2 text-gray-400 group-hover:text-gray-600 transition-colors"></i>
                <span>Back to Sign In</span>
              </a>
            </div>
          <?php else : ?>
            <div class="text-center">
              <div class="space-y-3">
                <a href="forgot_password.php" class="w-full inline-flex justify-center items-center py-3 px-6 border-2 border-blue-200 rounded-xl shadow-sm bg-gradient-to-r from-blue-50 to-indigo-50 text-sm font-bold text-gray-700 hover:from-blue-100 hover:to-indigo-100 hover:border-blue-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all duration-200 group">
                  <i class="fas fa-redo mr-2 text-blue-600 group-hover:text-blue-700 transition-colors"></i>
                  Request New Reset Link
                </a>
              </div>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- Footer -->
      <div class="mt-10 text-center">
        <p class="text-xs text-gray-500 leading-5">
          <i class="fas fa-shield-alt text-blue-600 mr-1"></i>
          <span class="font-medium">Protected by</span> Calamba Water District Security
        </p>
        <p class="text-xs text-gray-400 mt-1">© 2025 CWD AquaSense</p>
      </div>
    </div>
  </div>

  <script>
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

    // Confirm Password Toggle
    function toggleConfirmPassword() {
      const confirmPasswordInput = document.getElementById('confirm_password');
      const toggleIcon = document.getElementById('toggleConfirmPassword');
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

      if (confirmPasswordInput.type === 'password') {
        confirmPasswordInput.type = 'text';
        toggleIcon.innerHTML = showSvg;
        toggleIcon.setAttribute('aria-label', 'Hide password');
      } else {
        confirmPasswordInput.type = 'password';
        toggleIcon.innerHTML = hideSvg;
        toggleIcon.setAttribute('aria-label', 'Show password');
      }
    }

    // Real-time password match validation
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirm_password');

    function validatePasswordMatch() {
      if (confirmPasswordInput && passwordInput) {
        const password = passwordInput.value;
        const confirmPassword = confirmPasswordInput.value;
        
        if (confirmPassword) {
          if (password === confirmPassword) {
            confirmPasswordInput.classList.remove('border-red-500');
            confirmPasswordInput.classList.add('border-green-500');
          } else {
            confirmPasswordInput.classList.remove('border-green-500');
            confirmPasswordInput.classList.add('border-red-500');
          }
        }
      }
    }

    if (confirmPasswordInput && passwordInput) {
      confirmPasswordInput.addEventListener('input', validatePasswordMatch);
      passwordInput.addEventListener('input', validatePasswordMatch);
    }

    // Enhanced loading overlay
    document.querySelector('form')?.addEventListener('submit', function(e) {
      const button = e.target.querySelector('button[type="submit"]');
      const originalText = button.innerHTML;
      
      // Disable button and show loading
      button.disabled = true;
      button.innerHTML = `
        <i class="fas fa-spinner fa-spin mr-2"></i>
        Resetting Password...
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

    // Auto-hide success message after 5 seconds
    <?php if (!empty($success)) : ?>
      setTimeout(() => {
        const successMsg = document.querySelector('.from-green-50');
        if (successMsg) {
          successMsg.style.transition = 'opacity 0.5s ease-out';
          successMsg.style.opacity = '0';
          setTimeout(() => successMsg.remove(), 500);
        }
      }, 5000);
    <?php endif; ?>
  </script>
</body>
</html>