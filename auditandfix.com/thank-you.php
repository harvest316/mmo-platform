<?php
require_once __DIR__ . '/includes/config.php';

$email = htmlspecialchars($_GET['email'] ?? 'your email');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You — Audit&Fix</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="canonical" href="https://www.auditandfix.com/thank-you">
    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <header class="hero hero-short">
        <nav class="nav">
            <a href="/" class="logo"><img src="assets/img/logo.svg" alt="Audit&amp;Fix" class="logo-img" onerror="this.style.display='none';this.nextElementSibling.style.display='inline'"><span class="logo-text" style="display:none">Audit<span class="logo-amp">&amp;</span>Fix</span></a>
        </nav>

        <div class="hero-body">
            <div class="hero-content">
                <h1>Thank You for Your Purchase!</h1>
            </div>
        </div>
    </header>

    <main id="main-content">
    <section class="thank-you-content">
        <div class="container">
            <div class="thank-you-box">
                <div class="check-circle">✓</div>
                <h2>Your CRO Audit Report is Being Prepared</h2>
                <p>We'll deliver your comprehensive audit report to <strong><?= $email ?></strong> within <strong>24 hours</strong>.</p>

                <div class="steps">
                    <div class="step">
                        <span class="step-num">1</span>
                        <span>Full-page screenshot capture</span>
                    </div>
                    <div class="step">
                        <span class="step-num">2</span>
                        <span>AI analysis of 10 conversion factors</span>
                    </div>
                    <div class="step">
                        <span class="step-num">3</span>
                        <span>Problem area identification with screenshots</span>
                    </div>
                    <div class="step">
                        <span class="step-num">4</span>
                        <span>PDF report delivered to your inbox</span>
                    </div>
                </div>

                <div class="spam-notice">
                    <p>Please check your spam/junk folder if you don't see the report within 24 hours.</p>
                </div>

                <div class="thankyou-referral">
                    <h3>Know another business owner?</h3>
                    <p>Forward them <strong>auditandfix.com</strong> — if they purchase, we'll give you <strong>15% off your next order</strong>. Just reply to your report delivery email with their details.</p>
                </div>

                <div class="thankyou-upsell">
                    <h3>Coming back after implementing your fixes?</h3>
                    <p>Order a <strong>follow-up benchmarking audit at 50% off</strong> to measure your improvement. Email us at the address in your report, or visit <a href="/">auditandfix.com</a> and mention your original order.</p>
                </div>

                <p class="contact-info">Questions? Contact us at <?= obfuscatedEmail(SUPPORT_EMAIL) ?></p>
            </div>
        </div>
    </section>
    </main>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
    <script src="assets/js/obfuscate-email.js?v=1" defer></script>
</body>
</html>
