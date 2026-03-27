<?php
/**
 * CRO Audit Comparison — /compare
 *
 * Side-by-side comparison of Audit&Fix CRO Audit vs competitors.
 * Geo-detected pricing throughout. Linked from nav and pricing cards.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/geo.php';
require_once __DIR__ . '/includes/pricing.php';

$countryCode      = detectCountry();
$productPriceData = getProductPriceForCountry($countryCode, 'full_audit');
$symbol           = $productPriceData['symbol'];
$priceCents       = $productPriceData['price'];
$priceFormatted   = $productPriceData['formatted'];
$currency         = $productPriceData['currency'];

// Quick Fixes pricing for comparison row
$quickFixesData      = getProductPriceForCountry($countryCode, 'quick_fixes');
$quickFixesFormatted = $quickFixesData['formatted'];

// Audit + Implementation pricing
$auditFixData      = getProductPriceForCountry($countryCode, 'audit_fix');
$auditFixFormatted = $auditFixData['formatted'];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('cmp.page_title') ?></title>
    <meta name="description" content="<?= t('cmp.page_description') ?>">
    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/assets/img/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="canonical" href="https://www.auditandfix.com/compare">
    <meta property="og:title" content="<?= t('cmp.og_title') ?>">
    <meta property="og:description" content="<?= t('cmp.og_description') ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://www.auditandfix.com/compare">
    <meta property="og:image" content="https://www.auditandfix.com/assets/img/og-image.png">
    <meta property="og:site_name" content="Audit&amp;Fix">
    <meta name="twitter:card" content="summary_large_image">

    <!-- Schema.org structured data -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebPage",
      "name": "CRO Audit Comparison — How Audit&Fix Compares",
      "description": "Side-by-side comparison of Audit&Fix CRO Audit versus Hotjar, CrazyEgg, Google Lighthouse, DIY audits, and hiring a freelancer.",
      "url": "https://www.auditandfix.com/compare",
      "mainEntity": {
        "@type": "Table",
        "about": "CRO audit service comparison"
      },
      "breadcrumb": {
        "@type": "BreadcrumbList",
        "itemListElement": [
          {"@type": "ListItem", "position": 1, "name": "Home", "item": "https://www.auditandfix.com/"},
          {"@type": "ListItem", "position": 2, "name": "Conversion Audit", "item": "https://www.auditandfix.com/scan"},
          {"@type": "ListItem", "position": 3, "name": "Compare"}
        ]
      },
      "provider": {
        "@type": "Organization",
        "name": "Audit&Fix",
        "url": "https://www.auditandfix.com/"
      },
      "mainContentOfPage": {
        "@type": "FAQPage",
        "mainEntity": [
          {"@type": "Question", "name": "What is a CRO audit?", "acceptedAnswer": {"@type": "Answer", "text": "A CRO (Conversion Rate Optimisation) audit analyses your website to identify what stops visitors from taking the action you want — buying, booking, enquiring. It scores key conversion factors and gives you a prioritised list of fixes."}},
          {"@type": "Question", "name": "How is this different from Google Lighthouse?", "acceptedAnswer": {"@type": "Answer", "text": "Lighthouse measures page speed, accessibility, and SEO — useful, but none of that tells you whether your headline is persuasive or your call-to-action is visible. Our audit analyses the actual visual page the way a real visitor sees it: layout, copy, trust signals, and user flow."}},
          {"@type": "Question", "name": "Do I need to install any tracking code?", "acceptedAnswer": {"@type": "Answer", "text": "No. We work from your live URL — no scripts, no tracking pixels, no cookie banners required. You receive a finished PDF report without touching your site."}},
          {"@type": "Question", "name": "How quickly do I get my report?", "acceptedAnswer": {"@type": "Answer", "text": "Reports are delivered within 24 hours of payment. Most are ready within a few hours during business hours (AEST)."}},
          {"@type": "Question", "name": "Is there a money-back guarantee?", "acceptedAnswer": {"@type": "Answer", "text": "Yes — 30-day money-back guarantee, no questions asked. If the report doesn't help, you pay nothing."}},
          {"@type": "Question", "name": "Can I order a follow-up after making changes?", "acceptedAnswer": {"@type": "Answer", "text": "Yes. Once you have implemented fixes, you can order a follow-up benchmarking audit at 50% of the original price to measure how much your conversion score improved."}}
        ]
      }
    }
    </script>

    <style>
        /* ── Compare page styles ──────────────────────────────────── */

        /* Hero */
        .cmp-hero {
            background: linear-gradient(135deg, #f8fafc 0%, #eef2f7 50%, #f0f4f9 100%);
            color: #1a365d;
            padding: 0 0 60px;
        }
        .cmp-hero-body {
            max-width: 800px;
            margin: 0 auto;
            padding: 48px 24px 0;
            text-align: center;
        }
        .cmp-hero h1 {
            font-size: 2.2rem;
            line-height: 1.2;
            margin-bottom: 16px;
            font-weight: 800;
            color: #1a365d;
        }
        .cmp-hero .subtitle {
            font-size: 1.1rem;
            color: #4a5568;
            line-height: 1.6;
            max-width: 620px;
            margin: 0 auto;
        }

        /* ── Comparison table section ─────────────────────────────── */
        .cmp-table-section {
            padding: 60px 20px 80px;
            background: #f8fafc;
        }
        .cmp-table-section h2 {
            text-align: center;
            color: #1a365d;
            font-size: 1.8rem;
            margin-bottom: 12px;
        }
        .cmp-table-section .section-subtitle {
            text-align: center;
            color: #4a5568;
            font-size: 1rem;
            margin-bottom: 40px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.5;
        }
        .cmp-table-wrap {
            max-width: 1120px;
            margin: 0 auto;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 6px 24px rgba(0,0,0,0.06);
            background: #ffffff;
        }
        .cmp-table {
            width: 100%;
            min-width: 860px;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 0.93rem;
        }

        /* ── Table header ── */
        .cmp-table thead th {
            padding: 18px 16px;
            text-align: left;
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: rgba(255,255,255,0.7);
            background: linear-gradient(135deg, #1a365d 0%, #243b6a 100%);
            border-bottom: none;
            white-space: nowrap;
            vertical-align: bottom;
        }
        .cmp-table thead th:first-child {
            color: rgba(255,255,255,0.95);
            border-radius: 12px 0 0 0;
        }
        .cmp-table thead th:last-child {
            border-radius: 0 12px 0 0;
        }

        /* Our column header */
        .cmp-table thead th.cmp-ours {
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
            color: #ffffff;
            position: relative;
            border-left: 2px solid rgba(255,255,255,0.15);
            border-right: 2px solid rgba(255,255,255,0.15);
        }
        .cmp-table thead th.cmp-ours::after {
            content: 'Best Value';
            display: block;
            background: #e05d26;
            color: #fff;
            font-size: 0.6rem;
            font-weight: 700;
            padding: 3px 12px;
            border-radius: 8px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-top: 6px;
            width: fit-content;
            margin-left: auto;
            margin-right: auto;
        }

        /* ── Table body ── */
        .cmp-table tbody td {
            padding: 16px 16px;
            border-bottom: 1px solid #edf2f7;
            color: #4a5568;
            vertical-align: top;
            line-height: 1.55;
            transition: background 0.15s ease;
        }
        /* Zebra striping */
        .cmp-table tbody tr:nth-child(even) td {
            background: #f7fafc;
        }
        .cmp-table tbody tr:nth-child(even) td.cmp-ours {
            background: #eff6ff;
        }

        /* Feature column (first col) */
        .cmp-table tbody td:first-child {
            font-weight: 700;
            color: #1a365d;
            white-space: nowrap;
            font-size: 0.91rem;
        }

        /* Our column cells — highlighted */
        .cmp-table tbody td.cmp-ours {
            background: #f0f7ff;
            color: #1e3a5f;
            font-weight: 600;
            border-left: 2px solid #dbeafe;
            border-right: 2px solid #dbeafe;
        }
        .cmp-table tbody tr:last-child td {
            border-bottom: none;
        }
        .cmp-table tbody tr:last-child td:first-child {
            border-radius: 0 0 0 12px;
        }
        .cmp-table tbody tr:last-child td:last-child {
            border-radius: 0 0 12px 0;
        }
        .cmp-table tbody tr:last-child td.cmp-ours {
            border-bottom: 2px solid #dbeafe;
        }

        /* Row hover */
        .cmp-table tbody tr:hover td {
            background: #edf2f7;
        }
        .cmp-table tbody tr:hover td.cmp-ours {
            background: #dbeafe;
        }

        /* ── Check / cross / partial icons ── */
        .cmp-yes {
            color: #059669;
            font-weight: 700;
        }
        .cmp-no {
            color: #a0aec0;
            font-weight: 500;
        }
        .cmp-partial {
            color: #d97706;
            font-weight: 600;
        }

        /* Scroll hint on mobile */
        .cmp-scroll-hint {
            display: none;
            text-align: center;
            font-size: 0.85rem;
            color: #718096;
            margin-bottom: 14px;
            font-weight: 500;
            padding: 8px 16px;
            background: #edf2f7;
            border-radius: 8px;
            animation: cmp-nudge 2s ease-in-out infinite;
        }
        @keyframes cmp-nudge {
            0%, 100% { transform: translateX(0); }
            50% { transform: translateX(6px); }
        }

        /* ── Key differentiators ──────────────────────────────────── */
        .cmp-diff-section {
            padding: 80px 20px;
            background: #f7fafc;
        }
        .cmp-diff-section h2 {
            text-align: center;
            color: #1a365d;
            font-size: 1.8rem;
            margin-bottom: 40px;
        }
        .cmp-diff-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 28px;
            max-width: 960px;
            margin: 0 auto;
        }
        .cmp-diff-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 28px 24px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .cmp-diff-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        }
        .cmp-diff-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #dbeafe;
            color: #2563eb;
            font-size: 1.4rem;
            margin-bottom: 16px;
        }
        .cmp-diff-card h3 {
            color: #1a365d;
            font-size: 1.1rem;
            margin-bottom: 8px;
        }
        .cmp-diff-card p {
            color: #4a5568;
            font-size: 0.92rem;
            line-height: 1.6;
        }

        /* ── FAQ section ──────────────────────────────────────────── */
        .cmp-faq-section {
            padding: 80px 20px;
            background: #ffffff;
        }
        .cmp-faq-section h2 {
            text-align: center;
            color: #1a365d;
            font-size: 1.8rem;
            margin-bottom: 40px;
        }
        .cmp-faq-list {
            max-width: 720px;
            margin: 0 auto;
            list-style: none;
            padding: 0;
        }
        .cmp-faq-item {
            border-bottom: 1px solid #e2e8f0;
        }
        .cmp-faq-item:last-child {
            border-bottom: none;
        }
        .cmp-faq-q {
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 0;
            background: none;
            border: none;
            cursor: pointer;
            text-align: left;
            font-size: 1.05rem;
            font-weight: 600;
            color: #1a365d;
            line-height: 1.4;
            -webkit-tap-highlight-color: transparent;
        }
        .cmp-faq-q:hover {
            color: #2563eb;
        }
        .cmp-faq-q:focus-visible {
            outline: 2px solid #63b3ed;
            outline-offset: 2px;
        }
        .cmp-faq-chevron {
            flex-shrink: 0;
            width: 20px;
            height: 20px;
            margin-left: 16px;
            transition: transform 0.25s ease;
            color: #a0aec0;
        }
        .cmp-faq-item.open .cmp-faq-chevron {
            transform: rotate(180deg);
            color: #2563eb;
        }
        .cmp-faq-a {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease, padding 0.3s ease;
            padding: 0 0;
        }
        .cmp-faq-item.open .cmp-faq-a {
            max-height: 400px;
            padding: 0 0 20px;
        }
        .cmp-faq-a p {
            color: #4a5568;
            font-size: 0.95rem;
            line-height: 1.7;
            margin: 0;
        }

        /* ── Footer CTA ───────────────────────────────────────────── */
        .cmp-footer-cta {
            padding: 80px 20px;
            background: #1a365d;
            color: #ffffff;
            text-align: center;
        }
        .cmp-footer-cta h2 {
            font-size: 1.8rem;
            margin-bottom: 12px;
        }
        .cmp-footer-cta .subtitle {
            opacity: 0.8;
            font-size: 1rem;
            margin-bottom: 28px;
            max-width: 540px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.6;
        }
        .cmp-footer-cta .cta-button {
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
        .cmp-footer-cta .cta-button:hover {
            background: #c44d1e;
            text-decoration: none;
            transform: translateY(-1px);
        }
        .cmp-footer-cta .cta-note {
            margin-top: 16px;
            opacity: 0.6;
            font-size: 0.88rem;
        }

        /* ── Reviews section ─────────────────────────────────────── */
        .cmp-reviews-section {
            padding: 80px 20px;
            background: #ffffff;
        }
        .cmp-reviews-section h2 {
            text-align: center;
            color: #1a365d;
            font-size: 1.8rem;
            margin-bottom: 12px;
        }
        .cmp-reviews-subtitle {
            text-align: center;
            color: #4a5568;
            font-size: 1rem;
            margin-bottom: 48px;
            max-width: 620px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.6;
        }
        .cmp-review-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 32px 36px;
            max-width: 800px;
            margin: 0 auto 28px;
            transition: box-shadow 0.2s ease;
        }
        .cmp-review-card:last-child {
            margin-bottom: 0;
        }
        .cmp-review-card:hover {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        }
        .cmp-review-card.cmp-review-highlight {
            border-color: #dbeafe;
            border-width: 2px;
            background: #fafcff;
        }
        .cmp-review-card h3 {
            color: #1a365d;
            font-size: 1.25rem;
            margin-bottom: 16px;
            font-weight: 700;
        }
        .cmp-review-card h3 .cmp-review-badge {
            display: inline-block;
            background: #e05d26;
            color: #ffffff;
            font-size: 0.6rem;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 8px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-left: 10px;
            vertical-align: middle;
            position: relative;
            top: -1px;
        }
        .cmp-review-detail {
            margin-bottom: 12px;
            font-size: 0.94rem;
            line-height: 1.7;
            color: #4a5568;
        }
        .cmp-review-detail:last-child {
            margin-bottom: 0;
        }
        .cmp-review-label {
            font-weight: 700;
            color: #1a365d;
        }
        .cmp-review-verdict {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #edf2f7;
            font-size: 0.94rem;
            line-height: 1.7;
            color: #2d3748;
            font-weight: 600;
        }
        .cmp-review-verdict .cmp-review-label {
            color: #2563eb;
        }

        /* ── Responsive ───────────────────────────────────────────── */
        @media (max-width: 768px) {
            .cmp-hero h1 {
                font-size: 1.7rem;
            }
            .cmp-scroll-hint {
                display: block;
            }
            .cmp-diff-grid {
                grid-template-columns: 1fr;
                max-width: 400px;
                margin: 0 auto;
            }
            .cmp-review-card {
                padding: 24px 20px;
            }
        }
        @media (max-width: 480px) {
            .cmp-hero h1 {
                font-size: 1.4rem;
            }
            .cmp-table {
                font-size: 0.82rem;
            }
            .cmp-table thead th,
            .cmp-table tbody td {
                padding: 10px 10px;
            }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/consent-banner.php'; ?>
<a href="#main-content" class="skip-link">Skip to main content</a>

<?php
$headerCta   = ['text' => t('cmp.header_cta'), 'href' => '/scan'];
$headerTheme = 'light';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ── Hero ─────────────────────────────────────────────────────────────── -->
<header class="cmp-hero" style="padding-top: 0;">
    <div class="cmp-hero-body">
        <h1><?= t('cmp.hero_h1') ?></h1>
        <p class="subtitle"><?= t('cmp.hero_subtitle') ?></p>
    </div>
</header>

<!-- ── Main Content ────────────────────────────────────────────────────── -->
<main id="main-content">

    <!-- Comparison table -->
    <section class="cmp-table-section">
        <h2><?= t('cmp.table_h2') ?></h2>
        <p class="section-subtitle"><?= t('cmp.table_subtitle') ?></p>
        <div class="cmp-table-wrap">
            <p class="cmp-scroll-hint"><?= t('cmp.scroll_hint') ?></p>
            <table class="cmp-table">
                <thead>
                    <tr>
                        <th scope="col"><?= t('cmp.th_feature') ?></th>
                        <th scope="col" class="cmp-ours">Audit&amp;Fix CRO&nbsp;Audit</th>
                        <th scope="col">Hotjar</th>
                        <th scope="col">CrazyEgg</th>
                        <th scope="col">Google Lighthouse</th>
                        <th scope="col"><?= t('cmp.th_diy') ?></th>
                        <th scope="col"><?= t('cmp.th_freelancer') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?= t('cmp.row_price') ?></td>
                        <td class="cmp-ours"><?= t('cmp.row_price_ours', ['price' => $priceFormatted]) ?></td>
                        <td><?= t('cmp.row_price_hotjar') ?></td>
                        <td><?= t('cmp.row_price_crazyegg') ?></td>
                        <td><?= t('cmp.row_price_lighthouse') ?></td>
                        <td><?= t('cmp.row_price_diy') ?></td>
                        <td><?= t('cmp.row_price_freelancer') ?></td>
                    </tr>
                    <tr>
                        <td><?= t('cmp.row_turnaround') ?></td>
                        <td class="cmp-ours"><span class="cmp-yes"><?= t('cmp.row_turnaround_ours') ?></span></td>
                        <td><?= t('cmp.row_turnaround_hotjar') ?></td>
                        <td><?= t('cmp.row_turnaround_crazyegg') ?></td>
                        <td><?= t('cmp.row_turnaround_lighthouse') ?></td>
                        <td><?= t('cmp.row_turnaround_diy') ?></td>
                        <td><?= t('cmp.row_turnaround_freelancer') ?></td>
                    </tr>
                    <tr>
                        <td><?= t('cmp.row_screenshots') ?></td>
                        <td class="cmp-ours"><span class="cmp-yes"><?= t('cmp.row_screenshots_ours') ?></span></td>
                        <td><span class="cmp-partial"><?= t('cmp.row_screenshots_hotjar') ?></span></td>
                        <td><span class="cmp-partial"><?= t('cmp.row_screenshots_crazyegg') ?></span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-partial"><?= t('cmp.row_screenshots_freelancer') ?></span></td>
                    </tr>
                    <tr>
                        <td><?= t('cmp.row_recommendations') ?></td>
                        <td class="cmp-ours"><span class="cmp-yes"><?= t('cmp.row_recommendations_ours') ?></span></td>
                        <td><span class="cmp-no"><?= t('cmp.row_recommendations_hotjar') ?></span></td>
                        <td><span class="cmp-no"><?= t('cmp.row_recommendations_crazyegg') ?></span></td>
                        <td><span class="cmp-partial"><?= t('cmp.row_recommendations_lighthouse') ?></span></td>
                        <td><span class="cmp-partial"><?= t('cmp.row_recommendations_diy') ?></span></td>
                        <td><span class="cmp-yes">&#10003;</span></td>
                    </tr>
                    <tr>
                        <td><?= t('cmp.row_implementation') ?></td>
                        <td class="cmp-ours"><span class="cmp-yes"><?= t('cmp.row_implementation_ours') ?></span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-partial"><?= t('cmp.row_implementation_lighthouse') ?></span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-partial"><?= t('cmp.row_implementation_freelancer') ?></span></td>
                    </tr>
                    <tr>
                        <td><?= t('cmp.row_scoring') ?></td>
                        <td class="cmp-ours"><span class="cmp-yes"><?= t('cmp.row_scoring_ours') ?></span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-no"><?= t('cmp.row_scoring_lighthouse') ?></span></td>
                        <td><span class="cmp-partial"><?= t('cmp.row_scoring_diy') ?></span></td>
                        <td><span class="cmp-partial"><?= t('cmp.row_scoring_freelancer') ?></span></td>
                    </tr>
                    <tr>
                        <td><?= t('cmp.row_guarantee') ?></td>
                        <td class="cmp-ours"><span class="cmp-yes"><?= t('cmp.row_guarantee_ours') ?></span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><?= t('cmp.row_guarantee_lighthouse') ?></td>
                        <td><?= t('cmp.row_guarantee_diy') ?></td>
                        <td><span class="cmp-no"><?= t('cmp.row_guarantee_freelancer') ?></span></td>
                    </tr>
                    <tr>
                        <td><?= t('cmp.row_nocode') ?></td>
                        <td class="cmp-ours"><span class="cmp-yes"><?= t('cmp.row_nocode_ours') ?></span></td>
                        <td><span class="cmp-no"><?= t('cmp.row_nocode_hotjar') ?></span></td>
                        <td><span class="cmp-no"><?= t('cmp.row_nocode_crazyegg') ?></span></td>
                        <td><span class="cmp-yes">&#10003;</span></td>
                        <td><span class="cmp-yes">&#10003;</span></td>
                        <td><span class="cmp-partial"><?= t('cmp.row_nocode_freelancer') ?></span></td>
                    </tr>
                    <tr>
                        <td><?= t('cmp.row_monitoring') ?></td>
                        <td class="cmp-ours"><span class="cmp-yes"><?= t('cmp.row_monitoring_ours') ?></span></td>
                        <td><span class="cmp-yes"><?= t('cmp.row_monitoring_hotjar') ?></span></td>
                        <td><span class="cmp-yes"><?= t('cmp.row_monitoring_crazyegg') ?></span></td>
                        <td><span class="cmp-yes"><?= t('cmp.row_monitoring_lighthouse') ?></span></td>
                        <td><span class="cmp-partial"><?= t('cmp.row_monitoring_diy') ?></span></td>
                        <td><span class="cmp-partial"><?= t('cmp.row_monitoring_freelancer') ?></span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Key differentiators -->
    <section class="cmp-diff-section">
        <h2><?= t('cmp.diff_h2') ?></h2>
        <div class="cmp-diff-grid">
            <div class="cmp-diff-card">
                <div class="cmp-diff-icon" aria-hidden="true">&#128247;</div>
                <h3><?= t('cmp.diff_screenshots_title') ?></h3>
                <p><?= t('cmp.diff_screenshots_body') ?></p>
            </div>
            <div class="cmp-diff-card">
                <div class="cmp-diff-icon" aria-hidden="true">&#127919;</div>
                <h3><?= t('cmp.diff_conversion_title') ?></h3>
                <p><?= t('cmp.diff_conversion_body') ?></p>
            </div>
            <div class="cmp-diff-card">
                <div class="cmp-diff-icon" aria-hidden="true">&#9889;</div>
                <h3><?= t('cmp.diff_nocode_title') ?></h3>
                <p><?= t('cmp.diff_nocode_body') ?></p>
            </div>
            <div class="cmp-diff-card">
                <div class="cmp-diff-icon" aria-hidden="true">&#128203;</div>
                <h3><?= t('cmp.diff_fixlist_title') ?></h3>
                <p><?= t('cmp.diff_fixlist_body') ?></p>
            </div>
            <div class="cmp-diff-card">
                <div class="cmp-diff-icon" aria-hidden="true">&#128176;</div>
                <h3><?= t('cmp.diff_price_title') ?></h3>
                <p><?= t('cmp.diff_price_body') ?></p>
            </div>
            <div class="cmp-diff-card">
                <div class="cmp-diff-icon" aria-hidden="true">&#128170;</div>
                <h3><?= t('cmp.diff_guarantee_title') ?></h3>
                <p><?= t('cmp.diff_guarantee_body') ?></p>
            </div>
        </div>
    </section>

    <!-- Detailed reviews of each option -->
    <section class="cmp-reviews-section">
        <h2><?= t('cmp.reviews_h2') ?></h2>
        <p class="cmp-reviews-subtitle"><?= t('cmp.reviews_subtitle') ?></p>

        <!-- Audit&Fix CRO Audit -->
        <div class="cmp-review-card cmp-review-highlight">
            <h3>Audit&amp;Fix CRO Audit <span class="cmp-review-badge"><?= t('cmp.review_badge') ?></span></h3>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_what') ?></span> <?= t('cmp.review_af_what') ?></p>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_how') ?></span> <?= t('cmp.review_af_how') ?></p>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_best_for') ?></span> <?= t('cmp.review_af_best') ?></p>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_limitation') ?></span> <?= t('cmp.review_af_limitation') ?></p>
            <p class="cmp-review-verdict"><span class="cmp-review-label"><?= t('cmp.review_label_verdict') ?></span> <?= t('cmp.review_af_verdict', ['price' => $priceFormatted]) ?></p>
        </div>

        <!-- Hotjar -->
        <div class="cmp-review-card">
            <h3>Hotjar</h3>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_what') ?></span> <?= t('cmp.review_hotjar_what') ?></p>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_how') ?></span> <?= t('cmp.review_hotjar_how') ?></p>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_best_for') ?></span> <?= t('cmp.review_hotjar_best') ?></p>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_limitation') ?></span> <?= t('cmp.review_hotjar_limitation') ?></p>
            <p class="cmp-review-verdict"><span class="cmp-review-label"><?= t('cmp.review_label_verdict') ?></span> <?= t('cmp.review_hotjar_verdict') ?></p>
        </div>

        <!-- CrazyEgg -->
        <div class="cmp-review-card">
            <h3>CrazyEgg</h3>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_what') ?></span> <?= t('cmp.review_crazyegg_what') ?></p>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_how') ?></span> <?= t('cmp.review_crazyegg_how') ?></p>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_best_for') ?></span> <?= t('cmp.review_crazyegg_best') ?></p>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_limitation') ?></span> <?= t('cmp.review_crazyegg_limitation') ?></p>
            <p class="cmp-review-verdict"><span class="cmp-review-label"><?= t('cmp.review_label_verdict') ?></span> <?= t('cmp.review_crazyegg_verdict') ?></p>
        </div>

        <!-- Google Lighthouse -->
        <div class="cmp-review-card">
            <h3>Google Lighthouse</h3>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_what') ?></span> <?= t('cmp.review_lighthouse_what') ?></p>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_how') ?></span> <?= t('cmp.review_lighthouse_how') ?></p>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_best_for') ?></span> <?= t('cmp.review_lighthouse_best') ?></p>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_limitation') ?></span> <?= t('cmp.review_lighthouse_limitation') ?></p>
            <p class="cmp-review-verdict"><span class="cmp-review-label"><?= t('cmp.review_label_verdict') ?></span> <?= t('cmp.review_lighthouse_verdict') ?></p>
        </div>

        <!-- DIY Audit -->
        <div class="cmp-review-card">
            <h3><?= t('cmp.th_diy') ?></h3>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_what') ?></span> <?= t('cmp.review_diy_what') ?></p>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_how') ?></span> <?= t('cmp.review_diy_how') ?></p>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_best_for') ?></span> <?= t('cmp.review_diy_best') ?></p>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_limitation') ?></span> <?= t('cmp.review_diy_limitation') ?></p>
            <p class="cmp-review-verdict"><span class="cmp-review-label"><?= t('cmp.review_label_verdict') ?></span> <?= t('cmp.review_diy_verdict') ?></p>
        </div>

        <!-- Hiring a Freelancer -->
        <div class="cmp-review-card">
            <h3><?= t('cmp.th_freelancer') ?></h3>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_what') ?></span> <?= t('cmp.review_freelancer_what') ?></p>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_how') ?></span> <?= t('cmp.review_freelancer_how') ?></p>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_best_for') ?></span> <?= t('cmp.review_freelancer_best') ?></p>
            <p class="cmp-review-detail"><span class="cmp-review-label"><?= t('cmp.review_label_limitation') ?></span> <?= t('cmp.review_freelancer_limitation') ?></p>
            <p class="cmp-review-verdict"><span class="cmp-review-label"><?= t('cmp.review_label_verdict') ?></span> <?= t('cmp.review_freelancer_verdict') ?></p>
        </div>
    </section>

    <!-- FAQ -->
    <section class="cmp-faq-section">
        <h2><?= t('cmp.faq_h2') ?></h2>
        <ul class="cmp-faq-list" role="list">
            <li class="cmp-faq-item">
                <button class="cmp-faq-q" type="button" aria-expanded="false">
                    <span><?= t('cmp.faq1_q') ?></span>
                    <svg class="cmp-faq-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                </button>
                <div class="cmp-faq-a" aria-hidden="true">
                    <p><?= t('cmp.faq1_a') ?></p>
                </div>
            </li>
            <li class="cmp-faq-item">
                <button class="cmp-faq-q" type="button" aria-expanded="false">
                    <span><?= t('cmp.faq2_q') ?></span>
                    <svg class="cmp-faq-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                </button>
                <div class="cmp-faq-a" aria-hidden="true">
                    <p>Lighthouse measures page speed, accessibility, and SEO &mdash; useful technical metrics, but none of them tell you whether your headline is persuasive, your call-to-action is visible, or your trust signals are in the right place. Our audit analyses the actual visual page the way a real visitor sees it: layout, copy, trust signals, and user flow. It answers "why aren't people converting?" rather than "how fast does the page load?"</p>
                </div>
            </li>
            <li class="cmp-faq-item">
                <button class="cmp-faq-q" type="button" aria-expanded="false">
                    <span>Do I need to install any tracking code?</span>
                    <svg class="cmp-faq-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                </button>
                <div class="cmp-faq-a" aria-hidden="true">
                    <p>No. We work from your live URL &mdash; no scripts, no tracking pixels, no cookie banners required. You receive a finished PDF report without touching your site at all.</p>
                </div>
            </li>
            <li class="cmp-faq-item">
                <button class="cmp-faq-q" type="button" aria-expanded="false">
                    <span>How quickly do I get my report?</span>
                    <svg class="cmp-faq-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                </button>
                <div class="cmp-faq-a" aria-hidden="true">
                    <p>Reports are delivered to the email you provide within 24 hours of payment. Most are ready within a few hours during business hours (AEST).</p>
                </div>
            </li>
            <li class="cmp-faq-item">
                <button class="cmp-faq-q" type="button" aria-expanded="false">
                    <span>What does the report actually include?</span>
                    <svg class="cmp-faq-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                </button>
                <div class="cmp-faq-a" aria-hidden="true">
                    <p>For each of the 10 conversion factors, you get: a score (0&ndash;10), a grade (A+ to F), a plain-English explanation of what's wrong, a specific recommendation, and an annotated screenshot showing the exact problem area on your page. You also get an overall conversion score and a prioritised list of fixes ranked by impact.</p>
                </div>
            </li>
            <li class="cmp-faq-item">
                <button class="cmp-faq-q" type="button" aria-expanded="false">
                    <span>Is there a money-back guarantee?</span>
                    <svg class="cmp-faq-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                </button>
                <div class="cmp-faq-a" aria-hidden="true">
                    <p>Yes &mdash; 30-day money-back guarantee, no questions asked. If the report doesn't surface useful insights for your business, you get a full refund.</p>
                </div>
            </li>
            <li class="cmp-faq-item">
                <button class="cmp-faq-q" type="button" aria-expanded="false">
                    <span>Do you implement the fixes?</span>
                    <svg class="cmp-faq-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                </button>
                <div class="cmp-faq-a" aria-hidden="true">
                    <p>The standard CRO Audit report identifies and prioritises the issues &mdash; implementation is up to you or your developer. Most quick wins take under an hour to fix. If you'd prefer us to handle it, our Audit + Implementation package (<?= htmlspecialchars($auditFixFormatted) ?>) includes hands-on fixes.</p>
                </div>
            </li>
            <li class="cmp-faq-item">
                <button class="cmp-faq-q" type="button" aria-expanded="false">
                    <span>Can I re-audit after making changes?</span>
                    <svg class="cmp-faq-chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                </button>
                <div class="cmp-faq-a" aria-hidden="true">
                    <p>Yes. Once you've implemented fixes, you can order a follow-up benchmarking audit at 50% of the original price to measure how much your conversion score improved. It's entirely optional &mdash; there's no ongoing commitment.</p>
                </div>
            </li>
        </ul>
    </section>

    <!-- Footer CTA -->
    <section class="cmp-footer-cta">
        <div class="container">
            <h2>Ready to find out what's costing you conversions?</h2>
            <p class="subtitle">Get a free website score in under 60 seconds. No sign-up required &mdash; just enter your URL.</p>
            <a href="/scan" class="cta-button">Get Your Free Score &rarr;</a>
            <p class="cta-note">Free. No credit card. No tracking code to install.</p>
        </div>
    </section>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="/assets/js/obfuscate-email.js?v=1" defer></script>
<script>
// FAQ accordion
(function() {
    'use strict';
    var items = document.querySelectorAll('.cmp-faq-item');
    for (var i = 0; i < items.length; i++) {
        (function(item) {
            var btn = item.querySelector('.cmp-faq-q');
            var answer = item.querySelector('.cmp-faq-a');
            if (!btn || !answer) return;

            btn.addEventListener('click', function() {
                var isOpen = item.classList.contains('open');

                // Close all others
                for (var j = 0; j < items.length; j++) {
                    items[j].classList.remove('open');
                    var otherBtn = items[j].querySelector('.cmp-faq-q');
                    var otherAns = items[j].querySelector('.cmp-faq-a');
                    if (otherBtn) otherBtn.setAttribute('aria-expanded', 'false');
                    if (otherAns) otherAns.setAttribute('aria-hidden', 'true');
                }

                // Toggle current
                if (!isOpen) {
                    item.classList.add('open');
                    btn.setAttribute('aria-expanded', 'true');
                    answer.setAttribute('aria-hidden', 'false');
                }
            });
        })(items[i]);
    }
})();
</script>
</body>
</html>
