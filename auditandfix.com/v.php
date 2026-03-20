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

// Get 2Step video pricing for this visitor's country
$videoPricing = get2StepPriceForCountry($countryCode);
$symbol   = $videoPricing['symbol'];
$price4   = $videoPricing['monthly_4'];
$price8   = $videoPricing['monthly_8'];
$price12  = $videoPricing['monthly_12'];

// Deal timer (same IP-hash system as homepage)
$dealExpiresAt = getDealExpiresAt();
$dealActive = $dealExpiresAt > (int)(microtime(true) * 1000);
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
        <?php if ($dealActive): ?>
        <div class="deal-banner">
            <p>First-visit offer: <strong>20% off</strong> your setup fee</p>
            <div class="timer" id="deal-timer">--:--</div>
        </div>
        <?php endif; ?>

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

        <div class="pricing-cards">
            <div class="pricing-card">
                <div class="label">Starter</div>
                <div class="price"><?= $symbol ?><?= $price4 ?><span class="period">/mo</span></div>
                <p>4 videos per month</p>
            </div>
            <div class="pricing-card" style="border: 2px solid #2563eb;">
                <div class="label">Growth</div>
                <div class="price"><?= $symbol ?><?= $price8 ?><span class="period">/mo</span></div>
                <p>8 videos per month</p>
                <small style="color: #2563eb;">Most popular</small>
            </div>
            <div class="pricing-card">
                <div class="label">Scale</div>
                <div class="price"><?= $symbol ?><?= $price12 ?><span class="period">/mo</span></div>
                <p>12 videos per month</p>
            </div>
        </div>

        <a href="#order" class="cta-button">Get Started</a>
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

        <?php if ($dealActive): ?>
        // Deal countdown timer
        window.DEAL_EXPIRES_AT = <?= $dealExpiresAt ?>;
        (function() {
            var timer = document.getElementById('deal-timer');
            if (!timer) return;
            function update() {
                var remaining = window.DEAL_EXPIRES_AT - Date.now();
                if (remaining <= 0) {
                    timer.textContent = 'Expired';
                    document.querySelector('.deal-banner').style.display = 'none';
                    return;
                }
                var mins = Math.floor(remaining / 60000);
                var secs = Math.floor((remaining % 60000) / 1000);
                timer.textContent = mins + ':' + (secs < 10 ? '0' : '') + secs;
                setTimeout(update, 1000);
            }
            update();
        })();
        <?php endif; ?>
    </script>
</body>
</html>
