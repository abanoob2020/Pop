/**
 * @file Device identification & state detection (PHASE 0 + PHASE 5).
 *
 * Nothing here guesses a model. Identity is derived only from actual probe
 * output; when a field cannot be read it stays null, and the platform/state
 * fall back to UNKNOWN. Identifiers (IMEI/serial) are stored raw only in
 * memory and are always redacted before logging or display.
 */

import { DeviceState } from './deviceState.js';
import { createNullTransport } from './transport.js';
import { redactIdentifier } from '../logging/redaction.js';

/** @enum {string} */
export const Platform = Object.freeze({
  ANDROID: 'android',
  APPLE: 'apple',
  UNKNOWN: 'unknown',
});

/**
 * Known Android manufacturers we can route to a provider. This is used only
 * for provider selection — never to assume a model.
 * @type {readonly string[]}
 */
export const KNOWN_ANDROID_VENDORS = Object.freeze([
  'samsung',
  'xiaomi',
  'redmi',
  'poco',
  'google',
  'huawei',
  'honor',
  'oppo',
  'vivo',
  'oneplus',
  'realme',
  'motorola',
  'nokia',
]);

/**
 * @typedef {Object} DeviceIdentity
 * @property {Platform} platform
 * @property {string|null} manufacturer
 * @property {string|null} model
 * @property {string|null} modelNumber
 * @property {string|null} serial        Raw, in-memory only.
 * @property {string|null} imei          Raw, in-memory only.
 * @property {string|null} osVersion
 * @property {DeviceState} state
 * @property {boolean} usbConnected
 */

/**
 * Produce an empty identity representing "no device detected". This is the
 * honest default and what callers get when no tooling/device is present.
 * @returns {DeviceIdentity}
 */
export function emptyIdentity() {
  return {
    platform: Platform.UNKNOWN,
    manufacturer: null,
    model: null,
    modelNumber: null,
    serial: null,
    imei: null,
    osVersion: null,
    state: DeviceState.UNKNOWN,
    usbConnected: false,
  };
}

/**
 * A log/display-safe projection of an identity, with identifiers redacted.
 * @param {DeviceIdentity} id
 * @returns {Record<string, unknown>}
 */
export function toSafeSummary(id) {
  return {
    platform: id.platform,
    manufacturer: id.manufacturer,
    model: id.model,
    modelNumber: id.modelNumber,
    serial: id.serial ? redactIdentifier(id.serial) : null,
    imei: id.imei ? redactIdentifier(id.imei) : null,
    osVersion: id.osVersion,
    state: id.state,
    usbConnected: id.usbConnected,
  };
}

/**
 * Map a manufacturer string to a known vendor key, or null.
 * @param {string|null} manufacturer
 * @returns {string|null}
 */
export function normalizeVendor(manufacturer) {
  if (!manufacturer) return null;
  const m = manufacturer.trim().toLowerCase();
  return KNOWN_ANDROID_VENDORS.find((v) => m.includes(v)) ?? null;
}

/**
 * DeviceStateDetector — inspects the environment through a Transport and
 * returns a DeviceIdentity. Pure with respect to its transport, so tests inject
 * a mock transport and never touch real hardware (PHASE 11).
 */
export class DeviceStateDetector {
  /**
   * @param {Object} [opts]
   * @param {import('./transport.js').Transport} [opts.transport]
   */
  constructor(opts = {}) {
    this.transport = opts.transport ?? createNullTransport();
  }

  /**
   * Detect the current device. Never throws for "no device"; returns an
   * empty/UNKNOWN identity instead (fail safe).
   * @returns {Promise<DeviceIdentity>}
   */
  async detect() {
    const identity = emptyIdentity();

    const [adb, fastboot, apple] = await Promise.all([
      safe(() => this.transport.adbDevices()),
      safe(() => this.transport.fastbootDevices()),
      safe(() => this.transport.appleDevices()),
    ]);

    // ---- Apple path ---------------------------------------------------------
    if (apple.available && hasUdid(apple.raw)) {
      identity.platform = Platform.APPLE;
      identity.manufacturer = 'Apple';
      identity.usbConnected = true;
      identity.state = DeviceState.CONNECTED;
      return identity; // Deeper Apple identity requires ideviceinfo; left null.
    }

    // ---- Android fastboot ---------------------------------------------------
    if (fastboot.available && /\bfastboot\b/i.test(fastboot.raw ?? '')) {
      identity.platform = Platform.ANDROID;
      identity.usbConnected = true;
      identity.state = DeviceState.FASTBOOT;
      return identity;
    }

    // ---- Android adb --------------------------------------------------------
    if (adb.available) {
      const line = firstDeviceLine(adb.raw);
      if (line) {
        identity.platform = Platform.ANDROID;
        identity.usbConnected = true;
        if (/\brecovery\b/i.test(line)) {
          identity.state = DeviceState.RECOVERY;
        } else if (/\bunauthorized\b/i.test(line)) {
          identity.state = DeviceState.ADB_UNAVAILABLE;
        } else if (/\bdevice\b/i.test(line)) {
          identity.state = DeviceState.ADB_AVAILABLE;
        } else {
          identity.state = DeviceState.CONNECTED;
        }
        await this._enrichAndroid(identity);
        return identity;
      }
    }

    // ---- Nothing detected ---------------------------------------------------
    return identity; // platform UNKNOWN, state UNKNOWN, usbConnected false.
  }

  /**
   * Best-effort read of read-only Android properties. Only runs when adb shell
   * is usable; failures are swallowed (fields stay null).
   * @param {DeviceIdentity} identity
   */
  async _enrichAndroid(identity) {
    if (identity.state !== DeviceState.ADB_AVAILABLE) return;
    const props = {
      manufacturer: 'ro.product.manufacturer',
      model: 'ro.product.model',
      modelNumber: 'ro.product.name',
      osVersion: 'ro.build.version.release',
    };
    for (const [field, prop] of Object.entries(props)) {
      const res = await safe(() => this.transport.adbGetProp(prop));
      if (res.available && res.raw) {
        const val = res.raw.trim();
        if (val) /** @type {any} */ (identity)[field] = val;
      }
    }
  }
}

/**
 * @param {() => Promise<import('./transport.js').ProbeResult>} fn
 * @returns {Promise<import('./transport.js').ProbeResult>}
 */
async function safe(fn) {
  try {
    return await fn();
  } catch (err) {
    return { available: false, error: /** @type {Error} */ (err)?.message };
  }
}

/**
 * @param {string|undefined} raw
 * @returns {boolean}
 */
function hasUdid(raw) {
  return Boolean(raw && raw.trim().length > 0);
}

/**
 * Extract the first real device line from `adb devices -l` output.
 * @param {string|undefined} raw
 * @returns {string|null}
 */
function firstDeviceLine(raw) {
  if (!raw) return null;
  const lines = raw
    .split('\n')
    .map((l) => l.trim())
    .filter((l) => l && !/^list of devices/i.test(l));
  return lines[0] ?? null;
}
