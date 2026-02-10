// Mobile Menu
document.getElementById('mobile-menu-btn').addEventListener('click', function() {
    document.getElementById('mobile-menu').classList.toggle('hidden');
});
// testimonial-carousel.js

document.addEventListener('DOMContentLoaded', () => {
  const carousel = document.getElementById('testimonial-carousel');
  
  // Kung wala o hindi natagpuan ang carousel, tigilan na
  if (!carousel) return;

  const slides = document.querySelectorAll('#testimonial-carousel > div');
  
  // Kung isa lang o wala, walang saysay ang auto-slide
  if (slides.length <= 1) return;

  let currentSlide = 0;
  const slideDuration = 5000; // 5 seconds – pwede mong baguhin

  function goToSlide(index) {
    currentSlide = index;
    carousel.style.transform = `translateX(-${currentSlide * 100}%)`;
  }

  function nextSlide() {
    currentSlide = (currentSlide + 1) % slides.length;
    goToSlide(currentSlide);
  }

  // Simulan ang auto-slide
  let autoSlideInterval = setInterval(nextSlide, slideDuration);

  // Pause kapag hinover (desktop-friendly)
  const container = carousel.parentElement;

  container.addEventListener('mouseenter', () => {
    clearInterval(autoSlideInterval);
  });

  container.addEventListener('mouseleave', () => {
    // Iwasan ang multiple intervals – i-clear muna bago mag-set ulit
    clearInterval(autoSlideInterval);
    autoSlideInterval = setInterval(nextSlide, slideDuration);
  });

  // Mobile touch/swipe support
  let touchStartX = 0;
  let touchEndX = 0;

  container.addEventListener('touchstart', (e) => {
    touchStartX = e.changedTouches[0].screenX;
  }, { passive: true });

  container.addEventListener('touchend', (e) => {
    touchEndX = e.changedTouches[0].screenX;

    // Swipe left → next slide
    if (touchStartX - touchEndX > 60) {
      clearInterval(autoSlideInterval);
      nextSlide();
      autoSlideInterval = setInterval(nextSlide, slideDuration);
    }
    
    // Swipe right → previous slide (optional)
    if (touchEndX - touchStartX > 60) {
      clearInterval(autoSlideInterval);
      currentSlide = (currentSlide - 1 + slides.length) % slides.length;
      goToSlide(currentSlide);
      autoSlideInterval = setInterval(nextSlide, slideDuration);
    }
  }, { passive: true });
});
// Smooth Scroll
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        document.querySelector(this.getAttribute('href')).scrollIntoView({ behavior: 'smooth' });
    });
});
// Fade-in Animation
const observerOptions = { threshold: 0.1, rootMargin: '0px 0px -50px 0px' };
const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.classList.add('visible');
        }
    });
}, observerOptions);
document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));
// Counter Animation
function animateCounters() {
    const counters = document.querySelectorAll('.stat-counter');
    counters.forEach(counter => {
        const target = parseInt(counter.getAttribute('data-target'));
        const increment = target / 100;
        let current = 0;
        const timer = setInterval(() => {
            current += increment;
            if (current >= target) {
                counter.textContent = target.toLocaleString();
                clearInterval(timer);
            } else {
                counter.textContent = Math.floor(current).toLocaleString();
            }
        }, 20);
    });
}
document.querySelector('.py-20.bg-blue-50').addEventListener('mouseenter', animateCounters);
// TAP TO ZOOM
function openCleanZoom(src) {
    const modal = document.getElementById('cleanZoomModal');
    const img = document.getElementById('cleanZoomImage');
    img.src = src;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';
}
function closeCleanZoom() {
    const modal = document.getElementById('cleanZoomModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.style.overflow = 'auto';
}
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeCleanZoom();
});
// === CHATBOT LOGIC ===
let messageHistory = [];
let retryCount = 0;
const maxRetries = 3;
function openKuyaDaloy() {
    document.getElementById('kuyaDaloyModal').classList.add('show');
    document.getElementById('chatInput').focus();
    if (messageHistory.length === 0) {
        addBotMessage("Hello! I’m Kuya Daloy, your friendly water guide. How can I help you with your water services today? Kumusta ka?");
    }
}
function closeKuyaDaloy() {
    document.getElementById('kuyaDaloyModal').classList.remove('show');
}
function addMessage(text, isUser = false) {
    const messages = document.getElementById('chatMessages');
    const bubble = document.createElement('div');
    bubble.className = `chat-bubble ${isUser ? 'user' : 'bot'}`;
    bubble.innerHTML = text.replace(/\n/g, '<br>');
    messages.appendChild(bubble);
    messages.scrollTop = messages.scrollHeight;
}
function addTypingIndicator() {
    const typing = document.createElement('div');
    typing.className = 'typing-indicator';
    typing.innerHTML = '<div class="typing-dots"><span></span><span></span><span></span></div> Kuya Daloy is typing...';
    document.getElementById('chatMessages').appendChild(typing);
    document.getElementById('chatMessages').scrollTop = document.getElementById('chatMessages').scrollHeight;
    return typing;
}
async function sendMessageToAPI(text) {
    messageHistory.push({ role: 'user', content: text });
    const formData = new FormData();
    formData.append('messages', JSON.stringify(messageHistory));
    try {
        const response = await fetch('public_chat.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        return data.response || data.error || 'Sorry, something went wrong.';
    } catch (error) {
        return 'Connection error. Please try again.';
    }
}
document.getElementById('chatSend').addEventListener('click', async () => {
    const input = document.getElementById('chatInput');
    const text = input.value.trim();
    if (!text) return;
    input.value = '';
    addMessage(text, true);
    const typing = addTypingIndicator();
    document.getElementById('chatSend').disabled = true;
    let responseText = await sendMessageToAPI(text);
    while (responseText.includes('rate limit') && retryCount < maxRetries) {
        retryCount++;
        await new Promise(resolve => setTimeout(resolve, 2000 * retryCount));
        responseText = await sendMessageToAPI(text);
    }
    typing.remove();
    addMessage(responseText);
    messageHistory.push({ role: 'assistant', content: responseText });
    retryCount = 0;
    document.getElementById('chatSend').disabled = false;
});
document.getElementById('chatInput').addEventListener('keypress', (e) => {
    if (e.key === 'Enter') document.getElementById('chatSend').click();
});
function addBotMessage(text) {
    addMessage(text, false);
    messageHistory.push({ role: 'assistant', content: text });
}
// Set current year
document.getElementById('current-year').textContent = new Date().getFullYear();
// About Tabs Functionality
document.addEventListener('DOMContentLoaded', function() {
    const tabButtons = document.querySelectorAll('[data-tab]');
    const tabContents = document.querySelectorAll('.tab-content');
    tabButtons.forEach(button => {
        button.addEventListener('click', () => {
            const targetTab = button.getAttribute('data-tab');
            // Update active button
            tabButtons.forEach(btn => {
                btn.classList.remove('active-tab', 'bg-blue-600', 'text-white');
                btn.classList.add('bg-gray-100', 'text-gray-700');
            });
            button.classList.remove('bg-gray-100', 'text-gray-700');
            button.classList.add('active-tab', 'bg-blue-600', 'text-white');
            // Update active content
            tabContents.forEach(content => content.classList.add('hidden'));
            document.getElementById(targetTab).classList.remove('hidden');
        });
    });
});