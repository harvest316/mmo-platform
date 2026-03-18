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
<a href="#main-content" class="skip-link">Skip to main content</a>
<nav class="nav" aria-label="Site navigation" style="background: var(--color-navy);">
    <a href="index.php" class="logo">
        <img src="assets/img/logo.svg" alt="Audit&amp;Fix" class="logo-img">
    </a>
    <a href="index.php" class="nav-cta" style="background: transparent; border: 1px solid rgba(255,255,255,0.35); font-weight: 400; font-size: 0.85rem; padding: 7px 16px;">← Back to site</a>
</nav>
<main id="main-content" class="legal-body">
