# Sandbox live-run harness (Phase 6)

This directory is a placeholder for the sandbox end-to-end harness described
in Phase 6 of the DR-215 plan.

The harness will:

1. Create a real PayPal sandbox subscription via
   `https://auditandfix.com/api.php?sandbox=<E2E_SANDBOX_KEY>&action=create-2step-subscription`.
2. Pause for a human to approve in the PayPal sandbox browser.
3. Poll for up to 60 seconds across all three handlers:
   - `subscriptions-sandbox.sqlite` on the production host
   - The sandbox R2 bucket (`paypal-webhook-worker-test`)
   - `crai_test.tenants` via the CRAI staging Worker
4. Cancel the subscription via PayPal Subscriptions API.
5. Emit a chain-of-custody report.

**Not implemented yet.** Phase 6 is queued after the Vitest suite is green.

## When implementing

- Runs from the host (not the container) — needs direct SCP/SFTP to the
  production site, direct curl to PayPal sandbox, and a human in front of a
  browser for approval.
- Requires env vars: `E2E_SANDBOX_KEY`, `PAYPAL_SANDBOX_CLIENT_ID`,
  `PAYPAL_SANDBOX_CLIENT_SECRET`, `PAYPAL_SANDBOX_WEBHOOK_ID`.
- Should emit a timestamped JSON report so subsequent runs can be compared.
