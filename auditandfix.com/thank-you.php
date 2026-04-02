<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/pricing.php';

$emailRaw = $_GET['email'] ?? 'your email';
$email    = htmlspecialchars($emailRaw);
$product = $_GET['product'] ?? 'full_audit';
if (!in_array($product, VALID_PRODUCTS, true)) {
    $product = 'full_audit';
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(t('ty.page_title')) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="canonical" href="https://www.auditandfix.com/thank-you">
    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
</head>
<body>
<?php require_once __DIR__ . '/includes/consent-banner.php'; ?>
    <a href="#main-content" class="skip-link"><?= t('ty.skip_link') ?></a>
<?php require_once __DIR__ . '/includes/header.php'; ?>
    <header class="hero hero-short">

        <div class="hero-body">
            <div class="hero-content">
                <h1><?= t('ty.hero_h1') ?></h1>
            </div>
        </div>
    </header>

    <main id="main-content">
    <section class="thank-you-content">
        <div class="container">
            <div class="thank-you-box">
                <div class="check-circle">✓</div>

                <?php if ($product === 'quick_fixes'): ?>
                <h2><?= t('ty.qf_h2') ?></h2>
                <p><?= t('ty.qf_delivery', ['email' => $emailRaw]) ?></p>

                <div class="steps">
                    <div class="step">
                        <span class="step-num">1</span>
                        <span><?= t('ty.qf_step1') ?></span>
                    </div>
                    <div class="step">
                        <span class="step-num">2</span>
                        <span><?= t('ty.qf_step2') ?></span>
                    </div>
                    <div class="step">
                        <span class="step-num">3</span>
                        <span><?= t('ty.qf_step3') ?></span>
                    </div>
                </div>

                <div class="spam-notice">
                    <p><?= t('ty.qf_spam') ?></p>
                </div>

                <div class="thankyou-upsell">
                    <h3><?= t('ty.qf_upsell_h3') ?></h3>
                    <p><?= t('ty.qf_upsell_body') ?></p>
                </div>

                <?php elseif ($product === 'audit_fix'): ?>
                <h2><?= t('ty.af_h2') ?></h2>
                <p><?= t('ty.af_delivery', ['email' => $emailRaw]) ?></p>

                <div class="steps">
                    <div class="step">
                        <span class="step-num">1</span>
                        <span><?= t('ty.af_step1') ?></span>
                    </div>
                    <div class="step">
                        <span class="step-num">2</span>
                        <span><?= t('ty.af_step2') ?></span>
                    </div>
                    <div class="step">
                        <span class="step-num">3</span>
                        <span><?= t('ty.af_step3') ?></span>
                    </div>
                    <div class="step">
                        <span class="step-num">4</span>
                        <span><?= t('ty.af_step4') ?></span>
                    </div>
                    <div class="step">
                        <span class="step-num">5</span>
                        <span><?= t('ty.af_step5') ?></span>
                    </div>
                </div>

                <div class="spam-notice">
                    <p><?= t('ty.af_spam') ?></p>
                </div>

                <?php else: ?>
                <h2><?= t('ty.fa_h2') ?></h2>
                <p><?= t('ty.fa_delivery', ['email' => $emailRaw]) ?></p>

                <div class="steps">
                    <div class="step">
                        <span class="step-num">1</span>
                        <span><?= t('ty.fa_step1') ?></span>
                    </div>
                    <div class="step">
                        <span class="step-num">2</span>
                        <span><?= t('ty.fa_step2') ?></span>
                    </div>
                    <div class="step">
                        <span class="step-num">3</span>
                        <span><?= t('ty.fa_step3') ?></span>
                    </div>
                    <div class="step">
                        <span class="step-num">4</span>
                        <span><?= t('ty.fa_step4') ?></span>
                    </div>
                </div>

                <div class="spam-notice">
                    <p><?= t('ty.fa_spam') ?></p>
                </div>

                <?php endif; ?>

                <div class="thankyou-referral">
                    <h3><?= t('ty.referral_h3') ?></h3>
                    <p><?= t('ty.referral_body') ?></p>
                </div>

                <?php if ($product === 'full_audit'): ?>
                <div class="thankyou-upsell">
                    <h3><?= t('ty.followup_h3') ?></h3>
                    <p><?= t('ty.followup_body') ?></p>
                </div>
                <?php endif; ?>

                <p class="contact-info"><?= t('ty.contact_prefix') ?> <?= obfuscatedEmail(SUPPORT_EMAIL) ?></p>
            </div>
        </div>
    </section>
    </main>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
    <script src="assets/js/obfuscate-email.js?v=1" defer></script>
</body>
</html>
