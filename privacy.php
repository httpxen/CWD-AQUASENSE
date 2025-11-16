<?php /* privacy.php */ 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy Notice | CWD AquaSense</title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/icons/AquaSense2.png">

    <!-- Tailwind CDN (v3) -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Tailwind Config -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        cwd: {
                            primary: '#1E40AF',
                            accent:  '#3B82F6',
                            muted:   '#6B7280',
                            light:   '#F3F4F6',
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    },
                    typography: (theme) => ({
                        DEFAULT: {
                            css: {
                                maxWidth: 'none',
                                color: '#374151',
                                a: {
                                    color: theme('colors.cwd.accent'),
                                    fontWeight: '500',
                                    '&:hover': { textDecoration: 'underline' },
                                },
                                strong: { color: '#1F2937' },
                                'ul > li::marker': { color: theme('colors.cwd.primary') },
                            }
                        }
                    })
                }
            }
        }
    </script>

    <!-- Inter Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="bg-gray-50 text-gray-800 leading-relaxed antialiased min-h-screen">

    <!-- HEADER -->
    <header class="sticky top-0 bg-white shadow-sm z-50 border-b border-gray-100">
        <div class="max-w-5xl mx-auto px-6 py-4 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <img src="assets/icons/AquaSense2.png" alt="CWD AquaSense Logo" class="h-10 w-10 rounded-lg">
                <div>
                    <h1 class="text-xl font-bold text-cwd-primary leading-tight">CWD AquaSense</h1>
                    <p class="text-xs text-cwd-muted leading-none">Calamba Water District</p>
                </div>
            </div>
            <nav class="hidden md:flex items-center space-x-8 text-sm font-medium">
                <a href="index.php" class="text-cwd-muted hover:text-cwd-primary transition-colors">Home</a>
                <a href="privacy.php" class="text-cwd-primary font-semibold">Privacy Notice</a>
                <a href="terms.php" class="text-cwd-muted hover:text-cwd-primary transition-colors">Terms & Conditions</a>
            </nav>
        </div>
    </header>

    <!-- MAIN CONTENT -->
    <main class="max-w-4xl mx-auto px-6 py-12 lg:py-16">
        <!-- Title -->
        <div class="text-center mb-12">
            <h1 class="text-3xl md:text-4xl font-bold text-cwd-primary mb-2">Privacy Notice</h1>
            <p class="text-sm text-cwd-muted">Effective: <time datetime="2025-09-23">September 23, 2025</time></p>
        </div>

        <!-- Sections -->
        <?php
        $sections = [
            [
                'num' => '1',
                'title' => 'Introduction',
                'content' => <<<HTML
<p>Calamba Water District ("CWD", "we", "us") respects your privacy and is committed to protecting your personal data under the Philippine <strong>Data Privacy Act of 2012</strong> (Republic Act No. 10173) and its Implementing Rules and Regulations.</p>
<p>This Privacy Notice explains how we collect, use, disclose, and safeguard personal data through <strong>CWD AquaSense</strong> ("Service"), our online management system for water services, complaints, and related interactions.</p>
<p class="italic text-cwd-muted mt-3">By using the Service, you consent to the practices described herein. If you do not agree, please refrain from using the Service.</p>
HTML
            ],
            [
                'num' => '2',
                'title' => 'Information We Collect',
                'content' => <<<HTML
<p>We collect personal data to provide and improve the Service. This may include:</p>
<ul class="list-disc pl-6 space-y-2 mt-4">
    <li><strong>Account Data:</strong> Username, full name, email address, hashed password.</li>
    <li><strong>Usage Data:</strong> Complaints, chatbot interactions, survey responses, IP address, browser type, timestamps.</li>
    <li><strong>Technical Data:</strong> Device type, operating system, pages visited, session duration.</li>
</ul>
<p class="mt-4">We do not collect sensitive personal information unless voluntarily provided and necessary for complaint resolution.</p>
HTML
            ],
            [
                'num' => '3',
                'title' => 'How We Use Your Information',
                'content' => <<<HTML
<p>Your data is used solely for legitimate purposes, including:</p>
<ul class="list-disc pl-6 space-y-2 mt-4">
    <li>Account management and service delivery</li>
    <li>Processing and resolving complaints</li>
    <li>Improving user experience and system performance</li>
    <li>Sending essential notifications and updates</li>
    <li>Complying with legal and regulatory requirements</li>
</ul>
HTML
            ],
            [
                'num' => '4',
                'title' => 'Sharing Your Information',
                'content' => <<<HTML
<p>We do not sell, trade, or rent your personal data. Sharing occurs only when necessary:</p>
<ul class="list-disc pl-6 space-y-2 mt-4">
    <li>With authorized CWD personnel and trusted service providers under strict confidentiality</li>
    <li>To comply with legal obligations or protect public safety</li>
    <li>Aggregated and anonymized data for internal reporting and research</li>
</ul>
HTML
            ],
            [
                'num' => '5',
                'title' => 'Data Retention',
                'content' => <<<HTML
<p>Personal data is retained only as long as necessary for the purposes stated or as required by law. Inactive accounts may be deleted after <strong>1 year</strong> of inactivity, except where legal retention applies.</p>
HTML
            ],
            [
                'num' => '6',
                'title' => 'Your Data Privacy Rights',
                'content' => <<<HTML
<p>Under the Data Privacy Act, you have the right to:</p>
<ul class="list-disc pl-6 space-y-2 mt-4">
    <li><strong>Be informed</strong> about data processing</li>
    <li><strong>Access</strong> and receive a copy of your data</li>
    <li><strong>Correct</strong> inaccurate or incomplete information</li>
    <li><strong>Object</strong> or <strong>withdraw consent</strong></li>
    <li><strong>Request erasure</strong> (subject to legal exceptions)</li>
    <li><strong>Data portability</strong> in a structured format</li>
    <li><strong>Lodge a complaint</strong> with the NPC</li>
</ul>
<p class="mt-4">Exercise your rights by contacting our DPO. We respond within <strong>30 days</strong>.</p>
HTML
            ],
            [
                'num' => '7',
                'title' => 'Security Measures',
                'content' => <<<HTML
<p>We implement industry-standard safeguards, including:</p>
<ul class="list-disc pl-6 space-y-2 mt-4">
    <li>HTTPS encryption in transit</li>
    <li>Encrypted storage for sensitive data</li>
    <li>Role-based access control (RBAC)</li>
    <li>Regular security audits and logging</li>
</ul>
<p class="mt-4">While we strive for maximum security, no system is 100% immune. Report concerns immediately.</p>
HTML
            ],
            [
                'num' => '8',
                'title' => 'Cookies and Tracking',
                'content' => <<<HTML
<p>The Service uses essential cookies for authentication and functionality. Analytics cookies (if enabled) help improve performance. You may disable non-essential cookies via browser settings.</p>
HTML
            ],
            [
                'num' => '9',
                'title' => "Children's Privacy",
                'content' => <<<HTML
<p>The Service is not intended for individuals under 18 years of age. We do not knowingly collect data from minors without verifiable parental consent.</p>
HTML
            ],
            [
                'num' => '10',
                'title' => 'Changes to This Notice',
                'content' => <<<HTML
<p>We may update this Privacy Notice periodically. Material changes will be communicated via email or in-app notification. Continued use after changes implies acceptance.</p>
HTML
            ],
            [
                'num' => '11',
                'title' => 'Contact Information',
                'content' => <<<HTML
<p>For privacy concerns, rights requests, or inquiries:</p>
<ul class="list-disc pl-6 space-y-3 mt-4">
    <li><strong>DPO:</strong> <a href="mailto:dpo@cwd.example.ph" class="text-cwd-accent hover:underline font-medium">dpo@cwd.example.ph</a></li>
    <li><strong>Support:</strong> <a href="mailto:support@cwd.example.ph" class="text-cwd-accent hover:underline font-medium">support@cwd.example.ph</a></li>
    <li><strong>Address:</strong> Calamba Water District, Calamba City, Laguna, Philippines</li>
</ul>
<p class="mt-4">File complaints with the <a href="https://privacy.gov.ph" target="_blank" rel="noopener noreferrer" class="text-cwd-accent hover:underline font-medium">National Privacy Commission</a>.</p>
HTML
            ]
        ];

        foreach ($sections as $s) {
            echo <<<HTML
            <section id="section-{$s['num']}" class="mb-14 scroll-mt-20 md:scroll-mt-24 last:mb-8">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0 w-9 h-9 rounded-full bg-cwd-primary text-white text-sm font-bold flex items-center justify-center mt-1">
                        {$s['num']}
                    </div>
                    <div class="flex-1">
                        <h2 class="text-xl font-semibold text-cwd-primary mb-3">{$s['title']}</h2>
                        <div class="prose prose-lg max-w-none text-gray-700 leading-relaxed">
                            {$s['content']}
                        </div>
                    </div>
                </div>
            </section>
HTML;
        }
        ?>

        <!-- FOOTER -->
        <footer class="mt-20 pt-8 border-t border-gray-200 text-center text-sm text-cwd-muted">
            <p>Last updated: <strong>September 23, 2025</strong></p>
            <p class="mt-2">© <?php echo date('Y'); ?> Calamba Water District. All rights reserved.</p>
        </footer>
    </main>

    <!-- Back to Top Button -->
    <button 
        id="backToTop" 
        class="fixed bottom-6 right-6 w-12 h-12 bg-cwd-primary text-white rounded-full shadow-xl flex items-center justify-center text-2xl hover:bg-cwd-accent transition-all duration-300 opacity-0 invisible scale-0 origin-bottom-right"
        aria-label="Back to top"
        title="Back to top">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18" />
        </svg>
    </button>

    <script>
        const backToTop = document.getElementById('backToTop');
        let isScrolling;

        window.addEventListener('scroll', () => {
            window.clearTimeout(isScrolling);
            isScrolling = setTimeout(() => {
                if (window.scrollY > 400) {
                    backToTop.classList.remove('opacity-0', 'invisible', 'scale-0');
                    backToTop.classList.add('opacity-100', 'visible', 'scale-100');
                } else {
                    backToTop.classList.remove('opacity-100', 'visible', 'scale-100');
                    backToTop.classList.add('opacity-0', 'invisible', 'scale-0');
                }
            }, 66);
        });

        backToTop.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    </script>
</body>
</html>