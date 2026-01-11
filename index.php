<?php
// Main index.php - Simplified with includes
session_name('CustomerSession');  // Same as login.php
session_start();

$is_logged_in = isset($_SESSION['user_id']);  // Check kung logged in
$username = $is_logged_in ? $_SESSION['username'] ?? 'User' : '';  // Para sa welcome message
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CWD AquaSense</title>
    <meta name="description" content="CWD AquaSense: AI-powered water service management for Calamba Water District. Streamline complaints and analytics for 70K+ households.">
    <meta property="og:title" content="CWD AquaSense - Empowering Water Services with AI">
    <meta property="og:description" content="Streamline complaints, feedback, and analytics for over 70,000 households in Calamba. Faster resolutions, real-time tracking, and intelligent insights.">
    <meta property="og:image" content="assets/icons/AquaSense2.png">
    <link rel="stylesheet" href="index.css">  <!-- O kaya assets/css/index.css kung in-move mo -->
    <link rel="icon" type="image/png" href="assets/icons/AquaSense2.png">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <?php include 'includes/header.php'; ?>
    
    <?php include 'includes/hero.php'; ?>
    
    <?php include 'includes/features.php'; ?>
    
    <?php include 'includes/stats.php'; ?>
    
    <?php include 'includes/testimonials.php'; ?>
    
    <?php include 'includes/about.php'; ?>

    <?php include 'includes/services.php'; ?>

    <?php include 'includes/water-rates.php'; ?>
    
    <?php include 'includes/visit.php'; ?>
    
    <?php include 'includes/footer.php'; ?>
    
    <?php include 'includes/modals.php'; ?>
    
    <script src="includes/scripts.js"></script>  <!-- Lahat ng JS dito -->
</body>
</html>