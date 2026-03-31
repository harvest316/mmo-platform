<?php
/**
 * Email open tracker — /p/{hash}
 *
 * Serves as a transparent proxy for the poster image in outreach emails.
 * When an email client loads the poster image, this endpoint:
 *   1. Logs the open event to data/videos/{hash}.json
 *   2. Redirects 302 to the real CDN poster URL
 *
 * This gives us open tracking without third-party pixels or Resend's
 * click-tracking domain (which triggers URIBL_INVALUEMENT spam hits).
 *
 * URL format: https://auditandfix.com/p/{hash}
 * Used in email template as the poster img src.
 */

// Extract hash from PATH_INFO or REQUEST_URI
$hash = null;
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if (preg_match('#^/([a-zA-Z0-9]+)$#', $pathInfo, $m)) {
    $hash = $m[1];
}
if (!$hash) {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (preg_match('#/p/([a-zA-Z0-9]+)#', $uri, $m)) {
        $hash = $m[1];
    }
}

if (!$hash) {
    http_response_code(404);
    exit;
}

$safeHash = preg_replace('/[^a-zA-Z0-9]/', '', $hash);
$dataFile = __DIR__ . '/data/videos/' . $safeHash . '.json';

if (!is_file($dataFile)) {
    http_response_code(404);
    exit;
}

$video = json_decode(file_get_contents($dataFile), true);
$posterUrl = $video['poster_url'] ?? null;

if (!$posterUrl || !preg_match('#^https://#', $posterUrl)) {
    http_response_code(404);
    exit;
}

// Log the open event — increment email_open_count and record first/last open time
$now = date('c');
if (!isset($video['email_open_count'])) {
    $video['email_open_count'] = 0;
}
$video['email_open_count']++;
if (empty($video['email_first_opened_at'])) {
    $video['email_first_opened_at'] = $now;
}
$video['email_last_opened_at'] = $now;
$video['email_open_ua'] = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);

file_put_contents($dataFile, json_encode($video, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// Redirect to the real poster image
header('Location: ' . $posterUrl, true, 302);
// Anti-cache so mail clients don't skip the request on re-open
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
exit;
