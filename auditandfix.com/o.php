<?php
/**
 * Short-URL handler for outreach order links.
 *
 * URL format:  /o/{site_id}
 * Example:     auditandfix.com/o/17757
 *
 * Looks up prefill data from data/orders/{site_id}.json written by 333Method
 * when generating the payment reply, then redirects to index.php with the
 * prospect's domain, country and conversation_id pre-populated.
 *
 * If the data file doesn't exist (expired, invalid ID, etc.) we just redirect
 * to the homepage without prefill — the visitor still lands on the order page.
 */

// Extract site_id from PATH_INFO or REQUEST_URI
$siteId = null;

// Works with: /o/123  (mod_rewrite sets PATH_INFO or we parse REQUEST_URI)
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if (preg_match('#^/(\d+)$#', $pathInfo, $m)) {
    $siteId = (int) $m[1];
}

if (!$siteId) {
    // Fallback: parse REQUEST_URI directly (handles most server configs)
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (preg_match('#/o/(\d+)#', $uri, $m)) {
        $siteId = (int) $m[1];
    }
}

if (!$siteId) {
    header('Location: /');
    exit;
}

// Load prefill data written by 333Method
$dataFile = __DIR__ . '/data/orders/' . $siteId . '.json';
$prefill = [];
if (file_exists($dataFile)) {
    $raw = @file_get_contents($dataFile);
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $prefill = $decoded;
        }
    }
}

// Build redirect URL to index.php with prefill params
$params = [];

if (!empty($prefill['domain'])) {
    $params['domain'] = $prefill['domain'];
}
if (!empty($prefill['country'])) {
    $params['country'] = $prefill['country'];
}
if (!empty($prefill['email'])) {
    $params['email'] = $prefill['email'];
}
if (!empty($prefill['cid'])) {
    $params['cid'] = (int) $prefill['cid'];
}
// Jump straight to the order form
$params['ref'] = 'sms';

$query = $params ? '?' . http_build_query($params) : '';
header('Location: /' . $query . '#order');
exit;
