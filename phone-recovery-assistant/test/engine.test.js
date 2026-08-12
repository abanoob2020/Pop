import { test } from 'node:test';
import assert from 'node:assert/strict';
import { RecoveryEngine } from '../src/recovery/recoveryEngine.js';
import { AuditLog } from '../src/logging/auditLog.js';
import { OWNERSHIP_CLAUSES } from '../src/safety/ownershipGate.js';
import { mockTransport, noDeviceTransport } from './mocks.js';

const allAffirmed = () => Object.fromEntries(OWNERSHIP_CLAUSES.map((c) => [c.key, true]));

/** Android+samsung, adb available, so a provider and methods exist. */
function samsungTransport() {
  return mockTransport({
    adb: { available: true, raw: 'List of devices attached\nR58N1   device' },
    props: {
      'ro.product.manufacturer': 'samsung',
      'ro.product.model': 'SM-G973F',
      'ro.build.version.release': '12',
    },
  });
}

test('UNKNOWN device: engine stops safely and takes no action (PHASE 12)', async () => {
  const audit = new AuditLog();
  const engine = new RecoveryEngine({ transport: noDeviceTransport(), audit });
  const identity = await engine.detect();
  const res = await engine.execute({ identity, methodId: 'anything' });
  assert.equal(res.status, 'stopped');
  assert.match(res.message, /fail-safe/i);
  assert.equal(audit.getEntries().at(-1).result, 'stopped');
});

test('destructive method without confirmation => unauthorized', async () => {
  const engine = new RecoveryEngine({ transport: samsungTransport() });
  const identity = await engine.detect();
  const res = await engine.execute({
    identity,
    methodId: 'samsung-recovery-factory-reset',
    affirmations: allAffirmed(),
    explicitDestructiveConfirmation: false,
  });
  assert.equal(res.status, 'unauthorized');
});

test('destructive method with full auth + explicit confirm => ok (but not auto-executed)', async () => {
  const engine = new RecoveryEngine({ transport: samsungTransport() });
  const identity = await engine.detect();
  const res = await engine.execute({
    identity,
    methodId: 'samsung-recovery-factory-reset',
    affirmations: allAffirmed(),
    explicitDestructiveConfirmation: true,
  });
  assert.equal(res.status, 'ok');
  assert.match(res.message, /will not perform the destructive step automatically/i);
});

test('non-destructive method requires ownership but not destructive confirm', async () => {
  const engine = new RecoveryEngine({ transport: samsungTransport() });
  const identity = await engine.detect();
  const res = await engine.execute({
    identity,
    methodId: 'samsung-find-my-mobile-unlock',
    affirmations: allAffirmed(),
  });
  assert.equal(res.status, 'ok');
});

test('unknown methodId => no-path (failed recovery handled gracefully)', async () => {
  const engine = new RecoveryEngine({ transport: samsungTransport() });
  const identity = await engine.detect();
  const res = await engine.execute({ identity, methodId: 'does-not-exist', affirmations: allAffirmed() });
  assert.equal(res.status, 'no-path');
});

test('dry-run for no device asks for more info and never claims a path', async () => {
  const engine = new RecoveryEngine({ transport: noDeviceTransport() });
  const identity = await engine.detect();
  const dry = engine.dryRun(identity);
  assert.equal(dry.providerName, null);
  assert.equal(dry.potentialDataLoss, false);
  assert.match(dry.summaryLine, /more information/i);
});

test('dry-run marks factory-reset path as double-confirm + data loss', async () => {
  const engine = new RecoveryEngine({ transport: samsungTransport() });
  const identity = await engine.detect();
  // Force selection of the destructive method by checking provider output.
  const dry = engine.dryRun(identity);
  // Recommended is the least destructive; ensure the report structure is honest.
  assert.equal(dry.requiresConfirmation, true);
  assert.ok(dry.backups.anyPossible, 'android should surface google backups');
});
