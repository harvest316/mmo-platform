// @ts-check
import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright config for auditandfix.com E2E tests.
 *
 * By default, tests run against the live site: https://auditandfix.com
 * Set LOCAL_TEST=1 to test against a local PHP dev server instead.
 *
 * Usage:
 *   npx playwright test tests/e2e/website.spec.js          # live site
 *   LOCAL_TEST=1 npx playwright test tests/e2e/website.spec.js  # local
 */
export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: 1,
  workers: process.env.CI ? 1 : undefined,
  reporter: 'html',
  timeout: 30_000,

  use: {
    baseURL: process.env.LOCAL_TEST
      ? (process.env.LOCAL_BASE_URL || 'http://localhost:8080')
      : 'https://auditandfix.com',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
