<?php
/**
 * Customer Portal: Dashboard (Phase 1 — minimal)
 *
 * Shows welcome message + account info. Full dashboard with products,
 * reports, videos, and cross-sell ships in Phase 2.
 */

$customer = getCustomer();
if (!$customer) {
    header('Location: /account/login');
    exit;
}
?>

<div class="account-dashboard">
    <div class="account-dashboard__header">
        <h1>Welcome<?= $customer['display_name'] ? ', ' . htmlspecialchars($customer['display_name']) : '' ?></h1>
        <p class="text-muted"><?= htmlspecialchars($customer['email']) ?></p>
    </div>

    <div class="account-dashboard__grid">
        <div class="account-card">
            <h2>Your Account</h2>
            <p>Your customer portal is being set up. Soon you'll be able to:</p>
            <ul class="account-features">
                <li>View and download your CRO audit reports</li>
                <li>Manage your video review subscriptions</li>
                <li>Download your review videos</li>
                <li>Access exclusive offers</li>
            </ul>
            <p class="text-muted" style="margin-top:1rem;">
                We'll email you when new features are available.
            </p>
        </div>
    </div>

    <div class="account-dashboard__actions">
        <a href="/scan" class="btn btn--outline">Run a Free Audit</a>
        <a href="/video-reviews/" class="btn btn--outline">Video Reviews</a>
        <a href="/account/logout" class="btn btn--ghost">Log Out</a>
    </div>
</div>
