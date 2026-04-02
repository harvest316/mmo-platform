<?php
/**
 * Competitor Comparison — /video-reviews/compare
 *
 * Side-by-side comparison of Audit&Fix Video Reviews vs competitors.
 * Geo-detected pricing throughout. Linked from pricing cards on all pages.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/geo.php';
require_once __DIR__ . '/../includes/pricing.php';

$countryCode  = detectCountry();
$videoPricing = get2StepPriceForCountry($countryCode);
$symbol       = $videoPricing['symbol'];
$price4       = $videoPricing['monthly_4'];
$price8       = $videoPricing['monthly_8'];
$price12      = $videoPricing['monthly_12'];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(t('vcmp.page_title')) ?></title>
    <meta name="description" content="<?= htmlspecialchars(t('vcmp.page_description')) ?>">
    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/assets/img/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="canonical" href="https://www.auditandfix.com/video-reviews/compare">
    <meta property="og:title" content="<?= htmlspecialchars(t('vcmp.og_title')) ?>">
    <meta property="og:description" content="<?= htmlspecialchars(t('vcmp.og_description')) ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://www.auditandfix.com/video-reviews/compare">
    <meta property="og:image" content="https://www.auditandfix.com/assets/img/og-image.png">
    <meta property="og:site_name" content="Audit&amp;Fix">
    <meta name="twitter:card" content="summary_large_image">

    <!-- Schema.org structured data -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebPage",
      "name": "How We Compare to Other Video Review Services",
      "description": "Side-by-side comparison of Audit&Fix Video Reviews versus Testimonial.to, Vocal Video, Widewail, and DIY tools like InVideo and HeyGen.",
      "url": "https://www.auditandfix.com/video-reviews/compare",
      "mainEntity": {
        "@type": "Table",
        "about": "Video review service comparison"
      },
      "breadcrumb": {
        "@type": "BreadcrumbList",
        "itemListElement": [
          {"@type": "ListItem", "position": 1, "name": "Home", "item": "https://www.auditandfix.com/"},
          {"@type": "ListItem", "position": 2, "name": "Video Reviews", "item": "https://www.auditandfix.com/video-reviews/"},
          {"@type": "ListItem", "position": 3, "name": "Compare"}
        ]
      },
      "provider": {
        "@type": "Organization",
        "name": "Audit&Fix",
        "url": "https://www.auditandfix.com/"
      }
    }
    </script>

    <style>
        /* ── Compare page styles ──────────────────────────────────── */

        /* Hero */
        .cmp-hero {
            background: linear-gradient(135deg, #1a365d 0%, #2d5fa3 50%, #1a365d 100%);
            color: #ffffff;
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
        }
        .cmp-hero .subtitle {
            font-size: 1.1rem;
            opacity: 0.85;
            line-height: 1.6;
            max-width: 600px;
            margin: 0 auto;
        }

        /* ── Comparison table section ─────────────────────────────── */
        .cmp-table-section {
            padding: 60px 20px 80px;
            background: #f8fafc;
        }
        .cmp-table-wrap {
            max-width: 1080px;
            margin: 0 auto;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 6px 24px rgba(0,0,0,0.06);
            background: #ffffff;
        }
        .cmp-table {
            width: 100%;
            min-width: 780px;
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

        /* ── Competitor reviews section ───────────────────────────── */
        .cmp-reviews-section {
            padding: 80px 20px;
            background: #ffffff;
        }
        .cmp-reviews-section h2 {
            text-align: center;
            color: #1a365d;
            font-size: 1.8rem;
            margin-bottom: 48px;
        }
        .cmp-review-card {
            max-width: 800px;
            margin: 0 auto 32px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 36px 36px 32px;
            transition: box-shadow 0.2s ease;
        }
        .cmp-review-card:last-child {
            margin-bottom: 0;
        }
        .cmp-review-card:hover {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        }
        .cmp-review-card h3 {
            color: #1a365d;
            font-size: 1.3rem;
            margin-bottom: 20px;
            font-weight: 700;
        }
        .cmp-review-card dl {
            margin: 0;
        }
        .cmp-review-card dt {
            color: #2d3748;
            font-weight: 700;
            font-size: 0.88rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-top: 16px;
            margin-bottom: 4px;
        }
        .cmp-review-card dt:first-child {
            margin-top: 0;
        }
        .cmp-review-card dd {
            color: #4a5568;
            font-size: 0.95rem;
            line-height: 1.65;
            margin: 0 0 0 0;
        }
        .cmp-review-verdict {
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #edf2f7;
            color: #1a365d;
            font-size: 0.95rem;
            font-weight: 600;
            line-height: 1.55;
        }
        .cmp-review-card.cmp-review-ours {
            border-color: #bfdbfe;
            background: #f8fbff;
        }

        @media (max-width: 768px) {
            .cmp-review-card {
                padding: 24px 20px 20px;
            }
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
            max-width: 900px;
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

        /* ── Responsive ───────────────────────────────────────────── */
        @media (max-width: 768px) {
            .cmp-hero h1 {
                font-size: 1.7rem;
            }
            .cmp-scroll-hint {
                display: block;
            }
            .cmp-table-wrap {
                border-radius: 8px;
            }
            .cmp-diff-grid {
                grid-template-columns: 1fr;
                max-width: 400px;
                margin: 0 auto;
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
                padding: 12px 10px;
            }
            .cmp-table thead th:first-child {
                border-radius: 8px 0 0 0;
            }
            .cmp-table thead th:last-child {
                border-radius: 0 8px 0 0;
            }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/consent-banner.php'; ?>
<a href="#main-content" class="skip-link"><?= t('vcmp.skip_link') ?></a>

<?php
$headerCta   = ['text' => t('vcmp.header_cta'), 'href' => '/video-reviews/'];
$headerTheme = 'light';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ── Hero ─────────────────────────────────────────────────────────────── -->
<header class="cmp-hero" style="padding-top: 0;">

    <div class="cmp-hero-body">
        <h1><?= t('vcmp.hero_h1') ?></h1>
        <p class="subtitle"><?= t('vcmp.hero_subtitle') ?></p>
    </div>
</header>

<!-- ── Main Content ────────────────────────────────────────────────────── -->
<main id="main-content">

    <!-- Comparison table -->
    <section class="cmp-table-section">
        <div class="cmp-table-wrap">
            <p class="cmp-scroll-hint"><?= t('vcmp.scroll_hint') ?></p>
            <table class="cmp-table">
                <thead>
                    <tr>
                        <th scope="col"><?= t('vcmp.th_feature') ?></th>
                        <th scope="col" class="cmp-ours">Audit&amp;Fix Video&nbsp;Reviews</th>
                        <th scope="col">Testimonial.to</th>
                        <th scope="col">Vocal Video</th>
                        <th scope="col">Widewail</th>
                        <th scope="col"><?= t('vcmp.th_diy') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?= t('vcmp.row_price') ?></td>
                        <td class="cmp-ours"><?= t('vcmp.row_price_ours', ['symbol' => $symbol, 'price4' => $price4, 'price12' => $price12]) ?></td>
                        <td><?= t('vcmp.row_price_testimonial') ?></td>
                        <td><?= t('vcmp.row_price_vocal') ?></td>
                        <td><?= t('vcmp.row_price_widewail') ?></td>
                        <td><?= t('vcmp.row_price_diy') ?></td>
                    </tr>
                    <tr>
                        <td><?= t('vcmp.row_setup') ?></td>
                        <td class="cmp-ours"><span class="cmp-yes"><?= t('vcmp.row_setup_ours') ?></span></td>
                        <td><?= t('vcmp.row_setup_others') ?></td>
                        <td><?= t('vcmp.row_setup_others') ?></td>
                        <td><?= t('vcmp.row_setup_others') ?></td>
                        <td><?= t('vcmp.row_setup_others') ?></td>
                    </tr>
                    <tr>
                        <td><?= t('vcmp.row_effort') ?></td>
                        <td class="cmp-ours"><span class="cmp-yes"><?= t('vcmp.row_effort_ours') ?></span> <?= t('vcmp.row_effort_ours_detail') ?></td>
                        <td><?= t('vcmp.row_effort_testimonial') ?></td>
                        <td><?= t('vcmp.row_effort_vocal') ?></td>
                        <td><?= t('vcmp.row_effort_widewail') ?></td>
                        <td><?= t('vcmp.row_effort_diy') ?></td>
                    </tr>
                    <tr>
                        <td><?= t('vcmp.row_google') ?></td>
                        <td class="cmp-ours"><span class="cmp-yes"><?= t('vcmp.row_google_ours') ?></span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                    </tr>
                    <tr>
                        <td><?= t('vcmp.row_voiceover') ?></td>
                        <td class="cmp-ours"><span class="cmp-yes"><?= t('vcmp.row_voiceover_ours') ?></span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-partial"><?= t('vcmp.row_voiceover_diy') ?></span></td>
                    </tr>
                    <tr>
                        <td><?= t('vcmp.row_clips') ?></td>
                        <td class="cmp-ours"><span class="cmp-yes"><?= t('vcmp.row_clips_ours') ?></span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-partial"><?= t('vcmp.row_clips_diy') ?></span></td>
                    </tr>
                    <tr>
                        <td><?= t('vcmp.row_videos_month') ?></td>
                        <td class="cmp-ours"><?= t('vcmp.row_videos_ours') ?></td>
                        <td><?= t('vcmp.row_videos_testimonial') ?></td>
                        <td><?= t('vcmp.row_videos_vocal') ?></td>
                        <td><?= t('vcmp.row_videos_widewail') ?></td>
                        <td><?= t('vcmp.row_videos_diy') ?></td>
                    </tr>
                    <tr>
                        <td><?= t('vcmp.row_time') ?></td>
                        <td class="cmp-ours"><span class="cmp-yes"><?= t('vcmp.row_time_ours') ?></span></td>
                        <td><?= t('vcmp.row_time_testimonial') ?></td>
                        <td><?= t('vcmp.row_time_vocal') ?></td>
                        <td><?= t('vcmp.row_time_widewail') ?></td>
                        <td><?= t('vcmp.row_time_diy') ?></td>
                    </tr>
                    <tr>
                        <td><?= t('vcmp.row_cancel') ?></td>
                        <td class="cmp-ours"><span class="cmp-yes">&#10003;</span></td>
                        <td><span class="cmp-yes">&#10003;</span></td>
                        <td><?= t('vcmp.row_cancel_vocal') ?></td>
                        <td><?= t('vcmp.row_cancel_widewail') ?></td>
                        <td><span class="cmp-yes">&#10003;</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Competitor reviews -->
    <section class="cmp-reviews-section">
        <h2><?= t('vcmp.reviews_h2') ?></h2>

        <div class="cmp-review-card cmp-review-ours">
            <h3>Audit&amp;Fix Video Reviews</h3>
            <dl>
                <dt><?= t('vcmp.review_label_what') ?></dt>
                <dd><?= t('vcmp.review_af_what') ?></dd>

                <dt><?= t('vcmp.review_label_how') ?></dt>
                <dd><?= t('vcmp.review_af_how') ?></dd>

                <dt><?= t('vcmp.review_label_best_for') ?></dt>
                <dd><?= t('vcmp.review_af_best') ?></dd>

                <dt><?= t('vcmp.review_label_limitation') ?></dt>
                <dd><?= t('vcmp.review_af_limitation') ?></dd>
            </dl>
            <p class="cmp-review-verdict"><?= t('vcmp.review_label_verdict') ?> <?= t('vcmp.review_af_verdict') ?></p>
        </div>

        <div class="cmp-review-card">
            <h3>Testimonial.to</h3>
            <dl>
                <dt><?= t('vcmp.review_label_what') ?></dt>
                <dd><?= t('vcmp.review_testimonial_what') ?></dd>

                <dt><?= t('vcmp.review_label_how') ?></dt>
                <dd><?= t('vcmp.review_testimonial_how') ?></dd>

                <dt><?= t('vcmp.review_label_best_for') ?></dt>
                <dd><?= t('vcmp.review_testimonial_best') ?></dd>

                <dt><?= t('vcmp.review_label_limitation') ?></dt>
                <dd><?= t('vcmp.review_testimonial_limitation') ?></dd>
            </dl>
            <p class="cmp-review-verdict"><?= t('vcmp.review_label_verdict') ?> <?= t('vcmp.review_testimonial_verdict') ?></p>
        </div>

        <div class="cmp-review-card">
            <h3>Vocal Video</h3>
            <dl>
                <dt><?= t('vcmp.review_label_what') ?></dt>
                <dd><?= t('vcmp.review_vocal_what') ?></dd>

                <dt><?= t('vcmp.review_label_how') ?></dt>
                <dd><?= t('vcmp.review_vocal_how') ?></dd>

                <dt><?= t('vcmp.review_label_best_for') ?></dt>
                <dd><?= t('vcmp.review_vocal_best') ?></dd>

                <dt><?= t('vcmp.review_label_limitation') ?></dt>
                <dd><?= t('vcmp.review_vocal_limitation') ?></dd>
            </dl>
            <p class="cmp-review-verdict"><?= t('vcmp.review_label_verdict') ?> <?= t('vcmp.review_vocal_verdict') ?></p>
        </div>

        <div class="cmp-review-card">
            <h3>Widewail</h3>
            <dl>
                <dt><?= t('vcmp.review_label_what') ?></dt>
                <dd><?= t('vcmp.review_widewail_what') ?></dd>

                <dt><?= t('vcmp.review_label_how') ?></dt>
                <dd><?= t('vcmp.review_widewail_how') ?></dd>

                <dt><?= t('vcmp.review_label_best_for') ?></dt>
                <dd><?= t('vcmp.review_widewail_best') ?></dd>

                <dt><?= t('vcmp.review_label_limitation') ?></dt>
                <dd><?= t('vcmp.review_widewail_limitation') ?></dd>
            </dl>
            <p class="cmp-review-verdict"><?= t('vcmp.review_label_verdict') ?> <?= t('vcmp.review_widewail_verdict') ?></p>
        </div>

        <div class="cmp-review-card">
            <h3><?= t('vcmp.th_diy') ?></h3>
            <dl>
                <dt><?= t('vcmp.review_label_what') ?></dt>
                <dd><?= t('vcmp.review_diy_what') ?></dd>

                <dt><?= t('vcmp.review_label_how') ?></dt>
                <dd><?= t('vcmp.review_diy_how') ?></dd>

                <dt><?= t('vcmp.review_label_best_for') ?></dt>
                <dd><?= t('vcmp.review_diy_best') ?></dd>

                <dt><?= t('vcmp.review_label_limitation') ?></dt>
                <dd><?= t('vcmp.review_diy_limitation') ?></dd>
            </dl>
            <p class="cmp-review-verdict"><?= t('vcmp.review_label_verdict') ?> <?= t('vcmp.review_diy_verdict') ?></p>
        </div>
    </section>

    <!-- Key differentiators -->
    <section class="cmp-diff-section">
        <h2><?= t('vcmp.diff_h2') ?></h2>
        <div class="cmp-diff-grid">
            <div class="cmp-diff-card">
                <div class="cmp-diff-icon" aria-hidden="true">&#128588;</div>
                <h3><?= t('vcmp.diff_effort_title') ?></h3>
                <p><?= t('vcmp.diff_effort_body') ?></p>
            </div>
            <div class="cmp-diff-card">
                <div class="cmp-diff-icon" aria-hidden="true">&#11088;</div>
                <h3><?= t('vcmp.diff_reviews_title') ?></h3>
                <p><?= t('vcmp.diff_reviews_body') ?></p>
            </div>
            <div class="cmp-diff-card">
                <div class="cmp-diff-icon" aria-hidden="true">&#127908;</div>
                <h3><?= t('vcmp.diff_voiceover_title') ?></h3>
                <p><?= t('vcmp.diff_voiceover_body') ?></p>
            </div>
        </div>
    </section>

    <!-- Footer CTA -->
    <section class="cmp-footer-cta">
        <div class="container">
            <h2><?= t('vcmp.footer_h2') ?></h2>
            <p class="subtitle"><?= t('vcmp.footer_subtitle') ?></p>
            <a href="/video-reviews/#get-video" class="cta-button"><?= t('vcmp.footer_cta') ?></a>
            <p class="cta-note"><?= t('vcmp.footer_note') ?></p>
        </div>
    </section>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/obfuscate-email.js?v=1" defer></script>
</body>
</html>
