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
</head>
<body>
<?php require_once __DIR__ . '/consent-banner.php'; ?>
<a href="#main-content" class="skip-link">Skip to main content</a>
<?php $headerTheme = 'light'; $headerCta = ['text' => 'Back to site', 'href' => '/']; require_once __DIR__ . '/header.php'; ?>
<main id="main-content" class="legal-body">
