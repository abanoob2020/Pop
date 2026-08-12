/**
 * @file Provider registry & selection (PHASE 4). Selects the most specific
 *       official provider for a device; never assumes a model.
 */

import { SamsungRecoveryProvider } from './samsung.js';
import { GoogleRecoveryProvider } from './google.js';
import { XiaomiRecoveryProvider } from './xiaomi.js';
import { AppleRecoveryProvider } from './apple.js';
import { GenericAndroidRecoveryProvider } from './genericAndroid.js';

/**
 * Ordered most-specific first; the generic Android provider is the fallback and
 * must remain last.
 * @returns {import('./recoveryProvider.js').RecoveryProvider[]}
 */
export function defaultProviders() {
  return [
    new AppleRecoveryProvider(),
    new SamsungRecoveryProvider(),
    new GoogleRecoveryProvider(),
    new XiaomiRecoveryProvider(),
    new GenericAndroidRecoveryProvider(), // fallback
  ];
}

/**
 * Choose a provider for the given identity, or null if none applies (e.g. an
 * UNKNOWN device — which must NOT be forced into any path).
 * @param {import('../device/deviceIdentity.js').DeviceIdentity} identity
 * @param {import('./recoveryProvider.js').RecoveryProvider[]} [providers]
 * @returns {import('./recoveryProvider.js').RecoveryProvider|null}
 */
export function selectProvider(identity, providers = defaultProviders()) {
  if (!identity || identity.platform === 'unknown') return null;
  return providers.find((p) => p.supports(identity)) ?? null;
}
