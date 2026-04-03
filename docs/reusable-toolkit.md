# Reusable Toolkit

Components, patterns, and scripts worth extracting for future projects.

---

## Pre-commit PII Scanner (`scripts/check-pii.sh`)

**What:** Shell script that scans staged git diffs for PII, secrets, and sensitive data. Blocks commit if found.

**Checks:**
- Personal identifiers (configurable: name, email, address, phone)
- AU phone formats (+61, 04xx, landline)
- Credit card numbers (Visa, MC, Amex)
- Australian ABN / TFN patterns
- API keys (Stripe, GitHub, AWS, Anthropic, OpenRouter, Resend)
- Domain/brand names that shouldn't leak into public repos
- Hardcoded passwords
- **Dynamic .env leak detection** — reads actual values from `.env` / `.env.secrets` and flags if any appear in staged code

**Setup:** Add to `simple-git-hooks` pre-commit chain or symlink into `.git/hooks/pre-commit`. Bypass with `SKIP_PII_CHECK=1`.

**Location:** `mmo-platform/scripts/check-pii.sh` (symlinked into 333Method, 2Step, AdManager)

---

## COB Bundle Pattern (Radicle CI Mirror)

**What:** GitHub Actions workflow that mirrors pushes to Radicle's P2P network. Uses a "COB bundle" (git bundle containing Radicle identity docs + delegate sigrefs + HEAD ref) stored as a GitHub Secret.

**Key learnings:**
- `git bundle` drops refs whose SHA equals the prerequisite — use `^HEAD^` not `^HEAD`
- Checkout objects must be in Radicle storage BEFORE the bundle (prerequisite satisfaction)
- CI must be added as a delegate (`rad id update --delegate`)
- Push URL format: `rad://RID/NID` (not `rad://NID@RID`)

**Location:** `.github/workflows/mirror-radicle.yml` in each public repo

---

## Template Token System (333Method)

**What:** Email/SMS templates use `[token_name]` placeholders resolved at render time from env vars. Enables white-labelling without code changes.

**Tokens:** `[brand_url_short]`, `[brand_url]`, `[persona_name]`, `[persona_first_name]`, `[brand_name]`, plus standard `[domain]`, `[firstname]`, `[pricing]` etc.

**Key files:**
- `src/utils/template-proposals.js` → `populateTemplate()` — main token resolver
- `src/stages/followup-generator.js` → `resolveTemplate()` — followup sequences

---

## Sandbox Mode with Secret Key

**What:** E2E testing against production payment flows without `?sandbox=1` being publicly exploitable. Requires `?sandbox=<E2E_SANDBOX_KEY>` with timing-safe comparison.

**Location:** `auditandfix-website/site/includes/config.php`
