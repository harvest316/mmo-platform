/**
 * Unit tests for SES configuration set pre-flight check (DR-214).
 *
 * Strategy: mock the SESv2Client to return/throw for GetConfigurationSetCommand,
 * then exercise probeConfigSet / buildReport / checkSesConfigSets.
 */

import { vi, describe, it, expect, beforeEach } from 'vitest';

// ── SES SDK mock ────────────────────────────────────────────────────────────

const mockSend = vi.fn();

vi.mock('@aws-sdk/client-sesv2', () => {
  function SESv2Client() {
    this.send = mockSend;
  }
  function GetConfigurationSetCommand(params) {
    Object.assign(this, params);
  }
  return { SESv2Client, GetConfigurationSetCommand };
});

// Dynamic import to pick up the mock.
async function freshImport() {
  vi.resetModules();
  return await import('../../src/ses-config-check.js');
}

// ── Fixtures ────────────────────────────────────────────────────────────────

const BASE_ENV = {
  AWS_ACCESS_KEY_ID: 'test-access-key',
  AWS_SECRET_ACCESS_KEY: 'test-secret-key',
  AWS_REGION: 'us-east-1',
};

function notFoundError() {
  const err = new Error("Configuration set 'x' does not exist.");
  err.name = 'NotFoundException';
  return err;
}

function configSetResponse({ engagement } = {}) {
  // 'engagement' is one of: 'ENABLED', 'DISABLED', 'missing'
  const resp = { ConfigurationSetName: 'x' };
  if (engagement === 'ENABLED' || engagement === 'DISABLED') {
    resp.VdmOptions = { DashboardOptions: { EngagementMetrics: engagement } };
  }
  return resp;
}

beforeEach(() => {
  for (const [k, v] of Object.entries(BASE_ENV)) process.env[k] = v;
  delete process.env.SES_CONFIGURATION_SET;
  delete process.env.SES_CONFIGURATION_SET_NOTRACK;
  vi.clearAllMocks();
});

// ── probeConfigSet ──────────────────────────────────────────────────────────

describe('probeConfigSet', () => {
  it('returns { found: true, raw } when config set exists', async () => {
    const { probeConfigSet, _resetClient } = await freshImport();
    _resetClient();
    mockSend.mockResolvedValueOnce(configSetResponse({ engagement: 'ENABLED' }));

    const result = await probeConfigSet('mmo-outbound');
    expect(result.found).toBe(true);
    expect(result.raw.VdmOptions.DashboardOptions.EngagementMetrics).toBe('ENABLED');
  });

  it('returns { found: false } on NotFoundException', async () => {
    const { probeConfigSet, _resetClient } = await freshImport();
    _resetClient();
    mockSend.mockRejectedValueOnce(notFoundError());

    const result = await probeConfigSet('missing-set');
    expect(result.found).toBe(false);
    expect(result.errorCode).toBe('NotFoundException');
  });

  it('rethrows non-NotFound errors (e.g. credentials, throttling)', async () => {
    const { probeConfigSet, _resetClient } = await freshImport();
    _resetClient();
    const authError = new Error('Credentials missing');
    authError.name = 'UnrecognizedClientException';
    mockSend.mockRejectedValueOnce(authError);

    await expect(probeConfigSet('x')).rejects.toThrow('Credentials missing');
  });
});

// ── readEngagementState ─────────────────────────────────────────────────────

describe('readEngagementState', () => {
  it('returns "enabled" when VdmOptions.DashboardOptions.EngagementMetrics=ENABLED', async () => {
    const { readEngagementState } = await freshImport();
    expect(readEngagementState(configSetResponse({ engagement: 'ENABLED' }))).toBe('enabled');
  });

  it('returns "disabled" when DISABLED', async () => {
    const { readEngagementState } = await freshImport();
    expect(readEngagementState(configSetResponse({ engagement: 'DISABLED' }))).toBe('disabled');
  });

  it('returns "unknown" when VdmOptions is absent (account without VDM)', async () => {
    const { readEngagementState } = await freshImport();
    expect(readEngagementState(configSetResponse({ engagement: 'missing' }))).toBe('unknown');
  });

  it('returns "unknown" when raw is null/undefined (defensive)', async () => {
    const { readEngagementState } = await freshImport();
    expect(readEngagementState(null)).toBe('unknown');
    expect(readEngagementState(undefined)).toBe('unknown');
  });
});

// ── buildReport ─────────────────────────────────────────────────────────────

describe('buildReport', () => {
  const okTracked = { found: true, raw: configSetResponse({ engagement: 'ENABLED' }) };
  const okNotrack = { found: true, raw: configSetResponse({ engagement: 'DISABLED' }) };
  const missing   = { found: false, errorCode: 'NotFoundException' };

  it('returns ok=true when both exist with correct engagement state', async () => {
    const { buildReport } = await freshImport();
    const report = buildReport({
      trackedProbe: okTracked, notrackProbe: okNotrack,
      trackedName: 'mmo-outbound', notrackName: 'mmo-outbound-notrack',
    });
    expect(report.ok).toBe(true);
    expect(report.errors).toEqual([]);
    expect(report.warnings).toEqual([]);
    expect(report.tracked.engagement).toBe('enabled');
    expect(report.notrack.engagement).toBe('disabled');
  });

  it('reports error when tracked config set is missing', async () => {
    const { buildReport } = await freshImport();
    const report = buildReport({
      trackedProbe: missing, notrackProbe: okNotrack,
      trackedName: 'mmo-outbound', notrackName: 'mmo-outbound-notrack',
    });
    expect(report.ok).toBe(false);
    expect(report.errors[0]).toMatch(/mmo-outbound.*does not exist/);
  });

  it('reports error when notrack config set is missing', async () => {
    const { buildReport } = await freshImport();
    const report = buildReport({
      trackedProbe: okTracked, notrackProbe: missing,
      trackedName: 'mmo-outbound', notrackName: 'mmo-outbound-notrack',
    });
    expect(report.ok).toBe(false);
    expect(report.errors[0]).toMatch(/mmo-outbound-notrack.*does not exist/);
    expect(report.errors[0]).toMatch(/DR-214/);
  });

  it('reports both missing with two separate error messages', async () => {
    const { buildReport } = await freshImport();
    const report = buildReport({
      trackedProbe: missing, notrackProbe: missing,
      trackedName: 'mmo-outbound', notrackName: 'mmo-outbound-notrack',
    });
    expect(report.errors).toHaveLength(2);
  });

  it('flags engagement=DISABLED on tracked config set as an error in strict mode', async () => {
    const { buildReport } = await freshImport();
    const disabledTracked = { found: true, raw: configSetResponse({ engagement: 'DISABLED' }) };
    const report = buildReport({
      trackedProbe: disabledTracked, notrackProbe: okNotrack,
      trackedName: 'mmo-outbound', notrackName: 'mmo-outbound-notrack',
      strict: true,
    });
    expect(report.ok).toBe(false);
    expect(report.errors.some(e => /EngagementMetrics=DISABLED/.test(e))).toBe(true);
  });

  it('downgrades engagement mismatches to warnings when strict=false', async () => {
    const { buildReport } = await freshImport();
    const disabledTracked = { found: true, raw: configSetResponse({ engagement: 'DISABLED' }) };
    const report = buildReport({
      trackedProbe: disabledTracked, notrackProbe: okNotrack,
      trackedName: 'mmo-outbound', notrackName: 'mmo-outbound-notrack',
      strict: false,
    });
    expect(report.ok).toBe(true);
    expect(report.warnings.some(w => /EngagementMetrics=DISABLED/.test(w))).toBe(true);
  });

  it('flags engagement=ENABLED on notrack config set as misconfiguration', async () => {
    const { buildReport } = await freshImport();
    const enabledNotrack = { found: true, raw: configSetResponse({ engagement: 'ENABLED' }) };
    const report = buildReport({
      trackedProbe: okTracked, notrackProbe: enabledNotrack,
      trackedName: 'mmo-outbound', notrackName: 'mmo-outbound-notrack',
      strict: true,
    });
    expect(report.ok).toBe(false);
    expect(report.errors.some(e => /defeats the purpose/.test(e))).toBe(true);
  });

  it('treats engagement=unknown as OK (VDM field absent is not a failure)', async () => {
    const { buildReport } = await freshImport();
    const unknownTracked = { found: true, raw: configSetResponse({ engagement: 'missing' }) };
    const unknownNotrack = { found: true, raw: configSetResponse({ engagement: 'missing' }) };
    const report = buildReport({
      trackedProbe: unknownTracked, notrackProbe: unknownNotrack,
      trackedName: 'mmo-outbound', notrackName: 'mmo-outbound-notrack',
    });
    expect(report.ok).toBe(true);
    expect(report.tracked.engagement).toBe('unknown');
    expect(report.notrack.engagement).toBe('unknown');
  });
});

// ── checkSesConfigSets (integration of probe + report) ──────────────────────

describe('checkSesConfigSets', () => {
  it('uses default config set names when no args provided', async () => {
    const { checkSesConfigSets, _resetClient } = await freshImport();
    _resetClient();
    mockSend.mockResolvedValueOnce(configSetResponse({ engagement: 'ENABLED' }));
    mockSend.mockResolvedValueOnce(configSetResponse({ engagement: 'DISABLED' }));

    const report = await checkSesConfigSets();
    expect(report.tracked.name).toBe('mmo-outbound');
    expect(report.notrack.name).toBe('mmo-outbound-notrack');
    expect(report.ok).toBe(true);
  });

  it('respects SES_CONFIGURATION_SET* env vars', async () => {
    process.env.SES_CONFIGURATION_SET = 'custom-tracked';
    process.env.SES_CONFIGURATION_SET_NOTRACK = 'custom-notrack';
    const { checkSesConfigSets, _resetClient } = await freshImport();
    _resetClient();
    mockSend.mockResolvedValueOnce(configSetResponse({ engagement: 'ENABLED' }));
    mockSend.mockResolvedValueOnce(configSetResponse({ engagement: 'DISABLED' }));

    const report = await checkSesConfigSets();
    expect(report.tracked.name).toBe('custom-tracked');
    expect(report.notrack.name).toBe('custom-notrack');

    // Verify the SDK was called with the env-var names
    const firstCallCmd = mockSend.mock.calls[0][0];
    const secondCallCmd = mockSend.mock.calls[1][0];
    const names = [firstCallCmd.ConfigurationSetName, secondCallCmd.ConfigurationSetName].sort();
    expect(names).toEqual(['custom-notrack', 'custom-tracked']);
  });

  it('reports both missing when SES returns NotFound for both', async () => {
    const { checkSesConfigSets, _resetClient } = await freshImport();
    _resetClient();
    mockSend.mockRejectedValueOnce(notFoundError());
    mockSend.mockRejectedValueOnce(notFoundError());

    const report = await checkSesConfigSets();
    expect(report.ok).toBe(false);
    expect(report.errors).toHaveLength(2);
    expect(report.tracked.found).toBe(false);
    expect(report.notrack.found).toBe(false);
  });
});

// ── formatReport ────────────────────────────────────────────────────────────

describe('formatReport', () => {
  it('includes OK marker and both config set names on success', async () => {
    const { buildReport, formatReport } = await freshImport();
    const report = buildReport({
      trackedProbe: { found: true, raw: configSetResponse({ engagement: 'ENABLED' }) },
      notrackProbe: { found: true, raw: configSetResponse({ engagement: 'DISABLED' }) },
      trackedName: 'mmo-outbound',
      notrackName: 'mmo-outbound-notrack',
    });
    const text = formatReport(report);
    expect(text).toMatch(/OK/);
    expect(text).toMatch(/mmo-outbound/);
    expect(text).toMatch(/mmo-outbound-notrack/);
  });

  it('includes FAIL marker and error details on failure', async () => {
    const { buildReport, formatReport } = await freshImport();
    const report = buildReport({
      trackedProbe: { found: false, errorCode: 'NotFoundException' },
      notrackProbe: { found: true, raw: configSetResponse({ engagement: 'DISABLED' }) },
      trackedName: 'mmo-outbound',
      notrackName: 'mmo-outbound-notrack',
    });
    const text = formatReport(report);
    expect(text).toMatch(/FAIL/);
    expect(text).toMatch(/does not exist/);
  });
});
