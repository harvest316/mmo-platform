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

**Setup:** Runs automatically via global git hooks (see below). Bypass with `SKIP_PII_CHECK=1`.

**Location:** `mmo-platform/scripts/check-pii.sh`

---

## Global Git Hook System (`core.hooksPath`)

**What:** Centralised pre-commit and pre-push hooks covering all repos in the workspace, without per-repo setup. Runs shared checks first, then delegates to optional per-repo `.hooks/` files.

**Install (one-time per dev machine):**
```sh
git config --global core.hooksPath ~/code/mmo-platform/hooks
```

**What the global hooks do:**

*pre-commit:*
- PII scanner (blocks on secrets/PII in staged diff)
- Lint auto-detect: runs `lint-staged` if configured in `package.json`
- AI code review: sends staged diff to Claude Haiku, blocks on BLOCK verdict

*pre-push:*
- Unit test auto-detect: runs `npm run test:unit` if defined in `package.json`
- Dependency audit: runs `better-npm-audit --level moderate` if `package-lock.json` present

**Per-repo hooks** (`.hooks/pre-commit`, `.hooks/pre-push`): committed to the repo. Used only for checks that don't generalise (e.g. `check-required-files.sh`). PII, linting, AI review, tests are NOT duplicated here.

**Bypass:** `SKIP_HOOKS=1 git commit/push` or `SKIP_AI_REVIEW=1` for review only.

**Dependency audit exceptions:** create `.nsprc` in the repo root (JSON with advisory IDs as keys, expiry date as value).

**Location:** `mmo-platform/hooks/`, `mmo-platform/scripts/ai-review.sh`, `mmo-platform/scripts/check-pii.sh`

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
