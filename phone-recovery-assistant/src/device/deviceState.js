/**
 * @file Device connection/boot states (PHASE 5). UNKNOWN is a terminal safe
 *       state — it is NEVER converted into a bypass path.
 */

/** @enum {string} */
export const DeviceState = Object.freeze({
  CONNECTED: 'CONNECTED',
  LOCKED: 'LOCKED',
  ADB_AVAILABLE: 'ADB_AVAILABLE',
  ADB_UNAVAILABLE: 'ADB_UNAVAILABLE',
  FASTBOOT: 'FASTBOOT',
  RECOVERY: 'RECOVERY',
  DFU: 'DFU',
  NORMAL_BOOT: 'NORMAL_BOOT',
  UNKNOWN: 'UNKNOWN',
});

/**
 * States in which the tool must stop and hand control back to the user rather
 * than attempting further automatic action (PHASE 12).
 * @type {Set<string>}
 */
export const FAIL_SAFE_STATES = new Set([DeviceState.UNKNOWN]);
