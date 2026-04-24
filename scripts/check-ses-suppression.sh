#!/usr/bin/env bash
# check-ses-suppression.sh — Verify CRAI dogfood address isn't on SES suppression list.
# Runs fortnightly via cron. Auto-removes if suppressed and logs the event.
#
# DR-240 context: hello@contactreplyai.com was auto-suppressed 2026-04-17 after
# a bounce during early SES setup, silently blocking all dogfood tests for 6 days.
#
# Usage:  bash scripts/check-ses-suppression.sh [--email=<addr>] [--profile=<profile>]

set -euo pipefail

EMAIL="hello@contactreplyai.com"
REGION="ap-southeast-2"
PROFILE="mmo-admin"

for arg in "$@"; do
  [[ "$arg" == --email=*   ]] && EMAIL="${arg#--email=}"
  [[ "$arg" == --profile=* ]] && PROFILE="${arg#--profile=}"
done

TIMESTAMP=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

result=$(aws --profile "$PROFILE" sesv2 get-suppressed-destination \
  --email-address "$EMAIL" \
  --region "$REGION" 2>&1) && found=1 || found=0

if [[ $found == 1 ]] && echo "$result" | grep -q '"EmailAddress"'; then
  echo "$TIMESTAMP [ALERT] $EMAIL is on the SES suppression list — removing"
  aws --profile "$PROFILE" sesv2 delete-suppressed-destination \
    --email-address "$EMAIL" \
    --region "$REGION"
  echo "$TIMESTAMP [FIXED] $EMAIL removed from suppression list"
  exit 2  # non-zero so cron/monitoring can detect it
else
  echo "$TIMESTAMP [OK] $EMAIL is not suppressed"
fi
