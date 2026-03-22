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
            created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
        )");

        // One-way IP hash for GDPR-safe attribution (never store raw IP)
        $rawIp  = trim(explode(',', $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR'] ?? '')[0]);
        $ipHash = $rawIp ? hash('sha256', $rawIp) : null;

        $stmt = $db->prepare(
            "INSERT INTO scan_emails (scan_id, email, marketing_optin, optin_timestamp, ip_hash)
             VALUES (:scan_id, :email, :optin, :optin_ts, :ip_hash)"
        );
        $stmt->execute([
            ':scan_id'  => $scanId,
            ':email'    => $email,
            ':optin'    => $marketingOptin,
            ':optin_ts' => $optinTs,
            ':ip_hash'  => $ipHash,
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
