import { test } from 'node:test';
import assert from 'node:assert/strict';
import { DeviceStateDetector } from '../src/device/deviceIdentity.js';
import { DeviceState } from '../src/device/deviceState.js';
import { mockTransport, noDeviceTransport } from './mocks.js';

test('no device / no tooling => UNKNOWN, not connected (honest default)', async () => {
  const d = new DeviceStateDetector({ transport: noDeviceTransport() });
  const id = await d.detect();
  assert.equal(id.state, DeviceState.UNKNOWN);
  assert.equal(id.usbConnected, false);
  assert.equal(id.platform, 'unknown');
});

test('adb authorized android device => ADB_AVAILABLE and enriched props', async () => {
  const d = new DeviceStateDetector({
    transport: mockTransport({
      adb: { available: true, raw: 'List of devices attached\nR58N1234ABC   device product:beyond1' },
      props: {
        'ro.product.manufacturer': 'samsung',
        'ro.product.model': 'SM-G973F',
        'ro.build.version.release': '12',
      },
    }),
  });
  const id = await d.detect();
  assert.equal(id.state, DeviceState.ADB_AVAILABLE);
  assert.equal(id.platform, 'android');
  assert.equal(id.manufacturer, 'samsung');
  assert.equal(id.model, 'SM-G973F');
  assert.equal(id.usbConnected, true);
});

test('adb unauthorized => ADB_UNAVAILABLE (no property reads)', async () => {
  const d = new DeviceStateDetector({
    transport: mockTransport({
      adb: { available: true, raw: 'List of devices attached\nR58N1234ABC   unauthorized' },
    }),
  });
  const id = await d.detect();
  assert.equal(id.state, DeviceState.ADB_UNAVAILABLE);
  assert.equal(id.model, null);
});

test('recovery mode detected', async () => {
  const d = new DeviceStateDetector({
    transport: mockTransport({
      adb: { available: true, raw: 'List of devices attached\nR58N1234ABC   recovery' },
    }),
  });
  const id = await d.detect();
  assert.equal(id.state, DeviceState.RECOVERY);
});

test('fastboot device detected', async () => {
  const d = new DeviceStateDetector({
    transport: mockTransport({ fastboot: { available: true, raw: 'abcd1234\tfastboot' } }),
  });
  const id = await d.detect();
  assert.equal(id.state, DeviceState.FASTBOOT);
  assert.equal(id.platform, 'android');
});

test('apple device detected', async () => {
  const d = new DeviceStateDetector({
    transport: mockTransport({ apple: { available: true, raw: '00008030-000A1B2C3D4E' } }),
  });
  const id = await d.detect();
  assert.equal(id.platform, 'apple');
  assert.equal(id.manufacturer, 'Apple');
  assert.equal(id.state, DeviceState.CONNECTED);
});

test('USB disconnect mid-probe (transport throws) => safe UNKNOWN', async () => {
  const throwing = {
    adbDevices: async () => {
      throw new Error('USB disconnected');
    },
    fastbootDevices: async () => ({ available: false }),
    appleDevices: async () => ({ available: false }),
    adbGetProp: async () => ({ available: false }),
  };
  const d = new DeviceStateDetector({ transport: throwing });
  const id = await d.detect();
  assert.equal(id.state, DeviceState.UNKNOWN);
  assert.equal(id.usbConnected, false);
});
