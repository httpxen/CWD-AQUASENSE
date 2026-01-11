<!-- CLEAN ZOOM MODAL -->
<div id="cleanZoomModal" class="fixed inset-0 bg-black bg-opacity-95 hidden flex items-center justify-center z-[9999] p-4" onclick="closeCleanZoom()">
    <div class="relative max-w-6xl w-full">
        <button class="absolute top-4 right-4 text-white text-2xl hover:text-gray-300 transition z-10 bg-black bg-opacity-50 rounded-full p-2" onclick="event.stopPropagation(); closeCleanZoom()">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
        <img id="cleanZoomImage" src="" alt="Zoomed View" class="w-full h-auto max-h-screen object-contain rounded-lg shadow-2xl">
    </div>
</div>
<!-- Kuya Daloy Chatbot Modal -->
<div id="kuyaDaloyModal" class="chat-modal">
    <div class="chat-header">
        <div class="flex items-center gap-3">
            <img src="assets/icons/kuya-daloy.gif" alt="Kuya Daloy" class="w-12 h-12 rounded-full object-cover" />
            <div>
                <h4>Kuya Daloy</h4>
                <p class="text-xs opacity-80 m-0">Your water management helper</p>
            </div>
        </div>
        <button class="chat-close" onclick="closeKuyaDaloy()">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>
    <div id="chatMessages" class="chat-messages"></div>
    <div class="chat-input-container">
        <input id="chatInput" type="text" placeholder="Type your message..." class="chat-input" autocomplete="off" />
        <button id="chatSend" class="chat-send">
            <i class="fas fa-paper-plane"></i>
        </button>
    </div>
</div>
<!-- Toggle Button -->
<button id="kuyaDaloyToggle" class="chat-toggle" onclick="openKuyaDaloy()">
    <img src="assets/icons/kuya-daloy.gif" alt="Kuya Daloy Chat" class="w-12 h-12 rounded-full object-cover" />
</button>