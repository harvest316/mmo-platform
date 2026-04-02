#!/usr/bin/env node

/**
 * Check AI model versions across the mmo-platform ecosystem.
 *
 * Queries ElevenLabs and OpenRouter APIs to list available models, then
 * compares against what's configured in each project's .env files.
 * Flags when a newer model is available or a configured model is deprecated.
 *
 * OpenRouter covers all LLM providers (Anthropic, OpenAI, Google) since
 * 333Method routes everything through OpenRouter.
 *
 * Kling has no list-models API — reports the configured version only.
 *
 * Usage:
 *   node scripts/check-model-versions.js           # check all providers
 *   node scripts/check-model-versions.js --json     # machine-readable output
 */

import { readFileSync, existsSync } from 'fs';
import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';
import { parseArgs } from 'util';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, '..');

const { values: args } = parseArgs({
  options: {
    json: { type: 'boolean', default: false },
  },
  strict: false,
});

// ─── Load env vars from all projects ────────────────────────────────────────

function loadEnvFile(path) {
  if (!existsSync(path)) return {};
  const vars = {};
  for (const line of readFileSync(path, 'utf8').split('\n')) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;
    const eq = trimmed.indexOf('=');
    if (eq === -1) continue;
    vars[trimmed.slice(0, eq)] = trimmed.slice(eq + 1).replace(/^["']|["']$/g, '');
  }
  return vars;
}

const env333 = loadEnvFile(resolve(ROOT, '../333Method/.env'));
const env2Step = loadEnvFile(resolve(ROOT, '../2Step/.env'));
const envSecrets = loadEnvFile(resolve(ROOT, '../333Method/.env.secrets'));

// Merge — project .env overrides secrets
const env = { ...envSecrets, ...env333, ...env2Step };

// ─── Configured models ──────────────────────────────────────────────────────

function getConfigured() {
  return {
    anthropic: {
      CLAUDE_SONNET_MODEL:   env.CLAUDE_SONNET_MODEL   || 'anthropic/claude-sonnet-4-6',
      CLAUDE_HAIKU_MODEL:    env.CLAUDE_HAIKU_MODEL     || 'anthropic/claude-haiku-4-5',
      CLAUDE_OPUS_MODEL:     env.CLAUDE_OPUS_MODEL      || 'anthropic/claude-opus-4',
      SCORING_MODEL:         env.SCORING_MODEL          || 'openai/gpt-4o-mini',
      PROPOSAL_MODEL:        env.PROPOSAL_MODEL         || 'anthropic/claude-haiku-4-5',
      POLISH_MODEL:          env.POLISH_MODEL           || 'google/gemini-2.0-flash-001',
      ENRICHMENT_MODEL:      env.ENRICHMENT_MODEL       || 'openai/gpt-4o-mini',
      VISION_MODEL:          env.VISION_MODEL           || 'openai/gpt-4o-mini',
      CLASSIFICATION_MODEL:  env.CLASSIFICATION_MODEL   || 'anthropic/claude-haiku-4-5',
      AUDIT_REPORT_MODEL:    env.AUDIT_REPORT_MODEL     || 'anthropic/claude-opus-4',
    },
    elevenlabs: {
      ELEVENLABS_MODEL: env.ELEVENLABS_MODEL || 'eleven_turbo_v2_5',
    },
    kling: {
      KLING_MODEL: env.KLING_MODEL || 'kling-v3',
    },
  };
}

// ─── ElevenLabs — GET /v1/models ────────────────────────────────────────────

async function checkElevenLabs(configured) {
  const apiKey = env.ELEVENLABS_API_KEY;
  if (!apiKey) return { error: 'ELEVENLABS_API_KEY not set', configured };

  try {
    const res = await fetch('https://api.elevenlabs.io/v1/models', {
      headers: { 'xi-api-key': apiKey },
      signal: AbortSignal.timeout(10000),
    });
    if (!res.ok) return { error: `HTTP ${res.status}: ${await res.text()}`, configured };

    const models = await res.json();

    // Filter to TTS-capable models
    const ttsModels = models
      .filter(m => m.can_do_text_to_speech)
      .map(m => ({
        model_id: m.model_id,
        name: m.name,
        description: (m.description || '').slice(0, 100),
      }))
      .sort((a, b) => a.model_id.localeCompare(b.model_id));

    const configuredId = configured.ELEVENLABS_MODEL;
    const found = ttsModels.find(m => m.model_id === configuredId);

    return {
      configured: configuredId,
      status: found ? 'ok' : 'NOT FOUND — model may be deprecated',
      available_tts_models: ttsModels,
    };
  } catch (e) {
    return { error: e.message, configured };
  }
}

// ─── OpenRouter — GET /api/v1/models ────────────────────────────────────────
// Covers all LLM providers (Anthropic, OpenAI, Google) since 333Method routes
// everything through OpenRouter. No auth required for the models list endpoint.

async function checkOpenRouter(configured) {
  try {
    const res = await fetch('https://openrouter.ai/api/v1/models', {
      signal: AbortSignal.timeout(15000),
    });
    if (!res.ok) return { error: `HTTP ${res.status}: ${await res.text()}`, configured };

    const body = await res.json();
    const allModels = body.data || [];

    // Index by id for fast lookup — also index hyphen variants since
    // OpenRouter uses dots (claude-sonnet-4.6) but .env uses hyphens (claude-sonnet-4-6)
    const modelIndex = new Map(allModels.map(m => [m.id, m]));

    // Group configured models by provider for display
    const configuredStatus = {};
    for (const [varName, value] of Object.entries(configured)) {
      // Try exact match, then try dot→hyphen and hyphen→dot variants
      const found = modelIndex.get(value)
        || modelIndex.get(value.replace(/-(\d+)-(\d+)$/, '-$1.$2'))  // hyphens → dots
        || modelIndex.get(value.replace(/\.(\d+)$/, '-$1'));          // dots → hyphens

      if (found) {
        const canonical = found.id !== value ? ` (resolves to ${found.id})` : '';
        configuredStatus[varName] = {
          configured: value,
          name: found.name,
          status: 'ok',
          context_length: found.context_length,
          canonical: found.id !== value ? found.id : undefined,
        };
      } else {
        // Check for newer versions in the same family
        // e.g. "anthropic/claude-sonnet-4-6" → find all "anthropic/claude-sonnet-*"
        const basePrefix = value.replace(/[-.]?\d[\d.-]*$/, '');
        const siblings = allModels
          .filter(m => m.id.startsWith(basePrefix))
          .sort((a, b) => (b.created || 0) - (a.created || 0));

        configuredStatus[varName] = {
          configured: value,
          status: siblings.length
            ? `NOT FOUND — possible replacements: ${siblings.slice(0, 3).map(m => m.id).join(', ')}`
            : 'NOT FOUND on OpenRouter',
        };
      }
    }

    // Find latest Claude models by family for upgrade hints
    const claudeFamilies = {};
    for (const m of allModels) {
      if (!m.id.startsWith('anthropic/claude-')) continue;
      // Family: anthropic/claude-opus-4, anthropic/claude-sonnet-4-6, etc.
      const family = m.id.replace(/-\d{8}$/, '').replace(/:.*$/, '');
      if (!claudeFamilies[family]) claudeFamilies[family] = [];
      claudeFamilies[family].push({
        id: m.id,
        name: m.name,
        created: m.created,
      });
    }
    for (const fam of Object.values(claudeFamilies)) {
      fam.sort((a, b) => (b.created || 0) - (a.created || 0));
    }

    return {
      configured_status: configuredStatus,
      claude_families: Object.fromEntries(
        Object.entries(claudeFamilies).map(([k, v]) => [k, v.slice(0, 3).map(m => m.id)])
      ),
    };
  } catch (e) {
    return { error: e.message, configured };
  }
}

// ─── Kling — no list-models API ─────────────────────────────────────────────

function checkKling(configured) {
  return {
    configured: configured.KLING_MODEL,
    note: 'Kling has no list-models API. Check https://docs.qingque.cn/ for new model releases.',
    known_models: ['kling-v1', 'kling-v1-5', 'kling-v1-6', 'kling-v2', 'kling-v3'],
  };
}

// ─── Main ───────────────────────────────────────────────────────────────────

async function main() {
  const configured = getConfigured();

  const [elevenlabs, openrouter] = await Promise.all([
    checkElevenLabs(configured.elevenlabs),
    checkOpenRouter(configured.anthropic),
  ]);
  const kling = checkKling(configured.kling);

  const results = { elevenlabs, openrouter, kling, checked_at: new Date().toISOString() };

  if (args.json) {
    console.log(JSON.stringify(results, null, 2));
    return;
  }

  // ─── Human-readable output ──────────────────────────────────────────────

  console.log('═══════════════════════════════════════════════════════════');
  console.log('  AI Model Version Check');
  console.log('═══════════════════════════════════════════════════════════\n');

  // ElevenLabs
  console.log('─── ElevenLabs ────────────────────────────────────────────');
  if (elevenlabs.error) {
    console.log(`  ⚠  ${elevenlabs.error}`);
  } else {
    console.log(`  Configured: ${elevenlabs.configured}  →  ${elevenlabs.status}`);
    console.log('  Available TTS models:');
    for (const m of elevenlabs.available_tts_models) {
      const current = m.model_id === elevenlabs.configured ? ' ◄ current' : '';
      console.log(`    ${m.model_id.padEnd(30)} ${m.name}${current}`);
    }
  }

  // OpenRouter (all LLM providers)
  console.log('\n─── OpenRouter (Claude / GPT / Gemini) ────────────────────');
  if (openrouter.error) {
    console.log(`  ⚠  ${openrouter.error}`);
  } else {
    for (const [varName, info] of Object.entries(openrouter.configured_status)) {
      const flag = info.status === 'ok' ? '✓ ' : '⚠ ';
      const name = info.name ? ` (${info.name})` : '';
      const alias = info.canonical ? ` → ${info.canonical}` : '';
      console.log(`  ${flag}${varName}: ${info.configured}${alias}${name}`);
      if (info.status !== 'ok') {
        console.log(`     → ${info.status}`);
      }
    }
    if (openrouter.claude_families && Object.keys(openrouter.claude_families).length) {
      console.log('  Claude families on OpenRouter:');
      for (const [family, versions] of Object.entries(openrouter.claude_families)) {
        console.log(`    ${family}: ${versions.join(', ')}`);
      }
    }
  }

  // Kling
  console.log('\n─── Kling AI ──────────────────────────────────────────────');
  console.log(`  Configured: ${kling.configured}`);
  console.log(`  Known models: ${kling.known_models.join(', ')}`);
  console.log(`  ⚠  ${kling.note}`);

  console.log('\n═══════════════════════════════════════════════════════════');
  console.log(`  Checked at: ${results.checked_at}`);
  console.log('═══════════════════════════════════════════════════════════\n');
}

main().catch(e => { console.error('FATAL:', e.message); process.exit(1); });
