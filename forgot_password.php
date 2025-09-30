<?php
include 'db/db.php';
include 'config/email.php';
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

$error = '';
$success = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = mysqli_real_escape_string($conn, trim($_POST['email']));
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        // Check if email exists in database
        $check_sql = "SELECT id, username, email FROM users WHERE email='$email' LIMIT 1";
        $check_result = mysqli_query($conn, $check_sql);
        
        if ($check_result && mysqli_num_rows($check_result) == 1) {
            $user = mysqli_fetch_assoc($check_result);
            
            // Generate reset token
            $reset_token = bin2hex(random_bytes(32));
            $token_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Store token in database (you'll need to add these columns to your users table)
            $update_sql = "UPDATE users SET reset_token='$reset_token', reset_token_expiry='$token_expiry' WHERE id=" . $user['id'];
            
            if (mysqli_query($conn, $update_sql)) {
                // Send reset email
                $emailService = new EmailService();
                if ($emailService->sendPasswordReset($email, $user['username'], $reset_token)) {
                    $success = "Password reset instructions have been sent to your email address. Please check your inbox (and spam folder).";
                } else {
                    $error = "Failed to send reset email. Please try again later.";
                }
            } else {
                $error = "An error occurred while processing your request. Please try again.";
            }
        } else {
            // Email doesn't exist - show generic message for security
            $success = "If an account with that email exists, password reset instructions have been sent. Please check your inbox (and spam folder).";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password | CWD AquaSense</title>
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
          Forgot Password?
        </h1>
        <p class="text-sm text-gray-600 max-w-sm mx-auto">
          <span class="block font-medium">Calamba Water District</span>
          <span class="text-blue-600 font-semibold">AquaSense Management System</span>
        </p>
        <p class="text-sm text-gray-500 mt-2 max-w-sm mx-auto">
          Enter your email address and we'll send you instructions to reset your password.
        </p>
      </div>

      <!-- Form Card -->
      <div class="card py-7 px-6 shadow-xl rounded-2xl fade-in-up">
        
        <!-- Success Message -->
        <?php if (!empty($success)) : ?>
          <div class="mb-6 p-3.5 bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200/50 rounded-xl backdrop-blur-sm">
            <div class="flex items-start">
              <div class="flex-shrink-0 pt-0.5">
                <i class="fas fa-check-circle text-green-400 text-base"></i>
              </div>
              <div class="ml-3 flex-1">
                <p class="text-sm text-green-800 leading-5 font-medium"><?= htmlspecialchars($success); ?></p>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <!-- Error Message -->
        <?php if (!empty($error)) : ?>
          <div class="mb-6 p-3.5 bg-gradient-to-r from-red-50 to-pink-50 border border-red-200/50 rounded-xl backdrop-blur-sm">
            <div class="flex items-start">
              <div class="flex-shrink-0 pt-0.5">
                <i class="fas fa-exclamation-circle text-red-400 text-base"></i>
              </div>
              <div class="ml-3 flex-1">
                <p class="text-sm text-red-800 leading-5 font-medium"><?= htmlspecialchars($error); ?></p>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if (empty($success)) : ?>
          <form class="space-y-5" action="" method="POST">
            
            <!-- Email Field -->
            <div class="group">
              <label for="email" class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-blue-600 mr-2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                </svg>
                Email Address
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 text-gray-400 group-focus-within:text-blue-600 transition-colors">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                  </svg>
                </div>
                <input 
                  id="email"
                  name="email" 
                  type="email" 
                  required 
                  class="form-input appearance-none block w-full px-10 py-3 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm peer"
                  placeholder="Enter your registered email"
                  autocomplete="email"
                  value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>"
                >
              </div>
            </div>

            <!-- Submit Button -->
            <div>
              <button
                type="submit"
                class="btn-primary group relative w-full flex justify-center items-center py-3 px-6 border border-transparent text-sm font-bold rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
              >
                <span class="absolute left-4">
                  <i class="fas fa-paper-plane text-blue-100 group-hover:text-white transition-colors text-sm"></i>
                </span>
                <span class="relative">Send Reset Instructions</span>
              </button>
            </div>
          </form>
        <?php endif; ?>

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
    // Enhanced loading overlay
    document.querySelector('form')?.addEventListener('submit', function(e) {
      const button = e.target.querySelector('button[type="submit"]');
      const originalText = button.innerHTML;
      
      // Disable button and show loading
      button.disabled = true;
      button.innerHTML = `
        <i class="fas fa-spinner fa-spin mr-2"></i>
        Sending Instructions...
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
        const successMsg = document.querySelector('.bg-gradient-to-r.from-green-50');
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