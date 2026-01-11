<header class="header fixed top-0 w-full z-50">
    <nav class="max-w-7xl mx-auto px-6 py-4">
        <div class="flex justify-between items-center">
            <div class="flex items-center space-x-3">
                <img src="assets/icons/AquaSense.png" alt="CWD AquaSense Logo" class="w-10 h-10 rounded-lg object-contain" aria-label="Logo">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">CWD AquaSense</h1>
                    <p class="text-xs text-gray-500">Calamba Water District</p>
                </div>
            </div>
            <div class="hidden md:flex items-center space-x-6">
                <a href="index.php" class="nav-link text-gray-700 font-medium">Home</a>
                <a href="#features" class="nav-link text-gray-700 font-medium">Features</a>
                <a href="#about" class="nav-link text-gray-700 font-medium">About</a>
                <a href="#services" class="nav-link text-gray-700 font-medium">Services</a>
                <a href="#water-rates" class="nav-link text-gray-700 font-medium">Water Rates</a>
                <a href="#visit" class="nav-link text-gray-700 font-medium">Visit Us</a>
            </div>
            <button id="mobile-menu-btn" class="md:hidden text-gray-700 focus:outline-none" aria-label="Toggle menu">
                <i class="fas fa-bars text-xl"></i>
            </button>
            <!-- CONDITIONAL AUTH BUTTONS -->
            <div class="flex items-center space-x-4">
                <?php if (!$is_logged_in): ?>
                    <!-- Not Logged In: Original Login/Register -->
                    <a href="login.php" class="btn-outline px-4 py-2 rounded-lg text-sm font-medium" aria-label="Login">Login</a>
                    <a href="register.php" class="btn-primary px-6 py-2 rounded-lg text-sm font-medium" aria-label="Create Account">Register</a>
                <?php else: ?>
                    <!-- Logged In: Welcome + Dashboard + Logout -->
                    <span class="text-sm text-gray-700 hidden sm:inline">Welcome, <?= htmlspecialchars($username) ?>!</span>
                    <a href="user/dashboard.php" class="btn-primary px-6 py-2 rounded-lg text-sm font-medium flex items-center" aria-label="Go to Dashboard">
                        <svg class="w-4 h-4 mr-2 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                        </svg>
                        Dashboard
                    </a>
                    <a href="../logout.php" class="btn-outline px-4 py-2 rounded-lg text-sm font-medium text-red-600 border-red-600 hover:bg-red-50" aria-label="Logout">Logout</a>
                <?php endif; ?>
            </div>
        </div>
        <div id="mobile-menu" class="hidden md:hidden absolute top-full left-0 w-full bg-white shadow-lg py-4 border-t border-gray-200">
            <a href="index.php" class="block px-6 py-3 text-gray-700 hover:bg-blue-50 nav-link">Home</a>
            <a href="#features" class="block px-6 py-3 text-gray-700 hover:bg-blue-50 nav-link">Features</a>
            <a href="#about" class="block px-6 py-3 text-gray-700 hover:bg-blue-50 nav-link">About</a>
            <a href="#services" class="block px-6 py-3 text-gray-700 hover:bg-blue-50 nav-link">Services</a>
            <a href="#water-rates" class="block px-6 py-3 text-gray-700 hover:bg-blue-50 nav-link">Water Rates</a>
            <a href="#visit" class="block px-6 py-3 text-gray-700 hover:bg-blue-50 nav-link">Visit Us</a>
            <!-- Mobile Auth Links (Conditional din, pero simple para sa mobile) -->
            <?php if (!$is_logged_in): ?>
                <a href="login.php" class="block px-6 py-3 text-gray-700 hover:bg-blue-50 border-t border-gray-200">Login</a>
                <a href="register.php" class="block px-6 py-3 text-blue-600 font-medium hover:bg-blue-50 border-t border-gray-200">Register</a>
            <?php else: ?>
                <div class="border-t border-gray-200 px-6 py-3">
                    <p class="text-sm text-gray-600 mb-2">Welcome, <?= htmlspecialchars($username) ?>!</p>
                    <a href="user/dashboard.php" class="block text-blue-600 font-medium hover:bg-blue-50 py-2">Dashboard</a>
                    <a href="user/logout.php" class="block text-red-600 font-medium hover:bg-red-50 py-2">Logout</a>
                </div>
            <?php endif; ?>
        </div>
    </nav>
</header>