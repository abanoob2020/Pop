/**
 * @file Sensitive-data redaction (PHASE 8). Nothing in this list may ever be
 *       written to the audit log, console, or any artifact in clear text.
 */

/** Placeholder used for every redacted value. */
export const REDACTED = '[REDACTED]';

/**
 * Keys whose values must always be redacted, regardless of nesting depth.
 * Compared case-insensitively against normalized (a-z0-9) key names.
 * @type {readonly string[]}
 */
export const SENSITIVE_KEYS = Object.freeze([
  'pin',
  'password',
  'passwd',
  'passcode',
  'appleidpassword',
  'googlepassword',
  'credential',
  'credentials',
  'token',
  'accesstoken',
  'refreshtoken',
  'authtoken',
  'sessiontoken',
  'secret',
  'encryptionkey',
  'privatekey',
  'key',
  'keystore',
  'otp',
  'recoverykey',
]);

/**
 * Value-level patterns to mask even when they appear inside free-form strings.
 * @type {readonly {re: RegExp, replace: (m: string) => string}[]}
 */
const VALUE_PATTERNS = Object.freeze([
  // IMEI: 15 digits. We keep only the last 4 for support correlation.
  { re: /\b(\d{11})(\d{4})\b/g, replace: (_m, _a, last4) => `IMEI:***-${last4}` },
  // Long serial-like tokens (>=10 alphanumerics) -> keep last 4.
  { re: /\b[A-Z0-9]{6,}([A-Z0-9]{4})\b/g, replace: (m, last4) => (m.length >= 10 ? `***${last4}` : m) },
]);

/**
 * @param {string} key
 * @returns {boolean}
 */
function isSensitiveKey(key) {
  const norm = String(key).toLowerCase().replace(/[^a-z0-9]/g, '');
  return SENSITIVE_KEYS.includes(norm);
}

/**
 * Redact a single string value using value-level patterns.
 * @param {string} value
 * @returns {string}
 */
export function redactString(value) {
  let out = String(value);
  for (const { re, replace } of VALUE_PATTERNS) {
    out = out.replace(re, /** @type {any} */ (replace));
  }
  return out;
}

/**
 * Deep-redact an arbitrary structure. Returns a new value; never mutates input.
 * @param {unknown} input
 * @param {WeakSet<object>} [seen]
 * @returns {unknown}
 */
export function redact(input, seen = new WeakSet()) {
  if (input == null) return input;
  if (typeof input === 'string') return redactString(input);
  if (typeof input !== 'object') return input;

  if (seen.has(input)) return '[CIRCULAR]';
  seen.add(input);

  if (Array.isArray(input)) {
    return input.map((v) => redact(v, seen));
  }

  /** @type {Record<string, unknown>} */
  const out = {};
  for (const [k, v] of Object.entries(input)) {
    if (isSensitiveKey(k)) {
      out[k] = REDACTED;
    } else {
      out[k] = redact(v, seen);
    }
  }
  return out;
}

/**
 * Redact an IMEI/serial down to a non-identifying suffix for correlation.
 * @param {string} value
 * @returns {string}
 */
export function redactIdentifier(value) {
  const s = String(value ?? '').replace(/\s+/g, '');
  if (s.length <= 4) return REDACTED;
  return `***${s.slice(-4)}`;
}
