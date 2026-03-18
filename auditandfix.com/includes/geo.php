<?php
/**
 * IP Geolocation → Country Code
 *
 * Priority:
 *   1. CF-IPCountry header (Cloudflare sets this — zero latency, no API quota)
 *   2. X-Real-IP header → ip-api.com lookup (nginx/direct traffic)
 *   3. REMOTE_ADDR → ip-api.com lookup (fallback for non-proxied requests)
 *   4. Accept-Language header (last resort before default)
 *   5. Default: US
 *
 * Normalisation: GB is mapped to UK (our pricing key).
 */

/**
 * Normalise country codes to our internal pricing keys.
 * GB → UK (ISO uses GB, we store as UK in pricing data).
 */
function normaliseCountryCode(string $code): string {
    $map = ['GB' => 'UK'];
    return $map[$code] ?? $code;
}

function detectCountry(): string {
    // 1. Cloudflare sets CF-IPCountry on every request — fast and authoritative
    $cfCountry = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '';
    if ($cfCountry && $cfCountry !== 'XX') { // XX = Cloudflare "unknown"
        return normaliseCountryCode(strtoupper($cfCountry));
    }

    // 2/3. ip-api.com lookup — use X-Real-IP if available (nginx proxy), else REMOTE_ADDR
    $ip = $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip && $ip !== '127.0.0.1' && $ip !== '::1') {
        $ctx = stream_context_create(['http' => ['timeout' => 3]]);
        $response = @file_get_contents("http://ip-api.com/json/{$ip}?fields=countryCode", false, $ctx);
        if ($response) {
            $data = json_decode($response, true);
            if (!empty($data['countryCode'])) {
                return normaliseCountryCode(strtoupper($data['countryCode']));
            }
        }
    }

    // 4. Accept-Language header (e.g. "en-GB" → "GB")
    $lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    if (preg_match('/[a-z]{2}-([A-Z]{2})/', $lang, $m)) {
        return normaliseCountryCode($m[1]);
    }

    return 'US';
}
