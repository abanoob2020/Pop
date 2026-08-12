/**
 * @file Shared test mocks. No real device or tooling is ever used (PHASE 11).
 */

/**
 * Build a mock Transport with canned probe results.
 * @param {Object} cfg
 * @param {import('../src/device/transport.js').ProbeResult} [cfg.adb]
 * @param {import('../src/device/transport.js').ProbeResult} [cfg.fastboot]
 * @param {import('../src/device/transport.js').ProbeResult} [cfg.apple]
 * @param {Record<string, string>} [cfg.props]  getprop -> value
 * @returns {import('../src/device/transport.js').Transport}
 */
export function mockTransport(cfg = {}) {
  const un = { available: false, error: 'unavailable' };
  return {
    adbDevices: async () => cfg.adb ?? un,
    fastbootDevices: async () => cfg.fastboot ?? un,
    appleDevices: async () => cfg.apple ?? un,
    adbGetProp: async (prop) => {
      const v = cfg.props?.[prop];
      return v ? { available: true, raw: v } : un;
    },
  };
}

/** A transport that reports everything unavailable (no device). */
export function noDeviceTransport() {
  return mockTransport({});
}
