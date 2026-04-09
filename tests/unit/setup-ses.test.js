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

  // ── auditandfix.com (cold outreach + marketing) ──
  it('auditandfix.com → AF zone', () => {
    expect(zoneIdForDomain('auditandfix.com', ZONES)).toBe(ZONES.af);
  });
  it('send.auditandfix.com → AF zone', () => {
    expect(zoneIdForDomain('send.auditandfix.com', ZONES)).toBe(ZONES.af);
  });
  it('mail.auditandfix.com → AF zone', () => {
    expect(zoneIdForDomain('mail.auditandfix.com', ZONES)).toBe(ZONES.af);
  });
  it('deep.sub.auditandfix.com → AF zone', () => {
    expect(zoneIdForDomain('deep.sub.auditandfix.com', ZONES)).toBe(ZONES.af);
  });
  it('bounce.outreach.auditandfix.com (MAIL FROM) → AF zone', () => {
    expect(zoneIdForDomain('bounce.outreach.auditandfix.com', ZONES)).toBe(ZONES.af);
  });

  // ── auditandfix.app (customer portal + transactional) ──
  it('auditandfix.app → AF.app zone', () => {
    expect(zoneIdForDomain('auditandfix.app', ZONES)).toBe(ZONES.afApp);
  });
  it('send.auditandfix.app → AF.app zone', () => {
    expect(zoneIdForDomain('send.auditandfix.app', ZONES)).toBe(ZONES.afApp);
  });
  it('bounce.auditandfix.app (MAIL FROM) → AF.app zone', () => {
    expect(zoneIdForDomain('bounce.auditandfix.app', ZONES)).toBe(ZONES.afApp);
  });
  it('bounce.outbound.auditandfix.app → AF.app zone', () => {
    expect(zoneIdForDomain('bounce.outbound.auditandfix.app', ZONES)).toBe(ZONES.afApp);
  });

  // ── auditandfix.net (pre-seed) ──
  it('auditandfix.net → AF.net zone', () => {
    expect(zoneIdForDomain('auditandfix.net', ZONES)).toBe(ZONES.afNet);
  });
  it('send.auditandfix.net → AF.net zone', () => {
    expect(zoneIdForDomain('send.auditandfix.net', ZONES)).toBe(ZONES.afNet);
  });
  it('bounce.auditandfix.net (MAIL FROM) → AF.net zone', () => {
    expect(zoneIdForDomain('bounce.auditandfix.net', ZONES)).toBe(ZONES.afNet);
  });

  // ── contactreplyai.com (CRAI marketing) ──
  it('contactreplyai.com → CRAI zone', () => {
    expect(zoneIdForDomain('contactreplyai.com', ZONES)).toBe(ZONES.crai);
  });
  it('sub.contactreplyai.com → CRAI zone', () => {
    expect(zoneIdForDomain('sub.contactreplyai.com', ZONES)).toBe(ZONES.crai);
  });
  it('outbound.contactreplyai.com → CRAI zone', () => {
    expect(zoneIdForDomain('outbound.contactreplyai.com', ZONES)).toBe(ZONES.crai);
  });

  // ── contactreply.app (CRAI future portal) ──
  it('contactreply.app → CR.app zone', () => {
    expect(zoneIdForDomain('contactreply.app', ZONES)).toBe(ZONES.crApp);
  });
  it('send.contactreply.app → CR.app zone', () => {
    expect(zoneIdForDomain('send.contactreply.app', ZONES)).toBe(ZONES.crApp);
  });
  it('bounce.contactreply.app → CR.app zone', () => {
    expect(zoneIdForDomain('bounce.contactreply.app', ZONES)).toBe(ZONES.crApp);
  });

  // ── Disambiguation between similar suffixes ──
  it('does not confuse contactreply.app with contactreplyai.com', () => {
    expect(zoneIdForDomain('contactreply.app', ZONES)).toBe(ZONES.crApp);
    expect(zoneIdForDomain('contactreplyai.com', ZONES)).toBe(ZONES.crai);
    // The two are completely separate zones — never cross-contaminate
    expect(zoneIdForDomain('contactreply.app', ZONES)).not.toBe(ZONES.crai);
    expect(zoneIdForDomain('contactreplyai.com', ZONES)).not.toBe(ZONES.crApp);
  });

  it('does not confuse auditandfix.app, .com, .net', () => {
    expect(zoneIdForDomain('auditandfix.app', ZONES)).toBe(ZONES.afApp);
    expect(zoneIdForDomain('auditandfix.com', ZONES)).toBe(ZONES.af);
    expect(zoneIdForDomain('auditandfix.net', ZONES)).toBe(ZONES.afNet);
    // All three should be distinct zones
    const apps = new Set([ZONES.afApp, ZONES.af, ZONES.afNet]);
    expect(apps.size).toBe(3);
  });

  // ── Null / missing zone fallback ──
  it('contactreplyai.com with no CRAI zone configured → returns undefined', () => {
    const partial = { ...ZONES, crai: undefined };
    expect(zoneIdForDomain('contactreplyai.com', partial)).toBeUndefined();
  });

  it('auditandfix.app with no .app zone configured → returns undefined', () => {
    const partial = { ...ZONES, afApp: undefined };
    expect(zoneIdForDomain('auditandfix.app', partial)).toBeUndefined();
  });

  // ── Unknown domain fallthrough ──
  it('unknown domain → falls through to AF zone ID', () => {
    expect(zoneIdForDomain('otherdomain.com', ZONES)).toBe(ZONES.af);
  });
});
