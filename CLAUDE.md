# CLAUDE.md — mmo-platform (Shared Services)

## Overview

Parent platform providing shared services to child business projects (333Method, 2Step, future GhostHunter, etc.). Uses npm workspaces monorepo with `@mmo/` package scope.

## Architecture

```
mmo-platform/
  packages/
    core/          # @mmo/core — logger, error-handler, db, load-env, adaptive-concurrency
    outreach/      # @mmo/outreach — email, sms, form, spintax, compliance, sheets
    browser/       # @mmo/browser — stealth browser, profiles, contact extraction
    monitor/       # @mmo/monitor — cron framework, process guardian, AFK checks
    orchestrator/  # @mmo/orchestrator — claude -p batch runner, conservation mode
  services/
    overseer/      # Unified AFK monitoring across all child projects
    dashboard/     # Unified dashboard (later)
  website/         # auditandfix.com (sales pages, workers)
```

## Child Projects

- **333Method** (`~/code/333Method/`) — Audit&Fix website audits
- **2Step** (`~/code/2Step/`) — Video review cold outreach
- **333Method-infra** (`~/code/333Method-infra/`) — NixOS infrastructure

Children depend on shared packages via `file:` protocol:
```json
{ "@mmo/core": "file:../mmo-platform/packages/core" }
```

## Package Extraction Status

Packages are being extracted from `333Method/src/` into this monorepo. During extraction:
1. Copy module from 333Method to the appropriate package
2. Update exports in package.json
3. Update 333Method imports to use `@mmo/package-name`
4. Update 2Step imports to use `@mmo/package-name`
5. Run both projects' test suites — ensure nothing breaks

## Development

```bash
npm install              # Workspace-aware install (all packages)
npm test                 # Run all package tests
npm run lint             # Lint all packages
```

## Quality

Same standards as child projects:
- 85%+ test coverage target
- ESLint + Prettier
- Never commit secrets
- Run tests before committing

## Documentation

- `docs/TODO.md` — Platform-level tasks
- Each package has its own README when extracted
- Child projects reference this platform in their CLAUDE.md
