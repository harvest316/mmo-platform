/**
 * SQLite fixture + inspection helpers for api.php tests.
 *
 * api.php keeps two SQLite files under <siteDir>/data/:
 *   - subscriptions.sqlite          (live mode, 2Step)
 *   - subscriptions-sandbox.sqlite  (sandbox mode, 2Step, DR-214)
 *   - crai-subscriptions.sqlite / crai-subscriptions-sandbox.sqlite
 *     (not currently populated by handlePayPalWebhook() because CRAI uses
 *      the return-URL GET + the CRAI Worker webhook, but a helper is provided
 *      for future coverage — the schema is created on first open by
 *      getCraiSubscriptionDb() in api.php.)
 *
 * Tests typically:
 *   1. Call createTestSiteDir() to get a temp directory that becomes SITE_PATH.
 *   2. Launch php -S with SITE_PATH=<that dir>.
 *   3. After firing a webhook, call readSubscriptions({ sandbox: true/false })
 *      to assert rows.
 */

import Database from 'better-sqlite3';
import { mkdtemp, mkdir, rm, cp } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/**
 * Create an isolated per-test site directory. Returns an absolute path.
 *
 * If `sourceSiteDir` is provided, its tree is copied so api.php + its
 * includes are available under the returned path. Otherwise only `data/` is
 * created and the caller is expected to point SITE_PATH at the tmp dir while
 * keeping the PHP docroot at the real site.
 *
 * @param {object} [opts]
 * @param {string} [opts.sourceSiteDir]  Optional absolute path to copy.
 * @returns {Promise<{ dir: string, dataDir: string, cleanup: () => Promise<void> }>}
 */
export async function createTestSiteDir({ sourceSiteDir } = {}) {
  const dir = await mkdtemp(join(tmpdir(), 'paypal-e2e-'));
  const dataDir = join(dir, 'data');
  await mkdir(dataDir, { recursive: true });

  if (sourceSiteDir && existsSync(sourceSiteDir)) {
    // Shallow copy everything except data/ (we want an empty data dir)
    await cp(sourceSiteDir, dir, {
      recursive: true,
      filter: (src) => !src.includes('/data/') && !src.endsWith('/data'),
    });
    await mkdir(dataDir, { recursive: true });
  }

  return {
    dir,
    dataDir,
    cleanup: async () => {
      await rm(dir, { recursive: true, force: true });
    },
  };
}

/**
 * Path resolution for the 2Step subscription DB. Matches getSubscriptionDb()
 * in auditandfix-website/site/api.php:1241.
 */
export function subscriptionDbPath(siteDir, { sandbox = false } = {}) {
  const file = sandbox ? 'subscriptions-sandbox.sqlite' : 'subscriptions.sqlite';
  return join(siteDir, 'data', file);
}

/**
 * Path resolution for the CRAI subscription DB (api.php:getCraiSubscriptionDb).
 */
export function craiSubscriptionDbPath(siteDir, { sandbox = false } = {}) {
  const file = sandbox ? 'crai-subscriptions-sandbox.sqlite' : 'crai-subscriptions.sqlite';
  return join(siteDir, 'data', file);
}

/**
 * Open a read-only handle to the 2Step subscription DB and return all rows.
 * Returns `[]` if the file doesn't exist yet (no webhooks processed).
 */
export function readSubscriptions(siteDir, { sandbox = false } = {}) {
  const p = subscriptionDbPath(siteDir, { sandbox });
  if (!existsSync(p)) return [];
  const db = new Database(p, { readonly: true, fileMustExist: true });
  try {
    return db.prepare('SELECT * FROM subscriptions ORDER BY id').all();
  } finally {
    db.close();
  }
}

/**
 * Find a specific subscription row by its paypal_subscription_id.
 */
export function findSubscription(siteDir, id, { sandbox = false } = {}) {
  const p = subscriptionDbPath(siteDir, { sandbox });
  if (!existsSync(p)) return null;
  const db = new Database(p, { readonly: true, fileMustExist: true });
  try {
    return db
      .prepare('SELECT * FROM subscriptions WHERE paypal_subscription_id = ?')
      .get(id) ?? null;
  } finally {
    db.close();
  }
}

/**
 * Return CRAI subscription rows from api.php's CRAI ledger (mostly unused by
 * the 2Step webhook path today — kept for future coverage of the return-URL
 * CRAI handler).
 */
export function readCraiSubscriptions(siteDir, { sandbox = false } = {}) {
  const p = craiSubscriptionDbPath(siteDir, { sandbox });
  if (!existsSync(p)) return [];
  const db = new Database(p, { readonly: true, fileMustExist: true });
  try {
    return db.prepare('SELECT * FROM crai_subscriptions ORDER BY id').all();
  } finally {
    db.close();
  }
}
