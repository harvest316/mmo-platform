/**
 * Shared domain/brand constants for unit tests.
 *
 * Centralises project-specific strings so they appear in one place.
 * Tests that assert routing logic (e.g. setup-ses, email-forwarder) import
 * from here so a domain rename only requires editing this file.
 */

export const BRAND_DOMAIN      = 'auditandfix.com';
export const BRAND_APP_DOMAIN  = 'auditandfix.app';
export const BRAND_NET_DOMAIN  = 'auditandfix.net';
export const CRAI_DOMAIN       = 'contactreplyai.com';
export const CRAI_APP_DOMAIN   = 'contactreply.app';
export const CRAI_INBOUND_DOMAIN = 'inbound.contactreplyai.com';

export const BRAND_NAME        = 'Audit&Fix';
export const PERSONA_NAME      = 'Marcus';

export const TEST_SENDER_EMAIL = `marcus@${BRAND_DOMAIN}`;
export const TEST_CANARY_EMAIL = `test+canary@${BRAND_DOMAIN}`;
export const TEST_STATUS_EMAIL = `status@${BRAND_NET_DOMAIN}`;
export const TEST_FWD_EMAIL    = `status@dev.${BRAND_DOMAIN}`;
