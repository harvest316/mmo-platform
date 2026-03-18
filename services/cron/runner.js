#!/usr/bin/env node

/**
 * mmo-cron — Multi-project cron dispatcher
 *
 * Reads cron_jobs rows (filtered by project) from each project's DB and
 * delegates to that project's own cron runner. Each project owns its handler
 * logic; this service just provides a unified systemd unit and scheduling gate.
 *
 * Projects are registered in PROJECTS below. Add a new entry when onboarding
 * a new mmo child project that has a cron_jobs table.
 *
 * Usage:
 *   node runner.js [--project=333method]   # run one project only
 *   node runner.js                          # run all registered projects
 */

import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { join } from 'node:path';

// ── Project registry ──────────────────────────────────────────────────────────
// db      : absolute path to the project's SQLite database
// runner  : absolute path to the project's cron entry-point script
// root    : working directory for the runner process
const PROJECTS = {
  '333method': {
    db: '/home/jason/code/333Method/db/sites.db',
    runner: '/home/jason/code/333Method/src/cron.js',
    root: '/home/jason/code/333Method',
  },
  // '2step': {
  //   db: '/home/jason/code/2Step/db/2step.db',
  //   runner: '/home/jason/code/2Step/src/cron.js',
  //   root: '/home/jason/code/2Step',
  // },
};

// ── Argument parsing ──────────────────────────────────────────────────────────
const projectArg = process.argv
  .find((a) => a.startsWith('--project='))
  ?.split('=')[1];

const targets = projectArg
  ? { [projectArg]: PROJECTS[projectArg] }
  : PROJECTS;

if (projectArg && !PROJECTS[projectArg]) {
  console.error(`[mmo-cron] Unknown project: ${projectArg}`);
  console.error(`[mmo-cron] Known projects: ${Object.keys(PROJECTS).join(', ')}`);
  process.exit(1);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
for (const [project, cfg] of Object.entries(targets)) {
  if (!existsSync(cfg.runner)) {
    console.warn(`[mmo-cron] Skipping ${project} — runner not found: ${cfg.runner}`);
    continue;
  }

  if (!existsSync(cfg.db)) {
    console.warn(`[mmo-cron] Skipping ${project} — DB not found: ${cfg.db}`);
    continue;
  }

  console.log(`[mmo-cron] Dispatching ${project}…`);

  try {
    execFileSync(process.execPath, [cfg.runner], {
      cwd: cfg.root,
      stdio: 'inherit',
      env: {
        ...process.env,
        DATABASE_PATH: cfg.db,
        MMO_PROJECT: project,
      },
      timeout: 9 * 60 * 1000, // 9 min — under the 10 min systemd TimeoutStartSec
    });
    console.log(`[mmo-cron] ${project} completed`);
  } catch (err) {
    // Non-zero exit from a project runner is logged but does not abort others
    console.error(`[mmo-cron] ${project} runner exited with error: ${err.message}`);
    process.exitCode = 1;
  }
}
