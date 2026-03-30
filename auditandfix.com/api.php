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
        case 'create-subscription':
            createSubscription($input);
            break;
        case 'activate-subscription':
            activateSubscription($input);
            break;
        case 'send-magic-link':
            sendMagicLink($input);
            break;
        case 'verify-magic-link':
            verifyMagicLink($input);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Throwable $e) {
    error_log('api.php uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
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
    $purchaseValue = floatval($captureData['amount']['value'] ?? 0);
    $purchaseCurrency = $captureData['amount']['currency_code'] ?? 'USD';
    $captureId = $captureData['id'] ?? $orderId;
    ga4Event('purchase', [
        'transaction_id' => $captureId,
        'value'          => $purchaseValue,
        'currency'       => $purchaseCurrency,
        'gclid'          => $gclid,
        'items'          => [[
            'item_id'   => $product,
            'item_name' => $product,
            'quantity'  => 1,
            'price'     => $purchaseValue,
        ]],
    ], $clientId);

    // Meta CAPI — Purchase
    // Use client-provided event_id for dedup (browser pixel fires same ID via fbq)
    $capiEventId = !empty($input['event_id'])
        ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$input['event_id'])
        : 'purchase_' . $captureId;
    $ip = trim(explode(',', $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR'] ?? '')[0]);
    metaCapiEvent('Purchase', [
        'email'             => $input['email'] ?? '',
        'client_ip'         => $ip,
        'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'fbc'               => $_COOKIE['_fbc'] ?? null,
        'fbp'               => $_COOKIE['_fbp'] ?? null,
    ], [
        'value'        => $purchaseValue,
        'currency'     => $purchaseCurrency,
        'content_name' => $product,
        'content_ids'  => [$product],
        'content_type' => 'product',
    ], $capiEventId);

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

    // Meta CAPI — Lead (server-side, no consent gate needed for CAPI)
    if ($email && !isSandboxEnv()) {
        // Use client-provided event_id for dedup (browser pixel fires same ID via fbq)
        $leadEventId = !empty($input['event_id'])
            ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$input['event_id'])
            : 'lead_' . hash('sha256', $email . date('Y-m-d'));
        $ip = trim(explode(',', $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR'] ?? '')[0]);
        metaCapiEvent('Lead', [
            'email'             => $email,
            'client_ip'         => $ip,
            'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'fbc'               => $_COOKIE['_fbc'] ?? null,
            'fbp'               => $_COOKIE['_fbp'] ?? null,
        ], [
            'content_name' => 'SEO Audit',
        ], $leadEventId);
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
 * Send a server-side event to Meta Conversions API (fire-and-forget).
 *
 * Requires META_PIXEL_ID and META_ACCESS_TOKEN in server environment.
 * META_TEST_EVENT_CODE can be set for Events Manager test mode.
 *
 * @param string      $eventName  Meta standard event (Purchase, Lead, etc.)
 * @param array       $userData   User matching: email, phone, client_ip, client_user_agent, fbc, fbp
 * @param array       $customData Event data: value, currency, content_name, etc.
 * @param string|null $eventId    Dedup ID — use the same value in browser fbq() call to prevent double-counting
 */
function metaCapiEvent(string $eventName, array $userData, array $customData = [], ?string $eventId = null): void {
    $pixelId     = getenv('META_PIXEL_ID') ?: '';
    $accessToken = getenv('META_ACCESS_TOKEN') ?: '';
    if (!$pixelId || !$accessToken) return;

    // Hash PII per Meta requirements
    $hashed = [];
    foreach (['email' => 'em', 'phone' => 'ph', 'first_name' => 'fn', 'last_name' => 'ln'] as $field => $key) {
        if (!empty($userData[$field])) {
            $hashed[$key] = hash('sha256', strtolower(trim($userData[$field])));
        }
    }
    if (!empty($userData['client_ip']))         $hashed['client_ip_address']  = $userData['client_ip'];
    if (!empty($userData['client_user_agent'])) $hashed['client_user_agent']   = $userData['client_user_agent'];
    if (!empty($userData['fbc']))               $hashed['fbc']                 = $userData['fbc'];
    if (!empty($userData['fbp']))               $hashed['fbp']                 = $userData['fbp'];

    $event = [
        'event_name'       => $eventName,
        'event_time'       => time(),
        'action_source'    => 'website',
        'event_source_url' => 'https://auditandfix.com',
        'user_data'        => $hashed,
    ];
    if ($eventId)           $event['event_id']   = $eventId;
    if (!empty($customData)) $event['custom_data'] = $customData;

    $payload = ['data' => [$event], 'access_token' => $accessToken];
    $testCode = getenv('META_TEST_EVENT_CODE') ?: '';
    if ($testCode) $payload['test_event_code'] = $testCode;

    $apiVersion = getenv('META_API_VERSION') ?: 'v20.0';
    $ch = curl_init("https://graph.facebook.com/{$apiVersion}/{$pixelId}/events");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_NOSIGNAL       => 1,
    ]);
    curl_exec($ch);
    curl_close($ch);
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

// ── Video Subscription (2Step) ─────────────────────────────────────────────

function getSubscriptionDb(): PDO {
    $dbPath = getenv('AUDITANDFIX_SITE_PATH')
        ? rtrim(getenv('AUDITANDFIX_SITE_PATH'), '/') . '/data/subscriptions.sqlite'
        : __DIR__ . '/data/subscriptions.sqlite';

    $dataDir = dirname($dbPath);
    if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);

    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE IF NOT EXISTS subscriptions (
        id                     INTEGER PRIMARY KEY AUTOINCREMENT,
        paypal_subscription_id TEXT    UNIQUE NOT NULL,
        email                  TEXT    NOT NULL,
        business_name          TEXT,
        video_hash             TEXT,
        plan_tier              TEXT    NOT NULL,
        country_code           TEXT    NOT NULL,
        currency               TEXT    NOT NULL,
        amount                 INTEGER NOT NULL,
        status                 TEXT    DEFAULT 'pending',
        created_at             TEXT    NOT NULL DEFAULT (datetime('now')),
        activated_at           TEXT
    )");

    return $db;
}

function createSubscription(array $input): void {
    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $tier  = $input['tier'] ?? '';
    $country = strtoupper($input['country'] ?? detectCountry());
    $businessName = trim($input['business_name'] ?? '');
    $videoHash = trim($input['video_hash'] ?? '');

    if (!$email) {
        http_response_code(400);
        echo json_encode(['error' => 'Valid email required']);
        return;
    }

    $validTiers = ['monthly_4', 'monthly_8', 'monthly_12'];
    if (!in_array($tier, $validTiers, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid tier. Use: ' . implode(', ', $validTiers)]);
        return;
    }

    $planId = get2StepPlanId($country, $tier);
    if (!$planId) {
        http_response_code(400);
        echo json_encode(['error' => 'No plan available for this country/tier']);
        return;
    }

    $pricing = get2StepPriceForCountry($country);
    $amount  = $pricing[$tier] ?? 0;
    $currency = $pricing['currency'] ?? 'USD';

    $apiBase = getApiBase($input);
    $token   = getPayPalAccessToken($apiBase);

    $returnUrl = 'https://auditandfix.com/v/' . urlencode($videoHash) . '?subscription_activated=1';
    $cancelUrl = 'https://auditandfix.com/v/' . urlencode($videoHash) . '?subscription_cancelled=1';

    $body = [
        'plan_id' => $planId,
        'subscriber' => [
            'name' => ['given_name' => $businessName ?: 'Customer'],
            'email_address' => $email,
        ],
        'application_context' => [
            'brand_name'          => 'Audit&Fix Video Reviews',
            'shipping_preference' => 'NO_SHIPPING',
            'user_action'         => 'SUBSCRIBE_NOW',
            'return_url'          => $returnUrl,
            'cancel_url'          => $cancelUrl,
        ],
    ];

    $ch = curl_init($apiBase . '/v1/billing/subscriptions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        http_response_code(502);
        echo json_encode(['error' => 'PayPal subscription creation failed', 'detail' => $response]);
        return;
    }

    $data = json_decode($response, true);
    $subscriptionId = $data['id'] ?? '';
    $approveUrl = '';
    foreach ($data['links'] ?? [] as $link) {
        if ($link['rel'] === 'approve') {
            $approveUrl = $link['href'];
            break;
        }
    }

    if (!$subscriptionId || !$approveUrl) {
        http_response_code(502);
        echo json_encode(['error' => 'PayPal did not return subscription ID or approve URL']);
        return;
    }

    // Store pending subscription
    $db = getSubscriptionDb();
    $stmt = $db->prepare("INSERT INTO subscriptions
        (paypal_subscription_id, email, business_name, video_hash, plan_tier, country_code, currency, amount)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$subscriptionId, $email, $businessName, $videoHash, $tier, $country, $currency, $amount]);

    echo json_encode([
        'id'          => $subscriptionId,
        'approve_url' => $approveUrl,
    ]);
}

function activateSubscription(array $input): void {
    $subscriptionId = trim($input['subscription_id'] ?? '');
    $videoHash = trim($input['video_hash'] ?? '');

    if (!$subscriptionId) {
        http_response_code(400);
        echo json_encode(['error' => 'subscription_id required']);
        return;
    }

    $apiBase = getApiBase($input);
    $token   = getPayPalAccessToken($apiBase);

    // Verify subscription status with PayPal
    $ch = curl_init($apiBase . '/v1/billing/subscriptions/' . urlencode($subscriptionId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        http_response_code(502);
        echo json_encode(['error' => 'Could not verify subscription with PayPal']);
        return;
    }

    $data = json_decode($response, true);
    $status = $data['status'] ?? '';

    if (!in_array($status, ['ACTIVE', 'APPROVED'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Subscription not active', 'status' => $status]);
        return;
    }

    // Update local DB
    $db = getSubscriptionDb();
    $stmt = $db->prepare("UPDATE subscriptions SET status = 'active', activated_at = datetime('now')
        WHERE paypal_subscription_id = ?");
    $stmt->execute([$subscriptionId]);

    // Notify CF Worker (fire-and-forget)
    if (CF_WORKER_URL) {
        $row = $db->prepare("SELECT * FROM subscriptions WHERE paypal_subscription_id = ?")->fetch(PDO::FETCH_ASSOC);
        if (!empty($row)) {
            $workerBody = json_encode([
                'subscription_id' => $subscriptionId,
                'email'           => $row['email'] ?? '',
                'business_name'   => $row['business_name'] ?? '',
                'video_hash'      => $row['video_hash'] ?? '',
                'plan_tier'       => $row['plan_tier'] ?? '',
                'country_code'    => $row['country_code'] ?? '',
                'currency'        => $row['currency'] ?? '',
                'amount'          => $row['amount'] ?? 0,
            ]);

            $ch = curl_init(CF_WORKER_URL . '/subscription');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $workerBody,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-Auth-Secret: ' . CF_WORKER_SECRET,
                ],
                CURLOPT_TIMEOUT => 5,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    echo json_encode(['status' => 'active', 'subscription_id' => $subscriptionId]);
}

// ── Customer Portal: Magic Link Auth ────────────────────────────────────────

/**
 * Send a passwordless magic link email for customer login.
 *
 * Rate limits: 5 per IP per hour, 3 per email per hour.
 * Creates customer record if none exists (auto-registration).
 * Token is hashed before storage (SHA-256). 30-min expiry. Single-use.
 */
function sendMagicLink(array $input): void {
    require_once __DIR__ . '/includes/account/db.php';
    require_once __DIR__ . '/includes/account/rate-limit.php';
    require_once __DIR__ . '/includes/account/auth.php';

    $email = strtolower(trim(filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: ''));
    if (!$email) {
        http_response_code(400);
        echo json_encode(['error' => 'Please enter a valid email address.']);
        return;
    }

    // Rate limiting
    $ip = getClientIp();
    $ipHash = hash('sha256', $ip);
    $emailHash = hash('sha256', $email);

    if (!checkRateLimit($ipHash, 'magic_link_ip', 5, 60)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many requests. Please try again in an hour.']);
        return;
    }
    if (!checkRateLimit($emailHash, 'magic_link_email', 3, 60)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many login attempts for this email. Please try again in an hour.']);
        return;
    }

    rateLimitGc();

    $db = getCustomerDb();

    // Find or create customer
    $stmt = $db->prepare("SELECT id FROM customers WHERE email = ? AND deleted_at IS NULL");
    $stmt->execute([$email]);
    $customer = $stmt->fetch();

    if (!$customer) {
        $db->prepare("INSERT INTO customers (email) VALUES (?)")->execute([$email]);
        $customerId = (int)$db->lastInsertId();
    } else {
        $customerId = (int)$customer['id'];
    }

    // Invalidate any existing unused magic links for this email
    $db->prepare(
        "UPDATE magic_links SET used_at = datetime('now')
         WHERE email = ? AND used_at IS NULL AND expires_at > datetime('now')"
    )->execute([$email]);

    // Generate token — store hash, send plaintext in email
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + 30 * 60); // 30 minutes

    $db->prepare(
        "INSERT INTO magic_links (email, token_hash, expires_at)
         VALUES (?, ?, ?)"
    )->execute([$email, $tokenHash, $expiresAt]);

    // Build magic link URL
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'auditandfix.com';
    $verifyUrl = $scheme . '://' . $host . '/account/verify?token=' . urlencode($token);

    // Send branded email via Resend
    $resendKey = getenv('RESEND_API_KEY');
    if (!$resendKey) {
        error_log('sendMagicLink: RESEND_API_KEY not set');
        http_response_code(500);
        echo json_encode(['error' => 'Email service unavailable. Please try again later.']);
        return;
    }

    $htmlEmail = magicLinkEmailHtml($verifyUrl);
    $textEmail = "Log in to your Audit&Fix account\n\n"
        . "Click the link below to securely access your account:\n\n"
        . $verifyUrl . "\n\n"
        . "This link expires in 30 minutes. If you didn't request this, you can safely ignore this email.\n\n"
        . "-- Audit&Fix (auditandfix.com)";

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'from'    => 'Audit&Fix <noreply@auditandfix.com>',
            'to'      => [$email],
            'subject' => 'Your Audit&Fix login link',
            'html'    => $htmlEmail,
            'text'    => $textEmail,
        ]),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $resendKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        error_log('sendMagicLink Resend error ' . $code . ': ' . $resp);
        http_response_code(500);
        echo json_encode(['error' => 'Failed to send login email. Please try again.']);
        return;
    }

    echo json_encode(['success' => true]);
}

/**
 * Verify a magic link token and create an authenticated session.
 * Called via GET /account/verify?token=xxx (routed through account.php).
 * This function is also callable as a POST API action for AJAX flows.
 */
function verifyMagicLink(array $input): void {
    require_once __DIR__ . '/includes/account/db.php';
    require_once __DIR__ . '/includes/account/auth.php';

    $token = trim($input['token'] ?? $_GET['token'] ?? '');
    if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid login link.']);
        return;
    }

    $tokenHash = hash('sha256', $token);
    $db = getCustomerDb();

    // Find valid token (unused, not expired)
    $stmt = $db->prepare(
        "SELECT id, email, expires_at
         FROM magic_links
         WHERE token_hash = ?
           AND used_at IS NULL
           AND expires_at > datetime('now')"
    );
    $stmt->execute([$tokenHash]);
    $link = $stmt->fetch();

    if (!$link) {
        http_response_code(400);
        echo json_encode(['error' => 'expired', 'message' => 'This login link has expired or already been used.']);
        return;
    }

    // Mark token as used (single-use enforcement)
    $db->prepare("UPDATE magic_links SET used_at = datetime('now') WHERE id = ?")
       ->execute([$link['id']]);

    // Find the customer
    $custStmt = $db->prepare("SELECT id FROM customers WHERE email = ? AND deleted_at IS NULL");
    $custStmt->execute([$link['email']]);
    $customer = $custStmt->fetch();

    if (!$customer) {
        http_response_code(400);
        echo json_encode(['error' => 'Account not found.']);
        return;
    }

    // Create authenticated session
    createSession((int)$customer['id']);

    echo json_encode(['success' => true, 'redirect' => '/account/dashboard']);
}

/**
 * Branded HTML email template for magic link.
 * Clean design matching A&F brand. No Mailchimp artifacts.
 */
function magicLinkEmailHtml(string $verifyUrl): string {
    $url = htmlspecialchars($verifyUrl);
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:0;background:#f7f8fc;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f7f8fc;">
<tr><td align="center" style="padding:40px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.08);">

<!-- Logo -->
<tr><td style="padding:32px 32px 0;text-align:center;">
  <img src="https://auditandfix.com/assets/img/logo.svg" alt="Audit&Fix" width="160" style="display:inline-block;max-width:160px;">
</td></tr>

<!-- Body -->
<tr><td style="padding:24px 32px;">
  <h1 style="color:#0a1428;font-size:1.25rem;font-weight:600;margin:0 0 12px;">Log in to your account</h1>
  <p style="color:#4a5568;font-size:0.95rem;line-height:1.6;margin:0 0 24px;">
    Click the button below to securely access your Audit&amp;Fix account. This link expires in 30 minutes.
  </p>

  <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
  <tr><td style="border-radius:8px;background:#1a365d;">
    <a href="{$url}" target="_blank"
       style="display:inline-block;padding:14px 32px;color:#ffffff;font-size:1rem;font-weight:600;
              text-decoration:none;border-radius:8px;">
      Log In to Audit&amp;Fix &rarr;
    </a>
  </td></tr>
  </table>

  <p style="color:#718096;font-size:0.85rem;line-height:1.5;margin:24px 0 0;">
    If the button doesn&rsquo;t work, copy and paste this link into your browser:<br>
    <a href="{$url}" style="color:#2563eb;word-break:break-all;font-size:0.8rem;">{$url}</a>
  </p>
</td></tr>

<!-- Footer -->
<tr><td style="padding:0 32px 32px;">
  <hr style="border:none;border-top:1px solid #e2e8f0;margin:0 0 16px;">
  <p style="color:#a0aec0;font-size:0.8rem;margin:0;line-height:1.5;">
    If you didn&rsquo;t request this login link, you can safely ignore this email.
    <br>Audit&amp;Fix &mdash; <a href="https://auditandfix.com" style="color:#a0aec0;">auditandfix.com</a>
  </p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;
}
