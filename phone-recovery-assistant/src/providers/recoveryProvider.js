/**
 * @file RecoveryProvider abstraction (PHASE 4). Each concrete provider
 *       describes OFFICIAL recovery methods for a device family. Providers
 *       describe procedures; they contain NO bypass instructions.
 */

import { RecoveryLevel } from '../recovery/recoveryLevels.js';

/** @enum {string} */
export const RiskLevel = Object.freeze({
  LOW: 'Low',
  MEDIUM: 'Medium',
  HIGH: 'High',
});

/**
 * @typedef {Object} RecoveryMethod
 * @property {string} id
 * @property {number} level                 RecoveryLevel.
 * @property {string} title
 * @property {string} description
 * @property {boolean} dataPreserved         Does this method keep user data?
 * @property {boolean} requiresFactoryReset
 * @property {string[]} requiredDrivers
 * @property {string[]} requiredSoftware
 * @property {string[]} accountRequirements  e.g. "Google account credentials".
 * @property {string[]} risks
 * @property {RiskLevel} riskLevel
 * @property {string[]} officialReferences   URLs to vendor documentation.
 * @property {string} userConfirmation       Exact confirmation this method needs.
 */

/**
 * Base class. Concrete providers override the metadata methods. Kept as a class
 * for a clear, testable contract; all methods are pure/synchronous.
 */
export class RecoveryProvider {
  /** @returns {string} */
  get name() {
    return 'GenericRecoveryProvider';
  }

  /**
   * Device families this provider supports (lowercased vendor keys or
   * platforms). Used by the registry for selection.
   * @returns {string[]}
   */
  supportedFamilies() {
    return [];
  }

  /**
   * Whether this provider can handle the given identity.
   * @param {import('../device/deviceIdentity.js').DeviceIdentity} identity
   * @returns {boolean}
   */
  supports(identity) {
    const vendor = (identity.manufacturer ?? '').toLowerCase();
    return this.supportedFamilies().some((f) => vendor.includes(f));
  }

  /**
   * Ordered list of official recovery methods, least destructive first.
   * @param {import('../device/deviceIdentity.js').DeviceIdentity} _identity
   * @returns {RecoveryMethod[]}
   */
  // eslint-disable-next-line no-unused-vars
  methods(_identity) {
    return [];
  }

  /**
   * The recommended method for a given identity: the least-destructive method
   * whose preconditions plausibly hold. Defaults to the first method.
   * @param {import('../device/deviceIdentity.js').DeviceIdentity} identity
   * @returns {RecoveryMethod|null}
   */
  recommend(identity) {
    const list = this.methods(identity);
    return list.length ? list[0] : null;
  }
}

/**
 * Small helper for concrete providers to declare a method with sane defaults.
 * @param {Partial<RecoveryMethod> & {id: string, level: number, title: string}} m
 * @returns {RecoveryMethod}
 */
export function method(m) {
  return {
    description: '',
    dataPreserved: m.level < RecoveryLevel.FACTORY_RESET,
    requiresFactoryReset: m.level >= RecoveryLevel.FACTORY_RESET,
    requiredDrivers: [],
    requiredSoftware: [],
    accountRequirements: [],
    risks: [],
    riskLevel: RiskLevel.LOW,
    officialReferences: [],
    userConfirmation: 'Confirm you own this device and authorize this official procedure.',
    ...m,
  };
}
