import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import Database from 'better-sqlite3';
import {
  getPricingRow,
  getPricingRowByNiche,
  getPricingDisplay,
  seed333MethodPricing,
  seed2StepPricing,
  getMessagesDb,
} from '../../src/pricing.js';

function createTestDb() {
  const db = new Database(':memory:');
  db.exec(`
    CREATE TABLE pricing (
      id           INTEGER PRIMARY KEY AUTOINCREMENT,
      project      TEXT NOT NULL,
      country_code TEXT NOT NULL,
      niche_tier   TEXT NOT NULL CHECK(niche_tier IN ('budget', 'standard', 'premium')),
      setup_local  REAL,
      monthly_4    REAL,
      monthly_8    REAL,
      monthly_12   REAL,
      report_price REAL,
      currency     TEXT NOT NULL,
      effective_from TEXT NOT NULL DEFAULT (date('now')),
      superseded_at  TEXT,
      UNIQUE (project, country_code, niche_tier, effective_from)
    );

    CREATE TABLE niche_tiers (
      id    INTEGER PRIMARY KEY AUTOINCREMENT,
      niche TEXT NOT NULL,
      tier  TEXT NOT NULL CHECK(tier IN ('budget', 'standard', 'premium'))
    );
  `);
  return db;
}

describe('pricing', () => {
  let db;

  beforeEach(() => {
    db = createTestDb();
  });

  afterEach(() => {
    db.close();
  });

  // ── getPricingRow ──────────────────────────────────────────────────────

  describe('getPricingRow', () => {
    it('returns null when no matching row exists', () => {
      const row = getPricingRow(db, {
        project: '333method',
        countryCode: 'AU',
        nicheTier: 'standard',
      });
      expect(row).toBeNull();
    });

    it('returns the active pricing row', () => {
      db.exec(`
        INSERT INTO pricing (project, country_code, niche_tier, report_price, currency)
        VALUES ('333method', 'AU', 'standard', 337, 'AUD');
      `);

      const row = getPricingRow(db, {
        project: '333method',
        countryCode: 'AU',
        nicheTier: 'standard',
      });

      expect(row).not.toBeNull();
      expect(row.project).toBe('333method');
      expect(row.country_code).toBe('AU');
      expect(row.niche_tier).toBe('standard');
      expect(row.report_price).toBe(337);
      expect(row.currency).toBe('AUD');
    });

    it('ignores superseded rows', () => {
      db.exec(`
        INSERT INTO pricing (project, country_code, niche_tier, report_price, currency, superseded_at)
        VALUES ('333method', 'AU', 'standard', 300, 'AUD', '2026-01-01');
      `);

      const row = getPricingRow(db, {
        project: '333method',
        countryCode: 'AU',
        nicheTier: 'standard',
      });

      expect(row).toBeNull();
    });

    it('normalises country code to uppercase', () => {
      db.exec(`
        INSERT INTO pricing (project, country_code, niche_tier, report_price, currency)
        VALUES ('333method', 'UK', 'budget', 129, 'GBP');
      `);

      const row = getPricingRow(db, {
        project: '333method',
        countryCode: 'uk',
        nicheTier: 'budget',
      });

      expect(row).not.toBeNull();
      expect(row.report_price).toBe(129);
    });

    it('supports tablePrefix for ATTACH queries', () => {
      // Simulate a prefixed table by creating it under a different name
      // In real usage this is for ATTACH'd databases, but we test the SQL generation
      db.exec(`
        CREATE TABLE prefixed_pricing (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          project TEXT NOT NULL,
          country_code TEXT NOT NULL,
          niche_tier TEXT NOT NULL,
          setup_local REAL, monthly_4 REAL, monthly_8 REAL, monthly_12 REAL,
          report_price REAL,
          currency TEXT NOT NULL,
          effective_from TEXT NOT NULL DEFAULT (date('now')),
          superseded_at TEXT
        );
        INSERT INTO prefixed_pricing (project, country_code, niche_tier, report_price, currency)
        VALUES ('333method', 'US', 'standard', 297, 'USD');
      `);

      const row = getPricingRow(db, {
        project: '333method',
        countryCode: 'US',
        nicheTier: 'standard',
        tablePrefix: 'prefixed_',
      });

      expect(row).not.toBeNull();
      expect(row.report_price).toBe(297);
    });
  });

  // ── getPricingRowByNiche ───────────────────────────────────────────────

  describe('getPricingRowByNiche', () => {
    beforeEach(() => {
      db.exec(`
        INSERT INTO niche_tiers (niche, tier) VALUES ('pest control', 'standard');
        INSERT INTO niche_tiers (niche, tier) VALUES ('plumber', 'budget');
        INSERT INTO niche_tiers (niche, tier) VALUES ('dentist', 'premium');

        INSERT INTO pricing (project, country_code, niche_tier, report_price, currency)
        VALUES ('333method', 'AU', 'standard', 337, 'AUD');
        INSERT INTO pricing (project, country_code, niche_tier, report_price, currency)
        VALUES ('333method', 'AU', 'budget', 297, 'AUD');
        INSERT INTO pricing (project, country_code, niche_tier, report_price, currency)
        VALUES ('333method', 'AU', 'premium', 397, 'AUD');
      `);
    });

    it('resolves pricing via niche name', () => {
      const row = getPricingRowByNiche(db, {
        project: '333method',
        countryCode: 'AU',
        niche: 'pest control',
      });

      expect(row).not.toBeNull();
      expect(row.niche_tier).toBe('standard');
      expect(row.report_price).toBe(337);
    });

    it('normalises niche to lowercase', () => {
      const row = getPricingRowByNiche(db, {
        project: '333method',
        countryCode: 'AU',
        niche: 'PEST CONTROL',
      });

      expect(row).not.toBeNull();
      expect(row.report_price).toBe(337);
    });

    it('returns null for unknown niche', () => {
      const row = getPricingRowByNiche(db, {
        project: '333method',
        countryCode: 'AU',
        niche: 'astronaut',
      });

      expect(row).toBeNull();
    });

    it('ignores superseded pricing rows', () => {
      db.exec(`UPDATE pricing SET superseded_at = '2026-01-01' WHERE niche_tier = 'standard'`);

      const row = getPricingRowByNiche(db, {
        project: '333method',
        countryCode: 'AU',
        niche: 'pest control',
      });

      expect(row).toBeNull();
    });
  });

  // ── getPricingDisplay ──────────────────────────────────────────────────

  describe('getPricingDisplay', () => {
    it('returns null when no pricing row found', () => {
      const display = getPricingDisplay(db, {
        project: '333method',
        countryCode: 'AU',
        nicheTier: 'standard',
      });

      expect(display).toBeNull();
    });

    it('formats 333method pricing with report price', () => {
      db.exec(`
        INSERT INTO pricing (project, country_code, niche_tier, report_price, currency)
        VALUES ('333method', 'AU', 'standard', 337, 'AUD');
      `);

      const display = getPricingDisplay(db, {
        project: '333method',
        countryCode: 'AU',
        nicheTier: 'standard',
      });

      expect(display).not.toBeNull();
      expect(display.currency).toBe('AUD');
      expect(display.symbol).toBe('A$');
      expect(display.reportPrice).toBe(337);
      expect(display.reportFormatted).toBe('A$337');
      // 333method doesn't have setup/monthly (DB returns null)
      expect(display.setupFee).toBeNull();
      expect(display.setupFormatted).toBeNull();
    });

    it('formats 2step pricing with setup and monthly tiers', () => {
      db.exec(`
        INSERT INTO pricing (project, country_code, niche_tier, setup_local, monthly_4, monthly_8, monthly_12, currency)
        VALUES ('2step', 'US', 'standard', 625, 99, 179, 249, 'USD');
      `);

      const display = getPricingDisplay(db, {
        project: '2step',
        countryCode: 'US',
        nicheTier: 'standard',
      });

      expect(display).not.toBeNull();
      expect(display.currency).toBe('USD');
      expect(display.symbol).toBe('$');
      expect(display.setupFee).toBe(625);
      expect(display.setupFormatted).toBe('$625');
      expect(display.monthly4).toBe(99);
      expect(display.monthly4Formatted).toBe('$99/mo');
      expect(display.monthly8).toBe(179);
      expect(display.monthly8Formatted).toBe('$179/mo');
      expect(display.monthly12).toBe(249);
      expect(display.monthly12Formatted).toBe('$249/mo');
    });

    it('uses correct symbols for each currency', () => {
      const currencies = [
        { code: 'AUD', symbol: 'A$', country: 'AU' },
        { code: 'USD', symbol: '$', country: 'US' },
        { code: 'GBP', symbol: '£', country: 'UK' },
        { code: 'CAD', symbol: 'CA$', country: 'CA' },
        { code: 'NZD', symbol: 'NZ$', country: 'NZ' },
      ];

      for (const { code, symbol, country } of currencies) {
        db.exec(`
          INSERT INTO pricing (project, country_code, niche_tier, report_price, currency, effective_from)
          VALUES ('333method', '${country}', 'standard', 100, '${code}', '2026-01-0${currencies.indexOf({ code, symbol, country }) + 1}');
        `);

        const display = getPricingDisplay(db, {
          project: '333method',
          countryCode: country,
          nicheTier: 'standard',
        });

        expect(display.symbol).toBe(symbol);
        expect(display.reportFormatted).toBe(`${symbol}100`);
      }
    });

    it('falls back to currency code for unknown currencies', () => {
      db.exec(`
        INSERT INTO pricing (project, country_code, niche_tier, report_price, currency)
        VALUES ('333method', 'IE', 'standard', 200, 'EUR');
      `);

      const display = getPricingDisplay(db, {
        project: '333method',
        countryCode: 'IE',
        nicheTier: 'standard',
      });

      expect(display.symbol).toBe('EUR');
      expect(display.reportFormatted).toBe('EUR200');
    });

    it('returns pricingId from the row', () => {
      db.exec(`
        INSERT INTO pricing (project, country_code, niche_tier, report_price, currency)
        VALUES ('333method', 'AU', 'budget', 297, 'AUD');
      `);

      const display = getPricingDisplay(db, {
        project: '333method',
        countryCode: 'AU',
        nicheTier: 'budget',
      });

      expect(display.pricingId).toBeTypeOf('number');
      expect(display.pricingId).toBeGreaterThan(0);
    });
  });

  // ── seed333MethodPricing ───────────────────────────────────────────────

  describe('seed333MethodPricing', () => {
    it('seeds all 15 pricing rows (5 countries × 3 tiers)', () => {
      seed333MethodPricing(db);

      const count = db.prepare('SELECT COUNT(*) as c FROM pricing WHERE project = ?').get('333method').c;
      expect(count).toBe(15);
    });

    it('is idempotent — running twice does not duplicate rows', () => {
      seed333MethodPricing(db);
      seed333MethodPricing(db);

      const count = db.prepare('SELECT COUNT(*) as c FROM pricing WHERE project = ?').get('333method').c;
      expect(count).toBe(15);
    });

    it('seeds correct AU standard price', () => {
      seed333MethodPricing(db);

      const row = getPricingRow(db, {
        project: '333method',
        countryCode: 'AU',
        nicheTier: 'standard',
      });

      expect(row.report_price).toBe(337);
      expect(row.currency).toBe('AUD');
    });

    it('seeds correct UK budget price', () => {
      seed333MethodPricing(db);

      const row = getPricingRow(db, {
        project: '333method',
        countryCode: 'UK',
        nicheTier: 'budget',
      });

      expect(row.report_price).toBe(129);
      expect(row.currency).toBe('GBP');
    });

    it('seeds all countries', () => {
      seed333MethodPricing(db);

      const countries = db
        .prepare('SELECT DISTINCT country_code FROM pricing WHERE project = ? ORDER BY country_code')
        .all('333method')
        .map(r => r.country_code);

      expect(countries).toEqual(['AU', 'CA', 'NZ', 'UK', 'US']);
    });
  });

  // ── seed2StepPricing ───────────────────────────────────────────────────

  describe('seed2StepPricing', () => {
    it('seeds all 15 pricing rows (5 countries × 3 tiers)', () => {
      seed2StepPricing(db);

      const count = db.prepare('SELECT COUNT(*) as c FROM pricing WHERE project = ?').get('2step').c;
      expect(count).toBe(15);
    });

    it('is idempotent', () => {
      seed2StepPricing(db);
      seed2StepPricing(db);

      const count = db.prepare('SELECT COUNT(*) as c FROM pricing WHERE project = ?').get('2step').c;
      expect(count).toBe(15);
    });

    it('seeds correct US standard setup fee', () => {
      seed2StepPricing(db);

      const row = getPricingRow(db, {
        project: '2step',
        countryCode: 'US',
        nicheTier: 'standard',
      });

      expect(row.setup_local).toBe(625);
      expect(row.monthly_4).toBe(99);
      expect(row.monthly_8).toBe(179);
      expect(row.monthly_12).toBe(249);
      expect(row.currency).toBe('USD');
    });

    it('seeds correct AU premium setup fee', () => {
      seed2StepPricing(db);

      const row = getPricingRow(db, {
        project: '2step',
        countryCode: 'AU',
        nicheTier: 'premium',
      });

      expect(row.setup_local).toBe(1099);
      expect(row.currency).toBe('AUD');
    });

    it('does not interfere with 333method pricing', () => {
      seed333MethodPricing(db);
      seed2StepPricing(db);

      const methodCount = db.prepare('SELECT COUNT(*) as c FROM pricing WHERE project = ?').get('333method').c;
      const stepCount = db.prepare('SELECT COUNT(*) as c FROM pricing WHERE project = ?').get('2step').c;

      expect(methodCount).toBe(15);
      expect(stepCount).toBe(15);
    });
  });

  // ── getMessagesDb ──────────────────────────────────────────────────────

  describe('getMessagesDb', () => {
    it('returns injected db with owned=false', async () => {
      const result = await getMessagesDb(db);
      expect(result.db).toBe(db);
      expect(result.owned).toBe(false);
    });

    it('throws when MESSAGES_DB_PATH points to non-existent file and no default exists', async () => {
      const origEnv = process.env.MESSAGES_DB_PATH;
      process.env.MESSAGES_DB_PATH = '/tmp/nonexistent-test-path/messages.db';

      await expect(getMessagesDb(null)).rejects.toThrow('messages.db not found');

      process.env.MESSAGES_DB_PATH = origEnv;
    });
  });
});
