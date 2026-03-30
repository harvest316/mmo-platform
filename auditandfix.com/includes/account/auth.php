<?php
/**
 * Customer portal authentication helpers.
 *
 * Provides: requireLogin(), isLoggedIn(), getCustomer(), CSRF helpers.
 * Sessions validated against customer_sessions table (server-side).
 */

require_once __DIR__ . '/db.php';

/** Session idle timeout (30 days) and hard max (90 days). */
define('SESSION_IDLE_DAYS', 30);
define('SESSION_HARD_DAYS', 90);

/**
 * Check if the current visitor is logged in.
 * Validates PHP session against server-side customer_sessions table.
 */
function isLoggedIn(): bool {
    if (empty($_SESSION['customer_id']) || empty($_SESSION['session_token'])) {
        return false;
    }

    try {
        $db = getCustomerDb();
        $stmt = $db->prepare(
            "SELECT cs.id, cs.customer_id, cs.expires_at, cs.created_at, c.deleted_at
             FROM customer_sessions cs
             JOIN customers c ON c.id = cs.customer_id
             WHERE cs.token = ?
               AND cs.customer_id = ?
               AND cs.expires_at > datetime('now')"
        );
        $stmt->execute([$_SESSION['session_token'], $_SESSION['customer_id']]);
        $session = $stmt->fetch();

        if (!$session || $session['deleted_at']) {
            destroySession();
            return false;
        }

        // Update last_seen_at (throttled: max once per 5 minutes)
        $db->prepare(
            "UPDATE customer_sessions SET last_seen_at = datetime('now')
             WHERE id = ? AND last_seen_at < datetime('now', '-5 minutes')"
        )->execute([$session['id']]);

        return true;
    } catch (Throwable $e) {
        error_log('isLoggedIn check failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Require login — redirects to /account/login if not authenticated.
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        $returnTo = $_SERVER['REQUEST_URI'] ?? '/account/dashboard';
        header('Location: /account/login?return=' . urlencode($returnTo));
        exit;
    }
}

/**
 * Get the current logged-in customer record.
 * Returns null if not logged in.
 */
function getCustomer(): ?array {
    if (!isLoggedIn()) return null;

    $db = getCustomerDb();
    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$_SESSION['customer_id']]);
    return $stmt->fetch() ?: null;
}

/**
 * Create a new authenticated session after magic link verification.
 */
function createSession(int $customerId): string {
    $db = getCustomerDb();
    $token = bin2hex(random_bytes(32));

    $ip = getClientIp();
    $ipHash = $ip ? hash('sha256', $ip) : null;
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . SESSION_HARD_DAYS . ' days'));

    $db->prepare(
        "INSERT INTO customer_sessions (customer_id, token, expires_at, ip_hash, user_agent)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$customerId, $token, $expiresAt, $ipHash, $ua]);

    // Regenerate PHP session ID to prevent fixation
    session_regenerate_id(true);

    $_SESSION['customer_id'] = $customerId;
    $_SESSION['session_token'] = $token;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    // Update last login
    $db->prepare("UPDATE customers SET last_login_at = datetime('now'), updated_at = datetime('now') WHERE id = ?")
       ->execute([$customerId]);

    return $token;
}

/**
 * Destroy the current session (logout).
 */
function destroySession(): void {
    if (!empty($_SESSION['session_token'])) {
        try {
            $db = getCustomerDb();
            $db->prepare("DELETE FROM customer_sessions WHERE token = ?")
               ->execute([$_SESSION['session_token']]);
        } catch (Throwable $e) {
            error_log('Session cleanup failed: ' . $e->getMessage());
        }
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ── CSRF Protection ─────────────────────────────────────────────────────────

/**
 * Get (or generate) the CSRF token for the current session.
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Render a hidden CSRF input field for forms.
 */
function csrfField(): string {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrfToken()) . '">';
}

/**
 * Validate CSRF token from POST body or X-CSRF-Token header.
 * Exits with 403 on failure.
 */
function validateCsrf(): void {
    $submitted = $_POST['_csrf']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';

    if (!$submitted || !hash_equals(csrfToken(), $submitted)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid security token. Please refresh and try again.']);
        exit;
    }
}

// ── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Get the client IP address (Cloudflare → proxy → direct).
 */
function getClientIp(): string {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '';
    return trim(explode(',', $ip)[0]);
}

/**
 * Probabilistic session GC — clean expired sessions (~1% of requests).
 */
function sessionGc(): void {
    if (mt_rand(1, 100) !== 1) return;

    try {
        $db = getCustomerDb();
        $db->exec("DELETE FROM customer_sessions WHERE expires_at < datetime('now')");
        $db->exec("DELETE FROM magic_links WHERE expires_at < datetime('now') AND created_at < datetime('now', '-1 day')");
    } catch (Throwable $e) {
        error_log('Session GC failed: ' . $e->getMessage());
    }
}
