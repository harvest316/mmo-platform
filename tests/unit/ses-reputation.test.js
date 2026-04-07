/**
 * Unit tests for the pure decision logic in mmo-platform/src/ses-reputation.js
 *
 * Only the pure functions are tested here (classifyTier, actionForTier,
 * summarise). The CloudWatch fetcher is a thin SDK wrapper and is exercised
 * end-to-end via the cron job in non-test runs.
 */

import { describe, it, expect } from 'vitest';
import {
  TIERS,
  classifyTier,
  actionForTier,
  summarise,
} from '../../src/ses-reputation.js';

// ── classifyTier ────────────────────────────────────────────────────────────

describe('classifyTier', () => {
  it('returns NORMAL for clean rates', () => {
    expect(classifyTier(0, 0).name).toBe('normal');
    expect(classifyTier(1.99, 0.049).name).toBe('normal');
  });

  it('promotes to WARNING when bounce >= 2%', () => {
    expect(classifyTier(2, 0).name).toBe('warning');
    expect(classifyTier(3.99, 0).name).toBe('warning');
  });

  it('promotes to WARNING when complaint >= 0.05%', () => {
    expect(classifyTier(0, 0.05).name).toBe('warning');
    expect(classifyTier(0, 0.079).name).toBe('warning');
  });

  it('promotes to ELEVATED when bounce >= 4%', () => {
    expect(classifyTier(4, 0).name).toBe('elevated');
    expect(classifyTier(6.99, 0).name).toBe('elevated');
  });

  it('promotes to ELEVATED when complaint >= 0.08%', () => {
    expect(classifyTier(0, 0.08).name).toBe('elevated');
    expect(classifyTier(0, 0.149).name).toBe('elevated');
  });

  it('promotes to CRITICAL when bounce >= 7%', () => {
    expect(classifyTier(7, 0).name).toBe('critical');
    expect(classifyTier(8.99, 0).name).toBe('critical');
  });

  it('promotes to CRITICAL when complaint >= 0.15%', () => {
    expect(classifyTier(0, 0.15).name).toBe('critical');
    expect(classifyTier(0, 0.399).name).toBe('critical');
  });

  it('promotes to EMERGENCY when bounce >= 9%', () => {
    expect(classifyTier(9, 0).name).toBe('emergency');
    expect(classifyTier(15, 0).name).toBe('emergency');
  });

  it('promotes to EMERGENCY when complaint >= 0.4%', () => {
    expect(classifyTier(0, 0.4).name).toBe('emergency');
    expect(classifyTier(0, 1.0).name).toBe('emergency');
  });

  it('uses the higher of the two dimensions', () => {
    // bounce=normal, complaint=critical → critical wins
    expect(classifyTier(1, 0.2).name).toBe('critical');
    // bounce=elevated, complaint=normal → elevated wins
    expect(classifyTier(5, 0.01).name).toBe('elevated');
    // bounce=warning, complaint=emergency → emergency wins
    expect(classifyTier(2.5, 0.5).name).toBe('emergency');
  });

  it('handles zero rates without crashing', () => {
    expect(classifyTier(0, 0).name).toBe('normal');
  });

  it('handles very high rates without crashing', () => {
    expect(classifyTier(100, 100).name).toBe('emergency');
  });
});

// ── actionForTier ───────────────────────────────────────────────────────────

describe('actionForTier', () => {
  it('NORMAL → no action', () => {
    expect(actionForTier(TIERS.NORMAL)).toEqual({
      pauseCold: false, pauseAll: false, alert: false, page: false, killSwitch: false,
    });
  });

  it('WARNING → alert only', () => {
    expect(actionForTier(TIERS.WARNING)).toEqual({
      pauseCold: false, pauseAll: false, alert: true, page: false, killSwitch: false,
    });
  });

  it('ELEVATED → pause cold + alert (transactional still allowed)', () => {
    const a = actionForTier(TIERS.ELEVATED);
    expect(a.pauseCold).toBe(true);
    expect(a.pauseAll).toBe(false);
    expect(a.alert).toBe(true);
  });

  it('CRITICAL → pause all + page', () => {
    const a = actionForTier(TIERS.CRITICAL);
    expect(a.pauseCold).toBe(true);
    expect(a.pauseAll).toBe(true);
    expect(a.page).toBe(true);
    expect(a.killSwitch).toBe(false);
  });

  it('EMERGENCY → kill switch + page', () => {
    const a = actionForTier(TIERS.EMERGENCY);
    expect(a.pauseAll).toBe(true);
    expect(a.killSwitch).toBe(true);
    expect(a.page).toBe(true);
  });

  it('escalation is monotonic — every higher tier is at least as restrictive', () => {
    const ladder = [TIERS.NORMAL, TIERS.WARNING, TIERS.ELEVATED, TIERS.CRITICAL, TIERS.EMERGENCY];
    let prev = actionForTier(ladder[0]);
    for (let i = 1; i < ladder.length; i++) {
      const curr = actionForTier(ladder[i]);
      // Each flag, once set, stays set going up the ladder
      if (prev.alert)      expect(curr.alert).toBe(true);
      if (prev.pauseCold)  expect(curr.pauseCold).toBe(true);
      if (prev.pauseAll)   expect(curr.pauseAll).toBe(true);
      if (prev.page)       expect(curr.page).toBe(true);
      if (prev.killSwitch) expect(curr.killSwitch).toBe(true);
      prev = curr;
    }
  });

  it('falls back to no-action for unknown tier', () => {
    expect(actionForTier({ name: 'gibberish' })).toEqual({
      pauseCold: false, pauseAll: false, alert: false, page: false, killSwitch: false,
    });
  });
});

// ── summarise ───────────────────────────────────────────────────────────────

describe('summarise', () => {
  it('formats normal state as OK', () => {
    const tier = TIERS.NORMAL;
    const action = actionForTier(tier);
    const s = summarise(0.5, 0.01, tier, action);
    expect(s).toContain('[normal]');
    expect(s).toContain('bounce=0.50%');
    expect(s).toContain('complaint=0.010%');
    expect(s).toContain('OK');
  });

  it('formats warning as ALERT', () => {
    const tier = TIERS.WARNING;
    const action = actionForTier(tier);
    expect(summarise(2.5, 0.06, tier, action)).toContain('ALERT');
  });

  it('formats elevated as PAUSE-COLD', () => {
    const tier = TIERS.ELEVATED;
    const action = actionForTier(tier);
    expect(summarise(5, 0.1, tier, action)).toContain('PAUSE-COLD');
  });

  it('formats critical as PAUSE-ALL', () => {
    const tier = TIERS.CRITICAL;
    const action = actionForTier(tier);
    expect(summarise(7.5, 0.2, tier, action)).toContain('PAUSE-ALL');
  });

  it('formats emergency as KILL-SWITCH', () => {
    const tier = TIERS.EMERGENCY;
    const action = actionForTier(tier);
    expect(summarise(10, 0.5, tier, action)).toContain('KILL-SWITCH');
  });

  it('rounds bounce to 2dp and complaint to 3dp', () => {
    const tier = TIERS.NORMAL;
    const action = actionForTier(tier);
    const s = summarise(1.23456, 0.01234, tier, action);
    expect(s).toContain('1.23%');
    expect(s).toContain('0.012%');
  });
});
