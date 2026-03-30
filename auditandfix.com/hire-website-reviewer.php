<?php
/**
 * Hire a Website Reviewer — /hire-website-reviewer
 *
 * Targets: "hire someone to review my website and tell me what's wrong"
 * High purchase-intent landing page. No i18n body copy — English-only SEO target.
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
    <title>Hire Someone to Review Your Website — Audit&amp;Fix</title>
    <meta name="description" content="Get a professional website review that tells you exactly what's wrong and how to fix it. Delivered within 24 hours as a scored PDF report. No tracking code. No subscription. From <?= htmlspecialchars($priceFormatted) ?>.">
    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/assets/img/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="canonical" href="https://www.auditandfix.com/hire-website-reviewer">
    <meta property="og:title" content="Hire a Website Reviewer — Audit&amp;Fix">
    <meta property="og:description" content="A professional review of your website that tells you exactly what's wrong. Delivered within 24 hours. From <?= htmlspecialchars($priceFormatted) ?>.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://www.auditandfix.com/hire-website-reviewer">
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
          "@id": "https://www.auditandfix.com/hire-website-reviewer#service",
          "name": "Professional Website Review Service",
          "description": "A professional CRO audit that reviews your website and delivers a scored PDF report explaining exactly what's wrong and how to fix it. Delivered within 24 hours.",
          "url": "https://www.auditandfix.com/hire-website-reviewer",
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
          "areaServed": ["AU", "GB", "US", "NZ", "CA", "IE"],
          "hasOfferCatalog": {
            "@type": "OfferCatalog",
            "name": "Website Review Services",
            "itemListElement": [
              {
                "@type": "Offer",
                "itemOffered": {
                  "@type": "Service",
                  "name": "Full CRO Audit Report",
                  "description": "10-factor scored analysis of your website with prioritised fix list, delivered as a PDF within 24 hours."
                }
              }
            ]
          }
        },
        {
          "@type": "BreadcrumbList",
          "itemListElement": [
            {"@type": "ListItem", "position": 1, "name": "Home", "item": "https://www.auditandfix.com/"},
            {"@type": "ListItem", "position": 2, "name": "Hire a Website Reviewer"}
          ]
        },
        {
          "@type": "FAQPage",
          "mainEntity": [
            {
              "@type": "Question",
              "name": "What does the website review cover?",
              "acceptedAnswer": {"@type": "Answer", "text": "We review 10 conversion factors: first impression, headline clarity, value proposition, social proof, call-to-action visibility, mobile experience, page speed, trust signals, navigation, and contact accessibility. Each factor is scored 0–10 and graded A+ to F, with a prioritised list of fixes."}
            },
            {
              "@type": "Question",
              "name": "Do I need to give you access to my website?",
              "acceptedAnswer": {"@type": "Answer", "text": "No. We work from your live public URL. No logins, no tracking code, no access to your CMS or analytics. Just submit your URL and we do the rest."}
            },
            {
              "@type": "Question",
              "name": "How long does the review take?",
              "acceptedAnswer": {"@type": "Answer", "text": "Reviews are delivered within 24 hours of payment. Most are ready within a few hours during business hours (AEST)."}
            },
            {
              "@type": "Question",
              "name": "Is this a one-off service or a subscription?",
              "acceptedAnswer": {"@type": "Answer", "text": "One-off. You pay once, you get your report. No subscription, no monthly fees, no ongoing commitment."}
            },
            {
              "@type": "Question",
              "name": "How is this different from hiring a freelancer?",
              "acceptedAnswer": {"@type": "Answer", "text": "Most freelancers offer general feedback with no clear scoring framework, no benchmark, and no prioritised list of fixes. Our review uses a consistent 10-factor methodology calibrated across 10,000+ websites, so you get an objective score you can actually track over time."}
            }
          ]
        }
      ]
    }
    </script>

    <style>
        /* ── Hire Reviewer page styles ───────────────────────────── */

        .hrw-hero {
            background: linear-gradient(135deg, #f8fafc 0%, #eef2f7 50%, #f0f4f9 100%);
            color: #1a365d;
            padding: 0 0 60px;
        }
        .hrw-hero-body {
            max-width: 800px;
            margin: 0 auto;
            padding: 48px 24px 0;
            text-align: center;
        }
        .hrw-hero h1 {
            font-size: 2.2rem;
            line-height: 1.2;
            margin-bottom: 16px;
            font-weight: 800;
            color: #1a365d;
        }
        .hrw-hero .subtitle {
            font-size: 1.1rem;
            color: #4a5568;
            line-height: 1.6;
            max-width: 640px;
            margin: 0 auto 32px;
        }
        .hrw-hero-cta {
            display: inline-block;
            background: #2563eb;
            color: #fff;
            padding: 14px 32px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 700;
            text-decoration: none;
            transition: background 0.2s;
            margin-bottom: 12px;
        }
        .hrw-hero-cta:hover { background: #1d4ed8; }
        .hrw-hero-note {
            display: block;
            color: #718096;
            font-size: 0.85rem;
            margin-top: 8px;
        }

        /* ── What's included section ─────────────────────────────── */
        .hrw-section {
            padding: 80px 20px;
        }
        .hrw-section--alt { background: #f8fafc; }
        .hrw-section--white { background: #ffffff; }
        .hrw-section h2 {
            text-align: center;
            color: #1a365d;
            font-size: 1.8rem;
            margin-bottom: 12px;
        }
        .hrw-section-subtitle {
            text-align: center;
            color: #4a5568;
            font-size: 1rem;
            margin-bottom: 40px;
            max-width: 640px;
            margin-left: auto;
            margin-right: auto;
        }
        .hrw-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
            max-width: 960px;
            margin: 0 auto;
        }
        .hrw-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 28px 24px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .hrw-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
        }
        .hrw-card-icon {
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
        .hrw-card h3 {
            color: #1a365d;
            font-size: 1.05rem;
            margin-bottom: 8px;
        }
        .hrw-card p {
            color: #4a5568;
            font-size: 0.92rem;
            line-height: 1.6;
        }

        /* ── How it works ────────────────────────────────────────── */
        .hrw-steps {
            display: flex;
            flex-direction: column;
            gap: 24px;
            max-width: 680px;
            margin: 0 auto;
        }
        .hrw-step {
            display: flex;
            gap: 20px;
            align-items: flex-start;
        }
        .hrw-step-num {
            flex-shrink: 0;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #2563eb;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1rem;
        }
        .hrw-step-body h3 {
            color: #1a365d;
            font-size: 1.05rem;
            margin-bottom: 6px;
        }
        .hrw-step-body p {
            color: #4a5568;
            font-size: 0.95rem;
            line-height: 1.6;
        }

        /* ── Pricing box ─────────────────────────────────────────── */
        .hrw-pricing-box {
            max-width: 480px;
            margin: 0 auto;
            background: #fff;
            border: 2px solid #2563eb;
            border-radius: 16px;
            padding: 40px 36px;
            text-align: center;
        }
        .hrw-pricing-box .price {
            font-size: 3rem;
            font-weight: 800;
            color: #2563eb;
            line-height: 1;
            margin-bottom: 8px;
        }
        .hrw-pricing-box .price-note {
            color: #718096;
            font-size: 0.9rem;
            margin-bottom: 24px;
        }
        .hrw-pricing-checklist {
            list-style: none;
            padding: 0;
            margin: 0 0 28px;
            text-align: left;
        }
        .hrw-pricing-checklist li {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #2d3748;
            font-size: 0.95rem;
            margin-bottom: 10px;
        }
        .hrw-pricing-checklist li::before {
            content: '✓';
            color: #059669;
            font-weight: 700;
            flex-shrink: 0;
        }
        .hrw-pricing-cta {
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
        .hrw-pricing-cta:hover { background: #1d4ed8; }
        .hrw-guarantee {
            margin-top: 16px;
            color: #718096;
            font-size: 0.85rem;
        }

        /* ── Comparison table ────────────────────────────────────── */
        .hrw-compare-table {
            width: 100%;
            max-width: 700px;
            margin: 0 auto;
            border-collapse: collapse;
            font-size: 0.95rem;
        }
        .hrw-compare-table th {
            text-align: left;
            padding: 12px 16px;
            background: #1a365d;
            color: #fff;
            font-weight: 600;
        }
        .hrw-compare-table th:not(:first-child) { text-align: center; }
        .hrw-compare-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #e2e8f0;
            color: #2d3748;
        }
        .hrw-compare-table td:not(:first-child) { text-align: center; }
        .hrw-compare-table tr:nth-child(even) td { background: #f8fafc; }
        .hrw-compare-table td.hrw-ours { background: #eff6ff !important; font-weight: 600; }
        .hrw-yes { color: #059669; font-weight: 700; }
        .hrw-no  { color: #a0aec0; }

        /* ── FAQ ─────────────────────────────────────────────────── */
        .hrw-faq-list {
            max-width: 720px;
            margin: 0 auto;
        }
        .hrw-faq-item {
            border-bottom: 1px solid #e2e8f0;
            padding: 24px 0;
        }
        .hrw-faq-item h3 {
            color: #1a365d;
            font-size: 1.05rem;
            margin-bottom: 10px;
        }
        .hrw-faq-item p {
            color: #4a5568;
            font-size: 0.95rem;
            line-height: 1.7;
        }

        /* ── CTA band ────────────────────────────────────────────── */
        .hrw-cta-band {
            background: #1a365d;
            color: #fff;
            padding: 80px 20px;
            text-align: center;
        }
        .hrw-cta-band h2 {
            font-size: 2rem;
            margin-bottom: 12px;
        }
        .hrw-cta-band p {
            color: #a0aec8;
            font-size: 1rem;
            margin-bottom: 28px;
        }
        .hrw-cta-band a {
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
        .hrw-cta-band a:hover { background: #1d4ed8; }

        @media (max-width: 640px) {
            .hrw-hero h1 { font-size: 1.7rem; }
            .hrw-pricing-box { padding: 28px 20px; }
            .hrw-compare-table { font-size: 0.82rem; }
            .hrw-compare-table th, .hrw-compare-table td { padding: 10px 10px; }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/header.php'; ?>
<?php require_once __DIR__ . '/includes/consent-banner.php'; ?>

<!-- ── Hero ──────────────────────────────────────────────────────── -->
<section class="hrw-hero">
    <div class="hrw-hero-body">
        <h1>Hire Someone to Review Your Website — and Tell You Exactly What's Wrong</h1>
        <p class="subtitle">
            A professional CRO audit delivered within 24 hours. Scored report, prioritised fix list,
            no tracking code required. You get a clear answer, not a vague list of suggestions.
        </p>
        <a href="/scan" class="hrw-hero-cta">Get My Website Reviewed — <?= htmlspecialchars($priceFormatted) ?></a>
        <span class="hrw-hero-note">One-off payment &middot; 30-day money-back guarantee &middot; No subscription</span>
    </div>
</section>

<!-- ── What you get ──────────────────────────────────────────────── -->
<section class="hrw-section hrw-section--white">
    <h2>What You Get</h2>
    <p class="hrw-section-subtitle">
        Not a generic checklist. A scored analysis of your specific website with concrete,
        prioritised recommendations you can act on immediately.
    </p>
    <div class="hrw-grid">
        <div class="hrw-card">
            <div class="hrw-card-icon">📊</div>
            <h3>10-Factor Scored Report</h3>
            <p>Every conversion factor scored 0–10 and graded A+ to F, so you know exactly where your site sits and what to fix first.</p>
        </div>
        <div class="hrw-card">
            <div class="hrw-card-icon">🔍</div>
            <h3>Visual Page Analysis</h3>
            <p>We analyse your page the way a real visitor sees it — layout, headlines, calls-to-action, trust signals, and mobile experience.</p>
        </div>
        <div class="hrw-card">
            <div class="hrw-card-icon">📋</div>
            <h3>Prioritised Fix List</h3>
            <p>Fixes ordered by impact, not alphabetically. You know which three things to tackle first, and which to leave until later.</p>
        </div>
        <div class="hrw-card">
            <div class="hrw-card-icon">📄</div>
            <h3>PDF Delivered in 24h</h3>
            <p>Ready within 24 hours of payment — usually faster. A polished, shareable PDF you can hand straight to your developer.</p>
        </div>
        <div class="hrw-card">
            <div class="hrw-card-icon">🔒</div>
            <h3>No Access Required</h3>
            <p>We work from your public URL. No logins, no analytics access, no tracking code on your site.</p>
        </div>
        <div class="hrw-card">
            <div class="hrw-card-icon">📈</div>
            <h3>Benchmark vs 10,000+ Sites</h3>
            <p>Your score is calibrated against 10,000+ real business websites, so the grade actually means something.</p>
        </div>
    </div>
</section>

<!-- ── How it works ──────────────────────────────────────────────── -->
<section class="hrw-section hrw-section--alt">
    <h2>How It Works</h2>
    <p class="hrw-section-subtitle">Three steps. No calls, no back-and-forth, no waiting weeks.</p>
    <div class="hrw-steps">
        <div class="hrw-step">
            <div class="hrw-step-num">1</div>
            <div class="hrw-step-body">
                <h3>Submit your URL</h3>
                <p>Enter your website address and pay securely via PayPal. That's all we need from you.</p>
            </div>
        </div>
        <div class="hrw-step">
            <div class="hrw-step-num">2</div>
            <div class="hrw-step-body">
                <h3>We run the audit</h3>
                <p>Our AI analyses your site across 10 conversion factors, then a human expert reviews the output and writes the recommendations. Typically delivered within a few hours.</p>
            </div>
        </div>
        <div class="hrw-step">
            <div class="hrw-step-num">3</div>
            <div class="hrw-step-body">
                <h3>You receive a scored PDF</h3>
                <p>A clean, actionable report lands in your inbox. Score, grade, prioritised fix list — everything you need to improve your site without guessing.</p>
            </div>
        </div>
    </div>
</section>

<!-- ── Comparison ────────────────────────────────────────────────── -->
<section class="hrw-section hrw-section--white">
    <h2>Why Not Just Use a Freelancer?</h2>
    <p class="hrw-section-subtitle">Here's how we compare to the typical alternatives.</p>
    <div style="overflow-x:auto;">
        <table class="hrw-compare-table">
            <thead>
                <tr>
                    <th>Feature</th>
                    <th class="hrw-ours">Audit&amp;Fix</th>
                    <th>Freelancer</th>
                    <th>DIY Checklist</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Consistent scoring framework</td>
                    <td class="hrw-ours"><span class="hrw-yes">✓</span></td>
                    <td><span class="hrw-no">Rarely</span></td>
                    <td><span class="hrw-no">No</span></td>
                </tr>
                <tr>
                    <td>Benchmarked against other sites</td>
                    <td class="hrw-ours"><span class="hrw-yes">✓</span></td>
                    <td><span class="hrw-no">No</span></td>
                    <td><span class="hrw-no">No</span></td>
                </tr>
                <tr>
                    <td>Delivered within 24 hours</td>
                    <td class="hrw-ours"><span class="hrw-yes">✓</span></td>
                    <td><span class="hrw-no">1–2 weeks</span></td>
                    <td><span class="hrw-no">Your time</span></td>
                </tr>
                <tr>
                    <td>Prioritised fix list</td>
                    <td class="hrw-ours"><span class="hrw-yes">✓</span></td>
                    <td><span class="hrw-no">Sometimes</span></td>
                    <td><span class="hrw-no">No</span></td>
                </tr>
                <tr>
                    <td>No access or tracking code needed</td>
                    <td class="hrw-ours"><span class="hrw-yes">✓</span></td>
                    <td><span class="hrw-no">Usually wants access</span></td>
                    <td><span class="hrw-yes">✓</span></td>
                </tr>
                <tr>
                    <td>Money-back guarantee</td>
                    <td class="hrw-ours"><span class="hrw-yes">30 days</span></td>
                    <td><span class="hrw-no">Rarely</span></td>
                    <td>—</td>
                </tr>
                <tr>
                    <td>Typical price</td>
                    <td class="hrw-ours"><?= htmlspecialchars($priceFormatted) ?></td>
                    <td><span class="hrw-no"><?= $symbol ?>500–<?= $symbol ?>2,000+</span></td>
                    <td><span>Free (hours of work)</span></td>
                </tr>
            </tbody>
        </table>
    </div>
</section>

<!-- ── Pricing ───────────────────────────────────────────────────── -->
<section class="hrw-section hrw-section--alt">
    <h2>Simple, One-Off Pricing</h2>
    <p class="hrw-section-subtitle">No subscription. No retainer. Pay once, get your report.</p>
    <div class="hrw-pricing-box">
        <div class="price"><?= htmlspecialchars($priceFormatted) ?></div>
        <div class="price-note">One-off payment &middot; <?= htmlspecialchars($currency) ?></div>
        <ul class="hrw-pricing-checklist">
            <li>10-factor scored CRO audit</li>
            <li>A+ to F grade for each factor</li>
            <li>Prioritised fix list</li>
            <li>Visual page analysis with screenshots</li>
            <li>Benchmark vs 10,000+ websites</li>
            <li>Polished PDF delivered within 24 hours</li>
            <li>30-day money-back guarantee</li>
        </ul>
        <a href="/scan" class="hrw-pricing-cta">Get My Website Reviewed</a>
        <p class="hrw-guarantee">30-day money-back guarantee &middot; Secure payment via PayPal</p>
    </div>
</section>

<!-- ── FAQ ───────────────────────────────────────────────────────── -->
<section class="hrw-section hrw-section--white">
    <h2>Common Questions</h2>
    <div class="hrw-faq-list">
        <div class="hrw-faq-item">
            <h3>What does the website review cover?</h3>
            <p>We analyse 10 conversion factors: first impression, headline clarity, value proposition, social proof, call-to-action visibility, mobile experience, page speed, trust signals, navigation, and contact accessibility. Each is scored 0–10 and graded A+ to F.</p>
        </div>
        <div class="hrw-faq-item">
            <h3>Do I need to give you login access?</h3>
            <p>No. We review your public-facing website from the visitor's perspective. No logins, no CMS access, no analytics required.</p>
        </div>
        <div class="hrw-faq-item">
            <h3>Which page do you review?</h3>
            <p>You submit the URL you want reviewed — usually your homepage or a key landing page. If you're unsure, submit your homepage and note any specific pages you'd like us to flag.</p>
        </div>
        <div class="hrw-faq-item">
            <h3>Is this a one-off or a subscription?</h3>
            <p>One-off. You pay once, you get your report. No ongoing fees, no automatic renewals.</p>
        </div>
        <div class="hrw-faq-item">
            <h3>How is this different from Fiverr or a freelancer?</h3>
            <p>Freelance reviews vary wildly in quality and usually lack a consistent scoring framework. Our methodology is calibrated across 10,000+ websites, so your grade means something. You also get it in 24 hours with a money-back guarantee — not after two weeks of back-and-forth.</p>
        </div>
        <div class="hrw-faq-item">
            <h3>What if I'm not happy with the report?</h3>
            <p>30-day money-back guarantee, no questions asked. If the report doesn't give you actionable insights, we'll refund you in full.</p>
        </div>
    </div>
</section>

<!-- ── CTA band ───────────────────────────────────────────────────── -->
<section class="hrw-cta-band">
    <h2>Find out what's wrong with your website today</h2>
    <p>Professional review. Delivered in 24 hours. <?= htmlspecialchars($priceFormatted) ?> one-off.</p>
    <a href="/scan">Get My Website Reviewed</a>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
