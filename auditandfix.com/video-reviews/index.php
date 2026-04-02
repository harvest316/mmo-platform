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
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/geo.php';
require_once __DIR__ . '/../includes/pricing.php';

$countryCode     = detectCountry();
$videoPricing    = get2StepPriceForCountry($countryCode);
$competitorRange = getCompetitorPriceRange($countryCode);
$nicheParam      = isset($_GET['niche']) ? htmlspecialchars($_GET['niche']) : '';
// Verification link params — ?verify=DEMO_ID&token=TOKEN (from email link)
$verifyDemoId    = isset($_GET['verify']) ? preg_replace('/[^a-f0-9\-]/', '', $_GET['verify']) : '';
$verifyToken     = isset($_GET['token'])  ? preg_replace('/[^a-f0-9]/',   '', $_GET['token'])  : '';

$symbol  = $videoPricing['symbol'];
$price4  = $videoPricing['monthly_4'];
$price8  = $videoPricing['monthly_8'];
$price12 = $videoPricing['monthly_12'];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>"<?= $lang === 'ar' ? ' dir="rtl"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('vr.page_title') ?></title>
    <meta name="description" content="<?= t('vr.page_description') ?>">
    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/assets/img/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="canonical" href="https://www.auditandfix.com/video-reviews/">
    <meta property="og:title" content="<?= t('vr.og_title') ?>">
    <meta property="og:description" content="<?= t('vr.og_description') ?>">
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
            padding: 14px 16px;
        }
        .example-review-stars {
            color: #fbbf24;
            font-size: 0.85rem;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }
        .example-review-snippet {
            font-size: 0.82rem;
            opacity: 0.85;
            line-height: 1.5;
        }
        .example-review-snippet a {
            color: inherit;
            text-decoration: none;
        }
        .example-review-snippet a:hover {
            text-decoration: underline;
            opacity: 1;
        }
        .example-video-wrap {
            position: relative;
            background: #0d1b2a;
            aspect-ratio: 9 / 16;
            cursor: pointer;
        }
        .example-video-wrap video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        /* Hide native browser play button — we use our own overlay */
        .example-video-wrap video::-webkit-media-controls-panel,
        .example-video-wrap video::-webkit-media-controls-play-button,
        .example-video-wrap video::-webkit-media-controls-start-playback-button,
        .example-video-wrap video::-webkit-media-controls {
            display: none !important;
            -webkit-appearance: none;
        }
        .example-play-btn {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, 0.2);
            transition: opacity 0.2s ease, background 0.2s ease;
        }
        .example-play-btn svg {
            width: 52px;
            height: 52px;
            color: rgba(255, 255, 255, 0.92);
            filter: drop-shadow(0 2px 10px rgba(0, 0, 0, 0.6));
        }
        .example-play-btn:hover { background: rgba(0, 0, 0, 0.35); }
        .example-video-wrap.playing .example-play-btn {
            opacity: 0;
            pointer-events: none;
        }

        /* ── Video lightbox ─────────────────────────────────── */
        .video-lightbox {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        .video-lightbox--open {
            display: flex;
        }
        .video-lightbox__backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
        .video-lightbox__content {
            position: relative;
            max-width: 420px;
            width: 90vw;
            max-height: 90vh;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        }
        .video-lightbox__video {
            width: 100%;
            display: block;
            background: #000;
            cursor: pointer;
        }
        .video-lightbox__close {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 40px;
            height: 40px;
            background: rgba(0, 0, 0, 0.5);
            color: #fff;
            border: none;
            border-radius: 50%;
            font-size: 22px;
            line-height: 40px;
            text-align: center;
            cursor: pointer;
            z-index: 1;
            transition: background 0.2s;
        }
        .video-lightbox__close:hover {
            background: rgba(0, 0, 0, 0.75);
        }

        .example-meta {
            padding: 12px 16px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }
        .example-meta-name {
            display: block;
            font-weight: 600;
            font-size: 0.92rem;
            margin-bottom: 2px;
            color: inherit;
            text-decoration: none;
        }
        .example-meta-name:hover { text-decoration: underline; }
        .example-meta-info {
            display: block;
            font-size: 0.78rem;
            opacity: 0.6;
            color: inherit;
            text-decoration: none;
        }
        .example-meta-info:hover { opacity: 0.85; }
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

        /* ── Email sent / check inbox ───────────────────────── */
        .vod-email-sent-section {
            padding: 60px 20px;
            background: #ebf8ff;
            display: none;
            text-align: center;
        }
        .vod-email-sent-wrap {
            max-width: 520px;
            margin: 0 auto;
        }
        .vod-email-sent-icon {
            font-size: 3rem;
            margin-bottom: 16px;
        }
        .vod-email-sent-section h2 {
            color: #1a365d;
            font-size: 1.5rem;
            margin-bottom: 12px;
        }
        .vod-email-sent-section p {
            color: #4a5568;
            line-height: 1.6;
        }
        .vod-email-sent-address {
            font-weight: 600;
            color: #2b6cb0;
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
            background: linear-gradient(135deg, #1a365d 0%, #2a4a7f 100%);
        }
        .vod-pricing-section h2 {
            text-align: center;
            color: #ffffff;
            margin-bottom: 8px;
            font-size: 1.8rem;
        }
        .vod-pricing-sub {
            text-align: center;
            color: rgba(255, 255, 255, 0.75);
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
            content: '<?= t('vr.pricing_most_popular') ?>';
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
<a href="#main-content" class="skip-link"><?= t('vr.skip_link') ?></a>

<?php $headerTheme = 'light'; require_once __DIR__ . '/../includes/header.php'; ?>

<!-- ── Hero + Example Videos ─────────────────────────────────────────────── -->
<header class="vod-hero" style="padding-top: 0;">

    <div class="vod-hero-body">
        <p class="pre-headline"><?= t('vr.hero_pre_headline') ?></p>
        <h1><?= t('vr.hero_title') ?></h1>
        <p class="subtitle"><?= t('vr.hero_subtitle') ?></p>
    </div>

    <!-- 3 example cards: review screenshot -> video player -->
    <div class="example-grid">

        <!-- Example 1: Pest Control -->
        <div class="example-card">
            <div class="example-video-wrap">
                <video preload="auto" playsinline muted loop poster="https://cdn.auditandfix.com/poster-s3-1774578500984.jpg">
                    <source src="https://cdn.auditandfix.com/video-s3-1774578488894.mp4" type="video/mp4">
                </video>
                <div class="example-play-btn" aria-label="<?= t('vr.play_video') ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                </div>
            </div>
            <div class="example-review">
                <div class="example-review-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                <div class="example-review-snippet"><a href="https://search.google.com/local/reviews?placeid=ChIJH_Q423e9EmsRC_df825qoBo&q=R+VM" target="_blank" rel="noopener">&ldquo;Reece and the team were knowledgeable, responsive, and easy to book. Despite difficult to access to attic space, two possums were safely trapped and released over four days. We were advised leaving the cage out an extra night to get more &mdash; which proved helpful when a third returned briefly before moving on. After another company suggested rats (despite the noise clearly indicating otherwise), we were relieved to have the issue correctly identified and resolved. The team also sealed any likely entry points, even though both companies remain unsure of continued access. Possums have not returned.&rdquo; &mdash; R VM</a></div>
            </div>
            <div class="example-meta">
                <a href="https://search.google.com/local/reviews?placeid=ChIJH_Q423e9EmsRC_df825qoBo" target="_blank" rel="noopener" class="example-meta-name">BugFree Pest Control</a>
                <a href="https://search.google.com/local/reviews?placeid=ChIJH_Q423e9EmsRC_df825qoBo" target="_blank" rel="noopener" class="example-meta-info">4.9&#9733; &middot; 5,103 reviews</a>
            </div>
        </div>

        <!-- Example 2: Plumber -->
        <div class="example-card">
            <div class="example-video-wrap">
                <video preload="auto" playsinline muted loop poster="https://cdn.auditandfix.com/poster-s19-1774577448578.jpg">
                    <source src="https://cdn.auditandfix.com/video-s19-1774577432668.mp4" type="video/mp4">
                </video>
                <div class="example-play-btn" aria-label="<?= t('vr.play_video') ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                </div>
            </div>
            <div class="example-review">
                <div class="example-review-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                <div class="example-review-snippet"><a href="https://search.google.com/local/reviews?placeid=ChIJJ8arvB27EmsRbyFxWhykxr8&q=Selena+Lin" target="_blank" rel="noopener">&ldquo;Jim and Dan came to our place today to fix a plumbing issue and did an outstanding job. They managed to clear a 20+-year-old plumbing blockage, which made a huge difference. We&rsquo;ve just moved into this house, and from a long-term perspective the service was absolutely worth it &mdash; 200% value. Huge thanks to Jim and Dan for delivering such an excellent result for our new home! I would definitely recommend them and will be asking for Jim and Dan again in the future.&rdquo; &mdash; Selena Lin</a></div>
            </div>
            <div class="example-meta">
                <a href="https://search.google.com/local/reviews?placeid=ChIJJ8arvB27EmsRbyFxWhykxr8" target="_blank" rel="noopener" class="example-meta-name">Fix n Flow</a>
                <a href="https://search.google.com/local/reviews?placeid=ChIJJ8arvB27EmsRbyFxWhykxr8" target="_blank" rel="noopener" class="example-meta-info">4.8&#9733; &middot; 812 reviews</a>
            </div>
        </div>

        <!-- Example 3: House Cleaning -->
        <div class="example-card">
            <div class="example-video-wrap">
                <video preload="auto" playsinline muted loop poster="https://cdn.auditandfix.com/poster-s25-1774578668477.jpg">
                    <source src="https://cdn.auditandfix.com/video-s25-1774578655370.mp4" type="video/mp4">
                </video>
                <div class="example-play-btn" aria-label="<?= t('vr.play_video') ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                </div>
            </div>
            <div class="example-review">
                <div class="example-review-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                <div class="example-review-snippet"><a href="https://search.google.com/local/reviews?placeid=ChIJX_yV9selEmsR4F5SE40gR28&q=Mandy+Kwon" target="_blank" rel="noopener">&ldquo;I recently booked a deep clean with Maid2Go, and I cannot express how much of a relief the experience was. As someone with ADHD, I often struggle with keeping up with cleaning, and the task of a &lsquo;deep clean&rsquo; always felt too overwhelming to start. The quote was really reasonable. I also want to specifically mention how wonderful the cleaner, Rahman, was throughout the process. He was incredibly respectful, professional, and left the place absolutely sparkling. He cleaned places that I wouldn&rsquo;t have even thought to touch. Every hidden corner and neglected surface was addressed with such care and the level of thoroughness was truly incredible. &hellip;&rdquo; &mdash; Mandy Kwon</a></div>
            </div>
            <div class="example-meta">
                <a href="https://search.google.com/local/reviews?placeid=ChIJX_yV9selEmsR4F5SE40gR28" target="_blank" rel="noopener" class="example-meta-name">Maid2Go Cleaning Sydney</a>
                <a href="https://search.google.com/local/reviews?placeid=ChIJX_yV9selEmsR4F5SE40gR28" target="_blank" rel="noopener" class="example-meta-info">4.9&#9733; &middot; 827 reviews</a>
            </div>
        </div>

    </div>

    <p class="example-disclaimer"><?= t('vr.example_disclaimer') ?></p>
</header>

<!-- ── Main Content ─────────────────────────────────────────────────────── -->
<main id="main-content">

    <!-- How it works -->
    <section class="vod-steps-section">
        <h2><?= t('vr.steps_title') ?></h2>
        <p class="section-subhead"><?= t('vr.steps_subtitle') ?></p>
        <div class="vod-steps-grid">
            <div class="vod-step">
                <div class="vod-step-number">1</div>
                <h3><?= t('vr.step1_title') ?></h3>
                <p><?= t('vr.step1_desc') ?></p>
            </div>
            <div class="vod-step">
                <div class="vod-step-number">2</div>
                <h3><?= t('vr.step2_title') ?></h3>
                <p><?= t('vr.step2_desc') ?></p>
            </div>
            <div class="vod-step">
                <div class="vod-step-number">3</div>
                <h3><?= t('vr.step3_title') ?></h3>
                <p><?= t('vr.step3_desc') ?></p>
            </div>
        </div>
    </section>

    <!-- Section 2: Get Your Free Video form -->
    <section class="vod-form-section" id="get-video">
        <h2><?= t('vr.form_title') ?></h2>
        <p class="section-subhead"><?= t('vr.form_subtitle') ?></p>

        <div class="vod-form-wrap" id="vod-form-container">
            <div id="vod-form-error" class="vod-form-error" role="alert"></div>
            <form class="vod-form" id="vod-form" novalidate>
                <div class="form-group">
                    <label for="vod-business-name"><?= t('vr.label_business_name') ?></label>
                    <input
                        type="text"
                        id="vod-business-name"
                        name="business_name"
                        placeholder="<?= t('vr.placeholder_business_name') ?>"
                        autocomplete="off"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="vod-niche"><?= t('vr.label_industry') ?></label>
                    <select id="vod-niche" name="niche" required>
                        <option value="" disabled selected><?= t('vr.select_industry') ?></option>
                        <option value="pest_control"><?= t('vr.niche_pest_control') ?></option>
                        <option value="plumber"><?= t('vr.niche_plumber') ?></option>
                        <option value="dentist"><?= t('vr.niche_dentist') ?></option>
                        <option value="electrician"><?= t('vr.niche_electrician') ?></option>
                        <option value="roofer"><?= t('vr.niche_roofer') ?></option>
                        <option value="hvac"><?= t('vr.niche_hvac') ?></option>
                        <option value="real_estate"><?= t('vr.niche_real_estate') ?></option>
                        <option value="chiropractor"><?= t('vr.niche_chiropractor') ?></option>
                        <option value="personal_injury_lawyer"><?= t('vr.niche_personal_injury_lawyer') ?></option>
                        <option value="pool_installer"><?= t('vr.niche_pool_installer') ?></option>
                        <option value="dog_trainer"><?= t('vr.niche_dog_trainer') ?></option>
                        <option value="med_spa"><?= t('vr.niche_med_spa') ?></option>
                        <option value="house_cleaning"><?= t('vr.niche_house_cleaning') ?></option>
                        <option value="other"><?= t('vr.niche_other') ?></option>
                    </select>
                </div>

                <div class="form-group vod-form-other-niche" id="vod-other-niche-group">
                    <label for="vod-other-niche"><?= t('vr.label_other_niche') ?></label>
                    <input
                        type="text"
                        id="vod-other-niche"
                        name="other_niche"
                        placeholder="<?= t('vr.placeholder_other_niche') ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="vod-country"><?= t('vr.label_country') ?></label>
                    <?php
                    $countries = [
                        'AF'=>'Afghanistan','AL'=>'Albania','DZ'=>'Algeria','AR'=>'Argentina','AT'=>'Austria',
                        'AU'=>'Australia','BH'=>'Bahrain','BD'=>'Bangladesh','BE'=>'Belgium','BR'=>'Brazil',
                        'BG'=>'Bulgaria','KH'=>'Cambodia','CA'=>'Canada','CL'=>'Chile','CN'=>'China',
                        'CO'=>'Colombia','HR'=>'Croatia','CY'=>'Cyprus','CZ'=>'Czech Republic','DK'=>'Denmark',
                        'EG'=>'Egypt','EE'=>'Estonia','FI'=>'Finland','FR'=>'France','DE'=>'Germany',
                        'GH'=>'Ghana','GR'=>'Greece','HK'=>'Hong Kong','HU'=>'Hungary','IN'=>'India',
                        'ID'=>'Indonesia','IE'=>'Ireland','IL'=>'Israel','IT'=>'Italy','JP'=>'Japan',
                        'JO'=>'Jordan','KE'=>'Kenya','KW'=>'Kuwait','LV'=>'Latvia','LB'=>'Lebanon',
                        'LT'=>'Lithuania','LU'=>'Luxembourg','MY'=>'Malaysia','MT'=>'Malta','MX'=>'Mexico',
                        'MA'=>'Morocco','NL'=>'Netherlands','NZ'=>'New Zealand','NG'=>'Nigeria','NO'=>'Norway',
                        'OM'=>'Oman','PK'=>'Pakistan','PE'=>'Peru','PH'=>'Philippines','PL'=>'Poland',
                        'PT'=>'Portugal','QA'=>'Qatar','RO'=>'Romania','SA'=>'Saudi Arabia','RS'=>'Serbia',
                        'SG'=>'Singapore','SK'=>'Slovakia','SI'=>'Slovenia','ZA'=>'South Africa',
                        'KR'=>'South Korea','ES'=>'Spain','LK'=>'Sri Lanka','SE'=>'Sweden',
                        'CH'=>'Switzerland','TW'=>'Taiwan','TH'=>'Thailand','TR'=>'Turkey',
                        'UA'=>'Ukraine','AE'=>'United Arab Emirates','UK'=>'United Kingdom',
                        'US'=>'United States','VN'=>'Vietnam',
                    ];
                    $detected = in_array($countryCode, array_keys($countries), true) ? $countryCode : 'US';
                    if ($countryCode === 'GB') $detected = 'UK';
                    ?>
                    <select id="vod-country" name="country" required>
                        <?php foreach ($countries as $code => $name): ?>
                        <option value="<?= $code ?>"<?= $code === $detected ? ' selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="vod-form-btn" id="vod-form-btn"><?= t('vr.form_cta') ?></button>
            </form>

            <div class="vod-trust-badges">
                <span>&#10003; <?= t('vr.trust_free') ?></span>
                <span>&#10003; <?= t('vr.trust_one_video') ?></span>
                <span>&#10003; <?= t('vr.trust_yours_to_keep') ?></span>
            </div>
        </div>
    </section>

    <!-- Section 3: Email verification gate (hidden until form submit) -->
    <section class="vod-email-section" id="vod-email-section">
        <div class="vod-email-wrap">
            <h2><?= t('vr.email_title') ?></h2>
            <p><?= t('vr.email_desc') ?></p>
            <div id="vod-email-error" class="vod-email-error" role="alert"></div>
            <form class="vod-email-form" id="vod-email-form" novalidate>
                <label for="vod-email-input" class="sr-only"><?= t('vr.email_sr_label') ?></label>
                <input
                    type="email"
                    id="vod-email-input"
                    name="email"
                    placeholder="<?= t('vr.email_placeholder') ?>"
                    autocomplete="email"
                    required
                >
                <button type="submit" id="vod-email-btn"><?= t('vr.email_cta') ?></button>
            </form>
            <p class="vod-email-note"><?= t('vr.email_note') ?></p>
        </div>
    </section>

    <!-- Section 3b: Email sent — awaiting verification click (hidden until email submitted) -->
    <section class="vod-email-sent-section" id="vod-email-sent-section">
        <div class="vod-email-sent-wrap">
            <div class="vod-email-sent-icon" aria-hidden="true">&#9993;</div>
            <h2><?= t('vr.email_sent_title') ?></h2>
            <p id="vod-email-sent-desc"><?= t('vr.email_sent_desc', ['email' => '<span class="vod-email-sent-address" id="vod-email-sent-address"></span>']) ?></p>
            <p style="margin-top:12px;"><?= t('vr.email_sent_instruction') ?></p>
            <p class="vod-email-note" style="margin-top:20px;font-size:0.85rem;color:#718096;">
                <?= t('vr.email_sent_spam') ?>
            </p>
        </div>
    </section>

    <!-- Section 4: Post-verification confirmation (hidden until email verified) -->
    <section class="vod-confirmation-section" id="vod-confirmation-section">
        <div class="vod-confirmation-wrap">
            <div class="vod-confirmation-icon" aria-hidden="true">&#10003;</div>
            <h2 id="vod-confirmation-title"><?= t('vr.confirm_title') ?></h2>
            <p id="vod-confirmation-desc"><?= t('vr.confirm_desc') ?></p>
            <div class="vod-progress-bar">
                <div class="vod-progress-fill"></div>
            </div>
            <p class="vod-progress-label"><?= t('vr.confirm_progress') ?></p>
        </div>
    </section>

    <!-- Section 5: Pricing (always visible) -->
    <section class="vod-pricing-section" id="pricing">
        <h2><?= t('vr.pricing_title') ?></h2>
        <p class="vod-pricing-sub"><?= t('vr.pricing_subtitle') ?></p>

        <div class="vod-pricing-grid">
            <!-- Starter -->
            <div class="vod-pricing-card">
                <div class="vod-pricing-tier"><?= t('vr.pricing_tier_starter') ?></div>
                <div class="vod-pricing-price"><?= $symbol ?><?= $price4 ?></div>
                <div class="vod-pricing-period"><?= t('vr.pricing_per_month') ?></div>
                <div class="vod-pricing-setup"><?= t('vr.pricing_setup') ?></div>
                <ul class="vod-pricing-features">
                    <li><?= t('vr.pricing_videos_per_month', ['count' => '4']) ?></li>
                    <li><?= t('vr.pricing_30s_format') ?></li>
                    <li><?= t('vr.pricing_logo_branding') ?></li>
                    <li><?= t('vr.pricing_background_music') ?></li>
                    <li><?= t('vr.pricing_ai_voiceover') ?></li>
                </ul>
                <a href="#get-video" class="vod-pricing-cta"><?= t('vr.pricing_cta') ?></a>
            </div>

            <!-- Growth (featured) -->
            <div class="vod-pricing-card featured">
                <div class="vod-pricing-tier"><?= t('vr.pricing_tier_growth') ?></div>
                <div class="vod-pricing-price"><?= $symbol ?><?= $price8 ?></div>
                <div class="vod-pricing-period"><?= t('vr.pricing_per_month') ?></div>
                <div class="vod-pricing-setup"><?= t('vr.pricing_setup') ?></div>
                <ul class="vod-pricing-features">
                    <li><?= t('vr.pricing_videos_per_month', ['count' => '8']) ?></li>
                    <li><?= t('vr.pricing_30s_format') ?></li>
                    <li><?= t('vr.pricing_logo_branding') ?></li>
                    <li><?= t('vr.pricing_background_music') ?></li>
                    <li><?= t('vr.pricing_ai_voiceover') ?></li>
                    <li><?= t('vr.pricing_priority_delivery') ?></li>
                </ul>
                <a href="#get-video" class="vod-pricing-cta"><?= t('vr.pricing_cta') ?></a>
            </div>

            <!-- Scale -->
            <div class="vod-pricing-card">
                <div class="vod-pricing-tier"><?= t('vr.pricing_tier_scale') ?></div>
                <div class="vod-pricing-price"><?= $symbol ?><?= $price12 ?></div>
                <div class="vod-pricing-period"><?= t('vr.pricing_per_month') ?></div>
                <div class="vod-pricing-setup"><?= t('vr.pricing_setup') ?></div>
                <ul class="vod-pricing-features">
                    <li><?= t('vr.pricing_videos_per_month', ['count' => '12']) ?></li>
                    <li><?= t('vr.pricing_30s_format') ?></li>
                    <li><?= t('vr.pricing_logo_branding') ?></li>
                    <li><?= t('vr.pricing_background_music') ?></li>
                    <li><?= t('vr.pricing_ai_voiceover') ?></li>
                    <li><?= t('vr.pricing_priority_delivery') ?></li>
                </ul>
                <a href="#get-video" class="vod-pricing-cta"><?= t('vr.pricing_cta') ?></a>
            </div>
        </div>

        <p class="vod-pricing-compare"><?= t('vr.pricing_compare', ['symbol' => $competitorRange['symbol'], 'low' => $competitorRange['low'], 'symbol2' => $competitorRange['symbol'], 'high' => $competitorRange['high']]) ?> <a href="/video-reviews/compare"><?= t('vr.pricing_compare_link') ?></a></p>
    </section>

    <!-- FAQ -->
    <section class="vod-faq faq">
        <h2><?= t('vr.faq_title') ?></h2>

        <div class="faq-item">
            <h3><?= t('vr.faq1_q') ?></h3>
            <p><?= t('vr.faq1_a') ?></p>
        </div>
        <div class="faq-item">
            <h3><?= t('vr.faq2_q') ?></h3>
            <p><?= t('vr.faq2_a') ?></p>
        </div>
        <div class="faq-item">
            <h3><?= t('vr.faq3_q') ?></h3>
            <p><?= t('vr.faq3_a') ?></p>
        </div>
        <div class="faq-item">
            <h3><?= t('vr.faq4_q') ?></h3>
            <p><?= t('vr.faq4_a') ?></p>
        </div>
        <div class="faq-item">
            <h3><?= t('vr.faq5_q') ?></h3>
            <p><?= t('vr.faq5_a') ?></p>
        </div>
        <div class="faq-item">
            <h3><?= t('vr.faq6_q') ?></h3>
            <p><?= t('vr.faq6_a') ?></p>
        </div>
    </section>

    <!-- Footer CTA -->
    <section class="vod-footer-cta">
        <div class="container">
            <h2><?= t('vr.footer_cta_title') ?></h2>
            <a href="#get-video" class="cta-button"><?= t('vr.footer_cta_button') ?></a>
            <p><?= t('vr.footer_cta_sub') ?></p>
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
<?php if ($verifyDemoId && $verifyToken): ?>
    verifyDemoId: '<?= $verifyDemoId ?>',
    verifyToken:  '<?= $verifyToken ?>',
<?php endif; ?>
};
</script>
<?php
$googleMapsKey = getenv('GOOGLE_MAPS_API_KEY') ?: '';
if ($googleMapsKey):
?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($googleMapsKey) ?>&libraries=places&callback=initPlacesAutocomplete" async defer></script>
<?php endif; ?>
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

    // ── Lightbox ──────────────────────────────────────────────────────────
    var lightbox = document.createElement('div');
    lightbox.className = 'video-lightbox';
    lightbox.innerHTML = '<div class="video-lightbox__backdrop"></div>' +
        '<div class="video-lightbox__content">' +
        '<video class="video-lightbox__video" playsinline preload="auto"></video>' +
        '<button class="video-lightbox__close" aria-label="Close">&times;</button>' +
        '</div>';
    document.body.appendChild(lightbox);
    var lbVideo = lightbox.querySelector('.video-lightbox__video');
    var lbClose = lightbox.querySelector('.video-lightbox__close');
    var lbBackdrop = lightbox.querySelector('.video-lightbox__backdrop');
    var activeCardVideo = null;

    function openLightbox(cardVideo) {
        activeCardVideo = cardVideo;
        // Pause card video, transfer src and position to lightbox
        var wasPlaying = !cardVideo.paused;
        var pos = cardVideo.currentTime < 3 ? 0 : cardVideo.currentTime;
        cardVideo.pause();
        cardVideo.muted = true;
        lbVideo.src = cardVideo.querySelector('source').src;
        lbVideo.currentTime = pos;
        lbVideo.muted = false;
        lbVideo.loop = false;
        lightbox.classList.add('video-lightbox--open');
        document.body.style.overflow = 'hidden';
        // Play once ready
        function playLb() {
            lbVideo.play().catch(function() {});
        }
        if (lbVideo.readyState >= 3) {
            lbVideo.currentTime = pos;
            playLb();
        } else {
            lbVideo.addEventListener('loadeddata', function() {
                lbVideo.currentTime = pos;
                playLb();
            }, { once: true });
        }
    }

    function closeLightbox() {
        lbVideo.pause();
        lightbox.classList.remove('video-lightbox--open');
        document.body.style.overflow = '';
        // Resume position back on card but stay paused and muted
        if (activeCardVideo) {
            activeCardVideo.currentTime = lbVideo.currentTime;
            activeCardVideo.muted = true;
            var w = activeCardVideo.closest('.example-video-wrap');
            if (w) { w.classList.remove('playing'); w.__userPlaying = false; }
        }
        lbVideo.removeAttribute('src');
        activeCardVideo = null;
    }

    // Tap lightbox video to pause/resume
    lbVideo.addEventListener('click', function() {
        if (lbVideo.paused) { lbVideo.play().catch(function(){}); }
        else { lbVideo.pause(); }
    });
    lbVideo.addEventListener('ended', closeLightbox);
    lbClose.addEventListener('click', closeLightbox);
    lbBackdrop.addEventListener('click', closeLightbox);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && lightbox.classList.contains('video-lightbox--open')) closeLightbox();
    });

    // ── Card video interactions ─────────────────────────────────────────
    document.querySelectorAll('.example-video-wrap').forEach(function(wrap) {
        var video   = wrap.querySelector('video');
        var playBtn = wrap.querySelector('.example-play-btn');
        if (!video) return;

        var userPlaying = false;

        function userPlay() {
            // Pause any other card videos
            document.querySelectorAll('.example-video-wrap video').forEach(function(v) {
                if (v !== video && !v.paused) {
                    v.pause(); v.muted = true;
                    var w = v.closest('.example-video-wrap');
                    if (w) { w.classList.remove('playing'); w.__userPlaying = false; }
                }
            });
            video.loop = false;
            userPlaying = true;
            wrap.__userPlaying = true;
            function playUnmuted() {
                video.muted = false;
                video.play().then(function() {
                    wrap.classList.add('playing');
                    // Open lightbox after a brief moment so user sees the transition
                    setTimeout(function() { openLightbox(video); }, 300);
                }).catch(function() {});
            }
            if (video.readyState >= 3) { playUnmuted(); }
            else { video.addEventListener('canplay', playUnmuted, { once: true }); }
        }

        function userPause() {
            video.pause();
            // Don't reset position — resume where we left off
            userPlaying = false;
            wrap.__userPlaying = false;
            wrap.classList.remove('playing');
        }

        [playBtn, video].forEach(function(el) {
            if (!el) return;
            el.addEventListener('click', function(e) {
                e.stopPropagation();
                if (userPlaying) { userPause(); } else { userPlay(); }
            });
        });

        // Desktop hover preview (muted, no interference)
        wrap.addEventListener('mouseenter', function() {
            if (!userPlaying && video.paused) {
                video.muted = true;
                video.play().catch(function() {});
            }
        });
        wrap.addEventListener('mouseleave', function() {
            if (!userPlaying && !video.paused) {
                video.pause();
                video.currentTime = 0;
                wrap.classList.remove('playing');
            }
        });

        video.addEventListener('ended', function() {
            video.muted = true;
            video.loop = true;
            userPlaying = false;
            wrap.__userPlaying = false;
            wrap.classList.remove('playing');
        });
    });
})();
</script>

</body>
</html>
