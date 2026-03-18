<?php
/**
 * Audit&Fix Configuration
 */

// Cloudflare Worker
// Use staging worker for sandbox mode, production worker otherwise
define('CF_WORKER_URL',
    (isset($_GET['sandbox']) && $_GET['sandbox'] === '1' && getenv('AUDITANDFIX_WORKER_SANDBOX_URL'))
        ? getenv('AUDITANDFIX_WORKER_SANDBOX_URL')
        : (getenv('AUDITANDFIX_WORKER_URL') ?: ''));
define('CF_WORKER_SECRET', getenv('AUDITANDFIX_WORKER_SECRET') ?: '');

// PayPal — ?sandbox=1 param forces sandbox mode for E2E testing
$_paypalForceSandbox = isset($_GET['sandbox']) && $_GET['sandbox'] === '1';
define('PAYPAL_SANDBOX_FORCED', $_paypalForceSandbox);
$_paypalMode = $_paypalForceSandbox ? 'sandbox' : (getenv('PAYPAL_MODE') ?: 'live');
define('PAYPAL_MODE', $_paypalMode);

// Use sandbox credentials when in sandbox mode, live credentials otherwise
if (PAYPAL_MODE === 'sandbox') {
    define('PAYPAL_CLIENT_ID',     getenv('PAYPAL_SANDBOX_CLIENT_ID')     ?: getenv('PAYPAL_CLIENT_ID') ?: '');
    define('PAYPAL_CLIENT_SECRET', getenv('PAYPAL_SANDBOX_CLIENT_SECRET') ?: getenv('PAYPAL_CLIENT_SECRET') ?: '');
} else {
    define('PAYPAL_CLIENT_ID',     getenv('PAYPAL_CLIENT_ID') ?: '');
    define('PAYPAL_CLIENT_SECRET', getenv('PAYPAL_CLIENT_SECRET') ?: '');
}
define('PAYPAL_API_BASE', PAYPAL_MODE === 'live'
    ? 'https://api-m.paypal.com'
    : 'https://api-m.sandbox.paypal.com');

// TEST_PRICE disabled — was used for live testing at $1, now always null
define('TEST_PRICE', null);

// Contact & Business
define('SUPPORT_EMAIL',    getenv('AUDITANDFIX_SENDER_EMAIL') ?: '');

/**
 * Render an obfuscated email link.
 *
 * Two layers:
 *   1. PHP ROT13-encodes the address before writing it to HTML
 *   2. The address is split at '@' into two data- attributes
 *   JS (obfuscate-email.js) reverses ROT13 and reassembles href + text at runtime.
 * No plain email address appears anywhere in the HTML source.
 *
 * @param string $email  Plain-text email address
 * @param string $label  Link text (defaults to email address itself)
 */
function obfuscatedEmail(string $email, string $label = ''): string {
    if ($email === '') return '';
    $rot   = str_rot13($email);
    $parts = explode('@', $rot, 2);
    $user  = htmlspecialchars($parts[0] ?? $rot);
    $host  = htmlspecialchars($parts[1] ?? '');
    $text  = $label !== '' ? htmlspecialchars($label) : '&#8203;'; // filled by JS
    return sprintf(
        '<a href="#" class="obf-email" data-u="%s" data-h="%s" aria-label="Email us">%s</a>',
        $user, $host, $text
    );
}
define('LEGALS_EMAIL',     getenv('LEGALS_EMAIL')             ?: '');
define('BUSINESS_ADDRESS', getenv('BUSINESS_ADDRESS')         ?: '');
define('OPERATOR_NAME',    getenv('OPERATOR_NAME')            ?: '');
define('BUSINESS_PHONE',   getenv('BUSINESS_PHONE')           ?: '');
define('BUSINESS_ABN',     getenv('BUSINESS_ABN')             ?: '');

/**
 * Returns the deal expiry timestamp (ms) for this visitor.
 * Uses a hashed IP stored in a server-side JSON file.
 * IP-only hash: switching browsers on the same network won't reset the discount.
 * Never stores raw IP — only a one-way hash. No cookies required.
 *
 * @param int $durationMs  Deal duration in milliseconds (default 20 min)
 * @return int  Unix timestamp in ms when the deal expires
 */
function getDealExpiresAt(int $durationMs = 20 * 60 * 1000): int {
    // Build a privacy-safe fingerprint: hash of IP only
    // (UA excluded — trivially changed to bypass the discount)
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']   // Cloudflare real IP
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']    // proxy
        ?? $_SERVER['REMOTE_ADDR']             // direct
        ?? '';
    // Take only the first IP if comma-separated
    $ip = trim(explode(',', $ip)[0]);
    // HMAC with a salt to prevent rainbow-table attacks on plain sha256(IP)
    $salt = getenv('DEAL_HASH_SALT') ?: 'af-deal-default-salt-change-in-prod';
    $fingerprint = hash_hmac('sha256', $ip, $salt);

    // Storage: a JSON file per fingerprint under data/af_deals/ (persistent across requests)
    // /tmp is cleared on process restart on most shared hosts — data/ persists
    $storeDir  = __DIR__ . '/../data/af_deals';
    $storeFile = $storeDir . '/' . $fingerprint . '.json';

    $nowMs = (int)(microtime(true) * 1000);

    // Try to read existing record
    if (is_file($storeFile)) {
        $data = json_decode(file_get_contents($storeFile), true);
        if (isset($data['expires_at']) && $data['expires_at'] > $nowMs) {
            return (int)$data['expires_at'];
        }
        // Expired — fall through to create a new record
    }

    // Create storage dir if needed
    if (!is_dir($storeDir)) {
        mkdir($storeDir, 0700, true);
    }

    // Periodically clean up expired fingerprint files (~1% of requests)
    // Prevents data/af_deals/ accumulating thousands of stale files
    if (mt_rand(1, 100) === 1 && is_dir($storeDir)) {
        foreach (glob($storeDir . '/*.json') ?: [] as $f) {
            $d = json_decode(@file_get_contents($f), true);
            if (!isset($d['expires_at']) || $d['expires_at'] <= $nowMs) {
                @unlink($f);
            }
        }
    }

    // Write new expiry
    $expiresAt = $nowMs + $durationMs;
    file_put_contents(
        $storeFile,
        json_encode(['expires_at' => $expiresAt]),
        LOCK_EX
    );

    return $expiresAt;
}

// Base62 alphabet — URL-safe, case-sensitive, no look-alike chars omitted
// (full set intentional: short slugs are opaque — no need for readability)
const BASE62_ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

/**
 * Encode a positive integer as a base62 string.
 * Used to make /a/{slug} and /v/{slug} URLs opaque (can't enumerate by incrementing).
 */
function base62_encode(int $n): string {
    if ($n === 0) return '0';
    $alpha  = BASE62_ALPHABET;
    $result = '';
    while ($n > 0) {
        $result = $alpha[$n % 62] . $result;
        $n      = intdiv($n, 62);
    }
    return $result;
}

/**
 * Decode a base62 string back to an integer.
 * Returns 0 on invalid input (slug contains chars outside BASE62_ALPHABET).
 */
function base62_decode(string $s): int {
    $alpha = BASE62_ALPHABET;
    $n     = 0;
    $len   = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $pos = strpos($alpha, $s[$i]);
        if ($pos === false) return 0;
        $n = $n * 62 + $pos;
    }
    return $n;
}

/**
 * Return a cache-busted asset URL using filemtime().
 * Example: asset_url('assets/css/style.css') → 'assets/css/style.css?v=1741234567'
 *
 * @param string $path  Path relative to the site root (no leading slash required)
 */
function asset_url(string $path): string {
    $abs = __DIR__ . '/../' . ltrim($path, '/');
    $mtime = file_exists($abs) ? filemtime($abs) : 0;
    return $path . '?v=' . $mtime;
}
