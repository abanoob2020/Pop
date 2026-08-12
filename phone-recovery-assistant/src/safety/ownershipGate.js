/**
 * @file Ownership & Recovery Authorization gate (PHASE 1).
 *
 * No destructive recovery operation may proceed until the user has explicitly
 * affirmed every clause below. This is a pure state machine — it collects and
 * validates affirmations; the actual prompting lives in the UI layer so it can
 * be tested without any I/O.
 */

/**
 * The exact clauses the owner must confirm. Order and wording are part of the
 * product's safety contract and are referenced by RECOVERY_POLICY.md.
 * @type {readonly {key: string, text: string}[]}
 */
export const OWNERSHIP_CLAUSES = Object.freeze([
  { key: 'owns_device', text: 'This device belongs to me.' },
  { key: 'legal_right', text: 'I have the legal right to recover access to it.' },
  { key: 'accepts_data_loss_risk', text: 'I understand that some recovery methods may result in data loss.' },
  {
    key: 'understands_no_bypass',
    text: 'I understand this tool will NOT bypass Activation Lock, FRP, Secure Boot, or any vendor protection.',
  },
  { key: 'official_only', text: 'I agree to perform official recovery procedures only.' },
]);

/**
 * @typedef {Object} AuthorizationState
 * @property {boolean} authorized
 * @property {string[]} missing  Keys of clauses not yet affirmed.
 */

/**
 * Evaluate a map of clause affirmations.
 * @param {Record<string, boolean>} affirmations
 * @returns {AuthorizationState}
 */
export function evaluateAuthorization(affirmations = {}) {
  const missing = OWNERSHIP_CLAUSES.filter((c) => affirmations[c.key] !== true).map(
    (c) => c.key,
  );
  return { authorized: missing.length === 0, missing };
}

/**
 * A destructive operation requires full authorization AND an explicit,
 * separately-typed confirmation phrase. This mirrors PHASE 2's requirement of
 * an "additional confirmation" before factory reset.
 * @param {Object} params
 * @param {Record<string, boolean>} params.affirmations
 * @param {boolean} params.explicitDestructiveConfirmation
 * @returns {{allowed: boolean, reason: string}}
 */
export function authorizeDestructive({ affirmations, explicitDestructiveConfirmation }) {
  const { authorized, missing } = evaluateAuthorization(affirmations);
  if (!authorized) {
    return {
      allowed: false,
      reason: `Ownership authorization incomplete. Missing: ${missing.join(', ')}`,
    };
  }
  if (explicitDestructiveConfirmation !== true) {
    return {
      allowed: false,
      reason: 'Destructive operation requires an additional explicit confirmation.',
    };
  }
  return { allowed: true, reason: 'Authorized for a destructive operation.' };
}
