#!/usr/bin/env node
/**
 * setup-ses.mjs — AWS SES provisioning script
 *
 * Automates the full AWS infrastructure setup for migrating from Resend to SES.
 * Idempotent: safe to re-run at any stage.
 *
 * Usage:
 *   node scripts/setup-ses.mjs [--dry-run] [--switch-mx]
 *
 * Required env vars:
 *   AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_REGION (default: us-east-1)
 *   CF_API_TOKEN, CF_ZONE_ID_AUDITANDFIX
 *
 * Optional env vars:
 *   CF_ZONE_ID_CONTACTREPLYAI — if missing, CNAMEs for contactreplyai.com are printed for manual setup
 */

import crypto from 'node:crypto';
import { SESv2Client, CreateEmailIdentityCommand, GetEmailIdentityCommand,
  CreateConfigurationSetCommand, CreateConfigurationSetEventDestinationCommand } from '@aws-sdk/client-sesv2';
import { SESClient, CreateReceiptRuleSetCommand, CreateReceiptRuleCommand,
  SetActiveReceiptRuleSetCommand } from '@aws-sdk/client-ses';
import { SNSClient, CreateTopicCommand, SubscribeCommand, ListSubscriptionsByTopicCommand } from '@aws-sdk/client-sns';
import { S3Client, CreateBucketCommand, PutBucketPolicyCommand,
  PutPublicAccessBlockCommand, PutBucketEncryptionCommand,
  PutBucketLifecycleConfigurationCommand, GetBucketLocationCommand } from '@aws-sdk/client-s3';
import { IAMClient, CreateUserCommand, PutUserPolicyCommand, CreateAccessKeyCommand,
  GetUserCommand } from '@aws-sdk/client-iam';
import { STSClient, GetCallerIdentityCommand } from '@aws-sdk/client-sts';

// ---------------------------------------------------------------------------
// CLI flags
// ---------------------------------------------------------------------------
const args = process.argv.slice(2);
const DRY_RUN = args.includes('--dry-run');
const SWITCH_MX = args.includes('--switch-mx');

if (DRY_RUN) console.log('⚠  DRY RUN — no changes will be made\n');

// ---------------------------------------------------------------------------
// Environment validation
// ---------------------------------------------------------------------------
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
if (!CF_API_TOKEN) { console.error('✗ Missing required env var: CF_API_TOKEN or CLOUDFLARE_API_TOKEN'); process.exit(1); }
const CF_ZONE_ID_AUDITANDFIX     = requireEnv('CF_ZONE_ID_AUDITANDFIX');
const CF_ZONE_ID_CONTACTREPLYAI  = process.env.CF_ZONE_ID_CONTACTREPLYAI ?? null;

// ---------------------------------------------------------------------------
// AWS client config
// ---------------------------------------------------------------------------
const awsConfig = {
  region: AWS_REGION,
  credentials: { accessKeyId: AWS_ACCESS_KEY_ID, secretAccessKey: AWS_SECRET_ACCESS_KEY },
};

const sesv2  = new SESv2Client(awsConfig);
const sesv1  = new SESClient(awsConfig);
const sns    = new SNSClient(awsConfig);
const s3     = new S3Client(awsConfig);
const iam    = new IAMClient(awsConfig);
const sts    = new STSClient(awsConfig);

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
const DOMAINS = [
  'auditandfix.com',
  'send.auditandfix.com',
  'mail.auditandfix.com',
  'email.auditandfix.com',
  'outreach.auditandfix.com',
  'outbound.auditandfix.com',
  'eu.auditandfix.com',
  'sa.auditandfix.com',
  'contactreplyai.com',
];

// Zone ID lookup by domain root — subdomains of auditandfix.com use the same zone
function zoneIdForDomain(domain) {
  if (domain === 'contactreplyai.com' || domain.endsWith('.contactreplyai.com')) {
    return CF_ZONE_ID_CONTACTREPLYAI;
  }
  return CF_ZONE_ID_AUDITANDFIX;
}

const CONFIG_SET_NAME     = 'mmo-outbound';
const SNS_TOPIC_NAME      = process.env.SNS_TOPIC_NAME ?? 'auditandfix';
const SNS_TOPIC_ARN_OVERRIDE = process.env.SNS_TOPIC_ARN ?? null; // Use existing topic if provided
const S3_BUCKET_NAME      = 'auditandfix-ses-inbound';
const IAM_USER_NAME       = 'ses-sender';
const RECEIPT_RULE_SET    = 'mmo-inbound';
const RECEIPT_RULE_NAME   = 'mmo-inbound-rule';
const CF_WORKER_ENDPOINT  = 'https://email-webhook-worker.auditandfix.workers.dev/webhook/ses';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Cloudflare API request with Authorization Bearer */
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

/** Check if a CNAME already exists in a CF zone */
async function cfCnameExists(zoneId, name) {
  const res = await cfRequest('GET', `/zones/${zoneId}/dns_records?type=CNAME&name=${encodeURIComponent(name)}`);
  return res.result?.length > 0;
}

/** Create a CNAME record in CF */
async function cfCreateCname(zoneId, name, content) {
  if (DRY_RUN) {
    console.log(`  [dry-run] Would create CNAME ${name} → ${content}`);
    return;
  }
  await cfRequest('POST', `/zones/${zoneId}/dns_records`, {
    type: 'CNAME',
    name,
    content,
    proxied: false,
    ttl: 300,
  });
}

/** Sleep for ms milliseconds */
function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

/**
 * Derive SES SMTP password from IAM secret access key.
 *
 * Algorithm (AWS documented):
 *   1. HMAC-SHA256("AWS4" + secretKey, "11111111")
 *   2. HMAC-SHA256(step1, region)
 *   3. HMAC-SHA256(step2, "ses")
 *   4. HMAC-SHA256(step3, "aws4_request")
 *   5. HMAC-SHA256(step4, "SendRawEmail")
 *   6. Prepend version byte 0x04
 *   7. Base64-encode
 */
function deriveSmtpPassword(secretKey, region = 'us-east-1') {
  const message = 'SendRawEmail';
  const versionByte = Buffer.from([0x04]);

  let sig = crypto.createHmac('sha256', `AWS4${secretKey}`).update('11111111').digest();
  sig = crypto.createHmac('sha256', sig).update(region).digest();
  sig = crypto.createHmac('sha256', sig).update('ses').digest();
  sig = crypto.createHmac('sha256', sig).update('aws4_request').digest();
  sig = crypto.createHmac('sha256', sig).update(message).digest();

  return Buffer.concat([versionByte, sig]).toString('base64');
}

// ---------------------------------------------------------------------------
// Step 1: Verify domain identities
// ---------------------------------------------------------------------------
async function step1_verifyDomains() {
  console.log('\n── Step 1: Verify domain identities ──────────────────────────────────');
  const dkimTokensByDomain = {};

  for (const domain of DOMAINS) {
    if (DRY_RUN) {
      console.log(`  [dry-run] Would create SES identity for ${domain}`);
      dkimTokensByDomain[domain] = ['token1', 'token2', 'token3'];
      continue;
    }

    try {
      await sesv2.send(new CreateEmailIdentityCommand({ EmailIdentity: domain }));
      console.log(`  ✓ Created identity: ${domain}`);
    } catch (err) {
      if (err.name === 'AlreadyExistsException' || err.__type?.includes('AlreadyExists')) {
        console.log(`  ⚠ Already exists: ${domain}`);
      } else {
        console.error(`  ✗ Failed to create identity ${domain}:`, err.message);
        throw err;
      }
    }

    // Retrieve DKIM tokens
    const info = await sesv2.send(new GetEmailIdentityCommand({ EmailIdentity: domain }));
    const tokens = info.DkimAttributes?.Tokens ?? [];
    if (tokens.length === 0) {
      console.warn(`  ⚠ No DKIM tokens returned for ${domain} — may take a moment`);
    } else {
      console.log(`  ✓ DKIM tokens for ${domain}: ${tokens.join(', ')}`);
    }
    dkimTokensByDomain[domain] = tokens;
  }

  return dkimTokensByDomain;
}

// ---------------------------------------------------------------------------
// Step 1b: Clean up old Resend DNS records from Cloudflare
// ---------------------------------------------------------------------------
async function step1b_cleanupResendDns() {
  console.log('\n── Step 1b: Clean up old Resend DNS records ───────────────────────────');

  for (const domain of DOMAINS) {
    const zoneId = zoneIdForDomain(domain);
    if (!zoneId) continue;

    // Find Resend DKIM records: resend._domainkey.{domain} and resend{N}._domainkey.{domain}
    const dkimPatterns = [
      `resend._domainkey.${domain}`,
      `resend1._domainkey.${domain}`,
      `resend2._domainkey.${domain}`,
      `resend3._domainkey.${domain}`,
    ];

    for (const name of dkimPatterns) {
      try {
        const res = await cfRequest('GET', `/zones/${zoneId}/dns_records?type=CNAME&name=${encodeURIComponent(name)}`);
        for (const record of res.result ?? []) {
          if (DRY_RUN) {
            console.log(`  [dry-run] Would delete Resend DKIM: ${record.name} → ${record.content}`);
            continue;
          }
          await cfRequest('DELETE', `/zones/${zoneId}/dns_records/${record.id}`);
          console.log(`  ✓ Deleted Resend DKIM: ${record.name} → ${record.content}`);
        }
      } catch (err) {
        // Record doesn't exist — that's fine
      }
    }

    // Find Resend MX records (bounce handling): feedback-smtp.*.amazonses.com
    // and Resend-specific SPF includes or TXT records
    try {
      const mxRes = await cfRequest('GET', `/zones/${zoneId}/dns_records?type=MX&name=${encodeURIComponent(domain)}`);
      for (const record of mxRes.result ?? []) {
        if (record.content.includes('resend') || record.content.includes('feedback-smtp')) {
          if (DRY_RUN) {
            console.log(`  [dry-run] Would delete Resend MX: ${record.name} → ${record.content}`);
            continue;
          }
          await cfRequest('DELETE', `/zones/${zoneId}/dns_records/${record.id}`);
          console.log(`  ✓ Deleted Resend MX: ${record.name} → ${record.content}`);
        }
      }
    } catch (err) {
      // No MX records — fine
    }

    // Find Resend-specific TXT records (SPF on subdomains, verification records)
    try {
      const txtRes = await cfRequest('GET', `/zones/${zoneId}/dns_records?type=TXT&name=${encodeURIComponent(domain)}`);
      for (const record of txtRes.result ?? []) {
        // Don't touch the root SPF record — it already has include:amazonses.com
        // Only remove Resend-specific verification TXT records
        if (record.content.includes('resend-verification') || record.content.includes('resend_')) {
          if (DRY_RUN) {
            console.log(`  [dry-run] Would delete Resend TXT: ${record.name} = ${record.content.substring(0, 60)}...`);
            continue;
          }
          await cfRequest('DELETE', `/zones/${zoneId}/dns_records/${record.id}`);
          console.log(`  ✓ Deleted Resend TXT: ${record.name}`);
        }
      }
    } catch (err) {
      // No TXT records — fine
    }
  }

  console.log('  ✓ Resend DNS cleanup complete');
}

// ---------------------------------------------------------------------------
// Step 2: Add DKIM CNAME records via Cloudflare
// ---------------------------------------------------------------------------
async function step2_addDkimCnames(dkimTokensByDomain) {
  console.log('\n── Step 2: Add SES DKIM CNAME records via Cloudflare ────────────────');
  const manualCnames = []; // For domains without a CF zone ID

  for (const domain of DOMAINS) {
    const tokens = dkimTokensByDomain[domain] ?? [];
    const zoneId = zoneIdForDomain(domain);

    if (!zoneId) {
      console.warn(`  ⚠ No CF zone ID for ${domain} — skipping automated DNS`);
      for (const token of tokens) {
        manualCnames.push({
          name: `${token}._domainkey.${domain}`,
          content: `${token}.dkim.amazonses.com`,
        });
      }
      continue;
    }

    for (const token of tokens) {
      const recordName    = `${token}._domainkey.${domain}`;
      const recordContent = `${token}.dkim.amazonses.com`;

      if (DRY_RUN) {
        console.log(`  [dry-run] Would add CNAME ${recordName} → ${recordContent}`);
        continue;
      }

      try {
        const exists = await cfCnameExists(zoneId, recordName);
        if (exists) {
          console.log(`  ⚠ CNAME already exists: ${recordName}`);
        } else {
          await cfCreateCname(zoneId, recordName, recordContent);
          console.log(`  ✓ Created CNAME: ${recordName}`);
        }
      } catch (err) {
        console.error(`  ✗ Failed to create CNAME ${recordName}:`, err.message);
        manualCnames.push({ name: recordName, content: recordContent });
      }
    }
  }

  if (manualCnames.length > 0) {
    console.log('\n  ⚠ The following CNAMEs must be added manually:');
    for (const { name, content } of manualCnames) {
      console.log(`      ${name}  →  ${content}`);
    }
  }
}

// ---------------------------------------------------------------------------
// Step 3: Create configuration set
// ---------------------------------------------------------------------------
async function step3_createConfigSet() {
  console.log('\n── Step 3: Create SES configuration set ──────────────────────────────');

  if (DRY_RUN) {
    console.log(`  [dry-run] Would create configuration set: ${CONFIG_SET_NAME}`);
    return;
  }

  try {
    await sesv2.send(new CreateConfigurationSetCommand({
      ConfigurationSetName: CONFIG_SET_NAME,
    }));
    console.log(`  ✓ Created configuration set: ${CONFIG_SET_NAME}`);
  } catch (err) {
    if (err.name === 'AlreadyExistsException' || err.__type?.includes('AlreadyExists')) {
      console.log(`  ⚠ Configuration set already exists: ${CONFIG_SET_NAME}`);
    } else {
      throw err;
    }
  }
}

// ---------------------------------------------------------------------------
// Step 4: Create SNS topic
// ---------------------------------------------------------------------------
async function step4_createSnsTopic() {
  console.log('\n── Step 4: Create SNS topic ───────────────────────────────────────────');

  if (SNS_TOPIC_ARN_OVERRIDE) {
    console.log(`  ✓ Using existing SNS topic: ${SNS_TOPIC_ARN_OVERRIDE}`);
    return SNS_TOPIC_ARN_OVERRIDE;
  }

  if (DRY_RUN) {
    console.log(`  [dry-run] Would create SNS topic: ${SNS_TOPIC_NAME}`);
    return `arn:aws:sns:${AWS_REGION}:000000000000:${SNS_TOPIC_NAME}`;
  }

  // CreateTopic is idempotent — returns existing ARN if topic already exists
  const res = await sns.send(new CreateTopicCommand({ Name: SNS_TOPIC_NAME }));
  const topicArn = res.TopicArn;
  console.log(`  ✓ SNS topic ARN: ${topicArn}`);
  return topicArn;
}

// ---------------------------------------------------------------------------
// Step 5: Add SNS event destination to configuration set
// ---------------------------------------------------------------------------
async function step5_addEventDestination(topicArn) {
  console.log('\n── Step 5: Add SNS event destination ─────────────────────────────────');

  if (DRY_RUN) {
    console.log(`  [dry-run] Would add SNS event destination to ${CONFIG_SET_NAME}`);
    return;
  }

  try {
    await sesv2.send(new CreateConfigurationSetEventDestinationCommand({
      ConfigurationSetName: CONFIG_SET_NAME,
      EventDestinationName: 'sns-all-events',
      EventDestination: {
        Enabled: true,
        MatchingEventTypes: ['SEND', 'DELIVERY', 'BOUNCE', 'COMPLAINT'],
        SnsDestination: { TopicArn: topicArn },
      },
    }));
    console.log(`  ✓ Added SNS event destination`);
  } catch (err) {
    if (err.name === 'AlreadyExistsException' || err.__type?.includes('AlreadyExists')) {
      console.log(`  ⚠ Event destination already exists`);
    } else {
      throw err;
    }
  }
}

// ---------------------------------------------------------------------------
// Step 6: Subscribe CF Worker to SNS topic
// ---------------------------------------------------------------------------
async function step6_subscribeWorker(topicArn) {
  console.log('\n── Step 6: Subscribe CF Worker to SNS topic ───────────────────────────');

  if (DRY_RUN) {
    console.log(`  [dry-run] Would subscribe ${CF_WORKER_ENDPOINT} to ${topicArn}`);
    return;
  }

  // Check for existing subscriptions to avoid duplicates
  let alreadySubscribed = false;
  try {
    const existing = await sns.send(new ListSubscriptionsByTopicCommand({ TopicArn: topicArn }));
    alreadySubscribed = existing.Subscriptions?.some(
      s => s.Protocol === 'https' && s.Endpoint === CF_WORKER_ENDPOINT
    ) ?? false;
  } catch (err) {
    // If listing fails, attempt subscribe anyway
    console.warn(`  ⚠ Could not list subscriptions: ${err.message}`);
  }

  if (alreadySubscribed) {
    console.log(`  ⚠ Subscription already exists for ${CF_WORKER_ENDPOINT}`);
  } else {
    await sns.send(new SubscribeCommand({
      TopicArn: topicArn,
      Protocol: 'https',
      Endpoint: CF_WORKER_ENDPOINT,
    }));
    console.log(`  ✓ Subscribed ${CF_WORKER_ENDPOINT}`);
    console.log(`  ⚠ SNS will send a confirmation request — the CF Worker must confirm it`);
  }
}

// ---------------------------------------------------------------------------
// Step 7: Create S3 bucket
// ---------------------------------------------------------------------------
async function step7_createS3Bucket(accountId) {
  console.log('\n── Step 7: Create S3 bucket ───────────────────────────────────────────');

  if (DRY_RUN) {
    console.log(`  [dry-run] Would create S3 bucket: ${S3_BUCKET_NAME}`);
    return;
  }

  // Create bucket (idempotent for same owner/region)
  let bucketRegion = AWS_REGION;
  try {
    const createParams = { Bucket: S3_BUCKET_NAME };
    // us-east-1 must NOT include CreateBucketConfiguration — it's the default
    if (AWS_REGION !== 'us-east-1') {
      createParams.CreateBucketConfiguration = { LocationConstraint: AWS_REGION };
    }
    await s3.send(new CreateBucketCommand(createParams));
    console.log(`  ✓ Created S3 bucket: ${S3_BUCKET_NAME}`);
  } catch (err) {
    if (err.name === 'BucketAlreadyOwnedByYou' || err.name === 'BucketAlreadyExists') {
      console.log(`  ⚠ Bucket already exists: ${S3_BUCKET_NAME}`);
    } else {
      throw err;
    }
  }

  // Detect bucket region — GetBucketLocation must use us-east-1 global endpoint
  try {
    const globalS3 = new S3Client({ ...awsConfig, region: 'us-east-1' });
    const locRes = await globalS3.send(new GetBucketLocationCommand({ Bucket: S3_BUCKET_NAME }));
    // LocationConstraint is null/empty for us-east-1, otherwise the region string
    bucketRegion = locRes.LocationConstraint || 'us-east-1';
    if (bucketRegion !== AWS_REGION) {
      console.log(`  ⚠ Bucket is in ${bucketRegion}, creating region-specific S3 client`);
    }
  } catch (err) {
    console.log(`  ⚠ Could not detect bucket region (${err.message}), using ${AWS_REGION}`);
  }

  // Use a region-specific S3 client for policy/config operations
  const bucketS3 = bucketRegion !== AWS_REGION
    ? new S3Client({ ...awsConfig, region: bucketRegion })
    : s3;

  // Block all public access
  await bucketS3.send(new PutPublicAccessBlockCommand({
    Bucket: S3_BUCKET_NAME,
    PublicAccessBlockConfiguration: {
      BlockPublicAcls: true,
      IgnorePublicAcls: true,
      BlockPublicPolicy: true,
      RestrictPublicBuckets: true,
    },
  }));
  console.log(`  ✓ Blocked all public access`);

  // SSE-S3 default encryption
  await bucketS3.send(new PutBucketEncryptionCommand({
    Bucket: S3_BUCKET_NAME,
    ServerSideEncryptionConfiguration: {
      Rules: [{
        ApplyServerSideEncryptionByDefault: { SSEAlgorithm: 'AES256' },
        BucketKeyEnabled: true,
      }],
    },
  }));
  console.log(`  ✓ Enabled SSE-S3 encryption`);

  // 30-day lifecycle rule
  await bucketS3.send(new PutBucketLifecycleConfigurationCommand({
    Bucket: S3_BUCKET_NAME,
    LifecycleConfiguration: {
      Rules: [{
        ID: 'expire-after-30-days',
        Status: 'Enabled',
        Filter: { Prefix: '' },
        Expiration: { Days: 30 },
      }],
    },
  }));
  console.log(`  ✓ Set 30-day lifecycle expiration`);

  // Bucket policy: allow SES to PutObject, scoped to account
  const bucketPolicy = {
    Version: '2012-10-17',
    Statement: [{
      Sid: 'AllowSESPutObject',
      Effect: 'Allow',
      Principal: { Service: 'ses.amazonaws.com' },
      Action: 's3:PutObject',
      Resource: `arn:aws:s3:::${S3_BUCKET_NAME}/*`,
      Condition: {
        StringEquals: { 'aws:SourceAccount': accountId },
      },
    }],
  };

  await bucketS3.send(new PutBucketPolicyCommand({
    Bucket: S3_BUCKET_NAME,
    Policy: JSON.stringify(bucketPolicy),
  }));
  console.log(`  ✓ Applied bucket policy (SES PutObject, scoped to account ${accountId})`);
}

// ---------------------------------------------------------------------------
// Step 8: Create SES receipt rule set and rule
// NOTE: Receipt rules use SES v1 API
// ---------------------------------------------------------------------------
async function step8_createReceiptRules(topicArn) {
  console.log('\n── Step 8: Create SES receipt rule set and rule ──────────────────────');

  if (DRY_RUN) {
    console.log(`  [dry-run] Would create receipt rule set: ${RECEIPT_RULE_SET}`);
    console.log(`  [dry-run] Would create receipt rule: ${RECEIPT_RULE_NAME}`);
    return;
  }

  // Create rule set (AlreadyExists = ok)
  try {
    await sesv1.send(new CreateReceiptRuleSetCommand({ RuleSetName: RECEIPT_RULE_SET }));
    console.log(`  ✓ Created receipt rule set: ${RECEIPT_RULE_SET}`);
  } catch (err) {
    if (err.name === 'AlreadyExists' || err.name === 'AlreadyExistsException' || err.__type?.includes('AlreadyExists')) {
      console.log(`  ⚠ Receipt rule set already exists: ${RECEIPT_RULE_SET}`);
    } else {
      throw err;
    }
  }

  // Create rule
  try {
    await sesv1.send(new CreateReceiptRuleCommand({
      RuleSetName: RECEIPT_RULE_SET,
      Rule: {
        Name: RECEIPT_RULE_NAME,
        Enabled: true,
        TlsPolicy: 'Optional',
        Recipients: ['auditandfix.com', 'contactreplyai.com'],
        Actions: [
          {
            S3Action: {
              BucketName: S3_BUCKET_NAME,
              ObjectKeyPrefix: 'inbound/',
            },
          },
          {
            SNSAction: {
              TopicArn: topicArn,
              Encoding: 'UTF-8',
            },
          },
        ],
        ScanEnabled: true,
      },
    }));
    console.log(`  ✓ Created receipt rule: ${RECEIPT_RULE_NAME}`);
  } catch (err) {
    if (err.name === 'AlreadyExists' || err.name === 'AlreadyExistsException' || err.__type?.includes('AlreadyExists')) {
      console.log(`  ⚠ Receipt rule already exists: ${RECEIPT_RULE_NAME}`);
    } else {
      throw err;
    }
  }

  // Activate the rule set
  await sesv1.send(new SetActiveReceiptRuleSetCommand({ RuleSetName: RECEIPT_RULE_SET }));
  console.log(`  ✓ Activated receipt rule set: ${RECEIPT_RULE_SET}`);
}

// ---------------------------------------------------------------------------
// Step 9: Create IAM user and derive SMTP credentials
// ---------------------------------------------------------------------------
async function step9_createIamUser(accountId) {
  console.log('\n── Step 9: Create IAM user ────────────────────────────────────────────');

  if (DRY_RUN) {
    console.log(`  [dry-run] Would create IAM user: ${IAM_USER_NAME}`);
    return { accessKeyId: 'AKIAIOSFODNN7EXAMPLE', secretAccessKey: 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY' };
  }

  // Create user (skip if exists)
  try {
    await iam.send(new CreateUserCommand({ UserName: IAM_USER_NAME }));
    console.log(`  ✓ Created IAM user: ${IAM_USER_NAME}`);
  } catch (err) {
    if (err.name === 'EntityAlreadyExists' || err.name === 'EntityAlreadyExistsException') {
      console.log(`  ⚠ IAM user already exists: ${IAM_USER_NAME}`);
    } else {
      throw err;
    }
  }

  // Attach inline policy: SES send + S3 read on inbound bucket
  const policy = {
    Version: '2012-10-17',
    Statement: [
      {
        Sid: 'AllowSESSend',
        Effect: 'Allow',
        Action: ['ses:SendEmail', 'ses:SendRawEmail'],
        Resource: [
          `arn:aws:ses:${AWS_REGION}:${accountId}:identity/auditandfix.com`,
          `arn:aws:ses:${AWS_REGION}:${accountId}:identity/contactreplyai.com`,
          `arn:aws:ses:${AWS_REGION}:${accountId}:identity/send.auditandfix.com`,
          `arn:aws:ses:${AWS_REGION}:${accountId}:identity/mail.auditandfix.com`,
          `arn:aws:ses:${AWS_REGION}:${accountId}:identity/email.auditandfix.com`,
          `arn:aws:ses:${AWS_REGION}:${accountId}:identity/outreach.auditandfix.com`,
          `arn:aws:ses:${AWS_REGION}:${accountId}:identity/outbound.auditandfix.com`,
          `arn:aws:ses:${AWS_REGION}:${accountId}:identity/eu.auditandfix.com`,
          `arn:aws:ses:${AWS_REGION}:${accountId}:identity/sa.auditandfix.com`,
        ],
      },
      {
        Sid: 'AllowS3InboundRead',
        Effect: 'Allow',
        Action: ['s3:GetObject'],
        Resource: `arn:aws:s3:::${S3_BUCKET_NAME}/*`,
      },
    ],
  };

  await iam.send(new PutUserPolicyCommand({
    UserName: IAM_USER_NAME,
    PolicyName: 'ses-sender-policy',
    PolicyDocument: JSON.stringify(policy),
  }));
  console.log(`  ✓ Attached inline policy to ${IAM_USER_NAME}`);

  // Create access key
  const keyRes = await iam.send(new CreateAccessKeyCommand({ UserName: IAM_USER_NAME }));
  const accessKeyId     = keyRes.AccessKey.AccessKeyId;
  const secretAccessKey = keyRes.AccessKey.SecretAccessKey;
  console.log(`  ✓ Created access key: ${accessKeyId}`);

  return { accessKeyId, secretAccessKey };
}

// ---------------------------------------------------------------------------
// Step 10: Poll DKIM verification
// ---------------------------------------------------------------------------
async function step10_pollDkimVerification() {
  console.log('\n── Step 10: Polling DKIM verification ────────────────────────────────');
  console.log('  (Timeout: 10 min. Re-run script later if this times out.)\n');

  if (DRY_RUN) {
    console.log('  [dry-run] Would poll DKIM status for all domains');
    return;
  }

  const timeoutMs  = 10 * 60 * 1000;
  const pollMs     = 30 * 1000;
  const startTime  = Date.now();
  const pending    = new Set(DOMAINS);

  while (pending.size > 0 && Date.now() - startTime < timeoutMs) {
    for (const domain of [...pending]) {
      const info   = await sesv2.send(new GetEmailIdentityCommand({ EmailIdentity: domain }));
      const status = info.DkimAttributes?.Status;

      if (status === 'SUCCESS') {
        console.log(`  ✓ DKIM verified: ${domain}`);
        pending.delete(domain);
      } else {
        process.stdout.write(`  … ${domain}: ${status ?? 'PENDING'}\n`);
      }
    }

    if (pending.size > 0) {
      const elapsed = Math.round((Date.now() - startTime) / 1000);
      console.log(`  (${pending.size} pending, ${elapsed}s elapsed — waiting 30s)\n`);
      await sleep(pollMs);
    }
  }

  if (pending.size > 0) {
    console.warn('\n  ⚠ DKIM verification timed out for:');
    for (const domain of pending) {
      console.warn(`      ${domain}`);
    }
    console.warn('  Re-run the script to retry polling, or check SES console.');
  } else {
    console.log('\n  ✓ All domains DKIM-verified');
  }
}

// ---------------------------------------------------------------------------
// Switch MX records (Phase 4 — only when --switch-mx flag is passed)
// ---------------------------------------------------------------------------
async function switchMxRecords() {
  console.log('\n── Phase 4: Switching MX records ─────────────────────────────────────');

  if (DRY_RUN) {
    console.log('  [dry-run] Would update MX records for auditandfix.com to SES inbound');
    return;
  }

  const inboundSmtp = `inbound-smtp.${AWS_REGION}.amazonaws.com`;
  const zoneId = CF_ZONE_ID_AUDITANDFIX;

  // Fetch existing MX records
  const existing = await cfRequest('GET', `/zones/${zoneId}/dns_records?type=MX`);
  for (const record of existing.result ?? []) {
    console.log(`  ⚠ Existing MX record: ${record.name} ${record.content} (id: ${record.id})`);
    // Note: we intentionally don't delete them — user should verify first
  }

  // Create SES inbound MX record
  const mxName = 'auditandfix.com';
  const mxContent = `10 ${inboundSmtp}`;

  const exists = await cfRequest('GET', `/zones/${zoneId}/dns_records?type=MX&name=${encodeURIComponent(mxName)}&content=${encodeURIComponent(mxContent)}`);
  if (exists.result?.length > 0) {
    console.log(`  ⚠ MX record already points to SES`);
  } else {
    await cfRequest('POST', `/zones/${zoneId}/dns_records`, {
      type: 'MX',
      name: mxName,
      content: inboundSmtp,
      priority: 10,
      proxied: false,
      ttl: 300,
    });
    console.log(`  ✓ Created MX record: ${mxName} → ${inboundSmtp}`);
    console.log('  ⚠ You may want to remove old MX records via Cloudflare dashboard');
  }
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------
async function main() {
  console.log('AWS SES Provisioning Script');
  console.log('===========================');
  console.log(`Region: ${AWS_REGION}`);
  console.log(`Dry run: ${DRY_RUN}`);
  console.log(`Switch MX: ${SWITCH_MX}`);

  // Get AWS account ID (needed for bucket policy + IAM policies)
  let accountId = '000000000000';
  if (!DRY_RUN) {
    const identity = await sts.send(new GetCallerIdentityCommand({}));
    accountId = identity.Account;
    console.log(`Account ID: ${accountId}`);
  }

  const dkimTokensByDomain = await step1_verifyDomains();
  await step1b_cleanupResendDns();
  await step2_addDkimCnames(dkimTokensByDomain);
  await step3_createConfigSet();
  const topicArn = await step4_createSnsTopic();
  await step5_addEventDestination(topicArn);
  await step6_subscribeWorker(topicArn);
  await step7_createS3Bucket(accountId);
  await step8_createReceiptRules(topicArn);
  const { accessKeyId: sesKeyId, secretAccessKey: sesSecret } = await step9_createIamUser(accountId);
  await step10_pollDkimVerification();

  if (SWITCH_MX) {
    await switchMxRecords();
  }

  // Derive SMTP credentials
  const smtpUsername = sesKeyId;
  const smtpPassword = deriveSmtpPassword(sesSecret, AWS_REGION);

  // ---------------------------------------------------------------------------
  // Print output block
  // ---------------------------------------------------------------------------
  const effectiveTopicArn = DRY_RUN
    ? `arn:aws:sns:${AWS_REGION}:${accountId}:${SNS_TOPIC_NAME}`
    : topicArn;

  console.log('\n\n═══════════════════════════════════════════════════════════════════════');
  console.log('  PROVISIONING COMPLETE — Copy the values below');
  console.log('═══════════════════════════════════════════════════════════════════════\n');

  console.log(`# ── Add to 333Method/.env and 2Step/.env ──────────────────────────────
AWS_ACCESS_KEY_ID=${sesKeyId}
AWS_SECRET_ACCESS_KEY=${sesSecret}
AWS_REGION=${AWS_REGION}
SES_CONFIGURATION_SET=${CONFIG_SET_NAME}
SES_INBOUND_BUCKET=${S3_BUCKET_NAME}
SNS_TOPIC_ARN=${effectiveTopicArn}
EMAIL_PROVIDER=resend  # flip to 'ses' when ready
SES_WARMUP_START_DATE=  # set when you start sending via SES

# ── Add to auditandfix-website .htaccess / wp-config / worker secrets ──────
SES_SMTP_HOST=email-smtp.${AWS_REGION}.amazonaws.com
SES_SMTP_PORT=587
SES_SMTP_USERNAME=${smtpUsername}
SES_SMTP_PASSWORD=${smtpPassword}

# ── Add as CF Worker secret (run from host terminal) ───────────────────────
# wrangler secret put SNS_TOPIC_ARN
# (paste when prompted):
# ${effectiveTopicArn}
`);

  console.log('═══════════════════════════════════════════════════════════════════════');
  console.log('⚠  REVOKE the admin AWS access key now.');
  console.log('   The ses-sender key above is all you need for runtime.');
  console.log('   https://console.aws.amazon.com/iam/home#/security_credentials');
  console.log('═══════════════════════════════════════════════════════════════════════\n');

  if (!SWITCH_MX) {
    console.log('Note: MX records were NOT changed. Run with --switch-mx when ready for Phase 4.\n');
  }
}

main().catch(err => {
  console.error('\n✗ Fatal error:', err.message ?? err);
  if (err.stack) console.error(err.stack);
  process.exit(1);
});
