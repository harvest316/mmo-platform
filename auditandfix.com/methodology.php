<?php
/**
 * Scoring Methodology — /methodology
 *
 * Explains the 10-factor CRO scoring system, how we analyse sites,
 * calibration across 10,000+ websites, and what you get in the report.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/geo.php';
require_once __DIR__ . '/includes/pricing.php';

$countryCode      = detectCountry();
$pricing          = getPricing();
$productPriceData = getProductPriceForCountry($countryCode, 'full_audit');
$priceFormatted   = $productPriceData['formatted'];
$sitesScored      = number_format($pricing['_meta']['sites_scored'] ?? 10000);
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Our Scoring Methodology — How We Score Your Website | Audit&amp;Fix</title>
    <meta name="description" content="Learn how our 10-factor CRO scoring methodology works. Each conversion factor scored 0–10 and graded A+ to F, calibrated across <?= htmlspecialchars($sitesScored) ?>+ real business websites. AI-powered visual analysis with human expert review.">
    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/assets/img/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="canonical" href="https://www.auditandfix.com/methodology">
    <meta property="og:title" content="Our Scoring Methodology — Audit&amp;Fix CRO Audit">
    <meta property="og:description" content="10 conversion factors, each scored 0–10 and graded A+ to F. AI-powered visual analysis calibrated across <?= htmlspecialchars($sitesScored) ?>+ websites, reviewed by a human expert.">
    <meta property="og:type" content="article">
    <meta property="og:url" content="https://www.auditandfix.com/methodology">
    <meta property="og:image" content="https://www.auditandfix.com/assets/img/og-image.png">
    <meta property="og:site_name" content="Audit&amp;Fix">
    <meta name="twitter:card" content="summary_large_image">

    <!-- Schema.org structured data -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@graph": [
        {
          "@type": "TechArticle",
          "@id": "https://www.auditandfix.com/methodology#article",
          "headline": "Our Scoring Methodology — How We Score Your Website",
          "description": "A detailed explanation of the 10-factor CRO scoring methodology used by Audit&Fix. Each conversion factor is scored 0–10 and graded A+ to F, using AI-powered visual analysis calibrated across thousands of real business websites.",
          "url": "https://www.auditandfix.com/methodology",
          "datePublished": "2026-03-29T00:00:00+11:00",
          "dateModified": "2026-03-30T00:00:00+11:00",
          "author": {
            "@type": "Person",
            "name": "Marcus Webb",
            "jobTitle": "CRO Specialist & Marketing Strategist",
            "url": "https://www.auditandfix.com/#marcus-webb"
          },
          "publisher": {
            "@type": "Organization",
            "name": "Audit&Fix",
            "url": "https://www.auditandfix.com/",
            "logo": {
              "@type": "ImageObject",
              "url": "https://www.auditandfix.com/assets/img/logo.svg"
            }
          },
          "mainEntityOfPage": "https://www.auditandfix.com/methodology",
          "image": "https://www.auditandfix.com/assets/img/og-image.png",
          "inLanguage": "en",
          "about": {
            "@type": "Service",
            "name": "CRO Audit Report",
            "provider": {"@type": "Organization", "name": "Audit&Fix"}
          },
          "proficiencyLevel": "Beginner"
        },
        {
          "@type": "BreadcrumbList",
          "itemListElement": [
            {
              "@type": "ListItem",
              "position": 1,
              "name": "Home",
              "item": "https://www.auditandfix.com/"
            },
            {
              "@type": "ListItem",
              "position": 2,
              "name": "Scoring Methodology"
            }
          ]
        }
      ]
    }
    </script>

    <style>
        /* ── Methodology page styles ─────────────────────────────── */

        /* Hero */
        .meth-hero {
            background: linear-gradient(135deg, #f8fafc 0%, #eef2f7 50%, #f0f4f9 100%);
            color: #1a365d;
            padding: 0 0 60px;
        }
        .meth-hero-body {
            max-width: 800px;
            margin: 0 auto;
            padding: 48px 24px 0;
            text-align: center;
        }
        .meth-hero h1 {
            font-size: 2.2rem;
            line-height: 1.2;
            margin-bottom: 16px;
            font-weight: 800;
            color: #1a365d;
        }
        .meth-hero .subtitle {
            font-size: 1.1rem;
            color: #4a5568;
            line-height: 1.6;
            max-width: 640px;
            margin: 0 auto;
        }

        /* ── Shared section patterns ─────────────────────────────── */
        .meth-section {
            padding: 80px 20px;
        }
        .meth-section--alt {
            background: #f8fafc;
        }
        .meth-section--white {
            background: #ffffff;
        }
        .meth-section h2 {
            text-align: center;
            color: #1a365d;
            font-size: 1.8rem;
            margin-bottom: 12px;
        }
        .meth-section-subtitle {
            text-align: center;
            color: #4a5568;
            font-size: 1rem;
            margin-bottom: 40px;
            max-width: 680px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.6;
        }

        /* ── Overview section ────────────────────────────────────── */
        .meth-overview-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 28px;
            max-width: 960px;
            margin: 0 auto;
        }
        .meth-overview-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 28px 24px;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .meth-overview-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        }
        .meth-overview-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: #dbeafe;
            color: #2563eb;
            font-size: 1.5rem;
            margin-bottom: 16px;
            font-weight: 700;
        }
        .meth-overview-card h3 {
            color: #1a365d;
            font-size: 1.1rem;
            margin-bottom: 8px;
        }
        .meth-overview-card p {
            color: #4a5568;
            font-size: 0.92rem;
            line-height: 1.6;
        }

        /* ── Grading scale ───────────────────────────────────────── */
        .meth-grade-table-wrap {
            max-width: 560px;
            margin: 0 auto;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 6px 24px rgba(0,0,0,0.06);
        }
        .meth-grade-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.93rem;
        }
        .meth-grade-table thead th {
            padding: 14px 20px;
            text-align: left;
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: rgba(255,255,255,0.9);
            background: linear-gradient(135deg, #1a365d 0%, #243b6a 100%);
        }
        .meth-grade-table tbody td {
            padding: 12px 20px;
            border-bottom: 1px solid #edf2f7;
            color: #4a5568;
        }
        .meth-grade-table tbody tr:nth-child(even) td {
            background: #f7fafc;
        }
        .meth-grade-table tbody tr:last-child td {
            border-bottom: none;
        }
        .meth-grade-badge {
            display: inline-block;
            font-weight: 700;
            font-size: 0.85rem;
            padding: 3px 10px;
            border-radius: 6px;
            min-width: 36px;
            text-align: center;
        }
        .meth-grade-badge--a { background: #c6f6d5; color: #22543d; }
        .meth-grade-badge--b { background: #d4edda; color: #2d6a4f; }
        .meth-grade-badge--c { background: #fefcbf; color: #744210; }
        .meth-grade-badge--d { background: #fed7aa; color: #7b341e; }
        .meth-grade-badge--f { background: #fed7d7; color: #822727; }

        /* ── 10 Factors section ───────────────────────────────────── */
        .meth-factors-list {
            max-width: 800px;
            margin: 0 auto;
            list-style: none;
            padding: 0;
        }
        .meth-factor-item {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 28px 32px;
            margin-bottom: 20px;
            transition: box-shadow 0.2s ease;
        }
        .meth-factor-item:hover {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        }
        .meth-factor-item:last-child {
            margin-bottom: 0;
        }
        .meth-factor-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 12px;
        }
        .meth-factor-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
            color: #ffffff;
            font-weight: 700;
            font-size: 0.95rem;
            flex-shrink: 0;
        }
        .meth-factor-title {
            color: #1a365d;
            font-size: 1.15rem;
            font-weight: 700;
            margin: 0;
        }
        .meth-factor-body p {
            color: #4a5568;
            font-size: 0.94rem;
            line-height: 1.7;
            margin: 0 0 10px;
        }
        .meth-factor-body p:last-child {
            margin-bottom: 0;
        }
        .meth-factor-label {
            font-weight: 700;
            color: #1a365d;
        }

        /* ── How We Analyse section ──────────────────────────────── */
        .meth-process-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 32px;
            max-width: 880px;
            margin: 0 auto;
        }
        .meth-process-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 32px 28px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .meth-process-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        }
        .meth-process-icon {
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
        .meth-process-card h3 {
            color: #1a365d;
            font-size: 1.1rem;
            margin-bottom: 10px;
        }
        .meth-process-card p {
            color: #4a5568;
            font-size: 0.92rem;
            line-height: 1.6;
            margin: 0;
        }

        /* ── Calibration callout ─────────────────────────────────── */
        .meth-calibration-box {
            max-width: 760px;
            margin: 0 auto;
            background: #ffffff;
            border: 2px solid #dbeafe;
            border-radius: 12px;
            padding: 40px 36px;
            text-align: center;
        }
        .meth-calibration-stat {
            font-size: 3rem;
            font-weight: 800;
            color: #2563eb;
            line-height: 1;
            margin-bottom: 8px;
        }
        .meth-calibration-label {
            font-size: 1rem;
            font-weight: 600;
            color: #1a365d;
            margin-bottom: 20px;
        }
        .meth-calibration-box p {
            color: #4a5568;
            font-size: 0.95rem;
            line-height: 1.7;
            max-width: 600px;
            margin: 0 auto 12px;
        }
        .meth-calibration-box p:last-child {
            margin-bottom: 0;
        }

        /* ── Deliverable section ─────────────────────────────────── */
        .meth-deliverable-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 28px;
            max-width: 880px;
            margin: 0 auto;
        }
        .meth-deliverable-card {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
        }
        .meth-deliverable-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: #dbeafe;
            color: #2563eb;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .meth-deliverable-text h3 {
            color: #1a365d;
            font-size: 1rem;
            margin: 0 0 6px;
        }
        .meth-deliverable-text p {
            color: #4a5568;
            font-size: 0.9rem;
            line-height: 1.6;
            margin: 0;
        }

        /* ── Expert callout ───────────────────────────────────────── */
        .meth-expert-box {
            max-width: 760px;
            margin: 40px auto 0;
            background: #fafcff;
            border: 2px solid #dbeafe;
            border-radius: 12px;
            padding: 28px 32px;
            display: flex;
            align-items: center;
            gap: 24px;
        }
        .meth-expert-photo {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
            border: 3px solid #dbeafe;
        }
        .meth-expert-content p {
            color: #4a5568;
            font-size: 0.92rem;
            line-height: 1.6;
            margin: 0;
        }
        .meth-expert-content strong {
            color: #1a365d;
        }

        /* ── Footer CTA ──────────────────────────────────────────── */
        .meth-footer-cta {
            padding: 80px 20px;
            background: #1a365d;
            color: #ffffff;
            text-align: center;
        }
        .meth-footer-cta h2 {
            font-size: 1.8rem;
            margin-bottom: 12px;
            color: #ffffff;
        }
        .meth-footer-cta .subtitle {
            opacity: 0.8;
            font-size: 1rem;
            margin-bottom: 28px;
            max-width: 540px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.6;
        }
        .meth-footer-cta .cta-button {
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
        .meth-footer-cta .cta-button:hover {
            background: #c44d1e;
            text-decoration: none;
            transform: translateY(-1px);
        }
        .meth-footer-cta .cta-note {
            margin-top: 16px;
            opacity: 0.6;
            font-size: 0.88rem;
        }

        /* ── Responsive ──────────────────────────────────────────── */
        @media (max-width: 768px) {
            .meth-hero h1 {
                font-size: 1.7rem;
            }
            .meth-overview-grid {
                grid-template-columns: 1fr;
                max-width: 400px;
                margin: 0 auto;
            }
            .meth-process-grid {
                grid-template-columns: 1fr;
                max-width: 480px;
                margin: 0 auto;
            }
            .meth-deliverable-grid {
                grid-template-columns: 1fr;
                max-width: 480px;
                margin: 0 auto;
            }
            .meth-factor-item {
                padding: 24px 20px;
            }
            .meth-calibration-box {
                padding: 28px 20px;
            }
            .meth-expert-box {
                flex-direction: column;
                text-align: center;
                padding: 24px 20px;
            }
        }
        @media (max-width: 480px) {
            .meth-hero h1 {
                font-size: 1.4rem;
            }
            .meth-factor-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/consent-banner.php'; ?>
<a href="#main-content" class="skip-link">Skip to main content</a>

<?php
$headerCta   = ['text' => 'Get Your Report', 'href' => '/#order'];
$headerTheme = 'light';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ── Hero ─────────────────────────────────────────────────────────────── -->
<header class="meth-hero" style="padding-top: 0;">
    <div class="meth-hero-body">
        <h1>Our Scoring Methodology</h1>
        <p class="subtitle">How we turn a screenshot of your website into a scored, graded, prioritised audit report — and why every number in it means something.</p>
    </div>
</header>

<!-- ── Main Content ────────────────────────────────────────────────────── -->
<main id="main-content">

    <!-- How the scoring system works -->
    <section class="meth-section meth-section--alt">
        <h2>How the Scoring System Works</h2>
        <p class="meth-section-subtitle">Every website we audit is measured against 10 conversion factors. Each factor gets a score from 0 to 10, a letter grade from A+ to F, and a plain-English explanation of what's working and what isn't.</p>

        <div class="meth-overview-grid">
            <div class="meth-overview-card">
                <div class="meth-overview-icon">10</div>
                <h3>Factors Scored</h3>
                <p>We evaluate 10 distinct conversion factors that determine whether visitors take action or leave. Every factor is scored independently on a 0&ndash;10 scale.</p>
            </div>
            <div class="meth-overview-card">
                <div class="meth-overview-icon">A+</div>
                <h3>Letter Grades</h3>
                <p>Each factor receives a letter grade from A+ (exceptional) to F (critical issue). Grades make it immediately clear where you stand without needing to interpret raw numbers.</p>
            </div>
            <div class="meth-overview-card">
                <div class="meth-overview-icon" style="font-size: 1.2rem;">%</div>
                <h3>Overall Score</h3>
                <p>Your overall CRO score is calculated from all 10 factors combined, giving you a single number that represents your site's conversion readiness out of 100.</p>
            </div>
        </div>

        <!-- Grading scale -->
        <div style="max-width: 800px; margin: 48px auto 0; text-align: center;">
            <h3 style="color: #1a365d; font-size: 1.2rem; margin-bottom: 20px;">The Grading Scale</h3>
            <div class="meth-grade-table-wrap">
                <table class="meth-grade-table">
                    <thead>
                        <tr>
                            <th>Grade</th>
                            <th>Score Range</th>
                            <th>What It Means</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="meth-grade-badge meth-grade-badge--a">A+</span></td>
                            <td>9.5 &ndash; 10</td>
                            <td>Exceptional &mdash; best-in-class execution</td>
                        </tr>
                        <tr>
                            <td><span class="meth-grade-badge meth-grade-badge--a">A</span></td>
                            <td>8.5 &ndash; 9.4</td>
                            <td>Excellent &mdash; minor polish only</td>
                        </tr>
                        <tr>
                            <td><span class="meth-grade-badge meth-grade-badge--b">B+</span></td>
                            <td>7.5 &ndash; 8.4</td>
                            <td>Good &mdash; above average, small tweaks</td>
                        </tr>
                        <tr>
                            <td><span class="meth-grade-badge meth-grade-badge--b">B</span></td>
                            <td>6.5 &ndash; 7.4</td>
                            <td>Solid &mdash; functional but room to improve</td>
                        </tr>
                        <tr>
                            <td><span class="meth-grade-badge meth-grade-badge--c">C+</span></td>
                            <td>5.5 &ndash; 6.4</td>
                            <td>Average &mdash; not broken, not strong</td>
                        </tr>
                        <tr>
                            <td><span class="meth-grade-badge meth-grade-badge--c">C</span></td>
                            <td>4.5 &ndash; 5.4</td>
                            <td>Below average &mdash; noticeable issues</td>
                        </tr>
                        <tr>
                            <td><span class="meth-grade-badge meth-grade-badge--d">D</span></td>
                            <td>3.0 &ndash; 4.4</td>
                            <td>Poor &mdash; likely costing you conversions</td>
                        </tr>
                        <tr>
                            <td><span class="meth-grade-badge meth-grade-badge--f">F</span></td>
                            <td>0 &ndash; 2.9</td>
                            <td>Critical &mdash; this needs immediate attention</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- The 10 Factors -->
    <section class="meth-section meth-section--white">
        <h2>The 10 Conversion Factors</h2>
        <p class="meth-section-subtitle">These are the elements that determine whether a visitor becomes a customer. Each one is scored independently, with specific evidence from your actual page.</p>

        <div class="meth-factors-list">

            <!-- Factor 1: Headline Quality -->
            <div class="meth-factor-item">
                <div class="meth-factor-header">
                    <span class="meth-factor-num">1</span>
                    <h3 class="meth-factor-title">Headline Quality</h3>
                </div>
                <div class="meth-factor-body">
                    <p><span class="meth-factor-label">What we look for:</span> Does your headline communicate clear value within 3&ndash;5 seconds? Is it specific to your audience, or could it belong to any business in any industry? Does it address a pain point or promise a concrete outcome?</p>
                    <p><span class="meth-factor-label">Why it matters:</span> Your headline is the first thing visitors read. Research consistently shows you have roughly five seconds before someone decides to stay or leave. A vague headline like "Welcome to Our Company" tells the visitor nothing &mdash; and they'll bounce before scrolling further.</p>
                </div>
            </div>

            <!-- Factor 2: Value Proposition -->
            <div class="meth-factor-item">
                <div class="meth-factor-header">
                    <span class="meth-factor-num">2</span>
                    <h3 class="meth-factor-title">Value Proposition</h3>
                </div>
                <div class="meth-factor-body">
                    <p><span class="meth-factor-label">What we look for:</span> Does your page clearly answer "what's in it for me?" from the visitor's perspective? Are you leading with benefits rather than features? Can a stranger immediately understand the value you provide?</p>
                    <p><span class="meth-factor-label">Why it matters:</span> Features describe what you do. Benefits describe what the customer gets. Most small business websites list services without explaining the outcome. "24/7 emergency plumbing" is a feature. "No more waiting until Monday for a burst pipe fix" is a benefit. The difference matters.</p>
                </div>
            </div>

            <!-- Factor 3: Unique Selling Proposition -->
            <div class="meth-factor-item">
                <div class="meth-factor-header">
                    <span class="meth-factor-num">3</span>
                    <h3 class="meth-factor-title">Unique Selling Proposition</h3>
                </div>
                <div class="meth-factor-body">
                    <p><span class="meth-factor-label">What we look for:</span> Does your page answer "why should I choose you over the other options I'm comparing?" Is your differentiator visible above the fold, or buried in a paragraph nobody reads?</p>
                    <p><span class="meth-factor-label">Why it matters:</span> Every potential customer is comparing you to at least two or three alternatives &mdash; whether that's a competitor, doing it themselves, or doing nothing at all. If your page doesn't give them a reason to pick you specifically, you're relying on luck. A strong USP removes the guesswork.</p>
                </div>
            </div>

            <!-- Factor 4: Call-to-Action -->
            <div class="meth-factor-item">
                <div class="meth-factor-header">
                    <span class="meth-factor-num">4</span>
                    <h3 class="meth-factor-title">Call-to-Action</h3>
                </div>
                <div class="meth-factor-body">
                    <p><span class="meth-factor-label">What we look for:</span> Is your primary CTA visible without scrolling? Is the button text specific ("Get a Free Quote" beats "Submit")? Is there a single, clear next step &mdash; or are you giving visitors five different options and hoping they pick one?</p>
                    <p><span class="meth-factor-label">Why it matters:</span> The CTA is where intent becomes action. We've scored thousands of sites where the contact button is invisible on mobile, uses generic text like "Click Here", or competes with three other buttons. Every one of those is a missed conversion. Prominence, placement, and copy clarity all count.</p>
                </div>
            </div>

            <!-- Factor 5: Trust & Credibility Signals -->
            <div class="meth-factor-item">
                <div class="meth-factor-header">
                    <span class="meth-factor-num">5</span>
                    <h3 class="meth-factor-title">Trust &amp; Credibility Signals</h3>
                </div>
                <div class="meth-factor-body">
                    <p><span class="meth-factor-label">What we look for:</span> Testimonials, reviews, certifications, industry badges, professional memberships, insurance details, case studies, client logos, or any form of social proof. We check whether they're present, whether they're visible above the fold, and whether they feel genuine.</p>
                    <p><span class="meth-factor-label">Why it matters:</span> Trust is the single biggest barrier to conversion for a new visitor. They've never heard of you. They don't know if you're legitimate, experienced, or any good. Visible, credible proof &mdash; especially near the CTA &mdash; reduces friction and tips the balance. A site with zero social proof is asking visitors to take a leap of faith most won't take.</p>
                </div>
            </div>

            <!-- Factor 6: Urgency & Scarcity -->
            <div class="meth-factor-item">
                <div class="meth-factor-header">
                    <span class="meth-factor-num">6</span>
                    <h3 class="meth-factor-title">Urgency &amp; Scarcity</h3>
                </div>
                <div class="meth-factor-body">
                    <p><span class="meth-factor-label">What we look for:</span> Legitimate reasons to act now rather than later. Limited availability, seasonal offers, time-sensitive benefits, or natural urgency inherent to the service ("every day your roof leaks costs you more"). We also check for fake urgency &mdash; countdown timers that reset on refresh earn a lower score, not a higher one.</p>
                    <p><span class="meth-factor-label">Why it matters:</span> Without a reason to act now, visitors bookmark your page and never come back. Genuine urgency respects the visitor while giving them a nudge. The key word is "legitimate" &mdash; fabricated scarcity destroys trust, which is worse than having no urgency at all.</p>
                </div>
            </div>

            <!-- Factor 7: Hook & Visual Engagement -->
            <div class="meth-factor-item">
                <div class="meth-factor-header">
                    <span class="meth-factor-num">7</span>
                    <h3 class="meth-factor-title">Hook &amp; Visual Engagement</h3>
                </div>
                <div class="meth-factor-body">
                    <p><span class="meth-factor-label">What we look for:</span> What's the first thing a visitor sees? Is the hero section compelling enough to stop the scroll? Does the above-the-fold area create an immediate emotional or intellectual hook &mdash; or is it a generic stock photo with a forgettable tagline?</p>
                    <p><span class="meth-factor-label">Why it matters:</span> First impressions are formed in under a second. The hero area sets the tone for the entire visit. A strong visual hook &mdash; whether it's a striking image, a bold claim, or a clear problem statement &mdash; earns the visitor's attention long enough for the rest of your page to do its job.</p>
                </div>
            </div>

            <!-- Factor 8: Imagery & Design -->
            <div class="meth-factor-item">
                <div class="meth-factor-header">
                    <span class="meth-factor-num">8</span>
                    <h3 class="meth-factor-title">Imagery &amp; Design</h3>
                </div>
                <div class="meth-factor-body">
                    <p><span class="meth-factor-label">What we look for:</span> Are the photos authentic (your team, your work, your premises) or generic stock? Is the visual hierarchy clear &mdash; does the layout guide the eye from headline to benefit to CTA? Are colours, spacing, and typography consistent and professional?</p>
                    <p><span class="meth-factor-label">Why it matters:</span> Generic stock photos signal "template website" and erode trust. Authentic imagery builds credibility before the visitor reads a single word. Beyond photos, design quality signals professionalism &mdash; cluttered layouts, inconsistent fonts, and poor spacing make visitors question whether you'll be equally careless with their project.</p>
                </div>
            </div>

            <!-- Factor 9: Offer Clarity -->
            <div class="meth-factor-item">
                <div class="meth-factor-header">
                    <span class="meth-factor-num">9</span>
                    <h3 class="meth-factor-title">Offer Clarity</h3>
                </div>
                <div class="meth-factor-body">
                    <p><span class="meth-factor-label">What we look for:</span> Can a visitor tell exactly what they'll get if they take the next step? Is pricing visible (or at least a clear indication of the engagement process)? Are deliverables, timelines, and outcomes spelled out &mdash; or does the visitor have to guess?</p>
                    <p><span class="meth-factor-label">Why it matters:</span> Ambiguity kills conversions. When visitors can't figure out what they're buying, how much it costs, or what happens after they click, they leave. Clear offer framing ("You get X, it costs Y, here's how to start") removes the mental effort that causes drop-off. Every question left unanswered is a reason not to convert.</p>
                </div>
            </div>

            <!-- Factor 10: Industry Appropriateness -->
            <div class="meth-factor-item">
                <div class="meth-factor-header">
                    <span class="meth-factor-num">10</span>
                    <h3 class="meth-factor-title">Industry Appropriateness</h3>
                </div>
                <div class="meth-factor-body">
                    <p><span class="meth-factor-label">What we look for:</span> Does the design, tone, and structure of your site match the expectations of your industry? A law firm should look authoritative. A children's party entertainer should look fun. A trades business should look reliable. We check whether your site feels right for the audience you're trying to reach.</p>
                    <p><span class="meth-factor-label">Why it matters:</span> Visitors arrive with subconscious expectations about what a website in your industry should look and feel like. When the design doesn't match &mdash; a medical practice using playful Comic Sans, or a creative agency with a corporate grey template &mdash; it creates cognitive dissonance. The visitor can't articulate why, but something feels off. And they leave.</p>
                </div>
            </div>

        </div>
    </section>

    <!-- How We Analyse -->
    <section class="meth-section meth-section--alt">
        <h2>How We Analyse Your Site</h2>
        <p class="meth-section-subtitle">We don't scrape your DOM or run an automated crawler. We look at your website the same way a real visitor does &mdash; as a visual experience.</p>

        <div class="meth-process-grid">
            <div class="meth-process-card">
                <div class="meth-process-icon" aria-hidden="true">&#128247;</div>
                <h3>Visual Screenshot Analysis</h3>
                <p>We capture full-page screenshots of your site at real-world viewport sizes &mdash; desktop, tablet, and mobile. Our AI analyses the actual rendered page: the layout visitors see, the colours they respond to, the hierarchy their eyes follow. No DOM parsing, no source-code shortcuts.</p>
            </div>
            <div class="meth-process-card">
                <div class="meth-process-icon" aria-hidden="true">&#129504;</div>
                <h3>AI-Powered Scoring</h3>
                <p>Each screenshot is evaluated against our 10-factor framework using a vision model trained on conversion patterns across thousands of real business websites. The model assesses each factor independently, producing scores, grades, and specific observations about your page.</p>
            </div>
            <div class="meth-process-card">
                <div class="meth-process-icon" aria-hidden="true">&#128269;</div>
                <h3>Below-the-Fold Deep Dive</h3>
                <p>We don't stop at the hero section. A second analysis pass covers everything below the fold &mdash; service descriptions, testimonial placement, secondary CTAs, footer content, and page structure. Issues that only appear on scroll are flagged with the same rigour as above-the-fold problems.</p>
            </div>
            <div class="meth-process-card">
                <div class="meth-process-icon" aria-hidden="true">&#128100;</div>
                <h3>Human Expert Review</h3>
                <p>Every report is reviewed by our senior analyst before delivery. The AI catches patterns at scale; the human catches nuance. If a score seems off, if an industry-specific detail needs context, or if a recommendation needs qualifying &mdash; it gets adjusted. You're never receiving a raw AI output.</p>
            </div>
        </div>

        <div class="meth-expert-box">
            <img src="/assets/img/marcus-webb.jpg" alt="Marcus Webb, CRO Specialist" class="meth-expert-photo" loading="lazy">
            <div class="meth-expert-content">
                <p><strong>Marcus Webb, CRO Specialist:</strong> "I've been reviewing websites for conversion issues for over two decades. The AI handles the scale &mdash; scoring hundreds of factors across the page in seconds. But there's no substitute for someone who's seen the same mistake cost a real business real money. Every report gets my eyes before it reaches yours."</p>
            </div>
        </div>
    </section>

    <!-- Calibration -->
    <section class="meth-section meth-section--white">
        <h2>Calibrated Across Real Businesses</h2>
        <p class="meth-section-subtitle">Our scoring isn't theoretical. It's grounded in data from real websites across dozens of industries and multiple countries.</p>

        <div class="meth-calibration-box">
            <div class="meth-calibration-stat"><?= htmlspecialchars($sitesScored) ?>+</div>
            <div class="meth-calibration-label">Business Websites Scored</div>
            <p>Our methodology has been calibrated and refined across more than <?= htmlspecialchars($sitesScored) ?> real small business websites &mdash; from plumbers and physiotherapists to law firms and e-commerce stores, across Australia, the UK, the US, Canada, New Zealand, and beyond.</p>
            <p>This matters because it means your score isn't being compared to an abstract ideal. It's contextualised against businesses like yours, in industries like yours. When we say your headline is a 4/10, that's relative to what we've seen work (and fail) across thousands of real pages.</p>
            <p>As our dataset grows, our scoring model gets sharper. We continuously refine factor weightings and grade thresholds based on observed patterns &mdash; what separates a high-converting page from one that looks good but doesn't perform.</p>
        </div>
    </section>

    <!-- What You Get -->
    <section class="meth-section meth-section--alt">
        <h2>What You Get</h2>
        <p class="meth-section-subtitle">The output of all this analysis is a professional PDF report delivered to your inbox within 24 hours of payment.</p>

        <div class="meth-deliverable-grid">
            <div class="meth-deliverable-card">
                <div class="meth-deliverable-icon" aria-hidden="true">&#128196;</div>
                <div class="meth-deliverable-text">
                    <h3>9-Page Professional PDF</h3>
                    <p>A comprehensive report covering all 10 conversion factors with individual scores, grades, and detailed commentary. Not a template &mdash; generated specifically for your URL.</p>
                </div>
            </div>
            <div class="meth-deliverable-card">
                <div class="meth-deliverable-icon" aria-hidden="true">&#128247;</div>
                <div class="meth-deliverable-text">
                    <h3>Annotated Screenshots</h3>
                    <p>Zoomed-in screenshots of every problem area on your page, so you can see exactly what we're talking about. No vague descriptions &mdash; visual evidence of each issue.</p>
                </div>
            </div>
            <div class="meth-deliverable-card">
                <div class="meth-deliverable-icon" aria-hidden="true">&#128203;</div>
                <div class="meth-deliverable-text">
                    <h3>Prioritised Fix List</h3>
                    <p>Issues ranked by impact, with quick wins flagged first. Your developer (or you) can work through the list in order, tackling the changes that'll move the needle most.</p>
                </div>
            </div>
            <div class="meth-deliverable-card">
                <div class="meth-deliverable-icon" aria-hidden="true">&#128202;</div>
                <div class="meth-deliverable-text">
                    <h3>Overall CRO Score &amp; Grade</h3>
                    <p>A single number and letter grade that tells you where your site stands. Use it as a benchmark &mdash; order a follow-up audit after making changes to measure improvement.</p>
                </div>
            </div>
            <div class="meth-deliverable-card">
                <div class="meth-deliverable-icon" aria-hidden="true">&#128274;</div>
                <div class="meth-deliverable-text">
                    <h3>Technical Assessment</h3>
                    <p>SSL status, security headers, and mobile responsiveness checked alongside the conversion analysis. Technical issues that affect trust or usability are flagged.</p>
                </div>
            </div>
            <div class="meth-deliverable-card">
                <div class="meth-deliverable-icon" aria-hidden="true">&#128172;</div>
                <div class="meth-deliverable-text">
                    <h3>Plain-English Explanations</h3>
                    <p>Written for business owners, not developers. Every finding explains what the issue is, why it matters for conversions, and specifically how to fix it.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer CTA -->
    <section class="meth-footer-cta">
        <h2>Ready to See Your Score?</h2>
        <p class="subtitle">Find out exactly where your website is losing conversions &mdash; and what to fix first.</p>
        <a href="/#order" class="cta-button">Get Your Audit Report &mdash; <?= htmlspecialchars($priceFormatted) ?></a>
        <p class="cta-note">24-hour delivery &middot; 30-day money-back guarantee</p>
    </section>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script src="/assets/js/obfuscate-email.js?v=1" defer></script>
</body>
</html>
