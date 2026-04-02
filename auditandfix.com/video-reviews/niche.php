<?php
/**
 * Video-on-Demand — Niche-specific landing page template.
 *
 * Handles all verticals via ?niche= URL parameter.
 * .htaccess rewrites /video-reviews/{slug} → niche.php?niche={slug}
 *
 * Supported niches: pest-control, plumber, house-cleaning
 * Unknown niches fall back to 404 / redirect to /video-reviews/
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/geo.php';
require_once __DIR__ . '/../includes/pricing.php';

// ── Niche config ──────────────────────────────────────────────────────────────
$niches = [
    'pest-control' => [
        'slug'           => 'pest-control',
        'display'        => 'Pest Control',
        'niche_value'    => 'pest_control',
        'title'          => 'Turn Your Pest Control Reviews into 30-Second Videos',
        'meta_desc'      => 'Get a free 30-second video made from your best Google review — automatically. Perfect for pest control businesses. No filming required.',
        'hero_pre'       => 'Free video for pest control businesses',
        'hero_h1'        => 'Your customers\' reviews. Turned into video.',
        'hero_sub'       => 'When someone Googles "pest control near me", they look at 3 businesses. The one with a video review gets the call. We make that video for you — free.',
        'hook'           => 'When someone Googles "pest control near me", they\'re looking at 3 businesses. The one with a video review gets the call.',
        'r2_video'       => 'https://cdn.auditandfix.com/video-s3-1774578488894.mp4',
        'r2_poster'      => 'https://cdn.auditandfix.com/poster-s3-1774578500984.jpg',
        'biz_name'       => 'BugFree Pest Control',
        'biz_rating'     => '4.9',
        'biz_reviews'    => '5,103',
        'review_snippet' => '"Reece and the team were knowledgeable, responsive, and easy to book. Despite difficult to access to attic space, two possums were safely trapped and released over four days. …"',
        'faqs'           => [
            ['q' => 'How do you get the review text?', 'a' => 'We pull it directly from your Google Business Profile — your customer\'s actual words, verbatim. No editing, no AI-written copy.'],
            ['q' => 'Will it work for my pest control niche?', 'a' => 'Yes — we have clips for general pest control, cockroaches, termites, spiders, and rodents. The video is matched to your most common job type.'],
            ['q' => 'Can I use it in Google Ads?', 'a' => 'Absolutely. 30-second vertical video is the most effective format for local service ads. Many of our pest control customers run their free demo video in ads before subscribing.'],
        ],
    ],
    'plumber' => [
        'slug'           => 'plumber',
        'display'        => 'Plumber',
        'niche_value'    => 'plumber',
        'title'          => 'Turn Your Plumbing Reviews into 30-Second Videos',
        'meta_desc'      => 'Get a free 30-second video made from your best Google review — automatically. Perfect for plumbers and plumbing businesses. No filming required.',
        'hero_pre'       => 'Free video for plumbers',
        'hero_h1'        => 'Your customers\' reviews. Turned into video.',
        'hero_sub'       => 'Video reviews are referrals that work 24 hours a day. We turn your best Google review into a 30-second video — automatically.',
        'hook'           => 'Video reviews are referrals that work 24 hours a day.',
        'r2_video'       => 'https://cdn.auditandfix.com/video-s19-1774577432668.mp4',
        'r2_poster'      => 'https://cdn.auditandfix.com/poster-s19-1774577448578.jpg',
        'biz_name'       => 'Fix n Flow',
        'biz_rating'     => '4.8',
        'biz_reviews'    => '812',
        'review_snippet' => '"Jim and Dan came to our place today to fix a plumbing issue and did an outstanding job. They managed to clear a 20+-year-old plumbing blockage, which made a huge difference. …"',
        'faqs'           => [
            ['q' => 'What kind of plumbing jobs work best?', 'a' => 'Any job that gets 4–5 star reviews works great. Blocked drains, hot water systems, emergency call-outs — reviews for these tend to be detailed and emotional, which makes for compelling video.'],
            ['q' => 'How long does it take?', 'a' => 'Your free demo video is usually ready in about 15 minutes after you verify your email.'],
            ['q' => 'Can I post it on my Facebook business page?', 'a' => 'Yes — and we recommend it. Plumbing businesses that post video reviews on Facebook see significantly higher engagement than static posts.'],
        ],
    ],
    'house-cleaning' => [
        'slug'           => 'house-cleaning',
        'display'        => 'House Cleaning',
        'niche_value'    => 'house_cleaning',
        'title'          => 'Turn Your Cleaning Business Reviews into 30-Second Videos',
        'meta_desc'      => 'Get a free 30-second video made from your best Google review — automatically. Perfect for house cleaning and maid service businesses.',
        'hero_pre'       => 'Free video for cleaning businesses',
        'hero_h1'        => 'Your customers\' reviews. Turned into video.',
        'hero_sub'       => 'Before someone lets a cleaner into their home, they need to feel safe. A 30-second video of a happy client does that — better than any text review.',
        'hook'           => 'Before someone lets a cleaner into their home, they need to feel safe. A 30-second video of a happy client does that.',
        'r2_video'       => 'https://cdn.auditandfix.com/video-s25-1774578655370.mp4',
        'r2_poster'      => 'https://cdn.auditandfix.com/poster-s25-1774578668477.jpg',
        'biz_name'       => 'Maid2Go Cleaning Sydney',
        'biz_rating'     => '4.9',
        'biz_reviews'    => '827',
        'review_snippet' => '"I recently booked a deep clean with Maid2Go, and I cannot express how much of a relief the experience was. The quote was really reasonable. …"',
        'faqs'           => [
            ['q' => 'Will the video look professional?', 'a' => 'Yes — it uses professional stock clips matched to your cleaning niche, your customer\'s real review words, and AI voiceover. It\'s indistinguishable from a professionally produced testimonial video.'],
            ['q' => 'What if my business serves multiple suburbs?', 'a' => 'The video uses your business name and review text. You can post it across all your service area social accounts.'],
            ['q' => 'Do I need to do anything?', 'a' => 'Nothing. Enter your business name, verify your email, and we do the rest — finding reviews, creating the video, and delivering it to your inbox.'],
        ],
    ],
];

// ── Validate niche param ──────────────────────────────────────────────────────
$rawNiche = isset($_GET['niche']) ? trim($_GET['niche']) : '';

if ($rawNiche === '' || !array_key_exists($rawNiche, $niches)) {
    // Unknown niche — 404 with redirect fallback
    http_response_code(404);
    header('Location: /video-reviews/');
    exit;
}

$nicheData = $niches[$rawNiche];

// ── Geo + pricing ─────────────────────────────────────────────────────────────
$countryCode     = detectCountry();
$videoPricing    = get2StepPriceForCountry($countryCode);
$competitorRange = getCompetitorPriceRange($countryCode);

$symbol  = $videoPricing['symbol'];
$price4  = $videoPricing['monthly_4'];
$price8  = $videoPricing['monthly_8'];
$price12 = $videoPricing['monthly_12'];

// ── Verification link params ──────────────────────────────────────────────────
$verifyDemoId = isset($_GET['verify']) ? preg_replace('/[^a-f0-9\-]/', '', $_GET['verify']) : '';
$verifyToken  = isset($_GET['token'])  ? preg_replace('/[^a-f0-9]/',   '', $_GET['token'])  : '';

// ── Header CTA ────────────────────────────────────────────────────────────────
$headerCta = ['text' => 'Get Your Free Video', 'href' => '#get-video'];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($nicheData['title']) ?> | Audit&amp;Fix</title>
    <meta name="description" content="<?= htmlspecialchars($nicheData['meta_desc']) ?>">
    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/assets/img/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="canonical" href="https://www.auditandfix.com/video-reviews/<?= htmlspecialchars($nicheData['slug']) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($nicheData['title']) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($nicheData['meta_desc']) ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://www.auditandfix.com/video-reviews/<?= htmlspecialchars($nicheData['slug']) ?>">
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
          "name": "Audit&Fix Video Reviews — <?= htmlspecialchars($nicheData['display']) ?>",
          "url": "https://www.auditandfix.com/video-reviews/<?= htmlspecialchars($nicheData['slug']) ?>",
          "applicationCategory": "BusinessApplication",
          "description": "<?= htmlspecialchars($nicheData['meta_desc']) ?>",
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
<?php foreach ($nicheData['faqs'] as $i => $faq): ?>
            {"@type":"Question","name":<?= json_encode($faq['q']) ?>,"acceptedAnswer":{"@type":"Answer","text":<?= json_encode($faq['a']) ?>}}<?= $i < count($nicheData['faqs']) - 1 ? ',' : '' ?>
<?php endforeach; ?>
          ]
        }
      ]
    }
    </script>
    <style>
        /* ── Video-on-Demand niche page styles ──────────────────── */

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

        /* Single example video card — centred */
        .example-single {
            max-width: 340px;
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
            max-height: 340px;
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

        /* Hook bar below hero */
        .vod-hook-bar {
            background: #e05d26;
            color: #ffffff;
            text-align: center;
            padding: 16px 24px;
            font-size: 1rem;
            font-weight: 600;
            line-height: 1.5;
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
            0%   { width: 0%; }
            60%  { width: 70%; }
            100% { width: 92%; }
        }
        .vod-progress-label {
            font-size: 0.78rem;
            color: #718096;
        }

        /* ── How it works ──────────────────────────────────────── */
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

        /* ── FAQ ───────────────────────────────────────────────── */
        .vod-faq {
            padding: 80px 20px;
            background: #ffffff;
        }

        /* ── Pricing ───────────────────────────────────────────── */
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

<?php $headerTheme = 'light'; require_once __DIR__ . '/../includes/header.php'; ?>

<!-- ── Hero ──────────────────────────────────────────────────────────────── -->
<header class="vod-hero" style="padding-top: 0;">

    <div class="vod-hero-body">
        <p class="pre-headline"><?= htmlspecialchars($nicheData['hero_pre']) ?></p>
        <h1><?= htmlspecialchars($nicheData['hero_h1']) ?></h1>
        <p class="subtitle"><?= htmlspecialchars($nicheData['hero_sub']) ?></p>
    </div>

    <!-- Single niche example card -->
    <div class="example-single">
        <div class="example-card">
            <div class="example-review">
                <div class="example-review-icon"><?= strtoupper(substr($nicheData['biz_name'], 0, 1)) ?></div>
                <div class="example-review-text">
                    <div class="example-review-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
                    <div class="example-review-snippet"><?= htmlspecialchars($nicheData['review_snippet']) ?></div>
                </div>
            </div>
            <div class="example-arrow">&darr;</div>
            <div class="example-video-wrap">
                <video preload="auto" playsinline muted loop poster="<?= htmlspecialchars($nicheData['r2_poster']) ?>">
                    <source src="<?= htmlspecialchars($nicheData['r2_video']) ?>" type="video/mp4">
                </video>
                <div class="example-play-btn" aria-label="Play video">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                </div>
            </div>
            <div class="example-meta">
                <div class="example-meta-name"><?= htmlspecialchars($nicheData['biz_name']) ?></div>
                <div class="example-meta-info"><?= htmlspecialchars($nicheData['biz_rating']) ?>&#9733; &middot; <?= htmlspecialchars($nicheData['biz_reviews']) ?> reviews</div>
                <span class="example-meta-niche"><?= htmlspecialchars($nicheData['display']) ?></span>
            </div>
        </div>
    </div>

    <p class="example-disclaimer">Demo video created from a public Google review. Not a paid endorsement.</p>
</header>

<!-- ── Hook bar ──────────────────────────────────────────────────────────── -->
<div class="vod-hook-bar">
    <?= htmlspecialchars($nicheData['hook']) ?>
</div>

<!-- ── Main Content ──────────────────────────────────────────────────────── -->
<main id="main-content">

    <!-- How it works -->
    <section class="vod-steps-section">
        <h2>How it works</h2>
        <p class="section-subhead">From Google review to polished video in three simple steps.</p>
        <div class="vod-steps-grid">
            <div class="vod-step">
                <div class="vod-step-number">1</div>
                <h3>Tell us your business</h3>
                <p>Enter your business name. We scan your Google reviews and pick one that tells a great story.</p>
            </div>
            <div class="vod-step">
                <div class="vod-step-number">2</div>
                <h3>We create your video</h3>
                <p>AI-powered production turns the review into a polished 30-second video with voiceover, music, and your branding.</p>
            </div>
            <div class="vod-step">
                <div class="vod-step-number">3</div>
                <h3>Post it everywhere</h3>
                <p>Download and share on social, embed on your site, or use in ads. It's yours to keep forever.</p>
            </div>
        </div>
    </section>

    <!-- Get Your Free Video form -->
    <section class="vod-form-section" id="get-video">
        <h2>Get Your Free <?= htmlspecialchars($nicheData['display']) ?> Video</h2>
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
                        placeholder="e.g. <?= htmlspecialchars($nicheData['biz_name']) ?>"
                        autocomplete="off"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="vod-niche">Industry</label>
                    <select id="vod-niche" name="niche" required>
                        <option value="" disabled>Select your industry</option>
                        <option value="pest_control" <?= $nicheData['niche_value'] === 'pest_control'    ? 'selected' : '' ?>>Pest Control</option>
                        <option value="plumber"      <?= $nicheData['niche_value'] === 'plumber'         ? 'selected' : '' ?>>Plumber</option>
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
                        <option value="house_cleaning" <?= $nicheData['niche_value'] === 'house_cleaning' ? 'selected' : '' ?>>House Cleaning</option>
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

                <button type="submit" class="vod-form-btn" id="vod-form-btn">Get Your Free Video &rarr;</button>
            </form>

            <div class="vod-trust-badges">
                <span>&#10003; Free, no credit card</span>
                <span>&#10003; One video per business</span>
                <span>&#10003; Yours to keep</span>
            </div>
        </div>
    </section>

    <!-- Email verification gate (hidden until form submit) -->
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

    <!-- Email sent — awaiting verification click (hidden until email submitted) -->
    <section class="vod-email-sent-section" id="vod-email-sent-section">
        <div class="vod-email-sent-wrap">
            <div class="vod-email-sent-icon" aria-hidden="true">&#9993;</div>
            <h2>Check your inbox!</h2>
            <p>We sent a verification link to <span class="vod-email-sent-address" id="vod-email-sent-address"></span>.</p>
            <p style="margin-top:12px;">Click the link in that email to start creating your free video. It should arrive within a minute.</p>
            <p class="vod-email-note" style="margin-top:20px;font-size:0.85rem;color:#718096;">
                Can't find it? Check your spam folder. The link expires in 24 hours.
            </p>
        </div>
    </section>

    <!-- Post-verification confirmation (hidden until email verified) -->
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

    <!-- Pricing (always visible) -->
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

    <!-- Niche-specific FAQ -->
    <section class="vod-faq faq">
        <h2>Frequently asked questions</h2>

        <?php foreach ($nicheData['faqs'] as $faq): ?>
        <div class="faq-item">
            <h3><?= htmlspecialchars($faq['q']) ?></h3>
            <p><?= htmlspecialchars($faq['a']) ?></p>
        </div>
        <?php endforeach; ?>

        <div class="faq-item">
            <h3>Can I cancel anytime?</h3>
            <p>Yes. Monthly subscriptions with no lock-in. Cancel whenever you want.</p>
        </div>
        <div class="faq-item">
            <h3>What format are the videos?</h3>
            <p>Each video is about 30 seconds in vertical (9:16) format &mdash; perfect for Instagram Reels, TikTok, YouTube Shorts, and Facebook Stories.</p>
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
    nicheParam: '<?= htmlspecialchars($nicheData['niche_value']) ?>',
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

<!-- Inline: "Other" niche toggle + lightbox + video click-to-play -->
<script>
(function() {
    'use strict';

    // Show/hide "Other" free-text field
    var nicheSelect = document.getElementById('vod-niche');
    var otherGroup  = document.getElementById('vod-other-niche-group');
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
