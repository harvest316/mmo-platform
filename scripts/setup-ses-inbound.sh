#!/usr/bin/env bash
# setup-ses-inbound.sh — Provision AWS infrastructure for E2E email receipt tests.
#
# Creates (all idempotent — safe to re-run):
#   - S3 bucket  mmo-e2e-inbound-<account-id>  in ap-southeast-2
#   - Bucket policy allowing SES to write raw emails
#   - IAM user   mmo-e2e-email-reader  with GetObject/ListBucket/DeleteObject
#   - IAM access key for that user (only created once; printed once)
#   - SES receipt rule  accept-e2e-{domain}  inside rule set  mmo-inbound  (DR-240)
#     (rule set mmo-inbound is the single active set — never activates a separate set)
#   - SES receipt rule accepting *@e2e.{PARENT_DOMAIN} → S3 prefix incoming/
#
# DR-240: All receipt rules must live in mmo-inbound. Never activate a separate
# rule set — doing so would take down production inbound for all tenants.
#
# After running, add to your DNS provider (set PARENT_DOMAIN first):
#   MX  e2e  10  inbound-smtp.ap-southeast-2.amazonaws.com
#
# Requirements:
#   aws CLI installed and configured (admin-level credentials in default profile or AWS_PROFILE)
#
# Usage:
#   bash scripts/setup-ses-inbound.sh [--dry-run] [--profile=<aws-profile>]
#   Default profile: mmo-admin

set -euo pipefail

DRY_RUN=0
PROFILE="mmo-admin"
for arg in "$@"; do
  [[ "$arg" == "--dry-run" ]] && DRY_RUN=1
  [[ "$arg" == --profile=* ]] && PROFILE="${arg#--profile=}"
done
[[ $DRY_RUN == 1 ]] && echo "⚠  DRY RUN — no AWS changes will be made" && echo

# Thread --profile through every aws CLI call transparently
aws() { command aws --profile "$PROFILE" "$@"; }

# ── Prerequisites ────────────────────────────────────────────────────────────

if ! command -v aws &>/dev/null; then
  echo "✗ aws CLI not found. Install from https://aws.amazon.com/cli/" >&2
  exit 1
fi

echo "── Resolving AWS identity..."
ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
echo "   Account: $ACCOUNT_ID"
echo

REGION="ap-southeast-2"  # Sydney — must match verified domain region (SES inbound regional requirement)
BUCKET="mmo-e2e-inbound-${ACCOUNT_ID}"
IAM_USER="mmo-e2e-email-reader"
RULE_SET="mmo-inbound"
PARENT_DOMAIN="${PARENT_DOMAIN:?PARENT_DOMAIN must be set (e.g. export PARENT_DOMAIN=auditandfix.com)}"
DOMAIN="${E2E_DOMAIN:-e2e.${PARENT_DOMAIN}}"
RULE_NAME="${RULE_NAME:-accept-e2e-${PARENT_DOMAIN%%.*}}"

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

# ── SES domain identity verification (required for email receiving) ──────────
#
# SES only delivers inbound email for domains that are verified in the same
# region as the receipt rule set. Without this, emails are silently discarded
# after the SMTP submission returns 250 OK.

echo "── SES domain identity: $PARENT_DOMAIN (region: $REGION)"

VERIFY_STATUS=$(aws ses get-identity-verification-attributes \
  --identities "$PARENT_DOMAIN" \
  --region "$REGION" \
  --query "VerificationAttributes.\"${PARENT_DOMAIN}\".VerificationStatus" \
  --output text 2>/dev/null || echo "NotFound")

if [[ "$VERIFY_STATUS" == "Success" ]]; then
  echo "   ${PARENT_DOMAIN} already verified in ${REGION} ✓"
else
  echo "   Status: ${VERIFY_STATUS} — initiating domain verification..."
  if [[ $DRY_RUN == 0 ]]; then
    VERIFY_TOKEN=$(aws ses verify-domain-identity \
      --domain "$PARENT_DOMAIN" \
      --region "$REGION" \
      --query "VerificationToken" \
      --output text)
    echo "   Verification token: $VERIFY_TOKEN"
    echo ""
    echo "   ⚠  ADD this DNS TXT record in Hostinger BEFORE running tests:"
    echo "   Name:  _amazonses.${PARENT_DOMAIN}"
    echo "   Type:  TXT"
    echo "   Value: ${VERIFY_TOKEN}"
    echo "   TTL:   3600"
    echo ""
    echo "   Then wait ~5 min and re-run this script to confirm: Success"
    NEEDS_DNS_VERIFY=1
  else
    echo "   [dry-run] Would call verify-domain-identity for ${PARENT_DOMAIN}"
  fi
fi

echo

# ── SES receipt rule set ─────────────────────────────────────────────────────

echo "── SES receipt rule in rule set: $RULE_SET (region: $REGION)"

# mmo-inbound is the active rule set (managed by setup-ses.mjs). Verify it exists.
RULE_SET_EXISTS=0
aws ses describe-receipt-rule-set --rule-set-name "$RULE_SET" --region "$REGION" 2>/dev/null \
  && RULE_SET_EXISTS=1 || true

if [[ $RULE_SET_EXISTS == 0 ]]; then
  echo "   ✗ Rule set '$RULE_SET' not found — run setup-ses.mjs first" >&2
  exit 1
else
  echo "   ✓ Rule set exists"
fi

# Receipt rule: capture *@${DOMAIN} → S3
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

# mmo-inbound is the single active rule set (DR-240). Never activate a separate
# rule set here — doing so would clobber production inbound for all tenants.

echo

# ── Summary ──────────────────────────────────────────────────────────────────

echo "════════════════════════════════════════════════════════════"
if [[ ${NEEDS_DNS_VERIFY:-0} == 1 ]]; then
  echo " Setup INCOMPLETE — DNS records required before tests will work"
else
  echo " Setup complete"
fi
echo "════════════════════════════════════════════════════════════"
echo
echo "DNS records to add for ${PARENT_DOMAIN}:"
echo ""
if [[ ${NEEDS_DNS_VERIFY:-0} == 1 ]]; then
  echo "1. Domain verification TXT record (required for SES receiving):"
  echo "   Name:  _amazonses.${PARENT_DOMAIN}"
  echo "   Type:  TXT"
  echo "   Value: ${VERIFY_TOKEN:-<run-script-again-to-get-token>}"
  echo "   TTL:   3600"
  echo ""
  echo "2. MX record for inbound email routing:"
else
  echo "1. MX record for inbound email routing:"
fi
echo "   Name:  e2e"
echo "   Type:  MX"
echo "   Value: 10 inbound-smtp.${REGION}.amazonaws.com"
echo "   TTL:   3600"
echo
echo "After adding DNS records, wait ~5 min then verify:"
echo "   aws --profile mmo-admin ses get-identity-verification-attributes \\"
echo "     --identities ${PARENT_DOMAIN} --region ${REGION}"
echo "   (VerificationStatus should be 'Success')"
echo
echo "Tests/.env vars:"
echo "   E2E_AWS_ACCESS_KEY_ID=${ACCESS_KEY}"
if [[ ${KEY_CREATED:-0} == 1 ]]; then
  echo "   E2E_AWS_SECRET_ACCESS_KEY=${SECRET_KEY}"
fi
echo "   E2E_EMAIL_BUCKET=${BUCKET}"
echo "   E2E_EMAIL_REGION=${REGION}"
echo
echo "${PARENT_DOMAIN} site/.htaccess (already set — verify value matches):"
echo "   SetEnv E2E_HARNESS_ENABLED 1"
echo "   SetEnv E2E_SHARED_SECRET \"<same-value-as-wrangler-secret>\""
echo
