<?php
/**
 * Load pricing from CF Worker with local file fallback
 */

require_once __DIR__ . '/config.php';

function getPricing(): array {
    // Try CF Worker first
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $response = @file_get_contents(CF_WORKER_URL . '/pricing', false, $ctx);
    if ($response) {
        $data = json_decode($response, true);
        if ($data && is_array($data)) {
            return $data;
        }
    }

    // Fallback to local file
    $localPath = __DIR__ . '/../pricing.json';
    if (file_exists($localPath)) {
        $data = json_decode(file_get_contents($localPath), true);
        if ($data && is_array($data)) {
            return $data;
        }
    }

    // Default pricing
    return [
        'US' => ['price' => 29700, 'currency' => 'USD', 'symbol' => '$', 'formatted' => '$297'],
        'AU' => ['price' => 33700, 'currency' => 'AUD', 'symbol' => '$', 'formatted' => 'A$337'],
        'GB' => ['price' => 22700, 'currency' => 'GBP', 'symbol' => '£', 'formatted' => '£227'],
    ];
}

/**
 * 2Step video subscription pricing per country.
 *
 * Tiers: monthly_4 (4 posts/mo), monthly_8 (8 posts/mo), monthly_12 (12 posts/mo), setup (standard).
 * Returns an array keyed by country code (AU/US/UK/CA/NZ).
 */
function get2StepPricing(): array {
    return [
        'AU' => ['monthly_4' => 139, 'monthly_8' => 249, 'monthly_12' => 349, 'setup' => 899,  'currency' => 'AUD', 'symbol' => '$'],
        'US' => ['monthly_4' =>  99, 'monthly_8' => 179, 'monthly_12' => 249, 'setup' => 625,  'currency' => 'USD', 'symbol' => '$'],
        'UK' => ['monthly_4' =>  79, 'monthly_8' => 139, 'monthly_12' => 199, 'setup' => 489,  'currency' => 'GBP', 'symbol' => '£'],
        'CA' => ['monthly_4' => 129, 'monthly_8' => 229, 'monthly_12' => 329, 'setup' => 849,  'currency' => 'CAD', 'symbol' => '$'],
        'NZ' => ['monthly_4' => 149, 'monthly_8' => 279, 'monthly_12' => 389, 'setup' => 989,  'currency' => 'NZD', 'symbol' => '$'],
    ];
}

function getPriceForCountry(string $countryCode, array $pricing): array {
    $code = strtoupper($countryCode);
    // GB and UK are aliases — pricing data uses UK
    if ($code === 'GB') $code = 'UK';
    return $pricing[$code] ?? $pricing['US'] ?? [
        'price' => 29700,
        'currency' => 'USD',
        'symbol' => '$',
        'formatted' => '$297',
    ];
}
