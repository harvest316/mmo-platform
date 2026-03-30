#!/usr/bin/env node
/**
 * directory-submit.js — Submit a SaaS tool to AI/software directories
 *
 * Usage:
 *   node scripts/directory-submit.js [--dir=<name>] [--all]
 *
 * Credentials: set in mmo-platform/.env or environment:
 *   SAASHUB_EMAIL, SAASHUB_PASSWORD
 *   FUTURETOOLS_EMAIL (optional — used as contact only)
 *
 * Product config: edit TOOL below, or pass --config=path/to/tool.json
 *
 * Notes:
 *   - Cloudflare-protected sites (Toolify, TopAI, EasyWithAI) launch a
 *     VISIBLE browser so you can tick the challenge manually, then the
 *     script auto-fills and submits.
 *   - FutureTools has a Turnstile CAPTCHA — script fills the form and
 *     pauses for you to tick it, then submits.
 *   - Run from host terminal (not container) for best Cloudflare results.
 */

import { chromium } from 'playwright';
import { readFileSync, existsSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const readFileSync_ = readFileSync;
const existsSync_ = existsSync;

// ─── Load env ─────────────────────────────────────────────────────────────────

const envPath = join(__dirname, '..', '.env');
if (existsSync_(envPath)) {
  for (const line of readFileSync_(envPath, 'utf8').split('\n')) {
    const m = line.match(/^([A-Z_]+)=(.+)$/);
    if (m) process.env[m[1]] ??= m[2].trim().replace(/^["']|["']$/g, '');
  }
}

// ─── Tool config ──────────────────────────────────────────────────────────────
// Edit this section for each product, or pass --config=path/to/tool.json

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

// ─── Directory definitions ────────────────────────────────────────────────────

const DIRECTORIES = {

  futuretools: {
    name: 'Future Tools',
    url: 'https://www.futuretools.io/submit-a-tool',
    headless: false, // Turnstile CAPTCHA — needs visible browser
    async submit(page, tool) {
      await page.goto(tool.url + '/submit-a-tool', { waitUntil: 'networkidle' });
      // Fill form fields
      await fillIfExists(page, 'input[name="name"], input[placeholder*="name" i]', tool.contactName);
      await fillIfExists(page, 'input[name="email"], input[type="email"]', tool.contactEmail);
      await fillIfExists(page, 'input[name="toolName"], input[placeholder*="tool name" i]', tool.name);
      await fillIfExists(page, 'input[name="toolUrl"], input[placeholder*="url" i], input[placeholder*="link" i]', tool.url);
      await fillIfExists(page, 'textarea[name="description"], textarea[placeholder*="description" i]', tool.shortDescription);
      // Select pricing
      await selectIfExists(page, 'select[name="pricing"]', 'Freemium');
      console.log('\n⏸  FutureTools: form filled. Please tick the CAPTCHA in the browser, then press Enter here...');
      await waitForKeypress();
      await clickSubmit(page);
      await page.waitForTimeout(3000);
      console.log('✓ FutureTools: submitted. Check your email for confirmation.');
    },
  },

  toolify: {
    name: 'Toolify.ai',
    url: 'https://www.toolify.ai/submit',
    headless: false, // Cloudflare challenge
    async submit(page, tool) {
      await page.goto('https://www.toolify.ai/submit', { waitUntil: 'domcontentloaded', timeout: 60000 });
      console.log('\n⏸  Toolify: Cloudflare challenge may appear. Complete it in the browser, then press Enter...');
      await waitForKeypress();
      await fillIfExists(page, 'input[placeholder*="tool name" i], input[name*="name" i]', tool.name);
      await fillIfExists(page, 'input[placeholder*="url" i], input[placeholder*="link" i], input[name*="url" i]', tool.url);
      await fillIfExists(page, 'input[type="email"], input[placeholder*="email" i]', tool.contactEmail);
      await fillIfExists(page, 'textarea', tool.shortDescription);
      console.log('\n⏸  Toolify: form filled. Review in browser, then press Enter to submit...');
      await waitForKeypress();
      await clickSubmit(page);
      await page.waitForTimeout(3000);
      console.log('✓ Toolify: submitted.');
    },
  },

  topai: {
    name: 'TopAI.tools',
    url: 'https://topai.tools/submit',
    headless: false,
    async submit(page, tool) {
      await page.goto('https://topai.tools/submit', { waitUntil: 'domcontentloaded', timeout: 60000 });
      console.log('\n⏸  TopAI: Cloudflare challenge may appear. Complete it in the browser, then press Enter...');
      await waitForKeypress();
      await fillIfExists(page, 'input[placeholder*="name" i]', tool.name);
      await fillIfExists(page, 'input[placeholder*="url" i], input[placeholder*="link" i]', tool.url);
      await fillIfExists(page, 'input[type="email"]', tool.contactEmail);
      await fillIfExists(page, 'textarea', tool.shortDescription);
      console.log('\n⏸  TopAI: form filled. Review in browser, then press Enter to submit...');
      await waitForKeypress();
      await clickSubmit(page);
      await page.waitForTimeout(3000);
      console.log('✓ TopAI: submitted.');
    },
  },

  easywithai: {
    name: 'EasyWithAI',
    url: 'https://easywithai.com',
    headless: false,
    async submit(page, tool) {
      await page.goto('https://easywithai.com/submit', { waitUntil: 'domcontentloaded', timeout: 60000 });
      console.log('\n⏸  EasyWithAI: Cloudflare challenge may appear. Complete it in the browser, then press Enter...');
      await waitForKeypress();
      await fillIfExists(page, 'input[placeholder*="name" i]', tool.name);
      await fillIfExists(page, 'input[placeholder*="url" i]', tool.url);
      await fillIfExists(page, 'input[type="email"]', tool.contactEmail);
      await fillIfExists(page, 'textarea', tool.shortDescription);
      console.log('\n⏸  EasyWithAI: form filled. Review in browser, then press Enter to submit...');
      await waitForKeypress();
      await clickSubmit(page);
      await page.waitForTimeout(3000);
      console.log('✓ EasyWithAI: submitted.');
    },
  },

  saashub: {
    name: 'SaaSHub',
    url: 'https://www.saashub.com',
    headless: false,
    async submit(page, tool) {
      const email = process.env.SAASHUB_EMAIL;
      const password = process.env.SAASHUB_PASSWORD;
      if (!email || !password) {
        console.log('⚠  SaaSHub: set SAASHUB_EMAIL and SAASHUB_PASSWORD in .env');
        return;
      }
      // Login
      await page.goto('https://www.saashub.com/users/sign_in', { waitUntil: 'networkidle' });
      await fillIfExists(page, 'input[type="email"], input[name="user[email]"]', email);
      await fillIfExists(page, 'input[type="password"]', password);
      await clickSubmit(page);
      await page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {});
      console.log('✓ SaaSHub: logged in');

      // Go to the colormora listing
      await page.goto('https://www.saashub.com/colormora', { waitUntil: 'networkidle' });
      // Click Edit / Claim if available
      const editBtn = page.locator('a:has-text("Edit"), a:has-text("Claim"), button:has-text("Edit")').first();
      if (await editBtn.isVisible().catch(() => false)) {
        await editBtn.click();
        await page.waitForTimeout(2000);
      }
      // Fill description if field is present
      await fillIfExists(page, 'textarea[name*="description"], textarea[placeholder*="description" i]', tool.longDescription);
      // Fill short description
      await fillIfExists(page, 'input[name*="tagline"], input[placeholder*="tagline" i]', tool.tagline);
      console.log('\n⏸  SaaSHub: review the form in the browser, then press Enter to submit...');
      await waitForKeypress();
      await clickSubmit(page);
      await page.waitForTimeout(3000);
      console.log('✓ SaaSHub: listing updated.');
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
      await el.selectOption({ label: value });
    }
  } catch {}
}

async function clickSubmit(page) {
  const btn = page.locator('button[type="submit"], input[type="submit"], button:has-text("Submit"), button:has-text("Send")').first();
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

(async () => {
  const args = process.argv.slice(2);
  const dirArg = args.find(a => a.startsWith('--dir='))?.split('=')[1];
  const runAll = args.includes('--all');
  const configArg = args.find(a => a.startsWith('--config='))?.split('=')[1];

  // Load override config if provided
  let tool = TOOL;
  if (configArg && existsSync_(configArg)) {
    tool = { ...TOOL, ...JSON.parse(readFileSync_(configArg, 'utf8')) };
  }

  const toRun = dirArg
    ? [dirArg]
    : runAll
    ? Object.keys(DIRECTORIES)
    : (() => { console.log('Usage: node directory-submit.js --dir=<name> | --all\nAvailable:', Object.keys(DIRECTORIES).join(', ')); process.exit(0); })();

  for (const dirKey of toRun) {
    const dir = DIRECTORIES[dirKey];
    if (!dir) { console.log(`Unknown directory: ${dirKey}`); continue; }

    console.log(`\n${'─'.repeat(60)}`);
    console.log(`Submitting to: ${dir.name}`);
    console.log('─'.repeat(60));

    const browser = await chromium.launch({
      headless: dir.headless === false ? false : true,
      args: ['--no-sandbox'],
    });
    const context = await browser.newContext({
      userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
      viewport: { width: 1280, height: 900 },
    });
    const page = await context.newPage();

    try {
      await dir.submit(page, tool);
    } catch (err) {
      console.error(`✗ ${dir.name} error:`, err.message);
    } finally {
      await browser.close();
    }
  }

  console.log('\nAll done.');
})();
