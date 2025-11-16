<?php
include 'db/db.php';
session_start();

// Load .env file
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }
}

// REQUIRE LIBPHONENUMBER
require_once 'vendor/autoload.php';
use libphonenumber\PhoneNumberUtil;
use libphonenumber\PhoneNumberFormat;

/** =========================
 *  CONFIG
 *  ========================= */
define('TERMS_VERSION', '2025-09-23');
date_default_timezone_set('Asia/Manila');

$timeout_duration = 1800;
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
  session_unset();
  session_destroy();
  header("Location: login.php?message=Session expired, please log in again.");
  exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

$error = "";
require_once 'config/email.php';
require_once 'config/notification.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $username       = trim($_POST['username'] ?? '');
  $first_name     = trim($_POST['first_name'] ?? '');
  $middle_name    = trim($_POST['middle_name'] ?? '');
  $last_name      = trim($_POST['last_name'] ?? '');
  $email          = trim($_POST['email'] ?? '');
  $contact_number = trim($_POST['contact_number'] ?? '');
  $password       = $_POST['password'] ?? '';
  $confirm        = $_POST['confirm_password'] ?? '';
  $terms          = isset($_POST['terms']);
  $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';

  $errors = [];

  // REQUIRED FIELDS
  if (empty($username)) $errors[] = "Username is required.";
  if (empty($first_name)) $errors[] = "First name is required.";
  if (empty($last_name)) $errors[] = "Last name is required.";
  if (empty($email)) $errors[] = "Email address is required.";
  if (empty($password)) $errors[] = "Password is required.";
  if (empty($confirm)) $errors[] = "Please confirm your password.";

  // OTHER VALIDATIONS
  if (!$terms) $errors[] = "Please agree to the Terms and Conditions to continue.";
  if ($password !== $confirm) $errors[] = "Passwords do not match.";
  if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters long.";
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Please provide a valid email address.";

  // INTERNATIONAL PHONE VALIDATION
  if (empty($contact_number)) {
    $errors[] = "Contact number is required.";
  } else {
    $phoneUtil = PhoneNumberUtil::getInstance();
    try {
      $phoneNumberProto = $phoneUtil->parse($contact_number, "ZZ");
      if (!$phoneUtil->isValidNumber($phoneNumberProto)) {
        $errors[] = "Please enter a valid phone number with correct country code.";
      } else {
        $contact_number = $phoneUtil->format($phoneNumberProto, PhoneNumberFormat::E164);
      }
    } catch (\libphonenumber\NumberParseException $e) {
      $errors[] = "Invalid phone number format.";
    }
  }

  // reCAPTCHA VALIDATION
  $recaptcha_secret = $_ENV['RECAPTCHA_SECRET_KEY'] ?? '';
  if (empty($recaptcha_response)) {
    $errors[] = "Please complete the reCAPTCHA verification.";
  } elseif (!empty($recaptcha_secret)) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $verify_url = 'https://www.google.com/recaptcha/api/siteverify';
    $response = file_get_contents("{$verify_url}?secret={$recaptcha_secret}&response={$recaptcha_response}&remoteip={$ip}");
    $response_data = json_decode($response);

    if (!$response_data || !isset($response_data->success) || !$response_data->success) {
      $errors[] = "reCAPTCHA verification failed. Please try again.";
    }
  } else {
    $errors[] = "reCAPTCHA secret key is missing.";
  }

  if (empty($errors)) {
    $stmt = $conn->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) $errors[] = "Username already exists.";
    $stmt->close();

    $stmt = $conn->prepare("SELECT 1 FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) $errors[] = "Email already registered.";
    $stmt->close();

    $stmt = $conn->prepare("SELECT 1 FROM users WHERE contact_number = ? LIMIT 1");
    $stmt->bind_param("s", $contact_number);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) $errors[] = "Contact number already registered.";
    $stmt->close();
  }

  if (empty($errors)) {
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $terms_version = TERMS_VERSION;

    $stmt = $conn->prepare("
      INSERT INTO users
      (username, first_name, middle_name, last_name, email, contact_number, password, created_at,
       accepted_terms_version, accepted_terms_at, accepted_terms_ip, accepted_terms_ua)
      VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW(), ?, ?)
    ");
    $stmt->bind_param(
      "ssssssssss",
      $username, $first_name, $middle_name, $last_name, $email, $contact_number,
      $hashed_password, $terms_version, $ip, $ua
    );

    if ($stmt->execute()) {
      $stmt->close();
      $emailService = new EmailService();
      $emailService->sendWelcomeEmail($email, $username);

      $userDetails = [
        'username' => $username, 'first_name' => $first_name, 'middle_name' => $middle_name,
        'last_name' => $last_name, 'email' => $email, 'contact_number' => $contact_number, 'ip' => $ip
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Register | CWD AquaSense</title>
  <link rel="icon" type="image/png" href="assets/icons/AquaSense2.png">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/18.1.4/css/intlTelInput.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/18.1.4/js/intlTelInput.min.js"></script>
  <!-- reCAPTCHA v2 Script -->
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>

  <style>
    /* BACKGROUND + OVERLAY */
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

    /* CARD GLASS EFFECT */
    .card {
      background: rgba(255, 255, 255, 0.94) !important;
      backdrop-filter: blur(14px);
      -webkit-backdrop-filter: blur(14px);
      border: 1px solid rgba(255, 255, 255, 0.4);
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
      border-radius: 1.5rem;
    }

    /* FORM INPUTS */
    .form-input {
      transition: all .2s cubic-bezier(.4,0,.2,1);
      background: linear-gradient(#fff,#fff) padding-box,
                  linear-gradient(to right,#3b82f6,#1d4ed8) border-box;
      border: 2px solid transparent;
      border-radius: 0.75rem;
    }
    .form-input:focus {
      transform: translateY(-1px);
      box-shadow: 0 10px 25px -3px rgba(0,0,0,.1), 0 4px 6px -2px rgba(0,0,0,.05);
    }

    /* BUTTON */
    .btn-primary {
      transition: all .2s cubic-bezier(.4,0,.2,1);
      background: linear-gradient(135deg,#3b82f6 0%,#1d4ed8 100%);
      box-shadow: 0 4px 6px -1px rgba(0,0,0,.1), 0 2px 4px -1px rgba(0,0,0,.06);
    }
    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 20px 25px -5px rgba(0,0,0,.1), 0 10px 10px -5px rgba(0,0,0,.04);
    }

    /* CHARACTER */
    @media (min-width: 1024px) {
      .character-desktop {
        position: absolute;
        left: 10%;
        top: 50%;
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

    /* NAME GRID */
    .name-section {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 1rem;
    }
    @media (max-width: 640px) {
      .name-section { grid-template-columns: 1fr; gap: .75rem; }
    }

    /* TOAST */
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

    /* BOUNCE */
    @keyframes bounce {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-12px); }
    }
    .animate-bounce { animation: bounce 2s infinite; }

    /* INTL TEL INPUT */
    .iti { width: 100% !important; }
    .iti__flag { background-image: url("https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/18.1.4/img/flags.png"); }
    @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
      .iti__flag { background-image: url("https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/18.1.4/img/flags@2x.png"); }
    }

    .group:focus-within .svg-icon { color: #3b82f6; }
    .password-toggle { cursor: pointer; transition: color .2s; }
    .password-toggle:hover { color: #3b82f6; }
  </style>
</head>
<body class="bg-gray-50">
  <div class="min-h-screen flex flex-col justify-center py-8 sm:px-6 lg:px-8 relative overflow-hidden">

    <!-- DESKTOP CHARACTER -->
    <div class="hidden lg:block character-desktop">
      <img src="assets/icons/Register Here.png" alt="Register Here!" class="animate-bounce">
    </div>

    <div class="sm:mx-auto sm:w-full sm:max-w-lg relative z-20">

      <!-- LOGO & TITLE -->
      <div class="text-center mb-8">
        <div class="logo-container w-16 h-16 flex items-center justify-center mx-auto rounded-2xl shadow-sm mb-6 p-1">
          <div class="bg-white rounded-xl w-14 h-14 flex items-center justify-center shadow-sm">
            <img class="h-7 w-7" src="assets/icons/AquaSense.png" alt="CWD Logo">
          </div>
        </div>
        <h1 class="text-3xl font-bold leading-9 tracking-tight text-gray-900 mb-2">Create Account</h1>
        <p class="text-sm text-gray-600 max-w-sm mx-auto">
          <span class="block font-medium">Calamba Water District</span>
          <span class="text-blue-600 font-semibold">AquaSense Management System</span>
        </p>
      </div>

      <!-- FORM CARD -->
      <div class="card py-7 px-6 shadow-xl rounded-2xl">
        <?php if (!empty($_GET['message'])): ?>
          <div class="mb-6 p-4 rounded-lg bg-green-50 border border-green-200 text-green-800 flex items-start gap-3">
            <svg class="w-5 h-5 text-green-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div><?= htmlspecialchars($_GET['message']) ?></div>
          </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
          <script>
            document.addEventListener('DOMContentLoaded', function() {
              showToast(<?= json_encode(explode("<br>", $error)) ?>, 'error');
              if (typeof grecaptcha !== 'undefined') grecaptcha.reset();
            });
          </script>
        <?php endif; ?>

        <form class="space-y-5" action="" method="POST" novalidate id="registerForm">

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
              <input id="username" name="username" type="text" required class="form-input block w-full px-10 py-3 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm peer" placeholder="Enter your username" autocomplete="username" value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>">
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
                <input id="first_name" name="first_name" type="text" required class="form-input block w-full px-10 py-3 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm peer" placeholder="Enter first name" autocomplete="given-name" value="<?= isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : '' ?>">
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
                <input id="middle_name" name="middle_name" type="text" class="form-input block w-full px-10 py-3 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm peer" placeholder="Enter middle name (optional)" autocomplete="additional-name" value="<?= isset($_POST['middle_name']) ? htmlspecialchars($_POST['middle_name']) : '' ?>">
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
                <input id="last_name" name="last_name" type="text" required class="form-input block w-full px-10 py-3 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm peer" placeholder="Enter last name" autocomplete="family-name" value="<?= isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : '' ?>">
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
              <input id="email" name="email" type="email" required class="form-input block w-full px-10 py-3 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm peer" placeholder="Enter your email" autocomplete="email" value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
            </div>
          </div>

          <!-- Contact Number -->
          <div class="group">
            <label for="contact_number" class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 text-blue-600 mr-2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-1.484-.382-3.03-1.285-4.203-2.458-.615-.615-1.43-1.431-2.458-2.458l1.293-.97c.363-.27.527-.733.417-1.173L9.457 5.628c-.125-.5-.575-.852-1.091-.852H7.5a2.25 2.25 0 0 0-2.25 2.25Z" />
              </svg>
              Contact Number
            </label>
            <div class="relative">
              <input id="contact_number" name="contact_number" type="tel" required class="form-input block w-full px-3 py-3 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm" placeholder="Enter phone number" autocomplete="tel" value="<?= isset($_POST['contact_number']) ? htmlspecialchars($_POST['contact_number']) : '' ?>">
            </div>
            <p class="mt-1 text-xs text-gray-500">Select country code from the dropdown.</p>
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
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 svg-icon text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" />
                </svg>
              </div>
              <input id="password" name="password" type="password" required class="form-input block w-full px-11 py-3 pr-10 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm peer" placeholder="Create a password (8+ characters)" autocomplete="new-password">
              <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                <span class="password-toggle text-gray-400" onclick="togglePasswordVisibility('password', 'passwordEyeIcon')">
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
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 0 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
              </svg>
              Confirm Password
            </label>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 svg-icon text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 10.5V6.75a4.5 4.5 0 0 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                </svg>
              </div>
              <input id="confirm_password" name="confirm_password" type="password" required class="form-input block w-full px-11 py-3 pr-10 border-2 border-transparent placeholder-gray-400 text-gray-900 rounded-xl focus:outline-none focus:ring-0 sm:text-sm peer" placeholder="Confirm your password" autocomplete="new-password">
              <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                <span class="password-toggle text-gray-400" onclick="togglePasswordVisibility('confirm_password', 'confirmPasswordEyeIcon')">
                  <svg id="confirmPasswordEyeIcon" xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                  </svg>
                </span>
              </div>
            </div>
          </div>

          <!-- Terms -->
          <div class="space-y-2">
            <div class="flex items-start pt-1">
              <div class="flex-shrink-0 mt-0.5">
                <input id="terms" name="terms" type="checkbox" required class="h-4 w-4 text-blue-600 border-2 border-gray-300 rounded focus:ring-blue-500 focus:ring-2 mt-1">
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
          </div>

          <!-- reCAPTCHA v2 -->
          <div class="mt-5">
            <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars($_ENV['RECAPTCHA_SITE_KEY'] ?? '') ?>"></div>
            <p class="mt-1 text-xs text-gray-500">This site is protected by reCAPTCHA and the Google 
              <a href="https://policies.google.com/privacy" target="_blank" class="text-blue-600 underline">Privacy Policy</a> and 
              <a href="https://policies.google.com/terms" target="_blank" class="text-blue-600 underline">Terms of Service</a> apply.
            </p>
          </div>

          <!-- Submit -->
          <div class="mt-6">
            <button type="submit" class="btn-primary group relative w-full flex justify-center items-center py-3 px-6 border border-transparent text-sm font-bold rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
              <span class="absolute left-4"><i class="fas fa-user-plus text-blue-100 group-hover:text-white transition-colors text-sm"></i></span>
              <span class="relative">Create Account</span>
            </button>
          </div>
        </form>

        <div class="mt-6 relative">
          <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
          <div class="relative flex justify-center text-sm"><span class="px-3 bg-white text-gray-500 font-medium">Already have an account?</span></div>
        </div>

        <div class="mt-5">
          <a href="login.php" class="w-full inline-flex justify-center items-center py-3 px-6 border-2 border-blue-200 rounded-xl shadow-sm bg-gradient-to-r from-blue-50 to-indigo-50 text-sm font-bold text-gray-700 hover:from-blue-100 hover:to-indigo-100 hover:border-blue-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all duration-200 group">
            <i class="fas fa-sign-in-alt mr-2 text-blue-600 group-hover:text-blue-700 transition-colors"></i>
            Sign In to AquaSense
          </a>
        </div>
      </div>

      <div class="mt-10 text-center">
        <p class="text-xs text-gray-500 leading-5"><i class="fas fa-shield-alt text-blue-600 mr-1"></i><span class="font-medium">Protected by</span> Calamba Water District Security</p>
        <p class="text-xs text-gray-400 mt-1">© 2025 CWD AquaSense</p>
      </div>
    </div>
  </div>

  <!-- TOAST CONTAINER -->
  <div id="toastContainer" class="toast-container"></div>

  <!-- TERMS MODAL -->
  <div id="termsModal" class="fixed inset-0 hidden bg-black bg-opacity-40 z-50 items-center justify-center flex">
    <div class="bg-white rounded-2xl shadow-2xl w-11/12 max-w-2xl max-h-[80vh] overflow-hidden">
      <div class="px-6 py-4 border-b flex items-center justify-between">
        <h3 class="text-lg font-bold">Terms and Conditions (v<?= htmlspecialchars(TERMS_VERSION) ?>)</h3>
        <button class="text-gray-500" onclick="closeTermsModal()">X</button>
      </div>
      <div class="p-6 overflow-y-auto space-y-4 text-sm">
        <p class="text-gray-700">This is a quick summary. Read the full terms on <a href="terms.php" target="_blank" class="text-blue-600 underline">Terms and Conditions</a>.</p>
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

  <!-- PRIVACY MODAL -->
  <div id="privacyModal" class="fixed inset-0 hidden bg-black bg-opacity-40 z-50 items-center justify-center flex">
    <div class="bg-white rounded-2xl shadow-2xl w-11/12 max-w-2xl max-h-[80vh] overflow-hidden">
      <div class="px-6 py-4 border-b flex items-center justify-between">
        <h3 class="text-lg font-bold">Privacy Notice (Summary)</h3>
        <button class="text-gray-500" onclick="closePrivacyModal()">X</button>
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

  <script src="https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/18.1.4/js/utils.js"></script>
  <script>
    const input = document.querySelector("#contact_number");
    const iti = window.intlTelInput(input, {
      initialCountry: "ph",
      preferredCountries: ["ph", "us", "ca", "gb", "au"],
      separateDialCode: true,
      nationalMode: false,
      utilsScript: "https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/18.1.4/js/utils.js",
      formatOnDisplay: true,
    });

    function showToast(messages, type = 'error') {
      const container = document.getElementById('toastContainer');
      const toast = document.createElement('div');
      toast.className = `toast p-4 rounded-lg shadow-lg flex items-start gap-3 text-sm font-medium border ${type === 'error' ? 'toast-error' : 'toast-success'}`;

      let content = '';
      if (Array.isArray(messages)) {
        content = `
          <div class="flex-1">
            <p class="font-semibold mb-1">${messages.length > 1 ? 'Please fix the following:' : 'Error:'}</p>
            <ul class="list-disc list-inside space-y-1">
              ${messages.map(msg => `<li>${msg}</li>`).join('')}
            </ul>
          </div>
        `;
      } else {
        content = `<div class="flex-1">${messages}</div>`;
      }

      toast.innerHTML = `
        <div class="flex-shrink-0 mt-0.5">
          ${type === 'error' 
            ? '<svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>'
            : '<svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'
          }
        </div>
        ${content}
        <button type="button" onclick="this.parentElement.remove()" class="ml-3 text-gray-500 hover:text-gray-700">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      `;
      container.appendChild(toast);
      setTimeout(() => toast.remove(), 7000);
    }

    document.getElementById('registerForm').addEventListener('submit', function(e) {
      document.getElementById('toastContainer').innerHTML = '';
      const errors = [];

      if (!document.getElementById('username').value.trim()) errors.push("Username is required.");
      if (!document.getElementById('first_name').value.trim()) errors.push("First name is required.");
      if (!document.getElementById('last_name').value.trim()) errors.push("Last name is required.");
      if (!document.getElementById('email').value.trim()) errors.push("Email address is required.");
      if (!document.getElementById('password').value) errors.push("Password is required.");
      if (!document.getElementById('confirm_password').value) errors.push("Please confirm your password.");
      if (!document.getElementById('terms').checked) errors.push("Please agree to the Terms and Conditions.");

      const password = document.getElementById('password').value;
      const confirm = document.getElementById('confirm_password').value;
      if (password && confirm && password !== confirm) errors.push("Passwords do not match.");
      if (password && password.length < 8) errors.push("Password must be at least 8 characters long.");

      const email = document.getElementById('email').value.trim();
      if (email && !/^\S+@\S+\.\S+$/.test(email)) errors.push("Please provide a valid email address.");

      if (!iti.isValidNumber()) {
        errors.push("Please enter a valid phone number with correct country code.");
      } else {
        input.value = iti.getNumber();
      }

      // reCAPTCHA check
      const recaptchaResponse = document.querySelector('.g-recaptcha-response')?.value;
      if (!recaptchaResponse) {
        errors.push("Please complete the reCAPTCHA verification.");
      }

      if (errors.length > 0) {
        e.preventDefault();
        showToast(errors, 'error');
        if (typeof grecaptcha !== 'undefined') grecaptcha.reset();
      }
    });

    function togglePasswordVisibility(inputId, iconId) {
      const input = document.getElementById(inputId);
      const icon = document.getElementById(iconId);
      const isPassword = input.type === 'password';
      input.type = isPassword ? 'text' : 'password';

      const showPath = `M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z`;
      const hidePath = `M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88`;

      icon.innerHTML = `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="${isPassword ? showPath : hidePath}" />`;
    }

    function openTermsModal() { document.getElementById('termsModal').classList.remove('hidden'); document.getElementById('termsModal').classList.add('flex'); }
    function closeTermsModal() { document.getElementById('termsModal').classList.add('hidden'); document.getElementById('termsModal').classList.remove('flex'); }
    function openPrivacyModal() { document.getElementById('privacyModal').classList.remove('hidden'); document.getElementById('privacyModal').classList.add('flex'); }
    function closePrivacyModal() { document.getElementById('privacyModal').classList.add('hidden'); document.getElementById('privacyModal').classList.remove('flex'); }
  </script>
</body>
</html>