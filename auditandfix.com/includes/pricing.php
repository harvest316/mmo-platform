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
        'AU' => ['monthly_4' => 139, 'monthly_8' => 249, 'monthly_12' => 349, 'setup' => 899,  'currency' => 'AUD', 'symbol' => 'A$'],
        'US' => ['monthly_4' =>  99, 'monthly_8' => 179, 'monthly_12' => 249, 'setup' => 625,  'currency' => 'USD', 'symbol' => '$'],
        'UK' => ['monthly_4' =>  79, 'monthly_8' => 139, 'monthly_12' => 199, 'setup' => 489,  'currency' => 'GBP', 'symbol' => '£'],
        'CA' => ['monthly_4' => 129, 'monthly_8' => 229, 'monthly_12' => 329, 'setup' => 849,  'currency' => 'CAD', 'symbol' => 'CA$'],
        'NZ' => ['monthly_4' => 149, 'monthly_8' => 279, 'monthly_12' => 389, 'setup' => 989,  'currency' => 'NZD', 'symbol' => 'NZ$'],
    ];
}

/**
 * Look up 2Step video pricing for a given country code.
 * Falls back to US pricing for unknown countries.
 */
function get2StepPriceForCountry(string $countryCode): array {
    $table = get2StepPricing();
    $code = strtoupper($countryCode);
    if ($code === 'GB') $code = 'UK';
    return $table[$code] ?? $table['US'];
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

// ── Multi-product pricing ────────────────────────────────────────────────

/** Valid product slugs */
const VALID_PRODUCTS = ['full_audit', 'quick_fixes', 'audit_fix'];

/** Product display names for PayPal descriptions */
const PRODUCT_NAMES = [
    'full_audit'   => 'CRO Audit Report',
    'quick_fixes'  => 'Quick Fixes Report',
    'audit_fix'    => 'CRO Audit + Implementation',
];

/**
 * Multi-product pricing per country (amounts in cents).
 * full_audit prices come from getPricing() / CF Worker.
 * quick_fixes and audit_fix are fixed ratios.
 */
function getProductPricing(): array {
    return [
        'US' => [
            'full_audit'  => ['price' => 29700, 'currency' => 'USD', 'symbol' => '$',  'formatted' => '$297'],
            'quick_fixes' => ['price' =>  6700, 'currency' => 'USD', 'symbol' => '$',  'formatted' => '$67'],
            'audit_fix'   => ['price' => 49700, 'currency' => 'USD', 'symbol' => '$',  'formatted' => '$497'],
        ],
        'AU' => [
            'full_audit'  => ['price' => 33700, 'currency' => 'AUD', 'symbol' => '$',  'formatted' => 'A$337'],
            'quick_fixes' => ['price' =>  9700, 'currency' => 'AUD', 'symbol' => '$',  'formatted' => 'A$97'],
            'audit_fix'   => ['price' => 62500, 'currency' => 'AUD', 'symbol' => '$',  'formatted' => 'A$625'],
        ],
        'GB' => [
            'full_audit'  => ['price' => 15900, 'currency' => 'GBP', 'symbol' => '£',  'formatted' => '£159'],
            'quick_fixes' => ['price' =>  4700, 'currency' => 'GBP', 'symbol' => '£',  'formatted' => '£47'],
            'audit_fix'   => ['price' => 35000, 'currency' => 'GBP', 'symbol' => '£',  'formatted' => '£350'],
        ],
    ];
}

/**
 * Look up pricing for a specific product and country.
 * Falls back to US pricing for unknown countries.
 */
function getProductPriceForCountry(string $countryCode, string $product = 'full_audit'): array {
    $code = strtoupper($countryCode);
    if ($code === 'UK') $code = 'GB';
    if ($code === 'NZ') $code = 'AU'; // NZ uses AUD pricing

    $allPricing = getProductPricing();
    $countryPricing = $allPricing[$code] ?? $allPricing['US'];

    if (!in_array($product, VALID_PRODUCTS, true)) {
        $product = 'full_audit';
    }

    return $countryPricing[$product];
}
