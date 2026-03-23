<?php
// Shared header for legal pages
// Usage: require_once __DIR__ . '/legal-header.php';
// Before including, set $pageTitle and optionally $pageDescription
$pageTitle = $pageTitle ?? 'Legal — Audit&Fix';
$pageDescription = $pageDescription ?? 'Legal information for Audit&Fix CRO Audit Reports.';
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($pageLang ?? 'en') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('assets/css/legal.css') ?>">
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="assets/img/favicon-32.png" sizes="32x32" type="image/png">
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
<?php require_once __DIR__ . '/consent-banner.php'; ?>
<a href="#main-content" class="skip-link">Skip to main content</a>
<nav class="nav" aria-label="Site navigation" style="background: var(--color-navy);">
    <a href="index.php" class="logo">
        <img src="assets/img/logo.svg" alt="Audit&amp;Fix" class="logo-img">
    </a>
    <a href="index.php" class="nav-cta" style="background: transparent; border: 1px solid rgba(255,255,255,0.35); font-weight: 400; font-size: 0.85rem; padding: 7px 16px;">← Back to site</a>
</nav>
<main id="main-content" class="legal-body">
