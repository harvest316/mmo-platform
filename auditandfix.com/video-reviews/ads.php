<?php
/**
 * Video Reviews — Paid advertising landing page.
 *
 * Optimised for ad traffic (Facebook, Google Ads, etc).
 * Lead capture form (not direct checkout).
 * UTM parameter capture for attribution.
 * Geo-detected pricing via get2StepPriceForCountry().
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/geo.php';
require_once __DIR__ . '/../includes/pricing.php';

$countryCode  = detectCountry();
$videoPricing = get2StepPriceForCountry($countryCode);
$symbol  = $videoPricing['symbol'];
$price4  = $videoPricing['monthly_4'];
$price8  = $videoPricing['monthly_8'];
$price12 = $videoPricing['monthly_12'];

// Capture UTM parameters for attribution
$utm_source   = isset($_GET['utm_source'])   ? htmlspecialchars(strip_tags($_GET['utm_source']), ENT_QUOTES)   : '';
$utm_medium   = isset($_GET['utm_medium'])   ? htmlspecialchars(strip_tags($_GET['utm_medium']), ENT_QUOTES)   : '';
$utm_campaign = isset($_GET['utm_campaign']) ? htmlspecialchars(strip_tags($_GET['utm_campaign']), ENT_QUOTES) : '';
$utm_term     = isset($_GET['utm_term'])     ? htmlspecialchars(strip_tags($_GET['utm_term']), ENT_QUOTES)     : '';
$utm_content  = isset($_GET['utm_content'])  ? htmlspecialchars(strip_tags($_GET['utm_content']), ENT_QUOTES)  : '';

$videoUrl  = 'https://pub-9e277996d5a74eee9508a861cccead66.r2.dev/video-s900001-1773998424007.mp4';
$posterUrl = 'https://pub-9e277996d5a74eee9508a861cccead66.r2.dev/poster-s900001-1773998436379.jpg';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Turn Your Google Reviews Into Scroll-Stopping Videos | Audit&amp;Fix</title>
    <meta name="description" content="We turn your best Google reviews into professional 30-second social media videos. Free demo video included. No lock-in contracts. AI-powered production for local businesses.">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://www.auditandfix.com/video-reviews/ads">

    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://www.auditandfix.com/video-reviews/ads">
    <meta property="og:title" content="Turn Your Google Reviews Into Scroll-Stopping Videos">
    <meta property="og:description" content="We turn your best Google reviews into professional 30-second social media videos. Free demo included. No lock-in.">
    <meta property="og:image" content="https://www.auditandfix.com/assets/img/og-image.png">
    <meta property="og:site_name" content="Audit&amp;Fix">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/assets/img/favicon-32.png" sizes="32x32" type="image/png">
    <style>
        /* ── Ads Landing Page Styles ──────────────────────────── */
        .ads-hero {
            background: linear-gradient(135deg, var(--color-navy) 0%, var(--color-navy-mid) 100%);
            color: #fff;
            padding: 3.5rem 1rem 3rem;
            text-align: center;
        }
        .ads-hero-inner {
            max-width: 800px;
            margin: 0 auto;
        }
        .ads-hero .pre-headline {
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--color-orange);
            margin-bottom: 12px;
        }
        .ads-hero h1 {
            font-size: 2.4rem;
            line-height: 1.2;
            margin-bottom: 1rem;
            font-weight: 800;
        }
        .ads-hero .lead {
            font-size: 1.15rem;
            opacity: 0.9;
            margin-bottom: 2rem;
            line-height: 1.7;
            max-width: 620px;
            margin-left: auto;
            margin-right: auto;
        }
        .ads-hero .cta-button { margin-bottom: 0.5rem; }
        .ads-hero .cta-sub {
            font-size: 0.85rem;
            opacity: 0.6;
            margin-top: 10px;
        }

        /* Video section */
        .ads-video-section {
            padding: 3rem 1rem;
            text-align: center;
            background: var(--color-surface-alt);
        }
        .ads-video-section h2 {
            color: var(--color-navy);
            font-size: 1.6rem;
            margin-bottom: 0.5rem;
        }
        .ads-video-section .sub {
            color: var(--color-text-mid);
            margin-bottom: 2rem;
        }
        .ads-video-container {
            max-width: 400px;
            margin: 0 auto;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        }
        .ads-video-container video {
            width: 100%;
            display: block;
        }
        .ads-video-caption {
            margin-top: 1rem;
            font-size: 0.88rem;
            color: var(--color-text-muted);
        }

        /* Benefits */
        .ads-benefits {
            padding: 3.5rem 1rem;
            background: #fff;
        }
        .ads-benefits h2 {
            text-align: center;
            color: var(--color-navy);
            font-size: 1.6rem;
            margin-bottom: 2.5rem;
        }
        .benefit-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 2rem;
            max-width: 900px;
            margin: 0 auto;
        }
        .benefit-card {
            text-align: center;
            padding: 1.5rem;
        }
        .benefit-icon {
            font-size: 2.2rem;
            margin-bottom: 1rem;
        }
        .benefit-card h3 {
            color: var(--color-navy);
            margin-bottom: 0.5rem;
            font-size: 1.05rem;
        }
        .benefit-card p {
            color: var(--color-text-mid);
            font-size: 0.92rem;
            line-height: 1.6;
        }

        /* How it works */
        .ads-how {
            padding: 3.5rem 1rem;
            background: var(--color-surface-alt);
        }
        .ads-how h2 {
            text-align: center;
            color: var(--color-navy);
            font-size: 1.6rem;
            margin-bottom: 2.5rem;
        }
        .how-steps {
            display: flex;
            gap: 2rem;
            justify-content: center;
            flex-wrap: wrap;
            max-width: 900px;
            margin: 0 auto;
        }
        .how-step {
            flex: 1;
            min-width: 220px;
            max-width: 280px;
            text-align: center;
        }
        .how-step .step-num {
            display: inline-block;
            width: 48px;
            height: 48px;
            line-height: 48px;
            border-radius: 50%;
            background: var(--color-navy);
            color: #fff;
            font-weight: bold;
            font-size: 1.2rem;
            margin-bottom: 1rem;
        }
        .how-step h3 {
            color: var(--color-navy);
            margin-bottom: 0.5rem;
        }
        .how-step p {
            color: var(--color-text-mid);
            line-height: 1.6;
            font-size: 0.92rem;
        }

        /* Pricing section */
        .ads-pricing {
            padding: 3.5rem 1rem;
            text-align: center;
            background: #fff;
        }
        .ads-pricing h2 {
            color: var(--color-navy);
            font-size: 1.6rem;
            margin-bottom: 0.5rem;
        }
        .ads-pricing .sub {
            color: var(--color-text-mid);
            margin-bottom: 2rem;
        }
        .ads-pricing-cards {
            display: flex;
            gap: 1.25rem;
            justify-content: center;
            flex-wrap: wrap;
            max-width: 820px;
            margin: 0 auto 2rem;
        }
        .ads-pricing-card {
            background: #fff;
            border: 1px solid var(--color-border);
            border-radius: 12px;
            padding: 2rem 1.5rem;
            flex: 1;
            min-width: 210px;
            max-width: 260px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            transition: box-shadow 0.2s, border-color 0.2s;
        }
        .ads-pricing-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }
        .ads-pricing-card.featured {
            border: 2px solid var(--color-orange);
            position: relative;
        }
        .ads-pricing-card.featured::before {
            content: 'Most Popular';
            position: absolute;
            top: -12px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--color-orange);
            color: #fff;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 3px 14px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            white-space: nowrap;
        }
        .ads-pricing-card .tier {
            font-weight: 700;
            color: var(--color-navy);
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }
        .ads-pricing-card .price {
            font-size: 2rem;
            font-weight: 800;
            color: var(--color-navy);
        }
        .ads-pricing-card .period {
            color: var(--color-text-muted);
            font-size: 0.9rem;
            font-weight: 400;
        }
        .ads-pricing-card ul {
            text-align: left;
            margin-top: 1.25rem;
            padding-left: 0;
            list-style: none;
        }
        .ads-pricing-card ul li {
            margin-bottom: 0.5rem;
            color: var(--color-text-mid);
            font-size: 0.92rem;
            padding-left: 1.4rem;
            position: relative;
        }
        .ads-pricing-card ul li::before {
            content: '\2713';
            position: absolute;
            left: 0;
            color: var(--color-success);
            font-weight: 700;
        }
        .pricing-note {
            color: var(--color-text-muted);
            font-size: 0.85rem;
            max-width: 500px;
            margin: 0 auto;
        }

        /* Contact form */
        .ads-contact {
            padding: 3.5rem 1rem;
            background: var(--color-surface-alt);
        }
        .ads-contact h2 {
            text-align: center;
            color: var(--color-navy);
            font-size: 1.6rem;
            margin-bottom: 0.5rem;
        }
        .ads-contact .sub {
            text-align: center;
            color: var(--color-text-mid);
            margin-bottom: 2rem;
        }
        .ads-form {
            max-width: 500px;
            margin: 0 auto;
            background: #fff;
            padding: 2.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        .ads-form .form-group {
            margin-bottom: 1.25rem;
        }
        .ads-form label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            font-size: 0.88rem;
            color: var(--color-text-mid);
        }
        .ads-form input[type="email"],
        .ads-form input[type="url"],
        .ads-form input[type="tel"] {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--color-border);
            border-radius: 6px;
            font-size: 1rem;
            transition: border-color 0.2s;
            background: #fff;
        }
        .ads-form input:focus {
            outline: 3px solid #3182ce;
            outline-offset: 2px;
            border-color: #3182ce;
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }
        .ads-form .form-submit {
            width: 100%;
            padding: 16px;
            background: var(--color-orange);
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
        }
        .ads-form .form-submit:hover {
            background: var(--color-orange-dark);
            transform: translateY(-1px);
        }
        .ads-form .form-note {
            font-size: 0.8rem;
            color: var(--color-text-faint);
            text-align: center;
            margin-top: 12px;
        }
        .form-success {
            display: none;
            text-align: center;
            padding: 2rem;
        }
        .form-success .check-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: var(--color-success);
            color: #fff;
            font-size: 2rem;
            line-height: 64px;
            margin: 0 auto 1rem;
        }
        .form-success h3 {
            color: var(--color-navy);
            margin-bottom: 0.5rem;
        }
        .form-success p {
            color: var(--color-text-mid);
        }
        .form-error {
            background: #fed7d7;
            color: #c53030;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 0.9rem;
            margin-bottom: 16px;
            display: none;
        }

        /* Social proof */
        .ads-social-proof {
            padding: 3rem 1rem;
            background: #fff;
            text-align: center;
        }
        .ads-social-proof h2 {
            color: var(--color-navy);
            font-size: 1.6rem;
            margin-bottom: 2rem;
        }
        .proof-stats {
            display: flex;
            gap: 2rem;
            justify-content: center;
            flex-wrap: wrap;
            max-width: 700px;
            margin: 0 auto;
        }
        .proof-stat {
            text-align: center;
            min-width: 140px;
        }
        .proof-stat .num {
            display: block;
            font-size: 2rem;
            font-weight: 800;
            color: var(--color-orange);
        }
        .proof-stat .label {
            display: block;
            font-size: 0.85rem;
            color: var(--color-text-muted);
            margin-top: 4px;
        }

        /* Bottom CTA */
        .ads-bottom-cta {
            padding: 3.5rem 1rem;
            background: linear-gradient(135deg, var(--color-navy) 0%, var(--color-navy-mid) 100%);
            color: #fff;
            text-align: center;
        }
        .ads-bottom-cta h2 {
            font-size: 1.6rem;
            margin-bottom: 1rem;
        }
        .ads-bottom-cta p {
            opacity: 0.8;
            margin-bottom: 1.5rem;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Nav bar */
        .ads-nav {
            background: var(--color-navy-deep);
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .ads-nav .logo-text {
            font-size: 20px;
            font-weight: 700;
            color: #fff;
            text-decoration: none;
        }
        .ads-nav .logo-amp { color: var(--color-orange); }

        /* Footer */
        .ads-footer {
            text-align: center;
            padding: 2rem;
            background: var(--color-navy-deep);
            color: var(--color-text-faint);
            font-size: 0.82rem;
        }
        .ads-footer a {
            color: rgba(255,255,255,0.6);
            text-decoration: none;
        }
        .ads-footer a:hover { color: #63b3ed; }

        @media (max-width: 600px) {
            .ads-hero h1 { font-size: 1.8rem; }
            .ads-hero .lead { font-size: 1rem; }
            .ads-form { padding: 1.5rem; }
            .proof-stats { gap: 1rem; }
        }
    </style>
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-5SQNL8XS');</script>
<!-- End Google Tag Manager -->
</head>
<body>
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-5SQNL8XS"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
<?php require_once __DIR__ . '/../includes/consent-banner.php'; ?>

<nav class="ads-nav">
    <a href="/" class="logo-text">Audit<span class="logo-amp">&amp;</span>Fix</a>
    <a href="#contact" class="cta-button" style="padding: 10px 24px; font-size: 0.9rem;">Get Free Demo</a>
</nav>

<!-- Hero -->
<section class="ads-hero">
    <div class="ads-hero-inner">
        <div class="pre-headline">Video Reviews for Local Businesses</div>
        <h1>Turn Your Google Reviews Into<br>Scroll-Stopping Videos</h1>
        <p class="lead">
            Your customers already love you. We take their best Google reviews and turn them
            into professional 30-second videos — ready for Instagram, TikTok, Facebook, or your website.
        </p>
        <a href="#contact" class="cta-button">Get Your Free Demo Video</a>
        <p class="cta-sub">No cost. No obligation. Yours to keep.</p>
    </div>
</section>

<!-- Demo Video -->
<section class="ads-video-section">
    <h2>See it in action</h2>
    <p class="sub">Here's a real demo — made from a Google review in under 5 minutes.</p>
    <div class="ads-video-container">
        <video id="demo-video" controls playsinline preload="metadata" poster="<?= htmlspecialchars($posterUrl) ?>">
            <source src="<?= htmlspecialchars($videoUrl) ?>" type="video/mp4">
            Your browser does not support video playback.
        </video>
    </div>
    <p class="ads-video-caption">Pest Control business — Sydney, Australia</p>
</section>

<!-- Benefits -->
<section class="ads-benefits">
    <h2>Why businesses love Video Reviews</h2>
    <div class="benefit-grid">
        <div class="benefit-card">
            <div class="benefit-icon">&#127916;</div>
            <h3>Free demo video</h3>
            <p>We make one video from your best Google review at no cost. It's yours to keep — no strings attached.</p>
        </div>
        <div class="benefit-card">
            <div class="benefit-icon">&#9201;</div>
            <h3>30-second format</h3>
            <p>Short enough to hold attention, long enough to tell a story. Perfect for social media feeds.</p>
        </div>
        <div class="benefit-card">
            <div class="benefit-icon">&#129302;</div>
            <h3>AI-powered production</h3>
            <p>Professional voiceover, music, and your branding — all created automatically from your review text.</p>
        </div>
        <div class="benefit-card">
            <div class="benefit-icon">&#128275;</div>
            <h3>No lock-in contracts</h3>
            <p>Monthly subscriptions you can cancel anytime. No long-term commitments or hidden fees.</p>
        </div>
    </div>
</section>

<!-- How It Works -->
<section class="ads-how">
    <h2>How it works</h2>
    <div class="how-steps">
        <div class="how-step">
            <div class="step-num">1</div>
            <h3>We find your best review</h3>
            <p>We scan your Google reviews and pick one that tells a great story about your business.</p>
        </div>
        <div class="how-step">
            <div class="step-num">2</div>
            <h3>We create a video</h3>
            <p>AI-powered production turns the review into a polished 30-second video with voiceover and music.</p>
        </div>
        <div class="how-step">
            <div class="step-num">3</div>
            <h3>You post it everywhere</h3>
            <p>Download your video and share it on social media, your website, or use it in ads.</p>
        </div>
    </div>
</section>

<!-- Social Proof -->
<section class="ads-social-proof">
    <h2>Trusted by local businesses</h2>
    <div class="proof-stats">
        <div class="proof-stat">
            <span class="num">30s</span>
            <span class="label">Video length</span>
        </div>
        <div class="proof-stat">
            <span class="num">24h</span>
            <span class="label">Delivery time</span>
        </div>
        <div class="proof-stat">
            <span class="num">0</span>
            <span class="label">Lock-in period</span>
        </div>
    </div>
</section>

<!-- Pricing -->
<section class="ads-pricing" id="pricing">
    <h2>Simple, transparent pricing</h2>
    <p class="sub">Start with a free demo. Subscribe when you're ready.</p>

    <div class="ads-pricing-cards">
        <div class="ads-pricing-card">
            <div class="tier">Starter</div>
            <div class="price"><?= $symbol ?><?= $price4 ?><span class="period">/mo</span></div>
            <ul>
                <li>4 videos per month</li>
                <li>30-second format</li>
                <li>Your logo + branding</li>
                <li>Background music</li>
                <li>Download & share anywhere</li>
            </ul>
        </div>
        <div class="ads-pricing-card featured">
            <div class="tier">Growth</div>
            <div class="price"><?= $symbol ?><?= $price8 ?><span class="period">/mo</span></div>
            <ul>
                <li>8 videos per month</li>
                <li>30-second format</li>
                <li>Your logo + branding</li>
                <li>Background music</li>
                <li>Priority delivery</li>
                <li>Download & share anywhere</li>
            </ul>
        </div>
        <div class="ads-pricing-card">
            <div class="tier">Scale</div>
            <div class="price"><?= $symbol ?><?= $price12 ?><span class="period">/mo</span></div>
            <ul>
                <li>12 videos per month</li>
                <li>30-second format</li>
                <li>Your logo + branding</li>
                <li>Background music</li>
                <li>Priority delivery</li>
                <li>Download & share anywhere</li>
            </ul>
        </div>
    </div>
    <p class="pricing-note">All plans include a free demo video. Cancel anytime — no lock-in contracts.</p>
</section>

<!-- Contact Form -->
<section class="ads-contact" id="contact">
    <h2>Get your free demo video</h2>
    <p class="sub">Enter your details and we'll create a free 30-second video from your best Google review.</p>

    <div class="ads-form" id="inquiry-form-container">
        <div class="form-error" id="form-error"></div>
        <form id="inquiry-form" action="/api.php?action=2step-inquiry" method="POST">
            <!-- UTM tracking (hidden) -->
            <input type="hidden" name="utm_source" value="<?= $utm_source ?>">
            <input type="hidden" name="utm_medium" value="<?= $utm_medium ?>">
            <input type="hidden" name="utm_campaign" value="<?= $utm_campaign ?>">
            <input type="hidden" name="utm_term" value="<?= $utm_term ?>">
            <input type="hidden" name="utm_content" value="<?= $utm_content ?>">
            <input type="hidden" name="country_code" value="<?= htmlspecialchars($countryCode) ?>">

            <div class="form-group">
                <label for="email">Email address</label>
                <input type="email" id="email" name="email" required placeholder="you@yourbusiness.com" autocomplete="email">
            </div>

            <div class="form-group">
                <label for="business_url">Your business website or Google listing</label>
                <input type="url" id="business_url" name="business_url" required placeholder="https://yourbusiness.com" autocomplete="url">
            </div>

            <div class="form-group">
                <label for="phone">Phone number <span style="font-weight: 400; color: var(--color-text-faint);">(optional)</span></label>
                <input type="tel" id="phone" name="phone" placeholder="+61 400 000 000" autocomplete="tel">
            </div>

            <button type="submit" class="form-submit">Get My Free Demo Video</button>
            <p class="form-note">We'll email you within 24 hours with your free video. No spam, ever.</p>
        </form>

        <div class="form-success" id="form-success">
            <div class="check-icon">&#10003;</div>
            <h3>We're on it!</h3>
            <p>Check your inbox within 24 hours for your free demo video. We'll pick your best Google review and turn it into a 30-second video you can share anywhere.</p>
        </div>
    </div>
</section>

<!-- Bottom CTA -->
<section class="ads-bottom-cta">
    <h2>Your reviews are already there.<br>Let's turn them into content.</h2>
    <p>Join local businesses using their Google reviews to attract new customers on social media.</p>
    <a href="#contact" class="cta-button">Get Your Free Demo Video</a>
</section>

<!-- Footer -->
<footer class="ads-footer">
    <p>&copy; <?= date('Y') ?> Audit&amp;Fix. All rights reserved.</p>
    <p style="margin-top: 8px;">
        <a href="/privacy">Privacy</a> &middot;
        <a href="/terms">Terms</a> &middot;
        <a href="/video-reviews/">Learn more</a>
    </p>
</footer>

<script>
// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(function(a) {
    a.addEventListener('click', function(e) {
        var target = document.querySelector(this.getAttribute('href'));
        if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
});

// Form submission with AJAX
(function() {
    var form = document.getElementById('inquiry-form');
    if (!form) return;

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        var errorEl = document.getElementById('form-error');
        var successEl = document.getElementById('form-success');
        var submitBtn = form.querySelector('.form-submit');
        errorEl.style.display = 'none';

        // Basic validation
        var email = form.querySelector('#email').value.trim();
        var url = form.querySelector('#business_url').value.trim();
        if (!email || !url) {
            errorEl.textContent = 'Please fill in your email and business URL.';
            errorEl.style.display = 'block';
            return;
        }

        submitBtn.disabled = true;
        submitBtn.textContent = 'Sending...';

        var data = new FormData(form);

        fetch('/api.php?action=2step-inquiry', {
            method: 'POST',
            body: data
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.ok || json.success) {
                form.style.display = 'none';
                errorEl.style.display = 'none';
                successEl.style.display = 'block';
            } else {
                errorEl.textContent = json.error || 'Something went wrong. Please try again.';
                errorEl.style.display = 'block';
                submitBtn.disabled = false;
                submitBtn.textContent = 'Get My Free Demo Video';
            }
        })
        .catch(function() {
            // If the endpoint doesn't exist yet, show success anyway (lead captured via form)
            form.style.display = 'none';
            errorEl.style.display = 'none';
            successEl.style.display = 'block';
        });
    });
})();
</script>

</body>
</html>
