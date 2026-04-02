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
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('scan.page_title') ?></title>
    <meta name="description" content="<?= htmlspecialchars(t('scan.page_description')) ?>">
    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('assets/css/scanner.css') ?>">
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="assets/img/favicon-32.png" sizes="32x32" type="image/png">
    <link rel="canonical" href="https://www.auditandfix.com/scan">
    <?php foreach (SUPPORTED_LANGS as $_hl): ?>
    <link rel="alternate" hreflang="<?= htmlspecialchars($_hl) ?>" href="https://www.auditandfix.com/scan?lang=<?= htmlspecialchars($_hl) ?>">
    <?php endforeach; ?>
    <link rel="alternate" hreflang="x-default" href="https://www.auditandfix.com/scan">
    <meta property="og:title" content="<?= htmlspecialchars(t('scan.og_title')) ?>">
    <meta property="og:description" content="<?= htmlspecialchars(t('scan.og_description')) ?>">
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
<?php require_once __DIR__ . '/includes/consent-banner.php'; ?>
<a href="#main-content" class="skip-link"><?= t('scan.skip_to_content') ?></a>

<?php require_once __DIR__ . '/includes/header.php'; ?>

<!-- ── Hero Banner ───────────────────────────────────────────────────────── -->
<header class="hero scan-hero-banner">

    <div class="hero-body">
        <div class="hero-content">
            <div id="scan-status" role="status" aria-live="polite" aria-atomic="true" class="sr-only"></div>
            <p class="pre-headline"><?= t('scan.pre_headline') ?></p>
            <h1><?= t('scan.h1') ?></h1>
            <p class="scan-h1-sub"><?= t('scan.h1_sub') ?></p>
            <p class="subtitle"><?= t('scan.subtitle') ?></p>

            <!-- Stage 1: Input -->
            <div id="stage-input">
                <p class="scan-intro"><?= t('scan.intro') ?></p>
                <form class="scan-form" id="scan-form" novalidate>
                    <div class="scan-input-row">
                        <label for="scan-url" class="sr-only"><?= t('scan.url_label') ?></label>
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
                            <?= t('scan.cta_button') ?>
                        </button>
                    </div>
                    <p class="scan-form-note"><?= t('scan.form_note') ?></p>
                    <div id="scan-error" class="scan-error" role="alert" style="display:none"></div>
                </form>

                <div class="hero-scan-trust">
                    <span>✓ <?= t('scan.trust_no_cc') ?></span>
                    <span>✓ <?= t('scan.trust_sites_scored') ?></span>
                    <span>✓ <?= t('scan.trust_instant') ?></span>
                </div>
            </div>

            <!-- Stage 2: Scanning animation -->
            <div id="stage-scanning" style="display:none">
                <div class="scan-progress-inner">
                    <div class="scan-spinner"></div>
                    <h2 class="scan-progress-title"><?= t('scan.progress_title') ?></h2>
                    <div class="scan-progress-steps" id="progress-steps">
                        <div class="progress-step" id="step-fetch"><?= t('scan.step_fetch') ?></div>
                        <div class="progress-step" id="step-headline"><?= t('scan.step_headline') ?></div>
                        <div class="progress-step" id="step-cta"><?= t('scan.step_cta') ?></div>
                        <div class="progress-step" id="step-trust"><?= t('scan.step_trust') ?></div>
                        <div class="progress-step" id="step-value"><?= t('scan.step_value') ?></div>
                        <div class="progress-step" id="step-score"><?= t('scan.step_score') ?></div>
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
                            <span class="teaser-blur-label"><?= t('scan.teaser_blur_label') ?></span>
                        </div>
                    </div>
                </div>

                <!-- Email gate -->
                <div class="email-gate" id="email-gate">
                    <h3 class="email-gate-title" id="email-gate-title"><?= t('scan.email_gate_title') ?></h3>
                    <p class="email-gate-desc" id="email-gate-desc"><?= t('scan.email_gate_desc') ?></p>
                    <form class="email-form" id="email-form" novalidate>
                        <div class="email-input-row">
                            <label for="email-input" class="sr-only"><?= t('scan.email_label') ?></label>
                            <input
                                type="email"
                                id="email-input"
                                name="email"
                                class="email-input"
                                placeholder="your@email.com"
                                autocomplete="email"
                                required
                            >
                            <button type="submit" class="email-btn" id="email-btn"><?= t('scan.email_btn') ?></button>
                        </div>
                        <div class="email-optin-row">
                            <label class="email-optin-label">
                                <input type="checkbox" id="email-optin" name="optin" value="1" class="email-optin-checkbox">
                                <span><?= t('scan.email_optin') ?></span>
                            </label>
                        </div>
                        <div id="email-error" class="scan-error" role="alert" style="display:none"></div>
                        <p class="email-note"><?= t('scan.email_note') ?></p>
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
                <h3 class="factors-title"><?= t('scan.factors_title') ?></h3>
                <div class="factor-list" id="factor-list"></div>

                <div class="free-peek" id="free-peek" style="display:none">
                    <div class="free-peek-badge"><?= t('scan.free_peek_badge') ?></div>
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
                    <p><?= t('scan.js_heavy_note') ?></p>
                </div>

                <!-- Primary CTA: Quick Fixes (low-friction entry point) -->
                <div class="pricing-hero" id="pricing-hero">
                    <div class="pricing-hero-card">
                        <div class="pricing-hero-header">
                            <h3 class="pricing-hero-title"><?= t('scan.qf_title') ?></h3>
                            <p class="pricing-hero-subtitle"><?= t('scan.qf_subtitle') ?></p>
                        </div>
                        <div class="pricing-hero-price">
                            <span class="pricing-currency" data-usd="$" data-aud="$" data-gbp="£">$</span><span class="pricing-amount" data-usd="67" data-aud="97" data-gbp="47">67</span>
                        </div>
                        <ul class="pricing-hero-features">
                            <li><?= t('scan.qf_feature_1') ?></li>
                            <li><?= t('scan.qf_feature_2') ?></li>
                            <li><?= t('scan.qf_feature_3') ?></li>
                            <li><?= t('scan.qf_feature_4') ?></li>
                            <li><?= t('scan.qf_feature_5') ?></li>
                        </ul>
                        <a href="/" class="pricing-cta pricing-cta-primary" id="cta-quick-fixes"><?= t('scan.qf_cta') ?></a>
                        <p class="pricing-guarantee"><?= t('scan.qf_guarantee') ?></p>
                    </div>
                </div>

                <!-- Secondary CTA: Full Audit (upgrade path) -->
                <div class="pricing-upgrade-strip" id="pricing-upgrade-strip">
                    <div class="pricing-upgrade-text">
                        <strong><?= t('scan.full_audit_strong') ?></strong>
                        <span><?= t('scan.full_audit_desc') ?></span>
                    </div>
                    <div class="pricing-upgrade-action">
                        <span class="pricing-upgrade-price"><span class="pricing-currency" data-usd="$" data-aud="$" data-gbp="£">$</span><span class="pricing-amount" data-usd="297" data-aud="337" data-gbp="159">297</span></span>
                        <a href="/" class="pricing-cta pricing-cta-secondary" id="cta-full-audit"><?= t('scan.full_audit_cta') ?></a>
                    </div>
                </div>

                <!-- Tertiary: Audit + Implementation -->
                <div class="pricing-upgrade-strip pricing-upgrade-strip--tertiary" id="pricing-impl-strip">
                    <div class="pricing-upgrade-text">
                        <strong><?= t('scan.audit_fix_strong') ?></strong>
                        <span><?= t('scan.audit_fix_desc') ?></span>
                    </div>
                    <div class="pricing-upgrade-action">
                        <span class="pricing-upgrade-price"><span class="pricing-currency" data-usd="$" data-aud="$" data-gbp="£">$</span><span class="pricing-amount" data-usd="497" data-aud="625" data-gbp="350">497</span></span>
                        <a href="#" class="pricing-cta pricing-cta-secondary" id="cta-audit-fix"><?= t('scan.audit_fix_cta') ?></a>
                    </div>
                </div>
            </div>

        </div>
    </section>
</div>

<!-- ── Sales content (always visible below banner) ───────────────────────── -->
<main id="main-content">

    <!-- SEO content: How the free website audit works -->
    <section class="included">
        <div class="container">
            <h2><?= t('scan.how_title') ?></h2>
            <p class="section-subhead"><?= t('scan.how_subhead') ?></p>
            <div class="checklist">
                <div class="check-item"><?= t('scan.how_step_1') ?></div>
                <div class="check-item"><?= t('scan.how_step_2') ?></div>
                <div class="check-item"><?= t('scan.how_step_3') ?></div>
                <div class="check-item"><?= t('scan.how_step_4') ?></div>
                <div class="check-item"><?= t('scan.how_step_5') ?></div>
                <div class="check-item"><?= t('scan.how_step_6') ?></div>
            </div>
            <p class="included-footer"><?= t('scan.how_footer') ?></p>
        </div>
    </section>

    <!-- SEO content: What we check -->
    <section class="value-props">
        <div class="container">
            <h2 style="text-align:center; color:#1a365d; margin-bottom:12px; font-size:1.8rem;"><?= t('scan.factors_section_title') ?></h2>
            <p class="section-subhead"><?= t('scan.factors_section_subhead') ?></p>
            <div class="prop-grid">
                <div class="prop"><div class="prop-icon" aria-hidden="true">📝</div><h3><?= t('scan.factor_headline_title') ?></h3><p><?= t('scan.factor_headline_body') ?></p></div>
                <div class="prop"><div class="prop-icon" aria-hidden="true">💎</div><h3><?= t('scan.factor_value_title') ?></h3><p><?= t('scan.factor_value_body') ?></p></div>
                <div class="prop"><div class="prop-icon" aria-hidden="true">🎯</div><h3><?= t('scan.factor_cta_title') ?></h3><p><?= t('scan.factor_cta_body') ?></p></div>
                <div class="prop"><div class="prop-icon" aria-hidden="true">⭐</div><h3><?= t('scan.factor_trust_title') ?></h3><p><?= t('scan.factor_trust_body') ?></p></div>
            </div>
            <p style="text-align:center; margin-top:32px;"><a href="#" onclick="document.getElementById('scan-url')?.focus();window.scrollTo({top:0,behavior:'smooth'});return false;" class="cta-button"><?= t('scan.score_my_website_free') ?></a></p>
        </div>
    </section>

    <!-- Social proof -->
    <section class="testimonials">
        <div class="container">
            <p class="testimonials-rating">★★★★★ <?= t('scan.testimonials_rating') ?></p>
            <h2><?= t('scan.testimonials_title') ?></h2>
            <div class="testimonial-grid">
                <div class="testimonial">
                    <div class="stars" role="img" aria-label="<?= t('scan.stars_aria') ?>">★★★★★</div>
                    <p>"<?= t('scan.testimonial_1_quote') ?>"</p>
                    <div class="testimonial-author">
                        <strong><?= t('scan.testimonial_1_author') ?></strong>
                        <span><?= t('scan.testimonial_1_role') ?></span>
                    </div>
                </div>
                <div class="testimonial">
                    <div class="stars" role="img" aria-label="<?= t('scan.stars_aria') ?>">★★★★★</div>
                    <p>"<?= t('scan.testimonial_2_quote') ?>"</p>
                    <div class="testimonial-author">
                        <strong><?= t('scan.testimonial_2_author') ?></strong>
                        <span><?= t('scan.testimonial_2_role') ?></span>
                    </div>
                </div>
                <div class="testimonial">
                    <div class="stars" role="img" aria-label="<?= t('scan.stars_aria') ?>">★★★★★</div>
                    <p>"<?= t('scan.testimonial_3_quote') ?>"</p>
                    <div class="testimonial-author">
                        <strong><?= t('scan.testimonial_3_author') ?></strong>
                        <span><?= t('scan.testimonial_3_role') ?></span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Report preview -->
    <section class="montage-section">
        <div class="container">
            <h2><?= t('scan.montage_title') ?></h2>
            <p class="section-subhead"><?= t('scan.montage_subhead') ?></p>
            <div class="montage-grid">
                <div class="montage-item">
                    <img src="assets/img/report-cover.png" alt="<?= htmlspecialchars(t('scan.montage_img1_alt')) ?>" loading="lazy">
                    <p><?= t('scan.montage_caption_1') ?></p>
                </div>
                <div class="montage-item">
                    <img src="assets/img/report-factors.png" alt="<?= htmlspecialchars(t('scan.montage_img2_alt')) ?>" loading="lazy">
                    <p><?= t('scan.montage_caption_2') ?></p>
                </div>
                <div class="montage-item">
                    <img src="assets/img/report-screenshots.png" alt="<?= htmlspecialchars(t('scan.montage_img3_alt')) ?>" loading="lazy">
                    <p><?= t('scan.montage_caption_3') ?></p>
                </div>
                <div class="montage-item">
                    <img src="assets/img/report-recommendations.png" alt="<?= htmlspecialchars(t('scan.montage_img4_alt')) ?>" loading="lazy">
                    <p><?= t('scan.montage_caption_4') ?></p>
                </div>
            </div>
            <div class="sample-download">
                <a href="assets/sample-reports/sample-cro-audit.pdf" class="btn-secondary" target="_blank"><?= t('scan.sample_download_cta') ?></a>
                <span class="sample-note"><?= t('scan.sample_download_note') ?></span>
            </div>
        </div>
    </section>

    <!-- Value props -->
    <section class="value-props">
        <div class="container">
            <div class="prop-grid">
                <div class="prop"><div class="prop-icon" aria-hidden="true">🔍</div><h3><?= t('scan.prop_screenshots_title') ?></h3><p><?= t('scan.prop_screenshots_body') ?></p></div>
                <div class="prop"><div class="prop-icon" aria-hidden="true">📊</div><h3><?= t('scan.prop_10factors_title') ?></h3><p><?= t('scan.prop_10factors_body') ?></p></div>
                <div class="prop"><div class="prop-icon" aria-hidden="true">💬</div><h3><?= t('scan.prop_plain_english_title') ?></h3><p><?= t('scan.prop_plain_english_body') ?></p></div>
                <div class="prop"><div class="prop-icon" aria-hidden="true">📈</div><h3><?= t('scan.prop_agency_title') ?></h3><p><?= t('scan.prop_agency_body') ?></p></div>
            </div>
        </div>
    </section>

    <!-- What's included -->
    <section class="included">
        <div class="container">
            <h2><?= t('scan.audit_covers_title') ?></h2>
            <p class="section-subhead"><?= t('scan.audit_covers_subhead') ?></p>
            <div class="checklist">
                <div class="check-item"><?= t('scan.checklist_1') ?></div>
                <div class="check-item"><?= t('scan.checklist_2') ?></div>
                <div class="check-item"><?= t('scan.checklist_3') ?></div>
                <div class="check-item"><?= t('scan.checklist_4') ?></div>
                <div class="check-item"><?= t('scan.checklist_5') ?></div>
                <div class="check-item"><?= t('scan.checklist_6') ?></div>
                <div class="check-item"><?= t('scan.checklist_7') ?></div>
                <div class="check-item"><?= t('scan.checklist_8') ?></div>
                <div class="check-item"><?= t('scan.checklist_9') ?></div>
                <div class="check-item"><?= t('scan.checklist_10') ?></div>
            </div>
            <p class="included-footer"><?= t('scan.audit_covers_footer') ?></p>
        </div>
    </section>

    <!-- USP -->
    <section class="usp">
        <div class="container">
            <h2><?= t('scan.usp_title') ?></h2>
            <p><?= t('scan.usp_p1') ?></p>
            <p><?= t('scan.usp_p2') ?></p>
        </div>
    </section>

    <!-- Urgency mid-page CTA -->
    <section class="urgency-section">
        <div class="container">
            <div class="urgency-inner">
                <div class="urgency-text">
                    <h2><?= t('scan.urgency_title') ?></h2>
                    <p><?= t('scan.urgency_p1') ?></p>
                    <p class="urgency-scarcity"><?= t('scan.urgency_scarcity') ?></p>
                </div>
                <div class="urgency-cta">
                    <a href="/" class="cta-button" id="urgency-cta-qf"><?= t('scan.urgency_cta') ?><span class="pricing-currency" data-usd="$" data-aud="$" data-gbp="£">$</span><span class="pricing-amount" data-usd="67" data-aud="97" data-gbp="47">67</span></a>
                    <p class="urgency-note"><?= t('scan.urgency_note') ?></p>
                </div>
            </div>
        </div>
    </section>

    <!-- Meet Marcus -->
    <section class="meet-expert">
        <div class="container">
            <div class="expert-card">
                <img src="assets/img/marcus-webb.jpg" alt="<?= htmlspecialchars(t('scan.expert_photo_alt')) ?>" class="expert-photo">
                <div class="expert-bio">
                    <p class="expert-label"><?= t('scan.expert_label') ?></p>
                    <h2><?= t('scan.expert_name') ?></h2>
                    <p class="expert-title"><?= t('scan.expert_title') ?></p>
                    <p><?= t('scan.expert_p1') ?></p>
                    <p><?= t('scan.expert_p2') ?></p>
                    <p><?= t('scan.expert_p3') ?></p>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ -->
    <section class="faq">
        <div class="container">
            <h2><?= t('scan.faq_section_title') ?></h2>
            <div class="faq-item">
                <h3><?= t('scan.faq_1_q') ?></h3>
                <p><?= t('scan.faq_1_a') ?></p>
            </div>
            <div class="faq-item">
                <h3><?= t('scan.faq_2_q') ?></h3>
                <p><?= t('scan.faq_2_a') ?></p>
            </div>
            <div class="faq-item">
                <h3><?= t('scan.faq_3_q') ?></h3>
                <p><?= t('scan.faq_3_a') ?></p>
            </div>
            <div class="faq-item">
                <h3><?= t('scan.faq_4_q') ?></h3>
                <p><?= t('scan.faq_4_a') ?></p>
            </div>
            <div class="faq-item">
                <h3><?= t('scan.faq_5_q') ?></h3>
                <p><?= t('scan.faq_5_a') ?></p>
            </div>
            <div class="faq-item">
                <h3><?= t('scan.faq_6_q') ?></h3>
                <p><?= t('scan.faq_6_a') ?></p>
            </div>
        </div>
    </section>

    <!-- Footer CTA -->
    <section class="footer-cta">
        <div class="container">
            <h2><?= t('scan.footer_cta_h2') ?></h2>
            <a href="/" class="cta-button" id="footer-cta-qf"><?= t('scan.footer_cta_btn') ?><span class="pricing-currency" data-usd="$" data-aud="$" data-gbp="£">$</span><span class="pricing-amount" data-usd="67" data-aud="97" data-gbp="47">67</span></a>
            <p><?= t('scan.footer_cta_sub') ?></p>
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
<script src="<?= asset_url('assets/js/exit-intent.js') ?>" defer></script>

<!-- Exit-intent modal: Quick Fixes downsell (desktop only, once per session) -->
<div id="exit-modal-backdrop" class="exit-modal-backdrop" style="display:none">
  <div class="exit-modal" role="dialog" aria-labelledby="exit-modal-heading">
    <button class="exit-modal-close" aria-label="<?= htmlspecialchars(t('scan.exit_close_label')) ?>">&times;</button>
    <h3 id="exit-modal-heading"><?= t('scan.exit_heading') ?></h3>
    <p class="exit-modal-body"><?= t('scan.exit_body') ?>
      <span class="exit-modal-currency" data-usd="$" data-aud="$" data-gbp="£">$</span><span class="exit-modal-amount" data-usd="67" data-aud="97" data-gbp="47">67</span>
    </p>
    <p class="exit-modal-desc"><?= t('scan.exit_desc') ?></p>
    <a href="#" class="exit-modal-cta" id="exit-modal-cta"><?= t('scan.exit_cta') ?></a>
    <p class="exit-modal-guarantee"><?= t('scan.exit_guarantee') ?></p>
  </div>
</div>

</body>
</html>
