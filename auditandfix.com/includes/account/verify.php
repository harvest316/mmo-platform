<?php
/**
 * Customer Portal: Magic Link Verification
 *
 * Validates token from URL, creates session, redirects to dashboard.
 * No external resources loaded on this page (prevents Referer token leak).
 */

$token = trim($_GET['token'] ?? '');

if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $error = 'invalid';
} else {
    $tokenHash = hash('sha256', $token);
    $db = getCustomerDb();

    $stmt = $db->prepare(
        "SELECT id, email, expires_at
         FROM magic_links
         WHERE token_hash = ?
           AND used_at IS NULL
           AND expires_at > datetime('now')"
    );
    $stmt->execute([$tokenHash]);
    $link = $stmt->fetch();

    if (!$link) {
        $error = 'expired';
    } else {
        // Mark token as used
        $db->prepare("UPDATE magic_links SET used_at = datetime('now') WHERE id = ?")
           ->execute([$link['id']]);

        // Find customer
        $custStmt = $db->prepare("SELECT id FROM customers WHERE email = ? AND deleted_at IS NULL");
        $custStmt->execute([$link['email']]);
        $customer = $custStmt->fetch();

        if (!$customer) {
            $error = 'not_found';
        } else {
            // Create session and redirect immediately (no external resources = no Referer leak)
            createSession((int)$customer['id']);
            header('Location: /account/dashboard');
            exit;
        }
    }
}

// Error states — minimal page, no external resources loaded
?>
<div class="account-card account-card--narrow">
    <?php if (($error ?? '') === 'expired'): ?>
        <div class="verify-error">
            <div class="verify-error__icon">&#9202;</div>
            <h1>This link has expired</h1>
            <p>Login links are valid for 30 minutes. Please request a new one.</p>
            <a href="/account/login" class="btn btn--primary">Get a New Login Link</a>
        </div>
    <?php elseif (($error ?? '') === 'not_found'): ?>
        <div class="verify-error">
            <h1>Account not found</h1>
            <p>We couldn't find an account for this email. Please try logging in again.</p>
            <a href="/account/login" class="btn btn--primary">Try Again</a>
        </div>
    <?php else: ?>
        <div class="verify-error">
            <h1>Invalid login link</h1>
            <p>This link is invalid or has already been used. Please request a new one.</p>
            <a href="/account/login" class="btn btn--primary">Get a New Login Link</a>
        </div>
    <?php endif; ?>
</div>
