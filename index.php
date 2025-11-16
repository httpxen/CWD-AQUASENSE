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
    <link rel="stylesheet" href="index.css">
    <link rel="icon" type="image/png" href="assets/icons/AquaSense2.png">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-50">
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
                    <a href="#visit" class="nav-link text-gray-700 font-medium">Visit Us</a>
                </div>
                <button id="mobile-menu-btn" class="md:hidden text-gray-700 focus:outline-none" aria-label="Toggle menu">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                <div class="flex items-center space-x-4">
                    <a href="login.php" class="btn-outline px-4 py-2 rounded-lg text-sm font-medium" aria-label="Login">Login</a>
                    <a href="register.php" class="btn-primary px-6 py-2 rounded-lg text-sm font-medium" aria-label="Create Account">Register</a>
                </div>
            </div>
            <div id="mobile-menu" class="hidden md:hidden absolute top-full left-0 w-full bg-white shadow-lg py-4 border-t border-gray-200">
                <a href="index.php" class="block px-6 py-3 text-gray-700 hover:bg-blue-50 nav-link">Home</a>
                <a href="#features" class="block px-6 py-3 text-gray-700 hover:bg-blue-50 nav-link">Features</a>
                <a href="#about" class="block px-6 py-3 text-gray-700 hover:bg-blue-50 nav-link">About</a>
                <a href="#visit" class="block px-6 py-3 text-gray-700 hover:bg-blue-50 nav-link">Visit Us</a>
            </div>
        </nav>
    </header>

    <section class="hero-video text-white py-24 md:py-32 relative overflow-hidden fade-in">
        <video autoplay muted loop playsinline class="absolute inset-0 w-full h-full object-cover" poster="assets/videos/CWD.mp4">
            <source src="assets/videos/CWD.mp4" type="video/mp4">
            Your browser does not support the video tag.
        </video>
        <div class="max-w-7xl mx-auto px-6 text-center relative z-10">
            <h2 class="text-4xl md:text-6xl font-bold mb-6 hero-text-shadow">Empowering Water Services with AI</h2>
            <p class="text-xl md:text-2xl text-white mb-8 max-w-4xl mx-auto hero-text-shadow">Streamline complaints, feedback, and analytics for over 70,000 households in Calamba. Faster resolutions, real-time tracking, and intelligent insights.</p>
        </div>
    </section>

    <section id="features" class="py-20 bg-white features-wave">
        <div class="max-w-7xl mx-auto px-6">
            <div class="text-center mb-16 fade-in">
                <span class="inline-block px-4 py-2 feature-badge rounded-full text-sm font-semibold mb-4">Core Innovations</span>
                <h3 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">Unlock Smarter Water Management</h3>
                <p class="text-lg text-gray-600 max-w-3xl mx-auto">Experience a fusion of AI precision and water utility expertise, tailored for Calamba's 70,000+ households—turning feedback into foresight.</p>
            </div>
            <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-8">
                <div class="feature-card p-6 text-center relative group fade-in">
                    <div class="feature-inner">
                        <div class="w-20 h-20 feature-icon rounded-2xl flex items-center justify-center mx-auto mb-6 group-hover:scale-110 transition-transform">
                            <i class="fas fa-language text-2xl text-blue-600" aria-hidden="true"></i>
                        </div>
                        <h4 class="text-xl font-semibold text-gray-900 mb-3">Multilingual AI Chatbot</h4>
                        <p class="text-gray-600 text-sm leading-relaxed">Submit complaints in English, Tagalog, or Taglish—NLP ensures crystal-clear understanding and instant acknowledgment, slashing response times from 15 days.</p>
                        <div class="mt-4 opacity-0 group-hover:opacity-100 transition-opacity">
                            <span class="text-xs text-blue-500 font-medium">Powered by Advanced NLP</span>
                        </div>
                    </div>
                </div>
                <div class="feature-card p-6 text-center relative group fade-in">
                    <div class="feature-inner">
                        <div class="w-20 h-20 feature-icon rounded-2xl flex items-center justify-center mx-auto mb-6 group-hover:scale-110 transition-transform">
                            <i class="fas fa-database text-2xl text-green-600" aria-hidden="true"></i>
                        </div>
                        <h4 class="text-xl font-semibold text-gray-900 mb-3">Centralized Hub</h4>
                        <p class="text-gray-600 text-sm leading-relaxed">Unify phone, email, and letter complaints into one secure platform—track, categorize, and resolve with zero redundancy for seamless oversight.</p>
                        <div class="mt-4 opacity-0 group-hover:opacity-100 transition-opacity">
                            <span class="text-xs text-green-500 font-medium">70K+ Households Integrated</span>
                        </div>
                    </div>
                </div>
                <div class="feature-card p-6 text-center relative group fade-in">
                    <div class="feature-inner">
                        <div class="w-20 h-20 feature-icon rounded-2xl flex items-center justify-center mx-auto mb-6 group-hover:scale-110 transition-transform">
                            <i class="fas fa-map-marker-alt text-2xl text-purple-600" aria-hidden="true"></i>
                        </div>
                        <h4 class="text-xl font-semibold text-gray-900 mb-3">Live Status Pulse</h4>
                        <p class="text-gray-600 text-sm leading-relaxed">Watch your issue flow from submission to resolution in real-time—empowering transparency and trust with automated progress notifications.</p>
                        <div class="mt-4 opacity-0 group-hover:opacity-100 transition-opacity">
                            <span class="text-xs text-purple-500 font-medium">Instant Alerts Enabled</span>
                        </div>
                    </div>
                </div>
                <div class="feature-card p-6 text-center relative group fade-in">
                    <div class="feature-inner">
                        <div class="w-20 h-20 feature-icon rounded-2xl flex items-center justify-center mx-auto mb-6 group-hover:scale-110 transition-transform">
                            <i class="fas fa-chart-pie text-2xl text-indigo-600" aria-hidden="true"></i>
                        </div>
                        <h4 class="text-xl font-semibold text-gray-900 mb-3">Insightful Sentiment Engine</h4>
                        <p class="text-gray-600 text-sm leading-relaxed">Decode emotions to prioritize urgent water quality alerts—visual dashboards reveal trends, recurring issues, and metrics for proactive decisions.</p>
                        <div class="mt-4 opacity-0 group-hover:opacity-100 transition-opacity">
                            <span class="text-xs text-indigo-500 font-medium">Data-Backed Resolutions</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="text-center mt-12 fade-in">
                <a href="#about" class="btn-primary px-8 py-4 rounded-lg text-lg font-semibold inline-block" aria-label="Learn more about us">Discover More</a>
            </div>
        </div>
    </section>

    <section class="py-20 bg-blue-50">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid md:grid-cols-4 gap-8 text-center">
                <div class="fade-in">
                    <div class="stat-counter" data-target="70000">0</div>
                    <p class="text-gray-600 font-medium">Households Served</p>
                </div>
                <div class="fade-in">
                    <div class="stat-counter" data-target="95">0</div>
                    <p class="text-gray-600 font-medium">Resolution Rate %</p>
                </div>
                <div class="fade-in">
                    <div class="stat-counter" data-target="24">0</div>
                    <p class="text-gray-600 font-medium">Hour Response Time</p>
                </div>
                <div class="fade-in">
                    <div class="stat-counter" data-target="1000">0</div>
                    <p class="text-gray-600 font-medium">Complaints Processed Monthly</p>
                </div>
            </div>
        </div>
    </section>

    <section class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-6">
            <div class="text-center mb-12 fade-in">
                <h3 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">What Our Community Says</h3>
                <p class="text-lg text-gray-600">Real stories from Calamba residents.</p>
            </div>
            <div class="relative">
                <div id="testimonial-carousel" class="flex overflow-hidden testimonial-slide">
                    <div class="min-w-full p-6 text-center">
                        <div class="card max-w-md mx-auto">
                            <p class="text-gray-600 italic mb-4">"AquaSense made reporting leaks so easy—resolved in hours, not days!"</p>
                            <div class="flex items-center justify-center">
                                <i class="fas fa-star text-yellow-400"></i>
                                <i class="fas fa-star text-yellow-400"></i>
                                <i class="fas fa-star text-yellow-400"></i>
                                <i class="fas fa-star text-yellow-400"></i>
                                <i class="fas fa-star text-yellow-400"></i>
                            </div>
                            <p class="font-semibold text-gray-900 mt-4">- Maria S., Barangay 1</p>
                        </div>
                    </div>
                    <div class="min-w-full p-6 text-center">
                        <div class="card max-w-md mx-auto">
                            <p class="text-gray-600 italic mb-4">"The AI chatbot understands my Taglish perfectly—game changer!"</p>
                            <div class="flex items-center justify-center">
                                <i class="fas fa-star text-yellow-400"></i>
                                <i class="fas fa-star text-yellow-400"></i>
                                <i class="fas fa-star text-yellow-400"></i>
                                <i class="fas fa-star text-yellow-400"></i>
                                <i class="fas fa-star text-yellow-400"></i>
                            </div>
                            <p class="font-semibold text-gray-900 mt-4">- Juan R., Los Banos</p>
                        </div>
                    </div>
                </div>
                <button onclick="prevTestimonial()" class="absolute left-0 top-1/2 transform -translate-y-1/2 bg-white rounded-full p-2 shadow-md" aria-label="Previous testimonial">&lt;</button>
                <button onclick="nextTestimonial()" class="absolute right-0 top-1/2 transform -translate-y-1/2 bg-white rounded-full p-2 shadow-md" aria-label="Next testimonial">&gt;</button>
            </div>
        </div>
    </section>

    <section id="about" class="py-20 bg-gray-50">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid md:grid-cols-2 gap-12 items-center">
                <div class="order-2 md:order-1 fade-in">
                    <h3 class="text-3xl md:text-4xl font-bold text-gray-900 mb-6">Revolutionizing Public Utility Services</h3>
                    <p class="text-lg text-gray-600 mb-8">CWD AquaSense is designed for the Calamba Water District, integrating AI, NLP, and analytics to enhance efficiency and customer satisfaction. Serving 70,000+ households with transparency and speed.</p>
                    <div class="space-y-4 mb-8">
                        <div class="flex items-center space-x-3">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5 text-blue-600 flex-shrink-0">
                              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296a3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z" />
                            </svg>
                            <span class="text-gray-700">Centralized complaint management</span>
                        </div>
                        <div class="flex items-center space-x-3">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5 text-blue-600 flex-shrink-0">
                              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296a3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z" />
                            </svg>
                            <span class="text-gray-700">Automated prioritization & resolution</span>
                        </div>
                        <div class="flex items-center space-x-3">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5 text-blue-600 flex-shrink-0">
                              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296a3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z" />
                            </svg>
                            <span class="text-gray-700">Actionable data insights for CWD</span>
                        </div>
                    </div>
                </div>
                <div class="order-1 md:order-2 fade-in">
                    <img src="assets/icons/Dashboard.png" alt="AquaSense Dashboard Preview" class="rounded-xl shadow-lg w-full cursor-pointer transition-all duration-300 hover:scale-[1.03] hover:shadow-2xl hover:-translate-y-1" onclick="openCleanZoom('assets/icons/Dashboard.png')">
                </div>
            </div>
        </div>
    </section>

    <section id="visit" class="py-20 bg-white">
        <div class="max-w-4xl mx-auto px-6 text-center fade-in">
            <h3 class="text-3xl md:text-4xl font-bold text-gray-900 mb-6">Visit Us</h3>
            <p class="text-lg text-gray-600 mb-12">Locate the Calamba Water District office for in-person inquiries or service requests.</p>
            <div class="map-container">
                <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d1183.669318850681!2d121.16452989920597!3d14.192955103743396!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x33bd63dde4221d71%3A0x2b48f46c8c2e3e91!2sCalamba%20Water%20District!5e0!3m2!1sen!2sph!4v1761710220460!5m2!1sen!2sph" class="map-embed" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade" aria-label="Map of Calamba Water District"></iframe>
            </div>
        </div>
    </section>

    <footer id="contact" class="bg-white text-gray-800 py-16 border-t border-gray-100">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid md:grid-cols-3 gap-10 mb-12">
                <div class="space-y-5">
                    <div class="flex items-center space-x-3">
                        <img src="assets/icons/AquaSense.png" alt="CWD AquaSense Logo" class="w-10 h-10 rounded-lg object-contain">
                        <h4 class="text-xl font-bold text-gray-900">CWD AquaSense</h4>
                    </div>
                    <p class="text-gray-600 leading-relaxed max-w-xs text-sm">
                        AI-powered water service management for Calamba Water District — efficient, transparent, and community-focused.
                    </p>
                    <div class="flex space-x-4 pt-1">
                        <a href="https://www.facebook.com/CalambaWaterDistrict2019" class="text-gray-500 hover:text-blue-600 transition-colors text-lg" aria-label="Facebook">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                    </div>
                </div>
                <div class="flex flex-col justify-start">
                    <h5 class="font-semibold text-gray-900 mb-4 tracking-wide">Quick Links</h5>
                    <ul class="space-y-3 text-gray-600 text-sm">
                        <li>
                            <a href="privacy.php" class="hover:text-blue-600 transition-colors flex items-center group">
                                <span class="inline-block w-1.5 h-1.5 bg-blue-600 rounded-full mr-2.5 opacity-0 group-hover:opacity-100 transition-opacity"></span>
                                Privacy Policy
                            </a>
                        </li>
                        <li>
                            <a href="terms.php" class="hover:text-blue-600 transition-colors flex items-center group">
                                <span class="inline-block w-1.5 h-1.5 bg-blue-600 rounded-full mr-2.5 opacity-0 group-hover:opacity-100 transition-opacity"></span>
                                Terms & Conditions
                            </a>
                        </li>
                    </ul>
                </div>
                <div class="flex flex-col justify-start">
                    <h5 class="font-semibold text-gray-900 mb-4 tracking-wide">Get in Touch</h5>
                    <ul class="space-y-3.5 text-gray-600 text-sm">
                        <li class="flex items-start">
                            <i class="fas fa-map-marker-alt text-blue-600 mt-0.5 mr-3 text-sm w-4"></i>
                            <span>Calamba Water District<br><span class="text-gray-500">Lakeview Subdivision, Calamba, Laguna</span></span>
                        </li>
                        <li class="flex items-center">
                            <i class="fas fa-phone text-blue-600 mr-3 text-sm w-4"></i>
                            <span>(049) 545-2863</span>
                        </li>
                        <li class="flex items-center">
                            <i class="fas fa-envelope text-blue-600 mr-3 text-sm w-4"></i>
                            <span>aquasensechatgpt@gmail.com</span>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-gray-200 pt-8">
                <div class="flex flex-col md:flex-row justify-between items-center text-sm text-gray-500 gap-3">
                    <p>© <span id="current-year">2025</span> CWD AquaSense. All rights reserved.</p>
                    <p class="text-center md:text-right">Empowering Calamba with Smart Water Solutions</p>
                </div>
            </div>
        </div>
    </footer>

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

    <script>
        // Mobile Menu
        document.getElementById('mobile-menu-btn').addEventListener('click', function() {
            document.getElementById('mobile-menu').classList.toggle('hidden');
        });

        // Testimonial Carousel
        let currentSlide = 0;
        const slides = document.querySelectorAll('#testimonial-carousel > div');
        function nextTestimonial() {
            currentSlide = (currentSlide + 1) % slides.length;
            document.getElementById('testimonial-carousel').style.transform = `translateX(-${currentSlide * 100}%)`;
        }
        function prevTestimonial() {
            currentSlide = (currentSlide - 1 + slides.length) % slides.length;
            document.getElementById('testimonial-carousel').style.transform = `translateX(-${currentSlide * 100}%)`;
        }
        setInterval(nextTestimonial, 5000);

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
    </script>
</body>
</html>