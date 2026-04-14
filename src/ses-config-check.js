/**
 * SES configuration set pre-flight check (DR-214).
 *
 * The shared email transport routes sends through one of two configuration sets:
 *   - mmo-outbound          — engagement-tracked (default)
 *   - mmo-outbound-notrack  — no open/click tracking (image-less cold outreach)
 *
 * If either config set is missing at runtime, SES rejects the send with
 * `ConfigurationSetDoesNotExist`. This module provides a pre-flight check that
 * can be run from a script, cron, or deploy step to catch the misconfiguration
 * loudly and early — before it becomes a runtime send failure.
 *
 * Pure-ish: the SES I/O is injected via `sesClient`, making the decision logic
 * unit-testable with a mocked SESv2 client.
 */

import { SESv2Client, GetConfigurationSetCommand } from '@aws-sdk/client-sesv2';

// Keep defaults aligned with mmo-platform/src/email.js fallback literals.
export const DEFAULT_TRACKED_CONFIG_SET   = 'mmo-outbound';
export const DEFAULT_NOTRACK_CONFIG_SET   = 'mmo-outbound-notrack';

// ── SES client (lazy singleton) ────────────────────────────────────────────

let _client;

function getClient() {
  if (!_client) {
    if (!process.env.AWS_ACCESS_KEY_ID) throw new Error('Missing env var: AWS_ACCESS_KEY_ID');
    if (!process.env.AWS_SECRET_ACCESS_KEY) throw new Error('Missing env var: AWS_SECRET_ACCESS_KEY');
    _client = new SESv2Client({
      region: process.env.AWS_REGION || 'ap-southeast-2',
      credentials: {
        accessKeyId: process.env.AWS_ACCESS_KEY_ID,
        secretAccessKey: process.env.AWS_SECRET_ACCESS_KEY,
      },
    });
  }
  return _client;
}

/**
 * Test hook: clear the cached SES client.
 */
export function _resetClient() {
  _client = undefined;
}

// ── Individual config set probe ────────────────────────────────────────────

/**
 * Fetch one configuration set. Returns `{ found: true, raw }` on success,
 * `{ found: false, errorCode }` on NotFound, or throws on any other error.
 *
 * @param {string} name
 * @param {object} [client]   — injected SESv2Client (tests) or default lazy singleton
 */
export async function probeConfigSet(name, client = getClient()) {
  try {
    const raw = await client.send(new GetConfigurationSetCommand({ ConfigurationSetName: name }));
    return { found: true, raw };
  } catch (err) {
    // SESv2 throws NotFoundException with name 'NotFoundException'
    if (err?.name === 'NotFoundException' || err?.__type === 'NotFoundException') {
      return { found: false, errorCode: 'NotFoundException' };
    }
    throw err;
  }
}

// ── Interpretation ─────────────────────────────────────────────────────────

/**
 * Pull the engagement tracking state out of a GetConfigurationSet response.
 * Returns one of 'enabled' | 'disabled' | 'unknown'.
 *
 * VDM dashboard EngagementMetrics controls whether the config set participates
 * in open/click tracking. The API field may be absent for accounts without VDM
 * enabled at the account level — that's 'unknown', not 'disabled', because the
 * dashboard setting can't be authoritatively read in that case.
 */
export function readEngagementState(raw) {
  const em = raw?.VdmOptions?.DashboardOptions?.EngagementMetrics;
  if (em === 'ENABLED')  return 'enabled';
  if (em === 'DISABLED') return 'disabled';
  return 'unknown';
}

// ── Report builder (pure) ──────────────────────────────────────────────────

/**
 * Build a structured report from two config set probes. Pure function — all
 * I/O is done by the caller via probeConfigSet().
 *
 * The `strict` flag controls whether engagement-tracking misconfigurations
 * (tracked set has engagement disabled, or notrack set has engagement enabled)
 * are classified as errors (strict=true) or warnings (strict=false). Default
 * is strict — the whole point of the two-config-set split (DR-214) is that
 * tracking state differs between them.
 *
 * @returns {{
 *   ok: boolean,
 *   errors: string[],
 *   warnings: string[],
 *   tracked:  { name, found, engagement },
 *   notrack:  { name, found, engagement },
 * }}
 */
export function buildReport({ trackedProbe, notrackProbe, trackedName, notrackName, strict = true }) {
  const errors = [];
  const warnings = [];

  const tracked = {
    name: trackedName,
    found: trackedProbe.found,
    engagement: trackedProbe.found ? readEngagementState(trackedProbe.raw) : null,
  };
  const notrack = {
    name: notrackName,
    found: notrackProbe.found,
    engagement: notrackProbe.found ? readEngagementState(notrackProbe.raw) : null,
  };

  if (!tracked.found) {
    errors.push(
      `Tracked config set '${trackedName}' does not exist. Create it via the AWS console ` +
      `(SES → Configuration sets → Create) or: aws sesv2 create-configuration-set ` +
      `--configuration-set-name ${trackedName} --region $AWS_REGION`
    );
  }
  if (!notrack.found) {
    errors.push(
      `No-track config set '${notrackName}' does not exist. Create it (no tracking options, ` +
      `no open/click event destinations) via: aws sesv2 create-configuration-set ` +
      `--configuration-set-name ${notrackName} --region $AWS_REGION. See DR-214.`
    );
  }

  // Engagement-tracking sanity: tracked should have it ON, notrack should have it OFF.
  // 'unknown' is never an error — VDM field may be absent without account-level VDM.
  if (tracked.found && tracked.engagement === 'disabled') {
    const msg = `Tracked config set '${trackedName}' has VDM EngagementMetrics=DISABLED — ` +
      `no engagement data will flow to VDM dashboard. Enable it in the SES console.`;
    (strict ? errors : warnings).push(msg);
  }
  if (notrack.found && notrack.engagement === 'enabled') {
    const msg = `No-track config set '${notrackName}' has VDM EngagementMetrics=ENABLED — ` +
      `this defeats the purpose of the split (DR-214). Disable engagement metrics on this set.`;
    (strict ? errors : warnings).push(msg);
  }

  return {
    ok: errors.length === 0,
    errors,
    warnings,
    tracked,
    notrack,
  };
}

// ── Top-level orchestration ────────────────────────────────────────────────

/**
 * Check both config sets and return a structured report.
 *
 * @param {object} [opts]
 * @param {string} [opts.trackedName] — defaults to SES_CONFIGURATION_SET env var or 'mmo-outbound'
 * @param {string} [opts.notrackName] — defaults to SES_CONFIGURATION_SET_NOTRACK env var or 'mmo-outbound-notrack'
 * @param {boolean} [opts.strict]     — treat engagement mismatches as errors (default true)
 * @param {object} [opts.client]      — injectable SESv2Client (tests)
 */
export async function checkSesConfigSets({
  trackedName = process.env.SES_CONFIGURATION_SET || DEFAULT_TRACKED_CONFIG_SET,
  notrackName = process.env.SES_CONFIGURATION_SET_NOTRACK || DEFAULT_NOTRACK_CONFIG_SET,
  strict = true,
  client,
} = {}) {
  const [trackedProbe, notrackProbe] = await Promise.all([
    probeConfigSet(trackedName, client),
    probeConfigSet(notrackName, client),
  ]);

  return buildReport({ trackedProbe, notrackProbe, trackedName, notrackName, strict });
}

// ── Human-readable formatting ──────────────────────────────────────────────

/**
 * Render a report as a single multi-line string for console output.
 */
export function formatReport(report) {
  const lines = [];
  lines.push(`SES configuration set check (${report.ok ? 'OK' : 'FAIL'})`);
  lines.push(`  tracked: ${report.tracked.name} ` +
    `found=${report.tracked.found} engagement=${report.tracked.engagement ?? 'n/a'}`);
  lines.push(`  notrack: ${report.notrack.name} ` +
    `found=${report.notrack.found} engagement=${report.notrack.engagement ?? 'n/a'}`);
  if (report.errors.length) {
    lines.push('  errors:');
    for (const e of report.errors) lines.push(`    - ${e}`);
  }
  if (report.warnings.length) {
    lines.push('  warnings:');
    for (const w of report.warnings) lines.push(`    - ${w}`);
  }
  return lines.join('\n');
}
