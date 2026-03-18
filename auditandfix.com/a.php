<?php
/**
 * Cold Outreach Landing Page — /a/{site_id}
 *
 * URL format:  /a/{site_id}   (e.g. auditandfix.com/a/17757)
 *
 * Shows a personalised landing page for outreach recipients:
 *  - Acknowledges we already audited their site
 *  - Shows their domain + teaser score/grade (if available)
 *  - CTA to order the full report
 *
 * Reads prefill data from data/orders/{site_id}.json (written by 333Method
 * reply-processor when generating outreach messages).
 *
 * If no data file exists, falls back to a generic "we audited your site" page.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/geo.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/pricing.php';

// ── Extract site_id from URL ──────────────────────────────────────────────
// URL slug is base62-encoded site_id for opacity (can't enumerate).

$siteId = null;

function extractSlug(): string {
    $pathInfo = $_SERVER['PATH_INFO'] ?? '';
    if (preg_match('#^/([A-Za-z0-9]+)$#', $pathInfo, $m)) {
        return $m[1];
    }
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (preg_match('#/a/([A-Za-z0-9]+)#', $uri, $m)) {
        return $m[1];
    }
    return '';
}

$slug = extractSlug();
if ($slug !== '') {
    // Try base62 decode first; fall back to plain numeric for legacy numeric URLs
    $decoded = base62_decode($slug);
    if ($decoded > 0) {
        $siteId = $decoded;
    } elseif (ctype_digit($slug)) {
        $siteId = (int) $slug;
    }
}

// ── Load prefill data ─────────────────────────────────────────────────────

$prefill = [];
if ($siteId) {
    $dataFile = __DIR__ . '/data/orders/' . $siteId . '.json';
    if (file_exists($dataFile)) {
        $raw = @file_get_contents($dataFile);
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $prefill = $decoded;
            }
        }
    }
}

$domain  = !empty($prefill['domain'])  ? $prefill['domain']  : null;
$score   = isset($prefill['score'])    ? (float) $prefill['score']  : null;
$grade   = !empty($prefill['grade'])   ? $prefill['grade']   : null;
$email   = !empty($prefill['email'])   ? $prefill['email']   : null;
$prefillCountry = !empty($prefill['country']) ? preg_replace('/[^A-Z]/', '', strtoupper($prefill['country'])) : null;

// ── Country + pricing ─────────────────────────────────────────────────────

$countryCode = ($prefillCountry && strlen($prefillCountry) === 2)
    ? $prefillCountry
    : detectCountry();
$pricing     = getPricing();
$priceData   = getPriceForCountry($countryCode, $pricing);

// ── Build order URL with prefill ──────────────────────────────────────────

$orderParams = ['ref' => 'outreach'];
if ($domain)  $orderParams['domain']  = $domain;
if ($email)   $orderParams['email']   = $email;
if ($prefillCountry) $orderParams['country'] = $prefillCountry;
$orderUrl = '/?' . http_build_query($orderParams) . '#order';

// ── Grade helpers ─────────────────────────────────────────────────────────

function gradeClass(string $g): string {
    $first = strtolower($g[0] ?? '');
    if ($first === 'a') return 'grade-a';
    if ($first === 'b') return 'grade-b';
    if ($first === 'c') return 'grade-c';
    if ($first === 'd') return 'grade-d';
    return 'grade-f';
}

function gradeContext(float $score): string {
    if ($score >= 80) return 'Your site scores well — but there\'s still room to convert more visitors into customers.';
    if ($score >= 65) return 'Your site has a solid foundation. A few targeted fixes could significantly increase your conversion rate.';
    if ($score >= 50) return 'Your site has some clear opportunities. Small changes could meaningfully increase enquiries.';
    return 'Your site is losing potential customers at multiple points. Our report shows you exactly where and how to fix it.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $domain ? htmlspecialchars($domain) . ' — Your Website Audit | Audit&Fix' : 'We Audited Your Site | Audit&Fix' ?></title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="canonical" href="https://www.auditandfix.com/">
    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="assets/img/favicon-32.png" sizes="32x32" type="image/png">
</head>
<body class="outreach-landing">
<a href="#main-content" class="skip-link">Skip to main content</a>

<!-- ── Hero ────────────────────────────────────────────────────────────────── -->
<header class="hero hero-short" id="main-content">
    <nav class="nav" aria-label="Site navigation">
        <a href="/" class="logo">
            <img src="assets/img/logo.svg" alt="Audit&amp;Fix" class="logo-img"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='inline'">
            <span class="logo-text" style="display:none">Audit<span class="logo-amp">&amp;</span>Fix</span>
        </a>
    </nav>

    <div class="hero-body">
        <div class="hero-content">
            <p class="pre-headline">Free website audit completed</p>
            <h1>We audited<?= $domain ? ' <strong>' . htmlspecialchars($domain) . '</strong>' : ' your site' ?>. Here's what we found.</h1>

            <?php if ($score !== null && $grade): ?>
            <!-- Score display -->
            <div class="outreach-score-reveal">
                <div class="outreach-score-gauge">
                    <svg class="score-gauge" viewBox="0 0 120 70" aria-hidden="true">
                        <path class="gauge-bg" d="M10,60 A50,50 0 0,1 110,60" fill="none" stroke="rgba(255,255,255,0.2)" stroke-width="10" stroke-linecap="round"/>
                        <?php
                        $arcLen = 157;
                        $offset = $arcLen - ($arcLen * $score / 100);
                        $colour = $score >= 83 ? '#48bb78' : ($score >= 70 ? '#ed8936' : '#fc8181');
                        ?>
                        <path d="M10,60 A50,50 0 0,1 110,60" fill="none"
                              stroke="<?= $colour ?>" stroke-width="10" stroke-linecap="round"
                              stroke-dasharray="<?= $arcLen ?>"
                              stroke-dashoffset="<?= round($offset, 1) ?>"/>
                    </svg>
                    <div class="score-grade-wrap">
                        <span class="score-grade <?= gradeClass($grade) ?>"><?= htmlspecialchars($grade) ?></span>
                    </div>
                    <div class="score-number-wrap">
                        <span class="score-number"><?= round($score) ?></span>
                        <span class="score-denom">/100</span>
                    </div>
                </div>
                <p class="outreach-score-context"><?= htmlspecialchars(gradeContext($score)) ?></p>
            </div>
            <?php else: ?>
            <p class="subtitle">Our AI has scored your site across 10 conversion factors. The full breakdown — and exactly how to fix every issue — is in your report.</p>
            <?php endif; ?>

            <a href="<?= htmlspecialchars($orderUrl) ?>" class="cta-button">
                Get Your Full Report — <?= htmlspecialchars($priceData['formatted']) ?>
            </a>
            <p class="cta-sub">24-hour delivery &nbsp;·&nbsp; 30-day money-back guarantee</p>
        </div>
    </div>
</header>

<!-- ── What's in the report ─────────────────────────────────────────────── -->
<main>
    <section class="outreach-factors">
        <div class="container">
            <h2>Your 10-factor conversion breakdown</h2>
            <p class="section-subhead">The full report scores each factor A+ to F, explains why it matters, and gives you exact replacement copy and actions for every issue found.</p>

            <div class="checklist">
                <div class="check-item">✓ <strong>Headline quality</strong> — does it communicate value in 3–5 seconds?</div>
                <div class="check-item">✓ <strong>Value proposition</strong> — are you selling benefits, not features?</div>
                <div class="check-item">✓ <strong>Unique selling proposition</strong> — why choose you over alternatives?</div>
                <div class="check-item">✓ <strong>Call-to-action</strong> — prominence, placement, and copy clarity</div>
                <div class="check-item">✓ <strong>Trust &amp; credibility signals</strong> — testimonials, badges, certifications</div>
                <div class="check-item">✓ <strong>Urgency &amp; scarcity</strong> — legitimate pressure to act now</div>
                <div class="check-item">✓ <strong>Hook &amp; visual engagement</strong> — does your hero stop the scroll?</div>
                <div class="check-item">✓ <strong>Imagery &amp; design</strong> — authentic photos vs. generic stock</div>
                <div class="check-item">✓ <strong>Offer clarity</strong> — do visitors know exactly what they get?</div>
                <div class="check-item">✓ <strong>Industry appropriateness</strong> — does the design fit your market?</div>
            </div>
            <p class="included-footer">Plus: annotated screenshots of every problem area, competitor comparison, and a prioritised action plan with exact replacement copy.</p>
        </div>
    </section>

    <!-- Testimonials -->
    <section class="testimonials">
        <div class="container">
            <p class="testimonials-rating">★★★★★ Trusted by small business owners</p>
            <div class="testimonial-grid">
                <div class="testimonial">
                    <div class="stars" role="img" aria-label="5 out of 5 stars">★★★★★</div>
                    <p>"I'd been spending money on Google Ads sending traffic to a site quietly losing me enquiries. The free score told me enough — the full audit told me exactly what to do."</p>
                    <div class="testimonial-author"><strong>Sarah T.</strong> <span>Interior Designer, Melbourne</span></div>
                </div>
                <div class="testimonial">
                    <div class="stars" role="img" aria-label="5 out of 5 stars">★★★★★</div>
                    <p>"No fluff, no generic advice. Real screenshots of my site, real explanations, and a ranked list of what to fix first. Worth every cent."</p>
                    <div class="testimonial-author"><strong>Michelle R.</strong> <span>Physiotherapy Clinic, Brisbane</span></div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="footer-cta">
        <div class="container">
            <h2>Ready to see exactly what to fix?</h2>
            <a href="<?= htmlspecialchars($orderUrl) ?>" class="cta-button">
                Get Your Full Report — <?= htmlspecialchars($priceData['formatted']) ?>
            </a>
            <p>24-hour delivery &nbsp;·&nbsp; Money-back guarantee &nbsp;·&nbsp; Plain English, no jargon</p>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="assets/js/obfuscate-email.js?v=1" defer></script>
</body>
</html>
