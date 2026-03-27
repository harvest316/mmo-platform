<?php
/**
 * Video sales page — /v/{hash}
 *
 * Displays a personalised video page for each prospect showing:
 *   1. Their free short video (from Google reviews)
 *   2. Sales pitch personalised to their business
 *   3. Pricing + subscription form
 *
 * Video data stored at data/videos/{hash}.json by api.php?action=store-video
 * View tracked via beacon to api.php?action=video-viewed
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/geo.php';
require_once __DIR__ . '/includes/pricing.php';

// Extract hash from PATH_INFO or REQUEST_URI
$hash = null;
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if (preg_match('#^/([a-zA-Z0-9]+)$#', $pathInfo, $m)) {
    $hash = $m[1];
}
if (!$hash) {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (preg_match('#/v/([a-zA-Z0-9]+)#', $uri, $m)) {
        $hash = $m[1];
    }
}

if (!$hash) {
    header('Location: /video-reviews/');
    exit;
}

// Load video data
$dataFile = __DIR__ . '/data/videos/' . preg_replace('/[^a-zA-Z0-9]/', '', $hash) . '.json';
if (!is_file($dataFile)) {
    header('Location: /video-reviews/');
    exit;
}

$video = json_decode(file_get_contents($dataFile), true);
if (!$video || empty($video['video_url'])) {
    header('Location: /video-reviews/');
    exit;
}

$businessName = htmlspecialchars($video['business_name'] ?? 'your business', ENT_QUOTES);
$videoUrl     = htmlspecialchars($video['video_url'] ?? '', ENT_QUOTES);
$niche        = htmlspecialchars($video['niche'] ?? '', ENT_QUOTES);
$reviewCount  = (int)($video['review_count'] ?? 0);
$countryCode  = $video['country_code'] ?? detectCountry();
$nicheTier    = $video['niche_tier'] ?? 'standard';

// Store in session so the navbar can show "Your Free Video" link
$_SESSION['my_video'] = ['hash' => $hash, 'biz' => $video['business_name'] ?? 'your business'];

// Get 2Step video pricing for this visitor's country
$videoPricing = get2StepPriceForCountry($countryCode);
$symbol   = $videoPricing['symbol'];
$price4   = $videoPricing['monthly_4'];
$price8   = $videoPricing['monthly_8'];
$price12  = $videoPricing['monthly_12'];

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
        .pitch-section { max-width: 640px; margin: 0 auto; padding: 2rem 1rem; }
        .pitch-section h2 { font-size: 1.4rem; margin-bottom: 1rem; }
        .pitch-section p { line-height: 1.7; color: #444; margin-bottom: 1rem; }
        .pricing-section { background: #f8f9fa; padding: 3rem 1rem; text-align: center; }
        .pricing-cards { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; max-width: 800px; margin: 0 auto; }
        .pricing-card { background: white; border-radius: 12px; padding: 1.5rem; flex: 1; min-width: 200px; max-width: 250px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .pricing-card .price { font-size: 2rem; font-weight: bold; color: #2563eb; }
        .pricing-card .period { color: #666; font-size: 0.9rem; }
        .pricing-card .label { font-weight: 600; margin-bottom: 0.5rem; }
        .cta-button { display: inline-block; background: #2563eb; color: white; padding: 14px 32px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 1.1rem; margin-top: 1.5rem; }
        .cta-button:hover { background: #1d4ed8; }
        .deal-banner { background: #fef3c7; border: 1px solid #f59e0b; padding: 1rem; border-radius: 8px; margin-bottom: 2rem; text-align: center; }
        .deal-banner .timer { font-size: 1.5rem; font-weight: bold; color: #d97706; }
        .footer-note { text-align: center; padding: 2rem; color: #999; font-size: 0.85rem; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/consent-banner.php'; ?>
    <div class="video-hero">
        <h1>We made this for <?= $businessName ?></h1>
        <p class="subtitle">A free short video from your best Google review</p>

        <div class="video-container">
            <video id="review-video" controls playsinline preload="auto" poster="<?= $posterUrl ?? '' ?>">
                <source src="<?= $videoUrl ?>" type="video/mp4">
                Your browser does not support video playback.
            </video>
        </div>
    </div>

    <div class="pitch-section">
        <h2>Your customers already love you</h2>
        <p>
            <?= $businessName ?> has <?= $reviewCount ?> Google reviews — and some of them are incredible.
            We took one of your best and turned it into a short video you can use on social media,
            your website, or anywhere you want to show off what your customers think of you.
        </p>
        <p>
            That video above? It's yours. Free. No strings attached.
        </p>
        <p>
            Now imagine having a fresh one every week — each highlighting a different service you offer,
            a different happy customer. Your reviews are already there. Every week without videos
            is missed content.
        </p>
    </div>

    <div class="pricing-section">
        <h2>Ready to turn your reviews into a content machine?</h2>
        <p style="color: #666; margin-bottom: 2rem;">Choose your plan. Cancel anytime.</p>

        <?php if (!empty($_GET['subscription_activated'])): ?>
        <div id="subscription-success" style="background: #f0fdf4; border: 2px solid #16a34a; border-radius: 12px; padding: 2rem; margin-bottom: 2rem; max-width: 500px; margin-left: auto; margin-right: auto;">
            <h3 style="color: #16a34a; margin-bottom: 0.5rem;">You're subscribed!</h3>
            <p style="color: #444;">Your first batch of videos will arrive within 48 hours. We'll email you at the address you provided.</p>
        </div>
        <?php endif; ?>

        <?php if (!empty($_GET['subscription_cancelled'])): ?>
        <div style="background: #fef3c7; border: 1px solid #f59e0b; border-radius: 12px; padding: 1.5rem; margin-bottom: 2rem; max-width: 500px; margin-left: auto; margin-right: auto;">
            <p style="color: #92400e;">No worries — you weren't charged. Your free video is still yours.</p>
        </div>
        <?php endif; ?>

        <div id="subscribe-email-gate" style="max-width: 400px; margin: 0 auto 2rem; <?= !empty($_GET['subscription_activated']) ? 'display:none' : '' ?>">
            <label for="subscribe-email" style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 0.9rem; color: #444;">Your email (for video delivery)</label>
            <input type="email" id="subscribe-email" placeholder="you@yourbusiness.com" style="width: 100%; padding: 12px 14px; border: 1px solid #ddd; border-radius: 8px; font-size: 1rem; margin-bottom: 4px;">
            <div id="subscribe-error" style="color: #dc2626; font-size: 0.85rem; display: none;"></div>
        </div>

        <div class="pricing-cards">
            <div class="pricing-card">
                <div class="label">Starter</div>
                <div class="price"><?= $symbol ?><?= $price4 ?><span class="period">/mo</span></div>
                <p>4 videos per month</p>
                <p style="color: #16a34a; font-size: 0.85rem;">Setup: <?= $symbol ?>0 (waived)</p>
                <button class="cta-button subscribe-btn" data-tier="monthly_4" style="margin-top: 1rem; width: 100%; padding: 12px; font-size: 0.95rem;">Subscribe</button>
            </div>
            <div class="pricing-card" style="border: 2px solid #2563eb;">
                <div class="label">Growth</div>
                <div class="price"><?= $symbol ?><?= $price8 ?><span class="period">/mo</span></div>
                <p>8 videos per month</p>
                <p style="color: #16a34a; font-size: 0.85rem;">Setup: <?= $symbol ?>0 (waived)</p>
                <small style="color: #2563eb;">Most popular</small>
                <button class="cta-button subscribe-btn" data-tier="monthly_8" style="margin-top: 1rem; width: 100%; padding: 12px; font-size: 0.95rem;">Subscribe</button>
            </div>
            <div class="pricing-card">
                <div class="label">Scale</div>
                <div class="price"><?= $symbol ?><?= $price12 ?><span class="period">/mo</span></div>
                <p>12 videos per month</p>
                <p style="color: #16a34a; font-size: 0.85rem;">Setup: <?= $symbol ?>0 (waived)</p>
                <button class="cta-button subscribe-btn" data-tier="monthly_12" style="margin-top: 1rem; width: 100%; padding: 12px; font-size: 0.95rem;">Subscribe</button>
            </div>
        </div>

        <?php $compRange = getCompetitorPriceRange($countryCode); ?>
        <p style="color: #888; font-size: 0.85rem; margin-top: 1.5rem;">
            Comparable services charge <?= $compRange['symbol'] ?><?= $compRange['low'] ?>–<?= $compRange['symbol'] ?><?= $compRange['high'] ?>/month.
            <a href="/video-reviews/compare" style="color: #2563eb;">See how we compare</a>.
        </p>
    </div>

    <div class="footer-note">
        <p>&copy; <?= date('Y') ?> Audit&amp;Fix. All rights reserved.</p>
        <p><a href="/privacy.php">Privacy</a> &middot; <a href="/terms.php">Terms</a></p>
    </div>

    <!-- Prime Chromium audio context on first interaction -->
    <script>
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
                var v = document.getElementById('review-video');
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

    <!-- View tracking beacon -->
    <script>
        (function() {
            fetch('/api.php?action=video-viewed', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ hash: '<?= $hash ?>' }),
                keepalive: true
            }).catch(function() {});
        })();

    </script>

    <!-- Subscription checkout -->
    <script>
    (function() {
        var buttons = document.querySelectorAll('.subscribe-btn');
        var emailInput = document.getElementById('subscribe-email');
        var errorEl = document.getElementById('subscribe-error');
        if (!buttons.length || !emailInput) return;

        // Handle PayPal return — activate the subscription
        var params = new URLSearchParams(window.location.search);
        var subId = params.get('subscription_id');
        if (subId && params.get('subscription_activated') === '1') {
            fetch('/api.php?action=activate-subscription', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    subscription_id: subId,
                    video_hash: '<?= $hash ?>'
                })
            }).catch(function() {});
        }

        function showError(msg) {
            if (errorEl) { errorEl.textContent = msg; errorEl.style.display = ''; }
        }
        function hideError() {
            if (errorEl) { errorEl.style.display = 'none'; errorEl.textContent = ''; }
        }

        buttons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                hideError();
                var email = (emailInput.value || '').trim();
                if (!email || !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                    showError('Please enter a valid email address.');
                    emailInput.focus();
                    return;
                }

                var tier = btn.getAttribute('data-tier');
                btn.disabled = true;
                btn.textContent = 'Redirecting to PayPal…';

                fetch('/api.php?action=create-subscription', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        tier: tier,
                        email: email,
                        country: '<?= htmlspecialchars($countryCode) ?>',
                        business_name: '<?= $businessName ?>',
                        video_hash: '<?= $hash ?>'
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.approve_url) {
                        window.location.href = data.approve_url;
                    } else {
                        showError(data.error || 'Something went wrong. Please try again.');
                        btn.disabled = false;
                        btn.textContent = 'Subscribe';
                    }
                })
                .catch(function() {
                    showError('Network error. Please try again.');
                    btn.disabled = false;
                    btn.textContent = 'Subscribe';
                });
            });
        });
    })();
    </script>
</body>
</html>
