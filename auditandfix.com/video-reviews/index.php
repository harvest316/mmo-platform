<?php
/**
 * Video-on-Demand — Self-serve video demo flow landing page.
 *
 * Interactive self-serve UX (mirrors scan.php pattern):
 *   1. Hero + 3 real example video cards (review screenshot -> video player)
 *   2. "Get Your Free Video" form: business name (Places Autocomplete), niche, country
 *   3. Email verification gate (shown after form submit)
 *   4. Post-verification confirmation (shown after email verified)
 *   5. Pricing cards (always visible below form)
 *
 * Geo-detected pricing via get2StepPriceForCountry().
 * Supports ?niche= URL param for Google Ads targeting.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/geo.php';
require_once __DIR__ . '/../includes/pricing.php';

$countryCode     = detectCountry();
$videoPricing    = get2StepPriceForCountry($countryCode);
$competitorRange = getCompetitorPriceRange($countryCode);
$nicheParam      = isset($_GET['niche']) ? htmlspecialchars($_GET['niche']) : '';

$symbol  = $videoPricing['symbol'];
$price4  = $videoPricing['monthly_4'];
$price8  = $videoPricing['monthly_8'];
$price12 = $videoPricing['monthly_12'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Turn Google Reviews into Video Content — Free Demo | Audit&amp;Fix</title>
    <meta name="description" content="Turn your 5-star Google reviews into professional 30-second videos — automatically. Free demo video included. Perfect for trades, dental, and local service businesses.">
    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/assets/img/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="canonical" href="https://www.auditandfix.com/video-reviews/">
    <meta property="og:title" content="Turn your Google reviews into 30-second videos">
    <meta property="og:description" content="Your customers already wrote the script. We just filmed it. Free demo video — no signup required.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://www.auditandfix.com/video-reviews/">
    <meta property="og:image" content="https://www.auditandfix.com/assets/img/og-image.png">
    <meta property="og:site_name" content="Audit&amp;Fix">
    <meta name="twitter:card" content="summary_large_image">
    <!-- Schema.org structured data -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@graph": [
        {
          "@type": "WebApplication",
          "name": "Audit&Fix Video Reviews",
          "url": "https://www.auditandfix.com/video-reviews/",
          "applicationCategory": "BusinessApplication",
          "description": "Turn your best Google reviews into professional 30-second videos. Free demo included. AI-powered production with voiceover, music, and your branding.",
          "offers": {
            "@type": "AggregateOffer",
            "lowPrice": "<?= $price4 ?>",
            "highPrice": "<?= $price12 ?>",
            "priceCurrency": "<?= $videoPricing['currency'] ?>"
          },
          "provider": {
            "@type": "Organization",
            "name": "Audit&Fix",
            "url": "https://www.auditandfix.com/"
          }
        },
        {
          "@type": "FAQPage",
          "mainEntity": [
            {"@type":"Question","name":"How does the free demo work?","acceptedAnswer":{"@type":"Answer","text":"We make one video from your best Google review at no cost. It\u2019s yours to keep and use however you want \u2014 no obligation to subscribe."}},
            {"@type":"Question","name":"What kind of businesses is this for?","acceptedAnswer":{"@type":"Answer","text":"Any local service business with Google reviews \u2014 pest control, plumbers, dentists, HVAC, roofers, dog trainers, and more. If your customers leave reviews, we can turn them into videos."}},
            {"@type":"Question","name":"How long does each video take to create?","acceptedAnswer":{"@type":"Answer","text":"Most videos are delivered within 24 hours. Each video is about 30 seconds \u2014 perfect for social media."}},
            {"@type":"Question","name":"Can I cancel anytime?","acceptedAnswer":{"@type":"Answer","text":"Yes. Monthly subscriptions with no lock-in. Cancel whenever you want."}}
          ]
        }
      ]
    }
    </script>
    <style>
        /* ── Video-on-Demand page-specific styles ──────────────── */

        /* Hero — full-width navy with gradient overlay */
        .vod-hero {
            background: linear-gradient(135deg, #1a365d 0%, #2d5fa3 50%, #1a365d 100%);
            color: #ffffff;
            padding: 0 0 60px;
        }
        .vod-hero-body {
            max-width: 800px;
            margin: 0 auto;
            padding: 48px 24px 0;
            text-align: center;
        }
        .vod-hero h1 {
            font-size: 2.4rem;
            line-height: 1.2;
            margin-bottom: 16px;
            font-weight: 800;
        }
        .vod-hero .subtitle {
            font-size: 1.15rem;
            opacity: 0.9;
            margin-bottom: 40px;
            line-height: 1.7;
        }

        /* Example video cards */
        .example-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            max-width: 960px;
            margin: 0 auto;
            padding: 0 24px;
        }
        .example-card {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 12px;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .example-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
        }
        .example-review {
            padding: 16px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            min-height: 90px;
        }
        .example-review-icon {
            width: 36px;
            height: 36px;
            background: #4285f4;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            font-weight: 700;
            flex-shrink: 0;
            color: #fff;
        }
        .example-review-text {
            flex: 1;
            min-width: 0;
        }
        .example-review-stars {
            color: #fbbf24;
            font-size: 0.85rem;
            letter-spacing: 1px;
            margin-bottom: 2px;
        }
        .example-review-snippet {
            font-size: 0.82rem;
            opacity: 0.8;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .example-arrow {
            text-align: center;
            padding: 6px 0;
            font-size: 1.2rem;
            opacity: 0.5;
        }
        .example-video-wrap {
            position: relative;
            background: #0d1b2a;
            aspect-ratio: 9 / 16;
            max-height: 280px;
        }
        .example-video-wrap video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .example-meta {
            padding: 12px 16px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }
        .example-meta-name {
            font-weight: 600;
            font-size: 0.92rem;
            margin-bottom: 2px;
        }
        .example-meta-info {
            font-size: 0.78rem;
            opacity: 0.6;
        }
        .example-meta-niche {
            display: inline-block;
            background: rgba(224, 93, 38, 0.2);
            color: #fbd38d;
            font-size: 0.7rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .example-disclaimer {
            text-align: center;
            font-size: 0.75rem;
            opacity: 0.5;
            margin-top: 20px;
            padding: 0 24px;
        }

        /* ── Form section ──────────────────────────────────────── */
        .vod-form-section {
            padding: 80px 20px;
            background: #ffffff;
        }
        .vod-form-section h2 {
            text-align: center;
            color: #1a365d;
            margin-bottom: 8px;
            font-size: 1.8rem;
        }
        .vod-form-section .section-subhead {
            margin-bottom: 32px;
        }
        .vod-form-wrap {
            max-width: 520px;
            margin: 0 auto;
            background: #f7fafc;
            padding: 36px 32px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }

        .vod-form .form-group {
            margin-bottom: 18px;
        }
        .vod-form .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            font-size: 0.88rem;
            color: #4a5568;
        }
        .vod-form .form-group input,
        .vod-form .form-group select {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 1rem;
            transition: border-color 0.2s;
            background: #ffffff;
        }
        .vod-form .form-group input:focus,
        .vod-form .form-group select:focus {
            outline: 3px solid #3182ce;
            outline-offset: 2px;
            border-color: #3182ce;
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }
        .vod-form-other-niche {
            margin-top: 8px;
            display: none;
        }
        .vod-form-btn {
            display: block;
            width: 100%;
            background: #e05d26;
            color: #ffffff;
            padding: 16px;
            border-radius: 6px;
            font-size: 1.1rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            margin-top: 4px;
        }
        .vod-form-btn:hover {
            background: #c44d1e;
            transform: translateY(-1px);
        }
        .vod-form-btn:disabled {
            background: #a0aec0;
            cursor: not-allowed;
            transform: none;
        }
        .vod-form-error {
            background: #fed7d7;
            color: #c53030;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 0.9rem;
            margin-bottom: 16px;
            display: none;
        }
        .vod-trust-badges {
            display: flex;
            justify-content: center;
            gap: 24px;
            margin-top: 16px;
            flex-wrap: wrap;
        }
        .vod-trust-badges span {
            font-size: 0.82rem;
            color: #718096;
        }

        /* ── Email verification gate ───────────────────────────── */
        .vod-email-section {
            padding: 60px 20px;
            background: #f7fafc;
            display: none;
        }
        .vod-email-wrap {
            max-width: 480px;
            margin: 0 auto;
            text-align: center;
        }
        .vod-email-wrap h2 {
            color: #1a365d;
            font-size: 1.5rem;
            margin-bottom: 8px;
        }
        .vod-email-wrap p {
            color: #4a5568;
            font-size: 0.95rem;
            margin-bottom: 24px;
            line-height: 1.6;
        }
        .vod-email-form {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .vod-email-form input {
            flex: 1;
            min-width: 200px;
            padding: 12px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 1rem;
        }
        .vod-email-form input:focus {
            outline: 3px solid #3182ce;
            outline-offset: 2px;
            border-color: #3182ce;
        }
        .vod-email-form button {
            background: #e05d26;
            color: #fff;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            transition: background 0.2s;
        }
        .vod-email-form button:hover {
            background: #c44d1e;
        }
        .vod-email-form button:disabled {
            background: #a0aec0;
            cursor: not-allowed;
        }
        .vod-email-note {
            font-size: 0.82rem;
            color: #a0aec0;
            margin-top: 12px;
        }
        .vod-email-error {
            background: #fed7d7;
            color: #c53030;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 0.9rem;
            margin-bottom: 16px;
            display: none;
        }

        /* ── Post-verification confirmation ─────────────────── */
        .vod-confirmation-section {
            padding: 60px 20px;
            background: #f0fff4;
            display: none;
            text-align: center;
        }
        .vod-confirmation-wrap {
            max-width: 560px;
            margin: 0 auto;
        }
        .vod-confirmation-icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: #38a169;
            color: #fff;
            font-size: 2.2rem;
            line-height: 72px;
            margin: 0 auto 20px;
        }
        .vod-confirmation-wrap h2 {
            color: #1a365d;
            font-size: 1.5rem;
            margin-bottom: 12px;
        }
        .vod-confirmation-wrap p {
            color: #4a5568;
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 12px;
        }
        .vod-progress-bar {
            background: #e2e8f0;
            border-radius: 20px;
            height: 8px;
            max-width: 320px;
            margin: 24px auto 8px;
            overflow: hidden;
        }
        .vod-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #38a169, #48bb78);
            border-radius: 20px;
            width: 0%;
            animation: vodProgressFill 3s ease-out forwards;
        }
        @keyframes vodProgressFill {
            0% { width: 0%; }
            60% { width: 70%; }
            100% { width: 92%; }
        }
        .vod-progress-label {
            font-size: 0.78rem;
            color: #718096;
        }

        /* ── Pricing section ───────────────────────────────────── */
        .vod-pricing-section {
            padding: 80px 20px;
            background: #f7fafc;
        }
        .vod-pricing-section h2 {
            text-align: center;
            color: #1a365d;
            margin-bottom: 8px;
            font-size: 1.8rem;
        }
        .vod-pricing-sub {
            text-align: center;
            color: #718096;
            margin-bottom: 40px;
            font-size: 1rem;
        }
        .vod-pricing-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            max-width: 840px;
            margin: 0 auto;
        }
        .vod-pricing-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 28px 24px;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .vod-pricing-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        }
        .vod-pricing-card.featured {
            border: 2px solid #2563eb;
            position: relative;
        }
        .vod-pricing-card.featured::before {
            content: 'Most Popular';
            position: absolute;
            top: -12px;
            left: 50%;
            transform: translateX(-50%);
            background: #2563eb;
            color: #fff;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 3px 14px;
            border-radius: 10px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .vod-pricing-tier {
            font-weight: 700;
            font-size: 1.05rem;
            color: #1a365d;
            margin-bottom: 8px;
        }
        .vod-pricing-price {
            font-size: 2.2rem;
            font-weight: 800;
            color: #2563eb;
            margin-bottom: 4px;
        }
        .vod-pricing-period {
            color: #718096;
            font-size: 0.88rem;
        }
        .vod-pricing-setup {
            font-size: 0.82rem;
            color: #38a169;
            font-weight: 600;
            margin: 8px 0 16px;
        }
        .vod-pricing-features {
            list-style: none;
            text-align: left;
            margin: 0;
            padding: 0;
        }
        .vod-pricing-features li {
            padding: 6px 0;
            font-size: 0.9rem;
            color: #4a5568;
            border-bottom: 1px solid #f0f4f8;
        }
        .vod-pricing-features li:last-child {
            border-bottom: none;
        }
        .vod-pricing-features li::before {
            content: '\2713';
            color: #38a169;
            font-weight: 700;
            margin-right: 8px;
        }
        .vod-pricing-cta {
            display: inline-block;
            background: #e05d26;
            color: #ffffff;
            padding: 12px 28px;
            border-radius: 6px;
            font-size: 0.95rem;
            font-weight: 700;
            text-decoration: none;
            margin-top: 20px;
            transition: background 0.2s, transform 0.1s;
        }
        .vod-pricing-cta:hover {
            background: #c44d1e;
            text-decoration: none;
            transform: translateY(-1px);
        }
        .vod-pricing-compare {
            text-align: center;
            margin-top: 28px;
            font-size: 0.9rem;
            color: #718096;
        }
        .vod-pricing-compare a {
            color: #3182ce;
        }

        /* ── How it works (steps) ──────────────────────────────── */
        .vod-steps-section {
            padding: 80px 20px;
        }
        .vod-steps-section h2 {
            text-align: center;
            color: #1a365d;
            margin-bottom: 12px;
            font-size: 1.8rem;
        }
        .vod-steps-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 32px;
            max-width: 840px;
            margin: 0 auto;
        }
        .vod-step {
            text-align: center;
        }
        .vod-step-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #dbeafe;
            color: #2563eb;
            font-weight: 700;
            font-size: 1.2rem;
            margin-bottom: 12px;
        }
        .vod-step h3 {
            color: #1a365d;
            font-size: 1.05rem;
            margin-bottom: 8px;
        }
        .vod-step p {
            color: #4a5568;
            font-size: 0.92rem;
            line-height: 1.6;
        }

        /* ── FAQ overrides for this page ──────────────────────── */
        .vod-faq {
            padding: 80px 20px;
            background: #ffffff;
        }

        /* ── Footer CTA ────────────────────────────────────────── */
        .vod-footer-cta {
            padding: 80px 20px;
            background: #1a365d;
            color: #ffffff;
            text-align: center;
        }
        .vod-footer-cta h2 {
            font-size: 1.8rem;
            margin-bottom: 24px;
        }
        .vod-footer-cta p {
            margin-top: 16px;
            opacity: 0.6;
            font-size: 0.88rem;
        }

        /* ── Responsive ────────────────────────────────────────── */
        @media (max-width: 768px) {
            .example-grid {
                grid-template-columns: 1fr;
                max-width: 320px;
            }
            .vod-hero h1 {
                font-size: 1.8rem;
            }
            .vod-pricing-grid {
                grid-template-columns: 1fr;
                max-width: 320px;
                margin: 0 auto;
            }
            .vod-steps-grid {
                grid-template-columns: 1fr;
                max-width: 360px;
                margin: 0 auto;
            }
            .vod-form-wrap {
                padding: 24px 20px;
            }
            .vod-email-form {
                flex-direction: column;
            }
        }
        @media (max-width: 480px) {
            .vod-hero h1 {
                font-size: 1.5rem;
            }
            .vod-trust-badges {
                flex-direction: column;
                align-items: center;
                gap: 6px;
            }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/consent-banner.php'; ?>
<a href="#main-content" class="skip-link">Skip to main content</a>

<?php require_once __DIR__ . '/../includes/header.php'; ?>

<!-- ── Hero + Example Videos ─────────────────────────────────────────────── -->
<header class="vod-hero" style="padding-top: 0;">

    <div class="vod-hero-body">
        <p class="pre-headline">Free video demo</p>
        <h1>Turn your 5-star Google reviews into a 30-second video &mdash; automatically.</h1>
        <p class="subtitle">Your customers already wrote the script. We just filmed it.</p>
    </div>

    <!-- 3 example cards: review screenshot -> video player -->
    <div class="example-grid">

        <!-- Example 1: Pest Control -->
        <div class="example-card">
            <div class="example-review">
                <div class="example-review-icon">S</div>
                <div class="example-review-text">
                    <div class="example-review-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                    <div class="example-review-snippet">"Called them about a termite problem and they were out the same day. Thorough inspection and treatment..."</div>
                </div>
            </div>
            <div class="example-arrow">&darr;</div>
            <div class="example-video-wrap">
                <video preload="metadata" playsinline muted loop poster="https://pub-9e277996d5a74eee9508a861cccead66.r2.dev/poster-s900001-1773998436379.jpg">
                    <source src="https://pub-9e277996d5a74eee9508a861cccead66.r2.dev/video-s900001-1773998424007.mp4" type="video/mp4">
                </video>
            </div>
            <div class="example-meta">
                <div class="example-meta-name">ACME Pest Control</div>
                <div class="example-meta-info">4.9&#9733; &middot; 492 reviews</div>
                <span class="example-meta-niche">Pest Control</span>
            </div>
        </div>

        <!-- Example 2: Plumber -->
        <div class="example-card">
            <div class="example-review">
                <div class="example-review-icon">M</div>
                <div class="example-review-text">
                    <div class="example-review-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                    <div class="example-review-snippet">"Had a burst pipe at 11pm and they answered straight away. Fixed everything within the hour..."</div>
                </div>
            </div>
            <div class="example-arrow">&darr;</div>
            <div class="example-video-wrap">
                <video preload="metadata" playsinline muted loop poster="https://pub-9e277996d5a74eee9508a861cccead66.r2.dev/poster-s900003-1773998536448.jpg">
                    <source src="https://pub-9e277996d5a74eee9508a861cccead66.r2.dev/video-s900003-1773998522268.mp4" type="video/mp4">
                </video>
            </div>
            <div class="example-meta">
                <div class="example-meta-name">ACME Plumbing</div>
                <div class="example-meta-info">4.7&#9733; &middot; 256 reviews</div>
                <span class="example-meta-niche">Plumber</span>
            </div>
        </div>

        <!-- Example 3: House Cleaning -->
        <div class="example-card">
            <div class="example-review">
                <div class="example-review-icon">J</div>
                <div class="example-review-text">
                    <div class="example-review-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                    <div class="example-review-snippet">"They left our place spotless. Even cleaned behind the fridge without us asking. Absolutely recommend..."</div>
                </div>
            </div>
            <div class="example-arrow">&darr;</div>
            <div class="example-video-wrap">
                <video preload="metadata" playsinline muted loop poster="https://pub-9e277996d5a74eee9508a861cccead66.r2.dev/poster-p25.jpg">
                    <source src="https://pub-9e277996d5a74eee9508a861cccead66.r2.dev/video-p25.mp4" type="video/mp4">
                </video>
            </div>
            <div class="example-meta">
                <div class="example-meta-name">Maid2Go Cleaning Sydney</div>
                <div class="example-meta-info">4.9&#9733; &middot; 827 reviews</div>
                <span class="example-meta-niche">House Cleaning</span>
            </div>
        </div>

    </div>

    <p class="example-disclaimer">Demo videos created from public Google reviews. Not a paid endorsement.</p>
</header>

<!-- ── Main Content ─────────────────────────────────────────────────────── -->
<main id="main-content">

    <!-- How it works -->
    <section class="vod-steps-section">
        <h2>How it works</h2>
        <p class="section-subhead">From Google review to polished video in three simple steps.</p>
        <div class="vod-steps-grid">
            <div class="vod-step">
                <div class="vod-step-number">1</div>
                <h3>Tell us your business</h3>
                <p>Enter your business name and niche. We scan your Google reviews and pick one that tells a great story.</p>
            </div>
            <div class="vod-step">
                <div class="vod-step-number">2</div>
                <h3>We create your video</h3>
                <p>AI-powered production turns the review into a polished 30-second video with voiceover, music, and your logo.</p>
            </div>
            <div class="vod-step">
                <div class="vod-step-number">3</div>
                <h3>Post it everywhere</h3>
                <p>Download your video and share it on social media, embed it on your site, or use it in ads. It's yours to keep.</p>
            </div>
        </div>
    </section>

    <!-- Section 2: Get Your Free Video form -->
    <section class="vod-form-section" id="get-video">
        <h2>Get Your Free Video</h2>
        <p class="section-subhead">One free video per business. No credit card required. Yours to keep forever.</p>

        <div class="vod-form-wrap" id="vod-form-container">
            <div id="vod-form-error" class="vod-form-error" role="alert"></div>
            <form class="vod-form" id="vod-form" novalidate>
                <div class="form-group">
                    <label for="vod-business-name">Business name</label>
                    <input
                        type="text"
                        id="vod-business-name"
                        name="business_name"
                        placeholder="e.g. Sydney Pest Pros"
                        autocomplete="off"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="vod-niche">Industry</label>
                    <select id="vod-niche" name="niche" required>
                        <option value="" disabled selected>Select your industry</option>
                        <option value="pest_control">Pest Control</option>
                        <option value="plumber">Plumber</option>
                        <option value="dentist">Dentist</option>
                        <option value="electrician">Electrician</option>
                        <option value="roofer">Roofer</option>
                        <option value="hvac">HVAC</option>
                        <option value="real_estate">Real Estate</option>
                        <option value="chiropractor">Chiropractor</option>
                        <option value="personal_injury_lawyer">Personal Injury Lawyer</option>
                        <option value="pool_installer">Pool Installer</option>
                        <option value="dog_trainer">Dog Trainer</option>
                        <option value="med_spa">Med Spa</option>
                        <option value="house_cleaning">House Cleaning</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <div class="form-group vod-form-other-niche" id="vod-other-niche-group">
                    <label for="vod-other-niche">Tell us your industry</label>
                    <input
                        type="text"
                        id="vod-other-niche"
                        name="other_niche"
                        placeholder="e.g. Landscaping"
                    >
                </div>

                <div class="form-group">
                    <label for="vod-country">Country</label>
                    <select id="vod-country" name="country" required>
                        <option value="AU" <?= $countryCode === 'AU' ? 'selected' : '' ?>>Australia</option>
                        <option value="NZ" <?= $countryCode === 'NZ' ? 'selected' : '' ?>>New Zealand</option>
                        <option value="US" <?= ($countryCode === 'US' || !in_array($countryCode, ['AU','NZ','UK','CA','GB'], true)) ? 'selected' : '' ?>>United States</option>
                        <option value="UK" <?= ($countryCode === 'UK' || $countryCode === 'GB') ? 'selected' : '' ?>>United Kingdom</option>
                        <option value="CA" <?= $countryCode === 'CA' ? 'selected' : '' ?>>Canada</option>
                    </select>
                </div>

                <button type="submit" class="vod-form-btn" id="vod-form-btn">Get Your Free Video &rarr;</button>
            </form>

            <div class="vod-trust-badges">
                <span>&#10003; Free, no credit card</span>
                <span>&#10003; One video per business</span>
                <span>&#10003; Yours to keep</span>
            </div>
        </div>
    </section>

    <!-- Section 3: Email verification gate (hidden until form submit) -->
    <section class="vod-email-section" id="vod-email-section">
        <div class="vod-email-wrap">
            <h2>Enter your email to receive your free video</h2>
            <p>We'll email you a verification link. Click it to start creating your video.</p>
            <div id="vod-email-error" class="vod-email-error" role="alert"></div>
            <form class="vod-email-form" id="vod-email-form" novalidate>
                <label for="vod-email-input" class="sr-only">Your email address</label>
                <input
                    type="email"
                    id="vod-email-input"
                    name="email"
                    placeholder="your@email.com"
                    autocomplete="email"
                    required
                >
                <button type="submit" id="vod-email-btn">Send My Video</button>
            </form>
            <p class="vod-email-note">We'll email you a verification link. Click it to start creating your video.</p>
        </div>
    </section>

    <!-- Section 4: Post-verification confirmation (hidden until email verified) -->
    <section class="vod-confirmation-section" id="vod-confirmation-section">
        <div class="vod-confirmation-wrap">
            <div class="vod-confirmation-icon" aria-hidden="true">&#10003;</div>
            <h2 id="vod-confirmation-title">Your video is being created!</h2>
            <p id="vod-confirmation-desc">Check your email soon. We'll send you a link to your personalised video as soon as it's ready.</p>
            <div class="vod-progress-bar">
                <div class="vod-progress-fill"></div>
            </div>
            <p class="vod-progress-label">Scanning your reviews and creating your video...</p>
        </div>
    </section>

    <!-- Section 5: Pricing (always visible) -->
    <section class="vod-pricing-section" id="pricing">
        <h2>Simple, transparent pricing</h2>
        <p class="vod-pricing-sub">Setup: $0 (waived). Monthly subscription. Cancel anytime.</p>

        <div class="vod-pricing-grid">
            <!-- Starter -->
            <div class="vod-pricing-card">
                <div class="vod-pricing-tier">Starter</div>
                <div class="vod-pricing-price"><?= $symbol ?><?= $price4 ?></div>
                <div class="vod-pricing-period">per month</div>
                <div class="vod-pricing-setup">Setup: $0 (waived)</div>
                <ul class="vod-pricing-features">
                    <li>4 videos per month</li>
                    <li>30-second format</li>
                    <li>Your logo + branding</li>
                    <li>Background music</li>
                    <li>AI voiceover</li>
                </ul>
                <a href="#get-video" class="vod-pricing-cta">Get Started</a>
            </div>

            <!-- Growth (featured) -->
            <div class="vod-pricing-card featured">
                <div class="vod-pricing-tier">Growth</div>
                <div class="vod-pricing-price"><?= $symbol ?><?= $price8 ?></div>
                <div class="vod-pricing-period">per month</div>
                <div class="vod-pricing-setup">Setup: $0 (waived)</div>
                <ul class="vod-pricing-features">
                    <li>8 videos per month</li>
                    <li>30-second format</li>
                    <li>Your logo + branding</li>
                    <li>Background music</li>
                    <li>AI voiceover</li>
                    <li>Priority delivery</li>
                </ul>
                <a href="#get-video" class="vod-pricing-cta">Get Started</a>
            </div>

            <!-- Scale -->
            <div class="vod-pricing-card">
                <div class="vod-pricing-tier">Scale</div>
                <div class="vod-pricing-price"><?= $symbol ?><?= $price12 ?></div>
                <div class="vod-pricing-period">per month</div>
                <div class="vod-pricing-setup">Setup: $0 (waived)</div>
                <ul class="vod-pricing-features">
                    <li>12 videos per month</li>
                    <li>30-second format</li>
                    <li>Your logo + branding</li>
                    <li>Background music</li>
                    <li>AI voiceover</li>
                    <li>Priority delivery</li>
                </ul>
                <a href="#get-video" class="vod-pricing-cta">Get Started</a>
            </div>
        </div>

        <p class="vod-pricing-compare">Comparable services charge <?= $competitorRange['symbol'] ?><?= $competitorRange['low'] ?>&ndash;<?= $competitorRange['symbol'] ?><?= $competitorRange['high'] ?>/month. <a href="/video-reviews/compare">See how we compare</a></p>
    </section>

    <!-- FAQ -->
    <section class="vod-faq faq">
        <h2>Frequently asked questions</h2>

        <div class="faq-item">
            <h3>How does the free demo work?</h3>
            <p>We make one video from your best Google review at no cost. It's yours to keep and use however you want &mdash; no obligation to subscribe.</p>
        </div>
        <div class="faq-item">
            <h3>What kind of businesses is this for?</h3>
            <p>Any local service business with Google reviews &mdash; pest control, plumbers, dentists, HVAC, roofers, dog trainers, and more. If your customers leave reviews, we can turn them into videos.</p>
        </div>
        <div class="faq-item">
            <h3>Do I need to do anything?</h3>
            <p>Nothing. We handle everything &mdash; finding reviews, creating videos, and delivering them to you. Just post them.</p>
        </div>
        <div class="faq-item">
            <h3>How long until I receive my video?</h3>
            <p>Most videos are delivered within 24 hours. We'll email you a link as soon as it's ready.</p>
        </div>
        <div class="faq-item">
            <h3>Can I cancel anytime?</h3>
            <p>Yes. Monthly subscriptions with no lock-in. Cancel whenever you want.</p>
        </div>
        <div class="faq-item">
            <h3>What format are the videos?</h3>
            <p>Each video is about 30 seconds in vertical (9:16) format &mdash; perfect for Instagram Reels, TikTok, YouTube Shorts, and Facebook Stories. Long enough to tell a story, short enough to keep people watching.</p>
        </div>
    </section>

    <!-- Footer CTA -->
    <section class="vod-footer-cta">
        <div class="container">
            <h2>Your reviews are already there. Let's turn them into content.</h2>
            <a href="#get-video" class="cta-button">Get Your Free Video &rarr;</a>
            <p>Free. No credit card. One video per business.</p>
        </div>
    </section>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
window.VOD_CONFIG = {
    apiBase: '<?= rtrim((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'], '/') ?>',
    countryCode: '<?= htmlspecialchars($countryCode) ?>',
    nicheParam: '<?= $nicheParam ?>',
    pricing: {
        symbol: '<?= $videoPricing['symbol'] ?>',
        monthly4: <?= $videoPricing['monthly_4'] ?>,
    },
};
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=GOOGLE_PLACES_API_KEY&libraries=places&callback=initPlacesAutocomplete" async defer></script>
<script src="/video-reviews/video-demo-flow.js?v=<?= filemtime(__DIR__ . '/video-demo-flow.js') ?: '1' ?>" defer></script>
<script src="/assets/js/obfuscate-email.js?v=1" defer></script>

<!-- Inline: niche pre-select + "Other" toggle (no external JS dependency) -->
<script>
(function() {
    'use strict';

    // Pre-select niche from URL param
    var nicheParam = window.VOD_CONFIG.nicheParam;
    if (nicheParam) {
        var nicheSelect = document.getElementById('vod-niche');
        if (nicheSelect) {
            // Try exact match first
            for (var i = 0; i < nicheSelect.options.length; i++) {
                if (nicheSelect.options[i].value === nicheParam) {
                    nicheSelect.value = nicheParam;
                    break;
                }
            }
        }
    }

    // Show/hide "Other" free-text field
    var nicheSelect = document.getElementById('vod-niche');
    var otherGroup = document.getElementById('vod-other-niche-group');
    if (nicheSelect && otherGroup) {
        nicheSelect.addEventListener('change', function() {
            otherGroup.style.display = this.value === 'other' ? 'block' : 'none';
        });
    }

    // Play example videos on hover (desktop) or tap (mobile)
    var exampleVideos = document.querySelectorAll('.example-video-wrap video');
    exampleVideos.forEach(function(video) {
        var card = video.closest('.example-card');
        if (!card) return;
        card.addEventListener('mouseenter', function() {
            video.play().catch(function() {});
        });
        card.addEventListener('mouseleave', function() {
            video.pause();
            video.currentTime = 0;
        });
        // Mobile tap-to-play
        video.addEventListener('click', function() {
            if (video.paused) {
                video.play().catch(function() {});
            } else {
                video.pause();
            }
        });
    });
})();
</script>

</body>
</html>
