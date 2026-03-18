-- =============================================================================
-- mmo-platform shared messages database schema
-- Location: ~/code/mmo-platform/db/messages.db
--
-- All outreach messages, contacts, compliance tables, pricing, and metrics
-- for ALL projects (333Method, 2Step, etc.) live here.
-- Both projects access this DB via SQLite ATTACH.
--
-- Concurrency: WAL mode + busy_timeout set by init-messages-db.js at creation.
-- ATTACH connections inherit the file's journal mode automatically.
-- =============================================================================

PRAGMA journal_mode = WAL;
PRAGMA busy_timeout = 15000;

-- =============================================================================
-- messages: ALL outreach + inbound across all projects
-- =============================================================================
CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project TEXT NOT NULL,                -- '333method' | '2step'
    site_id INTEGER NOT NULL,             -- FK to sites table in the project's DB
    direction TEXT NOT NULL DEFAULT 'outbound' CHECK(direction IN ('inbound', 'outbound')),

    -- Channel
    contact_method TEXT NOT NULL CHECK(contact_method IN (
        'sms', 'email', 'form', 'x', 'linkedin'
    )),
    contact_uri TEXT NOT NULL,

    -- Content
    message_body TEXT,
    subject_line TEXT,
    video_url TEXT,                        -- 2Step: video attached to this message

    -- Approval workflow
    approval_status TEXT CHECK(approval_status IN (
        'pending', 'approved', 'rework', 'rejected', 'parked'
    )),
    rework_instructions TEXT,
    exported_at TEXT,

    -- Delivery tracking
    delivery_status TEXT CHECK(delivery_status IN (
        'queued', 'sent', 'delivered', 'failed', 'bounced', 'retry_later'
    )),
    error_message TEXT,
    retry_at TEXT,
    sent_at TEXT,
    delivered_at TEXT,
    email_id TEXT,

    -- Engagement
    opened_at TEXT,

    -- Inbound classification
    sentiment TEXT CHECK(sentiment IN ('positive', 'neutral', 'negative', 'objection')),
    intent TEXT CHECK(intent IN (
        'inquiry', 'opt-out', 'interested', 'not-interested',
        'pricing', 'schedule', 'unknown', 'autoresponder'
    )),

    -- Message type
    message_type TEXT DEFAULT 'outreach' CHECK(message_type IN (
        'outreach', 'followup1', 'followup2', 'reply'
    )),

    -- Inbound metadata
    raw_payload TEXT,
    is_read INTEGER DEFAULT 0,
    processed_at TEXT,

    -- Payment tracking
    payment_link TEXT,
    payment_amount REAL,
    payment_currency TEXT,

    -- Price intelligence: FK to pricing table row that was active when this message was sent.
    -- Pricing rows are never updated (append-only) — join to reconstruct exact prices shown
    -- even after future price changes.
    pricing_id INTEGER,         -- FK -> pricing.id (which price row was active at send time)
    price_discount_pct REAL,    -- discount % applied to this specific message (0 if none, 0.20 = 20% off)

    -- Template tracking
    template_id TEXT,

    -- Sequence tracking (8-touch cadence for 2Step)
    sequence_step INTEGER,          -- 1-8 touch position in the outreach sequence
    scheduled_send_at TEXT,         -- ISO datetime when this message becomes eligible to send

    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_messages_project_site ON messages(project, site_id);
CREATE INDEX IF NOT EXISTS idx_messages_direction ON messages(direction);
CREATE INDEX IF NOT EXISTS idx_messages_approval ON messages(approval_status) WHERE approval_status IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_messages_delivery ON messages(delivery_status) WHERE delivery_status IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_messages_contact ON messages(contact_method);
CREATE INDEX IF NOT EXISTS idx_messages_sent ON messages(sent_at) WHERE sent_at IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_messages_intent ON messages(intent) WHERE intent IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_messages_type ON messages(message_type);
CREATE INDEX IF NOT EXISTS idx_messages_contact_uri ON messages(contact_uri);
CREATE INDEX IF NOT EXISTS idx_messages_scheduled ON messages(scheduled_send_at) WHERE scheduled_send_at IS NOT NULL AND sent_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_messages_sequence ON messages(project, site_id, sequence_step) WHERE sequence_step IS NOT NULL;

-- =============================================================================
-- contacts: canonical identity for every business across all projects
-- =============================================================================
CREATE TABLE IF NOT EXISTS contacts (
    id INTEGER PRIMARY KEY,
    domain TEXT,                    -- primary dedup key
    business_name TEXT,
    phone TEXT,                     -- E.164
    email TEXT,
    first_seen_project TEXT,        -- '333method' | '2step'
    first_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    contacted_by_333method INTEGER DEFAULT 0,
    contacted_by_2step INTEGER DEFAULT 0,
    overall_sentiment TEXT,
    is_opted_out INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_contacts_domain ON contacts(domain) WHERE domain IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_contacts_email ON contacts(email) WHERE email IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_contacts_phone ON contacts(phone) WHERE phone IS NOT NULL;

-- =============================================================================
-- cross_sell_queue: opportunities for cross-project selling
-- =============================================================================
CREATE TABLE IF NOT EXISTS cross_sell_queue (
    id INTEGER PRIMARY KEY,
    contact_id INTEGER REFERENCES contacts(id),
    source_project TEXT NOT NULL,
    target_project TEXT NOT NULL,
    reason TEXT NOT NULL,               -- 'positive_reply', 'purchased', etc.
    status TEXT DEFAULT 'pending' CHECK(status IN ('pending', 'queued', 'sent', 'skipped')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================================
-- opt_outs: unified STOP/unsubscribe records across all projects
-- Cross-project: STOP from any project stops all; UNSTOP re-enables all.
-- Per-country TCPA hours and CAN-SPAM rules live in compliance.js (code logic).
-- =============================================================================
CREATE TABLE IF NOT EXISTS opt_outs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    phone TEXT,
    email TEXT,
    method TEXT NOT NULL CHECK(method IN ('sms', 'email')),
    project TEXT,                        -- which project triggered the opt-out (NULL = all)
    opted_out_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    source TEXT DEFAULT 'inbound',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(phone, method),
    UNIQUE(email, method)
);

-- =============================================================================
-- unsubscribed_emails: email unsubscribes (CAN-SPAM / Resend webhook)
-- =============================================================================
CREATE TABLE IF NOT EXISTS unsubscribed_emails (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    message_id INTEGER REFERENCES messages(id),
    project TEXT,
    unsubscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    source TEXT DEFAULT 'web',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================================
-- pricing: per-project, per-country, per-tier pricing (append-only rows)
-- Never UPDATE or DELETE rows. To change a price: set superseded_at = today on
-- the old row, INSERT a new row with effective_from = today.
-- Current active row: WHERE superseded_at IS NULL.
-- =============================================================================
CREATE TABLE IF NOT EXISTS pricing (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    project      TEXT NOT NULL,  -- '2step' | '333method'
    country_code TEXT NOT NULL,  -- 'AU', 'US', 'UK', 'CA', 'NZ'
    niche_tier   TEXT NOT NULL CHECK(niche_tier IN ('budget', 'standard', 'premium')),
    setup_local  REAL,           -- setup fee in local currency (2step only, NULL for 333method)
    monthly_4    REAL,           -- 4 videos/month (2step only, NULL for 333method)
    monthly_8    REAL,           -- 8 videos/month
    monthly_12   REAL,           -- 12 videos/month
    report_price REAL,           -- audit report price (333method only, NULL for 2step)
    currency     TEXT NOT NULL,  -- ISO 4217: 'AUD', 'USD', 'GBP', 'CAD', 'NZD'
    effective_from TEXT NOT NULL DEFAULT (date('now')),  -- date this row became active
    superseded_at  TEXT,         -- date this row was replaced (NULL = current)
    UNIQUE (project, country_code, niche_tier, effective_from)
);

CREATE INDEX IF NOT EXISTS idx_pricing_lookup ON pricing(project, country_code, niche_tier, superseded_at);

-- Seed: 2step pricing rows (setup + monthly tiers, PPP-adjusted with charm pricing)
-- Lookup: SELECT p.* FROM pricing p JOIN niche_tiers n ON n.tier = p.niche_tier
--         WHERE p.project='2step' AND p.country_code=? AND n.niche=? AND p.superseded_at IS NULL
INSERT OR IGNORE INTO pricing (project, country_code, niche_tier, setup_local, monthly_4, monthly_8, monthly_12, report_price, currency)
VALUES
    ('2step', 'AU', 'budget',    699,  139, 249, 349, NULL, 'AUD'),
    ('2step', 'AU', 'standard',  899,  139, 249, 349, NULL, 'AUD'),
    ('2step', 'AU', 'premium',  1099,  139, 249, 349, NULL, 'AUD'),
    ('2step', 'US', 'budget',    497,   99, 179, 249, NULL, 'USD'),
    ('2step', 'US', 'standard',  625,   99, 179, 249, NULL, 'USD'),
    ('2step', 'US', 'premium',   750,   99, 179, 249, NULL, 'USD'),
    ('2step', 'UK', 'budget',    389,   79, 139, 199, NULL, 'GBP'),
    ('2step', 'UK', 'standard',  489,   79, 139, 199, NULL, 'GBP'),
    ('2step', 'UK', 'premium',   589,   79, 139, 199, NULL, 'GBP'),
    ('2step', 'CA', 'budget',    669,  129, 229, 329, NULL, 'CAD'),
    ('2step', 'CA', 'standard',  849,  129, 229, 329, NULL, 'CAD'),
    ('2step', 'CA', 'premium',  1019,  129, 229, 329, NULL, 'CAD'),
    ('2step', 'NZ', 'budget',    789,  149, 279, 389, NULL, 'NZD'),
    ('2step', 'NZ', 'standard',  989,  149, 279, 389, NULL, 'NZD'),
    ('2step', 'NZ', 'premium',  1189,  149, 279, 389, NULL, 'NZD');

-- Seed: 333method pricing rows (report price only, setup/monthly NULL)
-- Confirmed current prices: AU standard=$337 AUD, UK standard=£159, US standard=$297
INSERT OR IGNORE INTO pricing (project, country_code, niche_tier, setup_local, monthly_4, monthly_8, monthly_12, report_price, currency)
VALUES
    ('333method', 'AU', 'budget',    NULL, NULL, NULL, NULL,  297, 'AUD'),
    ('333method', 'AU', 'standard',  NULL, NULL, NULL, NULL,  337, 'AUD'),
    ('333method', 'AU', 'premium',   NULL, NULL, NULL, NULL,  397, 'AUD'),
    ('333method', 'US', 'budget',    NULL, NULL, NULL, NULL,  197, 'USD'),
    ('333method', 'US', 'standard',  NULL, NULL, NULL, NULL,  297, 'USD'),
    ('333method', 'US', 'premium',   NULL, NULL, NULL, NULL,  397, 'USD'),
    ('333method', 'UK', 'budget',    NULL, NULL, NULL, NULL,  129, 'GBP'),
    ('333method', 'UK', 'standard',  NULL, NULL, NULL, NULL,  159, 'GBP'),
    ('333method', 'UK', 'premium',   NULL, NULL, NULL, NULL,  197, 'GBP'),
    ('333method', 'CA', 'budget',    NULL, NULL, NULL, NULL,  249, 'CAD'),
    ('333method', 'CA', 'standard',  NULL, NULL, NULL, NULL,  297, 'CAD'),
    ('333method', 'CA', 'premium',   NULL, NULL, NULL, NULL,  349, 'CAD'),
    ('333method', 'NZ', 'budget',    NULL, NULL, NULL, NULL,  329, 'NZD'),
    ('333method', 'NZ', 'standard',  NULL, NULL, NULL, NULL,  397, 'NZD'),
    ('333method', 'NZ', 'premium',   NULL, NULL, NULL, NULL,  447, 'NZD');

-- =============================================================================
-- system_metrics: per-stage performance data written each overseer cycle
-- Used by Sonnet overseer for trend detection and batch-size auto-tuning.
-- project = '333method' | '2step' | 'mmo-platform' (global/shared)
-- =============================================================================
CREATE TABLE IF NOT EXISTS system_metrics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project TEXT,               -- '333method' | '2step' | 'mmo-platform'
    metric_name TEXT NOT NULL,  -- 'sites_per_hour', 'stage_latency_p95', 'api_error_rate',
                                --   'batch_throughput', 'batch_size_used', 'stage_latency_p50'
    metric_value REAL NOT NULL,
    stage TEXT,                 -- pipeline stage this metric relates to (nullable)
    batch_size INTEGER,         -- batch size used when this metric was recorded (for experimentation)
    recorded_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_metrics_name_time ON system_metrics(metric_name, recorded_at);
CREATE INDEX IF NOT EXISTS idx_metrics_stage_time ON system_metrics(stage, recorded_at);
