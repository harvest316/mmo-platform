<?php
/**
 * Video Reviews — general service landing page.
 *
 * Not personalised (unlike v.php which is per-prospect).
 * For when someone asks "tell me more" or visits directly.
 * Prices are geo-resolved via getPriceForCountry().
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/geo.php';
require_once __DIR__ . '/../includes/pricing.php';

$countryCode  = detectCountry();
$videoPricing = get2StepPriceForCountry($countryCode);
$symbol  = $videoPricing['symbol'];
$price4  = $videoPricing['monthly_4'];
$price8  = $videoPricing['monthly_8'];
$price12 = $videoPricing['monthly_12'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Video Reviews — Turn Google Reviews into Social Content | Audit&amp;Fix</title>
    <meta name="description" content="We turn your best Google reviews into professional 30-second videos. Free demo included. Perfect for trades, dental, and local service businesses.">
    <link rel="canonical" href="https://auditandfix.com/video-reviews/">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .vr-hero { text-align: center; padding: 4rem 1rem 2rem; max-width: 720px; margin: 0 auto; }
        .vr-hero h1 { font-size: 2.2rem; margin-bottom: 1rem; line-height: 1.2; }
        .vr-hero .lead { font-size: 1.2rem; color: #555; margin-bottom: 2rem; line-height: 1.6; }
        .vr-hero .cta { display: inline-block; background: #2563eb; color: white; padding: 16px 36px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 1.1rem; }
        .vr-hero .cta:hover { background: #1d4ed8; }

        .steps { display: flex; gap: 2rem; justify-content: center; flex-wrap: wrap; padding: 3rem 1rem; max-width: 900px; margin: 0 auto; }
        .step { flex: 1; min-width: 220px; max-width: 280px; text-align: center; }
        .step .number { display: inline-block; width: 48px; height: 48px; line-height: 48px; border-radius: 50%; background: #dbeafe; color: #2563eb; font-weight: bold; font-size: 1.2rem; margin-bottom: 1rem; }
        .step h3 { margin-bottom: 0.5rem; }
        .step p { color: #666; line-height: 1.6; }

        .samples { background: #f8f9fa; padding: 3rem 1rem; text-align: center; }
        .samples h2 { margin-bottom: 2rem; }
        .sample-grid { display: flex; gap: 1.5rem; justify-content: center; flex-wrap: wrap; max-width: 900px; margin: 0 auto; }
        .sample-card { background: white; border-radius: 12px; overflow: hidden; width: 260px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .sample-card .placeholder { background: #e5e7eb; height: 360px; display: flex; align-items: center; justify-content: center; color: #999; font-size: 0.9rem; }
        .sample-card .label { padding: 0.75rem; font-weight: 600; }

        .pricing-section { padding: 3rem 1rem; text-align: center; }
        .pricing-section h2 { margin-bottom: 0.5rem; }
        .pricing-section .sub { color: #666; margin-bottom: 2rem; }
        .pricing-cards { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; max-width: 800px; margin: 0 auto; }
        .pricing-card { background: white; border: 1px solid #e5e7eb; border-radius: 12px; padding: 2rem 1.5rem; flex: 1; min-width: 200px; max-width: 250px; }
        .pricing-card.featured { border: 2px solid #2563eb; }
        .pricing-card .tier { font-weight: 600; margin-bottom: 0.5rem; }
        .pricing-card .price { font-size: 2rem; font-weight: bold; color: #2563eb; }
        .pricing-card .period { color: #666; }
        .pricing-card ul { text-align: left; margin-top: 1rem; padding-left: 1.2rem; }
        .pricing-card ul li { margin-bottom: 0.4rem; color: #444; }

        .faq { max-width: 640px; margin: 0 auto; padding: 3rem 1rem; }
        .faq h2 { text-align: center; margin-bottom: 2rem; }
        .faq-item { margin-bottom: 1.5rem; }
        .faq-item h3 { font-size: 1.05rem; margin-bottom: 0.3rem; }
        .faq-item p { color: #555; line-height: 1.6; }

        .bottom-cta { text-align: center; padding: 3rem 1rem; background: #f0f4ff; }
        .bottom-cta h2 { margin-bottom: 1rem; }
        .bottom-cta .cta { display: inline-block; background: #2563eb; color: white; padding: 16px 36px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 1.1rem; }

        .footer { text-align: center; padding: 2rem; color: #999; font-size: 0.85rem; }
    </style>
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-5SQNL8XS');</script>
<!-- End Google Tag Manager -->
</head>
<body>
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-5SQNL8XS"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->

<section class="vr-hero">
    <h1>Turn your Google reviews into<br>scroll-stopping video content</h1>
    <p class="lead">
        Your customers already love you. We take their words and turn them into
        professional 30-second videos — ready for Instagram, TikTok, your website,
        or anywhere you want more customers to see what people say about you.
    </p>
    <a href="#pricing" class="cta">See pricing</a>
</section>

<section class="steps">
    <div class="step">
        <div class="number">1</div>
        <h3>We find your best review</h3>
        <p>We scan your Google reviews and pick one that tells a great story about your business.</p>
    </div>
    <div class="step">
        <div class="number">2</div>
        <h3>We create a video</h3>
        <p>AI-powered production turns the review into a polished 30-second video with voiceover, music, and your logo.</p>
    </div>
    <div class="step">
        <div class="number">3</div>
        <h3>You post it everywhere</h3>
        <p>Download your video and share it on social media, embed it on your site, or use it in ads.</p>
    </div>
</section>

<section class="samples">
    <h2>Sample videos</h2>
    <div class="sample-grid">
        <div class="sample-card">
            <div class="placeholder">Pest Control sample video</div>
            <div class="label">Pest Control — Sydney</div>
        </div>
        <div class="sample-card">
            <div class="placeholder">Plumber sample video</div>
            <div class="label">Plumber — Melbourne</div>
        </div>
        <div class="sample-card">
            <div class="placeholder">Dentist sample video</div>
            <div class="label">Dentist — Los Angeles</div>
        </div>
    </div>
</section>

<section class="pricing-section" id="pricing">
    <h2>Simple, transparent pricing</h2>
    <p class="sub">One-time setup fee + monthly subscription. Cancel anytime.</p>

    <div class="pricing-cards">
        <div class="pricing-card">
            <div class="tier">Starter</div>
            <div class="price"><?= $symbol ?><?= $price4 ?><span class="period">/mo</span></div>
            <ul>
                <li>4 videos per month</li>
                <li>30-second format</li>
                <li>Your logo + branding</li>
                <li>Background music</li>
            </ul>
        </div>
        <div class="pricing-card featured">
            <div class="tier">Growth</div>
            <div class="price"><?= $symbol ?><?= $price8 ?><span class="period">/mo</span></div>
            <ul>
                <li>8 videos per month</li>
                <li>30-second format</li>
                <li>Your logo + branding</li>
                <li>Background music</li>
                <li>Priority delivery</li>
            </ul>
        </div>
        <div class="pricing-card">
            <div class="tier">Scale</div>
            <div class="price"><?= $symbol ?><?= $price12 ?><span class="period">/mo</span></div>
            <ul>
                <li>12 videos per month</li>
                <li>30-second format</li>
                <li>Your logo + branding</li>
                <li>Background music</li>
                <li>Priority delivery</li>
            </ul>
        </div>
    </div>
</section>

<section class="faq">
    <h2>Frequently asked questions</h2>

    <div class="faq-item">
        <h3>How does the free demo work?</h3>
        <p>We make one video from your best Google review at no cost. It's yours to keep and use however you want — no obligation to subscribe.</p>
    </div>
    <div class="faq-item">
        <h3>What kind of businesses is this for?</h3>
        <p>Any local service business with Google reviews — pest control, plumbers, dentists, HVAC, roofers, dog trainers, and more. If your customers leave reviews, we can turn them into videos.</p>
    </div>
    <div class="faq-item">
        <h3>Do I need to do anything?</h3>
        <p>Nothing. We handle everything — finding reviews, creating videos, and delivering them to you. Just post them.</p>
    </div>
    <div class="faq-item">
        <h3>Can I cancel anytime?</h3>
        <p>Yes. Monthly subscriptions with no lock-in. Cancel whenever you want.</p>
    </div>
    <div class="faq-item">
        <h3>How long does each video take?</h3>
        <p>Each video is about 30 seconds — perfect for social media attention spans. Long enough to tell a story, short enough to keep people watching.</p>
    </div>
</section>

<section class="bottom-cta">
    <h2>Your reviews are already there.<br>Let's turn them into content.</h2>
    <a href="mailto:videos@auditandfix.com" class="cta">Get your free demo</a>
</section>

<footer class="footer">
    <p>&copy; <?= date('Y') ?> Audit&amp;Fix. All rights reserved.</p>
    <p><a href="/privacy.php">Privacy</a> &middot; <a href="/terms.php">Terms</a></p>
</footer>

</body>
</html>
