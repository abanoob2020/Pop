/**
 * @file Data-preservation-first recovery ladder (PHASE 2). Lower level number
 *       = tried first = least risk to data. Factory reset is always last.
 */

/** @enum {number} */
export const RecoveryLevel = Object.freeze({
  OFFICIAL_NON_DESTRUCTIVE: 1, // Official recovery, no data loss.
  LINKED_ACCOUNT: 2, // Use the account linked to the device (if allowed).
  MANUFACTURER_SERVICE: 3, // Official manufacturer / service center path.
  OFFICIAL_RECOVERY_MODE: 4, // Vendor recovery mode procedure.
  FACTORY_RESET: 5, // Last resort; destructive.
});

/** @type {Record<number, string>} */
export const RECOVERY_LEVEL_LABEL = Object.freeze({
  1: 'Official recovery without data loss',
  2: 'Linked-account recovery (if the platform permits)',
  3: 'Official manufacturer / service center',
  4: 'Official recovery mode',
  5: 'Factory reset (LAST RESORT — data loss)',
});

/**
 * Whether a level is inherently destructive.
 * @param {number} level
 * @returns {boolean}
 */
export function isDestructiveLevel(level) {
  return level >= RecoveryLevel.FACTORY_RESET;
}
