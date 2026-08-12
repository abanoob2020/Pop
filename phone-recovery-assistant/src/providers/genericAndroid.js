/**
 * @file GenericAndroidRecoveryProvider — fallback for Android vendors without a
 *       dedicated provider (Oppo, Vivo, OnePlus, Realme, Motorola, Nokia,
 *       Huawei, etc.). OFFICIAL guidance only.
 */

import { RecoveryProvider, RiskLevel, method } from './recoveryProvider.js';
import { RecoveryLevel } from '../recovery/recoveryLevels.js';

export class GenericAndroidRecoveryProvider extends RecoveryProvider {
  get name() {
    return 'GenericAndroidRecoveryProvider';
  }

  supportedFamilies() {
    return ['oppo', 'vivo', 'oneplus', 'realme', 'motorola', 'nokia', 'huawei', 'honor'];
  }

  /**
   * Fallback matches any Android device; the registry uses it last.
   * @param {import('../device/deviceIdentity.js').DeviceIdentity} identity
   */
  supports(identity) {
    return identity.platform === 'android';
  }

  /** @param {import('../device/deviceIdentity.js').DeviceIdentity} _identity */
  methods(_identity) {
    return [
      method({
        id: 'generic-google-account',
        level: RecoveryLevel.LINKED_ACCOUNT,
        title: 'Recover the linked Google account',
        description:
          'Ensure you can access the Google account tied to the phone (g.co/recover). It is required after any reset (FRP) and unlocks access to Google backups.',
        dataPreserved: true,
        accountRequirements: ['Access to the linked Google account'],
        requiredSoftware: ['Web browser (g.co/recover)'],
        riskLevel: RiskLevel.LOW,
        officialReferences: ['https://g.co/recover'],
        userConfirmation: 'Confirm you are recovering YOUR own Google account.',
      }),
      method({
        id: 'generic-manufacturer-account',
        level: RecoveryLevel.LINKED_ACCOUNT,
        title: 'Use the manufacturer account / find-my-device service',
        description:
          'Many vendors (OnePlus, Oppo, Vivo, Realme, Motorola, Huawei/Honor) offer an official account or find-device portal. Sign in with the account linked to the device and use any official remote options.',
        dataPreserved: true,
        accountRequirements: ['Manufacturer account credentials tied to the device'],
        riskLevel: RiskLevel.LOW,
        officialReferences: ['Manufacturer official support site'],
        userConfirmation: 'Confirm you can sign in to the manufacturer account linked to this device.',
      }),
      method({
        id: 'generic-service-center',
        level: RecoveryLevel.MANUFACTURER_SERVICE,
        title: 'Authorized manufacturer service center',
        description: 'Official service center recovery with proof of ownership.',
        dataPreserved: false,
        accountRequirements: ['Proof of ownership'],
        riskLevel: RiskLevel.LOW,
        officialReferences: ['Manufacturer official support site'],
        userConfirmation: 'Confirm you can provide proof of ownership.',
      }),
      method({
        id: 'generic-recovery-factory-reset',
        level: RecoveryLevel.FACTORY_RESET,
        title: 'Factory reset via official stock recovery (LAST RESORT)',
        description:
          'Official stock recovery "Wipe data/factory reset". This ERASES all data. Google Account Protection will still require the Google account that was previously signed in on this device, so you must know it to finish setup afterward. This tool does not change that protection.',
        dataPreserved: false,
        requiresFactoryReset: true,
        accountRequirements: ['You MUST know the linked Google account (FRP) to complete setup.'],
        risks: ['ERASES ALL DATA.', 'FRP requires the prior Google account after reset.'],
        riskLevel: RiskLevel.HIGH,
        officialReferences: ['Manufacturer official support site', 'https://support.google.com/android'],
        userConfirmation: 'DOUBLE confirmation required: you accept full data loss and know the linked Google account.',
      }),
    ];
  }
}
