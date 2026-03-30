<?php
/**
 * Website Not Converting — /website-not-converting
 *
 * Targets: "website not converting what to do", "why is my website not converting visitors to customers"
 * Problem-aware buyer. Educational content with CTA to order audit.
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Website Not Converting? Here's Why (And What to Do) — Audit&amp;Fix</title>
    <meta name="description" content="If your website gets visitors but no enquiries or sales, these 7 conversion killers are probably why. Learn what to check — and how to fix it fast.">
    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/assets/img/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="canonical" href="https://www.auditandfix.com/website-not-converting">
    <meta property="og:title" content="Website Not Converting? Here's Why — Audit&amp;Fix">
    <meta property="og:description" content="The 7 most common reasons small business websites fail to convert visitors into customers — and what to do about each one.">
    <meta property="og:type" content="article">
    <meta property="og:url" content="https://www.auditandfix.com/website-not-converting">
    <meta property="og:image" content="https://www.auditandfix.com/assets/img/og-image.png">
    <meta property="og:site_name" content="Audit&amp;Fix">
    <meta name="twitter:card" content="summary_large_image">

    <!-- Schema.org structured data -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@graph": [
        {
          "@type": "Article",
          "@id": "https://www.auditandfix.com/website-not-converting#article",
          "headline": "Website Not Converting? Here's Why (And What to Do)",
          "description": "The 7 most common reasons small business websites fail to convert visitors into customers, and actionable fixes for each.",
          "url": "https://www.auditandfix.com/website-not-converting",
          "datePublished": "2026-03-30",
          "dateModified": "2026-03-30",
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
          "mainEntityOfPage": "https://www.auditandfix.com/website-not-converting",
          "image": "https://www.auditandfix.com/assets/img/og-image.png",
          "inLanguage": "en",
          "about": {"@type": "Thing", "name": "Conversion Rate Optimisation"}
        },
        {
          "@type": "BreadcrumbList",
          "itemListElement": [
            {"@type": "ListItem", "position": 1, "name": "Home", "item": "https://www.auditandfix.com/"},
            {"@type": "ListItem", "position": 2, "name": "Website Not Converting"}
          ]
        },
        {
          "@type": "FAQPage",
          "mainEntity": [
            {
              "@type": "Question",
              "name": "Why is my website not converting visitors into customers?",
              "acceptedAnswer": {"@type": "Answer", "text": "The most common reasons are: unclear headline (visitors don't immediately understand what you offer), weak or hidden call-to-action, missing trust signals like reviews or guarantees, slow page speed, poor mobile experience, and no clear explanation of what happens after they contact you."}
            },
            {
              "@type": "Question",
              "name": "What is a good website conversion rate for a small business?",
              "acceptedAnswer": {"@type": "Answer", "text": "For a small business website generating enquiries or leads, a realistic target is 2–5%. If you're getting traffic but your conversion rate is under 1%, there are almost certainly fixable issues on the page."}
            },
            {
              "@type": "Question",
              "name": "How do I find out what's stopping people from contacting me?",
              "acceptedAnswer": {"@type": "Answer", "text": "A CRO audit reviews your site across 10 conversion factors — headline clarity, trust signals, call-to-action, mobile experience, and more — and gives you a scored report with a prioritised fix list. You get a clear answer without needing analytics history or heatmaps."}
            },
            {
              "@type": "Question",
              "name": "Can I fix conversion problems myself?",
              "acceptedAnswer": {"@type": "Answer", "text": "Yes — but you need to know which problems to fix first. Many business owners waste time on low-impact changes (redesigning their logo, tweaking colours) while ignoring high-impact issues like a confusing headline or a call-to-action that's buried below the fold."}
            }
          ]
        }
      ]
    }
    </script>

    <style>
        /* ── Website Not Converting page styles ──────────────────── */

        .wnc-hero {
            background: linear-gradient(135deg, #f8fafc 0%, #eef2f7 50%, #f0f4f9 100%);
            color: #1a365d;
            padding: 0 0 60px;
        }
        .wnc-hero-body {
            max-width: 800px;
            margin: 0 auto;
            padding: 48px 24px 0;
            text-align: center;
        }
        .wnc-hero h1 {
            font-size: 2.2rem;
            line-height: 1.2;
            margin-bottom: 16px;
            font-weight: 800;
            color: #1a365d;
        }
        .wnc-hero .subtitle {
            font-size: 1.1rem;
            color: #4a5568;
            line-height: 1.6;
            max-width: 640px;
            margin: 0 auto;
        }

        /* ── Shared section patterns ─────────────────────────────── */
        .wnc-section {
            padding: 80px 20px;
        }
        .wnc-section--alt   { background: #f8fafc; }
        .wnc-section--white { background: #ffffff; }
        .wnc-section--dark  { background: #1a365d; color: #fff; }
        .wnc-inner {
            max-width: 760px;
            margin: 0 auto;
        }
        .wnc-section h2 {
            text-align: center;
            color: #1a365d;
            font-size: 1.8rem;
            margin-bottom: 12px;
        }
        .wnc-section--dark h2 { color: #fff; }
        .wnc-section-subtitle {
            text-align: center;
            color: #4a5568;
            font-size: 1rem;
            margin-bottom: 40px;
            max-width: 640px;
            margin-left: auto;
            margin-right: auto;
        }

        /* ── Reasons list ────────────────────────────────────────── */
        .wnc-reasons {
            display: flex;
            flex-direction: column;
            gap: 0;
            max-width: 760px;
            margin: 0 auto;
        }
        .wnc-reason {
            border-bottom: 1px solid #e2e8f0;
            padding: 32px 0;
            display: grid;
            grid-template-columns: 56px 1fr;
            gap: 20px;
            align-items: flex-start;
        }
        .wnc-reason:last-child { border-bottom: none; }
        .wnc-reason-num {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: #dbeafe;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        .wnc-reason h3 {
            color: #1a365d;
            font-size: 1.1rem;
            margin-bottom: 8px;
        }
        .wnc-reason p {
            color: #4a5568;
            font-size: 0.95rem;
            line-height: 1.7;
            margin-bottom: 8px;
        }
        .wnc-reason .fix-tag {
            display: inline-block;
            background: #dcfce7;
            color: #166534;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
        }

        /* ── Stat callout ────────────────────────────────────────── */
        .wnc-stat-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            max-width: 760px;
            margin: 0 auto;
        }
        .wnc-stat {
            text-align: center;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 28px 20px;
        }
        .wnc-stat .stat-num {
            font-size: 2.2rem;
            font-weight: 800;
            color: #2563eb;
            display: block;
            margin-bottom: 6px;
        }
        .wnc-stat p {
            color: #4a5568;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        /* ── Audit offer box ─────────────────────────────────────── */
        .wnc-offer-box {
            background: #fff;
            border: 2px solid #2563eb;
            border-radius: 16px;
            padding: 40px 36px;
            max-width: 640px;
            margin: 0 auto;
        }
        .wnc-offer-box h3 {
            color: #1a365d;
            font-size: 1.4rem;
            margin-bottom: 12px;
        }
        .wnc-offer-box p {
            color: #4a5568;
            font-size: 0.95rem;
            line-height: 1.7;
            margin-bottom: 20px;
        }
        .wnc-offer-checklist {
            list-style: none;
            padding: 0;
            margin: 0 0 28px;
        }
        .wnc-offer-checklist li {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #2d3748;
            font-size: 0.95rem;
            margin-bottom: 10px;
        }
        .wnc-offer-checklist li::before {
            content: '✓';
            color: #059669;
            font-weight: 700;
            flex-shrink: 0;
        }
        .wnc-offer-cta {
            display: inline-block;
            background: #2563eb;
            color: #fff;
            padding: 13px 28px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.95rem;
            text-decoration: none;
            transition: background 0.2s;
        }
        .wnc-offer-cta:hover { background: #1d4ed8; }
        .wnc-offer-note {
            display: block;
            color: #718096;
            font-size: 0.82rem;
            margin-top: 10px;
        }

        /* ── FAQ ─────────────────────────────────────────────────── */
        .wnc-faq-list {
            max-width: 720px;
            margin: 0 auto;
        }
        .wnc-faq-item {
            border-bottom: 1px solid #e2e8f0;
            padding: 24px 0;
        }
        .wnc-faq-item h3 {
            color: #1a365d;
            font-size: 1.05rem;
            margin-bottom: 10px;
        }
        .wnc-faq-item p {
            color: #4a5568;
            font-size: 0.95rem;
            line-height: 1.7;
        }

        /* ── CTA band ────────────────────────────────────────────── */
        .wnc-cta-band {
            background: #1a365d;
            color: #fff;
            padding: 80px 20px;
            text-align: center;
        }
        .wnc-cta-band h2 { font-size: 2rem; margin-bottom: 12px; }
        .wnc-cta-band p  { color: #a0aec8; font-size: 1rem; margin-bottom: 28px; }
        .wnc-cta-band a {
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
        .wnc-cta-band a:hover { background: #1d4ed8; }

        @media (max-width: 640px) {
            .wnc-hero h1 { font-size: 1.7rem; }
            .wnc-stat-row { grid-template-columns: 1fr; }
            .wnc-reason { grid-template-columns: 44px 1fr; }
            .wnc-offer-box { padding: 28px 20px; }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/header.php'; ?>
<?php require_once __DIR__ . '/includes/consent-banner.php'; ?>

<!-- ── Hero ──────────────────────────────────────────────────────── -->
<section class="wnc-hero">
    <div class="wnc-hero-body">
        <h1>Your Website's Getting Visitors But No Enquiries — Here's Why</h1>
        <p class="subtitle">
            Traffic without conversions means something on your page is stopping people from taking action.
            Here are the 7 most common reasons — and what to do about each one.
        </p>
    </div>
</section>

<!-- ── Stats ─────────────────────────────────────────────────────── -->
<section class="wnc-section wnc-section--white">
    <div class="wnc-stat-row">
        <div class="wnc-stat">
            <span class="stat-num">~2%</span>
            <p>Average website conversion rate. If you're below this, you're leaving enquiries on the table.</p>
        </div>
        <div class="wnc-stat">
            <span class="stat-num">8 sec</span>
            <p>How long a visitor takes to decide whether your site is worth their time.</p>
        </div>
        <div class="wnc-stat">
            <span class="stat-num">70%</span>
            <p>Of small business websites have a weak or missing call-to-action above the fold.</p>
        </div>
    </div>
</section>

<!-- ── 7 Reasons ─────────────────────────────────────────────────── -->
<section class="wnc-section wnc-section--alt">
    <h2>7 Reasons Your Website Isn't Converting</h2>
    <p class="wnc-section-subtitle">
        Most conversion problems come down to one of these. The hard part is knowing which ones apply to your site.
    </p>
    <div class="wnc-reasons">
        <div class="wnc-reason">
            <div class="wnc-reason-num">1</div>
            <div>
                <h3>Your headline doesn't explain what you do</h3>
                <p>Visitors decide in the first few seconds whether they're in the right place. If your headline leads with your business name, a tagline, or something vague like "Excellence in service," most people will leave before they understand what you offer.</p>
                <p><strong>Fix:</strong> Your headline should answer "What do you do, for whom, and why should I care?" in one sentence. "Professional plumbing for Sydney homeowners — available same day" beats "Welcome to Smith Plumbing" every time.</p>
                <span class="fix-tag">High impact</span>
            </div>
        </div>
        <div class="wnc-reason">
            <div class="wnc-reason-num">2</div>
            <div>
                <h3>Your call-to-action is buried or unclear</h3>
                <p>If a visitor has to scroll to find out how to contact you, or if your button says "Submit" instead of "Get a Free Quote," you're losing people at the point of conversion.</p>
                <p><strong>Fix:</strong> Your primary CTA should be visible without scrolling on both desktop and mobile. Use action-oriented language: "Get a Quote," "Book a Call," "Start Your Audit" — not "Contact Us" or "Learn More."</p>
                <span class="fix-tag">High impact</span>
            </div>
        </div>
        <div class="wnc-reason">
            <div class="wnc-reason-num">3</div>
            <div>
                <h3>You're not giving visitors a reason to trust you</h3>
                <p>Buying from an unknown website is a risk. Without social proof — reviews, testimonials, case studies, recognisable client logos — visitors default to the safe option (your competitor who has 50 Google reviews).</p>
                <p><strong>Fix:</strong> Place your strongest review or testimonial near the top of the page, not buried in a "Testimonials" section. Show the number of customers served. Display any guarantees prominently.</p>
                <span class="fix-tag">High impact</span>
            </div>
        </div>
        <div class="wnc-reason">
            <div class="wnc-reason-num">4</div>
            <div>
                <h3>Your page loads too slowly</h3>
                <p>For every second of load time beyond 2–3 seconds, conversion rates drop significantly. Mobile users especially will abandon a slow site before it finishes loading.</p>
                <p><strong>Fix:</strong> Compress images (use WebP instead of PNG/JPG), reduce unnecessary scripts and plugins, and check your hosting. Run Google PageSpeed Insights for a baseline.</p>
                <span class="fix-tag">Medium impact</span>
            </div>
        </div>
        <div class="wnc-reason">
            <div class="wnc-reason-num">5</div>
            <div>
                <h3>The mobile experience is broken or frustrating</h3>
                <p>More than half your visitors are on mobile. If your site looks fine on desktop but has tiny text, overlapping elements, or a contact form that's hard to fill in on a phone, you're losing those visitors entirely.</p>
                <p><strong>Fix:</strong> Test your site on your own phone. Is the text readable without zooming? Can you tap the buttons easily? Can you complete the enquiry form without frustration? If not, prioritise mobile fixes above everything else.</p>
                <span class="fix-tag">High impact on mobile traffic</span>
            </div>
        </div>
        <div class="wnc-reason">
            <div class="wnc-reason-num">6</div>
            <div>
                <h3>Your value proposition isn't clear</h3>
                <p>Even if visitors understand what you do, they need to understand why you're the right choice over the alternatives. "Quality service at competitive prices" says nothing — every competitor says the same.</p>
                <p><strong>Fix:</strong> Be specific about what makes you different. Same-day service? 10 years experience? A fixed price (no surprises)? A satisfaction guarantee? Pick your strongest differentiator and lead with it.</p>
                <span class="fix-tag">Medium impact</span>
            </div>
        </div>
        <div class="wnc-reason">
            <div class="wnc-reason-num">7</div>
            <div>
                <h3>Visitors don't know what happens after they contact you</h3>
                <p>Anxiety about the process kills conversions. If someone doesn't know whether submitting your form will result in an immediate call, a quote within 48 hours, or a sales pitch, many will simply not bother.</p>
                <p><strong>Fix:</strong> Set expectations on the page. "Submit your details and we'll call you within 2 hours" removes the uncertainty. Showing the 3-step process ("You enquire → we assess → we quote") gives people confidence to proceed.</p>
                <span class="fix-tag">Medium impact</span>
            </div>
        </div>
    </div>
</section>

<!-- ── Offer box ──────────────────────────────────────────────────── -->
<section class="wnc-section wnc-section--white">
    <h2>Not Sure Which of These Apply to Your Site?</h2>
    <p class="wnc-section-subtitle">
        A CRO audit gives you a scored analysis across all 10 conversion factors,
        so you know exactly what to fix — without guessing.
    </p>
    <div class="wnc-offer-box">
        <h3>Get a Professional Website Audit — <?= htmlspecialchars($priceFormatted) ?></h3>
        <p>
            Submit your URL and we'll analyse your site across all 10 conversion factors.
            You'll receive a scored PDF report within 24 hours — grade for each factor,
            prioritised fix list, and specific recommendations for your site.
        </p>
        <ul class="wnc-offer-checklist">
            <li>10-factor scored CRO audit</li>
            <li>Visual analysis — we see what your visitors see</li>
            <li>Prioritised fix list (not a generic checklist)</li>
            <li>Delivered within 24 hours as a PDF</li>
            <li>30-day money-back guarantee</li>
            <li>No tracking code, no access required</li>
        </ul>
        <a href="/scan" class="wnc-offer-cta">Get My Audit — <?= htmlspecialchars($priceFormatted) ?></a>
        <span class="wnc-offer-note">One-off payment &middot; <?= htmlspecialchars($currency) ?> &middot; Secure via PayPal</span>
    </div>
</section>

<!-- ── FAQ ───────────────────────────────────────────────────────── -->
<section class="wnc-section wnc-section--alt">
    <h2>Common Questions</h2>
    <div class="wnc-faq-list">
        <div class="wnc-faq-item">
            <h3>Why is my website not converting visitors into customers?</h3>
            <p>The most common causes are: unclear headline, missing or weak call-to-action, no visible social proof, slow page load, and poor mobile experience. Any one of these can cut your conversion rate significantly. A CRO audit tells you which ones apply to your specific site.</p>
        </div>
        <div class="wnc-faq-item">
            <h3>What's a good conversion rate for a small business website?</h3>
            <p>For enquiry-based small business sites, a realistic target is 2–5%. If you're getting traffic but your conversion rate is under 1%, there are almost certainly fixable issues on the page. Even improving from 1% to 2% doubles your leads from the same traffic.</p>
        </div>
        <div class="wnc-faq-item">
            <h3>Can I fix this myself without hiring someone?</h3>
            <p>Absolutely — but you need to know which problems to fix first. Most business owners spend time on low-impact changes (redesigning their logo, tweaking colour schemes) while missing high-impact issues like a confusing headline or a CTA that's below the fold. A scored audit tells you where to focus your time.</p>
        </div>
        <div class="wnc-faq-item">
            <h3>How do I know if the audit will help my specific site?</h3>
            <p>Every report is analysed against your specific URL — not a template. If the report doesn't give you actionable insights you can act on, we offer a 30-day money-back guarantee, no questions asked.</p>
        </div>
    </div>
</section>

<!-- ── CTA band ───────────────────────────────────────────────────── -->
<section class="wnc-cta-band">
    <h2>Stop guessing. Find out what's stopping conversions.</h2>
    <p>Scored audit. Prioritised fixes. Delivered in 24 hours. <?= htmlspecialchars($priceFormatted) ?> one-off.</p>
    <a href="/scan">Get My Website Audit</a>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
