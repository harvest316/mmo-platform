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
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// ── Project registry ──────────────────────────────────────────────────────────
// db      : absolute path to the project's SQLite database
// runner  : absolute path to the project's cron entry-point script
// root    : working directory for the runner process
export const PROJECTS = {
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
  'admanager': {
    db: '/home/jason/code/AdManager/db/admanager.db',
    runner: '/home/jason/code/AdManager/bin/cron-runner.php',
    root: '/home/jason/code/AdManager',
  },
};

// ── Argument parsing ──────────────────────────────────────────────────────────

export function parseProjectArg(argv) {
  const arg = argv.find((a) => a.startsWith('--project='));
  return arg ? arg.split('=')[1] : null;
}

export function resolveTargets(projectArg, projects = PROJECTS) {
  if (!projectArg) return projects;

  if (!projects[projectArg]) {
    return { error: `Unknown project: ${projectArg}`, known: Object.keys(projects) };
  }

  return { [projectArg]: projects[projectArg] };
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

export function dispatch(targets, { _execFileSync = execFileSync, _existsSync = existsSync } = {}) {
  const results = [];

  for (const [project, cfg] of Object.entries(targets)) {
    if (!_existsSync(cfg.runner)) {
      console.warn(`[mmo-cron] Skipping ${project} — runner not found: ${cfg.runner}`);
      results.push({ project, status: 'skipped', reason: 'runner_not_found' });
      continue;
    }

    if (!_existsSync(cfg.db)) {
      console.warn(`[mmo-cron] Skipping ${project} — DB not found: ${cfg.db}`);
      results.push({ project, status: 'skipped', reason: 'db_not_found' });
      continue;
    }

    console.log(`[mmo-cron] Dispatching ${project}…`);

    try {
      _execFileSync(process.execPath, [cfg.runner], {
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
      results.push({ project, status: 'completed' });
    } catch (err) {
      // Non-zero exit from a project runner is logged but does not abort others
      console.error(`[mmo-cron] ${project} runner exited with error: ${err.message}`);
      results.push({ project, status: 'error', error: err.message });
    }
  }

  return results;
}

/* c8 ignore start — direct-run guard cannot execute during import */
const isDirectRun = process.argv[1] && resolve(process.argv[1]) === resolve(fileURLToPath(import.meta.url));
if (isDirectRun) {
  const projectArg = parseProjectArg(process.argv);
  const targets = resolveTargets(projectArg);

  if (targets.error) {
    console.error(`[mmo-cron] ${targets.error}`);
    console.error(`[mmo-cron] Known projects: ${targets.known.join(', ')}`);
    process.exit(1);
  }

  const results = dispatch(targets);
  if (results.some(r => r.status === 'error')) {
    process.exitCode = 1;
  }
}
/* c8 ignore stop */
