/**
 * @file RecoveryEngine — orchestrates detection → provider selection → dry-run
 *       → gated execution. It NEVER runs a destructive step without full
 *       authorization, and NEVER runs a BLOCKED command at all. It also refuses
 *       to act automatically in fail-safe states (PHASE 12).
 */

import { DeviceStateDetector } from '../device/deviceIdentity.js';
import { FAIL_SAFE_STATES } from '../device/deviceState.js';
import { selectProvider } from '../providers/registry.js';
import { buildDryRun } from './dryRun.js';
import { isDestructiveLevel } from './recoveryLevels.js';
import { authorizeDestructive } from '../safety/ownershipGate.js';
import { assertExecutable, Classification } from '../safety/commandSafetyValidator.js';
import { AuditLog } from '../logging/auditLog.js';

/**
 * @typedef {Object} EngineResult
 * @property {'ok'|'stopped'|'blocked'|'unauthorized'|'no-path'} status
 * @property {string} message
 * @property {unknown=} data
 */

export class RecoveryEngine {
  /**
   * @param {Object} [opts]
   * @param {import('../device/transport.js').Transport} [opts.transport]
   * @param {AuditLog} [opts.audit]
   * @param {import('../providers/recoveryProvider.js').RecoveryProvider[]} [opts.providers]
   */
  constructor(opts = {}) {
    this.detector = new DeviceStateDetector({ transport: opts.transport });
    this.audit = opts.audit ?? new AuditLog();
    this.providers = opts.providers;
  }

  /**
   * Detect the device (read-only).
   * @returns {Promise<import('../device/deviceIdentity.js').DeviceIdentity>}
   */
  async detect() {
    return this.detector.detect();
  }

  /**
   * Produce a dry-run report for the currently detected device.
   * @param {import('../device/deviceIdentity.js').DeviceIdentity} identity
   * @returns {import('./dryRun.js').DryRunReport}
   */
  dryRun(identity) {
    return buildDryRun(identity, { providers: this.providers });
  }

  /**
   * Attempt to execute a recovery method. This is heavily gated:
   *  - fail-safe device state => stop, no action.
   *  - no provider/method     => no-path.
   *  - destructive method      => requires full authorization + explicit
   *                              destructive confirmation.
   *  - any command it would run passes through CommandSafetyValidator; a
   *    BLOCKED command aborts everything.
   *
   * NOTE: This method does not itself spawn device commands. It validates and
   * records intent, and returns the vetted plan for a human to carry out.
   * Automatic destructive execution is intentionally NOT implemented.
   *
   * @param {Object} params
   * @param {import('../device/deviceIdentity.js').DeviceIdentity} params.identity
   * @param {string} params.methodId
   * @param {Record<string, boolean>} [params.affirmations]
   * @param {boolean} [params.explicitDestructiveConfirmation]
   * @returns {Promise<EngineResult>}
   */
  async execute({ identity, methodId, affirmations = {}, explicitDestructiveConfirmation = false }) {
    // 1) Fail-safe states: stop, never auto-proceed.
    if (FAIL_SAFE_STATES.has(identity.state)) {
      const res = /** @type {EngineResult} */ ({
        status: 'stopped',
        message: 'Device is in a fail-safe state (UNKNOWN). Stopping safely; no action taken.',
      });
      await this._log(identity, methodId, 'stopped', res.message, false);
      return res;
    }

    // 2) Provider / method resolution.
    const provider = selectProvider(identity, this.providers);
    if (!provider) {
      const res = /** @type {EngineResult} */ ({ status: 'no-path', message: 'No supported official recovery provider for this device.' });
      await this._log(identity, methodId, 'no-path', res.message, false);
      return res;
    }
    const method = provider.methods(identity).find((m) => m.id === methodId);
    if (!method) {
      const res = /** @type {EngineResult} */ ({ status: 'no-path', message: `Unknown method: ${methodId}` });
      await this._log(identity, methodId, 'no-path', res.message, false);
      return res;
    }

    // 3) Destructive gating.
    if (isDestructiveLevel(method.level) || method.requiresFactoryReset) {
      const auth = authorizeDestructive({ affirmations, explicitDestructiveConfirmation });
      if (!auth.allowed) {
        const res = /** @type {EngineResult} */ ({ status: 'unauthorized', message: auth.reason });
        await this._log(identity, methodId, 'unauthorized', auth.reason, false);
        return res;
      }
    }

    // 4) Safety-validate the (advisory) command associated with the method.
    //    We validate the human intent so a method whose purpose is protection
    //    circumvention could never slip through, even by mislabeling.
    try {
      const check = assertExecutable(method.title, { intent: method.description });
      if (check.classification === Classification.BLOCKED) {
        // assertExecutable throws on BLOCKED; this is defense in depth.
        throw new Error('BLOCKED');
      }
    } catch (err) {
      const res = /** @type {EngineResult} */ ({
        status: 'blocked',
        message: /** @type {Error} */ (err).message,
      });
      await this._log(identity, methodId, 'blocked', res.message, true);
      return res;
    }

    // 5) Approved plan (execution of the physical steps is left to the human,
    //    following official references). Record and return the vetted plan.
    const res = /** @type {EngineResult} */ ({
      status: 'ok',
      message: `Authorized official plan: ${method.title}. Follow the official references; this tool will not perform the destructive step automatically.`,
      data: {
        methodId: method.id,
        title: method.title,
        officialReferences: method.officialReferences,
        dataPreserved: method.dataPreserved,
      },
    });
    await this._log(identity, methodId, 'authorized', res.message, true);
    return res;
  }

  /**
   * @param {import('../device/deviceIdentity.js').DeviceIdentity} identity
   * @param {string} operation
   * @param {string} result
   * @param {string} message
   * @param {boolean} userConfirmation
   */
  async _log(identity, operation, result, message, userConfirmation) {
    await this.audit.record({
      deviceModel: identity.model ?? identity.manufacturer ?? 'unknown',
      deviceState: identity.state,
      operation,
      result,
      error: result === 'blocked' || result === 'unauthorized' ? message : undefined,
      userConfirmation,
    });
  }
}
