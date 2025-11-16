<?php /* terms.php */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Terms and Conditions | CWD AquaSense</title>

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
                <a href="privacy.php" class="text-cwd-muted hover:text-cwd-primary transition-colors">Privacy Notice</a>
                <a href="terms.php" class="text-cwd-primary font-semibold">Terms & Conditions</a>
            </nav>
        </div>
    </header>

    <!-- MAIN CONTENT -->
    <main class="max-w-4xl mx-auto px-6 py-12 lg:py-16">
        <!-- Title -->
        <div class="text-center mb-12">
            <h1 class="text-3xl md:text-4xl font-bold text-cwd-primary mb-2">Terms and Conditions</h1>
            <p class="text-sm text-cwd-muted">Effective: <time datetime="2025-09-23">September 23, 2025</time></p>
        </div>

        <!-- Sections -->
        <?php
        $sections = [
            [
                'num' => '1',
                'title' => 'Acceptance of Terms',
                'content' => <<<HTML
<p>By creating an account or using <strong>CWD AquaSense</strong> (“Service”), you agree to be bound by these Terms and Conditions. If you do not agree, you must not access or use the Service.</p>
HTML
            ],
            [
                'num' => '2',
                'title' => 'Accounts & Eligibility',
                'content' => <<<HTML
<ul class="list-disc pl-6 space-y-2 mt-4">
    <li>You must provide accurate, complete, and updated registration information.</li>
    <li>You are solely responsible for maintaining the confidentiality of your account credentials.</li>
    <li>You are responsible for all activities that occur under your account.</li>
    <li>You must be at least 18 years old or have parental consent to use the Service.</li>
</ul>
HTML
            ],
            [
                'num' => '3',
                'title' => 'Acceptable Use',
                'content' => <<<HTML
<p>You agree not to use the Service for any unlawful, harmful, or prohibited purpose, including but not limited to:</p>
<ul class="list-disc pl-6 space-y-2 mt-4">
    <li>Engaging in fraudulent, abusive, or deceptive activities</li>
    <li>Violating any applicable local, national, or international law</li>
    <li>Interfering with the security, integrity, or performance of the Service</li>
    <li>Attempting to gain unauthorized access to systems or data</li>
</ul>
HTML
            ],
            [
                'num' => '4',
                'title' => 'User Content & License',
                'content' => <<<HTML
<p>You retain ownership of any content you submit (e.g., complaints, feedback). By submitting content, you grant CWD a worldwide, non-exclusive, royalty-free license to use, store, and process it solely to provide and improve the Service.</p>
HTML
            ],
            [
                'num' => '5',
                'title' => 'Data Privacy',
                'content' => <<<HTML
<p>We collect and process personal data in compliance with the <strong>Philippine Data Privacy Act of 2012</strong>. For full details on data handling, retention, and your rights, please refer to our <a href="privacy.php" class="text-cwd-accent hover:underline font-medium">Privacy Notice</a>.</p>
HTML
            ],
            [
                'num' => '6',
                'title' => 'Service Availability & Changes',
                'content' => <<<HTML
<p>We strive to maintain reliable access but do not guarantee uninterrupted availability. The Service may be modified, suspended, or discontinued temporarily or permanently for maintenance, upgrades, or other reasons without prior notice.</p>
HTML
            ],
            [
                'num' => '7',
                'title' => 'Security',
                'content' => <<<HTML
<p>We implement reasonable technical and organizational measures to protect your data and account. However, you must immediately report any suspected security incidents to <a href="mailto:support@cwd.example.ph" class="text-cwd-accent hover:underline font-medium">support@cwd.example.ph</a>.</p>
HTML
            ],
            [
                'num' => '8',
                'title' => 'Third-Party Services',
                'content' => <<<HTML
<p>The Service may integrate with third-party tools (e.g., email providers, analytics). Your use of such services is subject to their respective terms and privacy policies.</p>
HTML
            ],
            [
                'num' => '9',
                'title' => 'Termination',
                'content' => <<<HTML
<p>We reserve the right to suspend or terminate your account at any time, with or without notice, for violations of these Terms, suspicious activity, or legal requirements.</p>
HTML
            ],
            [
                'num' => '10',
                'title' => 'Disclaimers & Limitation of Liability',
                'content' => <<<HTML
<p>The Service is provided “as is” and “as available” without warranties of any kind. To the fullest extent permitted by law, CWD shall not be liable for any indirect, incidental, or consequential damages arising from your use of the Service.</p>
HTML
            ],
            [
                'num' => '11',
                'title' => 'Changes to Terms',
                'content' => <<<HTML
<p>We may update these Terms from time to time. Material changes will be notified via email or in-app message. Continued use of the Service after changes constitutes acceptance of the updated Terms.</p>
HTML
            ],
            [
                'num' => '12',
                'title' => 'Governing Law',
                'content' => <<<HTML
<p>These Terms are governed by the laws of the Republic of the Philippines. Any disputes shall be resolved exclusively in the proper courts of Laguna, Philippines.</p>
HTML
            ],
            [
                'num' => '13',
                'title' => 'Contact Information',
                'content' => <<<HTML
<p>For questions or concerns:<br>
<strong>Email:</strong> <a href="mailto:support@cwd.example.ph" class="text-cwd-accent hover:underline font-medium">support@cwd.example.ph</a></p>
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