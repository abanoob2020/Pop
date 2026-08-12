/**
 * @file CommandSafetyValidator — every command that the tool would ever run
 *       or suggest MUST pass through here first (PHASE 7).
 *
 * Classification:
 *   SAFE        — read-only / informational, no data risk.
 *   WARNING     — state-changing but reversible / low risk; needs awareness.
 *   DESTRUCTIVE — may erase user data (e.g. factory reset); needs explicit
 *                 double confirmation before it can proceed.
 *   BLOCKED     — intent is to circumvent vendor security. Never executes.
 */

import { matchBlockedRule } from './blockedPatterns.js';

/** @enum {string} */
export const Classification = Object.freeze({
  SAFE: 'SAFE',
  WARNING: 'WARNING',
  DESTRUCTIVE: 'DESTRUCTIVE',
  BLOCKED: 'BLOCKED',
});

/**
 * Command shapes that wipe or reset user data. These are permitted only as a
 * LAST resort and only behind explicit double confirmation elsewhere; here we
 * merely classify them so the rest of the system can enforce that.
 * @type {RegExp[]}
 */
const DESTRUCTIVE_PATTERNS = [
  /\bfastboot\b.*(\s-w\b|\berase\b|\bformat\b)/i,
  /\brecovery\b.*\bwipe\b/i,
  /\bwipe\b.*\b(data|userdata|cache)\b/i,
  /\bfactory\s*reset\b/i,
  /\berase\s*all\s*content\b/i, // Apple "Erase All Content and Settings"
  /\bidevicerestore\b/i,
  /\brm\b\s+-rf?\b/i,
];

/**
 * State-changing but reversible / preparatory commands.
 * @type {RegExp[]}
 */
const WARNING_PATTERNS = [
  /\badb\b.*\breboot\b/i,
  /\bfastboot\b.*\breboot\b/i,
  /\bidevicediagnostics\b.*\brestart\b/i,
  /\bpush\b|\bpull\b/i,
];

/**
 * Read-only / diagnostic commands considered SAFE.
 * @type {RegExp[]}
 */
const SAFE_PATTERNS = [
  /\badb\b.*\b(devices|get-state|version)\b/i,
  /\bfastboot\b.*\b(devices|getvar)\b/i,
  /\bidevice_id\b/i,
  /\bideviceinfo\b/i,
  /\becho\b/i,
];

/**
 * @typedef {Object} SafetyResult
 * @property {Classification} classification
 * @property {boolean} allowed            Whether execution may proceed at all.
 * @property {boolean} requiresConfirmation
 * @property {boolean} requiresDoubleConfirmation
 * @property {string}  reason
 * @property {string=} blockedRuleId
 */

/**
 * Classify a command string together with its declared human intent. Both are
 * checked against the blocked rules so that a harmless-looking command with a
 * malicious stated purpose is still blocked, and vice versa.
 *
 * @param {string} command  The literal command that would run.
 * @param {Object} [opts]
 * @param {string} [opts.intent]  Human description of what the command is for.
 * @returns {SafetyResult}
 */
export function classifyCommand(command, opts = {}) {
  const intent = opts.intent ?? '';
  const combined = `${command} ${intent}`;

  // 1) Blocked intent always wins, no matter how the command is spelled.
  const blocked = matchBlockedRule(combined);
  if (blocked) {
    return {
      classification: Classification.BLOCKED,
      allowed: false,
      requiresConfirmation: false,
      requiresDoubleConfirmation: false,
      reason: blocked.reason,
      blockedRuleId: blocked.id,
    };
  }

  const text = String(command ?? '');

  // 2) Destructive (data-loss) commands: allowed only with double confirm.
  if (DESTRUCTIVE_PATTERNS.some((re) => re.test(text))) {
    return {
      classification: Classification.DESTRUCTIVE,
      allowed: true,
      requiresConfirmation: true,
      requiresDoubleConfirmation: true,
      reason: 'This command can erase data on the device. Explicit double confirmation is required.',
    };
  }

  // 3) Reversible state changes: single confirmation / awareness.
  if (WARNING_PATTERNS.some((re) => re.test(text))) {
    return {
      classification: Classification.WARNING,
      allowed: true,
      requiresConfirmation: true,
      requiresDoubleConfirmation: false,
      reason: 'This command changes device state but is generally reversible.',
    };
  }

  // 4) Known-safe read-only commands.
  if (SAFE_PATTERNS.some((re) => re.test(text))) {
    return {
      classification: Classification.SAFE,
      allowed: true,
      requiresConfirmation: false,
      requiresDoubleConfirmation: false,
      reason: 'Read-only / diagnostic command.',
    };
  }

  // 5) Unknown commands are treated conservatively as WARNING, never assumed
  //    safe. Fail closed.
  return {
    classification: Classification.WARNING,
    allowed: true,
    requiresConfirmation: true,
    requiresDoubleConfirmation: false,
    reason: 'Unrecognized command; treated cautiously and requires confirmation.',
  };
}

/**
 * Throws if a command must not run. Callers that intend to execute anything
 * should gate on this. BLOCKED commands can never be forced through.
 * @param {string} command
 * @param {Object} [opts]
 * @param {string} [opts.intent]
 * @returns {SafetyResult}
 */
export function assertExecutable(command, opts = {}) {
  const result = classifyCommand(command, opts);
  if (result.classification === Classification.BLOCKED) {
    const err = new Error(`BLOCKED: ${result.reason}`);
    err.code = 'E_BLOCKED_COMMAND';
    err.safety = result;
    throw err;
  }
  return result;
}
