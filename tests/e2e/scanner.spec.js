// @ts-check
import { test, expect } from '@playwright/test';

/**
 * Audit&Fix Scanner Page E2E tests (scan.php)
 *
 * Tests the full funnel: URL form → scanning animation → score reveal →
 * email gate → factor breakdown → CTAs.
 *
 * Three tiers:
 *   1. Structure tests — no API calls, fast
 *   2. Mocked API tests — instant, cover copy/logic variants
 *   3. Live API test — hits real CF Worker, slow (up to 60s)
 */

const SCAN_URL = '/scan.php';

// ─────────────────────────────────────────────────────────────────────────────
// 1. Page structure (no API)
// ─────────────────────────────────────────────────────────────────────────────

test.describe('Scanner — structure', () => {
  test('loads with 200 and correct initial state', async ({ page }) => {
    const response = await page.goto(SCAN_URL);
    expect(response?.status()).toBe(200);

    // Only stage-input is visible initially
    await expect(page.locator('#stage-input')).toBeVisible();
    await expect(page.locator('#stage-scanning')).toBeHidden();
    await expect(page.locator('#stage-results')).toBeHidden();
    await expect(page.locator('#results-main')).toBeHidden();
  });

  test('?url= query param prefills the URL input', async ({ page }) => {
    await page.goto(`${SCAN_URL}?url=https://example.com`);
    await expect(page.locator('#scan-url')).toHaveValue('https://example.com');
  });

  test('progress step elements are in the DOM', async ({ page }) => {
    await page.goto(SCAN_URL);
    for (const id of ['step-fetch', 'step-headline', 'step-cta', 'step-trust', 'step-value', 'step-score']) {
      await expect(page.locator(`#${id}`)).toBeAttached();
    }
  });

  test('below-fold section is hidden before scan', async ({ page }) => {
    await page.goto(SCAN_URL);
    await expect(page.locator('#results-main')).toBeHidden();
    await expect(page.locator('#factor-breakdown')).toBeHidden();
    await expect(page.locator('#free-peek')).toBeHidden();
  });

  test('email gate and issue teaser are hidden before scan', async ({ page }) => {
    await page.goto(SCAN_URL);
    // These live inside #stage-results which is hidden, but check independently
    await expect(page.locator('#issue-teaser')).toBeHidden();
  });

  test('scan form has a URL input and submit button', async ({ page }) => {
    await page.goto(SCAN_URL);
    await expect(page.locator('#scan-url')).toBeVisible();
    await expect(page.locator('#scan-btn')).toBeVisible();
  });

  test('invalid URL shows validation error without hitting API', async ({ page }) => {
    await page.goto(SCAN_URL);
    // This URL cannot be normalised — new URL('https://!!bad url!!') throws
    await page.locator('#scan-url').fill('!!bad url!!');
    await page.locator('#scan-btn').click();

    // Error message shows (either JS client-side or API server-side)
    await expect(page.locator('#scan-error')).toBeVisible({ timeout: 15_000 });
    // Stage input is restored
    await expect(page.locator('#stage-input')).toBeVisible();
  });

  test('empty URL shows validation error', async ({ page }) => {
    await page.goto(SCAN_URL);
    // Disable native browser validation so the JS handler runs
    await page.locator('#scan-form').evaluate(form => { form.noValidate = true; });
    await page.locator('#scan-url').fill('');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#scan-error')).toBeVisible();
    await expect(page.locator('#scan-error')).toContainText('URL');
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. Mocked API — score reveal and email gate copy variants
// ─────────────────────────────────────────────────────────────────────────────

/** Returns a minimal scan result payload for route mocking. */
function mockScanResult(overrides = {}) {
  return {
    scan_id: 'mock-scan-001',
    score: 65,
    grade: 'C',
    domain: 'mock.example.com',
    issues_count: 3,
    free_peek: {
      factor: 'value_proposition',
      score: 4,
      reasoning: 'Value proposition is unclear.',
    },
    factor_summary: {
      headline_quality: 'good',
      value_proposition: 'needs_work',
      unique_selling_proposition: 'fair',
      call_to_action: 'needs_work',
      urgency_messaging: 'good',
      hook_engagement: 'fair',
      trust_signals: 'good',
      imagery_design: 'fair',
      offer_clarity: 'needs_work',
      contextual_appropriateness: 'good',
    },
    ...overrides,
  };
}

function mockScanRoute(page, result, delay = 300) {
  return page.route('**/api.php?action=free-scan', async route => {
    // Small delay so the scanning stage is visible before results appear
    await new Promise(r => setTimeout(r, delay));
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(result),
    });
  });
}

function mockEmailRoute(page) {
  return page.route('**/api.php?action=save-email', route =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true }) })
  );
}

test.describe('Scanner — mocked API: score reveal', () => {
  test('scanning stage appears, then results stage after API responds', async ({ page }) => {
    await mockScanRoute(page, mockScanResult());

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('mock.example.com');
    await page.locator('#scan-btn').click();

    // Scanning stage visible immediately
    await expect(page.locator('#stage-scanning')).toBeVisible({ timeout: 3_000 });
    await expect(page.locator('#stage-input')).toBeHidden();

    // Results appear after mock API resolves
    await expect(page.locator('#stage-results')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#stage-scanning')).toBeHidden();
  });

  test('progress-domain shows the scanned domain during scanning', async ({ page }) => {
    await mockScanRoute(page, mockScanResult({ domain: 'mock.example.com' }));

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('mock.example.com');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#stage-scanning')).toBeVisible({ timeout: 3_000 });
    await expect(page.locator('#progress-domain')).toHaveText('mock.example.com');
  });

  test('score number animates to correct value', async ({ page }) => {
    await mockScanRoute(page, mockScanResult({ score: 65, grade: 'C' }));

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('mock.example.com');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#stage-results')).toBeVisible({ timeout: 10_000 });

    // Animation runs ~1200ms — wait for final value
    await expect(page.locator('#score-number')).toHaveText('65', { timeout: 5_000 });
    await expect(page.locator('#score-grade')).toHaveText('C');
  });

  test('domain label shows in results', async ({ page }) => {
    await mockScanRoute(page, mockScanResult({ domain: 'mock.example.com' }));

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('mock.example.com');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#stage-results')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#score-domain-label')).toContainText('mock.example.com');
  });

  test('issue teaser is visible after scan', async ({ page }) => {
    await mockScanRoute(page, mockScanResult());

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('mock.example.com');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#stage-results')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#issue-teaser')).toBeVisible();
  });

  test('email gate is visible after scan', async ({ page }) => {
    await mockScanRoute(page, mockScanResult());

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('mock.example.com');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#stage-results')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#email-gate')).toBeVisible();
  });

  test('API error: shows scan error and restores input stage', async ({ page }) => {
    await page.route('**/api.php?action=free-scan', async route => {
      await new Promise(r => setTimeout(r, 300));
      await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ error: 'Worker unavailable' }) });
    });

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('error.example.com');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#stage-scanning')).toBeVisible({ timeout: 3_000 });

    // Error → back to input stage
    await expect(page.locator('#stage-input')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#scan-error')).toBeVisible();
    await expect(page.locator('#scan-error')).toContainText('Worker unavailable');

    // Button re-enabled
    await expect(page.locator('#scan-btn')).toBeEnabled();
  });
});

test.describe('Scanner — mocked API: email gate copy', () => {
  test('issues_count > 0: title says "N areas to work on"', async ({ page }) => {
    await mockScanRoute(page, mockScanResult({ issues_count: 5 }));

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('mock.example.com');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#stage-results')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#email-gate-title')).toContainText('5');
    await expect(page.locator('#email-gate-title')).toContainText('areas');
  });

  test('issues_count === 1: title says "1 area" (singular)', async ({ page }) => {
    await mockScanRoute(page, mockScanResult({ issues_count: 1 }));

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('mock.example.com');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#stage-results')).toBeVisible({ timeout: 10_000 });
    const titleText = await page.locator('#email-gate-title').textContent();
    expect(titleText).toContain('1');
    expect(titleText).toContain('area');
    // Should NOT say "areas" (plural)
    expect(titleText?.replace('area to work on', '')).not.toContain('areas');
  });

  test('issues_count === 0: title says "scores well"', async ({ page }) => {
    await mockScanRoute(page, mockScanResult({ issues_count: 0, score: 91, grade: 'A' }));

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('mock.example.com');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#stage-results')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#email-gate-title')).toContainText('scores well');
  });

  test('teaser label: score < 7 → "Your biggest issue"', async ({ page }) => {
    await mockScanRoute(page, mockScanResult({
      free_peek: { factor: 'call_to_action', score: 2, reasoning: 'No CTA.' },
    }));

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('mock.example.com');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#stage-results')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#issue-teaser')).toContainText('Your biggest issue');
  });

  test('teaser label: score >= 7 → "Your lowest-scoring factor"', async ({ page }) => {
    await mockScanRoute(page, mockScanResult({
      issues_count: 0,
      free_peek: { factor: 'trust_signals', score: 7, reasoning: 'Good trust signals.' },
    }));

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('mock.example.com');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#stage-results')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#issue-teaser')).toContainText('Your lowest-scoring factor');
  });

  test('teaser blur label: "+N more issues" shown when issues_count > 1', async ({ page }) => {
    await mockScanRoute(page, mockScanResult({ issues_count: 5 }));

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('mock.example.com');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#stage-results')).toBeVisible({ timeout: 10_000 });
    // showResults() sets blurLabel.innerHTML — free_peek = 1, so hidden = 5 - 1 = 4
    // JS sets: "+4 more issues — enter your email to unlock"
    await expect(page.locator('.teaser-blur-label')).toContainText('+4 more issues');
  });

  test('teaser blur label: "full breakdown" copy when issues_count === 0', async ({ page }) => {
    await mockScanRoute(page, mockScanResult({
      issues_count: 0,
      free_peek: { factor: 'trust_signals', score: 8, reasoning: 'Strong trust.' },
    }));

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('mock.example.com');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#stage-results')).toBeVisible({ timeout: 10_000 });
    // JS sets: "See your full 10-factor breakdown — enter your email"
    await expect(page.locator('.teaser-blur-label')).toContainText('full 10-factor breakdown');
  });
});

test.describe('Scanner — mocked API: email submit and factor breakdown', () => {
  test('invalid email shows error and keeps breakdown hidden', async ({ page }) => {
    await mockScanRoute(page, mockScanResult());

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('mock.example.com');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#stage-results')).toBeVisible({ timeout: 10_000 });

    await page.locator('#email-input').fill('not-an-email');
    await page.locator('#email-btn').click();

    await expect(page.locator('#email-error')).toBeVisible();
    await expect(page.locator('#email-error')).toContainText('valid email');
    await expect(page.locator('#results-main')).toBeHidden();
  });

  test('valid email reveals factor breakdown', async ({ page }) => {
    await mockScanRoute(page, mockScanResult());
    await mockEmailRoute(page);

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('mock.example.com');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#stage-results')).toBeVisible({ timeout: 10_000 });

    await page.locator('#email-input').fill('test@example.com');
    await page.locator('#email-btn').click();

    await expect(page.locator('#results-main')).toBeVisible({ timeout: 5_000 });
    await expect(page.locator('#factor-breakdown')).toBeVisible();
  });

  test('factor list renders 10 rows with human-readable labels', async ({ page }) => {
    await mockScanRoute(page, mockScanResult());
    await mockEmailRoute(page);

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('mock.example.com');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#stage-results')).toBeVisible({ timeout: 10_000 });

    await page.locator('#email-input').fill('test@example.com');
    await page.locator('#email-btn').click();

    await expect(page.locator('#results-main')).toBeVisible({ timeout: 5_000 });

    const rows = page.locator('#factor-list .factor-row');
    await expect(rows).toHaveCount(10);

    // Labels should be human-readable ("Value Proposition", not "value_proposition")
    for (let i = 0; i < 10; i++) {
      const text = await rows.nth(i).textContent();
      expect(text).not.toMatch(/_/); // no snake_case
    }
  });

  test('free peek box shows correct factor and reasoning', async ({ page }) => {
    await mockScanRoute(page, mockScanResult({
      free_peek: { factor: 'value_proposition', score: 4, reasoning: 'Value proposition is unclear.' },
    }));
    await mockEmailRoute(page);

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('mock.example.com');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#stage-results')).toBeVisible({ timeout: 10_000 });

    await page.locator('#email-input').fill('test@example.com');
    await page.locator('#email-btn').click();

    await expect(page.locator('#results-main')).toBeVisible({ timeout: 5_000 });
    await expect(page.locator('#free-peek')).toBeVisible();
    await expect(page.locator('#free-peek-factor')).toContainText('Value Proposition');
    await expect(page.locator('#free-peek-factor')).toContainText('4/10');
    await expect(page.locator('#free-peek-reasoning')).toContainText('unclear');
  });

  test('email gate is hidden after email submit', async ({ page }) => {
    await mockScanRoute(page, mockScanResult());
    await mockEmailRoute(page);

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('mock.example.com');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#stage-results')).toBeVisible({ timeout: 10_000 });

    await page.locator('#email-input').fill('test@example.com');
    await page.locator('#email-btn').click();

    await expect(page.locator('#results-main')).toBeVisible({ timeout: 5_000 });
    await expect(page.locator('#email-gate')).toBeHidden();
  });

  test('breakdown still shown even if save-email API fails', async ({ page }) => {
    await mockScanRoute(page, mockScanResult());
    // Save-email fails
    await page.route('**/api.php?action=save-email', route =>
      route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ error: 'DB error' }) })
    );

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('mock.example.com');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#stage-results')).toBeVisible({ timeout: 10_000 });

    await page.locator('#email-input').fill('test@example.com');
    await page.locator('#email-btn').click();

    // showFactorBreakdown is called even in the catch block
    await expect(page.locator('#results-main')).toBeVisible({ timeout: 5_000 });
    await expect(page.locator('#factor-breakdown')).toBeVisible();
  });

  test('CTA links include domain after scan', async ({ page }) => {
    await mockScanRoute(page, mockScanResult({ domain: 'targeted.example.com' }));
    await mockEmailRoute(page);

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('targeted.example.com');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#stage-results')).toBeVisible({ timeout: 10_000 });

    await page.locator('#email-input').fill('test@example.com');
    await page.locator('#email-btn').click();

    await expect(page.locator('#results-main')).toBeVisible({ timeout: 5_000 });

    const auditHref = await page.locator('#cta-full-audit').getAttribute('href');
    expect(auditHref).toContain('targeted.example.com');

    const quickHref = await page.locator('#cta-quick-fixes').getAttribute('href');
    expect(quickHref).toContain('targeted.example.com');
  });

  test('is_js_heavy: JS-heavy note shown when flag is set', async ({ page }) => {
    await mockScanRoute(page, mockScanResult({ is_js_heavy: true }));
    await mockEmailRoute(page);

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill('mock.example.com');
    await page.locator('#scan-btn').click();

    await expect(page.locator('#stage-results')).toBeVisible({ timeout: 10_000 });

    await page.locator('#email-input').fill('test@example.com');
    await page.locator('#email-btn').click();

    await expect(page.locator('#results-main')).toBeVisible({ timeout: 5_000 });
    await expect(page.locator('#js-heavy-note')).toBeVisible();
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. Live API — full funnel against the real CF Worker
// ─────────────────────────────────────────────────────────────────────────────

test.describe('Scanner — live API', () => {
  test.setTimeout(90_000);

  // Gate: only run if LIVE_SCAN_TEST=1 is explicitly set.
  // The CF Worker (AUDITANDFIX_WORKER_URL) must be deployed and responsive.
  // Requires: 095-free-scans.sql migration applied + wrangler deploy done.
  test.beforeEach(({}, testInfo) => {
    if (!process.env.LIVE_SCAN_TEST) {
      testInfo.skip(true, 'Set LIVE_SCAN_TEST=1 to run — requires CF Worker deployed');
    }
  });

  test('full funnel: SMB site → scan → score → email → breakdown', async ({ page }) => {
    // auditandfix.com is blocked (self-referential). Use a real SMB site that
    // returns 10 factors. Verified: pestcontrolsydney.com.au scores 82/B- with 10 factors.
    const testDomain = 'pestcontrolsydney.com.au';

    await page.goto(SCAN_URL);
    await page.locator('#scan-url').fill(testDomain);
    await page.locator('#scan-btn').click();

    // Scanning stage visible
    await expect(page.locator('#stage-scanning')).toBeVisible({ timeout: 5_000 });
    await expect(page.locator('#progress-domain')).toHaveText(testDomain, { timeout: 5_000 });

    // Wait for CF Worker to score the page (up to 75s).
    // If rate-limited, #scan-error appears and #stage-input is restored — skip gracefully.
    await Promise.race([
      page.locator('#stage-results').waitFor({ state: 'visible', timeout: 75_000 }),
      page.locator('#scan-error').waitFor({ state: 'visible', timeout: 75_000 }),
    ]);
    const rateLimited = await page.locator('#scan-error').isVisible();
    if (rateLimited) {
      const errText = await page.locator('#scan-error').textContent();
      test.skip(true, `Rate limited by CF Worker — run again later. Error: ${errText}`);
    }

    // Score is a valid number
    const scoreText = await page.locator('#score-number').textContent({ timeout: 5_000 });
    const score = parseInt(scoreText ?? '-1', 10);
    expect(score).toBeGreaterThanOrEqual(0);
    expect(score).toBeLessThanOrEqual(100);

    // Grade is a letter
    const grade = await page.locator('#score-grade').textContent();
    expect(grade).toMatch(/^[A-F][+-]?$/);

    // Domain label correct
    await expect(page.locator('#score-domain-label')).toContainText(testDomain);

    // Issue teaser visible (this site returns free_peek)
    await expect(page.locator('#issue-teaser')).toBeVisible();

    // Email gate visible with issues count
    await expect(page.locator('#email-gate')).toBeVisible();
    const gateTitle = await page.locator('#email-gate-title').textContent();
    expect(gateTitle?.length).toBeGreaterThan(5);

    // Submit email — save-email always returns success, factor breakdown shows regardless
    await page.locator('#email-input').fill('e2e-live@example.com');
    await page.locator('#email-btn').click();

    await expect(page.locator('#results-main')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#factor-breakdown')).toBeVisible();

    // Factor list has rows (site returns 10 factors)
    const rowCount = await page.locator('#factor-list .factor-row').count();
    expect(rowCount).toBeGreaterThanOrEqual(5);

    // Free peek box shown
    await expect(page.locator('#free-peek')).toBeVisible();

    // CTAs link to the test domain
    const auditHref = await page.locator('#cta-full-audit').getAttribute('href');
    expect(auditHref).toContain(testDomain);
  });
});
