<?php
/**
 * Video sales page — /v/{hash}
 *
 * Personalised single-page sales flow for 2Step video prospects.
 * Redesigned 2026-03-28 based on UX, copy, and growth agent specs.
 *
 * Structure (9 sections):
 *   1. Sitewide sticky navbar (shared header)
 *   2. Personalised video hero (name, stars, video player)
 *   3. Soft bridge ("Your customers already said it")
 *   4. Social proof stats
 *   5. How it works (3 steps)
 *   6. Single recommended pricing tier
 *   7. FAQ / objection handling
 *   8. Sticky mobile CTA (JS-triggered after video scroll)
 *   9. Sitewide footer (shared)
 *
 * Video data: data/videos/{hash}.json (pushed by api.php?action=store-video)
 * Tracking: video-viewed, video-play, video-complete beacons
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/geo.php';
require_once __DIR__ . '/includes/pricing.php';

// Extract hash from PATH_INFO or REQUEST_URI
$hash = null;
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if (preg_match('#^/([a-zA-Z0-9]+)$#', $pathInfo, $m)) {
    $hash = $m[1];
}
if (!$hash) {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (preg_match('#/v/([a-zA-Z0-9]+)#', $uri, $m)) {
        $hash = $m[1];
    }
}

if (!$hash) {
    header('Location: /video-reviews/');
    exit;
}

// Load video data
$dataFile = __DIR__ . '/data/videos/' . preg_replace('/[^a-zA-Z0-9]/', '', $hash) . '.json';
if (!is_file($dataFile)) {
    header('Location: /video-reviews/');
    exit;
}

$video = json_decode(file_get_contents($dataFile), true);
if (!$video || empty($video['video_url'])) {
    header('Location: /video-reviews/');
    exit;
}

$businessName  = htmlspecialchars($video['business_name'] ?? 'your business', ENT_QUOTES);
$videoUrl      = htmlspecialchars($video['video_url'] ?? '', ENT_QUOTES);
$posterUrl     = htmlspecialchars($video['poster_url'] ?? '', ENT_QUOTES);
$niche         = htmlspecialchars($video['niche'] ?? '', ENT_QUOTES);
$nicheDisplay  = htmlspecialchars($video['niche_display'] ?? $video['niche'] ?? '', ENT_QUOTES);
$city          = htmlspecialchars($video['city'] ?? '', ENT_QUOTES);
$reviewCount   = (int)($video['review_count'] ?? 0);
$starRating    = number_format((float)($video['google_rating'] ?? 5.0), 1);
$countryCode   = $video['country_code'] ?? detectCountry();
$contactEmail  = filter_var($video['contact_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '';

// Pricing
$videoPricing = get2StepPriceForCountry($countryCode);
$symbol   = $videoPricing['symbol'];
$price4   = $videoPricing['monthly_4'];
$price8   = $videoPricing['monthly_8'];
$price12  = $videoPricing['monthly_12'];

// Twilio number for SMS deep-link (AU default)
$smsNumber = '+61468089949';

// Session for navbar (if they navigate to main site)
$_SESSION['my_video'] = ['hash' => $hash, 'biz' => $video['business_name'] ?? 'your business'];

// Header config for personalised pages:
// - Solid background (no transparent-on-top — hero is below the fold line)
// - Hide language selector (language set from prospect record, not user choice)
// - CTA points to pricing section
$headerCta       = ['text' => 'Get Started', 'href' => '#pricing'];
$headerSolidBg   = true;
$hideLangSelector = true;

// Set language from prospect's country — they didn't choose it, we know it
$countryToLang = ['AU' => 'en', 'US' => 'en', 'UK' => 'en', 'GB' => 'en', 'CA' => 'en', 'NZ' => 'en',
    'FR' => 'fr', 'DE' => 'de', 'ES' => 'es', 'JP' => 'ja', 'KR' => 'ko', 'BR' => 'pt',
    'RU' => 'ru', 'TR' => 'tr', 'TH' => 'th', 'SA' => 'ar', 'SE' => 'sv', 'DK' => 'da'];
$_GET['lang'] = $countryToLang[$countryCode] ?? 'en';  // override before i18n loads

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $businessName ?> — your free video review | Audit&amp;Fix</title>
    <meta name="description" content="A free short video created from your best Google review. See what your customers are saying about <?= $businessName ?>.">
    <meta name="robots" content="noindex">

    <!-- Open Graph for social sharing -->
    <meta property="og:title" content="<?= $businessName ?> — free video from your Google reviews">
    <meta property="og:description" content="We turned your best Google review into a 30-second video. Watch it here.">
    <?php if ($posterUrl): ?><meta property="og:image" content="<?= $posterUrl ?>"><?php endif; ?>
    <meta property="og:video" content="<?= $videoUrl ?>">
    <meta property="og:type" content="video.other">

    <style>
        /* ── Reset + System fonts (no web fonts — sub-500KB page weight) ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            color: #2d3748; background: #fff; line-height: 1.6;
            -webkit-text-size-adjust: 100%;
        }
        a { color: #3182ce; text-decoration: none; }
        a:hover { text-decoration: underline; }
        img { max-width: 100%; height: auto; }

        /* ── Brand design tokens (from style.css) ── */
        :root {
            --color-navy: #1a365d;
            --color-navy-deep: #112240;
            --color-navy-mid: #2d5fa3;
            --color-orange: #e05d26;
            --color-orange-dark: #c44d1e;
            --color-text-dark: #2d3748;
            --color-text-mid: #4a5568;
            --color-text-muted: #718096;
            --color-text-faint: #a0aec0;
            --color-surface: #ffffff;
            --color-surface-alt: #f7fafc;
            --color-border: #e2e8f0;
            --color-success: #38a169;
        }

        /* ── Section 2: Video hero ── */
        .vp-hero {
            background: linear-gradient(170deg, var(--color-navy) 0%, var(--color-navy-deep) 60%, #0d1a30 100%);
            padding: 32px 20px 48px;
            text-align: center;
            color: #fff;
        }
        .vp-hero-inner {
            max-width: 480px;
            margin: 0 auto;
        }
        .vp-hero h1 {
            font-size: 1.5rem;
            font-weight: 800;
            line-height: 1.25;
            margin-bottom: 8px;
            color: #fff;
            letter-spacing: -0.01em;
        }
        .vp-hero .vp-stars-line {
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.7);
            margin-bottom: 24px;
        }
        .vp-hero .vp-stars-line .vp-stars {
            color: #fbd38d;
            letter-spacing: 2px;
            font-size: 1rem;
        }

        .vp-video-wrap {
            position: relative;
            border-radius: 16px;
            overflow: hidden;
            box-shadow:
                0 4px 12px rgba(0, 0, 0, 0.3),
                0 20px 60px rgba(0, 0, 0, 0.25);
            background: #000;
            max-width: 400px;
            margin: 0 auto;
            border: 2px solid rgba(255, 255, 255, 0.08);
        }
        .vp-video-wrap video {
            width: 100%;
            display: block;
            aspect-ratio: 9 / 16;
            object-fit: cover;
        }

        .vp-play-btn {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 76px;
            height: 76px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.95);
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.3);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            z-index: 2;
        }
        .vp-play-btn:hover {
            transform: translate(-50%, -50%) scale(1.06);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
        }
        .vp-play-btn svg {
            width: 28px;
            height: 28px;
            margin-left: 4px;
            fill: var(--color-navy);
        }
        .vp-play-btn.hidden { display: none; }

        .vp-tagline {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.5);
            margin-top: 16px;
            letter-spacing: 0.01em;
        }

        .vp-download-row {
            margin-top: 14px;
            opacity: 0;
            transition: opacity 0.4s ease;
            pointer-events: none;
        }
        .vp-download-row.show {
            opacity: 1;
            pointer-events: auto;
        }
        .vp-download-link {
            font-size: 0.82rem;
            color: rgba(255, 255, 255, 0.55);
            text-decoration: underline;
            text-underline-offset: 3px;
        }
        .vp-download-link:hover {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: underline;
        }
        .vp-download-link svg {
            width: 14px; height: 14px;
            vertical-align: -2px;
            margin-right: 4px;
            fill: currentColor;
        }
        .vp-download-micro {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.35);
            margin-top: 4px;
        }
        .vp-download-micro a {
            color: rgba(255, 255, 255, 0.5);
            text-decoration: underline;
        }

        /* ── Video end overlay ── */
        .vp-video-end-overlay {
            position: absolute;
            inset: 0;
            background: rgba(17, 34, 64, 0.88);
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #fff;
            padding: 24px;
            text-align: center;
            z-index: 3;
        }
        .vp-video-end-overlay.show { display: flex; }
        .vp-video-end-overlay p {
            font-size: 1.15rem;
            font-weight: 600;
            margin-bottom: 16px;
        }
        .vp-video-end-overlay .vp-btn-reply {
            background: var(--color-orange);
            color: #fff;
            padding: 14px 32px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1rem;
            text-decoration: none;
            display: inline-block;
            transition: background 0.15s ease;
        }
        .vp-video-end-overlay .vp-btn-reply:hover {
            background: var(--color-orange-dark);
            text-decoration: none;
        }

        /* ── Section 3: Soft bridge ── */
        .vp-bridge {
            max-width: 560px;
            margin: 0 auto;
            padding: 48px 24px 40px;
        }
        .vp-bridge h2 {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 16px;
            color: var(--color-navy);
            line-height: 1.3;
        }
        .vp-bridge p {
            color: var(--color-text-mid);
            margin-bottom: 14px;
            font-size: 0.95rem;
            line-height: 1.75;
        }

        /* ── Section 4: Stats ── */
        .vp-stats {
            background: var(--color-surface-alt);
            padding: 48px 20px;
        }
        .vp-stats-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
            max-width: 520px;
            margin: 0 auto;
        }
        .vp-stat-card {
            background: var(--color-surface);
            border-radius: 12px;
            padding: 24px 20px;
            text-align: center;
            border: 1px solid var(--color-border);
            transition: box-shadow 0.2s ease;
        }
        .vp-stat-card:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
        }
        .vp-stat-number {
            display: block;
            font-size: 2rem;
            font-weight: 800;
            color: var(--color-navy);
            line-height: 1.1;
            margin-bottom: 6px;
        }
        .vp-stat-label {
            font-size: 0.88rem;
            color: var(--color-text-mid);
            line-height: 1.5;
        }

        /* ── Section 5: How it works ── */
        .vp-how-it-works {
            max-width: 560px;
            margin: 0 auto;
            padding: 48px 24px;
        }
        .vp-how-it-works h2 {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 28px;
            text-align: center;
            color: var(--color-navy);
        }
        .vp-steps {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .vp-step {
            display: flex;
            gap: 16px;
            align-items: flex-start;
        }
        .vp-step-num {
            flex-shrink: 0;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--color-navy);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.9rem;
        }
        .vp-step-text h3 {
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--color-text-dark);
        }
        .vp-step-text p {
            font-size: 0.88rem;
            color: var(--color-text-muted);
            line-height: 1.6;
        }

        /* ── Section 6: Pricing ── */
        .vp-pricing {
            text-align: center;
            padding: 48px 20px 56px;
            background: linear-gradient(175deg, var(--color-surface-alt) 0%, #edf2f7 100%);
        }
        .vp-pricing h2 {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--color-navy);
        }
        .vp-pricing .vp-intro {
            color: var(--color-text-muted);
            font-size: 0.9rem;
            margin-bottom: 28px;
            max-width: 420px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.6;
        }

        /* ── 3-tier pricing grid ── */
        .vp-tier-grid {
            display: flex;
            gap: 16px;
            justify-content: center;
            flex-wrap: wrap;
            max-width: 800px;
            margin: 0 auto;
        }
        .vp-price-card {
            flex: 1;
            min-width: 220px;
            max-width: 260px;
            background: var(--color-surface);
            border-radius: 16px;
            padding: 24px 20px;
            box-shadow:
                0 1px 3px rgba(0, 0, 0, 0.04),
                0 4px 12px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--color-border);
            position: relative;
            overflow: hidden;
            text-align: center;
        }
        /* Hero card (middle tier) — bigger, accented */
        .vp-price-card.vp-hero {
            max-width: 280px;
            padding: 32px 24px;
            border: 2px solid var(--color-navy);
            box-shadow:
                0 1px 3px rgba(0, 0, 0, 0.04),
                0 8px 24px rgba(0, 0, 0, 0.08);
            transform: scale(1.03);
        }
        .vp-price-card.vp-hero::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--color-navy) 0%, var(--color-navy-mid) 100%);
        }
        .vp-price-card .vp-tier-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--color-text-mid);
            margin-bottom: 8px;
        }
        .vp-price-card .vp-badge {
            display: inline-block;
            background: var(--color-navy);
            color: #fff;
            font-size: 0.68rem;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 20px;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .vp-price-card .vp-amount {
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--color-navy);
            line-height: 1;
        }
        .vp-price-card.vp-hero .vp-amount {
            font-size: 2.8rem;
        }
        .vp-price-card .vp-period {
            font-size: 0.9rem;
            color: var(--color-text-muted);
            font-weight: 400;
        }
        .vp-price-card .vp-desc {
            color: var(--color-text-mid);
            font-size: 0.85rem;
            margin: 8px 0 4px;
        }
        .vp-price-card .vp-cancel {
            color: var(--color-text-faint);
            font-size: 0.78rem;
            margin-bottom: 16px;
        }
        .vp-price-card .vp-per-video {
            color: var(--color-text-faint);
            font-size: 0.75rem;
            margin-top: 4px;
        }
        /* Secondary tier CTA */
        .vp-btn-secondary {
            display: block;
            width: 100%;
            padding: 12px;
            background: transparent;
            color: var(--color-navy);
            border: 2px solid var(--color-navy);
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .vp-btn-secondary:hover {
            background: var(--color-navy);
            color: #fff;
        }

        .vp-email-gate { margin-bottom: 18px; max-width: 360px; margin-left: auto; margin-right: auto; }
        .vp-email-gate label {
            display: block;
            font-size: 0.82rem;
            color: var(--color-text-muted);
            margin-bottom: 6px;
            text-align: left;
            font-weight: 500;
        }
        .vp-email-gate input {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            font-size: 1rem;
            background: var(--color-surface);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .vp-email-gate input:focus {
            outline: none;
            border-color: var(--color-navy-mid);
            box-shadow: 0 0 0 3px rgba(45, 95, 163, 0.12);
        }
        .vp-email-gate .vp-error {
            color: #c53030;
            font-size: 0.82rem;
            display: none;
            margin-top: 6px;
            text-align: left;
        }

        .vp-btn-primary {
            display: block;
            width: 100%;
            padding: 16px;
            background: var(--color-orange);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1.05rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease, transform 0.1s ease;
        }
        .vp-btn-primary:hover {
            background: var(--color-orange-dark);
            transform: translateY(-1px);
        }

        /* Mobile: stack cards vertically, hero first */
        @media (max-width: 640px) {
            .vp-tier-grid {
                flex-direction: column;
                align-items: center;
            }
            .vp-price-card { max-width: 340px; width: 100%; }
            .vp-price-card.vp-hero { transform: none; max-width: 340px; order: -1; }
        }

        /* Success / cancel banners */
        .vp-banner-success {
            background: #f0fff4;
            border: 2px solid var(--color-success);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            max-width: 380px;
            margin-left: auto;
            margin-right: auto;
        }
        .vp-banner-success h3 { color: var(--color-success); margin-bottom: 4px; }
        .vp-banner-cancel {
            background: #fffbeb;
            border: 1px solid #fbbf24;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
            max-width: 380px;
            margin-left: auto;
            margin-right: auto;
            color: var(--color-text-mid);
            font-size: 0.9rem;
        }

        /* ── Section 7: FAQ ── */
        .vp-faq {
            max-width: 560px;
            margin: 0 auto;
            padding: 48px 24px;
        }
        .vp-faq h2 {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 24px;
            text-align: center;
            color: var(--color-navy);
        }
        .vp-faq details {
            border-bottom: 1px solid var(--color-border);
            padding: 18px 0;
        }
        .vp-faq details:first-of-type {
            border-top: 1px solid var(--color-border);
        }
        .vp-faq summary {
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            list-style: none;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--color-text-dark);
            padding: 2px 0;
            transition: color 0.15s ease;
        }
        .vp-faq summary:hover {
            color: var(--color-navy);
        }
        .vp-faq summary::-webkit-details-marker { display: none; }
        .vp-faq summary::after {
            content: '';
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            margin-left: 12px;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%23718096' stroke-width='2'%3E%3Cline x1='12' y1='5' x2='12' y2='19'/%3E%3Cline x1='5' y1='12' x2='19' y2='12'/%3E%3C/svg%3E") center / contain no-repeat;
            transition: transform 0.2s ease;
        }
        .vp-faq details[open] summary::after {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%231a365d' stroke-width='2'%3E%3Cline x1='5' y1='12' x2='19' y2='12'/%3E%3C/svg%3E");
        }
        .vp-faq .vp-answer {
            padding-top: 12px;
            color: var(--color-text-mid);
            font-size: 0.9rem;
            line-height: 1.75;
        }

        /* ── Section 8: Sticky mobile CTA ── */
        .vp-sticky-cta {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--color-surface);
            border-top: 1px solid var(--color-border);
            box-shadow: 0 -4px 16px rgba(0, 0, 0, 0.08);
            padding: 12px 20px;
            display: none;
            align-items: center;
            justify-content: space-between;
            z-index: 100;
        }
        .vp-sticky-cta.show { display: flex; }
        .vp-sticky-cta .vp-price-hint {
            font-size: 0.85rem;
            color: var(--color-text-muted);
        }
        .vp-sticky-cta .vp-price-hint strong {
            color: var(--color-navy);
            font-size: 1.15rem;
            font-weight: 800;
        }
        .vp-sticky-cta .vp-btn-sticky {
            background: var(--color-orange);
            color: #fff;
            padding: 12px 28px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            border: none;
            cursor: pointer;
            white-space: nowrap;
            transition: background 0.15s ease;
        }
        .vp-sticky-cta .vp-btn-sticky:hover {
            background: var(--color-orange-dark);
        }

        /* ── Footer overrides for this page ── */
        /* The shared footer uses .footer class from style.css.
           We include a minimal set of footer tokens so it renders
           correctly without loading the full style.css. */
        .footer {
            padding: 40px 20px;
            background: var(--color-navy-deep);
            color: var(--color-text-faint);
            text-align: center;
            font-size: 0.88rem;
        }
        .footer .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 0 24px;
        }
        .footer a { color: #63b3ed; }
        .footer-logo {
            display: inline-block;
            margin-bottom: 12px;
            opacity: 0.85;
        }
        .footer-logo:hover { opacity: 1; }
        .footer-logo-img { height: 28px; width: auto; }
        .footer-contact { margin-top: 6px; }
        .footer-privacy {
            margin-top: 8px;
            font-size: 0.78rem;
            color: #8899aa;
        }
        .footer-links {
            margin-top: 12px;
            display: flex;
            gap: 16px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .footer-links a {
            font-size: 0.82rem;
            color: rgba(255, 255, 255, 0.65);
            text-decoration: none;
        }
        .footer-links a:hover { color: #63b3ed; }
        .footer-legal {
            margin-top: 12px;
            display: flex;
            gap: 16px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .footer-legal a {
            font-size: 0.78rem;
            color: rgba(255, 255, 255, 0.5);
            text-decoration: none;
        }
        .footer-legal a:hover { color: #63b3ed; }

        /* ── Responsive: mobile-first with desktop enhancements ── */
        @media (max-width: 768px) {
            body.has-sticky { padding-bottom: 72px; }
        }

        @media (min-width: 480px) {
            .vp-stats-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 12px;
            }
            .vp-stat-card { padding: 20px 12px; }
            .vp-stat-number { font-size: 1.5rem; }
            .vp-stat-label { font-size: 0.8rem; }
        }

        @media (min-width: 769px) {
            .vp-sticky-cta { display: none !important; }

            .vp-hero { padding: 48px 24px 64px; }
            .vp-hero h1 { font-size: 1.75rem; }

            .vp-steps { flex-direction: row; }
            .vp-step {
                flex: 1;
                flex-direction: column;
                text-align: center;
                align-items: center;
            }
            .vp-step-num { margin-bottom: 8px; }

            .vp-stats-grid {
                max-width: 640px;
                gap: 20px;
            }
            .vp-stat-card { padding: 28px 20px; }
            .vp-stat-number { font-size: 2.2rem; }
        }

        /* ── Reduced motion support ── */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                transition-duration: 0.01ms !important;
            }
            html { scroll-behavior: auto; }
        }
    </style>
</head>
<body>

<!-- Section 1: Sitewide navigation -->
<?php require_once __DIR__ . '/includes/header.php'; ?>

<!-- Section 2: Video hero -->
<section class="vp-hero">
    <div class="vp-hero-inner">
        <h1><?= $businessName ?>, we made you a free video</h1>
        <div class="vp-stars-line">
            <span class="vp-stars">&#9733;&#9733;&#9733;&#9733;&#9733;</span>
            <?= $starRating ?> stars from <?= number_format($reviewCount) ?> reviews
        </div>

        <div class="vp-video-wrap" id="video-wrap">
            <video id="review-video" playsinline preload="metadata"
                   <?php if ($posterUrl): ?>poster="<?= $posterUrl ?>"<?php endif; ?>>
                <source src="<?= $videoUrl ?>" type="video/mp4">
            </video>
            <button class="vp-play-btn" id="play-btn" aria-label="Play video">
                <svg viewBox="0 0 24 24"><polygon points="6,3 20,12 6,21"/></svg>
            </button>
            <div class="vp-video-end-overlay" id="end-overlay">
                <p>Want this on your socials?</p>
                <a href="sms:<?= $smsNumber ?>?body=Interested" class="vp-btn-reply" id="reply-link">Reply via text</a>
            </div>
        </div>

        <p class="vp-tagline">30 seconds. Made from a real review. Yours to keep.</p>

        <div class="vp-download-row" id="download-row">
            <a class="vp-download-link" href="<?= $videoUrl ?>" download="<?= $businessName ?>-review.mp4"><svg viewBox="0 0 24 24"><path d="M12 16l-5-5h3V4h4v7h3l-5 5zm-7 2h14v2H5v-2z"/></svg>Download your free video</a>
            <p class="vp-download-micro">This one's on us. Want fresh videos every month? <a href="#pricing">See plans</a></p>
        </div>
    </div>
</section>

<!-- Section 3: Soft bridge -->
<section class="vp-bridge">
    <h2>Your customers already said it</h2>
    <p>
        <?= $businessName ?> has <?= number_format($reviewCount) ?> Google reviews. Your <?= $nicheDisplay ?>
        customers are already telling people how good you are — but only in text that most people scroll past.
    </p>
    <p>
        This video turns one of those reviews into something you can post on Facebook, Instagram,
        your website, or send to new leads. It's yours — no strings attached.
    </p>
</section>

<!-- Section 4: Stats -->
<section class="vp-stats">
    <div class="vp-stats-grid">
        <div class="vp-stat-card">
            <span class="vp-stat-number">88%</span>
            <span class="vp-stat-label">of people say video convinced them to buy</span>
        </div>
        <div class="vp-stat-card">
            <span class="vp-stat-number">42%</span>
            <span class="vp-stat-label">more direction requests with video on Google</span>
        </div>
        <div class="vp-stat-card">
            <span class="vp-stat-number">2.5x</span>
            <span class="vp-stat-label">more engagement than any other format</span>
        </div>
    </div>
</section>

<!-- Section 5: How it works -->
<section class="vp-how-it-works">
    <h2>How it works</h2>
    <div class="vp-steps">
        <div class="vp-step">
            <div class="vp-step-num">1</div>
            <div class="vp-step-text">
                <h3>We pick your best Google reviews</h3>
                <p>The ones with the most detail and emotion</p>
            </div>
        </div>
        <div class="vp-step">
            <div class="vp-step-num">2</div>
            <div class="vp-step-text">
                <h3>Turn them into short videos</h3>
                <p>Professional voiceover, music, your branding</p>
            </div>
        </div>
        <div class="vp-step">
            <div class="vp-step-num">3</div>
            <div class="vp-step-text">
                <h3>You post. Customers call.</h3>
                <p>Facebook, Instagram, your website — takes 10 seconds</p>
            </div>
        </div>
    </div>
</section>

<!-- Section 6: Pricing -->
<section class="vp-pricing" id="pricing">
    <h2>Want a fresh video every week?</h2>
    <p class="vp-intro">
        You got this one free. Subscribe and we'll send new videos from different reviews every month.
    </p>

    <?php if (!empty($_GET['subscription_activated'])): ?>
    <div class="vp-banner-success">
        <h3>You're subscribed!</h3>
        <p>Your first batch of videos will arrive within 48 hours.</p>
    </div>
    <?php endif; ?>

    <?php if (!empty($_GET['subscription_cancelled'])): ?>
    <div class="vp-banner-cancel">
        <p>No worries — you weren't charged. Your free video is still yours.</p>
    </div>
    <?php endif; ?>

    <div class="vp-email-gate" id="email-gate" <?= !empty($_GET['subscription_activated']) ? 'style="display:none"' : '' ?>>
        <?php if ($contactEmail): ?>
        <label for="sub-email">Should we send your videos to the same address?</label>
        <input type="email" id="sub-email" value="<?= htmlspecialchars($contactEmail, ENT_QUOTES) ?>" placeholder="you@yourbusiness.com">
        <?php else: ?>
        <label for="sub-email">Where should we send your videos?</label>
        <input type="email" id="sub-email" placeholder="you@yourbusiness.com">
        <?php endif; ?>
        <div class="vp-error" id="sub-error"></div>
    </div>

    <div class="vp-tier-grid">
        <!-- Starter -->
        <div class="vp-price-card">
            <div class="vp-tier-label">Starter</div>
            <div class="vp-amount"><?= $symbol ?><?= $price4 ?><span class="vp-period">/mo</span></div>
            <p class="vp-desc">4 videos per month</p>
            <p class="vp-per-video"><?= $symbol ?><?= round($price4 / 4) ?> per video</p>
            <p class="vp-cancel">Cancel anytime</p>
            <button class="vp-btn-secondary subscribe-btn" data-tier="monthly_4">Get Started</button>
        </div>

        <!-- Growth (hero) -->
        <div class="vp-price-card vp-hero">
            <span class="vp-badge">Most popular for <?= $nicheDisplay ?></span>
            <div class="vp-tier-label">Growth</div>
            <div class="vp-amount"><?= $symbol ?><?= $price8 ?><span class="vp-period">/mo</span></div>
            <p class="vp-desc">8 videos per month (2 per week)</p>
            <p class="vp-per-video"><?= $symbol ?><?= round($price8 / 8) ?> per video</p>
            <p class="vp-cancel">Cancel anytime. No lock-in.</p>
            <button class="vp-btn-primary subscribe-btn" data-tier="monthly_8">Get Started</button>
        </div>

        <!-- Scale -->
        <div class="vp-price-card">
            <div class="vp-tier-label">Scale</div>
            <div class="vp-amount"><?= $symbol ?><?= $price12 ?><span class="vp-period">/mo</span></div>
            <p class="vp-desc">12 videos per month</p>
            <p class="vp-per-video"><?= $symbol ?><?= round($price12 / 12) ?> per video — best value</p>
            <p class="vp-cancel">Cancel anytime</p>
            <button class="vp-btn-secondary subscribe-btn" data-tier="monthly_12">Get Started</button>
        </div>
    </div>
</section>

<!-- Section 7: FAQ -->
<section class="vp-faq">
    <h2>Fair questions</h2>

    <details open>
        <summary>Did I ask for this?</summary>
        <div class="vp-answer">
            Nope. We found <?= $businessName ?> through your Google reviews and thought your
            <?= $starRating ?>-star reputation deserved more than just text on a screen.
            The video is genuinely free — no invoice coming, no catch.
        </div>
    </details>

    <details>
        <summary>What do I get if I sign up?</summary>
        <div class="vp-answer">
            Each month we pick your strongest new Google reviews, turn them into professional short
            videos with voiceover and music, and deliver them ready to post on Instagram, Facebook,
            your website, or your Google listing. You just approve and share.
        </div>
    </details>

    <details>
        <summary>Can I cancel anytime?</summary>
        <div class="vp-answer">
            Yes. Monthly billing, no contracts, no lock-in. If it's not working for you, cancel and that's it.
        </div>
    </details>

    <details>
        <summary>I don't really do social media</summary>
        <div class="vp-answer">
            These videos work just as well on your website or Google Business Profile.
            Most of our <?= $nicheDisplay ?> clients just post them and move on — takes about 30 seconds.
        </div>
    </details>
</section>

<!-- Section 8: Sticky mobile CTA -->
<div class="vp-sticky-cta" id="sticky-cta">
    <div class="vp-price-hint"><strong><?= $symbol ?><?= $price8 ?></strong>/mo</div>
    <button class="vp-btn-sticky" onclick="document.getElementById('pricing').scrollIntoView({behavior:'smooth'})">Get Started</button>
</div>

<!-- Section 9: Sitewide footer -->
<?php require_once __DIR__ . '/includes/footer.php'; ?>

<!-- Scripts: play button, tracking, sticky CTA, PayPal checkout -->
<script>
(function() {
    var video = document.getElementById('review-video');
    var playBtn = document.getElementById('play-btn');
    var endOverlay = document.getElementById('end-overlay');
    var stickyCta = document.getElementById('sticky-cta');
    var videoWrap = document.getElementById('video-wrap');
    var hash = '<?= $hash ?>';

    // ── Tracking beacons ──
    function track(event) {
        fetch('/api.php?action=video-viewed', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ hash: hash, event: event }),
            keepalive: true
        }).catch(function() {});
    }

    // Page view
    track('page_viewed');

    // ── Play button ──
    playBtn.addEventListener('click', function() {
        playBtn.classList.add('hidden');
        endOverlay.classList.remove('show');
        video.play();
        track('video_play_started');
    });

    // Show/hide play button on play/pause
    video.addEventListener('play', function() { playBtn.classList.add('hidden'); });
    video.addEventListener('pause', function() {
        if (!video.ended) playBtn.classList.remove('hidden');
    });

    // Tap video to pause/play
    video.addEventListener('click', function() {
        if (video.paused) { video.play(); }
        else { video.pause(); }
    });

    // ── Video progress tracking ──
    var tracked25 = false, tracked50 = false, tracked75 = false;
    var downloadRow = document.getElementById('download-row');
    video.addEventListener('timeupdate', function() {
        if (!video.duration) return;
        var pct = video.currentTime / video.duration;
        if (pct >= 0.25 && !tracked25) { tracked25 = true; track('video_25_pct'); }
        if (pct >= 0.50 && !tracked50) { tracked50 = true; track('video_50_pct'); if (downloadRow) downloadRow.classList.add('show'); }
        if (pct >= 0.75 && !tracked75) { tracked75 = true; track('video_75_pct'); }
    });

    // ── End overlay ──
    video.addEventListener('ended', function() {
        endOverlay.classList.add('show');
        track('video_completed');
    });

    // ── SMS deep-link (iOS vs Android) ──
    var replyLink = document.getElementById('reply-link');
    if (replyLink) {
        var isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent);
        var num = '<?= $smsNumber ?>';
        replyLink.href = isIOS
            ? 'sms:' + num + '&body=Interested'
            : 'sms:' + num + '?body=Interested';
    }

    // ── Sticky CTA: show after scrolling past video ──
    if ('IntersectionObserver' in window && window.innerWidth <= 768) {
        document.body.classList.add('has-sticky');
        var observer = new IntersectionObserver(function(entries) {
            stickyCta.classList.toggle('show', !entries[0].isIntersecting);
        }, { threshold: 0 });
        observer.observe(videoWrap);
    }

    // ── Pricing scroll tracking ──
    var pricingEl = document.getElementById('pricing');
    if ('IntersectionObserver' in window && pricingEl) {
        var pricingTracked = false;
        var po = new IntersectionObserver(function(entries) {
            if (entries[0].isIntersecting && !pricingTracked) {
                pricingTracked = true;
                track('pricing_scrolled');
            }
        }, { threshold: 0.3 });
        po.observe(pricingEl);
    }

    // ── PayPal subscription checkout ──
    var emailInput = document.getElementById('sub-email');
    var errorEl = document.getElementById('sub-error');

    document.querySelectorAll('.subscribe-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (errorEl) { errorEl.style.display = 'none'; }

            var email = (emailInput.value || '').trim();
            if (!email || !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                if (errorEl) { errorEl.textContent = 'Please enter a valid email.'; errorEl.style.display = ''; }
                if (emailInput) emailInput.focus();
                return;
            }

            var tier = btn.getAttribute('data-tier');
            btn.disabled = true;
            var origText = btn.textContent;
            btn.textContent = 'Redirecting to PayPal\u2026';

            track('checkout_started');

            fetch('/api.php?action=create-subscription', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    tier: tier,
                    email: email,
                    country: '<?= htmlspecialchars($countryCode) ?>',
                    business_name: '<?= $businessName ?>',
                    video_hash: '<?= $hash ?>'
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.approve_url) {
                    window.location.href = data.approve_url;
                } else {
                    if (errorEl) { errorEl.textContent = data.error || 'Something went wrong.'; errorEl.style.display = ''; }
                    btn.disabled = false;
                    btn.textContent = origText;
                }
            })
            .catch(function() {
                if (errorEl) { errorEl.textContent = 'Network error. Please try again.'; errorEl.style.display = ''; }
                btn.disabled = false;
                btn.textContent = origText;
            });
        });
    });

    // Handle PayPal return
    var params = new URLSearchParams(window.location.search);
    var subId = params.get('subscription_id');
    if (subId && params.get('subscription_activated') === '1') {
        fetch('/api.php?action=activate-subscription', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ subscription_id: subId, video_hash: hash })
        }).catch(function() {});
        track('subscription_activated');
    }

    // ── Audio priming for Chromium ──
    var primed = false;
    function primeAudio() {
        if (primed) return;
        primed = true;
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var buf = ctx.createBuffer(1, 1, 22050);
            var src = ctx.createBufferSource();
            src.buffer = buf; src.connect(ctx.destination); src.start(0);
        } catch(e) {}
        document.removeEventListener('scroll', primeAudio);
        document.removeEventListener('touchstart', primeAudio);
    }
    document.addEventListener('scroll', primeAudio, { once: true, passive: true });
    document.addEventListener('touchstart', primeAudio, { once: true, passive: true });
})();
</script>

</body>
</html>
