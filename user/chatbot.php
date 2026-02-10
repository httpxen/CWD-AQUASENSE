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
                    <a href="../index.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition duration-200" title="Back to Homepage">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418" />
                        </svg>
                        Homepage
                    </a>
                    <a href="dashboard.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-gray-700 hover:bg-gray-100 hover:text-blue-600 transition-all duration-200">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="dashboard-icon mr-3">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                        </svg>
                        Dashboard
                    </a>
                    <a href="chatbot.php" class="flex items-center px-4 py-3 text-sm font-medium rounded-xl text-blue-600 bg-blue-50 border border-blue-200 transition-all duration-200 hover:bg-blue-100">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="chatbot-icon mr-3">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
                        </svg>
                        Chatbot
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

            <!-- Main Content: Welcome + Chat -->
            <main class="p-6 space-y-6">
                <!-- Welcome Screen -->
                <div id="welcomeScreen" class="welcome-container">
                    <div class="character-section">
                        <div class="character-img">
                            <video autoplay loop muted playsinline>
                                <source src="../assets/icons/kuya-daloy.mp4" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                        </div>
                    </div>
                    <button id="startSession" class="start-btn" data-tooltip="Click to start chatting with Kuya Daloy">
                        Start Session
                    </button>
                    <div class="developed-by">
                        <img src="../assets/icons/AquaSense.png" alt="CWD Logo" class="inline-block">
                        Developed by: CALAMBA WATER DISTRICT
                    </div>
                </div>

                <!-- Chat Screen (Hidden Initially) -->
                <div id="chatScreen" class="chat-container" style="display: none;">
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
                        <button class="close-btn" title="Close Session" onclick="window.location.href='chatbot.php'">
                            <i class="fa-solid fa-arrow-left text-xl"></i>
                        </button>
                    </header>

                    <div id="chatMessages" class="chat-messages">
                        <div id="initialMessage" class="chat-bubble bot">
                            Hello! I’m Kuya Daloy, your friendly water guide. How can I help you with your water services today? Kumusta ka?
                        </div>
                    </div>

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

        // Profile dropdown
        const profileDropdown = document.getElementById('profileDropdown');
        const profileDropdownMenu = document.getElementById('profileDropdownMenu');

        profileDropdown?.addEventListener('click', function(e) {
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

        function hideProfileDropdown() {
            profileDropdownMenu.classList.add('hidden');
        }

        document.addEventListener('click', hideProfileDropdown);

        // ───────────────────────────────────────────────
        // Welcome → Chat transition (fixed version)
        // ───────────────────────────────────────────────
        const startBtn = document.getElementById('startSession');
        const welcomeScreen = document.getElementById('welcomeScreen');
        const chatScreen = document.getElementById('chatScreen');

        if (startBtn) {
            startBtn.addEventListener('click', function() {
                if (welcomeScreen) welcomeScreen.style.display = 'none';
                if (chatScreen)  chatScreen.style.display  = 'flex';

                setTimeout(() => {
                    const initialMsg = document.getElementById('initialMessage');
                    if (initialMsg) initialMsg.classList.add('animate');
                }, 300);
            });
        }

        // Chatbot logic
        document.addEventListener("DOMContentLoaded", () => {
            const chatMessages = document.getElementById("chatMessages");
            const chatInput = document.getElementById("chatInput");
            const chatSend = document.getElementById("chatSend");

            if (!chatMessages || !chatInput || !chatSend) {
                console.error("AquaSense: missing chat element(s)");
                return;
            }

            let messageHistory = [];
            let retryCount = 0;
            const maxRetries = 3;

            function createMessageBubble(text, sender = "user") {
                const div = document.createElement("div");
                div.className = `chat-bubble ${sender} animate`;
                div.innerHTML = text;
                return div;
            }

            function createStructuredBubble(structured, sender = "bot") {
                const container = document.createElement("div");
                container.className = `chat-bubble ${sender} animate`;

                const messageDiv = document.createElement("div");
                messageDiv.innerHTML = structured.message || structured.content || '';
                messageDiv.className = 'mb-2';
                container.appendChild(messageDiv);

                if (structured.type === 'buttons' && Array.isArray(structured.buttons)) {
                    const btnContainer = document.createElement("div");
                    btnContainer.className = 'flex flex-wrap gap-2 mt-2';
                    structured.buttons.forEach(text => {
                        const btn = document.createElement("button");
                        btn.className = 'px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-sm hover:bg-blue-200 transition-colors';
                        btn.textContent = text;
                        btn.onclick = () => {
                            sendUserMessage(text);
                            btnContainer.remove();
                        };
                        btnContainer.appendChild(btn);
                    });
                    container.appendChild(btnContainer);
                }
                // ... (the rest of your structured bubble logic remains the same)

                return container;
            }

            function appendMessage(bubble) {
                chatMessages.appendChild(bubble);
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }

            function sendUserMessage(text) {
                appendMessage(createMessageBubble(text, "user"));
                messageHistory.push({ role: "user", content: text });
                chatInput.value = "";
                chatInput.focus();
            }

            function sendBotMessage(rawContent) {
                try {
                    const data = JSON.parse(rawContent);
                    appendMessage(createStructuredBubble(data, "bot"));
                    messageHistory.push({ role: "assistant", content: rawContent });
                } catch {
                    appendMessage(createMessageBubble(rawContent, "bot"));
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

            async function attemptApiCall() {
                const res = await fetch("chat.php", {
                    method: "POST",
                    credentials: 'same-origin',
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ messages: messageHistory })
                });

                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                return await res.json();
            }

            async function handleSendMessage() {
                const text = chatInput.value.trim();
                if (!text) return;

                chatSend.disabled = true;
                sendUserMessage(text);

                const typing = addTypingIndicator();

                try {
                    let data = await attemptApiCall();

                    while (data.error?.includes('429') && retryCount < maxRetries) {
                        retryCount++;
                        const delay = Math.pow(2, retryCount) * 2500;
                        await new Promise(r => setTimeout(r, delay));
                        data = await attemptApiCall();
                    }

                    typing.remove();

                    if (data.response) {
                        sendBotMessage(data.response);
                    } else if (data.error) {
                        sendBotMessage(`Error: ${data.error}`);
                    }
                } catch (err) {
                    typing.remove();
                    sendBotMessage("Cannot connect to server right now.");
                    console.error(err);
                } finally {
                    chatSend.disabled = false;
                    retryCount = 0;
                }
            }

            chatSend.addEventListener("click", handleSendMessage);

            chatInput.addEventListener("keydown", e => {
                if (e.key === "Enter" && !e.shiftKey) {
                    e.preventDefault();
                    handleSendMessage();
                }
            });

            // Show initial message
            const initial = document.getElementById('initialMessage');
            if (initial) appendMessage(initial);

            window.__AquaSense = { messageHistory };
        });
    </script>
</body>
</html>

<?php
if (isset($stmt)) mysqli_stmt_close($stmt);
mysqli_close($conn);
?>