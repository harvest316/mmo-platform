#!/usr/bin/env node
/**
 * cleanup-stale-ses-identities.mjs — one-shot cleanup of orphaned SES identities
 *
 * Removes the eu.* and sa.* subdomain identities that were created during the
 * Resend → SES migration. They were a Resend artefact for regional sending pools
 * and have no purpose under SES. Nothing in any codebase sends from them.
 * See DR-187.
 *
 * For each stale identity, this script:
 *   1. Calls SES DeleteEmailIdentity (idempotent — handles NotFoundException)
 *   2. Removes the matching DKIM CNAME records from Cloudflare
 *   3. Removes the matching MAIL FROM MX + SPF TXT records from Cloudflare
 *
 * Usage:
 *   node scripts/cleanup-stale-ses-identities.mjs [--dry-run]
 *
 * Required env vars (same as setup-ses.mjs):
 *   AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_REGION
 *   CF_API_TOKEN (or CLOUDFLARE_API_TOKEN)
 *   CF_ZONE_ID_AUDITANDFIX, CF_ZONE_ID_CONTACTREPLYAI
 *
 * Safe to re-run — idempotent. After successful run, the eu.* and sa.* SES
 * identities are gone and their DNS records are gone. The script can then be
 * deleted (or kept as a template for future identity cleanups).
 */

import {
  SESv2Client,
  DeleteEmailIdentityCommand,
  GetEmailIdentityCommand,
} from '@aws-sdk/client-sesv2';

// ── CLI flags ───────────────────────────────────────────────────────────────
const args = process.argv.slice(2);
const DRY_RUN = args.includes('--dry-run');
if (DRY_RUN) console.log('⚠  DRY RUN — no changes will be made\n');

// ── Env validation ──────────────────────────────────────────────────────────
function requireEnv(name) {
  const val = process.env[name];
  if (!val) {
    console.error(`✗ Missing required env var: ${name}`);
    process.exit(1);
  }
  return val;
}

const AWS_ACCESS_KEY_ID     = requireEnv('AWS_ACCESS_KEY_ID');
const AWS_SECRET_ACCESS_KEY = requireEnv('AWS_SECRET_ACCESS_KEY');
const AWS_REGION            = process.env.AWS_REGION ?? 'ap-southeast-2';
const CF_API_TOKEN          = process.env.CF_API_TOKEN || process.env.CLOUDFLARE_API_TOKEN;
if (!CF_API_TOKEN) { console.error('✗ Missing CF_API_TOKEN or CLOUDFLARE_API_TOKEN'); process.exit(1); }
const CF_ZONE_ID_AUDITANDFIX    = requireEnv('CF_ZONE_ID_AUDITANDFIX');
const CF_ZONE_ID_CONTACTREPLYAI = requireEnv('CF_ZONE_ID_CONTACTREPLYAI');

// ── Stale identities to remove ──────────────────────────────────────────────
//
// Each entry: { domain, zoneId }
// We delete the SES identity, the matching MAIL FROM bounce.{domain} MX + TXT,
// and any DKIM CNAMEs for the domain.

const STALE_IDENTITIES = [
  { domain: 'eu.auditandfix.com',    zoneId: CF_ZONE_ID_AUDITANDFIX    },
  { domain: 'sa.auditandfix.com',    zoneId: CF_ZONE_ID_AUDITANDFIX    },
  { domain: 'eu.contactreplyai.com', zoneId: CF_ZONE_ID_CONTACTREPLYAI },
  { domain: 'sa.contactreplyai.com', zoneId: CF_ZONE_ID_CONTACTREPLYAI },
];

// ── AWS SES client ──────────────────────────────────────────────────────────
const sesv2 = new SESv2Client({
  region: AWS_REGION,
  credentials: { accessKeyId: AWS_ACCESS_KEY_ID, secretAccessKey: AWS_SECRET_ACCESS_KEY },
});

// ── Cloudflare API helper ───────────────────────────────────────────────────
async function cfRequest(method, path, body) {
  const url = `https://api.cloudflare.com/client/v4${path}`;
  const opts = {
    method,
    headers: {
      'Authorization': `Bearer ${CF_API_TOKEN}`,
      'Content-Type': 'application/json',
    },
  };
  if (body) opts.body = JSON.stringify(body);
  const res = await fetch(url, opts);
  const json = await res.json();
  if (!json.success) {
    const errs = json.errors?.map(e => e.message).join(', ') ?? 'Unknown CF error';
    throw new Error(`CF API ${method} ${path}: ${errs}`);
  }
  return json;
}

// ── Delete a Cloudflare DNS record by id ────────────────────────────────────
async function cfDeleteRecord(zoneId, recordId, label) {
  if (DRY_RUN) {
    console.log(`  [dry-run] Would delete CF record ${label} (id: ${recordId})`);
    return;
  }
  await cfRequest('DELETE', `/zones/${zoneId}/dns_records/${recordId}`);
  console.log(`  ✓ Deleted CF record: ${label}`);
}

// ── Find and delete all DNS records matching name + type ────────────────────
async function deleteDnsRecordsForName(zoneId, name, type) {
  try {
    const res = await cfRequest('GET', `/zones/${zoneId}/dns_records?type=${type}&name=${encodeURIComponent(name)}`);
    const records = res.result ?? [];
    if (records.length === 0) {
      console.log(`  ⚠ No ${type} records found for ${name}`);
      return;
    }
    for (const record of records) {
      await cfDeleteRecord(zoneId, record.id, `${type} ${name} → ${record.content}`);
    }
  } catch (err) {
    console.error(`  ✗ Failed to query ${type} ${name}:`, err.message);
  }
}

// ── Delete one stale identity end-to-end ────────────────────────────────────
async function cleanupIdentity({ domain, zoneId }) {
  console.log(`\n── Cleaning up: ${domain} ──────────────────────────────────────`);

  // 1. Get DKIM tokens BEFORE deleting (needed to find the CNAMEs to remove)
  let dkimTokens = [];
  try {
    const info = await sesv2.send(new GetEmailIdentityCommand({ EmailIdentity: domain }));
    dkimTokens = info.DkimAttributes?.Tokens ?? [];
    console.log(`  ✓ Got ${dkimTokens.length} DKIM tokens for ${domain}`);
  } catch (err) {
    if (err.name === 'NotFoundException') {
      console.log(`  ⚠ SES identity ${domain} not found — already deleted, will still try to clean up CF records`);
    } else {
      console.error(`  ✗ Failed to fetch ${domain}:`, err.message);
    }
  }

  // 2. Delete SES identity
  if (DRY_RUN) {
    console.log(`  [dry-run] Would delete SES identity: ${domain}`);
  } else {
    try {
      await sesv2.send(new DeleteEmailIdentityCommand({ EmailIdentity: domain }));
      console.log(`  ✓ Deleted SES identity: ${domain}`);
    } catch (err) {
      if (err.name === 'NotFoundException') {
        console.log(`  ⚠ SES identity already deleted: ${domain}`);
      } else {
        console.error(`  ✗ Failed to delete SES identity ${domain}:`, err.message);
      }
    }
  }

  // 3. Delete DKIM CNAMEs at {token}._domainkey.{domain}
  for (const token of dkimTokens) {
    const recordName = `${token}._domainkey.${domain}`;
    await deleteDnsRecordsForName(zoneId, recordName, 'CNAME');
  }

  // 4. Delete MAIL FROM bounce.{domain} MX + SPF TXT
  const bounceDomain = `bounce.${domain}`;
  await deleteDnsRecordsForName(zoneId, bounceDomain, 'MX');
  await deleteDnsRecordsForName(zoneId, bounceDomain, 'TXT');
}

// ── Main ────────────────────────────────────────────────────────────────────
async function main() {
  console.log('SES Stale-Identity Cleanup');
  console.log('==========================');
  console.log(`Region: ${AWS_REGION}`);
  console.log(`Dry run: ${DRY_RUN}`);
  console.log(`Identities to clean up: ${STALE_IDENTITIES.length}`);
  for (const { domain } of STALE_IDENTITIES) console.log(`  - ${domain}`);

  for (const identity of STALE_IDENTITIES) {
    await cleanupIdentity(identity);
  }

  console.log('\n✓ Cleanup complete');
  if (DRY_RUN) {
    console.log('  This was a dry run — re-run without --dry-run to apply.');
  }
}

main().catch(err => {
  console.error('\n✗ Cleanup failed:', err.message);
  console.error(err.stack);
  process.exit(1);
});
