<?php
/**
 * Video sales page — /v/{hash}
 *
 * Personalised single-page sales flow for 2Step video prospects.
 * Redesigned 2026-03-28 based on UX, copy, and growth agent specs.
 *
 * Structure (9 sections):
 *   1. Micro-trust bar (no nav — dedicated sales page)
 *   2. Personalised video hero (name, stars, video player)
 *   3. Soft bridge ("Your customers already said it")
 *   4. Social proof stats
 *   5. How it works (3 steps)
 *   6. Single recommended pricing tier
 *   7. FAQ / objection handling
 *   8. Sticky mobile CTA (JS-triggered after video scroll)
 *   9. Minimal footer
 *
 * Video data: data/videos/{hash}.json (pushed by api.php?action=store-video)
 * Tracking: video-viewed, video-play, video-complete beacons
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
$posterUrl    = htmlspecialchars($video['poster_url'] ?? '', ENT_QUOTES);
$niche        = htmlspecialchars($video['niche'] ?? '', ENT_QUOTES);
$nicheDisplay = htmlspecialchars($video['niche_display'] ?? $video['niche'] ?? '', ENT_QUOTES);
$city         = htmlspecialchars($video['city'] ?? '', ENT_QUOTES);
$reviewCount  = (int)($video['review_count'] ?? 0);
$starRating   = number_format((float)($video['google_rating'] ?? 5.0), 1);
$countryCode  = $video['country_code'] ?? detectCountry();

// Pricing
$videoPricing = get2StepPriceForCountry($countryCode);
$symbol   = $videoPricing['symbol'];
$price4   = $videoPricing['monthly_4'];
$price8   = $videoPricing['monthly_8'];
$price12  = $videoPricing['monthly_12'];

// Twilio number for SMS deep-link (AU default)
$smsNumber = '+61468089949';

// Session for navbar (if they navigate to main site)
$_SESSION['my_video'] = ['hash' => $hash, 'biz' => $video['business_name'] ?? 'your business'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $businessName ?> — your free video review | Audit&amp;Fix</title>
    <meta name="description" content="A free short video created from your best Google review. See what your customers are saying about <?= $businessName ?>.">
    <meta name="robots" content="noindex">

    <!-- Open Graph for social sharing -->
    <meta property="og:title" content="<?= $businessName ?> — free video from your Google reviews">
    <meta property="og:description" content="We turned your best Google review into a 30-second video. Watch it here.">
    <?php if ($posterUrl): ?><meta property="og:image" content="<?= $posterUrl ?>"><?php endif; ?>
    <meta property="og:video" content="<?= $videoUrl ?>">
    <meta property="og:type" content="video.other">

    <style>
        /* ── Reset + System fonts (no web fonts — sub-500KB page weight) ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            color: #1a1a1a; background: #fff; line-height: 1.6;
            -webkit-text-size-adjust: 100%;
        }
        a { color: #2563eb; text-decoration: none; }
        a:hover { text-decoration: underline; }
        img { max-width: 100%; height: auto; }

        /* ── Section 1: Micro-trust bar ── */
        .trust-bar {
            background: #111827; color: #d1d5db; padding: 10px 16px;
            font-size: 0.8rem; text-align: center;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .trust-bar img { height: 20px; width: auto; }
        .trust-bar span { opacity: 0.8; }

        /* ── Section 2: Video hero ── */
        .hero {
            text-align: center; padding: 24px 16px 16px;
            max-width: 480px; margin: 0 auto;
        }
        .hero h1 {
            font-size: 1.35rem; font-weight: 700; line-height: 1.3;
            margin-bottom: 6px; color: #111;
        }
        .hero .stars-line {
            font-size: 0.95rem; color: #666; margin-bottom: 16px;
        }
        .hero .stars-line .stars { color: #f59e0b; letter-spacing: 1px; }
        .video-wrap {
            position: relative; border-radius: 12px; overflow: hidden;
            box-shadow: 0 8px 32px rgba(0,0,0,0.12); background: #000;
            max-width: 400px; margin: 0 auto;
        }
        .video-wrap video { width: 100%; display: block; }
        .play-btn {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            width: 72px; height: 72px; border-radius: 50%;
            background: rgba(255,255,255,0.9); border: none; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 16px rgba(0,0,0,0.2); transition: transform 0.15s;
            z-index: 2;
        }
        .play-btn:hover { transform: translate(-50%, -50%) scale(1.08); }
        .play-btn svg { width: 28px; height: 28px; margin-left: 4px; fill: #111; }
        .play-btn.hidden { display: none; }
        .hero .tagline {
            font-size: 0.85rem; color: #888; margin-top: 12px;
        }

        /* ── Video end overlay ── */
        .video-end-overlay {
            position: absolute; inset: 0; background: rgba(0,0,0,0.75);
            display: none; flex-direction: column; align-items: center; justify-content: center;
            color: #fff; padding: 24px; text-align: center; z-index: 3;
        }
        .video-end-overlay.show { display: flex; }
        .video-end-overlay p { font-size: 1.1rem; margin-bottom: 16px; }
        .video-end-overlay .btn-reply {
            background: #f59e0b; color: #111; padding: 14px 28px; border-radius: 8px;
            font-weight: 600; font-size: 1rem; text-decoration: none; display: inline-block;
        }

        /* ── Section 3: Soft bridge ── */
        .bridge {
            max-width: 560px; margin: 0 auto; padding: 32px 20px 24px;
        }
        .bridge h2 { font-size: 1.2rem; font-weight: 700; margin-bottom: 12px; }
        .bridge p { color: #444; margin-bottom: 12px; font-size: 0.95rem; line-height: 1.7; }

        /* ── Section 4: Stats ── */
        .stats {
            background: #f8fafc; padding: 28px 20px; text-align: center;
        }
        .stats ul {
            list-style: none; max-width: 480px; margin: 0 auto;
            display: flex; flex-direction: column; gap: 12px;
        }
        .stats li {
            font-size: 0.9rem; color: #333; padding: 10px 16px;
            background: #fff; border-radius: 8px; border: 1px solid #e5e7eb;
        }
        .stats li strong { color: #111; }

        /* ── Section 5: How it works ── */
        .how-it-works {
            max-width: 560px; margin: 0 auto; padding: 32px 20px;
        }
        .how-it-works h2 { font-size: 1.2rem; font-weight: 700; margin-bottom: 20px; text-align: center; }
        .steps { display: flex; flex-direction: column; gap: 20px; }
        .step {
            display: flex; gap: 14px; align-items: flex-start;
        }
        .step-num {
            flex-shrink: 0; width: 36px; height: 36px; border-radius: 50%;
            background: #111827; color: #fff; display: flex; align-items: center;
            justify-content: center; font-weight: 700; font-size: 0.9rem;
        }
        .step-text h3 { font-size: 0.95rem; font-weight: 600; margin-bottom: 2px; }
        .step-text p { font-size: 0.85rem; color: #666; }

        /* ── Section 6: Pricing ── */
        .pricing {
            text-align: center; padding: 32px 20px;
            background: #f8fafc;
        }
        .pricing h2 { font-size: 1.2rem; font-weight: 700; margin-bottom: 8px; }
        .pricing .intro { color: #666; font-size: 0.9rem; margin-bottom: 24px; max-width: 400px; margin-left: auto; margin-right: auto; }

        .price-card {
            max-width: 360px; margin: 0 auto; background: #fff;
            border: 2px solid #2563eb; border-radius: 16px; padding: 28px 24px;
            box-shadow: 0 4px 16px rgba(37,99,235,0.08);
        }
        .price-card .badge {
            display: inline-block; background: #2563eb; color: #fff;
            font-size: 0.75rem; font-weight: 600; padding: 4px 12px;
            border-radius: 12px; margin-bottom: 12px; text-transform: uppercase;
        }
        .price-card .amount { font-size: 2.5rem; font-weight: 800; color: #111; }
        .price-card .period { font-size: 1rem; color: #666; font-weight: 400; }
        .price-card .desc { color: #444; font-size: 0.9rem; margin: 8px 0 4px; }
        .price-card .cancel { color: #888; font-size: 0.8rem; margin-bottom: 16px; }

        .email-gate { margin-bottom: 16px; }
        .email-gate label { display: block; font-size: 0.8rem; color: #666; margin-bottom: 4px; text-align: left; }
        .email-gate input {
            width: 100%; padding: 12px 14px; border: 1px solid #d1d5db;
            border-radius: 8px; font-size: 1rem;
        }
        .email-gate .error { color: #dc2626; font-size: 0.8rem; display: none; margin-top: 4px; }

        .btn-primary {
            display: block; width: 100%; padding: 16px;
            background: #2563eb; color: #fff; border: none; border-radius: 8px;
            font-size: 1.05rem; font-weight: 600; cursor: pointer;
            transition: background 0.15s;
        }
        .btn-primary:hover { background: #1d4ed8; }

        .alt-tiers { margin-top: 16px; font-size: 0.85rem; color: #888; }
        .alt-tiers a { color: #2563eb; }

        /* Success / cancel banners */
        .banner-success { background: #f0fdf4; border: 2px solid #16a34a; border-radius: 12px; padding: 20px; margin-bottom: 20px; max-width: 360px; margin-left: auto; margin-right: auto; }
        .banner-success h3 { color: #16a34a; margin-bottom: 4px; }
        .banner-cancel { background: #fef3c7; border: 1px solid #f59e0b; border-radius: 12px; padding: 16px; margin-bottom: 20px; max-width: 360px; margin-left: auto; margin-right: auto; }

        /* ── Section 7: FAQ ── */
        .faq {
            max-width: 560px; margin: 0 auto; padding: 32px 20px;
        }
        .faq h2 { font-size: 1.2rem; font-weight: 700; margin-bottom: 16px; text-align: center; }
        .faq details {
            border-bottom: 1px solid #e5e7eb; padding: 14px 0;
        }
        .faq summary {
            font-weight: 600; font-size: 0.95rem; cursor: pointer;
            list-style: none; display: flex; justify-content: space-between; align-items: center;
        }
        .faq summary::after { content: '+'; font-size: 1.2rem; color: #999; }
        .faq details[open] summary::after { content: '−'; }
        .faq .answer { padding-top: 10px; color: #555; font-size: 0.9rem; line-height: 1.7; }

        /* ── Section 8: Sticky mobile CTA ── */
        .sticky-cta {
            position: fixed; bottom: 0; left: 0; right: 0;
            background: #fff; border-top: 1px solid #e5e7eb;
            box-shadow: 0 -2px 8px rgba(0,0,0,0.06);
            padding: 10px 16px; display: none; align-items: center;
            justify-content: space-between; z-index: 100;
        }
        .sticky-cta.show { display: flex; }
        .sticky-cta .price-hint { font-size: 0.85rem; color: #666; }
        .sticky-cta .price-hint strong { color: #111; font-size: 1.1rem; }
        .sticky-cta .btn-sticky {
            background: #2563eb; color: #fff; padding: 12px 24px;
            border-radius: 8px; font-weight: 600; font-size: 0.9rem;
            border: none; cursor: pointer; white-space: nowrap;
        }

        /* ── Section 9: Footer ── */
        .footer {
            text-align: center; padding: 20px 16px; color: #999; font-size: 0.8rem;
        }
        .footer a { color: #999; }

        /* ── Responsive: pad bottom for sticky CTA on mobile ── */
        @media (max-width: 768px) {
            body.has-sticky { padding-bottom: 72px; }
        }
        @media (min-width: 769px) {
            .sticky-cta { display: none !important; }
            .hero { padding-top: 40px; }
            .steps { flex-direction: row; }
            .step { flex: 1; flex-direction: column; text-align: center; align-items: center; }
        }
    </style>
</head>
<body>

<!-- Section 1: Micro-trust bar -->
<div class="trust-bar">
    <img src="/assets/img/logo-light.png" alt="Audit&amp;Fix" height="20">
    <span>We make video ads from real Google reviews</span>
</div>

<!-- Section 2: Video hero -->
<div class="hero">
    <h1><?= $businessName ?>, we made you a free video</h1>
    <div class="stars-line">
        <span class="stars">★★★★★</span>
        <?= $starRating ?> stars from <?= number_format($reviewCount) ?> reviews
    </div>

    <div class="video-wrap" id="video-wrap">
        <video id="review-video" playsinline preload="metadata"
               <?php if ($posterUrl): ?>poster="<?= $posterUrl ?>"<?php endif; ?>>
            <source src="<?= $videoUrl ?>" type="video/mp4">
        </video>
        <button class="play-btn" id="play-btn" aria-label="Play video">
            <svg viewBox="0 0 24 24"><polygon points="6,3 20,12 6,21"/></svg>
        </button>
        <div class="video-end-overlay" id="end-overlay">
            <p>Want this on your socials?</p>
            <a href="sms:<?= $smsNumber ?>?body=Interested" class="btn-reply" id="reply-link">Reply via text</a>
        </div>
    </div>

    <p class="tagline">30 seconds. Made from a real review. Yours to keep.</p>
</div>

<!-- Section 3: Soft bridge -->
<div class="bridge">
    <h2>Your customers already said it</h2>
    <p>
        <?= $businessName ?> has <?= number_format($reviewCount) ?> Google reviews. Your <?= $nicheDisplay ?>
        customers are already telling people how good you are — but only in text that most people scroll past.
    </p>
    <p>
        This video turns one of those reviews into something you can post on Facebook, Instagram,
        your website, or send to new leads. It's yours — no strings attached.
    </p>
</div>

<!-- Section 4: Stats -->
<div class="stats">
    <ul>
        <li><strong>88%</strong> of people say watching a business's video convinced them to buy</li>
        <li>Google listings with video get <strong>42% more</strong> direction requests</li>
        <li>Short-form video gets <strong>2.5x more</strong> engagement than any other format</li>
    </ul>
</div>

<!-- Section 5: How it works -->
<div class="how-it-works">
    <h2>How it works</h2>
    <div class="steps">
        <div class="step">
            <div class="step-num">1</div>
            <div class="step-text">
                <h3>We pick your best Google reviews</h3>
                <p>The ones with the most detail and emotion</p>
            </div>
        </div>
        <div class="step">
            <div class="step-num">2</div>
            <div class="step-text">
                <h3>Turn them into short videos</h3>
                <p>Professional voiceover, music, your branding</p>
            </div>
        </div>
        <div class="step">
            <div class="step-num">3</div>
            <div class="step-text">
                <h3>You post. Customers call.</h3>
                <p>Facebook, Instagram, your website — takes 10 seconds</p>
            </div>
        </div>
    </div>
</div>

<!-- Section 6: Pricing -->
<div class="pricing" id="pricing">
    <h2>Want a fresh video every week?</h2>
    <p class="intro">
        You got this one free. Subscribe and we'll send new videos from different reviews every month.
    </p>

    <?php if (!empty($_GET['subscription_activated'])): ?>
    <div class="banner-success">
        <h3>You're subscribed!</h3>
        <p>Your first batch of videos will arrive within 48 hours.</p>
    </div>
    <?php endif; ?>

    <?php if (!empty($_GET['subscription_cancelled'])): ?>
    <div class="banner-cancel">
        <p>No worries — you weren't charged. Your free video is still yours.</p>
    </div>
    <?php endif; ?>

    <div class="price-card">
        <span class="badge">Most popular for <?= $nicheDisplay ?></span>
        <div class="amount"><?= $symbol ?><?= $price8 ?><span class="period">/mo</span></div>
        <p class="desc">8 videos per month (2 per week)</p>
        <p class="cancel">Cancel anytime. No lock-in. No contracts.</p>

        <div class="email-gate" id="email-gate" <?= !empty($_GET['subscription_activated']) ? 'style="display:none"' : '' ?>>
            <label for="sub-email">Where should we send your videos?</label>
            <input type="email" id="sub-email" placeholder="you@yourbusiness.com">
            <div class="error" id="sub-error"></div>
        </div>

        <button class="btn-primary subscribe-btn" data-tier="monthly_8">Get Started</button>
    </div>

    <p class="alt-tiers">
        Need fewer? <a href="#" class="subscribe-btn" data-tier="monthly_4">4 videos/mo for <?= $symbol ?><?= $price4 ?></a> &middot;
        Need more? <a href="#" class="subscribe-btn" data-tier="monthly_12">12 videos/mo for <?= $symbol ?><?= $price12 ?></a>
    </p>
</div>

<!-- Section 7: FAQ -->
<div class="faq">
    <h2>Fair questions</h2>

    <details open>
        <summary>Did I ask for this?</summary>
        <div class="answer">
            Nope. We found <?= $businessName ?> through your Google reviews and thought your
            <?= $starRating ?>-star reputation deserved more than just text on a screen.
            The video is genuinely free — no invoice coming, no catch.
        </div>
    </details>

    <details>
        <summary>What do I get if I sign up?</summary>
        <div class="answer">
            Each month we pick your strongest new Google reviews, turn them into professional short
            videos with voiceover and music, and deliver them ready to post on Instagram, Facebook,
            your website, or your Google listing. You just approve and share.
        </div>
    </details>

    <details>
        <summary>Can I cancel anytime?</summary>
        <div class="answer">
            Yes. Monthly billing, no contracts, no lock-in. If it's not working for you, cancel and that's it.
        </div>
    </details>

    <details>
        <summary>I don't really do social media</summary>
        <div class="answer">
            These videos work just as well on your website or Google Business Profile.
            Most of our <?= $nicheDisplay ?> clients just post them and move on — takes about 30 seconds.
        </div>
    </details>
</div>

<!-- Section 8: Sticky mobile CTA -->
<div class="sticky-cta" id="sticky-cta">
    <div class="price-hint"><strong><?= $symbol ?><?= $price8 ?></strong>/mo</div>
    <button class="btn-sticky" onclick="document.getElementById('pricing').scrollIntoView({behavior:'smooth'})">Get Started</button>
</div>

<!-- Section 9: Footer -->
<div class="footer">
    &copy; <?= date('Y') ?> Audit&amp;Fix &middot;
    <a href="/privacy.php">Privacy</a> &middot;
    <a href="/terms.php">Terms</a>
</div>

<!-- Scripts: play button, tracking, sticky CTA, PayPal checkout -->
<script>
(function() {
    var video = document.getElementById('review-video');
    var playBtn = document.getElementById('play-btn');
    var endOverlay = document.getElementById('end-overlay');
    var stickyCta = document.getElementById('sticky-cta');
    var videoWrap = document.getElementById('video-wrap');
    var hash = '<?= $hash ?>';

    // ── Tracking beacons ──
    function track(event) {
        fetch('/api.php?action=video-viewed', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ hash: hash, event: event }),
            keepalive: true
        }).catch(function() {});
    }

    // Page view
    track('page_viewed');

    // ── Play button ──
    playBtn.addEventListener('click', function() {
        playBtn.classList.add('hidden');
        endOverlay.classList.remove('show');
        video.play();
        track('video_play_started');
    });

    // Tap video to pause/play
    video.addEventListener('click', function() {
        if (video.paused) { video.play(); }
        else { video.pause(); }
    });

    // ── Video progress tracking ──
    var tracked25 = false, tracked50 = false, tracked75 = false;
    video.addEventListener('timeupdate', function() {
        if (!video.duration) return;
        var pct = video.currentTime / video.duration;
        if (pct >= 0.25 && !tracked25) { tracked25 = true; track('video_25_pct'); }
        if (pct >= 0.50 && !tracked50) { tracked50 = true; track('video_50_pct'); }
        if (pct >= 0.75 && !tracked75) { tracked75 = true; track('video_75_pct'); }
    });

    // ── End overlay ──
    video.addEventListener('ended', function() {
        endOverlay.classList.add('show');
        track('video_completed');
    });

    // ── SMS deep-link (iOS vs Android) ──
    var replyLink = document.getElementById('reply-link');
    if (replyLink) {
        var isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent);
        var num = '<?= $smsNumber ?>';
        replyLink.href = isIOS
            ? 'sms:' + num + '&body=Interested'
            : 'sms:' + num + '?body=Interested';
    }

    // ── Sticky CTA: show after scrolling past video ──
    if ('IntersectionObserver' in window && window.innerWidth <= 768) {
        document.body.classList.add('has-sticky');
        var observer = new IntersectionObserver(function(entries) {
            stickyCta.classList.toggle('show', !entries[0].isIntersecting);
        }, { threshold: 0 });
        observer.observe(videoWrap);
    }

    // ── Pricing scroll tracking ──
    var pricingEl = document.getElementById('pricing');
    if ('IntersectionObserver' in window && pricingEl) {
        var pricingTracked = false;
        var po = new IntersectionObserver(function(entries) {
            if (entries[0].isIntersecting && !pricingTracked) {
                pricingTracked = true;
                track('pricing_scrolled');
            }
        }, { threshold: 0.3 });
        po.observe(pricingEl);
    }

    // ── PayPal subscription checkout ──
    var emailInput = document.getElementById('sub-email');
    var errorEl = document.getElementById('sub-error');

    document.querySelectorAll('.subscribe-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (errorEl) { errorEl.style.display = 'none'; }

            var email = (emailInput.value || '').trim();
            if (!email || !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                if (errorEl) { errorEl.textContent = 'Please enter a valid email.'; errorEl.style.display = ''; }
                if (emailInput) emailInput.focus();
                return;
            }

            var tier = btn.getAttribute('data-tier');
            btn.disabled = true;
            var origText = btn.textContent;
            btn.textContent = 'Redirecting to PayPal…';

            track('checkout_started');

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
                    if (errorEl) { errorEl.textContent = data.error || 'Something went wrong.'; errorEl.style.display = ''; }
                    btn.disabled = false;
                    btn.textContent = origText;
                }
            })
            .catch(function() {
                if (errorEl) { errorEl.textContent = 'Network error. Please try again.'; errorEl.style.display = ''; }
                btn.disabled = false;
                btn.textContent = origText;
            });
        });
    });

    // Handle PayPal return
    var params = new URLSearchParams(window.location.search);
    var subId = params.get('subscription_id');
    if (subId && params.get('subscription_activated') === '1') {
        fetch('/api.php?action=activate-subscription', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ subscription_id: subId, video_hash: hash })
        }).catch(function() {});
        track('subscription_activated');
    }

    // ── Audio priming for Chromium ──
    var primed = false;
    function primeAudio() {
        if (primed) return;
        primed = true;
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var buf = ctx.createBuffer(1, 1, 22050);
            var src = ctx.createBufferSource();
            src.buffer = buf; src.connect(ctx.destination); src.start(0);
        } catch(e) {}
        document.removeEventListener('scroll', primeAudio);
        document.removeEventListener('touchstart', primeAudio);
    }
    document.addEventListener('scroll', primeAudio, { once: true, passive: true });
    document.addEventListener('touchstart', primeAudio, { once: true, passive: true });
})();
</script>

</body>
</html>
