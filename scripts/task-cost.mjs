#!/usr/bin/env node
/**
 * task-cost.mjs — Track Claude Max token cost per task
 *
 * Usage:
 *   node task-cost.mjs start "description" [project]   → prints task ID
 *   node task-cost.mjs end <id> [pipeline_hours]       → prints cost summary
 *   node task-cost.mjs show [N]                        → last N tasks (default 20)
 *
 * pipeline_hours: how long the 333Method orchestrator ran during the task
 *   (used to subtract ~2%/hr pipeline noise from the net cost).
 *   Pass 0 if pipeline was paused. Omit to skip noise correction.
 */

import { readFileSync } from 'fs';
import { execSync } from 'child_process';

const USAGE_CACHE = `${process.env.HOME}/.claude/usage-cache.json`;
const PIPELINE_RATE = 2.0; // % weekly per hour of pipeline operation

function readUsage() {
  try {
    const raw = JSON.parse(readFileSync(USAGE_CACHE, 'utf8'));
    return {
      weekly: raw.seven_day ?? 0,
      five_hour: raw.five_hour ?? 0,
      fetched_at: raw.fetched_at ?? null,
      stale: raw.stale ?? false,
    };
  } catch {
    return { weekly: 0, five_hour: 0, fetched_at: null, stale: true };
  }
}

function sq(val) {
  // SQL single-quote escape for string literals
  return val === null ? 'NULL' : `'${String(val).replace(/'/g, "''")}'`;
}

function psql(query) {
  return execSync(
    `psql -h /run/postgresql mmo -t -A`,
    { input: query, encoding: 'utf8' }
  ).trim();
}

const [,, cmd, ...args] = process.argv;

if (cmd === 'start') {
  const description = args[0];
  const project = args[1] ?? null;
  if (!description) {
    console.error('Usage: task-cost.mjs start "description" [project]');
    process.exit(1);
  }
  const usage = readUsage();
  if (usage.stale) console.warn('⚠ usage-cache.json is stale — snapshot may be inaccurate');

  const id = psql(`
    INSERT INTO tel.task_costs (description, project, weekly_pct_start, five_hour_pct_start)
    VALUES (${sq(description)}, ${sq(project)}, ${usage.weekly}, ${usage.five_hour})
    RETURNING id;
  `).split('\n').find(l => /^\d+$/.test(l.trim()))?.trim();

  console.log(`Task #${id} started`);
  console.log(`  weekly=${usage.weekly}%  5h=${usage.five_hour}%  (cache: ${usage.fetched_at ?? 'unknown'})`);
  console.log(`  Run: node task-cost.mjs end ${id} [pipeline_hours]`);

} else if (cmd === 'end') {
  const id = args[0];
  const pipelineHours = args[1] !== undefined ? parseFloat(args[1]) : null;
  if (!id) {
    console.error('Usage: task-cost.mjs end <id> [pipeline_hours]');
    process.exit(1);
  }
  const usage = readUsage();
  if (usage.stale) console.warn('⚠ usage-cache.json is stale — snapshot may be inaccurate');

  // Fetch start values
  const startRow = psql(`SELECT weekly_pct_start, five_hour_pct_start, description, started_at FROM tel.task_costs WHERE id=${parseInt(id)}`);
  if (!startRow) { console.error(`Task #${id} not found`); process.exit(1); }
  const [weeklyStart, fiveStart, desc, startedAt] = startRow.split('|');

  const weeklyNet = pipelineHours !== null
    ? (usage.weekly - parseInt(weeklyStart)) - (pipelineHours * PIPELINE_RATE)
    : usage.weekly - parseInt(weeklyStart);

  psql(`
    UPDATE tel.task_costs SET
      ended_at = now(),
      weekly_pct_end = ${usage.weekly},
      five_hour_pct_end = ${usage.five_hour},
      pipeline_hours = ${pipelineHours ?? 'NULL'},
      weekly_pct_net = ${weeklyNet.toFixed(1)}
    WHERE id = ${id}
  `);

  const elapsed = startedAt
    ? Math.round((Date.now() - new Date(startedAt).getTime()) / 60000) + ' min'
    : 'unknown';

  console.log(`Task #${id} complete: "${desc}"`);
  console.log(`  elapsed=${elapsed}`);
  console.log(`  weekly: ${weeklyStart}% → ${usage.weekly}%  (gross +${usage.weekly - parseInt(weeklyStart)}%)`);
  if (pipelineHours !== null) {
    console.log(`  pipeline noise: ${pipelineHours}h × ${PIPELINE_RATE}%/h = ${(pipelineHours * PIPELINE_RATE).toFixed(1)}%`);
    console.log(`  net session cost: ~${weeklyNet.toFixed(1)}% of weekly quota`);
  } else {
    console.log(`  net session cost: ~${weeklyNet.toFixed(1)}% of weekly quota (no pipeline correction)`);
  }

} else if (cmd === 'show') {
  const n = parseInt(args[0] ?? '20');
  const rows = psql(`
    SELECT id, description, project,
      started_at::date as date,
      EXTRACT(epoch FROM (ended_at - started_at))/60 as min,
      weekly_pct_start, weekly_pct_end,
      weekly_pct_net,
      pipeline_hours
    FROM tel.task_costs
    WHERE ended_at IS NOT NULL
    ORDER BY started_at DESC
    LIMIT ${n}
  `);

  if (!rows) { console.log('No completed tasks yet.'); process.exit(0); }

  console.log('ID  | Date       | Min  | Weekly%Net | Gross | Project    | Description');
  console.log('----|------------|------|------------|-------|------------|----------------------------');
  for (const row of rows.split('\n')) {
    const [id, desc, proj, date, min, wStart, wEnd, wNet, pipeHrs] = row.split('|');
    const gross = wEnd && wStart ? `+${parseInt(wEnd) - parseInt(wStart)}%` : '?';
    const net = wNet ? `${parseFloat(wNet).toFixed(1)}%` : '?';
    const elapsed = min ? `${Math.round(parseFloat(min))}m` : '?';
    console.log(
      `${id?.padEnd(3)} | ${date?.padEnd(10)} | ${elapsed?.padEnd(4)} | ${net?.padEnd(10)} | ${gross?.padEnd(5)} | ${(proj ?? '-')?.padEnd(10)} | ${desc}`
    );
  }

} else {
  console.log('Usage:');
  console.log('  node task-cost.mjs start "description" [project]');
  console.log('  node task-cost.mjs end <id> [pipeline_hours]');
  console.log('  node task-cost.mjs show [N]');
}
