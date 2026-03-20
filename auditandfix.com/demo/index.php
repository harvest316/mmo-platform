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
        'video_url'     => 'https://pub-9e277996d5a74eee9508a861cccead66.r2.dev/video-s900001-1773988661103.mp4',
        'poster_url'    => 'https://pub-9e277996d5a74eee9508a861cccead66.r2.dev/poster-s900001-1773988673158.jpg',
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
        'video_url'     => 'https://pub-9e277996d5a74eee9508a861cccead66.r2.dev/video-s900002-1773991123649.mp4',
        'poster_url'    => 'https://pub-9e277996d5a74eee9508a861cccead66.r2.dev/poster-s900002-1773991135596.jpg',
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
        // TODO: re-render when ElevenLabs quota resets (exhausted 2026-03-20)
        'video_url'     => '',
        'poster_url'    => '',
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
            <p>See how we turn your best Google reviews into professional 30-second videos.</p>
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
    <meta name="description" content="A free 30-second video created from your best Google review. See what your customers are saying about <?= $businessName ?>.">
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
        .pricing-card .price { font-size: 2rem; font-weight: bold; color: #2563eb; }
        .pricing-card .period { color: #666; font-size: 0.9rem; }
        .pricing-card .label { font-weight: 600; margin-bottom: 0.5rem; }
        .cta-button { display: inline-block; background: #2563eb; color: white; padding: 14px 32px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 1.1rem; margin-top: 1.5rem; }
        .cta-button:hover { background: #1d4ed8; }
        .demo-badge { display: inline-block; background: #fef3c7; color: #92400e; padding: 4px 12px; border-radius: 4px; font-size: 0.85rem; font-weight: 600; margin-bottom: 1rem; }
        .footer-note { text-align: center; padding: 2rem; color: #999; font-size: 0.85rem; }
    </style>
</head>
<body>
    <div class="video-hero">
        <span class="demo-badge">DEMO</span>
        <h1>We made this for <?= $businessName ?></h1>
        <p class="subtitle">A free 30-second video from your best Google review</p>

        <?php if ($videoUrl): ?>
        <div class="video-container">
            <video controls playsinline preload="metadata" <?= $posterUrl ? "poster=\"$posterUrl\"" : '' ?>>
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
            We took one of your best and turned it into a 30-second video you can share on social media,
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

        <a href="/#order" class="cta-button">Get Started</a>
    </div>

    <div class="footer-note">
        <p>&copy; <?= date('Y') ?> Audit&amp;Fix. All rights reserved.</p>
        <p><a href="/privacy.php">Privacy</a> &middot; <a href="/terms.php">Terms</a></p>
    </div>
</body>
</html>
