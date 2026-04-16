#!/usr/bin/env bash
# setup-ses-inbound.sh — Provision AWS infrastructure for E2E email receipt tests.
#
# Creates (all idempotent — safe to re-run):
#   - S3 bucket  mmo-e2e-inbound-<account-id>  in us-west-2
#   - Bucket policy allowing SES (us-west-2) to write raw emails
#   - IAM user   mmo-e2e-email-reader  with GetObject/ListBucket/DeleteObject
#   - IAM access key for that user (only created once; printed once)
#   - SES receipt rule set  e2e-inbound  (us-west-2)
#   - SES receipt rule accepting *@e2e.auditandfix.com → S3 prefix incoming/
#   - Sets the rule set as the active rule set in us-west-2
#
# After running, add to Hostinger DNS for auditandfix.com:
#   MX  e2e  10  inbound-smtp.us-west-2.amazonaws.com
#
# Requirements:
#   aws CLI installed and configured (admin-level credentials in default profile or AWS_PROFILE)
#
# Usage:
#   bash scripts/setup-ses-inbound.sh [--dry-run]

set -euo pipefail

DRY_RUN=0
for arg in "$@"; do [[ "$arg" == "--dry-run" ]] && DRY_RUN=1; done
[[ $DRY_RUN == 1 ]] && echo "⚠  DRY RUN — no AWS changes will be made" && echo

# ── Prerequisites ────────────────────────────────────────────────────────────

if ! command -v aws &>/dev/null; then
  echo "✗ aws CLI not found. Install from https://aws.amazon.com/cli/" >&2
  exit 1
fi

echo "── Resolving AWS identity..."
ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
echo "   Account: $ACCOUNT_ID"
echo

REGION="us-west-2"
BUCKET="mmo-e2e-inbound-${ACCOUNT_ID}"
IAM_USER="mmo-e2e-email-reader"
RULE_SET="e2e-inbound"
RULE_NAME="accept-e2e-auditandfix"
DOMAIN="e2e.auditandfix.com"

# ── S3 bucket ────────────────────────────────────────────────────────────────

echo "── S3 bucket: $BUCKET"

if aws s3api head-bucket --bucket "$BUCKET" --region "$REGION" 2>/dev/null; then
  echo "   Already exists — skipping create"
else
  if [[ $DRY_RUN == 0 ]]; then
    aws s3api create-bucket \
      --bucket "$BUCKET" \
      --region "$REGION" \
      --create-bucket-configuration LocationConstraint="$REGION"
    echo "   Created"
  else
    echo "   [dry-run] Would create bucket"
  fi
fi

# Block all public access
if [[ $DRY_RUN == 0 ]]; then
  aws s3api put-public-access-block \
    --bucket "$BUCKET" \
    --public-access-block-configuration \
      "BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true"
  echo "   Public access blocked"
fi

# Bucket policy — allow SES to put objects
BUCKET_POLICY=$(cat <<EOF
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "AllowSESPuts",
      "Effect": "Allow",
      "Principal": { "Service": "ses.amazonaws.com" },
      "Action": "s3:PutObject",
      "Resource": "arn:aws:s3:::${BUCKET}/*",
      "Condition": {
        "StringEquals": { "aws:Referer": "${ACCOUNT_ID}" }
      }
    }
  ]
}
EOF
)

if [[ $DRY_RUN == 0 ]]; then
  aws s3api put-bucket-policy --bucket "$BUCKET" --policy "$BUCKET_POLICY"
  echo "   Bucket policy applied (SES PutObject allowed)"
else
  echo "   [dry-run] Would apply bucket policy"
fi

# 30-day lifecycle rule to auto-delete old test emails
LIFECYCLE=$(cat <<'EOF'
{
  "Rules": [
    {
      "ID": "expire-e2e-emails",
      "Status": "Enabled",
      "Filter": { "Prefix": "incoming/" },
      "Expiration": { "Days": 30 }
    }
  ]
}
EOF
)

if [[ $DRY_RUN == 0 ]]; then
  aws s3api put-bucket-lifecycle-configuration \
    --bucket "$BUCKET" \
    --lifecycle-configuration "$LIFECYCLE"
  echo "   30-day lifecycle rule set on incoming/"
fi

echo

# ── IAM user + policy ────────────────────────────────────────────────────────

echo "── IAM user: $IAM_USER"

USER_EXISTS=0
aws iam get-user --user-name "$IAM_USER" 2>/dev/null && USER_EXISTS=1 || true

if [[ $USER_EXISTS == 0 ]]; then
  if [[ $DRY_RUN == 0 ]]; then
    aws iam create-user --user-name "$IAM_USER"
    echo "   Created"
  else
    echo "   [dry-run] Would create user"
  fi
else
  echo "   Already exists — skipping create"
fi

IAM_POLICY=$(cat <<EOF
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "s3:ListBucket",
        "s3:GetObject",
        "s3:DeleteObject"
      ],
      "Resource": [
        "arn:aws:s3:::${BUCKET}",
        "arn:aws:s3:::${BUCKET}/*"
      ]
    }
  ]
}
EOF
)

if [[ $DRY_RUN == 0 ]]; then
  aws iam put-user-policy \
    --user-name "$IAM_USER" \
    --policy-name "e2e-email-bucket-access" \
    --policy-document "$IAM_POLICY"
  echo "   Inline policy applied (S3 read/delete on bucket)"
fi

# Create access key only if user has none
KEY_COUNT=$(aws iam list-access-keys --user-name "$IAM_USER" --query 'length(AccessKeyMetadata)' --output text 2>/dev/null || echo "0")

if [[ "$KEY_COUNT" == "0" ]] && [[ $DRY_RUN == 0 ]]; then
  KEY_OUTPUT=$(aws iam create-access-key --user-name "$IAM_USER")
  ACCESS_KEY=$(echo "$KEY_OUTPUT" | python3 -c "import sys,json; k=json.load(sys.stdin)['AccessKey']; print(k['AccessKeyId'])")
  SECRET_KEY=$(echo "$KEY_OUTPUT" | python3 -c "import sys,json; k=json.load(sys.stdin)['AccessKey']; print(k['SecretAccessKey'])")
  echo "   Access key created"
  KEY_CREATED=1
else
  if [[ "$KEY_COUNT" != "0" ]]; then
    echo "   Access key already exists — skipping (retrieve from AWS Console if needed)"
  fi
  ACCESS_KEY="<retrieve-from-aws-console>"
  SECRET_KEY="<retrieve-from-aws-console>"
  KEY_CREATED=0
fi

echo

# ── SES receipt rule set ─────────────────────────────────────────────────────

echo "── SES receipt rule set: $RULE_SET (region: $REGION)"

RULE_SET_EXISTS=0
aws ses describe-receipt-rule-set --rule-set-name "$RULE_SET" --region "$REGION" 2>/dev/null \
  && RULE_SET_EXISTS=1 || true

if [[ $RULE_SET_EXISTS == 0 ]]; then
  if [[ $DRY_RUN == 0 ]]; then
    aws ses create-receipt-rule-set --rule-set-name "$RULE_SET" --region "$REGION"
    echo "   Rule set created"
  else
    echo "   [dry-run] Would create rule set"
  fi
else
  echo "   Already exists"
fi

# Receipt rule: capture *@e2e.auditandfix.com → S3
RULE_JSON=$(cat <<EOF
{
  "Name": "${RULE_NAME}",
  "Enabled": true,
  "TlsPolicy": "Optional",
  "Recipients": ["${DOMAIN}"],
  "Actions": [
    {
      "S3Action": {
        "BucketName": "${BUCKET}",
        "ObjectKeyPrefix": "incoming/"
      }
    }
  ],
  "ScanEnabled": false
}
EOF
)

RULE_EXISTS=0
aws ses describe-receipt-rule \
  --rule-set-name "$RULE_SET" \
  --rule-name "$RULE_NAME" \
  --region "$REGION" 2>/dev/null && RULE_EXISTS=1 || true

if [[ $DRY_RUN == 0 ]]; then
  if [[ $RULE_EXISTS == 0 ]]; then
    aws ses create-receipt-rule \
      --rule-set-name "$RULE_SET" \
      --rule "$RULE_JSON" \
      --region "$REGION"
    echo "   Rule created: $DOMAIN → s3://$BUCKET/incoming/"
  else
    aws ses update-receipt-rule \
      --rule-set-name "$RULE_SET" \
      --rule "$RULE_JSON" \
      --region "$REGION"
    echo "   Rule updated: $DOMAIN → s3://$BUCKET/incoming/"
  fi
fi

# Activate the rule set
if [[ $DRY_RUN == 0 ]]; then
  aws ses set-active-receipt-rule-set --rule-set-name "$RULE_SET" --region "$REGION"
  echo "   Rule set set as active in $REGION"
fi

echo

# ── Summary ──────────────────────────────────────────────────────────────────

echo "════════════════════════════════════════════════════════════"
echo " Setup complete"
echo "════════════════════════════════════════════════════════════"
echo
echo "1. Add to Hostinger DNS (auditandfix.com zone):"
echo "   Type:  MX"
echo "   Name:  e2e"
echo "   Value: 10 inbound-smtp.${REGION}.amazonaws.com"
echo "   TTL:   3600"
echo
echo "2. Add to tests/.env in both projects:"
echo "   E2E_AWS_ACCESS_KEY_ID=${ACCESS_KEY}"
if [[ ${KEY_CREATED:-0} == 1 ]]; then
  echo "   E2E_AWS_SECRET_ACCESS_KEY=${SECRET_KEY}"
fi
echo "   E2E_EMAIL_BUCKET=${BUCKET}"
echo "   E2E_EMAIL_REGION=${REGION}"
echo
echo "3. Deploy CRAI Worker + set secret (host terminal):"
echo "   cd ~/code/ContactReplyAI/workers"
echo "   npx wrangler secret put E2E_SHARED_SECRET"
echo "   npx wrangler deploy"
echo
echo "4. Add to auditandfix.com private/secrets.php:"
echo "   putenv('E2E_HARNESS_ENABLED=1');"
echo "   putenv('E2E_SHARED_SECRET=<same-value-as-wrangler-secret>');"
echo
echo "Wait ~5 min after adding the MX record before running email receipt tests."
echo
