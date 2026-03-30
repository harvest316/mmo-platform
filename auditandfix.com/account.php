<?php
/**
 * Customer Portal Router
 *
 * Dispatches /account/* URLs to the appropriate page include.
 * All pages share the site header/footer and require auth (except login/verify).
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/account/db.php';
require_once __DIR__ . '/includes/account/auth.php';

// Run session GC probabilistically
sessionGc();

$page = $_GET['page'] ?? 'dashboard';

// Pages that don't require auth
$publicPages = ['login', 'verify', 'logout'];

// Validate page name (prevent directory traversal)
if (!preg_match('/^[a-z_]+$/', $page)) {
    $page = 'dashboard';
}

// Auth check for protected pages
if (!in_array($page, $publicPages, true)) {
    requireLogin();
}

// Resolve page include file
$pageFile = __DIR__ . '/includes/account/' . $page . '.php';
if (!is_file($pageFile)) {
    $page = 'dashboard';
    $pageFile = __DIR__ . '/includes/account/dashboard.php';
    if (!is_file($pageFile)) {
        // Phase 1: dashboard not built yet — redirect to login if not authenticated
        if (isLoggedIn()) {
            $page = 'login';
            $pageFile = __DIR__ . '/includes/account/login.php';
        }
    }
}

// Set page-specific variables for header
$headerSolidBg = true;
$hideLangSelector = true;
$pageTitle = match($page) {
    'login'         => 'Log In',
    'verify'        => 'Verifying...',
    'logout'        => 'Logging Out',
    'dashboard'     => 'My Account',
    'billing'       => 'Billing',
    'reports'       => 'Audit Reports',
    'videos'        => 'Video Library',
    'subscriptions' => 'Subscriptions',
    default         => 'My Account',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?> — Audit&amp;Fix</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('assets/css/account.css') ?>">
    <link rel="icon" href="/assets/img/favicon.ico" type="image/x-icon">
</head>
<body class="account-page account-page--<?= htmlspecialchars($page) ?>">

<?php include __DIR__ . '/includes/header.php'; ?>

<main class="account-main">
    <div class="account-container">
        <?php include $pageFile; ?>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php include __DIR__ . '/includes/consent-banner.php'; ?>

<script src="<?= asset_url('assets/js/obfuscate-email.js') ?>" defer></script>
</body>
</html>
