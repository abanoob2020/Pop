/**
 * @file Dry-run report builder (PHASE 6). Produces a preview of what a recovery
 *       would involve WITHOUT performing anything on the device.
 */

import { toSafeSummary } from '../device/deviceIdentity.js';
import { RECOVERY_LEVEL_LABEL } from './recoveryLevels.js';
import { selectProvider } from '../providers/registry.js';
import { detectBackups } from '../backup/backupDetector.js';

/**
 * @typedef {Object} DryRunReport
 * @property {Record<string, unknown>} device   Redacted device summary.
 * @property {string} detectedState
 * @property {string|null} providerName
 * @property {string|null} recommendedMethodId
 * @property {string|null} recommendedTitle
 * @property {string} recoveryPath
 * @property {string[]} requiredTools
 * @property {boolean} potentialDataLoss
 * @property {string[]} accountRequirements
 * @property {string[]} estimatedSteps
 * @property {string} riskLevel
 * @property {boolean} requiresConfirmation
 * @property {boolean} requiresDoubleConfirmation
 * @property {import('../backup/backupDetector.js').BackupReport} backups
 * @property {string} summaryLine
 */

/**
 * Build a dry-run report for a detected device. Pure — no side effects, no
 * device interaction.
 * @param {import('../device/deviceIdentity.js').DeviceIdentity} identity
 * @param {Object} [opts]
 * @param {import('../providers/recoveryProvider.js').RecoveryProvider[]} [opts.providers]
 * @returns {DryRunReport}
 */
export function buildDryRun(identity, opts = {}) {
  const provider = selectProvider(identity, opts.providers);
  const backups = detectBackups(identity);
  const device = toSafeSummary(identity);

  if (!provider) {
    return {
      device,
      detectedState: identity.state,
      providerName: null,
      recommendedMethodId: null,
      recommendedTitle: null,
      recoveryPath:
        'No supported official recovery path could be determined for the current device/state.',
      requiredTools: [],
      potentialDataLoss: false,
      accountRequirements: [],
      estimatedSteps: [
        'Provide manufacturer, model number, and operating system.',
        'Confirm the current screen/state of the phone.',
        'Re-run detection with the device connected.',
      ],
      riskLevel: 'Unknown',
      requiresConfirmation: true,
      requiresDoubleConfirmation: false,
      backups,
      summaryLine:
        'Unable to determine a safe, supported recovery path yet — more information about the device is required.',
    };
  }

  const method = provider.recommend(identity);
  if (!method) {
    return {
      device,
      detectedState: identity.state,
      providerName: provider.name,
      recommendedMethodId: null,
      recommendedTitle: null,
      recoveryPath: 'The selected provider has no applicable method for this state.',
      requiredTools: [],
      potentialDataLoss: false,
      accountRequirements: [],
      estimatedSteps: [],
      riskLevel: 'Unknown',
      requiresConfirmation: true,
      requiresDoubleConfirmation: false,
      backups,
      summaryLine: 'No applicable official method for the current device state.',
    };
  }

  const requiredTools = [...method.requiredSoftware, ...method.requiredDrivers];
  const potentialDataLoss = !method.dataPreserved || method.requiresFactoryReset;

  return {
    device,
    detectedState: identity.state,
    providerName: provider.name,
    recommendedMethodId: method.id,
    recommendedTitle: method.title,
    recoveryPath: `${RECOVERY_LEVEL_LABEL[method.level]} → ${method.title}`,
    requiredTools,
    potentialDataLoss,
    accountRequirements: method.accountRequirements,
    estimatedSteps: buildSteps(method, potentialDataLoss),
    riskLevel: method.riskLevel,
    requiresConfirmation: true,
    requiresDoubleConfirmation: potentialDataLoss,
    backups,
    summaryLine: potentialDataLoss
      ? 'This path may erase data. Check backups first; a double confirmation will be required.'
      : 'A data-preserving official path may be possible. Confirmation is still required.',
  };
}

/**
 * @param {import('../providers/recoveryProvider.js').RecoveryMethod} method
 * @param {boolean} potentialDataLoss
 * @returns {string[]}
 */
function buildSteps(method, potentialDataLoss) {
  const steps = [
    'Confirm device ownership & authorization (all clauses).',
  ];
  if (method.accountRequirements.length) {
    steps.push(`Confirm account requirements: ${method.accountRequirements.join('; ')}.`);
  }
  if (potentialDataLoss) {
    steps.push('Check official backups (see Backup section) BEFORE proceeding.');
  }
  steps.push(`Follow the official procedure: ${method.title}.`);
  if (method.officialReferences.length) {
    steps.push(`Use only official references: ${method.officialReferences.join(' , ')}.`);
  }
  if (potentialDataLoss) {
    steps.push('Provide the additional explicit confirmation for data loss.');
  }
  return steps;
}
