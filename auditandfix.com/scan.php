<?php
/**
 * Free Website Scanner — Inbound Funnel Entry Page
 *
 * Flow:
 *   1. User enters URL (hero, left side) → animated scoring → score reveal
 *   2. Issue teaser shown immediately (free peek + blurred extras)
 *   3. Email capture gates the full factor breakdown (below fold)
 *   4. Sales content below banner mirrors index.php (rewording for SEO)
 *   5. CTAs: $47 Quick Fixes or $297 Full Audit
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/geo.php';

$countryCode = detectCountry();

$utmSource   = isset($_GET['utm_source'])   ? htmlspecialchars($_GET['utm_source'])   : '';
$utmMedium   = isset($_GET['utm_medium'])   ? htmlspecialchars($_GET['utm_medium'])   : '';
$utmCampaign = isset($_GET['utm_campaign']) ? htmlspecialchars($_GET['utm_campaign']) : '';
$ref         = isset($_GET['ref'])          ? htmlspecialchars($_GET['ref'])          : '';

$prefillUrl = isset($_GET['url']) ? filter_var($_GET['url'], FILTER_VALIDATE_URL) ?: '' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Free Website Audit Tool — Score Your Site in 30 Seconds | Audit&Fix</title>
    <meta name="description" content="Enter your URL and get an instant website conversion score. Our AI has scored 23,000+ websites. The average? D+. Find out where you stand. Free, 30 seconds.">
    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('assets/css/scanner.css') ?>">
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="assets/img/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="canonical" href="https://www.auditandfix.com/scan">
    <?php foreach (SUPPORTED_LANGS as $_hl): ?>
    <link rel="alternate" hreflang="<?= htmlspecialchars($_hl) ?>" href="https://www.auditandfix.com/scan?lang=<?= htmlspecialchars($_hl) ?>">
    <?php endforeach; ?>
    <link rel="alternate" hreflang="x-default" href="https://www.auditandfix.com/scan">
    <meta property="og:title" content="What grade does your website get?">
    <meta property="og:description" content="We scored 23,000+ small business websites. The average grade? D+. Check yours free in 30 seconds.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://www.auditandfix.com/scan">
    <meta property="og:image" content="https://www.auditandfix.com/assets/img/og-image.png">
    <meta property="og:site_name" content="Audit&Fix">
    <meta name="twitter:card" content="summary_large_image">
    <!-- Schema.org structured data -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@graph": [
        {
          "@type": "WebApplication",
          "name": "Audit&Fix Free Website Audit Tool",
          "url": "https://www.auditandfix.com/scan",
          "applicationCategory": "BusinessApplication",
          "description": "Free website conversion audit tool. Scores your site across 10 conversion factors in 30 seconds. No signup required.",
          "offers": {
            "@type": "Offer",
            "price": "0",
            "priceCurrency": "AUD"
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
            {"@type":"Question","name":"How long does the free scan take?","acceptedAnswer":{"@type":"Answer","text":"The free website audit takes about 10\u201330 seconds. No signup required \u2014 just enter your URL."}},
            {"@type":"Question","name":"What does the free scan check?","acceptedAnswer":{"@type":"Answer","text":"The free scan scores your website across 10 conversion factors: headline quality, value proposition, unique selling proposition, call-to-action, trust signals, urgency messaging, hook engagement, imagery and design, offer clarity, and industry appropriateness."}},
            {"@type":"Question","name":"How is this different from Google Lighthouse?","acceptedAnswer":{"@type":"Answer","text":"Google Lighthouse scores page speed and accessibility \u2014 it cannot evaluate whether your headline is compelling, your CTA is placed correctly, or whether you have adequate trust signals. Those are the conversion factors we measure."}},
            {"@type":"Question","name":"Do I need to create an account?","acceptedAnswer":{"@type":"Answer","text":"No. The free scan requires no signup. You only need to provide your email if you want to unlock the full factor breakdown."}}
          ]
        }
      ]
    }
    </script>
</head>
<body class="scanner-page">
<a href="#main-content" class="skip-link">Skip to main content</a>

<!-- ── Hero Banner ───────────────────────────────────────────────────────── -->
<header class="hero scan-hero-banner">
    <nav class="nav" aria-label="Site navigation">
        <a href="/" class="logo">
            <img src="assets/img/logo.svg" alt="Audit&amp;Fix" class="logo-img"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='inline'">
            <span class="logo-text" style="display:none">Audit<span class="logo-amp">&amp;</span>Fix</span>
        </a>
        <div class="nav-right">
            <a href="/" class="nav-cta">Full Audit →</a>
        </div>
    </nav>

    <div class="hero-body">
        <div class="hero-content">
            <div id="scan-status" role="status" aria-live="polite" aria-atomic="true" class="sr-only"></div>
            <p class="pre-headline">Free website score</p>
            <h1>Free Website Audit Tool</h1>
            <p class="scan-h1-sub">Check your conversion score in 30 seconds — free, no signup required</p>
            <p class="subtitle">We've analysed <strong>23,990+</strong> small business websites. The average conversion grade? <strong class="grade-bad">D+</strong>. See where yours stands — free, in 30 seconds.</p>

            <!-- Stage 1: Input -->
            <div id="stage-input">
                <p class="scan-intro">Our free website audit tool scores your site across 10 conversion factors — headline clarity, trust signals, call-to-action placement, mobile experience, and more. Used by 20,000+ small business owners. No signup required.</p>
                <form class="scan-form" id="scan-form" novalidate>
                    <div class="scan-input-row">
                        <label for="scan-url" class="sr-only">Your website URL</label>
                        <input
                            type="url"
                            id="scan-url"
                            name="url"
                            class="scan-url-input"
                            placeholder="yourbusiness.com"
                            value="<?= htmlspecialchars($prefillUrl) ?>"
                            autocomplete="url"
                            inputmode="url"
                            required
                        >
                        <button type="submit" class="scan-btn" id="scan-btn">
                            Score My Website →
                        </button>
                    </div>
                    <p class="scan-form-note">Free. No signup required. Takes about 10 seconds.</p>
                    <div id="scan-error" class="scan-error" role="alert" style="display:none"></div>
                </form>

                <div class="hero-scan-trust">
                    <span>✓ No credit card</span>
                    <span>✓ 23,990+ sites scored</span>
                    <span>✓ Instant results</span>
                </div>
            </div>

            <!-- Stage 2: Scanning animation -->
            <div id="stage-scanning" style="display:none">
                <div class="scan-progress-inner">
                    <div class="scan-spinner"></div>
                    <h2 class="scan-progress-title">Analysing your website…</h2>
                    <div class="scan-progress-steps" id="progress-steps">
                        <div class="progress-step" id="step-fetch">Fetching page content</div>
                        <div class="progress-step" id="step-headline">Checking headline quality</div>
                        <div class="progress-step" id="step-cta">Analysing call-to-action</div>
                        <div class="progress-step" id="step-trust">Looking for trust signals</div>
                        <div class="progress-step" id="step-value">Reviewing value proposition</div>
                        <div class="progress-step" id="step-score">Computing conversion score</div>
                    </div>
                    <p class="scan-progress-domain" id="progress-domain"></p>
                </div>
            </div>

            <!-- Stage 3: Score reveal -->
            <div id="stage-results" style="display:none">

                <div class="score-reveal">
                    <div class="score-gauge-wrap">
                        <svg class="score-gauge" viewBox="0 0 120 70" aria-hidden="true">
                            <path class="gauge-bg"   d="M10,60 A50,50 0 0,1 110,60" fill="none" stroke="rgba(255,255,255,0.2)" stroke-width="10" stroke-linecap="round"/>
                            <path class="gauge-fill" id="gauge-fill" d="M10,60 A50,50 0 0,1 110,60" fill="none" stroke="#e05d26" stroke-width="10" stroke-linecap="round" stroke-dasharray="157" stroke-dashoffset="157"/>
                        </svg>
                        <div class="score-grade-wrap">
                            <span class="score-grade" id="score-grade">?</span>
                        </div>
                        <div class="score-number-wrap">
                            <span class="score-number" id="score-number">0</span>
                            <span class="score-denom">/100</span>
                        </div>
                    </div>
                    <div class="score-domain-label" id="score-domain-label"></div>
                    <div class="score-context" id="score-context"></div>
                </div>

                <!-- Issue teaser -->
                <div class="issue-teaser" id="issue-teaser" style="display:none">
                    <div class="teaser-free-peek" id="teaser-free-peek"></div>
                    <div class="teaser-blur-wrap">
                        <div class="teaser-blur-rows">
                            <div class="teaser-blur-row"></div>
                            <div class="teaser-blur-row"></div>
                            <div class="teaser-blur-row teaser-blur-row--short"></div>
                        </div>
                        <div class="teaser-blur-overlay">
                            <span class="teaser-blur-label">+<span id="teaser-hidden-count">?</span> more issues — enter your email to unlock</span>
                        </div>
                    </div>
                </div>

                <!-- Email gate -->
                <div class="email-gate" id="email-gate">
                    <h3 class="email-gate-title" id="email-gate-title">Analysing your results…</h3>
                    <p class="email-gate-desc" id="email-gate-desc">Enter your email to unlock all factor scores and see your biggest problem in detail.</p>
                    <form class="email-form" id="email-form" novalidate>
                        <div class="email-input-row">
                            <label for="email-input" class="sr-only">Your email address</label>
                            <input
                                type="email"
                                id="email-input"
                                name="email"
                                class="email-input"
                                placeholder="your@email.com"
                                autocomplete="email"
                                required
                            >
                            <button type="submit" class="email-btn" id="email-btn">Unlock My Factors →</button>
                        </div>
                        <div class="email-optin-row">
                            <label class="email-optin-label">
                                <input type="checkbox" id="email-optin" name="optin" value="1" class="email-optin-checkbox">
                                <span>Yes, email me tips and occasional offers from Audit&amp;Fix</span>
                            </label>
                        </div>
                        <div id="email-error" class="scan-error" role="alert" style="display:none"></div>
                        <p class="email-note">We'll also notify you if your score changes. Unsubscribe any time.</p>
                    </form>
                </div>

            </div><!-- /stage-results -->

        </div><!-- /hero-content -->
    </div><!-- /hero-body -->
</header>

<!-- ── Full results (below fold, shown after email submit) ───────────────── -->
<div id="results-main" style="display:none">
    <section class="scan-results-section">
        <div class="scan-results-inner">

            <div class="factor-breakdown" id="factor-breakdown">
                <h3 class="factors-title">Your 10-factor breakdown</h3>
                <div class="factor-list" id="factor-list"></div>

                <div class="free-peek" id="free-peek" style="display:none">
                    <div class="free-peek-badge">Free insight</div>
                    <h4 class="free-peek-factor" id="free-peek-factor"></h4>
                    <div class="free-peek-score-row">
                        <span class="free-peek-score" id="free-peek-score"></span>
                        <div class="free-peek-bar-wrap">
                            <div class="free-peek-bar" id="free-peek-bar"></div>
                        </div>
                    </div>
                    <p class="free-peek-reasoning" id="free-peek-reasoning"></p>
                </div>

                <div class="js-heavy-note" id="js-heavy-note" style="display:none">
                    <p>⚡ This site uses advanced JavaScript — our HTML analysis gives it a neutral score. A full visual audit would provide a more accurate picture.</p>
                </div>

                <!-- CTAs -->
                <div class="scan-ctas">
                    <div class="scan-cta-card scan-cta-full">
                        <div class="cta-badge">Best value</div>
                        <h4>Full CRO Audit</h4>
                        <div class="cta-price" id="cta-full-price">$297</div>
                        <ul class="cta-features">
                            <li>AI vision analysis (sees rendered page)</li>
                            <li>Annotated problem screenshots</li>
                            <li>Full prioritised action plan</li>
                            <li>Competitor comparison</li>
                            <li>Exact replacement copy for every fix</li>
                        </ul>
                        <a href="/" class="cta-btn cta-btn-primary" id="cta-full-audit">Get Full Audit →</a>
                    </div>
                    <div class="scan-cta-card scan-cta-quick">
                        <h4>Quick Fixes Report</h4>
                        <div class="cta-price">$47</div>
                        <ul class="cta-features">
                            <li>All 10 factor scores (detailed)</li>
                            <li>Top 3 quick wins with exact copy</li>
                            <li>Before → After examples</li>
                            <li>PDF delivered instantly</li>
                        </ul>
                        <p class="cta-credit-note">Credit applies toward the full audit</p>
                        <a href="#" class="cta-btn cta-btn-secondary" id="cta-quick-fixes">Get Quick Fixes →</a>
                    </div>
                </div>

                <p class="scan-guarantee">30-day money-back guarantee. If you're not satisfied, full refund — no questions asked.</p>
            </div>

        </div>
    </section>
</div>

<!-- ── Sales content (always visible below banner) ───────────────────────── -->
<main id="main-content">

    <!-- Social proof -->
    <section class="testimonials">
        <div class="container">
            <p class="testimonials-rating">★★★★★ Trusted by small business owners</p>
            <h2>What business owners say after getting their score</h2>
            <div class="testimonial-grid">
                <div class="testimonial">
                    <div class="stars" role="img" aria-label="5 out of 5 stars">★★★★★</div>
                    <p>"I'd been spending money on Google Ads and sending traffic to a site that was quietly losing me enquiries. The free score told me enough — the full audit told me exactly what to do."</p>
                    <div class="testimonial-author">
                        <strong>Sarah T.</strong>
                        <span>Interior Designer, Melbourne</span>
                    </div>
                </div>
                <div class="testimonial">
                    <div class="stars" role="img" aria-label="5 out of 5 stars">★★★★★</div>
                    <p>"Scored 52 when I thought we had a decent site. The report showed us the exact screenshots where visitors were losing confidence. Fixed the trust signals that weekend."</p>
                    <div class="testimonial-author">
                        <strong>James K.</strong>
                        <span>Electrical Contractor, Auckland</span>
                    </div>
                </div>
                <div class="testimonial">
                    <div class="stars" role="img" aria-label="5 out of 5 stars">★★★★★</div>
                    <p>"No fluff, no generic advice. Real screenshots of my site, real explanations, and a ranked list of what to fix first. Worth every cent."</p>
                    <div class="testimonial-author">
                        <strong>Michelle R.</strong>
                        <span>Physiotherapy Clinic, Brisbane</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Report preview -->
    <section class="montage-section">
        <div class="container">
            <h2>A professional report — not a generic checklist</h2>
            <p class="section-subhead">A scored PDF with real screenshots from your site, 10 factors graded A+ to F, and a prioritised fix list — written for business owners, not developers.</p>
            <div class="montage-grid">
                <div class="montage-item">
                    <img src="assets/img/report-cover.png" alt="Report cover page showing domain, score and grade" loading="lazy">
                    <p>Cover page with your overall grade</p>
                </div>
                <div class="montage-item">
                    <img src="assets/img/report-factors.png" alt="Factor analysis page with score bars" loading="lazy">
                    <p>Every factor scored with evidence</p>
                </div>
                <div class="montage-item">
                    <img src="assets/img/report-screenshots.png" alt="Zoomed screenshots of problem areas" loading="lazy">
                    <p>Zoomed screenshots of the actual problems</p>
                </div>
                <div class="montage-item">
                    <img src="assets/img/report-recommendations.png" alt="Prioritised recommendations page" loading="lazy">
                    <p>Ranked fix list — highest-impact wins first</p>
                </div>
            </div>
            <div class="sample-download">
                <a href="assets/sample-reports/sample-cro-audit.pdf" class="btn-secondary" target="_blank">Download Sample Report (PDF)</a>
                <span class="sample-note">Available in all languages</span>
            </div>
        </div>
    </section>

    <!-- Value props -->
    <section class="value-props">
        <div class="container">
            <div class="prop-grid">
                <div class="prop"><div class="prop-icon" aria-hidden="true">🔍</div><h3>Screenshots of Every Problem</h3><p>We zoom in on the exact parts of your page that are costing you conversions — so you can see the issue, not just read about it.</p></div>
                <div class="prop"><div class="prop-icon" aria-hidden="true">📊</div><h3>10 Factors Graded A+ to F</h3><p>Headline, CTA, trust signals, urgency, and more — each graded independently, so you know precisely what to fix and in what order.</p></div>
                <div class="prop"><div class="prop-icon" aria-hidden="true">💬</div><h3>Plain English, No Jargon</h3><p>Written for business owners, not developers. Every finding tells you what it is, why it matters, and exactly how to fix it.</p></div>
                <div class="prop"><div class="prop-icon" aria-hidden="true">📈</div><h3>Agency Quality at a Fraction of the Price</h3><p>Professional CRO audits from agencies start at $2,500. We deliver the same expert-level analysis for a fraction of that — with same-day turnaround.</p></div>
            </div>
        </div>
    </section>

    <!-- What's included -->
    <section class="included">
        <div class="container">
            <h2>What your full audit covers</h2>
            <p class="section-subhead">Each of the 10 conversion factors is scored 0–10 and graded A+ to F — so you know exactly what to fix and why.</p>
            <div class="checklist">
                <div class="check-item">✓ <strong>Headline quality</strong> — does it communicate value in 3–5 seconds?</div>
                <div class="check-item">✓ <strong>Value proposition</strong> — benefits, not features; "what's in it for me?"</div>
                <div class="check-item">✓ <strong>Unique selling proposition</strong> — why choose you over alternatives?</div>
                <div class="check-item">✓ <strong>Call-to-action</strong> — prominence, placement, and copy clarity</div>
                <div class="check-item">✓ <strong>Trust &amp; credibility signals</strong> — testimonials, badges, certifications</div>
                <div class="check-item">✓ <strong>Urgency &amp; scarcity</strong> — legitimate pressure to act now</div>
                <div class="check-item">✓ <strong>Hook &amp; visual engagement</strong> — first-impression hero element</div>
                <div class="check-item">✓ <strong>Imagery &amp; design</strong> — authentic photos vs. generic stock</div>
                <div class="check-item">✓ <strong>Offer clarity</strong> — do visitors know exactly what they get?</div>
                <div class="check-item">✓ <strong>Industry appropriateness</strong> — does the design fit your market?</div>
            </div>
            <p class="included-footer">Plus: zoomed screenshots of every problem area, a two-pass below-the-fold analysis, and a technical assessment covering SSL, mobile responsiveness, and page speed.</p>
        </div>
    </section>

    <!-- USP -->
    <section class="usp">
        <div class="container">
            <h2>Not a checklist. Not a sales call. An actual report.</h2>
            <p>Free tools like Google Lighthouse score page speed — they can't see that your headline is vague, your CTA is buried on mobile, or that you have no trust signals above the fold. That's what loses customers.</p>
            <p>Unlike agency audits priced at $2,500–$15,000 for enterprise clients, we're built for small business owners. You get the same depth of analysis, delivered the same day, with no sales calls, no retainers, and no lock-in.</p>
        </div>
    </section>

    <!-- Urgency mid-page CTA -->
    <section class="urgency-section">
        <div class="container">
            <div class="urgency-inner">
                <div class="urgency-text">
                    <h2>Every day your site underconverts, you're leaving leads on the table</h2>
                    <p>If your site converts at 1% and a fix pushes it to 2%, that's <strong>double the leads from the same traffic</strong> — without spending another cent on ads.</p>
                    <p class="urgency-scarcity">⏱ Reports are processed in batches, twice daily. Order before 8 pm to receive your report by the following morning.</p>
                </div>
                <div class="urgency-cta">
                    <a href="/" class="cta-button cta-button--light">Get Your Full Audit — $297</a>
                    <p class="urgency-note">24-hour delivery · Money-back guarantee</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Meet Marcus -->
    <section class="meet-expert">
        <div class="container">
            <div class="expert-card">
                <img src="assets/img/marcus-webb.jpg" alt="Marcus Webb, CRO Specialist" class="expert-photo">
                <div class="expert-bio">
                    <p class="expert-label">A note from your analyst</p>
                    <h2>Marcus Webb</h2>
                    <p class="expert-title">CRO Specialist &amp; Marketing Strategist</p>
                    <p>Hi, I'm Marcus. Our team has 24 years of combined digital marketing experience — and we've seen the same conversion mistakes repeated across thousands of small business websites.</p>
                    <p>We've worked with clients across retail, trades, health, and hospitality. Our edge? We combine hands-on marketing expertise with AI-powered analysis that can see and read your page the same way a customer does.</p>
                    <p><strong>Every report that comes through this system gets my eyes on it.</strong> If something doesn't look right, I flag it before it goes out.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ -->
    <section class="faq">
        <div class="container">
            <h2>Frequently Asked Questions</h2>
            <div class="faq-item">
                <h3>How long until I get my report?</h3>
                <p>Reports are delivered to your email within 24 hours of payment. Most are delivered within a few hours during business hours.</p>
            </div>
            <div class="faq-item">
                <h3>What format is the report?</h3>
                <p>A professional PDF (typically 9 pages) with scored analysis, zoomed screenshots, a prioritised fix list, and plain-English explanations for every finding.</p>
            </div>
            <div class="faq-item">
                <h3>How is this different from free tools like Google Lighthouse?</h3>
                <p>Lighthouse scores your page speed and accessibility — useful, but unrelated to conversions. It can't tell you that your headline is weak, your CTA is buried, or that you're missing trust signals. That's what we look for.</p>
            </div>
            <div class="faq-item">
                <h3>What if my site already scores well on the free scan?</h3>
                <p>Even high-scoring sites have room for improvement. Our full audit uses real screenshot analysis and goes deeper than the free HTML-only scan — catching issues that only appear on the rendered page.</p>
            </div>
            <div class="faq-item">
                <h3>Is there a follow-up option after I've made changes?</h3>
                <p>Yes — once you've implemented fixes, you can order a follow-up benchmarking audit at 50% of the original price to measure your improvement.</p>
            </div>
            <div class="faq-item">
                <h3>Do you implement the fixes?</h3>
                <p>The report identifies and prioritises the issues — implementation is up to you or your developer. Most quick wins take under an hour to implement.</p>
            </div>
        </div>
    </section>

    <!-- Footer CTA -->
    <section class="footer-cta">
        <div class="container">
            <h2>Ready to see what's holding your site back?</h2>
            <a href="/" class="cta-button">Get Your Full Audit — $297</a>
            <p>24-hour delivery · Money-back guarantee · Plain English, no jargon</p>
        </div>
    </section>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
    window.SCAN_CONFIG = {
        apiBase: '<?= rtrim((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'], '/') ?>',
        utmSource:   '<?= $utmSource ?>',
        utmMedium:   '<?= $utmMedium ?>',
        utmCampaign: '<?= $utmCampaign ?>',
        ref:         '<?= $ref ?>',
        countryCode: '<?= htmlspecialchars($countryCode) ?>',
    };
</script>
<script src="assets/js/obfuscate-email.js?v=1" defer></script>
<script src="<?= asset_url('assets/js/scanner.js') ?>" defer></script>
</body>
</html>
