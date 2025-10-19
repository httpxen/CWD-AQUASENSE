<?php
include '../db/db.php';
session_name('CustomerSession');
session_start();

// Session timeout duration (30 minutes)
$timeout_duration = 1800;

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php?message=Please log in to access the dashboard.");
    exit();
}

// Check for timeout
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header("Location: ../login.php?message=Session expired, please log in again.");
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

// -------- Utility: safe output --------
function e($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }

// -------- Fetch logged-in user (for header/avatar) --------
$user_id = $_SESSION['user_id'];
$user_query = "SELECT first_name, last_name, username, email, profile_picture FROM users WHERE id = ?";
$stmt = mysqli_prepare($conn, $user_query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$user_result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($user_result);
mysqli_stmt_close($stmt);
$stmt = null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chatbot | CWD AquaSense</title>
    <link rel="icon" type="image/png" href="../assets/icons/AquaSense2.png">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/chatbot.css">
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
                    <a href="chatbot.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-blue-600 bg-blue-50 border border-blue-200 transition-all duration-200 hover:bg-blue-100">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="chatbot-icon mr-3">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
                        </svg>
                        Chatbot
                    </a>
                </nav>

                <!-- User Info & Logout -->
                <div class="p-4 border-t border-gray-100">
                    <div class="flex items-center space-x-3 mb-4">
                        <div class="relative avatar-glow">
                            <img src="<?php echo e($user['profile_picture'] ?: 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode(($user['first_name']??'').' '.($user['last_name']??''))); ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover"/>
                            <div class="absolute -bottom-0.5 -right-0.5 w-4 h-4 bg-green-500 border-2 border-white rounded-full animate-gentle-pulse"></div>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900"><?php echo e(($user['first_name']??'').' '.($user['last_name']??'')); ?></p>
                            <p class="text-xs text-gray-500">@<?php echo e($user['username']??''); ?></p>
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

        <!-- Main Content -->
        <div class="flex-1">
            <header class="header-2025 sticky top-0 z-20">
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4">
                            
                        </div>
                        <div class="flex items-center space-x-4">
                            <button class="relative p-2 text-gray-600 hover:text-gray-900 transition-all duration-200 rounded-full hover:bg-gray-100 group" id="notificationBtn">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.16a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0M3.124 7.5A8.969 8.969 0 0 1 5.292 3m13.416 0a8.969 8.969 0 0 1 2.168 4.5" />
                                </svg>
                                <div class="notification-badge">3</div>
                            </button>

                            <div class="flex items-center space-x-3 p-2 profile-card hover:bg-gray-50 rounded-xl transition-all duration-200 group cursor-pointer relative" id="profileDropdown">
                                <div class="avatar-glow">
                                    <img src="<?php echo e($user['profile_picture'] ?: 'https://ui-avatars.com/api/?background=3b82f6&color=fff&name=' . urlencode(($user['first_name']??'').' '.($user['last_name']??''))); ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover"/>
                                    <div class="absolute -bottom-0.5 -right-0.5 w-4 h-4 bg-green-500 border-2 border-white rounded-full animate-gentle-pulse"></div>
                                </div>
                                <div class="hidden md:block">
                                    <p class="text-sm font-semibold text-gray-900 truncate max-w-32"><?php echo e($user['first_name']??''); ?></p>
                                    <p class="text-xs text-gray-500 truncate max-w-32">@<?php echo e($user['username']??''); ?></p>
                                </div>
                                <i class="fas fa-chevron-down text-gray-400 text-sm ml-1 transition-transform duration-200 group-hover:text-gray-600"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Main Content: Welcome Screen -->
            <main class="p-6 space-y-6">
                <!-- Reworked Welcome Screen -->
                <div id="welcomeScreen" class="welcome-container">
                    <div class="character-section">
                        <div class="character-img">
                            <video autoplay loop muted playsinline>
                                <source src="../assets/icons/kuya-daloy.mp4" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                        </div>
                    </div>
                    <button id="startSession" class="start-btn">
                        Start Session
                    </button>
                    <div class="developed-by">
                        <img src="../assets/icons/AquaSense.png" alt="CWD Logo" class="inline-block">
                        Developed by: CWD
                    </div>
                </div>

                <!-- Chat Screen (Hidden Initially) -->
                <div id="chatScreen" class="chat-container">
                    <!-- Header -->
                    <header class="chat-header relative z-10">
                        <div class="flex items-center gap-3">
                            <div class="header-icon">
                                <img src="../assets/icons/kuya-daloy.gif" alt="Kuya Daloy" 
                                    class="w-9 h-9 object-contain rounded-full" />
                            </div>

                            <div>
                                <h3 class="text-white font-semibold leading-tight">Kuya Daloy</h3>
                                <p class="text-white/80 text-xs">Your water management helper</p>
                            </div>
                        </div>
                        <button class="close-btn" title="Back to Dashboard" onclick="window.location.href='dashboard.php'">
                            <i class="fa-solid fa-arrow-left text-xl"></i>
                        </button>
                    </header>

                    <!-- Messages -->
                    <div id="chatMessages" class="chat-messages">
                        <div id="initialMessage" class="chat-bubble bot">
                            Hello! I’m Kuya Daloy, your friendly water guide. How can I help you with your water services today? Kumusta ka?
                        </div>
                    </div>

                    <!-- Composer -->
                    <footer class="chat-composer">
                        <div class="flex items-center gap-2 w-full">
                            <input id="chatInput" type="text" placeholder="Type your message..." class="chat-input">
                            <button id="chatSend" type="button" title="Send" aria-label="Send message">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="20" height="20" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                                </svg>
                            </button>
                        </div>
                    </footer>
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

    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuToggle').addEventListener('click', function() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('-translate-x-full');
            sidebar.classList.toggle('translate-x-0');
        });

        // Sidebar collapse on mobile
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
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5 mr-3">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                </svg>
                Sign Out
            </a>
            `;
            profileDropdownMenu.classList.remove('hidden');
            const rect = profileDropdown.getBoundingClientRect();
            profileDropdownMenu.style.right = '1.5rem';
            profileDropdownMenu.style.top = `${rect.bottom + 8}px`;
        }

        function hideProfileDropdown() { profileDropdownMenu.classList.add('hidden'); }
        document.addEventListener('click', function() { hideProfileDropdown(); });

        // Notification (placeholder)
        document.getElementById('notificationBtn').addEventListener('click', function(e) {
            e.stopPropagation();
            this.style.transform = 'scale(0.95)';
            setTimeout(() => { this.style.transform = 'scale(1)'; }, 150);
            alert('Notifications feature coming soon! 🔔');
        });

        // Add loading animation to example buttons only
        document.querySelectorAll('button').forEach(button => {
            if (['mobileMenuToggle','notificationBtn'].includes(button.id)) return;
            button.addEventListener('click', function() {
                if (!this.classList.contains('loading')) {
                    const originalText = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Loading...';
                    this.classList.add('loading');
                    setTimeout(() => { this.innerHTML = originalText; this.classList.remove('loading'); }, 1500);
                }
            });
        });

        // Welcome Screen to Chat Transition
        document.getElementById('startSession').addEventListener('click', function() {
            document.getElementById('welcomeScreen').style.display = 'none';
            document.getElementById('chatScreen').style.display = 'flex';
            // Trigger initial message animation
            setTimeout(() => {
                const initialMsg = document.getElementById('initialMessage');
                initialMsg.classList.add('animate');
            }, 300);
        });

        // Chatbot Script with Quick Replies Support
        document.addEventListener("DOMContentLoaded", () => {
            const chatMessages = document.getElementById("chatMessages");
            const chatInput = document.getElementById("chatInput");
            const chatSend = document.getElementById("chatSend");

            // Quick sanity check
            if (!chatMessages || !chatInput || !chatSend) {
                console.error("AquaSense: missing element(s)");
                return;
            }

            let messageHistory = [];

            function createMessageBubble(text, sender = "user") {
                const div = document.createElement("div");
                div.className = `chat-bubble ${sender} animate`;
                div.textContent = text;
                return div;
            }

            function createStructuredBubble(structured, sender = "bot") {
                const container = document.createElement("div");
                container.className = `chat-bubble ${sender} animate`;

                const messageDiv = document.createElement("div");
                messageDiv.textContent = structured.message || structured.content || '';
                messageDiv.className = 'mb-2';

                container.appendChild(messageDiv);

                // Add quick reply buttons if present
                if (structured.type === 'buttons' && structured.buttons && Array.isArray(structured.buttons)) {
                    const buttonsContainer = document.createElement("div");
                    buttonsContainer.className = 'flex flex-wrap gap-2 mt-2';
                    structured.buttons.forEach(btnText => {
                        const btn = document.createElement("button");
                        btn.className = 'px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-sm hover:bg-blue-200 transition-colors';
                        btn.textContent = btnText;
                        btn.onclick = () => {
                            sendUserMessage(btnText);
                            buttonsContainer.remove(); // Remove buttons after selection
                        };
                        buttonsContainer.appendChild(btn);
                    });
                    container.appendChild(buttonsContainer);
                } else if (structured.type === 'confirm' && structured.buttons && Array.isArray(structured.buttons)) {
                    // Similar to buttons
                    const buttonsContainer = document.createElement("div");
                    buttonsContainer.className = 'flex flex-wrap gap-2 mt-2 justify-center';
                    structured.buttons.forEach(btnText => {
                        const btn = document.createElement("button");
                        btn.className = `px-4 py-2 rounded-full text-sm font-medium transition-colors ${
                            btnText === 'Yes' ? 'bg-green-100 text-green-700 hover:bg-green-200' : 'bg-red-100 text-red-700 hover:bg-red-200'
                        }`;
                        btn.textContent = btnText;
                        btn.onclick = () => {
                            const confirmText = btnText.toLowerCase() === 'yes' ? 'yes' : 'no';
                            sendUserMessage(confirmText);
                            buttonsContainer.remove();
                        };
                        buttonsContainer.appendChild(btn);
                    });
                    container.appendChild(buttonsContainer);
                } else if (structured.type === 'input') {
                    // Just message, focus input
                    chatInput.focus();
                } else if (structured.type === 'success' || structured.type === 'error') {
                    const statusDiv = document.createElement("div");
                    statusDiv.className = `mt-2 p-2 rounded-lg ${
                        structured.type === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'
                    }`;
                    statusDiv.textContent = structured.message;
                    container.appendChild(statusDiv);
                }

                return container;
            }

            function appendMessage(bubble) {
                chatMessages.appendChild(bubble);
                bubble.getBoundingClientRect();
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }

            function sendUserMessage(text) {
                const userBubble = createMessageBubble(text, "user");
                appendMessage(userBubble);
                messageHistory.push({ role: "user", content: text });
                chatInput.value = "";
                chatInput.focus();
            }

            function sendBotMessage(rawContent) {
                try {
                    // Try to parse as structured response
                    const structured = JSON.parse(rawContent);
                    const botBubble = createStructuredBubble(structured, "bot");
                    appendMessage(botBubble);
                    messageHistory.push({ role: "assistant", content: rawContent });
                } catch (e) {
                    // Normal text response
                    const botBubble = createMessageBubble(rawContent, "bot");
                    appendMessage(botBubble);
                    messageHistory.push({ role: "assistant", content: rawContent });
                }
            }

            function addTypingIndicator() {
                const el = document.createElement("div");
                el.className = "typing-indicator";
                el.innerHTML = `<div class="typing-dots"><span></span><span></span><span></span></div> Kuya Daloy is typing...`;
                chatMessages.appendChild(el);
                chatMessages.scrollTop = chatMessages.scrollHeight;
                return el;
            }

            let retryCount = 0;
            const maxRetries = 3;

            async function attemptApiCall() {
                const response = await fetch("chat.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ messages: messageHistory })
                });

                const data = await response.json();
                return data;
            }

            async function handleSendMessage() {
                const text = chatInput.value.trim();
                if (!text) return;

                chatSend.disabled = true;
                sendUserMessage(text);

                const typingEl = addTypingIndicator();

                try {
                    let data = await attemptApiCall();
                    
                    while ((data.error && (data.error.includes('rate limit') || data.error.includes('429'))) && retryCount < maxRetries) {
                        retryCount++;
                        const waitTime = Math.pow(2, retryCount) * 2500;  // 5s, 10s, 20s
                        sendBotMessage(`⏳ Rate limit hit. Retrying in ${waitTime/1000}s... (Try ${retryCount}/${maxRetries})`);
                        await new Promise(resolve => setTimeout(resolve, waitTime));
                        data = await attemptApiCall();
                    }

                    typingEl.remove();

                    if (data.answer) {
                        sendBotMessage(data.answer);
                    } else if (data.error) {
                        sendBotMessage(`⚠️ ${data.error}. Try again later!`);
                        console.error(data);
                    } else {
                        sendBotMessage("⚠️ No response from AI. Check PHP logs.");
                        console.error(data);
                    }
                } catch (err) {
                    console.error("AquaSense: OpenAI fetch error", err);
                    typingEl.remove();
                    sendBotMessage("⚠️ Error contacting server.");
                } finally {
                    chatSend.disabled = false;
                    retryCount = 0;  // Reset for next message
                }
            }

            chatSend.addEventListener("click", handleSendMessage);

            chatInput.addEventListener("keydown", (e) => {
                if (e.key === "Enter" && !e.shiftKey) {
                    e.preventDefault();
                    handleSendMessage();
                }
            });

            // Add initial message
            const initialMsg = document.getElementById('initialMessage');
            appendMessage(initialMsg);

            // Expose history for dev
            window.__AquaSense = { messageHistory };
        });
    </script>
</body>
</html>

<?php
if (isset($stmt)) mysqli_stmt_close($stmt);
mysqli_close($conn);
?>