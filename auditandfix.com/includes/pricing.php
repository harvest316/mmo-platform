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
