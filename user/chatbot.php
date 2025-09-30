<!-- =========================
     AQUASENSE CHATBOT — UI ONLY
     ========================= -->
<!-- Floating Launcher Image -->
<img id="aquasenseChatLauncher" src="../assets/icons/AquaSense.png" alt="Chat Launcher" class="aquasense-launcher-img" />

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
            <button id="aquasenseChatClose" class="text-white/90 hover:text-white transition transform hover:scale-110" title="Close">
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
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                     stroke-width="1.5" stroke="currentColor" width="20" height="20" aria-hidden="true">
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
        border: 1px solid rgba(0,0,0,0.08);
        border-radius: 1rem;
        box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        overflow: hidden;
        transform: translateX(120%) scale(0.95);
        transition: all .35s cubic-bezier(.4,0,.2,1);
        z-index: 70; /* ↑ to sit above launcher */
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
        position: absolute; inset: 0;
        background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 20"><defs><pattern id="wave" x="0" y="0" width="100" height="20" patternUnits="userSpaceOnUse"><path d="M0 10 Q25 0 50 10 T100 10 V20 H0 Z" fill="%23ffffff" opacity="0.1"/></pattern></defs><rect width="100" height="20" fill="url(%23wave)"/></svg>');
        opacity: 0.1;
        animation: wave 20s linear infinite;
    }
    @keyframes wave { 0% { transform: translateX(0); } 100% { transform: translateX(-100px); } }

    .aquasense-scroll { scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent; }
    .aquasense-scroll::-webkit-scrollbar { width: 8px; }
    .aquasense-scroll::-webkit-scrollbar-thumb {
        background: linear-gradient(135deg, #06b6d4, #0891b2);
        border-radius: 9999px;
    }
    .aquasense-launcher-img {
        position: fixed; right: 1.5rem; bottom: 1.5rem;
        width: 4rem; height: 4rem;
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
    @keyframes float { 0%, 100% { transform: translateY(0px); } 50% { transform: translateY(-10px); } }

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
    .aquasense-bubble.animate { animation: messageSlideIn 0.4s ease-out forwards; }
    @keyframes messageSlideIn { to { opacity: 1; transform: translateY(0); } }

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
        display: flex; align-items: center; gap: 0.25rem;
        padding: 0.625rem 0.875rem;
        background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
        border: 1px solid #bae6fd;
        border-radius: 0.875rem;
        border-top-left-radius: .4rem;
        max-width: 80%;
    }
    .typing-dots { display: flex; gap: 0.25rem; }
    .typing-dots span {
        width: 0.5rem; height: 0.5rem; background: #06b6d4; border-radius: 50%;
        animation: typing 1.4s infinite ease-in-out;
    }
    .typing-dots span:nth-child(2) { animation-delay: 0.2s; }
    .typing-dots span:nth-child(3) { animation-delay: 0.4s; }
    @keyframes typing { 0%, 60%, 100% { transform: translateY(0); } 30% { transform: translateY(-10px); } }

    #aquasenseChatInput { transition: all 0.2s ease; }
    #aquasenseChatInput:focus { transform: scale(1.02); box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.1); }

  
    /* --- PURE CSS for SEND button (no Tailwind needed) --- */
    #aquasenseChatSend {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 8px 12px;
        border: none;
        border-radius: 10px;
        background: #0891b2;   /* cyan-700-ish */
        color: #ffffff;
        cursor: pointer;
        line-height: 0;
        transition: background .2s ease, opacity .2s ease;
    }
    #aquasenseChatSend:hover { background: #0e7490; } /* darker on hover only */
    #aquasenseChatSend:disabled { opacity: .6; cursor: not-allowed; }


    @media (max-width: 480px) {
        .aquasense-chat-drawer { right: .75rem; width: calc(100vw - 1.5rem); height: 75vh; }
    }
</style>

<script>
/* ===== AQUASENSE CHATBOT — safer send handler ===== */
const chatDrawer  = document.getElementById('aquasenseChatDrawer');
const chatOpenBtn = document.getElementById('aquasenseChatLauncher');
const chatClose   = document.getElementById('aquasenseChatClose');
const chatSend    = document.getElementById('aquasenseChatSend');
const chatInput   = document.getElementById('aquasenseChatInput');
const chatMsgs    = document.getElementById('aquasenseChatMessages');
const initialMsg  = document.getElementById('initialMessage');

function openChat()  { 
  chatDrawer.classList.add('open');
  if (chatOpenBtn) chatOpenBtn.style.display = 'none';   // hide launcher while open
}
function closeChat() { 
  chatDrawer.classList.remove('open'); 
  if (chatOpenBtn) chatOpenBtn.style.display = '';       // show launcher back
}

chatOpenBtn?.addEventListener('click', openChat);
chatClose?.addEventListener('click', closeChat);

setTimeout(() => initialMsg?.classList.add('animate'), 500);

// prevent double-spam while awaiting
let sending = false;

async function sendMessage() {
  if (sending) return;
  const text = chatInput.value.trim();
  if (!text) return;

  sending = true;
  chatSend.setAttribute('disabled', 'true');

  // add user bubble
  const userBubble = document.createElement('div');
  userBubble.className = 'aquasense-bubble user animate';
  userBubble.textContent = text;
  chatMsgs.appendChild(userBubble);
  chatInput.value = '';
  chatMsgs.scrollTop = chatMsgs.scrollHeight;

  // typing indicator
  const typingDiv = document.createElement('div');
  typingDiv.className = 'typing-indicator';
  typingDiv.innerHTML = '<div class="typing-dots"><span></span><span></span><span></span></div><span class="ml-2 text-sm text-gray-500">AquaSense is typing...</span>';
  chatMsgs.appendChild(typingDiv);
  chatMsgs.scrollTop = chatMsgs.scrollHeight;

  try {
    const res = await fetch('../api/chat.php', {   // ← adjust if your path differs
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: text })
    });

    // handle non-OK with readable message
    if (!res.ok) {
      let msg = 'Server error.';
      if (res.status === 429) msg = 'Rate limit reached. Please wait a moment.';
      if (res.status === 401 || res.status === 403) msg = 'Auth error. Contact admin.';
      try {
        const maybe = await res.json();
        if (maybe?.error) msg = maybe.error;
      } catch (_) {}
      throw new Error(msg);
    }

    // safe JSON parse
    let data;
    try {
      data = await res.json();
    } catch (e) {
      throw new Error('Invalid response from server.');
    }

    safeRemove(typingDiv);

    if (data.error) throw new Error(data.error);

    const botBubble = document.createElement('div');
    botBubble.className = 'aquasense-bubble bot animate';
    botBubble.textContent = data.reply;
    chatMsgs.appendChild(botBubble);
    chatMsgs.scrollTop = chatMsgs.scrollHeight;

  } catch (err) {
    safeRemove(typingDiv);
    const errorBubble = document.createElement('div');
    errorBubble.className = 'aquasense-bubble bot animate';
    errorBubble.textContent = (err && err.message) ? err.message : 'Oops! May problem sa connection. Subukan mo ulit? 💧';
    chatMsgs.appendChild(errorBubble);
    chatMsgs.scrollTop = chatMsgs.scrollHeight;
    console.error('Chat error:', err);
  } finally {
    sending = false;
    chatSend.removeAttribute('disabled');
  }
}

// helper to avoid "node to be removed is not a child" error
function safeRemove(node) {
  if (node && node.parentNode) node.parentNode.removeChild(node);
}

chatSend.addEventListener('click', sendMessage);
chatInput.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});
</script>
