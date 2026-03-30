<?php
/**
 * One-Time Website Audit — /one-time-audit
 *
 * Targets: "website performance audit one time report not subscription"
 * Comparison shopper who wants a one-off service, not an ongoing tool.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/geo.php';
require_once __DIR__ . '/includes/pricing.php';

$countryCode      = detectCountry();
$productPriceData = getProductPriceForCountry($countryCode, 'full_audit');
$priceFormatted   = $productPriceData['formatted'];
$priceCents       = $productPriceData['price'];
$currency         = $productPriceData['currency'];
$symbol           = $productPriceData['symbol'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>One-Time Website Audit — No Subscription Required | Audit&amp;Fix</title>
    <meta name="description" content="A professional CRO audit delivered as a one-off PDF report. No subscription, no monthly fees, no tools to learn. Pay <?= htmlspecialchars($priceFormatted) ?>, get your report within 24 hours.">
    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/assets/img/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="canonical" href="https://www.auditandfix.com/one-time-audit">
    <meta property="og:title" content="One-Time Website Audit — No Subscription | Audit&amp;Fix">
    <meta property="og:description" content="Pay once, get your report. No subscription, no monthly fees, no tools to learn. Professional CRO audit delivered within 24 hours.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://www.auditandfix.com/one-time-audit">
    <meta property="og:image" content="https://www.auditandfix.com/assets/img/og-image.png">
    <meta property="og:site_name" content="Audit&amp;Fix">
    <meta name="twitter:card" content="summary_large_image">

    <!-- Schema.org structured data -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@graph": [
        {
          "@type": "Service",
          "@id": "https://www.auditandfix.com/one-time-audit#service",
          "name": "One-Time CRO Audit Report",
          "description": "A one-off professional CRO audit delivered as a scored PDF report within 24 hours. No subscription, no monthly fees.",
          "url": "https://www.auditandfix.com/one-time-audit",
          "provider": {
            "@type": "Organization",
            "name": "Audit&Fix",
            "url": "https://www.auditandfix.com/"
          },
          "offers": {
            "@type": "Offer",
            "url": "https://www.auditandfix.com/#order",
            "price": "<?= $priceCents ?>",
            "priceCurrency": "<?= $currency ?>",
            "availability": "https://schema.org/InStock",
            "priceValidUntil": "2027-01-01"
          },
          "serviceType": "Website CRO Audit",
          "areaServed": ["AU", "GB", "US", "NZ", "CA", "IE"]
        },
        {
          "@type": "BreadcrumbList",
          "itemListElement": [
            {"@type": "ListItem", "position": 1, "name": "Home", "item": "https://www.auditandfix.com/"},
            {"@type": "ListItem", "position": 2, "name": "One-Time Audit"}
          ]
        },
        {
          "@type": "FAQPage",
          "mainEntity": [
            {
              "@type": "Question",
              "name": "Is this a one-time payment or a subscription?",
              "acceptedAnswer": {"@type": "Answer", "text": "One-time payment. You pay once, you receive your audit report. No subscription, no monthly fees, no automatic renewals. There is nothing to cancel."}
            },
            {
              "@type": "Question",
              "name": "What's included in the one-time audit?",
              "acceptedAnswer": {"@type": "Answer", "text": "A 10-factor CRO audit scored 0–10 and graded A+ to F, a prioritised list of fixes ordered by impact, visual page analysis with screenshots, and benchmarking against 10,000+ real business websites. Delivered as a polished PDF within 24 hours."}
            },
            {
              "@type": "Question",
              "name": "Why would I pay for a report instead of using a free tool?",
              "acceptedAnswer": {"@type": "Answer", "text": "Free tools like Google Lighthouse measure technical performance (speed, accessibility, SEO). They don't tell you whether your headline is confusing, your call-to-action is buried, or your trust signals are missing — the things that actually determine whether visitors convert. Our audit analyses the page the way a real visitor sees it."}
            },
            {
              "@type": "Question",
              "name": "Can I order a follow-up audit later?",
              "acceptedAnswer": {"@type": "Answer", "text": "Yes. Once you've implemented fixes, you can order a follow-up benchmarking audit at 50% of the original price to measure how much your conversion score improved."}
            }
          ]
        }
      ]
    }
    </script>

    <style>
        /* ── One-Time Audit page styles ──────────────────────────── */

        .ota-hero {
            background: linear-gradient(135deg, #f8fafc 0%, #eef2f7 50%, #f0f4f9 100%);
            color: #1a365d;
            padding: 0 0 60px;
        }
        .ota-hero-body {
            max-width: 800px;
            margin: 0 auto;
            padding: 48px 24px 0;
            text-align: center;
        }
        .ota-hero h1 {
            font-size: 2.2rem;
            line-height: 1.2;
            margin-bottom: 16px;
            font-weight: 800;
            color: #1a365d;
        }
        .ota-hero .subtitle {
            font-size: 1.1rem;
            color: #4a5568;
            line-height: 1.6;
            max-width: 640px;
            margin: 0 auto 32px;
        }
        .ota-hero-badges {
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 32px;
        }
        .ota-badge {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 6px 16px;
            font-size: 0.88rem;
            color: #2d3748;
            font-weight: 600;
        }
        .ota-hero-cta {
            display: inline-block;
            background: #2563eb;
            color: #fff;
            padding: 14px 32px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 700;
            text-decoration: none;
            transition: background 0.2s;
        }
        .ota-hero-cta:hover { background: #1d4ed8; }
        .ota-hero-note {
            display: block;
            color: #718096;
            font-size: 0.85rem;
            margin-top: 10px;
        }

        /* ── Shared section patterns ─────────────────────────────── */
        .ota-section {
            padding: 80px 20px;
        }
        .ota-section--alt   { background: #f8fafc; }
        .ota-section--white { background: #ffffff; }
        .ota-section h2 {
            text-align: center;
            color: #1a365d;
            font-size: 1.8rem;
            margin-bottom: 12px;
        }
        .ota-section-subtitle {
            text-align: center;
            color: #4a5568;
            font-size: 1rem;
            margin-bottom: 40px;
            max-width: 640px;
            margin-left: auto;
            margin-right: auto;
        }

        /* ── What's included grid ────────────────────────────────── */
        .ota-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
            max-width: 960px;
            margin: 0 auto;
        }
        .ota-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 28px 24px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .ota-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
        }
        .ota-card-icon {
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
        .ota-card h3 {
            color: #1a365d;
            font-size: 1.05rem;
            margin-bottom: 8px;
        }
        .ota-card p {
            color: #4a5568;
            font-size: 0.92rem;
            line-height: 1.6;
        }

        /* ── Comparison: one-off vs tools ────────────────────────── */
        .ota-compare-table {
            width: 100%;
            max-width: 760px;
            margin: 0 auto;
            border-collapse: collapse;
            font-size: 0.95rem;
        }
        .ota-compare-table th {
            text-align: left;
            padding: 12px 16px;
            background: #1a365d;
            color: #fff;
            font-weight: 600;
        }
        .ota-compare-table th:not(:first-child) { text-align: center; }
        .ota-compare-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #e2e8f0;
            color: #2d3748;
        }
        .ota-compare-table td:not(:first-child) { text-align: center; }
        .ota-compare-table tr:nth-child(even) td { background: #f8fafc; }
        .ota-compare-table td.ota-ours { background: #eff6ff !important; font-weight: 600; }
        .ota-yes { color: #059669; font-weight: 700; }
        .ota-no  { color: #a0aec0; }
        .ota-partial { color: #d97706; font-size: 0.85rem; }

        /* ── Pricing box ─────────────────────────────────────────── */
        .ota-pricing-box {
            max-width: 480px;
            margin: 0 auto;
            background: #fff;
            border: 2px solid #2563eb;
            border-radius: 16px;
            padding: 40px 36px;
            text-align: center;
        }
        .ota-pricing-box .price {
            font-size: 3rem;
            font-weight: 800;
            color: #2563eb;
            line-height: 1;
            margin-bottom: 8px;
        }
        .ota-pricing-box .price-note {
            color: #718096;
            font-size: 0.9rem;
            margin-bottom: 24px;
        }
        .ota-checklist {
            list-style: none;
            padding: 0;
            margin: 0 0 28px;
            text-align: left;
        }
        .ota-checklist li {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #2d3748;
            font-size: 0.95rem;
            margin-bottom: 10px;
        }
        .ota-checklist li::before {
            content: '✓';
            color: #059669;
            font-weight: 700;
            flex-shrink: 0;
        }
        .ota-pricing-cta {
            display: block;
            background: #2563eb;
            color: #fff;
            padding: 14px 28px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 1rem;
            text-decoration: none;
            transition: background 0.2s;
        }
        .ota-pricing-cta:hover { background: #1d4ed8; }
        .ota-guarantee {
            margin-top: 16px;
            color: #718096;
            font-size: 0.85rem;
        }

        /* ── FAQ ─────────────────────────────────────────────────── */
        .ota-faq-list {
            max-width: 720px;
            margin: 0 auto;
        }
        .ota-faq-item {
            border-bottom: 1px solid #e2e8f0;
            padding: 24px 0;
        }
        .ota-faq-item h3 {
            color: #1a365d;
            font-size: 1.05rem;
            margin-bottom: 10px;
        }
        .ota-faq-item p {
            color: #4a5568;
            font-size: 0.95rem;
            line-height: 1.7;
        }

        /* ── CTA band ────────────────────────────────────────────── */
        .ota-cta-band {
            background: #1a365d;
            color: #fff;
            padding: 80px 20px;
            text-align: center;
        }
        .ota-cta-band h2 { font-size: 2rem; margin-bottom: 12px; }
        .ota-cta-band p  { color: #a0aec8; font-size: 1rem; margin-bottom: 28px; }
        .ota-cta-band a {
            display: inline-block;
            background: #2563eb;
            color: #fff;
            padding: 14px 36px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 1rem;
            text-decoration: none;
            transition: background 0.2s;
        }
        .ota-cta-band a:hover { background: #1d4ed8; }

        @media (max-width: 640px) {
            .ota-hero h1 { font-size: 1.7rem; }
            .ota-pricing-box { padding: 28px 20px; }
            .ota-compare-table { font-size: 0.82rem; }
            .ota-compare-table th, .ota-compare-table td { padding: 10px 10px; }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/header.php'; ?>
<?php require_once __DIR__ . '/includes/consent-banner.php'; ?>

<!-- ── Hero ──────────────────────────────────────────────────────── -->
<section class="ota-hero">
    <div class="ota-hero-body">
        <h1>A One-Time Website Audit — No Subscription, No Monthly Fees</h1>
        <p class="subtitle">
            Most audit tools charge you every month for access to a dashboard you check once.
            We do it differently: pay once, get a polished PDF report within 24 hours, and you're done.
        </p>
        <div class="ota-hero-badges">
            <span class="ota-badge">✓ One-off payment</span>
            <span class="ota-badge">✓ No subscription</span>
            <span class="ota-badge">✓ No tools to learn</span>
            <span class="ota-badge">✓ Delivered in 24h</span>
        </div>
        <a href="/scan" class="ota-hero-cta">Get My One-Time Audit — <?= htmlspecialchars($priceFormatted) ?></a>
        <span class="ota-hero-note">30-day money-back guarantee &middot; Secure payment via PayPal</span>
    </div>
</section>

<!-- ── Problem with subscriptions ────────────────────────────────── -->
<section class="ota-section ota-section--white">
    <h2>The Problem with Subscription Audit Tools</h2>
    <p class="ota-section-subtitle">
        Most CRO and website analysis tools are built for marketing agencies, not small businesses.
        Here's what you actually get with most of them.
    </p>
    <div style="overflow-x:auto;">
        <table class="ota-compare-table">
            <thead>
                <tr>
                    <th>What you need</th>
                    <th class="ota-ours">Audit&amp;Fix</th>
                    <th>Hotjar / Clarity</th>
                    <th>SEMrush / Ahrefs</th>
                    <th>Lighthouse</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>One-off payment (no subscription)</td>
                    <td class="ota-ours"><span class="ota-yes">✓</span></td>
                    <td><span class="ota-no">Monthly</span></td>
                    <td><span class="ota-no">Monthly</span></td>
                    <td><span class="ota-yes">Free</span></td>
                </tr>
                <tr>
                    <td>Works immediately — no setup</td>
                    <td class="ota-ours"><span class="ota-yes">✓</span></td>
                    <td><span class="ota-no">Needs tracking code + data</span></td>
                    <td><span class="ota-partial">Partial</span></td>
                    <td><span class="ota-yes">✓</span></td>
                </tr>
                <tr>
                    <td>Tells you what to fix, not just what's wrong</td>
                    <td class="ota-ours"><span class="ota-yes">✓</span></td>
                    <td><span class="ota-no">You interpret heatmaps yourself</span></td>
                    <td><span class="ota-partial">SEO issues only</span></td>
                    <td><span class="ota-no">Technical only</span></td>
                </tr>
                <tr>
                    <td>Analyses conversion copy &amp; UX</td>
                    <td class="ota-ours"><span class="ota-yes">✓</span></td>
                    <td><span class="ota-no">No</span></td>
                    <td><span class="ota-no">No</span></td>
                    <td><span class="ota-no">No</span></td>
                </tr>
                <tr>
                    <td>Benchmarked against other sites</td>
                    <td class="ota-ours"><span class="ota-yes">✓</span></td>
                    <td><span class="ota-no">No</span></td>
                    <td><span class="ota-partial">Competitor data only</span></td>
                    <td><span class="ota-no">No</span></td>
                </tr>
                <tr>
                    <td>Prioritised fix list</td>
                    <td class="ota-ours"><span class="ota-yes">✓</span></td>
                    <td><span class="ota-no">No</span></td>
                    <td><span class="ota-partial">SEO issues only</span></td>
                    <td><span class="ota-no">No</span></td>
                </tr>
                <tr>
                    <td>Money-back guarantee</td>
                    <td class="ota-ours"><span class="ota-yes">30 days</span></td>
                    <td><span class="ota-no">No</span></td>
                    <td><span class="ota-no">No</span></td>
                    <td>—</td>
                </tr>
            </tbody>
        </table>
    </div>
</section>

<!-- ── What's included ───────────────────────────────────────────── -->
<section class="ota-section ota-section--alt">
    <h2>What's in the One-Time Report</h2>
    <p class="ota-section-subtitle">
        Everything you need to understand what's stopping conversions — in a single, shareable PDF.
    </p>
    <div class="ota-grid">
        <div class="ota-card">
            <div class="ota-card-icon">📊</div>
            <h3>10-Factor Scored Analysis</h3>
            <p>Every conversion factor scored 0–10 and graded A+ to F. You see exactly where your site stands across all the dimensions that drive conversions.</p>
        </div>
        <div class="ota-card">
            <div class="ota-card-icon">🔍</div>
            <h3>Visual Page Analysis</h3>
            <p>Annotated screenshots showing exactly what we identified on your page — not abstract scores in a dashboard, but specific observations about your actual site.</p>
        </div>
        <div class="ota-card">
            <div class="ota-card-icon">📋</div>
            <h3>Prioritised Fix List</h3>
            <p>Fixes ordered by estimated impact so you know exactly what to tackle first. No vague suggestions — specific changes with the reasoning behind each one.</p>
        </div>
        <div class="ota-card">
            <div class="ota-card-icon">📈</div>
            <h3>Benchmark vs 10,000+ Sites</h3>
            <p>Your score is calibrated against 10,000+ real business websites. A grade of C isn't a guess — it's based on how your site compares to real benchmarks.</p>
        </div>
        <div class="ota-card">
            <div class="ota-card-icon">📱</div>
            <h3>Mobile Experience Review</h3>
            <p>We test both desktop and mobile views. Given that over half of web traffic is mobile, this is often where the biggest conversion opportunities are found.</p>
        </div>
        <div class="ota-card">
            <div class="ota-card-icon">🤝</div>
            <h3>Human Expert Review</h3>
            <p>AI-powered analysis reviewed by a CRO specialist. You're not getting a raw algorithm output — you're getting a considered professional opinion on your site.</p>
        </div>
    </div>
</section>

<!-- ── Pricing ───────────────────────────────────────────────────── -->
<section class="ota-section ota-section--white">
    <h2>One Payment. One Report. That's It.</h2>
    <p class="ota-section-subtitle">No monthly fees. No dashboard. No subscription to cancel.</p>
    <div class="ota-pricing-box">
        <div class="price"><?= htmlspecialchars($priceFormatted) ?></div>
        <div class="price-note">One-off payment &middot; <?= htmlspecialchars($currency) ?></div>
        <ul class="ota-checklist">
            <li>10-factor CRO audit scored 0–10</li>
            <li>A+ to F grade for each conversion factor</li>
            <li>Prioritised fix list by impact</li>
            <li>Visual analysis with annotated screenshots</li>
            <li>Benchmark vs 10,000+ real business websites</li>
            <li>Human expert review, not just an algorithm</li>
            <li>Delivered as a PDF within 24 hours</li>
            <li>30-day money-back guarantee</li>
        </ul>
        <a href="/scan" class="ota-pricing-cta">Get My One-Time Audit</a>
        <p class="ota-guarantee">30-day money-back guarantee &middot; Secure payment via PayPal</p>
    </div>
</section>

<!-- ── FAQ ───────────────────────────────────────────────────────── -->
<section class="ota-section ota-section--alt">
    <h2>Common Questions</h2>
    <div class="ota-faq-list">
        <div class="ota-faq-item">
            <h3>Is this genuinely a one-time payment?</h3>
            <p>Yes. You pay once, you receive your report. There is no subscription, no free trial that converts to a monthly fee, and no automatic renewal. There is nothing to cancel.</p>
        </div>
        <div class="ota-faq-item">
            <h3>Why pay for a report when free tools like Lighthouse exist?</h3>
            <p>Lighthouse measures technical performance — speed, accessibility, basic SEO. It doesn't tell you whether your headline is confusing, your call-to-action is buried, or your pricing page lacks social proof. Those are the factors that determine whether visitors actually convert, and they require human judgement to assess.</p>
        </div>
        <div class="ota-faq-item">
            <h3>Do I need to install anything on my website?</h3>
            <p>No. We work from your public URL. No tracking code, no scripts, no access to your CMS or analytics. Just submit your URL and we do the rest.</p>
        </div>
        <div class="ota-faq-item">
            <h3>Can I order another audit in 6 months to see if I've improved?</h3>
            <p>Yes — and we offer a 50% discount on follow-up benchmarking audits. Ordering a second audit after you've implemented fixes lets you see exactly how much your conversion score improved.</p>
        </div>
        <div class="ota-faq-item">
            <h3>What if the report doesn't give me anything useful?</h3>
            <p>30-day money-back guarantee, no questions asked. If the report doesn't contain actionable insights you can act on, we'll refund you in full.</p>
        </div>
    </div>
</section>

<!-- ── CTA band ───────────────────────────────────────────────────── -->
<section class="ota-cta-band">
    <h2>Pay once. Get your report. Improve your site.</h2>
    <p>No subscription. No monthly fee. Professional CRO audit in 24 hours. <?= htmlspecialchars($priceFormatted) ?> one-off.</p>
    <a href="/scan">Get My One-Time Audit</a>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
