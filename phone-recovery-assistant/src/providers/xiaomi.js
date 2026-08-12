/**
 * @file XiaomiRecoveryProvider — OFFICIAL Xiaomi / Redmi / POCO recovery only.
 */

import { RecoveryProvider, RiskLevel, method } from './recoveryProvider.js';
import { RecoveryLevel } from '../recovery/recoveryLevels.js';

export class XiaomiRecoveryProvider extends RecoveryProvider {
  get name() {
    return 'XiaomiRecoveryProvider';
  }

  supportedFamilies() {
    return ['xiaomi', 'redmi', 'poco'];
  }

  /** @param {import('../device/deviceIdentity.js').DeviceIdentity} _identity */
  methods(_identity) {
    return [
      method({
        id: 'mi-account-find-device',
        level: RecoveryLevel.LINKED_ACCOUNT,
        title: 'Sign in with the Mi account (Find Device)',
        description:
          'If the Mi account was linked and Find Device enabled, sign in at i.mi.com with that account. Some official account-based options may help regain access without wiping. Preserves data where supported.',
        dataPreserved: true,
        accountRequirements: ['Mi account credentials tied to the device'],
        requiredSoftware: ['Web browser (i.mi.com)'],
        risks: ['Only works if a Mi account was linked and the feature enabled.'],
        riskLevel: RiskLevel.LOW,
        officialReferences: ['https://i.mi.com', 'https://www.mi.com/global/support/'],
        userConfirmation: 'Confirm you can sign in to the Mi account linked to this device.',
      }),
      method({
        id: 'mi-flash-official',
        level: RecoveryLevel.OFFICIAL_RECOVERY_MODE,
        title: 'Reflash official MIUI/HyperOS firmware (Mi Flash)',
        description:
          'Use the official Mi Flash tool with the correct fastboot ROM for the exact model. This is destructive. The Mi account lock and Google Account Protection both still apply afterward, so you must know those accounts.',
        dataPreserved: false,
        requiresFactoryReset: true,
        requiredSoftware: ['Mi Flash tool (official)', 'Correct official fastboot ROM'],
        requiredDrivers: ['Xiaomi USB / Qualcomm drivers (official)'],
        risks: [
          'ERASES data.',
          'Mi account / Google FRP protections still apply afterward.',
          'Bootloader-locked devices cannot be flashed without the official unlock process (which itself has a waiting period and wipes data).',
        ],
        riskLevel: RiskLevel.HIGH,
        officialReferences: ['https://www.mi.com/global/support/', 'https://c.mi.com/'],
        userConfirmation: 'DOUBLE confirmation required: you accept data loss and know the linked accounts.',
      }),
      method({
        id: 'xiaomi-service',
        level: RecoveryLevel.MANUFACTURER_SERVICE,
        title: 'Authorized Xiaomi service center',
        description: 'Official service center recovery with proof of ownership.',
        dataPreserved: false,
        accountRequirements: ['Proof of ownership'],
        riskLevel: RiskLevel.LOW,
        officialReferences: ['https://www.mi.com/global/support/'],
        userConfirmation: 'Confirm you can provide proof of ownership.',
      }),
    ];
  }
}
