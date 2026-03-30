<?php
/**
 * Customer portal database — SQLite with auto-migration.
 *
 * Usage: $db = getCustomerDb();
 * Returns a PDO instance with WAL mode, busy_timeout, foreign keys enabled.
 * Auto-creates the DB + schema on first call.
 */

function getCustomerDb(): PDO {
    static $db = null;
    if ($db !== null) return $db;

    $dbPath = getenv('AUDITANDFIX_SITE_PATH')
        ? rtrim(getenv('AUDITANDFIX_SITE_PATH'), '/') . '/data/customers.sqlite'
        : dirname(__DIR__, 2) . '/data/customers.sqlite';

    $dataDir = dirname($dbPath);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0700, true);
    }

    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $db->exec("PRAGMA journal_mode = WAL");
    $db->exec("PRAGMA busy_timeout = 5000");
    $db->exec("PRAGMA synchronous = NORMAL");
    $db->exec("PRAGMA foreign_keys = ON");

    runCustomerMigrations($db);

    return $db;
}

function runCustomerMigrations(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version     INTEGER PRIMARY KEY,
        applied_at  TEXT NOT NULL DEFAULT (datetime('now')),
        description TEXT
    )");

    $applied = array_flip(
        $db->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN)
    );

    $migrations = [
        1 => [
            'description' => 'Initial schema: customers, magic_links, sessions, products, cross_sell, rate_limits, portal_services',
            'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS customers (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    email           TEXT    NOT NULL COLLATE NOCASE,
    display_name    TEXT,
    country_code    TEXT,
    marketing_optin INTEGER NOT NULL DEFAULT 0,
    last_login_at   TEXT,
    deleted_at      TEXT,
    created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_customers_email ON customers(email);

CREATE TABLE IF NOT EXISTS magic_links (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    email       TEXT    NOT NULL COLLATE NOCASE,
    token_hash  TEXT    NOT NULL,
    expires_at  TEXT    NOT NULL,
    used_at     TEXT,
    intent      TEXT    NOT NULL DEFAULT 'login',
    payload     TEXT,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_magic_links_token ON magic_links(token_hash);
CREATE INDEX IF NOT EXISTS idx_magic_links_email ON magic_links(email, created_at DESC);

CREATE TABLE IF NOT EXISTS customer_sessions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id  INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    token        TEXT    NOT NULL,
    expires_at   TEXT    NOT NULL,
    ip_hash      TEXT,
    user_agent   TEXT,
    created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    last_seen_at TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_sessions_token ON customer_sessions(token);

CREATE TABLE IF NOT EXISTS customer_products (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id     INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    product_type    TEXT    NOT NULL,
    external_ref    TEXT,
    status          TEXT    NOT NULL DEFAULT 'active',
    label           TEXT,
    domain          TEXT,
    country_code    TEXT,
    score           INTEGER,
    grade           TEXT,
    acquired_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    synced_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_products_customer ON customer_products(customer_id, acquired_at DESC);
CREATE INDEX IF NOT EXISTS idx_products_ref ON customer_products(product_type, external_ref);

CREATE TABLE IF NOT EXISTS cross_sell_events (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id          INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
    offer_type           TEXT    NOT NULL,
    outcome              TEXT    NOT NULL DEFAULT 'shown',
    trigger_product_id   INTEGER REFERENCES customer_products(id),
    converted_product_id INTEGER REFERENCES customer_products(id),
    created_at           TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_cross_sell_customer ON cross_sell_events(customer_id, offer_type);

CREATE TABLE IF NOT EXISTS rate_limits (
    ip_hash      TEXT NOT NULL,
    action       TEXT NOT NULL,
    count        INTEGER NOT NULL DEFAULT 1,
    window_start TEXT NOT NULL DEFAULT (datetime('now')),
    PRIMARY KEY (ip_hash, action)
);

CREATE TABLE IF NOT EXISTS portal_services (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    service_key  TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL,
    icon         TEXT,
    enabled      INTEGER NOT NULL DEFAULT 1,
    sort_order   INTEGER NOT NULL DEFAULT 0
);

INSERT OR IGNORE INTO portal_services (service_key, display_name, icon, sort_order) VALUES
    ('dashboard',     'Dashboard',       'home',    0),
    ('reports',       'Audit Reports',   'chart',  10),
    ('subscriptions', 'Subscriptions',   'video',  20),
    ('videos',        'Video Library',   'play',   30),
    ('billing',       'Billing',         'credit', 40);
SQL
        ],
    ];

    foreach ($migrations as $version => $migration) {
        if (isset($applied[$version])) continue;

        $db->beginTransaction();
        try {
            $db->exec($migration['sql']);
            $stmt = $db->prepare("INSERT INTO schema_migrations (version, description) VALUES (?, ?)");
            $stmt->execute([$version, $migration['description']]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw new RuntimeException("Customer DB migration v{$version} failed: " . $e->getMessage());
        }
    }
}
