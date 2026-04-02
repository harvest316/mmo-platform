<?php
/**
 * Demo video landing page — /demo/{vertical}
 *
 * Showcases a sample video review for each vertical/niche.
 * No database or JSON dependency — all content is hardcoded per vertical.
 *
 * Routes:
 *   /demo/pest-control  — Pest control demo (Sydney)
 *   /demo/plumber        — Plumbing demo (placeholder)
 *   /demo/electrician    — Electrician demo (placeholder)
 *   /demo/               — Index listing all demos
 *
 * Requires .htaccess: RewriteRule ^demo/([a-z-]+)/?$ demo/index.php?vertical=$1 [L,QSA]
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/geo.php';
require_once __DIR__ . '/../includes/pricing.php';

$vertical = preg_replace('/[^a-z-]/', '', $_GET['vertical'] ?? '');

// ── Demo data per vertical ──────────────────────────────────────────────────

$demos = [
    'pest-control' => [
        'business_name' => 'ACME Pest Control',
        'city'          => 'Sydney',
        'niche'         => 'Pest Control',
        'review_author' => 'Sarah Mitchell',
        'review_text'   => 'Absolutely fantastic service — professional, thorough, and genuinely friendly. They explained everything clearly and the problem was sorted same day. Highly recommend to anyone in Sydney.',
        'star_rating'   => '4.9',
        'review_count'  => 492,
        'country_code'  => 'AU',
        'video_url'     => 'https://cdn.auditandfix.com/video-s900001-1773998424007.mp4',
        'poster_url'    => 'https://cdn.auditandfix.com/poster-s900001-1773998436379.jpg',
    ],
    'cleaning' => [
        'business_name' => 'ACME Cleaning',
        'city'          => 'Sydney',
        'niche'         => 'Cleaning',
        'review_author' => 'James Thornton',
        'review_text'   => 'Best cleaning service we have ever used in Sydney. The team arrived right on time and were incredibly thorough from top to bottom. They left our entire office absolutely spotless, including the kitchen and bathrooms. We have already booked them for weekly cleans going forward.',
        'star_rating'   => '4.8',
        'review_count'  => 318,
        'country_code'  => 'AU',
        'video_url'     => 'https://cdn.auditandfix.com/video-s900002-1773998472988.mp4',
        'poster_url'    => 'https://cdn.auditandfix.com/poster-s900002-1773998485234.jpg',
    ],
    'plumber' => [
        'business_name' => 'ACME Plumbing',
        'city'          => 'Sydney',
        'niche'         => 'Plumbing',
        'review_author' => 'Kate Williams',
        'review_text'   => 'Called them for an emergency leak on a Sunday morning and they were here within the hour. The plumber was professional, friendly, and explained everything before starting work. Fixed the issue quickly with fair and transparent pricing. They even cleaned up after themselves which was a nice touch.',
        'star_rating'   => '4.7',
        'review_count'  => 256,
        'country_code'  => 'AU',
        'video_url'     => 'https://cdn.auditandfix.com/video-s900003-1773998522268.mp4',
        'poster_url'    => 'https://cdn.auditandfix.com/poster-s900003-1773998536448.jpg',
    ],
];

// ── Index page (no vertical specified) ──────────────────────────────────────

if (!$vertical || !isset($demos[$vertical])) {
    $isIndex = empty($vertical);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isIndex ? 'Video Review Demos' : '404 — Demo Not Found' ?> | Audit&amp;Fix</title>
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .demo-index { max-width: 640px; margin: 4rem auto; padding: 0 1rem; text-align: center; }
        .demo-index h1 { font-size: 2rem; margin-bottom: 1rem; }
        .demo-index p { color: #666; margin-bottom: 2rem; line-height: 1.6; }
        .demo-list { list-style: none; padding: 0; }
        .demo-list li { margin-bottom: 1rem; }
        .demo-list a { display: inline-block; background: #2563eb; color: white; padding: 12px 28px; border-radius: 8px; text-decoration: none; font-weight: 600; }
        .demo-list a:hover { background: #1d4ed8; }
        .not-found { color: #dc2626; }
    </style>
</head>
<body>
    <div class="demo-index">
        <?php if (!$isIndex): ?>
            <h1 class="not-found">Demo Not Found</h1>
            <p>We don't have a demo for "<?= htmlspecialchars($vertical, ENT_QUOTES) ?>" yet.</p>
        <?php else: ?>
            <h1>Video Review Demos</h1>
            <p>See how we turn your best Google reviews into professional short videos.</p>
        <?php endif; ?>

        <ul class="demo-list">
            <?php foreach ($demos as $slug => $d): ?>
                <li><a href="/demo/<?= $slug ?>"><?= htmlspecialchars($d['niche']) ?> — <?= htmlspecialchars($d['city']) ?></a></li>
            <?php endforeach; ?>
        </ul>

        <p style="margin-top: 3rem;"><a href="/" style="color: #2563eb;">&larr; Back to Audit&amp;Fix</a></p>
    </div>
</body>
</html>
<?php
    exit;
}

// ── Demo page for a specific vertical ───────────────────────────────────────

$d = $demos[$vertical];
$businessName = htmlspecialchars($d['business_name'], ENT_QUOTES);
$city         = htmlspecialchars($d['city'], ENT_QUOTES);
$niche        = htmlspecialchars($d['niche'], ENT_QUOTES);
$reviewAuthor = htmlspecialchars($d['review_author'], ENT_QUOTES);
$reviewText   = htmlspecialchars($d['review_text'], ENT_QUOTES);
$starRating   = htmlspecialchars($d['star_rating'], ENT_QUOTES);
$reviewCount  = (int)$d['review_count'];
$videoUrl     = htmlspecialchars($d['video_url'], ENT_QUOTES);
$posterUrl    = htmlspecialchars($d['poster_url'], ENT_QUOTES);
$countryCode  = $d['country_code'] ?? detectCountry();

$videoPricing = get2StepPriceForCountry($countryCode);
$symbol   = $videoPricing['symbol'];
$currency = $videoPricing['currency'];
$price4   = $videoPricing['monthly_4'];
$price8   = $videoPricing['monthly_8'];
$price12  = $videoPricing['monthly_12'];

// PayPal plan IDs — keyed by country_code
$isSandbox = PAYPAL_MODE === 'sandbox';
$paypalClientId = $isSandbox
    ? getenv('PAYPAL_SANDBOX_CLIENT_ID')
    : getenv('PAYPAL_CLIENT_ID');

// Plan IDs loaded from env: PAYPAL_PLANS_SANDBOX / PAYPAL_PLANS_LIVE (JSON)
// Format: {"AU":{"starter":"P-xxx","growth":"P-xxx","scale":"P-xxx"},"US":{...},...}
$plansJson = $isSandbox
    ? getenv('PAYPAL_PLANS_SANDBOX')
    : getenv('PAYPAL_PLANS_LIVE');
$planIds = $plansJson ? json_decode($plansJson, true) : [];

$cc = strtoupper($countryCode);
if ($cc === 'GB') $cc = 'UK';
$countryPlans = $planIds[$cc] ?? $planIds['US'];
$planIdsJson = json_encode($countryPlans);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>We made this for <?= $businessName ?> | Audit&amp;Fix Video Reviews</title>
    <meta name="description" content="A free short video created from your best Google review. See what your customers are saying about <?= $businessName ?>.">
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .video-hero { text-align: center; padding: 2rem 1rem; }
        .video-hero h1 { font-size: 1.8rem; margin-bottom: 0.5rem; }
        .video-hero .subtitle { color: #666; font-size: 1.1rem; margin-bottom: 2rem; }
        .video-container { max-width: 400px; margin: 0 auto 2rem; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.15); }
        .video-container video { width: 100%; display: block; }
        .video-placeholder { background: #1a1a2e; color: #888; text-align: center; padding: 4rem 2rem; border-radius: 12px; max-width: 400px; margin: 0 auto 2rem; }
        .video-placeholder p { margin: 0.5rem 0; }
        .review-card { max-width: 640px; margin: 0 auto 2rem; background: #f8f9fa; border-radius: 12px; padding: 2rem; }
        .review-card .stars { color: #f59e0b; font-size: 1.4rem; }
        .review-card blockquote { font-style: italic; color: #444; margin: 1rem 0; line-height: 1.7; }
        .review-card .author { color: #666; font-size: 0.9rem; }
        .pitch-section { max-width: 640px; margin: 0 auto; padding: 2rem 1rem; }
        .pitch-section h2 { font-size: 1.4rem; margin-bottom: 1rem; }
        .pitch-section p { line-height: 1.7; color: #444; margin-bottom: 1rem; }
        .pricing-section { background: #f8f9fa; padding: 3rem 1rem; text-align: center; }
        .pricing-cards { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; max-width: 800px; margin: 0 auto; }
        .pricing-card { background: white; border-radius: 12px; padding: 1.5rem; flex: 1; min-width: 200px; max-width: 250px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .pricing-card { cursor: pointer; transition: border-color 0.2s, box-shadow 0.2s; border: 2px solid transparent; }
        .pricing-card:hover { border-color: #93c5fd; }
        .pricing-card.selected { border-color: #2563eb; box-shadow: 0 4px 16px rgba(37,99,235,0.2); }
        .pricing-card .price { font-size: 2rem; font-weight: bold; color: #2563eb; }
        .pricing-card .period { color: #666; font-size: 0.9rem; }
        .pricing-card .label { font-weight: 600; margin-bottom: 0.5rem; }
        .pricing-card .check { display: none; color: #2563eb; font-size: 1.2rem; margin-top: 0.5rem; }
        .pricing-card.selected .check { display: block; }
        #paypal-subscribe-container { max-width: 400px; margin: 1.5rem auto 0; min-height: 50px; }
        .subscribe-status { text-align: center; margin-top: 1rem; font-size: 0.9rem; }
        .subscribe-status.error { color: #dc2626; }
        .subscribe-status.success { color: #16a34a; }
        .demo-badge { display: inline-block; background: #fef3c7; color: #92400e; padding: 4px 12px; border-radius: 4px; font-size: 0.85rem; font-weight: 600; margin-bottom: 1rem; }
        .footer-note { text-align: center; padding: 2rem; color: #999; font-size: 0.85rem; }
    </style>
</head>
<body>
    <div class="video-hero">
        <span class="demo-badge">DEMO</span>
        <h1>We made this for <?= $businessName ?></h1>
        <p class="subtitle">A free short video from your best Google review</p>

        <?php if ($videoUrl): ?>
        <div class="video-container">
            <video id="demo-video" controls playsinline preload="auto" <?= $posterUrl ? "poster=\"$posterUrl\"" : '' ?>>
                <source src="<?= $videoUrl ?>" type="video/mp4">
                Your browser does not support video playback.
            </video>
        </div>
        <?php else: ?>
        <div class="video-placeholder">
            <p style="font-size: 2rem;">&#9654;</p>
            <p>Demo video coming soon</p>
            <p style="font-size: 0.85rem;">We're rendering a sample video for <?= $niche ?> in <?= $city ?></p>
        </div>
        <?php endif; ?>
    </div>

    <div class="review-card" style="max-width: 640px; margin: 0 auto 2rem;">
        <div class="stars"><?= str_repeat('&#9733; ', 5) ?></div>
        <blockquote>&ldquo;<?= $reviewText ?>&rdquo;</blockquote>
        <div class="author">&mdash; <?= $reviewAuthor ?> &middot; <?= $starRating ?> stars across <?= $reviewCount ?> reviews</div>
    </div>

    <div class="pitch-section">
        <h2>Your customers already love you</h2>
        <p>
            <?= $businessName ?> has <?= $reviewCount ?> Google reviews &mdash; and some of them are incredible.
            We took one of your best and turned it into a short video you can share on social media,
            your website, or your Google Business Profile.
        </p>
        <p>
            That video above? It's yours. Free. No strings attached.
        </p>
        <p>
            Now imagine having a fresh one every week &mdash; each highlighting a different happy customer,
            a different service you offer. Your reviews are already there. We just turn them into content
            that gets noticed.
        </p>
    </div>

    <div class="pricing-section" id="order">
        <h2>Ready to turn your reviews into a content machine?</h2>
        <p style="color: #666; margin-bottom: 2rem;">Choose your plan. Cancel anytime.</p>

        <div class="pricing-cards">
            <div class="pricing-card" data-plan="starter" onclick="selectPlan('starter')">
                <div class="label">Starter</div>
                <div class="price"><?= $symbol ?><?= $price4 ?><span class="period">/mo</span></div>
                <p>4 videos per month</p>
                <div class="check">&#10003; Selected</div>
            </div>
            <div class="pricing-card selected" data-plan="growth" onclick="selectPlan('growth')">
                <div class="label">Growth</div>
                <div class="price"><?= $symbol ?><?= $price8 ?><span class="period">/mo</span></div>
                <p>8 videos per month</p>
                <small style="color: #2563eb;">Most popular</small>
                <div class="check">&#10003; Selected</div>
            </div>
            <div class="pricing-card" data-plan="scale" onclick="selectPlan('scale')">
                <div class="label">Scale</div>
                <div class="price"><?= $symbol ?><?= $price12 ?><span class="period">/mo</span></div>
                <p>12 videos per month</p>
                <div class="check">&#10003; Selected</div>
            </div>
        </div>

        <div id="paypal-subscribe-container"></div>
        <div class="subscribe-status" id="subscribe-status"></div>
    </div>

    <div class="footer-note">
        <p>&copy; <?= date('Y') ?> Audit&amp;Fix. All rights reserved.</p>
        <p><a href="/privacy.php">Privacy</a> &middot; <a href="/terms.php">Terms</a></p>
    </div>

    <script>
    // Prime Chromium audio context on first interaction to eliminate cold-start silence.
    // The AudioContext needs a user gesture to start; once running, video audio plays immediately.
    (function() {
        var primed = false;
        function primeAudio() {
            if (primed) return;
            primed = true;
            try {
                var ctx = new (window.AudioContext || window.webkitAudioContext)();
                var buf = ctx.createBuffer(1, 1, 22050);
                var src = ctx.createBufferSource();
                src.buffer = buf;
                src.connect(ctx.destination);
                src.start(0);
                // Also force video element to load
                var v = document.getElementById('demo-video');
                if (v && v.readyState < 3) v.load();
            } catch(e) {}
            document.removeEventListener('scroll', primeAudio);
            document.removeEventListener('click', primeAudio);
            document.removeEventListener('touchstart', primeAudio);
        }
        document.addEventListener('scroll', primeAudio, { once: true, passive: true });
        document.addEventListener('click', primeAudio, { once: true });
        document.addEventListener('touchstart', primeAudio, { once: true, passive: true });
    })();
    </script>
    <script src="https://www.paypal.com/sdk/js?client-id=<?= urlencode($paypalClientId) ?>&vault=true&intent=subscription&currency=<?= urlencode($currency) ?>"></script>
    <script>
    var PLAN_IDS = <?= $planIdsJson ?>;
    var selectedPlan = 'growth'; // default

    function selectPlan(plan) {
        selectedPlan = plan;
        document.querySelectorAll('.pricing-card').forEach(function(card) {
            card.classList.toggle('selected', card.dataset.plan === plan);
        });
        // Re-render PayPal button with new plan
        var container = document.getElementById('paypal-subscribe-container');
        container.innerHTML = '';
        renderPayPalButton();
    }

    function renderPayPalButton() {
        if (typeof paypal === 'undefined') return;
        paypal.Buttons({
            style: { layout: 'vertical', color: 'blue', shape: 'rect', label: 'subscribe' },
            createSubscription: function(data, actions) {
                return actions.subscription.create({
                    plan_id: PLAN_IDS[selectedPlan]
                });
            },
            onApprove: function(data) {
                var status = document.getElementById('subscribe-status');
                status.className = 'subscribe-status success';
                status.textContent = 'Subscription active! ID: ' + data.subscriptionID + ' — we\'ll be in touch within 24 hours.';
                // TODO: POST to api.php to record the subscription
            },
            onError: function(err) {
                var status = document.getElementById('subscribe-status');
                status.className = 'subscribe-status error';
                status.textContent = 'Something went wrong. Please try again or contact us.';
            }
        }).render('#paypal-subscribe-container');
    }

    renderPayPalButton();
    </script>
</body>
</html>
