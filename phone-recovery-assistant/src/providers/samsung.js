/**
 * @file SamsungRecoveryProvider — OFFICIAL Samsung recovery paths only.
 *       No Knox bypass, no FRP removal, no lock-screen circumvention.
 */

import { RecoveryProvider, RiskLevel, method } from './recoveryProvider.js';
import { RecoveryLevel } from '../recovery/recoveryLevels.js';

export class SamsungRecoveryProvider extends RecoveryProvider {
  get name() {
    return 'SamsungRecoveryProvider';
  }

  supportedFamilies() {
    return ['samsung'];
  }

  /** @param {import('../device/deviceIdentity.js').DeviceIdentity} _identity */
  methods(_identity) {
    return [
      method({
        id: 'samsung-find-my-mobile-unlock',
        level: RecoveryLevel.LINKED_ACCOUNT,
        title: 'Remote Unlock via Samsung account (Find My Mobile)',
        description:
          'If the phone had SmartThings Find / Find My Mobile and "Remote unlock" enabled, sign in at findmymobile.samsung.com with the Samsung account tied to the device and use Unlock. This preserves data.',
        dataPreserved: true,
        accountRequirements: ['Samsung account credentials tied to this device', 'Remote unlock previously enabled'],
        requiredSoftware: ['Web browser (findmymobile.samsung.com)'],
        risks: ['Requires the Samsung account; will not work if it was never linked or remote unlock was off.'],
        riskLevel: RiskLevel.LOW,
        officialReferences: ['https://findmymobile.samsung.com', 'https://www.samsung.com/support/'],
        userConfirmation: 'Confirm you can sign in to the Samsung account linked to this device.',
      }),
      method({
        id: 'samsung-smart-switch-repair',
        level: RecoveryLevel.OFFICIAL_RECOVERY_MODE,
        title: 'Software repair via Samsung Smart Switch',
        description:
          'Use the official Smart Switch desktop app → "Emergency software recovery / initialization" with the exact model. Reinstalling stock firmware may resolve a soft-locked/boot-looping device.',
        dataPreserved: false,
        requiresFactoryReset: false,
        requiredSoftware: ['Samsung Smart Switch (official)'],
        requiredDrivers: ['Samsung USB drivers (official)'],
        risks: ['Firmware reinstall can reset data depending on device state.', 'Must use firmware for the exact model number.'],
        riskLevel: RiskLevel.MEDIUM,
        officialReferences: ['https://www.samsung.com/smartswitch/'],
        userConfirmation: 'Confirm the exact model number and that you accept possible data loss.',
      }),
      method({
        id: 'samsung-service-center',
        level: RecoveryLevel.MANUFACTURER_SERVICE,
        title: 'Authorized Samsung service center',
        description:
          'Bring the device and proof of ownership to an authorized Samsung service center for official recovery/repair.',
        dataPreserved: false,
        accountRequirements: ['Proof of ownership (receipt/invoice)'],
        risks: ['Service may require a reset to restore functionality.'],
        riskLevel: RiskLevel.LOW,
        officialReferences: ['https://www.samsung.com/support/'],
        userConfirmation: 'Confirm you can provide proof of ownership at the service center.',
      }),
      method({
        id: 'samsung-recovery-factory-reset',
        level: RecoveryLevel.FACTORY_RESET,
        title: 'Factory reset via stock Recovery (LAST RESORT)',
        description:
          'Official stock recovery "Wipe data/factory reset". This ERASES all data. Important: after the reset, Google Account Protection will still require the Google account that was previously signed in on this device, so make sure you know it. This tool does not change that protection.',
        dataPreserved: false,
        requiresFactoryReset: true,
        accountRequirements: ['You MUST know the Google account previously on the device (FRP) to finish setup.'],
        risks: [
          'ERASES ALL DATA on the device.',
          'FRP / Google Account Protection will require the prior account after reset.',
        ],
        riskLevel: RiskLevel.HIGH,
        officialReferences: ['https://www.samsung.com/support/'],
        userConfirmation: 'DOUBLE confirmation required: you accept full data loss and know the linked Google account.',
      }),
    ];
  }
}
