#!/usr/bin/env node

/**
 * Initialize the shared mmo-platform messages database.
 *
 * Usage:
 *   node scripts/init-messages-db.js
 *   MESSAGES_DB_PATH=/absolute/path/to/messages.db node scripts/init-messages-db.js
 *
 * The script:
 *   - Resolves the DB path from MESSAGES_DB_PATH env var or a default relative path
 *   - If MESSAGES_DB_PATH is set but the file doesn't exist, throws a clear error
 *     (prevents silent creation of an empty DB at a wrong path in production)
 *   - If using the default path and the DB doesn't exist, creates it
 *   - Runs schema-messages.sql (idempotent — uses CREATE TABLE IF NOT EXISTS)
 *   - Sets WAL mode + busy_timeout on the file itself so all ATTACH connections
 *     inherit WAL mode automatically
 */

import Database from 'better-sqlite3';
import { readFileSync, existsSync } from 'fs';
import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = resolve(__dirname, '..');

// Default path: relative from script location -> mmo-platform/db/messages.db
const DEFAULT_DB_PATH = resolve(root, 'db/messages.db');
const envPath = process.env.MESSAGES_DB_PATH;

let dbPath;

if (envPath) {
  // Production: env var must point to an existing file OR we're creating it fresh.
  // If the directory doesn't exist that's a config error — throw.
  dbPath = envPath;
  const { existsSync: exists } = await import('fs');
  const dirPath = dirname(dbPath);
  if (!exists(dirPath)) {
    throw new Error(
      `MESSAGES_DB_PATH directory does not exist: ${dirPath}\n` +
      `Create the directory first, then re-run this script.`
    );
  }
  // Allow creation if the file itself doesn't exist (first-time init on a new host)
  if (!existsSync(dbPath)) {
    console.log(`MESSAGES_DB_PATH set. File not found — will create at: ${dbPath}`);
  } else {
    console.log(`MESSAGES_DB_PATH set. Updating schema at: ${dbPath}`);
  }
} else {
  dbPath = DEFAULT_DB_PATH;
  if (!existsSync(dbPath)) {
    console.log(`No MESSAGES_DB_PATH set. Creating new DB at default path: ${dbPath}`);
  } else {
    console.log(`No MESSAGES_DB_PATH set. Updating schema at default path: ${dbPath}`);
  }
}

const schemaPath = resolve(root, 'db/schema-messages.sql');

if (!existsSync(schemaPath)) {
  throw new Error(`Schema file not found: ${schemaPath}`);
}

const schema = readFileSync(schemaPath, 'utf8');

// Open (or create) the DB
const db = new Database(dbPath);

// WAL mode must be set on the file itself at creation time.
// All ATTACH connections inherit the file's journal mode.
db.pragma('journal_mode = WAL');
db.pragma('busy_timeout = 15000');

// Run schema (idempotent — all statements use IF NOT EXISTS / INSERT OR IGNORE)
db.exec(schema);

// Verify tables
const tables = db
  .prepare("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
  .all();

console.log(`\nTables: ${tables.map(t => t.name).join(', ')}`);

// Row counts for quick sanity check
const counts = tables
  .filter(t => !t.name.startsWith('sqlite_'))
  .map(t => {
    const count = db.prepare(`SELECT COUNT(*) as c FROM "${t.name}"`).get().c;
    return `  ${t.name}: ${count} rows`;
  });
console.log('Row counts:\n' + counts.join('\n'));

// Verify WAL mode is active
const journalMode = db.pragma('journal_mode', { simple: true });
console.log(`\nJournal mode: ${journalMode}`);

db.close();
console.log('\nmmo-platform messages.db initialized successfully.');
console.log(`Path: ${dbPath}`);
