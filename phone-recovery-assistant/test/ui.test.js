import { test } from 'node:test';
import assert from 'node:assert/strict';
import { PassThrough } from 'node:stream';
import { renderDashboard, renderDryRun, renderOwnershipGate, progressBar } from '../src/ui/dashboard.js';
import { confirm, typeToConfirm } from '../src/ui/prompts.js';
import { emptyIdentity } from '../src/device/deviceIdentity.js';
import { buildDryRun } from '../src/recovery/dryRun.js';

test('progressBar clamps and fills', () => {
  assert.equal(progressBar(0, 10), '░'.repeat(10));
  assert.equal(progressBar(1, 10), '█'.repeat(10));
  assert.equal(progressBar(2, 10), '█'.repeat(10)); // clamped
});

test('dashboard renders without color and shows UNKNOWN state honestly', () => {
  const identity = emptyIdentity();
  const dry = buildDryRun(identity);
  const out = renderDashboard({ identity, dryRun: dry, color: false });
  assert.match(out, /PHONE RECOVERY ASSISTANT/);
  assert.match(out, /State\s+: UNKNOWN/);
});

test('ownership gate shows all five clauses unchecked by default', () => {
  const out = renderOwnershipGate({}, false);
  assert.equal((out.match(/\[ \]/g) || []).length, 5);
});

test('dry-run render includes protection-relevant guidance for android', () => {
  const identity = { ...emptyIdentity(), platform: 'android', manufacturer: 'samsung', model: 'SM-X', state: 'ADB_AVAILABLE', usbConnected: true };
  const out = renderDryRun(buildDryRun(identity), false);
  assert.match(out, /DRY RUN/);
  assert.match(out, /Provider/);
  assert.match(out, /Backups/);
});

test('confirm: empty input defaults to false (fail safe)', async () => {
  const input = new PassThrough();
  const output = new PassThrough();
  const p = confirm('proceed?', { input, output });
  input.write('\n');
  assert.equal(await p, false);
});

test('confirm: yes is accepted', async () => {
  const input = new PassThrough();
  const output = new PassThrough();
  const p = confirm('proceed?', { input, output });
  input.write('y\n');
  assert.equal(await p, true);
});

test('typeToConfirm requires the exact phrase (user cancellation on mismatch)', async () => {
  const input = new PassThrough();
  const output = new PassThrough();
  const p = typeToConfirm('ERASE MY DEVICE', { input, output });
  input.write('nope\n');
  assert.equal(await p, false);
});
