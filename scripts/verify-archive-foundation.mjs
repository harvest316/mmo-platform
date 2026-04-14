#!/usr/bin/env node
import { STSClient, GetCallerIdentityCommand } from '@aws-sdk/client-sts';
import { S3Client, PutObjectCommand, HeadObjectCommand, GetBucketVersioningCommand } from '@aws-sdk/client-s3';
import { readFileSync } from 'node:fs';
import { randomBytes } from 'node:crypto';

const env = Object.fromEntries(
  readFileSync('/home/jason/code/mmo-platform/.env', 'utf8')
    .split('\n')
    .filter((l) => l && !l.startsWith('#') && l.includes('='))
    .map((l) => { const i = l.indexOf('='); return [l.slice(0, i).trim(), l.slice(i + 1).trim()]; })
);

const accessKeyId = env.ARCHIVE_AWS_ACCESS_KEY_ID;
const secretAccessKey = env.ARCHIVE_AWS_SECRET_ACCESS_KEY;
if (!accessKeyId || !secretAccessKey) {
  console.error('FAIL: ARCHIVE_AWS_ACCESS_KEY_ID or _SECRET_ACCESS_KEY missing from .env');
  process.exit(1);
}

const region = 'ap-southeast-2';
const creds = { accessKeyId, secretAccessKey };

const bucketProd = 'mmo-comms-archive';
const bucketSandbox = 'mmo-comms-archive-sandbox';

const report = [];
const line = (k, v) => report.push(`${k.padEnd(40)} ${v}`);

try {
  const sts = new STSClient({ region, credentials: creds });
  const id = await sts.send(new GetCallerIdentityCommand({}));
  line('Account ID', id.Account);
  line('Caller ARN', id.Arn);
  if (!id.Arn.includes('mmo-comms-archive-writer')) {
    line('WARNING', `caller is not mmo-comms-archive-writer (got: ${id.Arn})`);
  }
} catch (e) {
  line('STS GetCallerIdentity', `FAIL: ${e.name}: ${e.message}`);
  console.log(report.join('\n'));
  process.exit(2);
}

const s3 = new S3Client({ region, credentials: creds });

// Test 1: Confirm we can PUT into prod bucket (default 7y retention will auto-apply).
// Use a hash-suffixed key so repeated runs don't collide, and a small payload.
const probeKey = `probe/${new Date().toISOString()}_${randomBytes(6).toString('hex')}.txt`;
const probeBody = `verify-archive-foundation probe ${new Date().toISOString()}\n`;

for (const bucket of [bucketProd, bucketSandbox]) {
  try {
    const resp = await s3.send(new PutObjectCommand({
      Bucket: bucket,
      Key: probeKey,
      Body: probeBody,
      ContentType: 'text/plain',
    }));
    line(`PutObject → ${bucket}`, `OK (VersionId=${resp.VersionId ? resp.VersionId.slice(0, 8) + '...' : 'none'})`);
  } catch (e) {
    line(`PutObject → ${bucket}`, `FAIL: ${e.name}: ${e.message}`);
    continue;
  }

  try {
    const head = await s3.send(new HeadObjectCommand({ Bucket: bucket, Key: probeKey }));
    line(`  HeadObject ObjectLockMode`, head.ObjectLockMode || 'NONE');
    line(`  HeadObject RetainUntil`, head.ObjectLockRetainUntilDate?.toISOString() || 'NONE');
    line(`  HeadObject ServerSideEncryption`, head.ServerSideEncryption || 'NONE');
    line(`  HeadObject SSEKMSKeyId`, head.SSEKMSKeyId ? 'present' : 'NONE');
    if (head.ObjectLockMode !== 'COMPLIANCE') {
      line(`  ERROR`, `${bucket} default retention is NOT compliance mode — bucket misconfigured`);
    }
    if (head.ServerSideEncryption !== 'aws:kms') {
      line(`  ERROR`, `${bucket} SSE is not KMS — bucket misconfigured`);
    }
  } catch (e) {
    line(`  HeadObject ${bucket}`, `FAIL: ${e.name}: ${e.message}`);
  }
}

console.log(report.join('\n'));
