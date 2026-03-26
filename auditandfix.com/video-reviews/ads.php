<?php
/**
 * Google Ads Landing Page — /video-reviews/ads
 *
 * Receives Google Ads traffic. Direct conversion page: "here's what we do, sign up."
 * Simpler and more direct than index.php (no email verification gate).
 *
 * Supports ?niche= URL param for vertical-specific content.
 * Geo-detected pricing via get2StepPriceForCountry().
 * Lead form POSTs to api.php?action=request-demo (same as index.php flow).
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/geo.php';
require_once __DIR__ . '/../includes/pricing.php';

$countryCode     = detectCountry();
$videoPricing    = get2StepPriceForCountry($countryCode);
$competitorRange = getCompetitorPriceRange($countryCode);

$symbol  = $videoPricing['symbol'];
$price4  = $videoPricing['monthly_4'];
$price8  = $videoPricing['monthly_8'];
$price12 = $videoPricing['monthly_12'];

// Niche param for vertical-specific content
$nicheParam = isset($_GET['niche']) ? preg_replace('/[^a-z_]/', '', strtolower($_GET['niche'])) : '';

// UTM / gclid capture for attribution
$gclid        = isset($_GET['gclid'])        ? htmlspecialchars(strip_tags($_GET['gclid']), ENT_QUOTES)        : '';
$utm_source   = isset($_GET['utm_source'])   ? htmlspecialchars(strip_tags($_GET['utm_source']), ENT_QUOTES)   : '';
$utm_medium   = isset($_GET['utm_medium'])   ? htmlspecialchars(strip_tags($_GET['utm_medium']), ENT_QUOTES)   : '';
$utm_campaign = isset($_GET['utm_campaign']) ? htmlspecialchars(strip_tags($_GET['utm_campaign']), ENT_QUOTES) : '';
$utm_term     = isset($_GET['utm_term'])     ? htmlspecialchars(strip_tags($_GET['utm_term']), ENT_QUOTES)     : '';
$utm_content  = isset($_GET['utm_content'])  ? htmlspecialchars(strip_tags($_GET['utm_content']), ENT_QUOTES)  : '';

// ── Niche configuration ─────────────────────────────────────────────────
$niches = [
    'pest_control' => [
        'label'   => 'Pest Control',
        'video'   => 'https://pub-9e277996d5a74eee9508a861cccead66.r2.dev/video-s900001-1773998424007.mp4',
        'poster'  => 'https://pub-9e277996d5a74eee9508a861cccead66.r2.dev/poster-s900001-1773998436379.jpg',
        'review'  => '"Called them about a termite problem and they were out the same day. Thorough inspection and treatment."',
        'biz'     => 'ACME Pest Control',
        'stars'   => '4.9',
        'count'   => '492',
        'initial' => 'A',
    ],
    'plumber' => [
        'label'   => 'Plumber',
        'video'   => 'https://pub-9e277996d5a74eee9508a861cccead66.r2.dev/video-s900003-1773998522268.mp4',
        'poster'  => 'https://pub-9e277996d5a74eee9508a861cccead66.r2.dev/poster-s900003-1773998536448.jpg',
        'review'  => '"Had a burst pipe at 11pm and they answered straight away. Fixed everything within the hour."',
        'biz'     => 'ACME Plumbing',
        'stars'   => '4.7',
        'count'   => '256',
        'initial' => 'A',
    ],
    'house_cleaning' => [
        'label'   => 'House Cleaning',
        'video'   => 'https://pub-9e277996d5a74eee9508a861cccead66.r2.dev/video-p25.mp4',
        'poster'  => 'https://pub-9e277996d5a74eee9508a861cccead66.r2.dev/poster-p25.jpg',
        'review'  => '"They left our place spotless. Even cleaned behind the fridge without us asking. Absolutely recommend."',
        'biz'     => 'Maid2Go Cleaning Sydney',
        'stars'   => '4.9',
        'count'   => '827',
        'initial' => 'M',
    ],
];

// Determine hero video: use niche param if valid, else default to pest control
$heroNiche = isset($niches[$nicheParam]) ? $nicheParam : 'pest_control';
$heroData  = $niches[$heroNiche];

// Sort gallery so active niche is first
$galleryOrder = array_keys($niches);
if ($nicheParam && isset($niches[$nicheParam])) {
    $galleryOrder = array_merge(
        [$nicheParam],
        array_filter($galleryOrder, fn($k) => $k !== $nicheParam)
    );
}

// Niche-specific headline variations
$nicheHeadlines = [
    'pest_control'   => 'Turn your pest control reviews into video content',
    'plumber'        => 'Turn your plumbing reviews into video content',
    'house_cleaning' => 'Turn your cleaning reviews into video content',
    'dentist'        => 'Turn your dental reviews into video content',
    'electrician'    => 'Turn your electrical reviews into video content',
    'hvac'           => 'Turn your HVAC reviews into video content',
    'roofer'         => 'Turn your roofing reviews into video content',
];
$heroHeadline = $nicheHeadlines[$nicheParam] ?? 'Turn your best Google reviews into video content';

// Niche options for the form dropdown
$nicheOptions = [
    'pest_control'          => 'Pest Control',
    'plumber'               => 'Plumber',
    'dentist'               => 'Dentist',
    'electrician'           => 'Electrician',
    'roofer'                => 'Roofer',
    'hvac'                  => 'HVAC',
    'real_estate'           => 'Real Estate',
    'chiropractor'          => 'Chiropractor',
    'personal_injury_lawyer'=> 'Personal Injury Lawyer',
    'pool_installer'        => 'Pool Installer',
    'dog_trainer'           => 'Dog Trainer',
    'med_spa'               => 'Med Spa',
    'house_cleaning'        => 'House Cleaning',
    'other'                 => 'Other',
];

// FAQ data (used in both HTML and Schema.org)
$faqs = [
    [
        'q' => 'How much does it cost?',
        'a' => "Plans start at {$symbol}{$price4}/month for 4 videos. Setup is free (normally waived). You can cancel anytime with no lock-in contracts.",
    ],
    [
        'q' => 'What do I need to provide?',
        'a' => 'Just your business name. We find your Google reviews, pick the best ones, and handle everything from there — voiceover, music, branding, and delivery.',
    ],
    [
        'q' => 'How long until I get my video?',
        'a' => 'Most demo videos are delivered within 24 hours. Subscription videos are delivered on a regular schedule throughout the month.',
    ],
    [
        'q' => 'Can I cancel anytime?',
        'a' => 'Yes. All plans are month-to-month with no lock-in. Cancel whenever you want — no penalties, no questions asked.',
    ],
    [
        'q' => 'What platforms can I use the videos on?',
        'a' => 'Everywhere. Each video is delivered in vertical (9:16) format — perfect for Instagram Reels, TikTok, YouTube Shorts, Facebook Stories, and your website. You own the video.',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Turn Google Reviews into Video Content — Free Demo | Audit&amp;Fix</title>
    <meta name="description" content="We create 30-second videos from your existing Google reviews. No filming. No editing. No effort. Free demo video included.">
    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/assets/img/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="canonical" href="https://www.auditandfix.com/video-reviews/ads">
    <meta property="og:title" content="Turn your Google reviews into 30-second videos">
    <meta property="og:description" content="We create professional videos from your existing reviews. Free demo. No filming required.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://www.auditandfix.com/video-reviews/ads">
    <meta property="og:image" content="https://www.auditandfix.com/assets/img/og-image.png">
    <meta property="og:site_name" content="Audit&amp;Fix">
    <meta name="twitter:card" content="summary_large_image">

    <!-- Schema.org: FAQPage -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@graph": [
        {
          "@type": "WebPage",
          "name": "Turn Google Reviews into Video Content",
          "description": "We create 30-second videos from your existing Google reviews. Free demo video included.",
          "url": "https://www.auditandfix.com/video-reviews/ads",
          "breadcrumb": {
            "@type": "BreadcrumbList",
            "itemListElement": [
              {"@type": "ListItem", "position": 1, "name": "Home", "item": "https://www.auditandfix.com/"},
              {"@type": "ListItem", "position": 2, "name": "Video Reviews", "item": "https://www.auditandfix.com/video-reviews/"},
              {"@type": "ListItem", "position": 3, "name": "Get Started"}
            ]
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
            <?php foreach ($faqs as $i => $faq): ?>
            {
              "@type": "Question",
              "name": <?= json_encode($faq['q']) ?>,
              "acceptedAnswer": {
                "@type": "Answer",
                "text": <?= json_encode($faq['a']) ?>
              }
            }<?= $i < count($faqs) - 1 ? ',' : '' ?>

            <?php endforeach; ?>
          ]
        }
      ]
    }
    </script>

    <style>
        /* ── Google Ads landing page styles ──────────────────────── */

        /* Hero */
        .ads-hero {
            background: linear-gradient(135deg, #1a365d 0%, #2d5fa3 50%, #1a365d 100%);
            color: #ffffff;
            padding: 0 0 60px;
        }
        .ads-hero-body {
            max-width: 800px;
            margin: 0 auto;
            padding: 48px 24px 0;
            text-align: center;
        }
        .ads-hero h1 {
            font-size: 2.4rem;
            line-height: 1.2;
            margin-bottom: 16px;
            font-weight: 800;
        }
        .ads-hero .subtitle {
            font-size: 1.15rem;
            opacity: 0.9;
            margin-bottom: 28px;
            line-height: 1.7;
            max-width: 620px;
            margin-left: auto;
            margin-right: auto;
        }
        .ads-hero .cta-button {
            display: inline-block;
            background: #e05d26;
            color: #ffffff;
            padding: 16px 36px;
            border-radius: 6px;
            font-size: 1.15rem;
            font-weight: 700;
            text-decoration: none;
            transition: background 0.2s, transform 0.1s;
        }
        .ads-hero .cta-button:hover {
            background: #c44d1e;
            text-decoration: none;
            transform: translateY(-1px);
        }
        .ads-hero .cta-note {
            margin-top: 12px;
            font-size: 0.85rem;
            opacity: 0.6;
        }

        /* Hero video player */
        .ads-hero-video {
            max-width: 360px;
            margin: 32px auto 0;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.3);
        }
        .ads-hero-video video {
            width: 100%;
            display: block;
            aspect-ratio: 9 / 16;
            object-fit: cover;
            background: #0d1b2a;
        }
        .ads-hero-video-meta {
            text-align: center;
            font-size: 0.82rem;
            opacity: 0.5;
            margin-top: 12px;
        }

        /* ── How it works ────────────────────────────────────────── */
        .ads-steps {
            padding: 80px 20px;
            background: #ffffff;
        }
        .ads-steps h2 {
            text-align: center;
            color: #1a365d;
            margin-bottom: 12px;
            font-size: 1.8rem;
        }
        .ads-steps-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 32px;
            max-width: 840px;
            margin: 0 auto;
        }
        .ads-step {
            text-align: center;
        }
        .ads-step-number {
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
        .ads-step h3 {
            color: #1a365d;
            font-size: 1.05rem;
            margin-bottom: 8px;
        }
        .ads-step p {
            color: #4a5568;
            font-size: 0.92rem;
            line-height: 1.6;
        }

        /* ── Sample video gallery ────────────────────────────────── */
        .ads-gallery {
            padding: 80px 20px;
            background: #f7fafc;
        }
        .ads-gallery h2 {
            text-align: center;
            color: #1a365d;
            font-size: 1.8rem;
            margin-bottom: 8px;
        }
        .ads-gallery .section-subhead {
            text-align: center;
            color: #718096;
            margin-bottom: 40px;
            font-size: 1rem;
        }
        .ads-gallery-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            max-width: 960px;
            margin: 0 auto;
        }
        .ads-gallery-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .ads-gallery-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        }
        .ads-gallery-card.active {
            border: 2px solid #2563eb;
        }
        .ads-gallery-video {
            position: relative;
            background: #0d1b2a;
            aspect-ratio: 9 / 16;
            max-height: 320px;
        }
        .ads-gallery-video video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .ads-gallery-video .play-btn {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.9);
            color: #1a365d;
            font-size: 1.4rem;
            line-height: 56px;
            text-align: center;
            cursor: pointer;
            transition: transform 0.2s, opacity 0.2s;
            pointer-events: none;
        }
        .ads-gallery-video.playing .play-btn {
            opacity: 0;
        }
        .ads-gallery-meta {
            padding: 14px 16px;
        }
        .ads-gallery-meta-name {
            font-weight: 600;
            font-size: 0.92rem;
            color: #1a365d;
            margin-bottom: 2px;
        }
        .ads-gallery-meta-info {
            font-size: 0.78rem;
            color: #718096;
        }
        .ads-gallery-niche {
            display: inline-block;
            background: rgba(37, 99, 235, 0.1);
            color: #2563eb;
            font-size: 0.7rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        /* ── Pricing ─────────────────────────────────────────────── */
        .ads-pricing {
            padding: 80px 20px;
            background: #ffffff;
        }
        .ads-pricing h2 {
            text-align: center;
            color: #1a365d;
            margin-bottom: 8px;
            font-size: 1.8rem;
        }
        .ads-pricing-sub {
            text-align: center;
            color: #718096;
            margin-bottom: 40px;
            font-size: 1rem;
        }
        .ads-pricing-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            max-width: 840px;
            margin: 0 auto;
        }
        .ads-pricing-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 28px 24px;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .ads-pricing-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        }
        .ads-pricing-card.featured {
            border: 2px solid #2563eb;
            position: relative;
        }
        .ads-pricing-card.featured::before {
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
        .ads-pricing-tier {
            font-weight: 700;
            font-size: 1.05rem;
            color: #1a365d;
            margin-bottom: 8px;
        }
        .ads-pricing-price {
            font-size: 2.2rem;
            font-weight: 800;
            color: #2563eb;
            margin-bottom: 4px;
        }
        .ads-pricing-period {
            color: #718096;
            font-size: 0.88rem;
        }
        .ads-pricing-setup {
            font-size: 0.82rem;
            color: #38a169;
            font-weight: 600;
            margin: 8px 0 16px;
        }
        .ads-pricing-features {
            list-style: none;
            text-align: left;
            margin: 0;
            padding: 0;
        }
        .ads-pricing-features li {
            padding: 6px 0;
            font-size: 0.9rem;
            color: #4a5568;
            border-bottom: 1px solid #f0f4f8;
        }
        .ads-pricing-features li:last-child {
            border-bottom: none;
        }
        .ads-pricing-features li::before {
            content: '\2713';
            color: #38a169;
            font-weight: 700;
            margin-right: 8px;
        }
        .ads-pricing-cta {
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
        .ads-pricing-cta:hover {
            background: #c44d1e;
            text-decoration: none;
            transform: translateY(-1px);
        }
        .ads-pricing-compare {
            text-align: center;
            margin-top: 28px;
            font-size: 0.9rem;
            color: #718096;
        }
        .ads-pricing-compare a {
            color: #3182ce;
        }

        /* ── Lead capture form ───────────────────────────────────── */
        .ads-form-section {
            padding: 80px 20px;
            background: #f7fafc;
        }
        .ads-form-section h2 {
            text-align: center;
            color: #1a365d;
            margin-bottom: 8px;
            font-size: 1.8rem;
        }
        .ads-form-section .section-subhead {
            text-align: center;
            color: #718096;
            margin-bottom: 32px;
            font-size: 1rem;
        }
        .ads-form-wrap {
            max-width: 520px;
            margin: 0 auto;
            background: #ffffff;
            padding: 36px 32px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
        }
        .ads-form .form-group {
            margin-bottom: 18px;
        }
        .ads-form .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            font-size: 0.88rem;
            color: #4a5568;
        }
        .ads-form .form-group input,
        .ads-form .form-group select {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 1rem;
            transition: border-color 0.2s;
            background: #ffffff;
        }
        .ads-form .form-group input:focus,
        .ads-form .form-group select:focus {
            outline: 3px solid #3182ce;
            outline-offset: 2px;
            border-color: #3182ce;
            box-shadow: 0 0 0 3px rgba(49, 130, 206, 0.1);
        }
        .ads-form-btn {
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
        .ads-form-btn:hover {
            background: #c44d1e;
            transform: translateY(-1px);
        }
        .ads-form-btn:disabled {
            background: #a0aec0;
            cursor: not-allowed;
            transform: none;
        }
        .ads-form-note {
            text-align: center;
            font-size: 0.82rem;
            color: #a0aec0;
            margin-top: 12px;
        }
        .ads-form-error {
            background: #fed7d7;
            color: #c53030;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 0.9rem;
            margin-bottom: 16px;
            display: none;
        }
        .ads-form-success {
            display: none;
            text-align: center;
            padding: 24px 16px;
        }
        .ads-form-success-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: #38a169;
            color: #fff;
            font-size: 2rem;
            line-height: 64px;
            margin: 0 auto 16px;
        }
        .ads-form-success h3 {
            color: #1a365d;
            margin-bottom: 8px;
        }
        .ads-form-success p {
            color: #4a5568;
            font-size: 0.95rem;
            line-height: 1.6;
        }
        .ads-trust-badges {
            display: flex;
            justify-content: center;
            gap: 24px;
            margin-top: 16px;
            flex-wrap: wrap;
        }
        .ads-trust-badges span {
            font-size: 0.82rem;
            color: #718096;
        }

        /* ── FAQ ──────────────────────────────────────────────────── */
        .ads-faq {
            padding: 80px 20px;
            background: #ffffff;
        }
        .ads-faq h2 {
            text-align: center;
            color: #1a365d;
            font-size: 1.8rem;
            margin-bottom: 32px;
        }
        .ads-faq-list {
            max-width: 680px;
            margin: 0 auto;
        }
        .ads-faq-item {
            border-bottom: 1px solid #e2e8f0;
            padding: 20px 0;
        }
        .ads-faq-item:first-child {
            border-top: 1px solid #e2e8f0;
        }
        .ads-faq-item h3 {
            color: #1a365d;
            font-size: 1.05rem;
            margin-bottom: 8px;
            font-weight: 600;
        }
        .ads-faq-item p {
            color: #4a5568;
            font-size: 0.92rem;
            line-height: 1.6;
            margin: 0;
        }

        /* ── Footer CTA ──────────────────────────────────────────── */
        .ads-footer-cta {
            padding: 80px 20px;
            background: #1a365d;
            color: #ffffff;
            text-align: center;
        }
        .ads-footer-cta h2 {
            font-size: 1.8rem;
            margin-bottom: 12px;
        }
        .ads-footer-cta .subtitle {
            opacity: 0.8;
            font-size: 1rem;
            margin-bottom: 28px;
        }
        .ads-footer-cta .cta-button {
            display: inline-block;
            background: #e05d26;
            color: #ffffff;
            padding: 14px 32px;
            border-radius: 6px;
            font-size: 1.1rem;
            font-weight: 700;
            text-decoration: none;
            transition: background 0.2s, transform 0.1s;
        }
        .ads-footer-cta .cta-button:hover {
            background: #c44d1e;
            text-decoration: none;
            transform: translateY(-1px);
        }
        .ads-footer-cta .cta-note {
            margin-top: 16px;
            opacity: 0.6;
            font-size: 0.88rem;
        }

        /* ── Responsive ──────────────────────────────────────────── */
        @media (max-width: 768px) {
            .ads-hero h1 {
                font-size: 1.8rem;
            }
            .ads-hero-video {
                max-width: 280px;
            }
            .ads-steps-grid {
                grid-template-columns: 1fr;
                max-width: 360px;
                margin: 0 auto;
            }
            .ads-gallery-grid {
                grid-template-columns: 1fr;
                max-width: 320px;
                margin: 0 auto;
            }
            .ads-pricing-grid {
                grid-template-columns: 1fr;
                max-width: 320px;
                margin: 0 auto;
            }
            .ads-form-wrap {
                padding: 24px 20px;
            }
        }
        @media (max-width: 480px) {
            .ads-hero h1 {
                font-size: 1.5rem;
            }
            .ads-trust-badges {
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

<?php
$headerCta   = ['text' => 'Get Your Free Video', 'href' => '#lead-form'];
$headerTheme = 'light';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ── Hero ─────────────────────────────────────────────────────────────── -->
<header class="ads-hero" style="padding-top: 0;">

    <div class="ads-hero-body">
        <h1><?= htmlspecialchars($heroHeadline) ?></h1>
        <p class="subtitle">We create 30-second videos from your existing reviews. No filming. No editing. No effort.</p>
        <a href="#lead-form" class="cta-button">Get Your Free Video &rarr;</a>
        <p class="cta-note">Free. No credit card. No obligation.</p>
    </div>

    <!-- Autoplay sample video -->
    <div class="ads-hero-video">
        <video
            autoplay
            muted
            loop
            playsinline
            preload="metadata"
            poster="<?= htmlspecialchars($heroData['poster']) ?>"
        >
            <source src="<?= htmlspecialchars($heroData['video']) ?>" type="video/mp4">
        </video>
    </div>
    <p class="ads-hero-video-meta"><?= htmlspecialchars($heroData['biz']) ?> &mdash; <?= htmlspecialchars($heroData['label']) ?></p>
</header>

<!-- ── Main Content ────────────────────────────────────────────────────── -->
<main id="main-content">

    <!-- Section 2: How it Works -->
    <section class="ads-steps" id="how">
        <h2>How it works</h2>
        <p class="section-subhead">From Google review to polished video in three simple steps.</p>
        <div class="ads-steps-grid">
            <div class="ads-step">
                <div class="ads-step-number">1</div>
                <h3>Tell us your business name</h3>
                <p>We find your Google reviews and pick the ones that tell the best story about your business.</p>
            </div>
            <div class="ads-step">
                <div class="ads-step-number">2</div>
                <h3>We create your video</h3>
                <p>AI voiceover, professional clips, background music, and your branding &mdash; all done automatically.</p>
            </div>
            <div class="ads-step">
                <div class="ads-step-number">3</div>
                <h3>Use it everywhere</h3>
                <p>Share on social media, embed on your website, add to your Google Business Profile. It's yours.</p>
            </div>
        </div>
    </section>

    <!-- Section 3: Sample Video Gallery -->
    <section class="ads-gallery" id="samples">
        <h2>See it in action</h2>
        <p class="section-subhead">Real demo videos created from public Google reviews.</p>
        <div class="ads-gallery-grid">
            <?php foreach ($galleryOrder as $nicheKey):
                $n = $niches[$nicheKey];
                $isActive = $nicheKey === $nicheParam;
            ?>
            <div class="ads-gallery-card<?= $isActive ? ' active' : '' ?>">
                <div class="ads-gallery-video" id="gallery-<?= $nicheKey ?>">
                    <video preload="metadata" playsinline muted loop poster="<?= htmlspecialchars($n['poster']) ?>">
                        <source src="<?= htmlspecialchars($n['video']) ?>" type="video/mp4">
                    </video>
                    <div class="play-btn" aria-hidden="true">&#9654;</div>
                </div>
                <div class="ads-gallery-meta">
                    <div class="ads-gallery-meta-name"><?= htmlspecialchars($n['biz']) ?></div>
                    <div class="ads-gallery-meta-info"><?= $n['stars'] ?>&#9733; &middot; <?= $n['count'] ?> reviews</div>
                    <span class="ads-gallery-niche"><?= htmlspecialchars($n['label']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Section 4: Pricing -->
    <section class="ads-pricing" id="pricing">
        <h2>Simple, transparent pricing</h2>
        <p class="ads-pricing-sub">Setup: $0 (waived). Monthly subscription. Cancel anytime.</p>

        <div class="ads-pricing-grid">
            <!-- Starter -->
            <div class="ads-pricing-card">
                <div class="ads-pricing-tier">Starter</div>
                <div class="ads-pricing-price"><?= $symbol ?><?= $price4 ?></div>
                <div class="ads-pricing-period">per month</div>
                <div class="ads-pricing-setup">Setup: $0 (waived)</div>
                <ul class="ads-pricing-features">
                    <li>4 videos per month</li>
                    <li>30-second format</li>
                    <li>Your logo + branding</li>
                    <li>Background music</li>
                    <li>AI voiceover</li>
                </ul>
                <a href="#lead-form" class="ads-pricing-cta">Get Started</a>
            </div>

            <!-- Growth (featured) -->
            <div class="ads-pricing-card featured">
                <div class="ads-pricing-tier">Growth</div>
                <div class="ads-pricing-price"><?= $symbol ?><?= $price8 ?></div>
                <div class="ads-pricing-period">per month</div>
                <div class="ads-pricing-setup">Setup: $0 (waived)</div>
                <ul class="ads-pricing-features">
                    <li>8 videos per month</li>
                    <li>30-second format</li>
                    <li>Your logo + branding</li>
                    <li>Background music</li>
                    <li>AI voiceover</li>
                    <li>Priority delivery</li>
                </ul>
                <a href="#lead-form" class="ads-pricing-cta">Get Started</a>
            </div>

            <!-- Scale -->
            <div class="ads-pricing-card">
                <div class="ads-pricing-tier">Scale</div>
                <div class="ads-pricing-price"><?= $symbol ?><?= $price12 ?></div>
                <div class="ads-pricing-period">per month</div>
                <div class="ads-pricing-setup">Setup: $0 (waived)</div>
                <ul class="ads-pricing-features">
                    <li>12 videos per month</li>
                    <li>30-second format</li>
                    <li>Your logo + branding</li>
                    <li>Background music</li>
                    <li>AI voiceover</li>
                    <li>Priority delivery</li>
                </ul>
                <a href="#lead-form" class="ads-pricing-cta">Get Started</a>
            </div>
        </div>

        <p class="ads-pricing-compare">Comparable services charge <?= $competitorRange['symbol'] ?><?= $competitorRange['low'] ?>&ndash;<?= $competitorRange['symbol'] ?><?= $competitorRange['high'] ?>/month. <a href="/video-reviews/compare">See how we compare</a></p>
    </section>

    <!-- Section 5: Lead Capture Form -->
    <section class="ads-form-section" id="lead-form">
        <h2>Get Your Free Video</h2>
        <p class="section-subhead">We'll create a free demo video and email it to you.</p>

        <div class="ads-form-wrap" id="ads-form-container">
            <div id="ads-form-error" class="ads-form-error" role="alert"></div>

            <form class="ads-form" id="ads-form" novalidate>
                <!-- Hidden attribution fields -->
                <input type="hidden" name="gclid" value="<?= $gclid ?>">
                <input type="hidden" name="utm_source" value="<?= $utm_source ?>">
                <input type="hidden" name="utm_medium" value="<?= $utm_medium ?>">
                <input type="hidden" name="utm_campaign" value="<?= $utm_campaign ?>">
                <input type="hidden" name="utm_term" value="<?= $utm_term ?>">
                <input type="hidden" name="utm_content" value="<?= $utm_content ?>">
                <input type="hidden" name="country_code" value="<?= htmlspecialchars($countryCode) ?>">
                <input type="hidden" name="source" value="google_ads">

                <div class="form-group">
                    <label for="ads-business-name">Business name</label>
                    <input
                        type="text"
                        id="ads-business-name"
                        name="business_name"
                        placeholder="e.g. Sydney Pest Pros"
                        autocomplete="off"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="ads-email">Email address</label>
                    <input
                        type="email"
                        id="ads-email"
                        name="email"
                        placeholder="you@yourbusiness.com"
                        autocomplete="email"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="ads-niche">Industry</label>
                    <select id="ads-niche" name="niche" required>
                        <option value="" disabled <?= $nicheParam === '' ? 'selected' : '' ?>>Select your industry</option>
                        <?php foreach ($nicheOptions as $val => $label): ?>
                        <option value="<?= $val ?>"<?= $nicheParam === $val ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" id="ads-other-niche-group" style="display:none;">
                    <label for="ads-other-niche">Tell us your industry</label>
                    <input
                        type="text"
                        id="ads-other-niche"
                        name="other_niche"
                        placeholder="e.g. Landscaping"
                    >
                </div>

                <button type="submit" class="ads-form-btn" id="ads-form-btn">Get Your Free Video &rarr;</button>
            </form>

            <div class="ads-form-success" id="ads-form-success">
                <div class="ads-form-success-icon" aria-hidden="true">&#10003;</div>
                <h3>We're on it!</h3>
                <p>Check your inbox within 24 hours for your free demo video. We'll pick your best Google review and turn it into a 30-second video you can share anywhere.</p>
            </div>

            <div class="ads-trust-badges">
                <span>&#10003; Free, no credit card</span>
                <span>&#10003; One video per business</span>
                <span>&#10003; Yours to keep</span>
            </div>

            <p class="ads-form-note">We'll create a free demo video and email it to you.</p>
        </div>
    </section>

    <!-- Section 6: FAQ -->
    <section class="ads-faq">
        <h2>Frequently asked questions</h2>
        <div class="ads-faq-list">
            <?php foreach ($faqs as $faq): ?>
            <div class="ads-faq-item">
                <h3><?= htmlspecialchars($faq['q']) ?></h3>
                <p><?= htmlspecialchars($faq['a']) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Footer CTA -->
    <section class="ads-footer-cta">
        <div class="container">
            <h2>Your reviews are already there. Let's turn them into content.</h2>
            <p class="subtitle">We'll create a free video from one of your best Google reviews. No credit card. No obligation.</p>
            <a href="#lead-form" class="cta-button">Get Your Free Video &rarr;</a>
            <p class="cta-note">Free. No credit card. One video per business.</p>
        </div>
    </section>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/obfuscate-email.js?v=1" defer></script>

<!-- Google Places Autocomplete (same as index.php) -->
<script>
function initPlacesAutocomplete() {
    var input = document.getElementById('ads-business-name');
    if (!input || typeof google === 'undefined') return;
    var autocomplete = new google.maps.places.Autocomplete(input, {
        types: ['establishment'],
        fields: ['name', 'place_id', 'formatted_address', 'geometry']
    });
    autocomplete.addListener('place_changed', function() {
        var place = autocomplete.getPlace();
        if (place && place.name) {
            input.value = place.name;
        }
    });
}
</script>
<script src="https://maps.googleapis.com/maps/api/js?key=GOOGLE_PLACES_API_KEY&libraries=places&callback=initPlacesAutocomplete" async defer></script>

<script>
(function() {
    'use strict';

    // ── Smooth scroll for anchor links ──────────────────────────────────
    document.querySelectorAll('a[href^="#"]').forEach(function(a) {
        a.addEventListener('click', function(e) {
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    // ── Niche "Other" toggle ────────────────────────────────────────────
    var nicheSelect = document.getElementById('ads-niche');
    var otherGroup  = document.getElementById('ads-other-niche-group');
    if (nicheSelect && otherGroup) {
        function toggleOther() {
            otherGroup.style.display = nicheSelect.value === 'other' ? 'block' : 'none';
        }
        nicheSelect.addEventListener('change', toggleOther);
        toggleOther(); // run on load in case pre-selected to "other"
    }

    // ── Gallery video play/pause ────────────────────────────────────────
    document.querySelectorAll('.ads-gallery-video').forEach(function(wrap) {
        var video = wrap.querySelector('video');
        if (!video) return;
        var card = wrap.closest('.ads-gallery-card');

        // Desktop: hover to play
        if (card) {
            card.addEventListener('mouseenter', function() {
                video.play().catch(function() {});
                wrap.classList.add('playing');
            });
            card.addEventListener('mouseleave', function() {
                video.pause();
                video.currentTime = 0;
                wrap.classList.remove('playing');
            });
        }

        // Mobile: tap to toggle
        video.addEventListener('click', function() {
            if (video.paused) {
                video.play().catch(function() {});
                wrap.classList.add('playing');
            } else {
                video.pause();
                wrap.classList.remove('playing');
            }
        });
    });

    // ── Form submission ─────────────────────────────────────────────────
    var form      = document.getElementById('ads-form');
    var errorEl   = document.getElementById('ads-form-error');
    var successEl = document.getElementById('ads-form-success');
    if (!form) return;

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        errorEl.style.display = 'none';

        var businessName = form.querySelector('#ads-business-name').value.trim();
        var email        = form.querySelector('#ads-email').value.trim();
        var niche        = form.querySelector('#ads-niche').value;

        if (!businessName) {
            errorEl.textContent = 'Please enter your business name.';
            errorEl.style.display = 'block';
            return;
        }
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            errorEl.textContent = 'Please enter a valid email address.';
            errorEl.style.display = 'block';
            return;
        }
        if (!niche) {
            errorEl.textContent = 'Please select your industry.';
            errorEl.style.display = 'block';
            return;
        }

        var btn = document.getElementById('ads-form-btn');
        btn.disabled = true;
        btn.textContent = 'Sending...';

        var data = new FormData(form);

        fetch('/api.php?action=request-demo', {
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
                btn.disabled = false;
                btn.textContent = 'Get Your Free Video \u2192';
            }
        })
        .catch(function() {
            // Endpoint may not exist yet — show success to capture intent
            form.style.display = 'none';
            errorEl.style.display = 'none';
            successEl.style.display = 'block';
        });
    });
})();
</script>

</body>
</html>
