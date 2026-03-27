<?php
// Shared site footer — included on all pages.
// Requires: config.php (always), obfuscatedEmail(), SUPPORT_EMAIL
// Optional: i18n t() function, $countryCode (for impressum link)
$_t = function(string $key, string $fallback): string {
    return function_exists('t') ? t($key) : $fallback;
};
$_showImpressum = isset($countryCode) && in_array($countryCode, ['DE', 'AT', 'CH'], true);
// Also show impressum if the lang is German (index.php sets $lang)
if (!$_showImpressum && isset($lang) && $lang === 'de') {
    $_showImpressum = true;
}
?>
<footer class="footer">
    <div class="container">
        <a href="/" class="footer-logo"><img src="/assets/img/logo.svg" alt="Audit&amp;Fix" class="footer-logo-img"></a>
        <p class="footer-contact"><?= $_t('footer.questions', 'Questions?') ?> <?= obfuscatedEmail(SUPPORT_EMAIL) ?></p>
        <p class="footer-privacy"><?= $_t('footer.confidentiality', 'All audits are strictly confidential. We never publish, share, or resell client data.') ?></p>
        <nav class="footer-links" aria-label="Site links" style="margin-bottom:12px;">
            <a href="/scan">Conversion Audit</a>
            <a href="/video-reviews/">Review Videos</a>
            <a href="/blog/">Blog</a>
        </nav>
        <nav class="footer-legal" aria-label="Legal">
            <a href="/privacy"><?= $_t('footer.privacy', 'Privacy Policy') ?></a>
            <a href="/terms"><?= $_t('footer.terms', 'Terms of Service') ?></a>
            <a href="/cookies"><?= $_t('footer.cookies', 'Cookie Policy') ?></a>
            <?php if ($_showImpressum): ?>
            <a href="/impressum"><?= $_t('footer.impressum', 'Impressum') ?></a>
            <?php endif; ?>
        </nav>
    </div>
</footer>
