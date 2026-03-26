<?php
/**
 * Competitor Comparison — /video-reviews/compare
 *
 * Side-by-side comparison of Audit&Fix Video Reviews vs competitors.
 * Geo-detected pricing throughout. Linked from pricing cards on all pages.
 */

require_once __DIR__ . '/../includes/config.php';
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
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>How We Compare to Other Video Review Services | Audit&amp;Fix</title>
    <meta name="description" content="Side-by-side comparison of Audit&Fix Video Reviews vs Testimonial.to, Vocal Video, Widewail, and DIY tools. See why zero-effort AI video wins.">
    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/assets/img/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="canonical" href="https://www.auditandfix.com/video-reviews/compare">
    <meta property="og:title" content="How we compare to other video review services">
    <meta property="og:description" content="Side-by-side comparison of Audit&Fix Video Reviews vs Testimonial.to, Vocal Video, Widewail, and DIY tools.">
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
            background: #ffffff;
        }
        .cmp-table-wrap {
            max-width: 1080px;
            margin: 0 auto;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .cmp-table {
            width: 100%;
            min-width: 780px;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 0.92rem;
        }
        .cmp-table thead th {
            padding: 16px 14px;
            text-align: left;
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
            vertical-align: bottom;
        }
        .cmp-table thead th:first-child {
            color: #1a365d;
        }
        /* Our column header */
        .cmp-table thead th.cmp-ours {
            background: #eef4ff;
            color: #1a365d;
            border-bottom-color: #2563eb;
            border-radius: 8px 8px 0 0;
            position: relative;
        }
        .cmp-table thead th.cmp-ours::after {
            content: 'Us';
            position: absolute;
            top: -10px;
            left: 50%;
            transform: translateX(-50%);
            background: #2563eb;
            color: #fff;
            font-size: 0.65rem;
            font-weight: 700;
            padding: 2px 10px;
            border-radius: 10px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .cmp-table tbody td {
            padding: 14px 14px;
            border-bottom: 1px solid #f0f4f8;
            color: #4a5568;
            vertical-align: top;
            line-height: 1.5;
        }
        .cmp-table tbody td:first-child {
            font-weight: 600;
            color: #1a365d;
            white-space: nowrap;
        }
        /* Our column cells */
        .cmp-table tbody td.cmp-ours {
            background: #f7faff;
            color: #1a365d;
            font-weight: 600;
        }
        .cmp-table tbody tr:last-child td.cmp-ours {
            border-radius: 0 0 8px 8px;
        }
        .cmp-table tbody tr:hover td {
            background: #fafbfc;
        }
        .cmp-table tbody tr:hover td.cmp-ours {
            background: #eef4ff;
        }
        /* Check / cross icons */
        .cmp-yes {
            color: #38a169;
            font-weight: 700;
        }
        .cmp-no {
            color: #c53030;
        }
        .cmp-partial {
            color: #d69e2e;
        }
        /* Scroll hint on mobile */
        .cmp-scroll-hint {
            display: none;
            text-align: center;
            font-size: 0.82rem;
            color: #a0aec0;
            margin-bottom: 12px;
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
                padding: 10px 10px;
            }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/consent-banner.php'; ?>
<a href="#main-content" class="skip-link">Skip to main content</a>

<?php
$headerCta   = ['text' => 'Get Your Free Video', 'href' => '/video-reviews/'];
$headerTheme = 'light';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ── Hero ─────────────────────────────────────────────────────────────── -->
<header class="cmp-hero" style="padding-top: 0;">

    <div class="cmp-hero-body">
        <h1>How we compare to other video review services</h1>
        <p class="subtitle">Most video testimonial tools need your customers to record themselves. We don't. We turn the Google reviews you already have into professional videos &mdash; automatically.</p>
    </div>
</header>

<!-- ── Main Content ────────────────────────────────────────────────────── -->
<main id="main-content">

    <!-- Comparison table -->
    <section class="cmp-table-section">
        <div class="cmp-table-wrap">
            <p class="cmp-scroll-hint">Scroll sideways to see all providers &rarr;</p>
            <table class="cmp-table">
                <thead>
                    <tr>
                        <th scope="col">Feature</th>
                        <th scope="col" class="cmp-ours">Audit&amp;Fix Video&nbsp;Reviews</th>
                        <th scope="col">Testimonial.to</th>
                        <th scope="col">Vocal Video</th>
                        <th scope="col">Widewail</th>
                        <th scope="col">DIY (InVideo/HeyGen)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Price</td>
                        <td class="cmp-ours"><?= $symbol ?><?= $price4 ?>&ndash;<?= $symbol ?><?= $price12 ?>/mo</td>
                        <td>$20&ndash;80/mo</td>
                        <td>$69&ndash;139/mo</td>
                        <td>$500&ndash;750/mo</td>
                        <td>$29&ndash;99/mo</td>
                    </tr>
                    <tr>
                        <td>Setup fee</td>
                        <td class="cmp-ours"><span class="cmp-yes">$0 (waived)</span></td>
                        <td>$0</td>
                        <td>$0</td>
                        <td>$0</td>
                        <td>$0</td>
                    </tr>
                    <tr>
                        <td>Customer effort required</td>
                        <td class="cmp-ours"><span class="cmp-yes">None</span> &mdash; we use your existing Google&nbsp;reviews</td>
                        <td>Customer must record video</td>
                        <td>Customer records via questionnaire</td>
                        <td>Customer must record video</td>
                        <td>Business owner creates each video</td>
                    </tr>
                    <tr>
                        <td>Uses existing Google&nbsp;reviews</td>
                        <td class="cmp-ours"><span class="cmp-yes">&#10003; Automatic</span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                    </tr>
                    <tr>
                        <td>AI voiceover</td>
                        <td class="cmp-ours"><span class="cmp-yes">&#10003; Professional</span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-partial">&#10003; Basic</span></td>
                    </tr>
                    <tr>
                        <td>Custom video clips</td>
                        <td class="cmp-ours"><span class="cmp-yes">&#10003; Niche-specific B-roll</span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-no">&#10007;</span></td>
                        <td><span class="cmp-partial">&#10003; Stock footage</span></td>
                    </tr>
                    <tr>
                        <td>Videos per month</td>
                        <td class="cmp-ours">4&ndash;12</td>
                        <td>Unlimited text, limited video</td>
                        <td>Varies by plan</td>
                        <td>Varies</td>
                        <td>Unlimited (manual effort)</td>
                    </tr>
                    <tr>
                        <td>Time to first video</td>
                        <td class="cmp-ours"><span class="cmp-yes">~15 minutes</span></td>
                        <td>Days (waiting for customer)</td>
                        <td>Days (waiting for customer)</td>
                        <td>Days (waiting for customer)</td>
                        <td>30&ndash;60 min per video (your time)</td>
                    </tr>
                    <tr>
                        <td>Cancel anytime</td>
                        <td class="cmp-ours"><span class="cmp-yes">&#10003;</span></td>
                        <td><span class="cmp-yes">&#10003;</span></td>
                        <td>Annual billing</td>
                        <td>Custom contract</td>
                        <td><span class="cmp-yes">&#10003;</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Key differentiators -->
    <section class="cmp-diff-section">
        <h2>What sets us apart</h2>
        <div class="cmp-diff-grid">
            <div class="cmp-diff-card">
                <div class="cmp-diff-icon" aria-hidden="true">&#128588;</div>
                <h3>Zero customer effort</h3>
                <p>Every other video testimonial service needs your customers to sit down and record something. That means emails, reminders, and a lot of waiting. We skip all of it &mdash; your Google reviews are the raw material, and we do the rest.</p>
            </div>
            <div class="cmp-diff-card">
                <div class="cmp-diff-icon" aria-hidden="true">&#11088;</div>
                <h3>Uses reviews you already have</h3>
                <p>You've already earned great reviews. They're sitting on Google right now, being read by a few people and ignored by everyone else. We turn them into short, shareable videos that work on every social platform.</p>
            </div>
            <div class="cmp-diff-card">
                <div class="cmp-diff-icon" aria-hidden="true">&#127908;</div>
                <h3>Professional AI voiceover</h3>
                <p>Each video features a natural-sounding AI voiceover that reads the review aloud, paired with niche-specific B-roll footage and background music. The result looks and sounds like it was made by a production studio.</p>
            </div>
        </div>
    </section>

    <!-- Footer CTA -->
    <section class="cmp-footer-cta">
        <div class="container">
            <h2>Ready to see one made for your business?</h2>
            <p class="subtitle">We'll create a free video from one of your best Google reviews. No credit card. No obligation.</p>
            <a href="/video-reviews/#get-video" class="cta-button">Get Your Free Video &rarr;</a>
            <p class="cta-note">Free. No credit card. One video per business.</p>
        </div>
    </section>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script src="/assets/js/obfuscate-email.js?v=1" defer></script>
</body>
</html>
