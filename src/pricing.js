/**
 * Shared pricing helper — queries the `pricing` table in messages.db.
 *
 * The `pricing` table is append-only (versioned). Never UPDATE or DELETE rows.
 * To change a price: set superseded_at on the old row, INSERT a new row.
 *
 * Usage:
 *   import { getPricingRow, getPricingDisplay } from '../../mmo-platform/src/pricing.js';
 *
 *   // Get the active pricing row (for recording pricing_id on messages)
 *   const row = getPricingRow(db, { project: '333method', countryCode: 'AU', nicheTier: 'standard' });
 *
 *   // Get display values (formatted prices for emails/SMS)
 *   const display = getPricingDisplay(db, { project: '2step', countryCode: 'US', nicheTier: 'budget' });
 */

import path from 'path';
import { fileURLToPath } from 'url';
import { existsSync } from 'fs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/**
 * Get the messages.db Database instance.
 * Accepts an injected db or opens from MESSAGES_DB_PATH env.
 */
function getMessagesDb(injectedDb) {
  if (injectedDb) return { db: injectedDb, owned: false };

  const messagesDbPath =
    process.env.MESSAGES_DB_PATH ||
    path.resolve(__dirname, '../../mmo-platform/db/messages.db');

  if (!existsSync(messagesDbPath)) {
    throw new Error(
      `messages.db not found at ${messagesDbPath}. Run mmo-platform/scripts/init-messages-db.js first.`
    );
  }

  // Dynamic import to avoid making better-sqlite3 a hard dep at module load time
  const { default: Database } = await import('better-sqlite3');
  return { db: new Database(messagesDbPath, { readonly: true }), owned: true };
}

/**
 * Get the active pricing row for a project+country+niche_tier combination.
 *
 * @param {import('better-sqlite3').Database} db - Attached or standalone messages.db connection
 * @param {object} opts
 * @param {'333method'|'2step'} opts.project
 * @param {string} opts.countryCode - 'AU', 'US', 'UK', 'CA', 'NZ'
 * @param {'budget'|'standard'|'premium'} opts.nicheTier
 * @param {string} [opts.tablePrefix] - table prefix if querying via ATTACH (e.g. 'msgs.')
 * @returns {object|null} pricing row or null
 */
export function getPricingRow(db, { project, countryCode, nicheTier, tablePrefix = '' }) {
  const table = `${tablePrefix}pricing`;
  return db
    .prepare(
      `SELECT * FROM ${table}
       WHERE project = ? AND country_code = ? AND niche_tier = ? AND superseded_at IS NULL
       LIMIT 1`
    )
    .get(project, countryCode.toUpperCase(), nicheTier) || null;
}

/**
 * Get pricing row via niche name (joins niche_tiers to resolve tier).
 *
 * @param {import('better-sqlite3').Database} db
 * @param {object} opts
 * @param {'333method'|'2step'} opts.project
 * @param {string} opts.countryCode
 * @param {string} opts.niche - e.g. 'pest control', 'plumber'
 * @param {string} [opts.tablePrefix]
 * @returns {object|null}
 */
export function getPricingRowByNiche(db, { project, countryCode, niche, tablePrefix = '' }) {
  const pricingTable = `${tablePrefix}pricing`;
  const nicheTiersTable = `${tablePrefix}niche_tiers`;
  return db
    .prepare(
      `SELECT p.* FROM ${pricingTable} p
       JOIN ${nicheTiersTable} n ON n.tier = p.niche_tier
       WHERE p.project = ? AND p.country_code = ? AND n.niche = ? AND p.superseded_at IS NULL
       LIMIT 1`
    )
    .get(project, countryCode.toUpperCase(), niche.toLowerCase()) || null;
}

/**
 * Get formatted display pricing for outreach messages.
 * Returns the values needed to render price in emails/SMS.
 *
 * @param {import('better-sqlite3').Database} db
 * @param {object} opts
 * @param {'333method'|'2step'} opts.project
 * @param {string} opts.countryCode
 * @param {'budget'|'standard'|'premium'} opts.nicheTier
 * @param {string} [opts.tablePrefix]
 * @returns {object|null}
 */
export function getPricingDisplay(db, { project, countryCode, nicheTier, tablePrefix = '' }) {
  const row = getPricingRow(db, { project, countryCode, nicheTier, tablePrefix });
  if (!row) return null;

  const currencySymbols = { AUD: 'A$', USD: '$', GBP: '£', CAD: 'CA$', NZD: 'NZ$' };
  const symbol = currencySymbols[row.currency] || row.currency;

  return {
    pricingId: row.id,
    currency: row.currency,
    symbol,
    // 333Method fields
    reportPrice: row.report_price,
    reportFormatted: row.report_price ? `${symbol}${row.report_price}` : null,
    // 2Step fields
    setupFee: row.setup_local,
    setupFormatted: row.setup_local ? `${symbol}${row.setup_local}` : null,
    monthly4: row.monthly_4,
    monthly4Formatted: row.monthly_4 ? `${symbol}${row.monthly_4}/mo` : null,
    monthly8: row.monthly_8,
    monthly8Formatted: row.monthly_8 ? `${symbol}${row.monthly_8}/mo` : null,
    monthly12: row.monthly_12,
    monthly12Formatted: row.monthly_12 ? `${symbol}${row.monthly_12}/mo` : null,
  };
}

/**
 * Seed 333Method pricing rows into messages.db.
 * Safe to run multiple times — skips rows that already exist.
 *
 * @param {import('better-sqlite3').Database} db - messages.db connection
 */
export function seed333MethodPricing(db) {
  const rows = [
    // AU
    { project: '333method', country_code: 'AU', niche_tier: 'budget',    report_price: 297, currency: 'AUD' },
    { project: '333method', country_code: 'AU', niche_tier: 'standard',  report_price: 337, currency: 'AUD' },
    { project: '333method', country_code: 'AU', niche_tier: 'premium',   report_price: 397, currency: 'AUD' },
    // US
    { project: '333method', country_code: 'US', niche_tier: 'budget',    report_price: 197, currency: 'USD' },
    { project: '333method', country_code: 'US', niche_tier: 'standard',  report_price: 297, currency: 'USD' },
    { project: '333method', country_code: 'US', niche_tier: 'premium',   report_price: 397, currency: 'USD' },
    // UK
    { project: '333method', country_code: 'UK', niche_tier: 'budget',    report_price: 129, currency: 'GBP' },
    { project: '333method', country_code: 'UK', niche_tier: 'standard',  report_price: 159, currency: 'GBP' },
    { project: '333method', country_code: 'UK', niche_tier: 'premium',   report_price: 197, currency: 'GBP' },
    // CA
    { project: '333method', country_code: 'CA', niche_tier: 'budget',    report_price: 249, currency: 'CAD' },
    { project: '333method', country_code: 'CA', niche_tier: 'standard',  report_price: 297, currency: 'CAD' },
    { project: '333method', country_code: 'CA', niche_tier: 'premium',   report_price: 349, currency: 'CAD' },
    // NZ
    { project: '333method', country_code: 'NZ', niche_tier: 'budget',    report_price: 329, currency: 'NZD' },
    { project: '333method', country_code: 'NZ', niche_tier: 'standard',  report_price: 397, currency: 'NZD' },
    { project: '333method', country_code: 'NZ', niche_tier: 'premium',   report_price: 447, currency: 'NZD' },
  ];

  const insert = db.prepare(
    `INSERT OR IGNORE INTO pricing
     (project, country_code, niche_tier, setup_local, monthly_4, monthly_8, monthly_12, report_price, currency, effective_from)
     VALUES (?, ?, ?, NULL, NULL, NULL, NULL, ?, ?, date('now'))`
  );

  const seedMany = db.transaction((rows) => {
    for (const r of rows) {
      insert.run(r.project, r.country_code, r.niche_tier, r.report_price, r.currency);
    }
  });

  seedMany(rows);
}

/**
 * Seed 2Step pricing rows into messages.db.
 * Safe to run multiple times — skips rows that already exist.
 *
 * @param {import('better-sqlite3').Database} db - messages.db connection
 */
export function seed2StepPricing(db) {
  const rows = [
    // AU
    { country_code: 'AU', niche_tier: 'budget',   setup_local: 699,  monthly_4: 139, monthly_8: 249, monthly_12: 349, currency: 'AUD' },
    { country_code: 'AU', niche_tier: 'standard',  setup_local: 899,  monthly_4: 139, monthly_8: 249, monthly_12: 349, currency: 'AUD' },
    { country_code: 'AU', niche_tier: 'premium',   setup_local: 1099, monthly_4: 139, monthly_8: 249, monthly_12: 349, currency: 'AUD' },
    // US
    { country_code: 'US', niche_tier: 'budget',   setup_local: 497,  monthly_4: 99,  monthly_8: 179, monthly_12: 249, currency: 'USD' },
    { country_code: 'US', niche_tier: 'standard',  setup_local: 625,  monthly_4: 99,  monthly_8: 179, monthly_12: 249, currency: 'USD' },
    { country_code: 'US', niche_tier: 'premium',   setup_local: 750,  monthly_4: 99,  monthly_8: 179, monthly_12: 249, currency: 'USD' },
    // UK
    { country_code: 'UK', niche_tier: 'budget',   setup_local: 389,  monthly_4: 79,  monthly_8: 139, monthly_12: 199, currency: 'GBP' },
    { country_code: 'UK', niche_tier: 'standard',  setup_local: 489,  monthly_4: 79,  monthly_8: 139, monthly_12: 199, currency: 'GBP' },
    { country_code: 'UK', niche_tier: 'premium',   setup_local: 589,  monthly_4: 79,  monthly_8: 139, monthly_12: 199, currency: 'GBP' },
    // CA
    { country_code: 'CA', niche_tier: 'budget',   setup_local: 669,  monthly_4: 129, monthly_8: 229, monthly_12: 329, currency: 'CAD' },
    { country_code: 'CA', niche_tier: 'standard',  setup_local: 849,  monthly_4: 129, monthly_8: 229, monthly_12: 329, currency: 'CAD' },
    { country_code: 'CA', niche_tier: 'premium',   setup_local: 1019, monthly_4: 129, monthly_8: 229, monthly_12: 329, currency: 'CAD' },
    // NZ
    { country_code: 'NZ', niche_tier: 'budget',   setup_local: 789,  monthly_4: 149, monthly_8: 279, monthly_12: 389, currency: 'NZD' },
    { country_code: 'NZ', niche_tier: 'standard',  setup_local: 989,  monthly_4: 149, monthly_8: 279, monthly_12: 389, currency: 'NZD' },
    { country_code: 'NZ', niche_tier: 'premium',   setup_local: 1189, monthly_4: 149, monthly_8: 279, monthly_12: 389, currency: 'NZD' },
  ];

  const insert = db.prepare(
    `INSERT OR IGNORE INTO pricing
     (project, country_code, niche_tier, setup_local, monthly_4, monthly_8, monthly_12, report_price, currency, effective_from)
     VALUES ('2step', ?, ?, ?, ?, ?, ?, NULL, ?, date('now'))`
  );

  const seedMany = db.transaction((rows) => {
    for (const r of rows) {
      insert.run(
        r.country_code, r.niche_tier,
        r.setup_local, r.monthly_4, r.monthly_8, r.monthly_12,
        r.currency
      );
    }
  });

  seedMany(rows);
}

export default { getPricingRow, getPricingRowByNiche, getPricingDisplay, seed333MethodPricing, seed2StepPricing };
