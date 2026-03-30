#!/usr/bin/env node
/**
 * directory-submit.js — Submit a SaaS tool to AI/software directories
 *
 * Usage (from host terminal):
 *   node scripts/directory-submit.js --dir=<name>
 *   node scripts/directory-submit.js --all
 *
 * Available dirs: futuretools, toolify, topai, easywithai, saashub
 *
 * Credentials — loaded from 333Method/.env.secrets then mmo-platform/.env:
 *   NOPECHA_API_KEY   — auto-solves Turnstile / reCAPTCHA / hCaptcha
 *   SAASHUB_EMAIL / SAASHUB_PASSWORD
 *
 * To reuse for another product:
 *   node scripts/directory-submit.js --dir=saashub --config=path/to/tool.json
 */

import { chromium } from 'playwright';
import { readFileSync, existsSync, mkdirSync, cpSync, writeFileSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));

// ─── Load env (333Method secrets first, then mmo-platform) ───────────────────

for (const envFile of [
  join(__dirname, '../../333Method/.env.secrets'),
  join(__dirname, '../../333Method/.env'),
  join(__dirname, '../.env'),
]) {
  if (!existsSync(envFile)) continue;
  for (const line of readFileSync(envFile, 'utf8').split('\n')) {
    const m = line.match(/^([A-Z_0-9]+)=(.+)$/);
    if (m) process.env[m[1]] ??= m[2].trim().replace(/^["']|["']$/g, '');
  }
}

// ─── NopeCHA extension setup (same pattern as stealth-browser.js) ─────────────

function prepareNopeCHA() {
  const apiKey = process.env.NOPECHA_API_KEY;
  if (!apiKey) { console.warn('⚠  NOPECHA_API_KEY not set — CAPTCHAs will need manual solving'); return null; }

  const srcDir = join(__dirname, '../../333Method/extensions/nopecha');
  if (!existsSync(srcDir)) { console.warn('⚠  NopeCHA extension not found at 333Method/extensions/nopecha'); return null; }

  const destDir = `/tmp/nopecha-ext-${process.pid}`;
  cpSync(srcDir, destDir, { recursive: true });

  // Inject API key into the extension's background/settings
  const manifestPath = join(destDir, 'manifest.json');
  const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
  if (!manifest._key_injected) {
    // Write a seed script that sets the key on extension load
    const seedScript = `
(function() {
  var settings = { key: "${apiKey}", enabled: true, auto_start: true };
  if (typeof chrome !== 'undefined' && chrome.storage) {
    chrome.storage.local.set({ settings: settings });
  }
})();
`;
    writeFileSync(join(destDir, '_key_seed.js'), seedScript);
    // Add seed script to background scripts if v2, or inject into service worker if v3
    if (manifest.manifest_version === 2 && manifest.background?.scripts) {
      manifest.background.scripts.unshift('_key_seed.js');
    }
    manifest._key_injected = true;
    writeFileSync(manifestPath, JSON.stringify(manifest, null, 2));
  }

  console.log(`✓ NopeCHA extension ready (key: ${apiKey.slice(0, 8)}...)`);
  return destDir;
}

// ─── Tool config ──────────────────────────────────────────────────────────────

const TOOL = {
  name: 'Colormora',
  url: 'https://colormora.com',
  tagline: 'AI-generated coloring books and color palettes, on demand',
  shortDescription:
    'Colormora generates coloring book pages and color palettes from text prompts. Download as PDF or PNG, or order professional prints. Ideal for Etsy sellers, KDP publishers, parents, and teachers.',
  longDescription: `Colormora is an AI-powered creative tool that generates coloring book pages and color palettes from simple text prompts.

Type a prompt — "a magical forest with mushrooms and fairies" — and Colormora produces a clean, ready-to-print coloring page in seconds. Generate single pages or full 30-page coloring books in one session.

Key features:
- Text-to-coloring-page generation with adjustable complexity
- AI color palette generator from text descriptions or themes
- KDP and Etsy-ready export (PDF, PNG, 300 DPI, trim marks)
- Commercial use included on all paid plans
- Professional print fulfillment — order a printed and bound coloring book
- Public gallery with shareable creation URLs

Perfect for: Etsy sellers creating printable coloring products, Amazon KDP publishers building low-content books, teachers generating classroom activity pages, parents creating personalised books for children, and interior designers building color stories.

Pricing: Free tier (limited generations) + credit packs ($10–$100) + subscription plans with 10% discount. Commercial rights included on all paid tiers.`,
  category: 'Image Generation / Design Tools',
  pricing: 'Freemium',
  tags: ['coloring book', 'AI art', 'KDP', 'Etsy', 'printable', 'color palette', 'education'],
  contactName: 'Colormora Team',
  contactEmail: 'webmaster@colormora.com',
};

// ─── Browser factory ──────────────────────────────────────────────────────────


async function launchBrowser(nopechaDir) {
  // Resolve system chromium — required on NixOS where bundled Chromium lacks shared libs
  let executablePath;
  if (process.env.CHROMIUM_PATH) {
    executablePath = process.env.CHROMIUM_PATH;
  } else {
    try {
      const { execSync } = await import('child_process');
      const p = execSync('which chromium 2>/dev/null || which google-chrome-stable 2>/dev/null', { encoding: 'utf8' }).trim();
      if (p) executablePath = p;
    } catch {}
  }

  if (!executablePath) {
    console.error('⚠  No system Chromium found. Run this script inside nix-shell (cd ~/code/mmo-platform && nix-shell) or set CHROMIUM_PATH.');
    console.error('   Attempting with Playwright bundled Chromium — may fail on NixOS.');
  } else {
    console.log(`Using Chromium: ${executablePath}`);
  }

  const args = ['--no-sandbox', '--disable-blink-features=AutomationControlled'];
  if (nopechaDir) {
    args.push(`--disable-extensions-except=${nopechaDir}`);
    args.push(`--load-extension=${nopechaDir}`);
  }
  const browser = await chromium.launch({
    headless: false, // Always headed — needed for Cloudflare + CAPTCHA
    executablePath,
    args,
  });
  const context = await browser.newContext({
    userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    viewport: { width: 1280, height: 900 },
  });
  return { browser, context };
}

// ─── Directory definitions ────────────────────────────────────────────────────

const DIRECTORIES = {

  futuretools: {
    name: 'Future Tools',
    async submit(page, tool) {
      await page.goto('https://www.futuretools.io/submit-a-tool', { waitUntil: 'networkidle', timeout: 60000 });
      await page.waitForSelector('input#tool_name', { timeout: 15000 });
      await page.waitForTimeout(2000); // Let NopeCHA initialise

      await page.fill('input[name="submitter_name"]', tool.contactName);
      await page.fill('input[name="tool_name"]', tool.name);
      await page.fill('input[name="tool_url"]', tool.url);
      await page.fill('input[name="submitter_email"]', tool.contactEmail);
      await page.fill('textarea[name="description"]', tool.shortDescription);
      await page.selectOption('select[name="category"]', 'generative-art');
      // Pricing radio — click the label wrapping the freemium radio
      await page.locator('label:has(input[name="pricing_tier"][value="freemium"])').click();

      console.log('Form filled. Waiting for NopeCHA to solve Turnstile...');
      console.log('Press Enter when CAPTCHA widget shows a tick, then submit...');
      await waitForKeypress();
      await clickSubmit(page);
      await page.waitForTimeout(4000);
      console.log(`✓ FutureTools submitted. URL: ${page.url()}`);
    },
  },

  toolify: {
    name: 'Toolify.ai',
    async submit(page, tool) {
      await page.goto('https://www.toolify.ai/submit', { waitUntil: 'domcontentloaded', timeout: 60000 });
      console.log('Waiting for Cloudflare challenge to clear (NopeCHA active)...');
      await page.waitForTimeout(8000);
      await fillIfExists(page, 'input[placeholder*="tool name" i], input[name*="name" i]', tool.name);
      await fillIfExists(page, 'input[placeholder*="url" i], input[name*="url" i]', tool.url);
      await fillIfExists(page, 'input[type="email"]', tool.contactEmail);
      await fillIfExists(page, 'textarea', tool.shortDescription);
      console.log('Form filled. Press Enter to review then submit...');
      await waitForKeypress();
      await clickSubmit(page);
      await page.waitForTimeout(4000);
      console.log(`✓ Toolify submitted.`);
    },
  },

  topai: {
    name: 'TopAI.tools',
    async submit(page, tool) {
      await page.goto('https://topai.tools/submit', { waitUntil: 'domcontentloaded', timeout: 60000 });
      console.log('Waiting for Cloudflare challenge...');
      await page.waitForTimeout(8000);
      await fillIfExists(page, 'input[placeholder*="name" i]', tool.name);
      await fillIfExists(page, 'input[placeholder*="url" i]', tool.url);
      await fillIfExists(page, 'input[type="email"]', tool.contactEmail);
      await fillIfExists(page, 'textarea', tool.shortDescription);
      console.log('Form filled. Press Enter to submit...');
      await waitForKeypress();
      await clickSubmit(page);
      await page.waitForTimeout(4000);
      console.log(`✓ TopAI submitted.`);
    },
  },

  easywithai: {
    name: 'EasyWithAI',
    async submit(page, tool) {
      // Try /submit-tool first, fall back to /submit
      await page.goto('https://easywithai.com/submit-tool', { waitUntil: 'domcontentloaded', timeout: 60000 });
      console.log('Waiting for Cloudflare challenge...');
      await page.waitForTimeout(8000);
      await fillIfExists(page, 'input[placeholder*="name" i]', tool.name);
      await fillIfExists(page, 'input[placeholder*="url" i]', tool.url);
      await fillIfExists(page, 'input[type="email"]', tool.contactEmail);
      await fillIfExists(page, 'textarea', tool.shortDescription);
      console.log('Form filled. Press Enter to submit...');
      await waitForKeypress();
      await clickSubmit(page);
      await page.waitForTimeout(4000);
      console.log(`✓ EasyWithAI submitted.`);
    },
  },

  saashub: {
    name: 'SaaSHub',
    async submit(page, tool) {
      const email = process.env.SAASHUB_EMAIL;
      const password = process.env.SAASHUB_PASSWORD;
      if (!email || !password) {
        console.log('⚠  Set SAASHUB_EMAIL and SAASHUB_PASSWORD in mmo-platform/.env');
        return;
      }
      // Login
      await page.goto('https://www.saashub.com/users/sign_in', { waitUntil: 'networkidle', timeout: 60000 });
      await fillIfExists(page, 'input[name="user[email]"], input[type="email"]', email);
      await fillIfExists(page, 'input[name="user[password]"], input[type="password"]', password);
      await clickSubmit(page);
      await page.waitForNavigation({ waitUntil: 'networkidle', timeout: 30000 }).catch(() => {});
      console.log('✓ SaaSHub: logged in');

      // Navigate to the pending colormora listing
      await page.goto('https://www.saashub.com/colormora', { waitUntil: 'networkidle', timeout: 30000 });
      const editBtn = page.locator('a:has-text("Edit"), a:has-text("Claim"), button:has-text("Edit")').first();
      if (await editBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
        await editBtn.click();
        await page.waitForTimeout(2000);
      }
      await fillIfExists(page, 'textarea[name*="description"]', tool.longDescription);
      await fillIfExists(page, 'input[name*="tagline"], input[placeholder*="tagline" i]', tool.tagline);
      console.log('SaaSHub listing open. Review in browser, then press Enter to save...');
      await waitForKeypress();
      await clickSubmit(page);
      await page.waitForTimeout(3000);
      console.log('✓ SaaSHub listing updated.');
    },
  },

};

// ─── Helpers ──────────────────────────────────────────────────────────────────

async function fillIfExists(page, selector, value) {
  try {
    const el = page.locator(selector).first();
    if (await el.isVisible({ timeout: 3000 }).catch(() => false)) {
      await el.fill(value);
    }
  } catch {}
}

async function selectIfExists(page, selector, value) {
  try {
    const el = page.locator(selector).first();
    if (await el.isVisible({ timeout: 2000 }).catch(() => false)) {
      await el.selectOption({ label: value }).catch(() => el.selectOption({ value: value.toLowerCase() }));
    }
  } catch {}
}

async function clickSubmit(page) {
  const btn = page.locator('button[type="submit"], input[type="submit"], button:has-text("Submit"), button:has-text("Save")').first();
  if (await btn.isVisible({ timeout: 3000 }).catch(() => false)) {
    await btn.click();
  }
}

function waitForKeypress() {
  return new Promise(resolve => {
    process.stdin.setRawMode?.(true);
    process.stdin.resume();
    process.stdin.once('data', () => {
      process.stdin.setRawMode?.(false);
      process.stdin.pause();
      resolve();
    });
  });
}

// ─── Main ─────────────────────────────────────────────────────────────────────

const args = process.argv.slice(2);
const dirArg = args.find(a => a.startsWith('--dir='))?.split('=')[1];
const runAll = args.includes('--all');
const configArg = args.find(a => a.startsWith('--config='))?.split('=')[1];

if (!dirArg && !runAll) {
  console.log('Usage: node scripts/directory-submit.js --dir=<name> | --all');
  console.log('Available:', Object.keys(DIRECTORIES).join(', '));
  process.exit(0);
}

let tool = TOOL;
if (configArg && existsSync(configArg)) {
  tool = { ...TOOL, ...JSON.parse(readFileSync(configArg, 'utf8')) };
}

const nopechaDir = prepareNopeCHA();
const toRun = dirArg ? [dirArg] : Object.keys(DIRECTORIES);

for (const dirKey of toRun) {
  const dir = DIRECTORIES[dirKey];
  if (!dir) { console.log(`Unknown directory: ${dirKey}`); continue; }

  console.log(`\n${'─'.repeat(60)}\nSubmitting to: ${dir.name}\n${'─'.repeat(60)}`);

  const { browser, context } = await launchBrowser(nopechaDir);
  const page = await context.newPage();

  try {
    await dir.submit(page, tool);
  } catch (err) {
    console.error(`✗ ${dir.name} error:`, err.message);
  } finally {
    await browser.close();
  }
}

// Cleanup NopeCHA temp dir
if (nopechaDir) {
  import('fs').then(({ rmSync }) => rmSync(nopechaDir, { recursive: true, force: true }));
}

console.log('\nAll done.');
