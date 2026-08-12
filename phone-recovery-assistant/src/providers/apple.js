/**
 * @file AppleRecoveryProvider — OFFICIAL Apple recovery only. No Activation
 *       Lock / iCloud bypass, no passcode circumvention.
 */

import { RecoveryProvider, RiskLevel, method } from './recoveryProvider.js';
import { RecoveryLevel } from '../recovery/recoveryLevels.js';

export class AppleRecoveryProvider extends RecoveryProvider {
  get name() {
    return 'AppleRecoveryProvider';
  }

  supportedFamilies() {
    return ['apple', 'iphone', 'ipad'];
  }

  /** @param {import('../device/deviceIdentity.js').DeviceIdentity} identity */
  supports(identity) {
    return identity.platform === 'apple' || super.supports(identity);
  }

  /** @param {import('../device/deviceIdentity.js').DeviceIdentity} _identity */
  methods(_identity) {
    return [
      method({
        id: 'apple-id-account',
        level: RecoveryLevel.LINKED_ACCOUNT,
        title: 'Confirm access to the Apple ID first',
        description:
          'A locked iPhone/iPad still requires its Apple ID after any erase (Activation Lock). Ensure you can sign in to the Apple ID at appleid.apple.com or via iforgot.apple.com. Without it, the device cannot be reactivated after erase.',
        dataPreserved: true,
        accountRequirements: ['Access to the Apple ID (and its password / trusted device) linked to this device'],
        requiredSoftware: ['Web browser (appleid.apple.com / iforgot.apple.com)'],
        risks: ['Recovering the Apple ID does not by itself remove the passcode.'],
        riskLevel: RiskLevel.LOW,
        officialReferences: ['https://iforgot.apple.com', 'https://support.apple.com/en-us/HT201365'],
        userConfirmation: 'Confirm you can sign in to YOUR Apple ID linked to this device.',
      }),
      method({
        id: 'apple-icloud-restore',
        level: RecoveryLevel.OFFICIAL_RECOVERY_MODE,
        title: 'Erase remotely + restore from iCloud backup',
        description:
          'If you know the Apple ID, you can Erase the device (Find My → Erase) or erase locally, then restore from an iCloud backup during setup. Data is recovered from the backup, not from the locked device.',
        dataPreserved: true, // via backup; the device contents are erased but restored.
        requiresFactoryReset: true,
        accountRequirements: ['Apple ID credentials', 'An existing iCloud backup for full restore'],
        requiredSoftware: ['iCloud (icloud.com/find) or on-device setup'],
        risks: ['Only content present in the iCloud backup is restored.'],
        riskLevel: RiskLevel.MEDIUM,
        officialReferences: ['https://support.apple.com/en-us/HT201252', 'https://www.icloud.com/find'],
        userConfirmation: 'Confirm your Apple ID and that a backup exists (see Backup Detection).',
      }),
      method({
        id: 'apple-recovery-mode-restore',
        level: RecoveryLevel.OFFICIAL_RECOVERY_MODE,
        title: 'Restore via Finder/iTunes (Recovery mode)',
        description:
          'Official Apple procedure: put the device in Recovery mode and Restore with Finder (macOS) or iTunes (Windows). This ERASES the device and reinstalls iOS. Activation Lock still applies afterward — you must know the Apple ID.',
        dataPreserved: false,
        requiresFactoryReset: true,
        requiredSoftware: ['Finder (macOS) or iTunes (Windows), official'],
        accountRequirements: ['Apple ID required after restore (Activation Lock)'],
        risks: ['ERASES ALL DATA.', 'Activation Lock will require the Apple ID after restore.'],
        riskLevel: RiskLevel.HIGH,
        officialReferences: ['https://support.apple.com/en-us/HT201263'],
        userConfirmation: 'DOUBLE confirmation required: you accept data loss and know the Apple ID.',
      }),
      method({
        id: 'apple-support',
        level: RecoveryLevel.MANUFACTURER_SERVICE,
        title: 'Apple Support / Apple Store',
        description:
          'If you cannot access the Apple ID, only Apple can help, through their official proof-of-ownership process. Activation Lock can be cleared only by Apple; no third party is able to do this.',
        dataPreserved: false,
        accountRequirements: ['Proof of purchase / ownership'],
        riskLevel: RiskLevel.LOW,
        officialReferences: ['https://support.apple.com', 'https://support.apple.com/en-us/HT201441'],
        userConfirmation: 'Confirm you can provide proof of ownership to Apple.',
      }),
    ];
  }
}
