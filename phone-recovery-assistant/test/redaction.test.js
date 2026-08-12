import { test } from 'node:test';
import assert from 'node:assert/strict';
import { redact, redactString, redactIdentifier, REDACTED } from '../src/logging/redaction.js';
import { AuditLog } from '../src/logging/auditLog.js';

test('sensitive keys are redacted at any depth', () => {
  const input = {
    operation: 'unlock',
    pin: '1234',
    nested: { password: 'hunter2', appleIdPassword: 'x', token: 'abc' },
    list: [{ secret: 'k' }],
  };
  const out = redact(input);
  assert.equal(out.pin, REDACTED);
  assert.equal(out.nested.password, REDACTED);
  assert.equal(out.nested.appleIdPassword, REDACTED);
  assert.equal(out.nested.token, REDACTED);
  assert.equal(out.list[0].secret, REDACTED);
  assert.equal(out.operation, 'unlock'); // non-sensitive preserved
});

test('IMEI in free text is masked to last 4', () => {
  const masked = redactString('device imei 356938035643809 connected');
  assert.match(masked, /IMEI:\*\*\*-3809/);
  assert.doesNotMatch(masked, /356938035643809/);
});

test('redactIdentifier keeps only a short suffix', () => {
  assert.equal(redactIdentifier('R58N1234ABCD'), '***ABCD');
  assert.equal(redactIdentifier('ab'), REDACTED);
});

test('AuditLog never stores secrets even if passed accidentally', async () => {
  const log = new AuditLog({ now: () => new Date('2020-01-01T00:00:00Z') });
  await log.record({
    deviceModel: 'SM-G973F',
    deviceState: 'ADB_AVAILABLE',
    operation: 'attempt',
    result: 'failure',
    // Intentionally leak secrets to prove redaction:
    details: { pin: '0000', password: 'p', token: 't', note: 'ok' },
  });
  const [entry] = log.getEntries();
  assert.equal(entry.details.pin, REDACTED);
  assert.equal(entry.details.password, REDACTED);
  assert.equal(entry.details.token, REDACTED);
  assert.equal(entry.details.note, 'ok');
  assert.equal(entry.timestamp, '2020-01-01T00:00:00.000Z');
});
