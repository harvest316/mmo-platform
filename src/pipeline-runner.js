#!/usr/bin/env node

/**
 * Unified pipeline runner — imports and runs stages from both projects.
 *
 * Each loop iteration processes stages in order:
 *   333Method: serps → assets → scoring → rescoring → enrich → proposals
 *   2Step:     reviews → enrich → video → proposals
 *   Shared:    outreach → followup_check → replies
 *
 * The shared outreach stage drains msgs.messages for BOTH projects in one pass.
 * The only project-specific fork is email HTML rendering (video poster vs audit report).
 *
 * Usage:
 *   node src/pipeline-runner.js              # Continuous loop
 *   node src/pipeline-runner.js --once       # One iteration then exit
 *   node src/pipeline-runner.js --project 2step  # Only 2Step stages
 *
 * NOTE: This is the non-LLM portion of the pipeline (browser automation,
 * API calls, video rendering, outreach sending). LLM batches (proposals,
 * scoring, proofreading) are handled by the orchestrator.sh which calls
 * claude-batch.js / 2step-batch.js and pipes through `claude -p`.
 */

import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';
import { parseArgs } from 'util';

const __dirname = dirname(fileURLToPath(import.meta.url));
const mmoRoot = resolve(__dirname, '..');
const methodRoot = resolve(mmoRoot, '../333Method');
const twostepRoot = resolve(mmoRoot, '../2Step');

// ── Shutdown handling ────────────────────────────────────────────────────────

let shuttingDown = false;

export function isShuttingDown() {
  return shuttingDown;
}

export function resetShutdown() {
  shuttingDown = false;
}

export function requestShutdown(signal) {
  if (shuttingDown) return;
  shuttingDown = true;
  console.log(`\n[unified] Received ${signal} — finishing current stage then exiting...`);
}

// ── Safe dynamic imports ─────────────────────────────────────────────────────
// Each stage is imported lazily so a missing/broken stage doesn't block others.

export async function safeImport(specifier, exportName) {
  try {
    const mod = await import(specifier);
    return mod[exportName] || mod.default;
  } catch (err) {
    console.warn(`[unified] Could not import ${specifier}: ${err.message}`);
    return null;
  }
}

export async function safeRun(name, fn, options = {}) {
  if (shuttingDown) return { skipped: 'shutdown' };
  if (!fn) return { skipped: 'not_loaded' };

  const start = Date.now();
  try {
    console.log(`[unified] Running: ${name}`);
    const result = await fn(options);
    const elapsed = ((Date.now() - start) / 1000).toFixed(1);
    console.log(`[unified] ${name} done in ${elapsed}s:`, JSON.stringify(result));
    return { ok: true, result, elapsed };
  } catch (err) {
    const elapsed = ((Date.now() - start) / 1000).toFixed(1);
    console.error(`[unified] ${name} failed after ${elapsed}s: ${err.message}`);
    return { ok: false, error: err.message, elapsed };
  }
}

// ── One pipeline iteration ───────────────────────────────────────────────────

export async function runIteration(projectFilter, { _safeImport = safeImport, _safeRun = safeRun } = {}) {
  const ts = new Date().toISOString().replace('T', ' ').slice(0, 19);
  console.log(`\n[unified] ===== Iteration at ${ts} =====`);

  const summary = {};

  // 333Method stages (non-LLM: browser automation + data processing)
  if (!projectFilter || projectFilter === '333method') {
    const runEnrichmentStage = await _safeImport(
      resolve(methodRoot, 'src/stages/enrich.js'), 'runEnrichmentStage'
    );

    if (runEnrichmentStage) {
      summary['333m:enrich'] = await _safeRun('333m:enrich', runEnrichmentStage, { limit: 5 });
    }
  }

  // 2Step stages (non-LLM)
  if (!projectFilter || projectFilter === '2step') {
    const runEnrichStage = await _safeImport(
      resolve(twostepRoot, 'src/stages/enrich.js'), 'runEnrichStage'
    );
    const runVideoStage = await _safeImport(
      resolve(twostepRoot, 'src/stages/video.js'), 'runVideoStage'
    );

    if (runEnrichStage) {
      summary['2step:enrich'] = await _safeRun('2step:enrich', runEnrichStage, { limit: 5 });
    }
    if (runVideoStage) {
      summary['2step:video'] = await _safeRun('2step:video', runVideoStage, { limit: 5 });
    }
  }

  // Shared stages — outreach runs once, drains both projects
  if (!projectFilter || projectFilter === 'shared') {
    const runOutreachStage = await _safeImport(
      resolve(twostepRoot, 'src/stages/outreach.js'), 'runOutreachStage'
    );

    if (runOutreachStage) {
      summary['shared:outreach'] = await _safeRun('shared:outreach', runOutreachStage, {
        limit: 50,
        methods: ['email', 'sms'],
      });
    }
  }

  console.log('[unified] ===== Iteration complete =====');
  return summary;
}

// ── Main loop ────────────────────────────────────────────────────────────────

/* c8 ignore start — glue code: all components individually tested */
export async function main() {
  const { values: args } = parseArgs({
    options: {
      once: { type: 'boolean', default: false },
      project: { type: 'string' },
      interval: { type: 'string' },
    },
    strict: false,
  });

  const runOnce = args.once;
  const projectFilter = args.project || null;
  const intervalMs = args.interval
    ? parseInt(args.interval, 10)
    : parseInt(process.env.PIPELINE_INTERVAL_MS || '60000', 10);

  if (runOnce) {
    console.log('[unified] Running one iteration');
    await runIteration(projectFilter);
    process.exit(0);
    return;
  }

  console.log(`[unified] Starting continuous loop (interval=${intervalMs}ms, project=${projectFilter || 'all'})`);

  while (!shuttingDown) {
    await runIteration(projectFilter);

    if (shuttingDown) break;

    // Sleep in chunks for responsive shutdown
    let slept = 0;
    while (slept < intervalMs && !shuttingDown) {
      await new Promise(r => setTimeout(r, Math.min(1000, intervalMs - slept)));
      slept += 1000;
    }
  }

  console.log('[unified] Exiting cleanly.');
  process.exit(0);
}
/* c8 ignore stop */

/* c8 ignore start — direct-run guard cannot execute during import */
const isDirectRun = process.argv[1] && resolve(process.argv[1]) === resolve(fileURLToPath(import.meta.url));
if (isDirectRun) {
  process.on('SIGINT', () => requestShutdown('SIGINT'));
  process.on('SIGTERM', () => requestShutdown('SIGTERM'));

  main().catch(err => {
    console.error('[unified] Fatal:', err.message);
    process.exit(1);
  });
}
/* c8 ignore stop */
