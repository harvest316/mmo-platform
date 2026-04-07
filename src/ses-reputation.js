/**
 * SES Reputation monitoring + tiered pause logic.
 *
 * Pulls bounce + complaint rates from CloudWatch (account-level) and decides
 * what action to take based on tiered thresholds. The decision logic is pure
 * (no I/O) so it's directly unit-testable; the CloudWatch fetch is a thin
 * wrapper that the cron driver calls.
 *
 * Tiers (rates in percent):
 *
 *   Normal     bounce<2     complaint<0.05    log only
 *   Warning    2-4          0.05-0.08         alert, no auto-action
 *   Elevated   4-7          0.08-0.15         pause cold outreach (333Method)
 *   Critical   7-9          0.15-0.4          pause all outbound, page operator
 *   Emergency  >=9          >=0.4             pause + flip kill switch
 *
 * The 333Method 'Account Health' panel uses the same thresholds at the
 * warn/bad boundary (2/5, 0.05/0.08), so this stays visually consistent.
 */

import { CloudWatchClient, GetMetricStatisticsCommand } from '@aws-sdk/client-cloudwatch';

// ── Thresholds (percent) ────────────────────────────────────────────────────

export const TIERS = Object.freeze({
  NORMAL:    { name: 'normal',    bounceMin: 0,   complaintMin: 0     },
  WARNING:   { name: 'warning',   bounceMin: 2,   complaintMin: 0.05  },
  ELEVATED:  { name: 'elevated',  bounceMin: 4,   complaintMin: 0.08  },
  CRITICAL:  { name: 'critical',  bounceMin: 7,   complaintMin: 0.15  },
  EMERGENCY: { name: 'emergency', bounceMin: 9,   complaintMin: 0.4   },
});

// Order matters — must be highest → lowest for classifyTier()
const TIER_LADDER = [
  TIERS.EMERGENCY,
  TIERS.CRITICAL,
  TIERS.ELEVATED,
  TIERS.WARNING,
  TIERS.NORMAL,
];

// ── Pure decision logic ─────────────────────────────────────────────────────

/**
 * Classify a (bounce, complaint) pair into the highest tier it triggers.
 * Either dimension can independently push us up the ladder.
 *
 * @param {number} bounceRate    - Percent (e.g. 3.5 for 3.5%)
 * @param {number} complaintRate - Percent (e.g. 0.1 for 0.1%)
 * @returns {{name: string}}
 */
export function classifyTier(bounceRate, complaintRate) {
  for (const tier of TIER_LADDER) {
    if (bounceRate >= tier.bounceMin || complaintRate >= tier.complaintMin) {
      return tier;
    }
  }
  return TIERS.NORMAL;
}

/**
 * Map a tier to the action the system should take. Pure — returns a struct
 * the cron driver applies via DB updates and the shared transport reads via
 * the pause flag.
 *
 * @param {{name: string}} tier
 * @returns {{
 *   pauseCold: boolean,
 *   pauseAll: boolean,
 *   alert: boolean,
 *   page: boolean,
 *   killSwitch: boolean,
 * }}
 */
export function actionForTier(tier) {
  switch (tier.name) {
    case 'emergency':
      return { pauseCold: true,  pauseAll: true,  alert: true,  page: true,  killSwitch: true  };
    case 'critical':
      return { pauseCold: true,  pauseAll: true,  alert: true,  page: true,  killSwitch: false };
    case 'elevated':
      return { pauseCold: true,  pauseAll: false, alert: true,  page: false, killSwitch: false };
    case 'warning':
      return { pauseCold: false, pauseAll: false, alert: true,  page: false, killSwitch: false };
    case 'normal':
    default:
      return { pauseCold: false, pauseAll: false, alert: false, page: false, killSwitch: false };
  }
}

/**
 * Compose a one-line summary for logs and the status panel.
 * Format: "[tier] bounce=X.XX% complaint=Y.YY%  → action"
 */
export function summarise(bounceRate, complaintRate, tier, action) {
  const parts = [];
  if (action.killSwitch)      parts.push('KILL-SWITCH');
  else if (action.pauseAll)   parts.push('PAUSE-ALL');
  else if (action.pauseCold)  parts.push('PAUSE-COLD');
  else if (action.alert)      parts.push('ALERT');
  else                        parts.push('OK');

  return `[${tier.name}] bounce=${bounceRate.toFixed(2)}% complaint=${complaintRate.toFixed(3)}% → ${parts.join(',')}`;
}

// ── CloudWatch fetch (impure — wraps SDK) ───────────────────────────────────

let _cwClient;
function getCwClient() {
  if (!_cwClient) {
    if (!process.env.AWS_ACCESS_KEY_ID) throw new Error('Missing env var: AWS_ACCESS_KEY_ID');
    if (!process.env.AWS_SECRET_ACCESS_KEY) throw new Error('Missing env var: AWS_SECRET_ACCESS_KEY');
    _cwClient = new CloudWatchClient({
      region: process.env.AWS_REGION || 'ap-southeast-2',
      credentials: {
        accessKeyId: process.env.AWS_ACCESS_KEY_ID,
        secretAccessKey: process.env.AWS_SECRET_ACCESS_KEY,
      },
    });
  }
  return _cwClient;
}

/**
 * Fetch the most recent account-level reputation values from CloudWatch.
 * Returns rates as PERCENT (CloudWatch returns 0-1 fractions for these
 * metrics, so we multiply by 100 here).
 *
 * @returns {Promise<{ bounceRate: number, complaintRate: number, fetchedAt: string }>}
 */
export async function fetchReputation() {
  const cw = getCwClient();
  const end = new Date();
  const start = new Date(end.getTime() - 6 * 60 * 60 * 1000); // 6h window — these are 1h-ish updated metrics

  async function getLatest(metricName) {
    const res = await cw.send(new GetMetricStatisticsCommand({
      Namespace: 'AWS/SES',
      MetricName: metricName,
      StartTime: start,
      EndTime: end,
      Period: 3600,
      Statistics: ['Maximum'],
    }));
    if (!res.Datapoints || res.Datapoints.length === 0) return 0;
    res.Datapoints.sort((a, b) => b.Timestamp - a.Timestamp);
    return res.Datapoints[0].Maximum || 0;
  }

  const [bounceFraction, complaintFraction] = await Promise.all([
    getLatest('Reputation.BounceRate'),
    getLatest('Reputation.ComplaintRate'),
  ]);

  return {
    bounceRate:    bounceFraction * 100,
    complaintRate: complaintFraction * 100,
    fetchedAt:     new Date().toISOString(),
  };
}
