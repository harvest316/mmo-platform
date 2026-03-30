<?php
require_once __DIR__ . '/includes/config.php';

// First-visit discount: IP+UA fingerprint stored server-side (no cookies)
$dealExpiresAt = getDealExpiresAt();

require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/pricing.php';
require_once __DIR__ . '/includes/geo.php';

// Prefill params from outreach short-URL (/o/{site_id} redirect)
$prefillDomain  = isset($_GET['domain'])  ? preg_replace('/[^a-zA-Z0-9.\-]/', '', $_GET['domain']) : null;
$prefillEmail   = isset($_GET['email'])   ? filter_var($_GET['email'], FILTER_VALIDATE_EMAIL) ?: null : null;
$prefillCountry = isset($_GET['country']) ? preg_replace('/[^A-Z]/', '', strtoupper($_GET['country'])) : null;

// Product: validate ?product= param
$product = $_GET['product'] ?? 'full_audit';
if (!in_array($product, VALID_PRODUCTS, true)) {
    $product = 'full_audit';
}

// Country: use prefill override if provided and valid, else geo-detect
$countryCode   = ($prefillCountry && strlen($prefillCountry) === 2) ? $prefillCountry : detectCountry();
$pricing       = getPricing();
$priceData     = getPriceForCountry($countryCode, $pricing);
$productPriceData = getProductPriceForCountry($countryCode, $product);
$paypalClientId = PAYPAL_CLIENT_ID;
$paypalCurrency = $productPriceData['currency'];
$sitesScored    = number_format($pricing['_meta']['sites_scored'] ?? 10000);

// Buy link params: conversation ID from 333Method outreach, language override
$cid         = isset($_GET['cid']) ? (int)$_GET['cid'] : null; // conversation_id
$sandboxMode = PAYPAL_SANDBOX_FORCED;
// $lang already set by i18n.php
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('page.title') ?></title>
    <meta name="description" content="<?= t('page.description') ?>">
    <link rel="canonical" href="https://www.auditandfix.com/">
    <?php foreach (SUPPORTED_LANGS as $_hl): ?>
    <link rel="alternate" hreflang="<?= htmlspecialchars($_hl) ?>" href="https://www.auditandfix.com/?lang=<?= htmlspecialchars($_hl) ?>">
    <?php endforeach; ?>
    <link rel="alternate" hreflang="x-default" href="https://www.auditandfix.com/">
    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://www.auditandfix.com/">
    <meta property="og:title" content="<?= htmlspecialchars(t('page.title')) ?>">
    <meta property="og:description" content="<?= htmlspecialchars(t('page.description')) ?>">
    <meta property="og:image" content="https://www.auditandfix.com/assets/img/og-image.png">
    <meta property="og:site_name" content="Audit&amp;Fix">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="preload" as="image" href="assets/img/hero-background.png">
    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="assets/img/favicon-32.png" sizes="32x32" type="image/png">
    <!-- Schema.org structured data -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@graph": [
        {
          "@type": "WebSite",
          "@id": "https://www.auditandfix.com/#website",
          "url": "https://www.auditandfix.com/",
          "name": "Audit&Fix",
          "description": "Professional CRO audit reports for small business websites. 10 conversion factors scored, annotated screenshots, prioritised fix list.",
          "publisher": {"@id": "https://www.auditandfix.com/#organization"},
          "inLanguage": ["en","de","fr","es","ja","pt","nl","da","sv","ko","it","pl","zh","id","ru","hi","ar","tr","th","nb"]
        },
        {
          "@type": "Organization",
          "@id": "https://www.auditandfix.com/#organization",
          "name": "Audit&Fix",
          "url": "https://www.auditandfix.com/",
          "logo": {
            "@type": "ImageObject",
            "url": "https://www.auditandfix.com/assets/img/logo.svg"
          },
          "contactPoint": {
            "@type": "ContactPoint",
            "contactType": "customer support",
            "availableLanguage": ["en","de","fr","es","ja","pt","nl","da","sv","ko","it","pl","zh","id","ru","hi","ar","tr","th","nb"]
          },
          "sameAs": ["https://au.trustpilot.com/review/auditandfix.com"]
        },
        {
          "@type": "Person",
          "@id": "https://www.auditandfix.com/#marcus-webb",
          "name": "Marcus Webb",
          "jobTitle": "CRO Specialist & Marketing Strategist",
          "worksFor": {"@id": "https://www.auditandfix.com/#organization"},
          "description": "Senior CRO analyst with 24 years of combined digital marketing experience. Reviews every audit report before delivery.",
          "image": "https://www.auditandfix.com/assets/img/marcus-webb.jpg"
        },
        {
          "@type": "Service",
          "@id": "https://www.auditandfix.com/#service",
          "serviceType": "Website CRO Audit",
          "name": "CRO Audit Report",
          "provider": {"@id": "https://www.auditandfix.com/#organization"},
          "description": "AI-powered conversion rate optimisation audit. 10 factors scored A+ to F, annotated screenshots, and a prioritised fix list. Delivered as a PDF within 24 hours.",
          "areaServed": ["AU","US","GB","CA","NZ","IE","ZA","IN"],
          "offers": {
            "@type": "Offer",
            "url": "https://www.auditandfix.com/#order",
            "price": "<?= $productPriceData['price'] ?>",
            "priceCurrency": "<?= $productPriceData['currency'] ?>",
            "availability": "https://schema.org/InStock",
            "seller": {"@id": "https://www.auditandfix.com/#organization"},
            "hasMerchantReturnPolicy": {
              "@type": "MerchantReturnPolicy",
              "returnPolicyCategory": "https://schema.org/MerchantReturnFiniteReturnWindow",
              "merchantReturnDays": 30
            }
          }
        },
        {
          "@type": "Product",
          "@id": "https://www.auditandfix.com/#product-cro-audit",
          "name": "CRO Audit Report",
          "description": "Professional 9-page PDF with 10 conversion factors scored A+ to F, annotated screenshots of every problem area, and a prioritised fix list. Delivered within 24 hours.",
          "brand": {"@id": "https://www.auditandfix.com/#organization"},
          "image": "https://www.auditandfix.com/assets/img/report-cover.png",
          "offers": {
            "@type": "Offer",
            "url": "https://www.auditandfix.com/#order",
            "price": "<?= $productPriceData['price'] ?>",
            "priceCurrency": "<?= $productPriceData['currency'] ?>",
            "availability": "https://schema.org/InStock",
            "seller": {"@id": "https://www.auditandfix.com/#organization"},
            "hasMerchantReturnPolicy": {
              "@type": "MerchantReturnPolicy",
              "returnPolicyCategory": "https://schema.org/MerchantReturnFiniteReturnWindow",
              "merchantReturnDays": 30
            },
            "shippingDetails": {
              "@type": "OfferShippingDetails",
              "deliveryTime": {
                "@type": "ShippingDeliveryTime",
                "handlingTime": {
                  "@type": "QuantitativeValue",
                  "minValue": 0,
                  "maxValue": 24,
                  "unitCode": "HUR"
                }
              }
            }
          }
        },
        {
          "@type": "BreadcrumbList",
          "itemListElement": [
            {
              "@type": "ListItem",
              "position": 1,
              "name": "Home",
              "item": "https://www.auditandfix.com/"
            }
          ]
        },
        {
          "@type": "FAQPage",
          "@id": "https://www.auditandfix.com/#faq",
          "mainEntity": [
            {"@type":"Question","name":"How long until I get my report?","acceptedAnswer":{"@type":"Answer","text":"Reports are delivered to the email you provide within 24 hours of payment. Most are delivered within a few hours during business hours."}},
            {"@type":"Question","name":"What format is the report?","acceptedAnswer":{"@type":"Answer","text":"You\u2019ll receive a professional PDF (typically 9 pages) with scored analysis, zoomed screenshots of every problem area, and a prioritised fix list."}},
            {"@type":"Question","name":"What does the report actually show?","acceptedAnswer":{"@type":"Answer","text":"For each of the 10 conversion factors, you get: a score (0\u201310), a grade (A+ to F), a plain-English explanation of what\u2019s wrong, and a specific recommendation. You also get actual zoomed screenshots showing the exact problem area on your page."}},
            {"@type":"Question","name":"What if my site is already performing well?","acceptedAnswer":{"@type":"Answer","text":"Even high-scoring sites have room for improvement. Our report identifies specific optimisations regardless of your starting point. If you\u2019re genuinely satisfied there\u2019s nothing to act on, we offer a money-back guarantee."}},
            {"@type":"Question","name":"Do you implement the fixes?","acceptedAnswer":{"@type":"Answer","text":"The report identifies and prioritises the issues \u2014 implementation is up to you or your developer. Most quick wins take under an hour to fix."}},
            {"@type":"Question","name":"How is this different from free tools like Google Lighthouse?","acceptedAnswer":{"@type":"Answer","text":"Lighthouse scores your page speed and accessibility \u2014 useful, but it has nothing to do with conversions. It can\u2019t tell you if your headline is weak, your CTA is buried, or your trust signals are in the wrong place. Those are exactly what we analyse, on the real visual page, the way a visitor actually sees it."}},
            {"@type":"Question","name":"Is there a follow-up option after I\u2019ve made changes?","acceptedAnswer":{"@type":"Answer","text":"Yes \u2014 once you\u2019ve implemented fixes, you can order a follow-up benchmarking audit at 50% of the original price to measure how much your score improved."}},
            {"@type":"Question","name":"What\u2019s a CTA?","acceptedAnswer":{"@type":"Answer","text":"CTA stands for Call-to-Action \u2014 the button, link, or prompt that tells your visitor what to do next. Examples: \"Get a Free Quote\", \"Book a Consultation\", \"Buy Now\". A weak CTA (wrong wording, wrong colour, buried too low) is one of the most common and most fixable conversion killers. Our report scores your CTA and tells you exactly what to change."}}
          ]
        }
      ]
    }
    </script>
</head>
<body>
<?php require_once __DIR__ . '/includes/consent-banner.php'; ?>
    <a href="#main-content" class="skip-link">Skip to main content</a>

<?php
$headerBanner = '<div id="deal-banner" class="deal-banner" style="display:none" role="alert" aria-live="polite">' . t('deal.banner') . '</div>';
$headerCta = ['text' => t('nav.cta'), 'href' => '#order'];
require_once __DIR__ . '/includes/header.php';
?>

    <!-- Hero Section -->
    <header class="hero" id="main-content">

        <div class="hero-body">
            <div class="hero-content">
                <p class="pre-headline"><?= t('hero.pre_headline') ?></p>
                <h1><?= t('hero.h1') ?></h1>
                <p class="subtitle"><?= t('hero.subtitle') ?> <strong><?= t('hero.subtitle_emphasis') ?></strong></p>

                <p class="hero-usp"><?= t('hero.usp') ?></p>
                <a href="#order" class="cta-button"><?= t('hero.cta_button', ['price' => $priceData['formatted']]) ?></a>
                <p class="cta-sub"><?= t('hero.cta_sub') ?></p>

                <div class="trust-bar">
                    <div class="trust-item">
                        <span class="trust-num" id="sites-scored-num"><?= htmlspecialchars(number_format($pricing['_meta']['sites_scored'] ?? 10000)) ?>+</span>
                        <span class="trust-label"><?= t('trust.sites_scored_label') ?></span>
                    </div>
                    <div class="trust-sep"></div>
                    <div class="trust-item">
                        <span class="trust-num"><?= t('trust.experience_num') ?></span>
                        <span class="trust-label"><?= t('trust.experience_label') ?></span>
                    </div>
                    <div class="trust-sep"></div>
                    <div class="trust-item">
                        <span class="trust-num"><?= t('trust.guarantee_num') ?></span>
                        <span class="trust-label"><?= t('trust.guarantee_label') ?></span>
                    </div>
                </div>
            </div>

        </div>
    </header>

    <!-- Social Proof / Testimonials -->
    <section class="testimonials">
        <div class="container">
            <h2><?= t('testimonials.section_title') ?></h2>
            <div class="testimonial-grid">
                <?php for ($i = 1; $i <= 3; $i++): ?>
                <div class="testimonial">
                    <div class="stars" role="img" aria-label="5 out of 5 stars">★★★★★</div>
                    <p>"<?= t("testimonials.{$i}.quote") ?>"</p>
                    <div class="testimonial-author">
                        <strong><?= t("testimonials.{$i}.author") ?></strong>
                        <span><?= t("testimonials.{$i}.role") ?></span>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        </div>
    </section>

    <!-- Report Screenshots Montage -->
    <section class="montage-section">
        <div class="container">
            <h2><?= t('montage.title') ?></h2>
            <p class="section-subhead"><?= t('montage.subhead') ?></p>
            <div class="montage-grid">
                <div class="montage-item">
                    <img src="assets/img/report-cover.png" alt="Report cover page showing domain, score and grade" loading="lazy">
                    <p><?= t('montage.item1_caption') ?></p>
                </div>
                <div class="montage-item">
                    <img src="assets/img/report-factors.png" alt="Factor analysis page with score bars" loading="lazy">
                    <p><?= t('montage.item2_caption') ?></p>
                </div>
                <div class="montage-item">
                    <img src="assets/img/report-screenshots.png" alt="Zoomed screenshots of problem areas" loading="lazy">
                    <p><?= t('montage.item3_caption') ?></p>
                </div>
                <div class="montage-item">
                    <img src="assets/img/report-recommendations.png" alt="Prioritised recommendations page" loading="lazy">
                    <p><?= t('montage.item4_caption') ?></p>
                </div>
            </div>
            <div class="sample-download">
                <a href="assets/sample-reports/sample-cro-audit.pdf" class="btn-secondary" target="_blank"><?= t('montage.download_cta') ?></a>
                <span class="sample-note"><?= t('montage.download_note') ?></span>
            </div>
        </div>
    </section>

    <!-- Value Proposition -->
    <section class="value-props">
        <div class="container">
            <div class="prop-grid">
                <div class="prop"><div class="prop-icon" aria-hidden="true">🔍</div><h3><?= t('props.1.title') ?></h3><p><?= t('props.1.body') ?></p></div>
                <div class="prop"><div class="prop-icon" aria-hidden="true">📊</div><h3><?= t('props.2.title') ?></h3><p><?= t('props.2.body') ?></p></div>
                <div class="prop"><div class="prop-icon" aria-hidden="true">💬</div><h3><?= t('props.3.title') ?></h3><p><?= t('props.3.body') ?></p></div>
                <div class="prop"><div class="prop-icon" aria-hidden="true">📈</div><h3><?= t('props.4.title') ?></h3><p><?= t('props.4.body') ?></p></div>
            </div>
        </div>
    </section>

    <!-- What's Included checklist -->
    <section class="included">
        <div class="container">
            <h2><?= t('checklist.title') ?></h2>
            <p class="section-subhead"><?= t('checklist.subhead') ?></p>
            <div class="checklist">
                <?php for ($i = 1; $i <= 10; $i++): ?>
                <div class="check-item">✓ <?= t("checklist.{$i}") ?></div>
                <?php endfor; ?>
            </div>
            <p class="included-footer"><?= t('checklist.footer') ?></p>
        </div>
    </section>

    <!-- USP -->
    <section class="usp">
        <div class="container">
            <h2><?= t('usp.title') ?></h2>
            <p><?= t('usp.p1', ['sites_scored' => $sitesScored]) ?></p>
            <p><?= t('usp.p2') ?></p>
        </div>
    </section>

    <!-- Urgency mid-page CTA -->
    <section class="urgency-section">
        <div class="container">
            <div class="urgency-inner">
                <div class="urgency-text">
                    <h2><?= t('urgency.title') ?></h2>
                    <p><?= t('urgency.p1') ?></p>
                    <p class="urgency-scarcity"><?= t('urgency.scarcity') ?></p>
                </div>
                <div class="urgency-cta">
                    <a href="#order" class="cta-button"><?= t('urgency.cta_button', ['price' => $priceData['formatted']]) ?></a>
                    <p class="urgency-note"><?= t('urgency.cta_sub') ?></p>
                </div>
            </div>
        </div>
    </section>

    <!-- Meet Marcus Webb -->
    <section class="meet-expert">
        <div class="container">
            <div class="expert-card">
                <img src="assets/img/marcus-webb.jpg" alt="<?= t('expert.photo_alt') ?>" class="expert-photo">
                <div class="expert-bio">
                    <p class="expert-label"><?= t('expert.label') ?></p>
                    <h2><?= t('expert.name') ?></h2>
                    <p class="expert-title"><?= t('expert.title') ?></p>
                    <p><?= t('expert.p1') ?></p>
                    <p><?= t('expert.p2', ['sites_scored' => $sitesScored]) ?></p>
                    <p><?= t('expert.p3') ?></p>
                </div>
            </div>
        </div>
    </section>

    <!-- Order Form -->
    <section id="order" class="order-section">
        <div class="container">
            <div class="order-icon">
                <img src="assets/img/icon-audit.svg" alt="" aria-hidden="true" class="order-icon-img">
            </div>
            <?php if ($product === 'quick_fixes'): ?>
            <h2>Get Your Quick Fixes Report</h2>
            <p class="order-subtitle">Your top 5 issues with screenshot annotations and plain-English fix instructions. Same-day delivery.</p>
            <?php elseif ($product === 'audit_fix'): ?>
            <h2>Get Your Audit + Implementation</h2>
            <p class="order-subtitle">Full 10-factor audit plus we implement your top 3 fixes. 48-hour turnaround with before/after screenshots.</p>
            <?php else: ?>
            <h2><?= t('order.h2') ?></h2>
            <p class="order-subtitle"><?= t('order.subtitle') ?></p>
            <?php endif; ?>

            <form id="audit-form" class="audit-form">
                <div id="form-error" class="form-error" role="alert"></div>

                <div class="form-group">
                    <label for="email"><?= t('order.email_label') ?></label>
                    <input type="email" id="email" name="email" required placeholder="<?= t('order.email_placeholder') ?>">
                    <p class="field-hint"><?= t('order.email_hint') ?></p>
                </div>

                <div class="form-group">
                    <label for="url"><?= t('order.url_label') ?></label>
                    <input type="url" id="url" name="url" required placeholder="<?= t('order.url_placeholder') ?>" pattern="https?://.+">
                    <p class="field-hint"><?= t('order.url_hint') ?></p>
                </div>

                <div class="form-group">
                    <label for="phone"><?= t('order.phone_label') ?></label>
                    <input type="tel" id="phone" name="phone" placeholder="<?= t('order.phone_placeholder') ?>">
                </div>

                <div class="form-group">
                    <label for="currency"><?= t('order.country_label') ?> <span class="label-hint"><?= t('order.currency_hint') ?></span></label>
                    <select id="currency" name="currency">
                        <!-- Populated by JS from pricing data -->
                    </select>
                </div>

                <div class="price-display">
                    <span class="price-label"><?= t('order.price_label') ?></span>
                    <span id="display-price" class="price-amount"><?= htmlspecialchars($productPriceData['formatted']) ?></span>
                    <span class="price-meta"><?= t('order.price_meta') ?></span>
                </div>
                <div id="order-deal-banner" class="order-deal-banner" style="display:none">
                    ⏰ <?= t('order.deal_offer') ?> <strong id="order-deal-timer">20:00</strong>
                </div>

                <div id="paypal-button-container"></div>

                <p class="form-note"><?= t('order.paypal_note') ?></p>
            </form>

            <div class="order-guarantee">
                <div class="guarantee-icon">✓</div>
                <div>
                    <strong><?= t('order.guarantee_money_title') ?></strong>
                    <p><?= t('order.guarantee_money_body') ?></p>
                </div>
            </div>
            <div class="order-guarantee order-guarantee--delivery">
                <div class="guarantee-icon">⏰</div>
                <div>
                    <strong><?= t('order.guarantee_delivery_title') ?></strong>
                    <p><?= t('order.guarantee_delivery_body') ?></p>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ -->
    <section class="faq">
        <div class="container">
            <h2><?= t('faq.title') ?></h2>
            <?php for ($i = 1; $i <= 8; $i++): ?>
            <div class="faq-item">
                <h3><?= t("faq.{$i}.q") ?></h3>
                <p><?= t("faq.{$i}.a") ?></p>
            </div>
            <?php endfor; ?>
        </div>
    </section>

    <!-- Footer CTA -->
    <section class="footer-cta">
        <div class="container">
            <h2><?= t('footer_cta.h2') ?></h2>
            <a href="#order" class="cta-button"><?= t('footer_cta.cta', ['price' => $priceData['formatted']]) ?></a>
            <p><?= t('footer_cta.sub') ?></p>
        </div>
    </section>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>

    <script>
        window.PRICING_DATA       = <?= json_encode($pricing) ?>;
        window.DETECTED_COUNTRY   = <?= json_encode($countryCode) ?>;
        window.PRODUCT            = <?= json_encode($product) ?>;
        window.INITIAL_PRICE      = <?= json_encode($productPriceData) ?>;
        window.CONVERSATION_ID    = <?= json_encode($cid) ?>;
        window.LANG               = <?= json_encode($lang) ?>;
        window.SITES_SCORED       = <?= json_encode($pricing['_meta']['sites_scored'] ?? 10000) ?>;
        window.DEAL_DISCOUNT      = 0.20; // 20% first-visit discount
        window.DEAL_DURATION_MS   = 20 * 60 * 1000; // 20 minutes
        window.DEAL_EXPIRES_AT    = <?= $dealExpiresAt ?>; // server-set session expiry (ms)
        window.SANDBOX_MODE       = <?= json_encode($sandboxMode) ?>;
        window.TEST_PRICE         = <?= json_encode(TEST_PRICE) ?>; // null or float override
        window.PAYPAL_CLIENT_ID   = <?= json_encode($paypalClientId) ?>;
        window.PAYPAL_CURRENCY    = <?= json_encode($paypalCurrency) ?>;
        window.PAYPAL_SANDBOX     = <?= json_encode($sandboxMode) ?>;
        // Outreach prefill (set when prospect arrives via /o/{site_id} short URL)
        window.PREFILL_DOMAIN     = <?= json_encode($prefillDomain) ?>;
        window.PREFILL_EMAIL      = <?= json_encode($prefillEmail) ?>;
        window.PREFILL_COUNTRY    = <?= json_encode($prefillCountry) ?>;
        // Meta Pixel ID (server env → client; empty = pixel disabled)
        window.META_PIXEL_ID      = <?= json_encode(getenv('META_PIXEL_ID') ?: '') ?>;
    </script>
    <script src="assets/js/obfuscate-email.js?v=1" defer></script>
    <script src="<?= asset_url('assets/js/main.js') ?>" defer></script>
</body>
</html>
