/**
 * HTTP bridge that speaks Neon's serverless-driver `/sql` protocol on top of
 * a local Postgres (via node-postgres). Used by Miniflare-hosted Workers whose
 * only DB access path is `neon(DATABASE_URL)` — those Workers POST JSON to
 * `https://<dbHost>:<dbPort>/sql`, which we catch via Miniflare's
 * `outboundService` and forward to this bridge.
 *
 * Protocol summary (per @neondatabase/serverless v1.x):
 *   POST /sql
 *   Headers:
 *     Neon-Connection-String: <DATABASE_URL>
 *     Neon-Raw-Text-Output:   true
 *     Neon-Array-Mode:        true
 *     Authorization:          Bearer <jwt>   (optional — ignored locally)
 *   Body:
 *     Single query:    { query: "SELECT $1::int", params: [1] }
 *     Batch:           { queries: [{query, params}, ...] }
 *
 * Response on success (array of rows expected by the driver — rows each
 * shaped as an array because Neon-Array-Mode is `true`):
 *   Single query: { fields: [{name, dataTypeID}, ...], rows: [[...]], rowCount, command, rowAsArray: true }
 *   Batch:        { results: [<same shape>, ...] }
 *
 * Schema rewrite:
 *   The CRAI Worker uses qualified `crai.*` table names. For tests we target
 *   a `crai_test` schema (production `crai` lives on the same server and must
 *   not be touched). Each inbound query string has `\bcrai\.` rewritten to
 *   `crai_test.` before execution. The Worker doesn't emit any literals that
 *   would collide with this substitution.
 */

import { createServer } from 'node:http';
import pg from 'pg';

const { Pool, types: pgTypes } = pg;

/**
 * Neon's driver sets `Neon-Raw-Text-Output: true` on its /sql requests and
 * relies on dataTypeID + its own parsers to coerce values. We therefore need
 * raw text from Postgres — NOT pg's default JS-side parsing. We install a
 * noop type parser that returns each value verbatim.
 */
const rawTextTypes = {
  getTypeParser: () => (val) => val,
};

/**
 * Minimal OID→name map for fields PayPal tests care about. Neon-Raw-Text-Output
 * is set to `true` by the driver, which means all values come back as strings
 * and the driver's own type coercion handles parsing via dataTypeID. We only
 * need to return the right dataTypeID for the column.
 *
 * node-postgres returns this via Field.dataTypeID already — we just pass it
 * through from `result.fields`.
 */

/**
 * Rewrite schema-qualified references. Worker emits queries like
 *   `SELECT ... FROM crai.tenants WHERE ...`
 * We substitute schema `crai` → `crai_test` so the test schema is hit.
 */
function rewriteSchema(sql, fromSchema, toSchema) {
  const re = new RegExp(`\\b${fromSchema}\\.`, 'g');
  return sql.replace(re, `${toSchema}.`);
}

/**
 * Run a single parameterised query. Returns Neon-style response object.
 */
async function runQuery(pool, { query, params }, { fromSchema, toSchema }) {
  const rewritten = rewriteSchema(query, fromSchema, toSchema);
  // Neon driver already converts params via prepareValue() before sending —
  // strings (including arrays serialised as Postgres array literals) come
  // through. node-postgres accepts the same for its `values` argument.
  const res = await pool.query({
    text: rewritten,
    values: params,
    rowMode: 'array',   // matches Neon-Array-Mode: true
    types: rawTextTypes, // return raw text — neon parses on its side
  });

  // Neon-Raw-Text-Output means values should be strings — disable the default
  // pg-types parsers. We do this by cloning the result rows and re-stringifying
  // non-null values. But `rowMode: 'array'` + default parsers actually runs
  // parsers already. For this test's needs (no timestamp math, no bigint), the
  // downstream driver will re-apply parsers anyway based on dataTypeID, so we
  // pass already-parsed values and the driver stringifies them via JSON.
  //
  // NOTE: this is a known limitation — for tests needing byte-accurate text
  // output from Postgres, we'd need to disable parsers. The CRAI webhook
  // handler only needs `id`, `billing_plan`, `email` back, so string values
  // are fine.

  return {
    command: res.command,
    rowCount: res.rowCount ?? 0,
    rowAsArray: true,
    // With `types: rawTextTypes`, every non-null value comes back as the raw
    // text Postgres sent — exactly what Neon's own parsers expect.
    rows: res.rows.map((row) => row.map((v) => (v == null ? null : v))),
    fields: res.fields.map((f) => ({
      name: f.name,
      dataTypeID: f.dataTypeID,
    })),
  };
}

/**
 * Start an HTTP server that speaks the Neon `/sql` protocol on top of a pg.Pool.
 *
 * @param {object} opts
 * @param {string} [opts.connectionString]   Postgres DSN. Defaults to unix socket on /run/postgresql.
 * @param {string} [opts.fromSchema='crai']  Schema name the Worker queries against.
 * @param {string} [opts.toSchema='crai_test'] Schema name in the test DB.
 * @returns {Promise<{ url: string, host: string, port: number, close: () => Promise<void> }>}
 */
export async function startNeonHttpBridge(opts = {}) {
  const {
    connectionString = process.env.PG_TEST_URL ??
      'postgresql:///mmo?host=/run/postgresql',
    fromSchema = 'crai',
    toSchema = 'crai_test',
  } = opts;

  const pool = new Pool({ connectionString, max: 4 });

  const server = createServer(async (req, res) => {
    if (req.method !== 'POST' || !req.url.endsWith('/sql')) {
      res.statusCode = 404;
      res.end('not found');
      return;
    }
    try {
      const chunks = [];
      for await (const c of req) chunks.push(c);
      const raw = Buffer.concat(chunks).toString('utf8');
      const body = JSON.parse(raw);

      let out;
      if (Array.isArray(body.queries)) {
        const results = [];
        for (const q of body.queries) {
          results.push(await runQuery(pool, q, { fromSchema, toSchema }));
        }
        out = { results };
      } else {
        out = await runQuery(pool, body, { fromSchema, toSchema });
      }

      res.statusCode = 200;
      res.setHeader('content-type', 'application/json');
      res.end(JSON.stringify(out));
    } catch (err) {
      // Neon driver surfaces 400 responses by parsing `message` out of the
      // body — match that shape so errors propagate cleanly.
      res.statusCode = 400;
      res.setHeader('content-type', 'application/json');
      res.end(
        JSON.stringify({
          message: err.message,
          code: err.code || 'XX000',
          severity: 'ERROR',
        }),
      );
    }
  });

  await new Promise((resolve) => server.listen(0, '127.0.0.2', resolve));
  const addr = server.address();

  return {
    url: `http://127.0.0.2:${addr.port}`,
    host: '127.0.0.2',
    port: addr.port,
    async close() {
      await new Promise((resolve) => server.close(resolve));
      try { await pool.end(); } catch {}
    },
  };
}
