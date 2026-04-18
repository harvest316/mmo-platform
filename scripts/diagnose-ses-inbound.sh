#!/usr/bin/env bash
# diagnose-ses-inbound.sh — Check SES inbound receipt configuration in us-west-2.
#
# Run from host terminal:
#   cd ~/code/mmo-platform
#   bash scripts/diagnose-ses-inbound.sh [--profile=<aws-profile>]
#   Default profile: mmo-admin

set -euo pipefail

PROFILE="mmo-admin"
for arg in "$@"; do
  [[ "$arg" == --profile=* ]] && PROFILE="${arg#--profile=}"
done

aws() { command aws --profile "$PROFILE" "$@"; }

REGION="ap-southeast-2"
PARENT_DOMAIN="${PARENT_DOMAIN:?PARENT_DOMAIN must be set (e.g. export PARENT_DOMAIN=auditandfix.com)}"
E2E_DOMAIN="${E2E_DOMAIN:-e2e.${PARENT_DOMAIN}}"
ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
BUCKET="mmo-e2e-inbound-${ACCOUNT_ID}"

echo "════════════════════════════════════════════════════════════"
echo " SES inbound diagnostics — region: $REGION"
echo "════════════════════════════════════════════════════════════"
echo

# ── 1. Domain verification status ────────────────────────────────────────────
echo "── 1. Domain verification: $PARENT_DOMAIN in $REGION"
VERIFY_STATUS=$(aws ses get-identity-verification-attributes \
  --identities "$PARENT_DOMAIN" \
  --region "$REGION" \
  --query "VerificationAttributes.\"${PARENT_DOMAIN}\".VerificationStatus" \
  --output text 2>/dev/null || echo "NotFound")
echo "   Status: $VERIFY_STATUS"
if [[ "$VERIFY_STATUS" != "Success" ]]; then
  echo "   ✗ PROBLEM: Domain not verified in $REGION — SES will not deliver inbound email."
  echo "   FIX: bash scripts/setup-ses-inbound.sh  (adds verification TXT record step)"
else
  echo "   ✓ Verified"
fi
echo

# ── 2. Active receipt rule set ────────────────────────────────────────────────
echo "── 2. Active receipt rule set in $REGION"
ACTIVE_SET=$(aws ses describe-active-receipt-rule-set \
  --region "$REGION" \
  --query "Metadata.Name" \
  --output text 2>/dev/null || echo "None")
echo "   Active rule set: $ACTIVE_SET"
if [[ "$ACTIVE_SET" == "None" || "$ACTIVE_SET" == "None" ]]; then
  echo "   ✗ PROBLEM: No active receipt rule set — emails will be rejected."
  echo "   FIX: bash scripts/setup-ses-inbound.sh"
else
  echo "   ✓ Active"
fi
echo

# ── 3. Receipt rule for e2e domain ────────────────────────────────────────────
echo "── 3. Receipt rule for $E2E_DOMAIN in rule set: $ACTIVE_SET"
if [[ "$ACTIVE_SET" != "None" ]]; then
  RULE_OUTPUT=$(aws ses describe-receipt-rule \
    --rule-set-name "$ACTIVE_SET" \
    --rule-name "accept-e2e-auditandfix" \
    --region "$REGION" 2>/dev/null || echo "NotFound")
  if [[ "$RULE_OUTPUT" == "NotFound" ]]; then
    echo "   ✗ PROBLEM: Rule 'accept-e2e-auditandfix' not found in active rule set."
    echo "   FIX: bash scripts/setup-ses-inbound.sh"
  else
    RULE_ENABLED=$(echo "$RULE_OUTPUT" | python3 -c "import sys,json; r=json.load(sys.stdin)['Rule']; print(r.get('Enabled','?'))" 2>/dev/null || echo "?")
    echo "   Rule enabled: $RULE_ENABLED"
    RECIPIENTS=$(echo "$RULE_OUTPUT" | python3 -c "import sys,json; r=json.load(sys.stdin)['Rule']; print(r.get('Recipients',[]))" 2>/dev/null || echo "?")
    echo "   Recipients: $RECIPIENTS"
    if [[ "$RULE_ENABLED" == "True" ]]; then
      echo "   ✓ Rule is enabled"
    else
      echo "   ✗ PROBLEM: Rule is disabled."
    fi
  fi
else
  echo "   (skipped — no active rule set)"
fi
echo

# ── 4. S3 bucket ─────────────────────────────────────────────────────────────
echo "── 4. S3 bucket: $BUCKET"
if aws s3api head-bucket --bucket "$BUCKET" --region "$REGION" 2>/dev/null; then
  echo "   ✓ Bucket exists"
  SETUP_NOTIF=$(aws s3 ls "s3://$BUCKET/incoming/" 2>/dev/null | grep AMAZON_SES_SETUP || echo "not found")
  echo "   Setup notification: $SETUP_NOTIF"
  RECENT=$(aws s3 ls "s3://$BUCKET/incoming/" 2>/dev/null | grep -v AMAZON_SES_SETUP | tail -3 || echo "(none)")
  echo "   Recent emails: $RECENT"
else
  echo "   ✗ PROBLEM: Bucket does not exist."
  echo "   FIX: bash scripts/setup-ses-inbound.sh"
fi
echo

# ── 5. MX record ─────────────────────────────────────────────────────────────
echo "── 5. DNS MX record for $E2E_DOMAIN"
MX=$(curl -s "https://dns.google/resolve?name=${E2E_DOMAIN}&type=MX" \
  | python3 -c "import sys,json; d=json.load(sys.stdin); [print('  ',a['data']) for a in d.get('Answer',[])]" 2>/dev/null || echo "(lookup failed)")
echo "$MX"
if echo "$MX" | grep -q "inbound-smtp.${REGION}"; then
  echo "   ✓ MX record correct"
else
  echo "   ✗ PROBLEM: MX record missing or wrong."
  echo "   FIX: Add to DNS: ${E2E_DOMAIN}  MX 10  inbound-smtp.${REGION}.amazonaws.com"
fi
echo

echo "════════════════════════════════════════════════════════════"
echo " Done"
echo "════════════════════════════════════════════════════════════"
