/**
 * Vitest config for PayPal webhook E2E suite (DR-215).
 *
 * Key choices:
 *  - testTimeout: 30_000  — Miniflare cold-starts + PHP server spawn can take
 *    several seconds on first boot.
 *  - pool: 'forks'        — PHP CLI subprocesses, Miniflare instances, and
 *    better-sqlite3 native handles don't coexist safely with worker threads
 *    sharing the same module graph. Forks give each test file its own process.
 *  - setupFiles           — vitest.setup.js provisions the crai_test / m333_test
 *    Postgres schemas beforeAll and drops them afterAll.
 */

import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    include: ['tests/**/*.test.js'],
    exclude: ['node_modules/**', 'tmp/**'],
    setupFiles: ['./vitest.setup.js'],
    testTimeout: 30_000,
    hookTimeout: 30_000,
    pool: 'forks',
    // Vitest 4 removed test.poolOptions — pool-specific knobs are now top-level.
    // We intentionally allow multiple forks so separate test files don't
    // contend for the same PHP/Miniflare ports. Individual test files that
    // need singleton behaviour should serialise within-file with beforeAll.
    coverage: {
      provider: 'v8',
      reportsDirectory: './coverage',
      include: [
        // These globs are intentionally absolute-ish — they point at the three
        // handlers living in sibling repos. v8 coverage still works when paths
        // fall outside the nominal project root.
        '../../../../auditandfix-website/site/api.php',
        '../../../../ContactReplyAI/workers/index.js',
        '../../../../333Method/workers/paypal-webhook/src/index.js',
        '../../../../333Method/src/payment/webhook-handler.js',
        '../../../../333Method/src/payment/poll-paypal-events.js',
      ],
      exclude: ['node_modules/**', 'tests/**', 'helpers/**', 'fixtures/**'],
    },
  },
});
