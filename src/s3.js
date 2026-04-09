/**
 * S3 Upload Utilities
 *
 * Thin wrappers around @aws-sdk/client-s3.
 * Uses AWS_ADMIN_ACCESS_KEY_ID / AWS_ADMIN_SECRET_ACCESS_KEY from env
 * (or falls back to instance-role / ambient credentials if those are absent).
 *
 * Environment variables (loaded before calling):
 *   AWS_ADMIN_ACCESS_KEY_ID      — IAM key with s3:PutObject, s3:ListBucket, s3:DeleteObject
 *   AWS_ADMIN_SECRET_ACCESS_KEY  — corresponding secret
 *   BACKUP_S3_REGION             — AWS region (default: ap-southeast-2)
 */

import {
  S3Client,
  PutObjectCommand,
  ListObjectsV2Command,
  DeleteObjectCommand,
} from '@aws-sdk/client-s3';
import { readFileSync } from 'fs';

function getS3Client() {
  const region = process.env.BACKUP_S3_REGION || 'ap-southeast-2';

  if (!process.env.AWS_ADMIN_ACCESS_KEY_ID) {
    throw new Error(
      '[s3] AWS_ADMIN_ACCESS_KEY_ID is not set — cannot create S3 client',
    );
  }
  if (!process.env.AWS_ADMIN_SECRET_ACCESS_KEY) {
    throw new Error(
      '[s3] AWS_ADMIN_SECRET_ACCESS_KEY is not set — cannot create S3 client',
    );
  }

  return new S3Client({
    region,
    credentials: {
      accessKeyId: process.env.AWS_ADMIN_ACCESS_KEY_ID,
      secretAccessKey: process.env.AWS_ADMIN_SECRET_ACCESS_KEY,
    },
  });
}

/**
 * Upload a local file to S3.
 *
 * @param {string} localPath  — absolute path to the file to upload
 * @param {string} s3Key      — S3 object key (e.g. "backups/customers/customers-2026-04-09.sqlite")
 * @param {string} bucket     — S3 bucket name
 * @returns {Promise<void>}
 */
export async function uploadToS3(localPath, s3Key, bucket) {
  if (!bucket) throw new Error('[s3] uploadToS3: bucket is required');

  const client = getS3Client();
  const body = readFileSync(localPath);

  await client.send(new PutObjectCommand({
    Bucket: bucket,
    Key: s3Key,
    Body: body,
    ServerSideEncryption: 'AES256',
  }));
}

/**
 * List all objects in a bucket under a given key prefix.
 *
 * @param {string} prefix  — e.g. "backups/customers/"
 * @param {string} bucket
 * @returns {Promise<Array<{Key: string, LastModified: Date, Size: number}>>}
 */
export async function listS3Objects(prefix, bucket) {
  if (!bucket) throw new Error('[s3] listS3Objects: bucket is required');

  const client = getS3Client();
  const objects = [];
  let continuationToken;

  do {
    const resp = await client.send(new ListObjectsV2Command({
      Bucket: bucket,
      Prefix: prefix,
      ContinuationToken: continuationToken,
    }));
    for (const obj of (resp.Contents || [])) {
      objects.push({ Key: obj.Key, LastModified: obj.LastModified, Size: obj.Size });
    }
    continuationToken = resp.NextContinuationToken;
  } while (continuationToken);

  return objects;
}

/**
 * Delete a single S3 object by key.
 *
 * @param {string} key
 * @param {string} bucket
 * @returns {Promise<void>}
 */
export async function deleteS3Object(key, bucket) {
  if (!bucket) throw new Error('[s3] deleteS3Object: bucket is required');

  const client = getS3Client();
  await client.send(new DeleteObjectCommand({ Bucket: bucket, Key: key }));
}

export default { uploadToS3, listS3Objects, deleteS3Object };
