<?php
/**
 * Audit&Fix API Endpoint
 *
 * Handles PayPal order creation/capture and forwards purchases to CF Worker.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/pricing.php';
require_once __DIR__ . '/includes/geo.php';

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

try {
    switch ($action) {
        case 'create-order':
            createOrder($input);
            break;
        case 'capture-payment':
            capturePayment($input);
            break;
        case 'store-prefill':
            storePrefill($input);
            break;
        case 'store-video':
            storeVideo($input);
            break;
        case 'video-viewed':
            videoViewed($input);
            break;
        case 'get-video-views':
            getVideoViews($input);
            break;
        case 'free-scan':
            freeScan($input);
            break;
        case 'save-email':
            saveEmail($input);
            break;
        case 'request-demo':
            requestDemo($input);
            break;
        case 'demo-status':
            demoStatus($input);
            break;
        case 'demo-email':
            demoEmail($input);
            break;
        case 'verify-demo':
            verifyDemo($input);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function isSandbox(array $input): bool {
    return !empty($input['sandbox']) || PAYPAL_MODE === 'sandbox';
}

function getApiBase(array $input): string {
    return isSandbox($input) ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
}

function getPayPalAccessToken(string $apiBase = ''): string {
    if (!$apiBase) $apiBase = PAYPAL_API_BASE;
    $auth = base64_encode(PAYPAL_CLIENT_ID . ':' . PAYPAL_CLIENT_SECRET);

    $ch = curl_init($apiBase . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . $auth,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('PayPal auth failed: HTTP ' . $httpCode . ' — ' . $response);
    }

    $data = json_decode($response, true);
    return $data['access_token'];
}

function createOrder(array $input): void {
    // Validate
    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $url = filter_var($input['url'] ?? '', FILTER_VALIDATE_URL);
    $currency = strtoupper($input['currency'] ?? 'USD');

    if (!$email || !$url) {
        http_response_code(400);
        echo json_encode(['error' => 'Valid email and URL required']);
        return;
    }

    // Get pricing — product determines which price table to use
    $product = $input['product'] ?? 'full_audit';
    if (!in_array($product, VALID_PRODUCTS, true)) {
        $product = 'full_audit';
    }
    $countryCode = $input['country_code'] ?? detectCountry();
    $priceData = getProductPriceForCountry($countryCode, $product);

    // Price always comes from server-side pricing — never from POST body
    $amount = $priceData['price'] / 100;

    $apiBase = getApiBase($input);
    $accessToken = getPayPalAccessToken($apiBase);

    $orderData = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'description' => (PRODUCT_NAMES[$product] ?? 'CRO Audit Report') . ' - ' . parse_url($url, PHP_URL_HOST),
            'amount' => [
                'currency_code' => $priceData['currency'],
                'value' => number_format($amount, 2, '.', ''),
            ],
        ]],
    ];

    $ch = curl_init($apiBase . '/v2/checkout/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($orderData),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        http_response_code(500);
        echo json_encode(['error' => 'PayPal order creation failed']);
        return;
    }

    $order = json_decode($response, true);
    echo json_encode(['id' => $order['id']]);
}

function capturePayment(array $input): void {
    $orderId = $input['order_id'] ?? '';
    if (!$orderId) {
        http_response_code(400);
        echo json_encode(['error' => 'order_id required']);
        return;
    }

    $apiBase = getApiBase($input);
    $accessToken = getPayPalAccessToken($apiBase);

    // Capture payment
    $ch = curl_init($apiBase . '/v2/checkout/orders/' . $orderId . '/capture');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '{}',
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        http_response_code(500);
        echo json_encode(['error' => 'Payment capture failed']);
        return;
    }

    $capture = json_decode($response, true);
    $captureData = $capture['purchase_units'][0]['payments']['captures'][0] ?? [];

    // Build purchase data for CF Worker
    $product = $input['product'] ?? 'full_audit';
    if (!in_array($product, VALID_PRODUCTS, true)) {
        $product = 'full_audit';
    }
    $purchaseData = [
        'product' => $product,
        'email' => $input['email'] ?? '',
        'landing_page_url' => $input['url'] ?? '',
        'phone' => $input['phone'] ?? null,
        'paypal_order_id' => $orderId,
        'paypal_payer_id' => $capture['payer']['payer_id'] ?? null,
        'paypal_capture_id' => $captureData['id'] ?? null,
        'amount' => intval(floatval($captureData['amount']['value'] ?? 0) * 100),
        'currency' => $captureData['amount']['currency_code'] ?? 'USD',
        'amount_usd' => $input['amount_usd'] ?? intval(floatval($captureData['amount']['value'] ?? 0) * 100),
        'country_code' => $input['country_code'] ?? null,
        'lang' => $input['lang'] ?? 'en',
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ];

    // Forward to CF Worker (skip in sandbox mode — don't pollute real purchase queue)
    if (isSandbox($input)) {
        echo json_encode([
            'success' => true,
            'sandbox' => true,
            'order_id' => $orderId,
            'capture_id' => $captureData['id'] ?? null,
        ]);
        return;
    }

    $ch = curl_init(CF_WORKER_URL . '/purchases');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($purchaseData),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Auth-Secret: ' . CF_WORKER_SECRET,
        ],
        CURLOPT_TIMEOUT => 10,
    ]);

    $workerResponse = curl_exec($ch);
    curl_close($ch);

    // GA4 Measurement Protocol — purchase
    $gclid = isset($input['gclid']) ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$input['gclid']) : null;
    $clientId = ga4ClientId();
    ga4Event('purchase', [
        'transaction_id' => $captureData['id'] ?? $orderId,
        'value'          => floatval($captureData['amount']['value'] ?? 0),
        'currency'       => $captureData['amount']['currency_code'] ?? 'USD',
        'gclid'          => $gclid,
        'items'          => [[
            'item_id'   => $product,
            'item_name' => $product,
            'quantity'  => 1,
            'price'     => floatval($captureData['amount']['value'] ?? 0),
        ]],
    ], $clientId);

    echo json_encode([
        'success' => true,
        'order_id' => $orderId,
        'capture_id' => $captureData['id'] ?? null,
    ]);
}

function storePrefill(array $input): void {
    // Authenticate — must match CF_WORKER_SECRET
    $secret = $_SERVER['HTTP_X_AUTH_SECRET'] ?? '';
    if (!$secret || !hash_equals(CF_WORKER_SECRET, $secret)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        return;
    }

    $siteId = isset($input['site_id']) ? (int) $input['site_id'] : 0;
    if ($siteId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'site_id required']);
        return;
    }

    // Sanitise fields
    $domain  = isset($input['domain'])  ? preg_replace('/[^a-zA-Z0-9.\-]/', '', (string) $input['domain']) : null;
    $country = isset($input['country']) ? preg_replace('/[^A-Z]/', '', strtoupper((string) $input['country'])) : null;
    $email   = isset($input['email'])   ? (filter_var($input['email'], FILTER_VALIDATE_EMAIL) ?: null) : null;
    $cid     = isset($input['cid'])     ? (int) $input['cid'] : null;
    $score   = isset($input['score'])   ? (float) $input['score'] : null;
    $grade   = isset($input['grade'])   ? preg_replace('/[^A-Fa-f+\-]/', '', (string) $input['grade']) : null;

    $data = array_filter([
        'domain'  => $domain,
        'country' => $country,
        'email'   => $email,
        'cid'     => $cid,
        'score'   => $score,
        'grade'   => $grade,
    ], fn($v) => $v !== null && $v !== '');

    $ordersDir = __DIR__ . '/data/orders';
    if (!is_dir($ordersDir)) {
        mkdir($ordersDir, 0755, true);
    }

    file_put_contents($ordersDir . '/' . $siteId . '.json', json_encode($data));

    echo json_encode(['success' => true]);
}

/**
 * Proxy a free website scan request to the Cloudflare Worker scoring API.
 * The Worker runs at the edge — no local server required, works from Hostinger.
 *
 * Required env vars (set in .htaccess or Hostinger control panel):
 *   AUDITANDFIX_WORKER_URL    e.g. https://auditandfix-api.auditandfix.workers.dev
 *   (No separate FREE_SCORE_API_KEY needed — Worker rate-limits by CF-Connecting-IP)
 */
function freeScan(array $input): void {
    $workerUrl = rtrim(getenv('AUDITANDFIX_WORKER_URL') ?: '', '/');

    if (!$workerUrl) {
        http_response_code(503);
        echo json_encode(['error' => 'Scoring service not configured']);
        return;
    }

    // Pass client IP as fallback (Worker uses CF-Connecting-IP first)
    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR']
        ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]
        : ($_SERVER['REMOTE_ADDR'] ?? '');

    $payload = [
        'url'          => $input['url'] ?? '',
        'utm_source'   => $input['utm_source']   ?? null,
        'utm_medium'   => $input['utm_medium']   ?? null,
        'utm_campaign' => $input['utm_campaign'] ?? null,
        'ref'          => $input['ref']          ?? null,
    ];

    $ch = curl_init($workerUrl . '/scan');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Forwarded-For: ' . $clientIp,
        ],
        CURLOPT_TIMEOUT        => 25,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    http_response_code($httpCode ?: 500);
    echo $response ?: json_encode(['error' => 'Scoring service unavailable']);
}

/**
 * Save an email address for a free scan (email gate).
 *
 * Stores the following fields in the local scan_emails SQLite table:
 *   scan_id, email, marketing_optin, optin_timestamp, ip_hash, created_at
 *
 * Also proxies to the Cloudflare Worker POST /scan/:id/email endpoint
 * (fire-and-forget — client response is not blocked on CF Worker success).
 */
function saveEmail(array $input): void {
    $scanId         = trim($input['scan_id'] ?? '');
    $email          = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '';
    $marketingOptin = !empty($input['marketing_optin']) ? 1 : 0;
    $optinTs        = $marketingOptin && !empty($input['optin_timestamp'])
        ? preg_replace('/[^0-9T:Z.\-]/', '', (string)$input['optin_timestamp'])
        : null;

    // Scan context fields (optional — old scanner.js versions won't send these)
    $score        = isset($input['score']) ? (int)$input['score'] : null;
    $grade        = isset($input['grade']) ? substr(preg_replace('/[^A-Za-z+\-]/', '', (string)$input['grade']), 0, 3) : null;
    $domain       = isset($input['domain']) ? substr(preg_replace('/[^a-zA-Z0-9.\-]/', '', (string)$input['domain']), 0, 253) : null;
    $issuesCount  = isset($input['issues_count']) ? (int)$input['issues_count'] : null;
    // Cap factor_summary at 4 KB — it's a flat key→int object, should never exceed ~300 bytes in practice
    $factorSummaryRaw = $input['factor_summary'] ?? null;
    $factorSummary = null;
    if (is_string($factorSummaryRaw) && strlen($factorSummaryRaw) <= 4096) {
        json_decode($factorSummaryRaw); // validate
        $factorSummary = json_last_error() === JSON_ERROR_NONE ? $factorSummaryRaw : null;
    } elseif (is_array($factorSummaryRaw)) {
        $encoded = json_encode($factorSummaryRaw);
        $factorSummary = $encoded !== false && strlen($encoded) <= 4096 ? $encoded : null;
    }

    // ── Local SQLite storage ──────────────────────────────────────────────
    $dbPath = getenv('AUDITANDFIX_SITE_PATH')
        ? rtrim(getenv('AUDITANDFIX_SITE_PATH'), '/') . '/data/scan_emails.sqlite'
        : __DIR__ . '/data/scan_emails.sqlite';

    try {
        $dataDir = dirname($dbPath);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $db->exec("CREATE TABLE IF NOT EXISTS scan_emails (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            scan_id         TEXT    NOT NULL,
            email           TEXT    NOT NULL,
            marketing_optin INTEGER NOT NULL DEFAULT 0,
            optin_timestamp TEXT,
            ip_hash         TEXT,
            created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
            score           INTEGER,
            grade           TEXT,
            domain          TEXT,
            issues_count    INTEGER,
            factor_summary  TEXT
        )");

        // Self-migrate pre-existing tables that lack the new columns.
        // ALTER TABLE fails if the column exists — catch and ignore each individually.
        foreach ([
            'score          INTEGER',
            'grade          TEXT',
            'domain         TEXT',
            'issues_count   INTEGER',
            'factor_summary TEXT',
        ] as $colDef) {
            try { $db->exec("ALTER TABLE scan_emails ADD COLUMN $colDef"); } catch (Throwable $ignored) {}
        }

        // One-way IP hash for GDPR-safe attribution (never store raw IP)
        $rawIp  = trim(explode(',', $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR'] ?? '')[0]);
        $ipHash = $rawIp ? hash('sha256', $rawIp) : null;

        $stmt = $db->prepare(
            "INSERT INTO scan_emails
                (scan_id, email, marketing_optin, optin_timestamp, ip_hash,
                 score, grade, domain, issues_count, factor_summary)
             VALUES
                (:scan_id, :email, :optin, :optin_ts, :ip_hash,
                 :score, :grade, :domain, :issues_count, :factor_summary)"
        );
        $stmt->execute([
            ':scan_id'        => $scanId,
            ':email'          => $email,
            ':optin'          => $marketingOptin,
            ':optin_ts'       => $optinTs,
            ':ip_hash'        => $ipHash,
            ':score'          => $score,
            ':grade'          => $grade,
            ':domain'         => $domain,
            ':issues_count'   => $issuesCount,
            ':factor_summary' => $factorSummary,
        ]);
    } catch (Throwable $e) {
        // Log but don't surface to client — local DB failure must not block the user
        error_log('saveEmail DB error: ' . $e->getMessage());
    }

    // ── CF Worker proxy (fire-and-forget) ─────────────────────────────────
    $workerUrl = rtrim(getenv('AUDITANDFIX_WORKER_URL') ?: '', '/');
    if ($workerUrl && $scanId) {
        $ch = curl_init($workerUrl . '/scan/' . urlencode($scanId) . '/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['email' => $email]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    // GA4 Measurement Protocol — generate_lead (only if user consented)
    $analyticsConsent = !empty($input['analytics_consent']);
    $gclid = isset($input['gclid']) ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$input['gclid']) : null;
    if ($email && $analyticsConsent && !isSandboxEnv()) {
        $clientId = ga4ClientId();
        ga4Event('generate_lead', [
            'email_hash' => hash('sha256', strtolower(trim($email))),
            'score'      => $score,
            'grade'      => $grade,
            'domain'     => $domain,
            'gclid'      => $gclid,
        ], $clientId);
    }

    // Always return success — never block the factor breakdown on email persistence
    echo json_encode(['success' => true]);
}

// ─── 2Step Video Endpoints ──────────────────────────────────────────────────

/**
 * Store video metadata for the /v/{hash} sales page.
 * Called by 2Step pipeline after video creation.
 * Requires X-Auth-Secret header matching CF_WORKER_SECRET.
 */
function storeVideo(array $input): void {
    $secret = $_SERVER['HTTP_X_AUTH_SECRET'] ?? '';
    if (!$secret || !hash_equals(CF_WORKER_SECRET, $secret)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        return;
    }

    $hash = preg_replace('/[^a-zA-Z0-9]/', '', (string)($input['hash'] ?? ''));
    if (!$hash) {
        http_response_code(400);
        echo json_encode(['error' => 'hash required']);
        return;
    }

    $videosDir = __DIR__ . '/data/videos';
    if (!is_dir($videosDir)) {
        mkdir($videosDir, 0755, true);
    }

    $data = [
        'hash'             => $hash,
        'video_url'        => $input['video_url'] ?? null,
        'business_name'    => $input['business_name'] ?? null,
        'domain'           => isset($input['domain']) ? preg_replace('/[^a-zA-Z0-9.\-]/', '', $input['domain']) : null,
        'review_count'     => isset($input['review_count']) ? (int)$input['review_count'] : null,
        'niche'            => $input['niche'] ?? null,
        'niche_tier'       => $input['niche_tier'] ?? 'standard',
        'country_code'     => isset($input['country_code']) ? preg_replace('/[^A-Z]/', '', strtoupper($input['country_code'])) : null,
        'created_at'       => date('c'),
        'views'            => [],
    ];

    file_put_contents($videosDir . '/' . $hash . '.json', json_encode($data, JSON_PRETTY_PRINT));

    echo json_encode(['success' => true, 'page_url' => '/v/' . $hash]);
}

/**
 * Record a video page view. Called by v.php on page load (beacon).
 * No auth required — this is a public endpoint called by the visitor's browser.
 */
function videoViewed(array $input): void {
    $hash = preg_replace('/[^a-zA-Z0-9]/', '', (string)($input['hash'] ?? $_GET['hash'] ?? ''));
    if (!$hash) {
        http_response_code(400);
        echo json_encode(['error' => 'hash required']);
        return;
    }

    $filePath = __DIR__ . '/data/videos/' . $hash . '.json';
    if (!is_file($filePath)) {
        http_response_code(404);
        echo json_encode(['error' => 'video not found']);
        return;
    }

    $data = json_decode(file_get_contents($filePath), true);
    if (!$data) {
        http_response_code(500);
        echo json_encode(['error' => 'corrupt video data']);
        return;
    }

    $ip = trim(explode(',', $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR'] ?? '')[0]);

    $data['views'][] = [
        'viewed_at'   => date('c'),
        'user_agent'  => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        'ip_country'  => $_SERVER['HTTP_CF_IPCOUNTRY'] ?? null,
    ];

    file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT));

    echo json_encode(['success' => true]);
}

/**
 * Get video view data. Called by 2Step's sync_video_views orchestrator batch.
 * Requires X-Auth-Secret.
 */
function getVideoViews(array $input): void {
    $secret = $_SERVER['HTTP_X_AUTH_SECRET'] ?? '';
    if (!$secret || !hash_equals(CF_WORKER_SECRET, $secret)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        return;
    }

    $videosDir = __DIR__ . '/data/videos';
    if (!is_dir($videosDir)) {
        echo json_encode(['videos' => []]);
        return;
    }

    $result = [];
    foreach (glob($videosDir . '/*.json') as $file) {
        $data = json_decode(file_get_contents($file), true);
        if (!$data) continue;
        $result[] = [
            'hash'       => $data['hash'] ?? basename($file, '.json'),
            'view_count' => count($data['views'] ?? []),
            'last_view'  => !empty($data['views']) ? end($data['views'])['viewed_at'] : null,
        ];
    }

    echo json_encode(['videos' => $result]);
}

// ─── 2Step Video-on-Demand Demo Proxies ─────────────────────────────────────

define('VALID_DEMO_NICHES', [
    'pest_control', 'plumber', 'house_cleaning', 'dentist', 'electrician',
    'roofing', 'hvac', 'real_estate', 'chiropractor', 'personal_injury_lawyer',
    'pool_installer', 'dog_trainer', 'med_spa', 'other',
]);

/**
 * Proxy a video demo request to the CF Worker POST /video-demo.
 * Thin proxy — all business logic lives in the Worker.
 */
function requestDemo(array $input): void {
    $workerUrl = rtrim(getenv('AUDITANDFIX_WORKER_URL') ?: '', '/');
    if (!$workerUrl) {
        http_response_code(503);
        echo json_encode(['error' => 'Demo service not configured']);
        return;
    }

    // Sanitise inputs
    $businessName = substr(trim($input['business_name'] ?? ''), 0, 100);
    $placeId      = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)($input['place_id'] ?? ''));
    $niche        = in_array($input['niche'] ?? '', VALID_DEMO_NICHES, true)
        ? $input['niche']
        : null;
    $city         = substr(trim($input['city'] ?? ''), 0, 100);
    $countryCode  = preg_replace('/[^A-Z]/', '', strtoupper((string)($input['country_code'] ?? '')));
    if (strlen($countryCode) !== 2) $countryCode = '';

    if (!$businessName || !$placeId || !$niche) {
        http_response_code(400);
        echo json_encode(['error' => 'business_name, place_id, and niche are required']);
        return;
    }

    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR']
        ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]
        : ($_SERVER['REMOTE_ADDR'] ?? '');

    $payload = array_filter([
        'business_name' => $businessName,
        'place_id'      => $placeId,
        'niche'         => $niche,
        'city'          => $city ?: null,
        'country_code'  => $countryCode ?: null,
        'utm_source'    => $input['utm_source']   ?? null,
        'utm_medium'    => $input['utm_medium']    ?? null,
        'utm_campaign'  => $input['utm_campaign'] ?? null,
    ], fn($v) => $v !== null);

    $ch = curl_init($workerUrl . '/video-demo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Forwarded-For: ' . $clientIp,
        ],
        CURLOPT_TIMEOUT        => 25,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    http_response_code($httpCode ?: 503);
    echo $response ?: json_encode(['error' => 'Demo service unavailable']);
}

/**
 * Proxy a demo status check to the CF Worker GET /video-demo/{demo_id}.
 * api.php is POST-only so demo_id arrives in the POST body.
 */
function demoStatus(array $input): void {
    $workerUrl = rtrim(getenv('AUDITANDFIX_WORKER_URL') ?: '', '/');
    if (!$workerUrl) {
        http_response_code(503);
        echo json_encode(['error' => 'Demo service not configured']);
        return;
    }

    $demoId = (string)($input['demo_id'] ?? '');
    if (!preg_match('/^[a-f0-9\-]+$/', $demoId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid demo_id']);
        return;
    }

    $ch = curl_init($workerUrl . '/video-demo/' . urlencode($demoId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    http_response_code($httpCode ?: 503);
    echo $response ?: json_encode(['error' => 'Demo service unavailable']);
}

// Disposable email domains to reject at the PHP layer (CF Worker has its own blocklist too)
const DISPOSABLE_EMAIL_DOMAINS = [
    'mailinator.com','guerrillamail.com','guerrillamail.info','guerrillamail.net',
    'guerrillamail.org','guerrillamailblock.com','grr.la','sharklasers.com',
    'spam4.me','yopmail.com','yopmail.fr','cool.fr.nf','jetable.fr.nf',
    'nospam.ze.tc','nomail.xl.cx','mega.zik.dj','speed.1s.fr','courriel.fr.nf',
    'moncourrier.fr.nf','monemail.fr.nf','monmail.fr.nf','temp-mail.org',
    'throwaway.email','trashmail.com','trashmail.me','trashmail.net',
    'dispostable.com','discardmail.com','discard.email','tempinbox.com',
    'spamgourmet.com','maildrop.cc','mailnull.com','spamgourmet.net',
];

/**
 * Collect a demo viewer's email, generate a verification token, and send
 * a verification email via Resend. Does NOT call the CF Worker — the Worker
 * is only notified after the user clicks the verification link (verifyDemo).
 */
function demoEmail(array $input): void {
    $demoId = (string)($input['demo_id'] ?? '');
    $email  = strtolower(trim(filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: ''));

    if (!preg_match('/^[a-f0-9\-]+$/', $demoId) || !$email) {
        http_response_code(400);
        echo json_encode(['error' => 'Valid demo_id and email required']);
        return;
    }

    $emailDomain = substr($email, strpos($email, '@') + 1);
    if (in_array($emailDomain, DISPOSABLE_EMAIL_DOMAINS, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Please use a business email address']);
        return;
    }

    // ── Local SQLite storage + verification token ────────────────────────
    $dbPath = getenv('AUDITANDFIX_SITE_PATH')
        ? rtrim(getenv('AUDITANDFIX_SITE_PATH'), '/') . '/data/scan_emails.sqlite'
        : __DIR__ . '/data/scan_emails.sqlite';

    try {
        $dataDir = dirname($dbPath);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $db->exec("CREATE TABLE IF NOT EXISTS scan_emails (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            scan_id         TEXT    NOT NULL,
            email           TEXT    NOT NULL,
            marketing_optin INTEGER NOT NULL DEFAULT 0,
            optin_timestamp TEXT,
            ip_hash         TEXT,
            created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
            score           INTEGER,
            grade           TEXT,
            domain          TEXT,
            issues_count    INTEGER,
            factor_summary  TEXT
        )");
        try { $db->exec("ALTER TABLE scan_emails ADD COLUMN verification_token TEXT"); } catch (Throwable $e) {}
        try { $db->exec("ALTER TABLE scan_emails ADD COLUMN email_verified_at TEXT"); } catch (Throwable $e) {}

        $rawIp  = trim(explode(',', $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR'] ?? '')[0]);
        $ipHash = $rawIp ? hash('sha256', $rawIp) : null;

        $verificationToken = bin2hex(random_bytes(32)); // 64 hex chars, expires via TTL check

        $stmt = $db->prepare(
            "INSERT INTO scan_emails (scan_id, email, marketing_optin, ip_hash, verification_token)
             VALUES (:scan_id, :email, 0, :ip_hash, :token)"
        );
        $stmt->execute([
            ':scan_id' => 'demo_' . $demoId,
            ':email'   => $email,
            ':ip_hash' => $ipHash,
            ':token'   => $verificationToken,
        ]);
    } catch (Throwable $e) {
        error_log('demoEmail DB error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to process request']);
        return;
    }

    // ── Send verification email via Resend ───────────────────────────────
    $resendKey = getenv('RESEND_API_KEY');
    if ($resendKey) {
        $scheme    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host      = $_SERVER['HTTP_HOST'] ?? 'auditandfix.com';
        $verifyUrl = $scheme . '://' . $host
            . '/video-reviews/?verify=' . urlencode($demoId)
            . '&token=' . urlencode($verificationToken);

        $bodyHtml =
            '<div style="font-family:sans-serif;max-width:520px;margin:0 auto;padding:32px 24px;">'
            . '<h2 style="color:#0a1428;font-size:1.3rem;margin-bottom:8px;">You\'re one step away from your free video</h2>'
            . '<p style="color:#4a5568;">Click the button below to verify your email and start creating your personalised review video.</p>'
            . '<p style="margin:24px 0;">'
            .   '<a href="' . htmlspecialchars($verifyUrl) . '" '
            .   'style="background:#2563eb;color:#fff;padding:14px 28px;border-radius:6px;'
            .          'text-decoration:none;display:inline-block;font-weight:600;">'
            .   'Verify Email &amp; Get My Video</a>'
            . '</p>'
            . '<p style="color:#718096;font-size:0.85rem;">This link expires in 24 hours. If you didn\'t request this, ignore this email.</p>'
            . '<hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">'
            . '<p style="color:#a0aec0;font-size:0.8rem;">'
            .   'Audit&amp;Fix &mdash; <a href="https://auditandfix.com" style="color:#a0aec0;">auditandfix.com</a>'
            . '</p>'
            . '</div>';

        $bodyText = "You're one step away from your free video.\n\n"
            . "Click below to verify your email and start creating your personalised review video:\n\n"
            . $verifyUrl . "\n\n"
            . "This link expires in 24 hours. If you didn't request this, ignore this email.\n\n"
            . "-- Audit&Fix (auditandfix.com)";

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'from'    => 'Audit&Fix <noreply@auditandfix.com>',
                'to'      => [$email],
                'subject' => 'Verify your email to get your free review video',
                'html'    => $bodyHtml,
                'text'    => $bodyText,
            ]),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $resendKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resendResp = curl_exec($ch);
        $resendCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resendCode < 200 || $resendCode >= 300) {
            error_log('demoEmail Resend error ' . $resendCode . ': ' . $resendResp);
        }
    }

    echo json_encode(['success' => true, 'email_sent' => true]);
}

/**
 * Validate the email verification token and notify the CF Worker to mark
 * the demo as 'verified' so the pipeline can pick it up.
 */
function verifyDemo(array $input): void {
    $demoId = (string)($input['demo_id'] ?? '');
    $token  = (string)($input['token'] ?? '');

    if (!preg_match('/^[a-f0-9\-]+$/', $demoId) || !preg_match('/^[a-f0-9]+$/', $token) || strlen($token) < 32) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid verification link']);
        return;
    }

    $dbPath = getenv('AUDITANDFIX_SITE_PATH')
        ? rtrim(getenv('AUDITANDFIX_SITE_PATH'), '/') . '/data/scan_emails.sqlite'
        : __DIR__ . '/data/scan_emails.sqlite';

    $verifiedEmail = null;
    try {
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Token is valid for 24 hours
        $stmt = $db->prepare(
            "SELECT id, email, email_verified_at FROM scan_emails
             WHERE scan_id = :scan_id
               AND verification_token = :token
               AND created_at >= datetime('now', '-24 hours')
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([':scan_id' => 'demo_' . $demoId, ':token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or expired verification link']);
            return;
        }

        $verifiedEmail = $row['email'];

        // Mark as verified (idempotent)
        if (!$row['email_verified_at']) {
            $upd = $db->prepare(
                "UPDATE scan_emails SET email_verified_at = datetime('now') WHERE id = :id"
            );
            $upd->execute([':id' => $row['id']]);
        }
    } catch (Throwable $e) {
        error_log('verifyDemo DB error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Verification failed']);
        return;
    }

    // ── Notify CF Worker ─────────────────────────────────────────────────
    $hasClips  = false;
    $workerUrl = rtrim(getenv('AUDITANDFIX_WORKER_URL') ?: '', '/');
    if ($workerUrl && $verifiedEmail) {
        // Mark demo as verified in the Worker KV
        $ch = curl_init($workerUrl . '/video-demo/' . urlencode($demoId) . '/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['email' => $verifiedEmail]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);

        // Fetch demo record to return has_clips flag
        $ch = curl_init($workerUrl . '/video-demo/' . urlencode($demoId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp) {
            $data     = json_decode($resp, true);
            $hasClips = (bool)($data['has_clips'] ?? false);
        }
    }

    echo json_encode(['success' => true, 'demo_id' => $demoId, 'has_clips' => $hasClips]);
}

// ─── GA4 Measurement Protocol Helpers ───────────────────────────────────────

/**
 * Returns true when running in PayPal sandbox mode (env-based check).
 * Used to suppress GA4 events during testing.
 */
function isSandboxEnv(): bool {
    return getenv('PAYPAL_MODE') === 'sandbox';
}

/**
 * Derives a stable GA4 client_id from the visitor's IP.
 * GA4 MP requires a client_id; we use an IP-based hash as a privacy-safe proxy
 * since we have no access to the browser's _ga cookie server-side.
 * Format matches GA4's expected "random.timestamp" pattern.
 */
function ga4ClientId(): string {
    $ip = trim(explode(',', $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')[0]);
    // Deterministic pseudo-random from IP hash — not truly unique per user but
    // good enough for aggregate conversion counting via MP.
    $hash = crc32(hash('sha256', $ip . date('Y-m-d')));
    return abs($hash) . '.' . strtotime('today');
}

/**
 * Fire a GA4 Measurement Protocol event (fire-and-forget, non-blocking).
 *
 * @param string $eventName  GA4 event name (e.g. 'purchase', 'generate_lead')
 * @param array  $params     Event parameters
 * @param string $clientId   GA4 client ID
 */
function ga4Event(string $eventName, array $params, string $clientId): void {
    $measurementId = 'G-QMPMDVQJGP';
    $apiSecret     = '326IHGuaQ7abuNR1hHXfrg';

    $payload = json_encode([
        'client_id' => $clientId,
        'events'    => [[
            'name'   => $eventName,
            'params' => array_filter($params, fn($v) => $v !== null),
        ]],
    ]);

    $url = "https://www.google-analytics.com/mp/collect"
         . "?measurement_id={$measurementId}&api_secret={$apiSecret}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 3,         // non-blocking — fail fast
        CURLOPT_NOSIGNAL       => 1,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
