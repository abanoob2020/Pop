/**
 * @file GoogleRecoveryProvider — OFFICIAL Google / Pixel recovery paths only.
 */

import { RecoveryProvider, RiskLevel, method } from './recoveryProvider.js';
import { RecoveryLevel } from '../recovery/recoveryLevels.js';

export class GoogleRecoveryProvider extends RecoveryProvider {
  get name() {
    return 'GoogleRecoveryProvider';
  }

  supportedFamilies() {
    return ['google', 'pixel'];
  }

  /** @param {import('../device/deviceIdentity.js').DeviceIdentity} _identity */
  methods(_identity) {
    return [
      method({
        id: 'google-account-recovery',
        level: RecoveryLevel.LINKED_ACCOUNT,
        title: 'Recover the Google account first',
        description:
          'The lock screen is protected locally, but knowing the Google account tied to the phone is essential for setup after any reset (FRP). Recover the account at g.co/recover if needed. This step preserves whatever is in Google backups.',
        dataPreserved: true,
        accountRequirements: ['Access to the Google account linked to the device'],
        requiredSoftware: ['Web browser (g.co/recover)'],
        risks: ['Recovering the account does not unlock the screen by itself.'],
        riskLevel: RiskLevel.LOW,
        officialReferences: ['https://g.co/recover', 'https://support.google.com/android'],
        userConfirmation: 'Confirm you are recovering YOUR own Google account.',
      }),
      method({
        id: 'pixel-official-restore',
        level: RecoveryLevel.OFFICIAL_RECOVERY_MODE,
        title: 'Reflash stock firmware via Android Flash Tool (official)',
        description:
          'Google\'s official Android Flash Tool (flash.android.com) can restore stock firmware on Pixel. Requires an unlockable/unlocked bootloader state; wiping is typical. Google Account Protection still applies afterward, so you must know the linked account.',
        dataPreserved: false,
        requiresFactoryReset: true,
        requiredSoftware: ['Android Flash Tool (flash.android.com, official)'],
        requiredDrivers: ['Chrome/WebUSB'],
        risks: ['Flashing typically erases data.', 'FRP still applies after flashing — you must know the account.'],
        riskLevel: RiskLevel.HIGH,
        officialReferences: ['https://flash.android.com', 'https://developers.google.com/android/images'],
        userConfirmation: 'DOUBLE confirmation required: you accept data loss and know the linked Google account.',
      }),
      method({
        id: 'google-repair',
        level: RecoveryLevel.MANUFACTURER_SERVICE,
        title: 'Official Google / Pixel repair',
        description: 'Use Google\'s official repair/support flow with proof of ownership.',
        dataPreserved: false,
        accountRequirements: ['Proof of ownership'],
        riskLevel: RiskLevel.LOW,
        officialReferences: ['https://support.google.com/store'],
        userConfirmation: 'Confirm you can provide proof of ownership.',
      }),
    ];
  }
}
