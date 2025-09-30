<!-- =========================
     AQUASENSE CHATBOT — UI ONLY
     ========================= -->
<!-- Floating Launcher Image -->
<img id="aquasenseChatLauncher" src="../assets/icons/AquaSense.png" alt="Chat Launcher"
    class="aquasense-launcher-img" />

<!-- Slide-in Chat Drawer -->
<section id="aquasenseChatDrawer" class="aquasense-chat-drawer" aria-label="AquaSense Assistant">
    <!-- Header -->
    <header class="aquasense-chat-header px-4 py-3 flex items-center justify-between relative">
        <div class="flex items-center gap-3 relative z-10">
            <div class="w-11 h-11 bg-white/20 flex items-center justify-center">
                <img src="../assets/icons/AquaSense.png" alt="Water Icon" class="w-9 h-9 object-contain text-white" />
            </div>
            <div>
                <h3 class="text-white font-semibold leading-tight">AquaSense Assistant</h3>
                <p class="text-white/80 text-xs">Your water management helper</p>
            </div>
        </div>
        <div class="flex items-center gap-2 relative z-10">
            <button id="aquasenseChatClose" class="text-white/90 hover:text-white transition transform hover:scale-110"
                title="Close">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    </header>

    <!-- Messages -->
    <div id="aquasenseChatMessages" class="aquasense-scroll bg-gray-50 px-4 py-4 flex-1 overflow-y-auto space-y-3">
        <div id="initialMessage" class="aquasense-bubble bot">
            Hello! I’m your AquaSense Assistant. How can I help you with your water services today?
        </div>
    </div>

    <!-- Composer -->
    <footer class="border-t border-gray-200 bg-white px-3 py-3 shrink-0">
        <div class="flex items-center gap-2">
            <input id="aquasenseChatInput" type="text" placeholder="Type your message..."
                class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-transparent">
            <!-- SEND BUTTON: SVG + pure CSS, no Tailwind dependency -->
            <button id="aquasenseChatSend" type="button" title="Send" aria-label="Send message">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                    stroke="currentColor" width="20" height="20" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                </svg>
            </button>
        </div>
    </footer>
</section>

<style>
    /* =========================
       AQUASENSE CHATBOT — CSS (extracted)
       ========================= */
    .aquasense-chat-drawer {
        position: fixed;
        right: 1.5rem;
        bottom: 6.5rem;
        width: 24rem;
        max-width: calc(100vw - 2rem);
        height: 70vh;
        max-height: 720px;
        background: #ffffff;
        border: 1px solid rgba(0, 0, 0, 0.08);
        border-radius: 1rem;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        overflow: hidden;
        transform: translateX(120%) scale(0.95);
        transition: all .35s cubic-bezier(.4, 0, .2, 1);
        z-index: 70;
        /* ↑ to sit above launcher */
        opacity: 0;
        display: flex;
        flex-direction: column;
    }

    .aquasense-chat-drawer.open {
        transform: translateX(0) scale(1);
        opacity: 1;
    }

    .aquasense-chat-header {
        background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
        color: #fff;
        position: relative;
        overflow: hidden;
        transform: translateY(-20px);
        opacity: 0;
        transition: all 0.3s ease-out 0.1s;
    }

    .aquasense-chat-drawer.open .aquasense-chat-header {
        transform: translateY(0);
        opacity: 1;
    }

    .aquasense-chat-header::before {
        content: '';
        position: absolute;
        inset: 0;
        background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 20"><defs><pattern id="wave" x="0" y="0" width="100" height="20" patternUnits="userSpaceOnUse"><path d="M0 10 Q25 0 50 10 T100 10 V20 H0 Z" fill="%23ffffff" opacity="0.1"/></pattern></defs><rect width="100" height="20" fill="url(%23wave)"/></svg>');
        opacity: 0.1;
        animation: wave 20s linear infinite;
    }

    @keyframes wave {
        0% {
            transform: translateX(0);
        }

        100% {
            transform: translateX(-100px);
        }
    }

    .aquasense-scroll {
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
    }

    .aquasense-scroll::-webkit-scrollbar {
        width: 8px;
    }

    .aquasense-scroll::-webkit-scrollbar-thumb {
        background: linear-gradient(135deg, #06b6d4, #0891b2);
        border-radius: 9999px;
    }

    .aquasense-launcher-img {
        position: fixed;
        right: 1.5rem;
        bottom: 1.5rem;
        width: 4rem;
        height: 4rem;
        cursor: pointer;
        z-index: 60;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        animation: float 3s ease-in-out infinite;
        filter: drop-shadow(0 4px 8px rgba(6, 182, 212, 0.3));
    }

    .aquasense-launcher-img:hover {
        transform: scale(1.1) rotate(5deg);
        filter: drop-shadow(0 6px 12px rgba(6, 182, 212, 0.4));
    }

    @keyframes float {

        0%,
        100% {
            transform: translateY(0px);
        }

        50% {
            transform: translateY(-10px);
        }
    }

    .aquasense-bubble {
        max-width: 80%;
        padding: .625rem .875rem;
        font-size: .925rem;
        line-height: 1.35rem;
        border-radius: .875rem;
        position: relative;
        opacity: 0;
        transform: translateY(20px);
        animation: messageSlideIn 0.4s ease-out forwards;
    }

    .aquasense-bubble.animate {
        animation: messageSlideIn 0.4s ease-out forwards;
    }

    @keyframes messageSlideIn {
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .aquasense-bubble.bot {
        background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
        color: #0c4a6e;
        border: 1px solid #bae6fd;
        border-top-left-radius: .4rem;
        box-shadow: 0 2px 8px rgba(6, 182, 212, 0.15);
    }

    .aquasense-bubble.user {
        background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
        color: #fff;
        margin-left: auto;
        border-top-right-radius: .4rem;
        box-shadow: 0 2px 8px rgba(6, 182, 212, 0.25);
    }

    .typing-indicator {
        display: flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.625rem 0.875rem;
        background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
        border: 1px solid #bae6fd;
        border-radius: 0.875rem;
        border-top-left-radius: .4rem;
        max-width: 80%;
    }

    .typing-dots {
        display: flex;
        gap: 0.25rem;
    }

    .typing-dots span {
        width: 0.5rem;
        height: 0.5rem;
        background: #06b6d4;
        border-radius: 50%;
        animation: typing 1.4s infinite ease-in-out;
    }

    .typing-dots span:nth-child(2) {
        animation-delay: 0.2s;
    }

    .typing-dots span:nth-child(3) {
        animation-delay: 0.4s;
    }

    @keyframes typing {

        0%,
        60%,
        100% {
            transform: translateY(0);
        }

        30% {
            transform: translateY(-10px);
        }
    }

    #aquasenseChatInput {
        transition: all 0.2s ease;
    }

    #aquasenseChatInput:focus {
        transform: scale(1.02);
        box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.1);
    }


    /* --- PURE CSS for SEND button (no Tailwind needed) --- */
    #aquasenseChatSend {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 8px 12px;
        border: none;
        border-radius: 10px;
        background: #0891b2;
        /* cyan-700-ish */
        color: #ffffff;
        cursor: pointer;
        line-height: 0;
        transition: background .2s ease, opacity .2s ease;
    }

    #aquasenseChatSend:hover {
        background: #0e7490;
    }

    /* darker on hover only */
    #aquasenseChatSend:disabled {
        opacity: .6;
        cursor: not-allowed;
    }


    @media (max-width: 480px) {
        .aquasense-chat-drawer {
            right: .75rem;
            width: calc(100vw - 1.5rem);
            height: 75vh;
        }
    }
</style>
<script>
    document.addEventListener("DOMContentLoaded", () => {
        const chatDrawer = document.getElementById("aquasenseChatDrawer");
        const launcher = document.getElementById("aquasenseChatLauncher");
        const closeBtn = document.getElementById("aquasenseChatClose");
        const chatMessages = document.getElementById("aquasenseChatMessages");
        const chatInput = document.getElementById("aquasenseChatInput");
        const chatSend = document.getElementById("aquasenseChatSend");

        // Quick sanity check (helps catch typos in IDs)
        if (!chatDrawer || !launcher || !closeBtn || !chatMessages || !chatInput || !chatSend) {
            console.error("AquaSense: missing element(s)", {
                chatDrawer, launcher, closeBtn, chatMessages, chatInput, chatSend
            });
            return;
        }

        let messageHistory = [];

        function createMessageBubble(text, sender = "user") {
            const div = document.createElement("div");
            div.className = `aquasense-bubble ${sender}`;
            div.textContent = text;
            return div;
        }

        function appendMessage(bubble) {
            chatMessages.appendChild(bubble);
            // force reflow so CSS animation runs reliably
            /* eslint-disable no-unused-expressions */
            bubble.getBoundingClientRect();
            /* eslint-enable no-unused-expressions */
            // scroll to bottom
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        function sendUserMessage(text) {
            const userBubble = createMessageBubble(text, "user");
            appendMessage(userBubble);
            messageHistory.push({ role: "user", content: text });
        }

        function sendBotMessage(text) {
            const botBubble = createMessageBubble(text, "bot");
            appendMessage(botBubble);
            messageHistory.push({ role: "assistant", content: text });
        }

        // typing indicator helper
        function addTypingIndicator() {
            const el = document.createElement("div");
            el.className = "typing-indicator";
            el.innerHTML = `<div class="typing-dots"><span></span><span></span><span></span></div>`;
            chatMessages.appendChild(el);
            chatMessages.scrollTop = chatMessages.scrollHeight;
            return el;
        }

        async function handleSendMessage() {
            const text = chatInput.value.trim();
            if (!text) return; // nothing to send

            // UI: disable button while processing
            chatSend.disabled = true;

            // add user message
            sendUserMessage(text);
            chatInput.value = "";
            chatInput.focus();

            // show typing indicator (simulate async work / API call)
            const typingEl = addTypingIndicator();
            try {
                const response = await fetch("chat.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ messages: messageHistory })
                });

                const data = await response.json();
                typingEl.remove();

                if (data.answer) {
                    sendBotMessage(data.answer);
                } else {
                    sendBotMessage("⚠️ No response from AI. Check PHP logs.");
                    console.error(data);
                }
            } catch (err) {
                console.error("AquaSense: OpenAI fetch error", err);
                typingEl.remove();
                sendBotMessage("⚠️ Error contacting server.");
            }

        }

        // Attach events
        chatSend.addEventListener("click", handleSendMessage);

        // Enter to send (Shift+Enter to keep if you later switch to a textarea)
        chatInput.addEventListener("keydown", (e) => {
            if (e.key === "Enter" && !e.shiftKey) {
                e.preventDefault();
                handleSendMessage();
            }
        });

        // Drawer control (open/close)
        function openDrawer() {
            chatDrawer.classList.add("open");
            chatDrawer.setAttribute("aria-hidden", "false");
            setTimeout(() => chatInput.focus(), 150);
        }
        function closeDrawer() {
            chatDrawer.classList.remove("open");
            chatDrawer.setAttribute("aria-hidden", "true");
            launcher.focus();
        }

        launcher.addEventListener("click", (e) => {
            e.stopPropagation();
            if (chatDrawer.classList.contains("open")) closeDrawer();
            else openDrawer();
        });

        closeBtn.addEventListener("click", (e) => {
            e.stopPropagation();
            closeDrawer();
        });

        // Click outside to close
        document.addEventListener("click", (e) => {
            if (!chatDrawer.classList.contains("open")) return;
            if (!chatDrawer.contains(e.target) && !launcher.contains(e.target)) closeDrawer();
        });

        // optional: keyboard ESC to close
        document.addEventListener("keydown", (e) => {
            if (e.key === "Escape" && chatDrawer.classList.contains("open")) closeDrawer();
        });

        // small dev helper: expose history on window for quick inspection
        window.__AquaSense = { messageHistory };
    });
</script>