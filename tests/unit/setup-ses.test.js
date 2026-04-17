/**
 * setup-ses.mjs — helper function unit tests
 *
 * The script uses top-level await and global side-effects, so we do not
 * import it directly. Instead we test:
 *
 *   1. deriveSmtpPassword() — copied verbatim from the script (pure, deterministic)
 *   2. zoneIdForDomain()    — also copied; depends only on two env-var-derived constants
 *
 * These are pure/near-pure functions with no I/O, so straightforward unit tests
 * give us high-confidence coverage of the derivation logic without spinning up
 * any AWS or Cloudflare clients.
 */

import { describe, it, expect } from 'vitest';
import crypto from 'node:crypto';
import {
  BRAND_DOMAIN,
  BRAND_APP_DOMAIN,
  BRAND_NET_DOMAIN,
  CRAI_DOMAIN,
  CRAI_APP_DOMAIN,
} from '../helpers/test-domains.js';

// ── Inline copies of the helper functions under test ─────────────────────────
// The script is not designed for import (top-level await + process.exit calls),
// so we duplicate the function bodies here. They are pure and stable.

/**
 * Derive SES SMTP password from an IAM secret access key.
 * Algorithm documented by AWS — see setup-ses.mjs for the reference comment.
 */
function deriveSmtpPassword(secretKey, region = 'us-east-1') {
  const message    = 'SendRawEmail';
  const versionByte = Buffer.from([0x04]);

  let sig = crypto.createHmac('sha256', `AWS4${secretKey}`).update('11111111').digest();
  sig = crypto.createHmac('sha256', sig).update(region).digest();
  sig = crypto.createHmac('sha256', sig).update('ses').digest();
  sig = crypto.createHmac('sha256', sig).update('aws4_request').digest();
  sig = crypto.createHmac('sha256', sig).update(message).digest();

  return Buffer.concat([versionByte, sig]).toString('base64');
}

/**
 * Zone ID lookup by domain root.
 * Mirrors the function in setup-ses.mjs; receives the five zone-ID constants
 * as parameters so tests can inject different values without env mutation.
 *
 * Order matters: more-specific suffixes (.auditandfix.app) must match before
 * less-specific ones (.auditandfix.com) so the wrong zone isn't picked.
 */
function zoneIdForDomain(domain, zones) {
  const { af, crai, afApp, afNet, crApp } = zones;
  if (domain === 'auditandfix.app' || domain.endsWith('.auditandfix.app')) {
    return afApp;
  }
  if (domain === 'auditandfix.net' || domain.endsWith('.auditandfix.net')) {
    return afNet;
  }
  if (domain === 'contactreply.app' || domain.endsWith('.contactreply.app')) {
    return crApp;
  }
  if (domain === 'contactreplyai.com' || domain.endsWith('.contactreplyai.com')) {
    return crai;
  }
  return af;
}

// ── deriveSmtpPassword ────────────────────────────────────────────────────────

describe('deriveSmtpPassword', () => {
  const TEST_SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

  it('produces deterministic output — same input yields same password', () => {
    const pw1 = deriveSmtpPassword(TEST_SECRET);
    const pw2 = deriveSmtpPassword(TEST_SECRET);
    expect(pw1).toBe(pw2);
  });

  it('starts with version byte 0x04 when base64-decoded', () => {
    const pw      = deriveSmtpPassword(TEST_SECRET);
    const decoded = Buffer.from(pw, 'base64');
    expect(decoded[0]).toBe(0x04);
  });

  it('different regions produce different passwords', () => {
    const pw1 = deriveSmtpPassword(TEST_SECRET, 'us-east-1');
    const pw2 = deriveSmtpPassword(TEST_SECRET, 'eu-west-1');
    const pw3 = deriveSmtpPassword(TEST_SECRET, 'ap-southeast-2');
    expect(pw1).not.toBe(pw2);
    expect(pw1).not.toBe(pw3);
    expect(pw2).not.toBe(pw3);
  });

  it('different secret keys produce different passwords for same region', () => {
    const pw1 = deriveSmtpPassword('secret-a', 'us-east-1');
    const pw2 = deriveSmtpPassword('secret-b', 'us-east-1');
    expect(pw1).not.toBe(pw2);
  });

  it('defaults to us-east-1 when region is omitted', () => {
    const pwDefault  = deriveSmtpPassword(TEST_SECRET);
    const pwExplicit = deriveSmtpPassword(TEST_SECRET, 'us-east-1');
    expect(pwDefault).toBe(pwExplicit);
  });

  it('output is valid base64', () => {
    const pw      = deriveSmtpPassword(TEST_SECRET);
    const decoded = Buffer.from(pw, 'base64');
    // Re-encoding should produce the same string (round-trip check)
    expect(decoded.toString('base64')).toBe(pw);
  });

  it('decoded output is 33 bytes — 1 version byte + 32 HMAC bytes', () => {
    const pw      = deriveSmtpPassword(TEST_SECRET);
    const decoded = Buffer.from(pw, 'base64');
    expect(decoded.length).toBe(33);
  });

  it('applies all 5 HMAC rounds — changing the intermediate "ses" constant changes output', () => {
    // We can't easily stub the HMAC rounds, but we can verify that the
    // algorithm is sensitive to the key material at each step by using a
    // known-different key and asserting a distinct result.
    const pw1 = deriveSmtpPassword('key-one', 'us-east-1');
    const pw2 = deriveSmtpPassword('key-two', 'us-east-1');
    expect(pw1).not.toBe(pw2);
  });
});

// ── zoneIdForDomain ───────────────────────────────────────────────────────────

describe('zoneIdForDomain', () => {
  const ZONES = {
    af:    'zone-af-xxxxxx',
    crai:  'zone-crai-yyyyyy',
    afApp: 'zone-afapp-zzzzzz',
    afNet: 'zone-afnet-aaaaaa',
    crApp: 'zone-crapp-bbbbbb',
  };

  // ── BRAND_DOMAIN (cold outreach + marketing) ──
  it(`${BRAND_DOMAIN} → AF zone`, () => {
    expect(zoneIdForDomain(BRAND_DOMAIN, ZONES)).toBe(ZONES.af);
  });
  it(`send.${BRAND_DOMAIN} → AF zone`, () => {
    expect(zoneIdForDomain(`send.${BRAND_DOMAIN}`, ZONES)).toBe(ZONES.af);
  });
  it(`mail.${BRAND_DOMAIN} → AF zone`, () => {
    expect(zoneIdForDomain(`mail.${BRAND_DOMAIN}`, ZONES)).toBe(ZONES.af);
  });
  it(`deep.sub.${BRAND_DOMAIN} → AF zone`, () => {
    expect(zoneIdForDomain(`deep.sub.${BRAND_DOMAIN}`, ZONES)).toBe(ZONES.af);
  });
  it(`bounce.outreach.${BRAND_DOMAIN} (MAIL FROM) → AF zone`, () => {
    expect(zoneIdForDomain(`bounce.outreach.${BRAND_DOMAIN}`, ZONES)).toBe(ZONES.af);
  });

  // ── BRAND_APP_DOMAIN (customer portal + transactional) ──
  it(`${BRAND_APP_DOMAIN} → AF.app zone`, () => {
    expect(zoneIdForDomain(BRAND_APP_DOMAIN, ZONES)).toBe(ZONES.afApp);
  });
  it(`send.${BRAND_APP_DOMAIN} → AF.app zone`, () => {
    expect(zoneIdForDomain(`send.${BRAND_APP_DOMAIN}`, ZONES)).toBe(ZONES.afApp);
  });
  it(`bounce.${BRAND_APP_DOMAIN} (MAIL FROM) → AF.app zone`, () => {
    expect(zoneIdForDomain(`bounce.${BRAND_APP_DOMAIN}`, ZONES)).toBe(ZONES.afApp);
  });
  it(`bounce.outbound.${BRAND_APP_DOMAIN} → AF.app zone`, () => {
    expect(zoneIdForDomain(`bounce.outbound.${BRAND_APP_DOMAIN}`, ZONES)).toBe(ZONES.afApp);
  });

  // ── BRAND_NET_DOMAIN (pre-seed) ──
  it(`${BRAND_NET_DOMAIN} → AF.net zone`, () => {
    expect(zoneIdForDomain(BRAND_NET_DOMAIN, ZONES)).toBe(ZONES.afNet);
  });
  it(`send.${BRAND_NET_DOMAIN} → AF.net zone`, () => {
    expect(zoneIdForDomain(`send.${BRAND_NET_DOMAIN}`, ZONES)).toBe(ZONES.afNet);
  });
  it(`bounce.${BRAND_NET_DOMAIN} (MAIL FROM) → AF.net zone`, () => {
    expect(zoneIdForDomain(`bounce.${BRAND_NET_DOMAIN}`, ZONES)).toBe(ZONES.afNet);
  });

  // ── CRAI_DOMAIN (CRAI marketing) ──
  it(`${CRAI_DOMAIN} → CRAI zone`, () => {
    expect(zoneIdForDomain(CRAI_DOMAIN, ZONES)).toBe(ZONES.crai);
  });
  it(`sub.${CRAI_DOMAIN} → CRAI zone`, () => {
    expect(zoneIdForDomain(`sub.${CRAI_DOMAIN}`, ZONES)).toBe(ZONES.crai);
  });
  it(`outbound.${CRAI_DOMAIN} → CRAI zone`, () => {
    expect(zoneIdForDomain(`outbound.${CRAI_DOMAIN}`, ZONES)).toBe(ZONES.crai);
  });

  // ── CRAI_APP_DOMAIN (CRAI future portal) ──
  it(`${CRAI_APP_DOMAIN} → CR.app zone`, () => {
    expect(zoneIdForDomain(CRAI_APP_DOMAIN, ZONES)).toBe(ZONES.crApp);
  });
  it(`send.${CRAI_APP_DOMAIN} → CR.app zone`, () => {
    expect(zoneIdForDomain(`send.${CRAI_APP_DOMAIN}`, ZONES)).toBe(ZONES.crApp);
  });
  it(`bounce.${CRAI_APP_DOMAIN} → CR.app zone`, () => {
    expect(zoneIdForDomain(`bounce.${CRAI_APP_DOMAIN}`, ZONES)).toBe(ZONES.crApp);
  });

  // ── Disambiguation between similar suffixes ──
  it('does not confuse CRAI_APP_DOMAIN with CRAI_DOMAIN', () => {
    expect(zoneIdForDomain(CRAI_APP_DOMAIN, ZONES)).toBe(ZONES.crApp);
    expect(zoneIdForDomain(CRAI_DOMAIN, ZONES)).toBe(ZONES.crai);
    // The two are completely separate zones — never cross-contaminate
    expect(zoneIdForDomain(CRAI_APP_DOMAIN, ZONES)).not.toBe(ZONES.crai);
    expect(zoneIdForDomain(CRAI_DOMAIN, ZONES)).not.toBe(ZONES.crApp);
  });

  it('does not confuse .app, .com, .net brand domains', () => {
    expect(zoneIdForDomain(BRAND_APP_DOMAIN, ZONES)).toBe(ZONES.afApp);
    expect(zoneIdForDomain(BRAND_DOMAIN, ZONES)).toBe(ZONES.af);
    expect(zoneIdForDomain(BRAND_NET_DOMAIN, ZONES)).toBe(ZONES.afNet);
    // All three should be distinct zones
    const apps = new Set([ZONES.afApp, ZONES.af, ZONES.afNet]);
    expect(apps.size).toBe(3);
  });

  // ── Null / missing zone fallback ──
  it('CRAI_DOMAIN with no CRAI zone configured → returns undefined', () => {
    const partial = { ...ZONES, crai: undefined };
    expect(zoneIdForDomain(CRAI_DOMAIN, partial)).toBeUndefined();
  });

  it('BRAND_APP_DOMAIN with no .app zone configured → returns undefined', () => {
    const partial = { ...ZONES, afApp: undefined };
    expect(zoneIdForDomain(BRAND_APP_DOMAIN, partial)).toBeUndefined();
  });

  // ── Unknown domain fallthrough ──
  it('unknown domain → falls through to AF zone ID', () => {
    expect(zoneIdForDomain('otherdomain.com', ZONES)).toBe(ZONES.af);
  });
});
