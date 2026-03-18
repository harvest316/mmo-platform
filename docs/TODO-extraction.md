# @mmo/* Package Extraction — Tech Debt

## Current State

2Step imports directly from 333Method via `file:../333Method` link.
Shared Module Usage is documented in `2Step/CLAUDE.md`.

## Trigger Conditions for Extraction

- A third project is added to the mmo-platform workspace
- 333Method module changes break 2Step (coupling pain)
- User directs extraction to begin

## Modules to Extract (by package)

### @mmo/core (highest priority)

- `logger.js` — needs domain map made configurable (currently 90 hardcoded 333Method entries)
- `error-handler.js` — imports logger
- `db.js` — database initialisation
- `load-env.js` — environment loading
- `adaptive-concurrency.js` — needs `../../.env` and `../../logs/` paths parameterised
- `circuit-breaker.js` — needs rate-limit-scheduler decoupled

### @mmo/browser

- `html-contact-extractor.js` — self-contained, easiest to extract
- `stealth-browser.js` — needs `../../extensions/nopecha` path parameterised

### @mmo/outreach

- `spintax.js` — self-contained
- `compliance.js` — imports logger, timezone-detector
- `phone-normalizer.js` — self-contained
- `email.js` — 7 internal deps (complex extraction)
- `sms.js` — 7 internal deps (complex extraction)

### @mmo/monitor, @mmo/orchestrator

Defer until unified orchestrator stabilises.

## Extraction Order

1. `html-contact-extractor` (zero deps, zero risk)
2. `spintax`, `phone-normalizer` (zero deps)
3. `logger` (hardest — 98 importers in 333Method, needs domain map refactor)
4. `error-handler`, `db`, `load-env` (depend on logger)
5. Everything else follows
