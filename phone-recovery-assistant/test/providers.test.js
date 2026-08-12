import { test } from 'node:test';
import assert from 'node:assert/strict';
import { selectProvider, defaultProviders } from '../src/providers/registry.js';
import { emptyIdentity, Platform } from '../src/device/deviceIdentity.js';
import { RecoveryLevel } from '../src/recovery/recoveryLevels.js';
import { classifyCommand, Classification } from '../src/safety/commandSafetyValidator.js';

/**
 * @param {Partial<import('../src/device/deviceIdentity.js').DeviceIdentity>} over
 */
function id(over) {
  return { ...emptyIdentity(), ...over };
}

test('provider selection by manufacturer', () => {
  assert.equal(selectProvider(id({ platform: Platform.ANDROID, manufacturer: 'samsung' })).name, 'SamsungRecoveryProvider');
  assert.equal(selectProvider(id({ platform: Platform.ANDROID, manufacturer: 'Google' })).name, 'GoogleRecoveryProvider');
  assert.equal(selectProvider(id({ platform: Platform.ANDROID, manufacturer: 'Redmi' })).name, 'XiaomiRecoveryProvider');
  assert.equal(selectProvider(id({ platform: Platform.APPLE, manufacturer: 'Apple' })).name, 'AppleRecoveryProvider');
});

test('unsupported android vendor falls back to generic provider', () => {
  const p = selectProvider(id({ platform: Platform.ANDROID, manufacturer: 'FooPhone' }));
  assert.equal(p.name, 'GenericAndroidRecoveryProvider');
});

test('UNKNOWN device selects NO provider (never forced into a path)', () => {
  assert.equal(selectProvider(emptyIdentity()), null);
});

test('every provider orders methods data-preservation-first and reset last', () => {
  for (const p of defaultProviders()) {
    const methods = p.methods(id({ platform: Platform.ANDROID, manufacturer: 'samsung' }));
    if (methods.length === 0) continue;
    const levels = methods.map((m) => m.level);
    // The recommended (first) method must be the least destructive available.
    assert.equal(levels[0], Math.min(...levels), `${p.name}: first method must be least destructive`);
    // Any factory-reset method must be flagged destructive & data-not-preserved.
    for (const m of methods) {
      if (m.level >= RecoveryLevel.FACTORY_RESET) {
        assert.equal(m.dataPreserved, false, `${p.name}: reset must not preserve data`);
        assert.equal(m.requiresFactoryReset, true);
      }
    }
  }
});

test('NO provider method describes a bypass (defense against policy drift)', () => {
  for (const p of defaultProviders()) {
    const methods = p.methods(id({ platform: Platform.ANDROID, manufacturer: 'samsung' }));
    for (const m of methods) {
      const c = classifyCommand(m.title, { intent: m.description });
      assert.notEqual(c.classification, Classification.BLOCKED, `${p.name}/${m.id} must not be a bypass`);
    }
  }
});

test('every method carries official references and explicit confirmation text', () => {
  for (const p of defaultProviders()) {
    const methods = p.methods(id({ platform: Platform.APPLE, manufacturer: 'Apple' }));
    for (const m of methods) {
      assert.ok(m.userConfirmation && m.userConfirmation.length > 0, `${m.id} needs confirmation text`);
    }
  }
});
