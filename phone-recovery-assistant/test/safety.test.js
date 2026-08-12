import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  classifyCommand,
  assertExecutable,
  Classification,
} from '../src/safety/commandSafetyValidator.js';
import { matchBlockedRule } from '../src/safety/blockedPatterns.js';
import {
  evaluateAuthorization,
  authorizeDestructive,
  OWNERSHIP_CLAUSES,
} from '../src/safety/ownershipGate.js';

test('command classification: read-only is SAFE', () => {
  assert.equal(classifyCommand('adb devices -l').classification, Classification.SAFE);
  assert.equal(classifyCommand('fastboot getvar product').classification, Classification.SAFE);
});

test('command classification: reboot is WARNING', () => {
  assert.equal(classifyCommand('adb reboot').classification, Classification.WARNING);
});

test('command classification: wipe/factory reset is DESTRUCTIVE with double confirm', () => {
  const r = classifyCommand('fastboot -w');
  assert.equal(r.classification, Classification.DESTRUCTIVE);
  assert.equal(r.requiresDoubleConfirmation, true);
  const r2 = classifyCommand('recovery wipe data');
  assert.equal(r2.classification, Classification.DESTRUCTIVE);
});

test('command classification: unknown command fails closed to WARNING', () => {
  const r = classifyCommand('some-unknown-binary --frobnicate');
  assert.equal(r.classification, Classification.WARNING);
  assert.equal(r.requiresConfirmation, true);
});

test('BLOCKED: bypass / FRP / activation lock / brute force / exploit intents', () => {
  const blocked = [
    'bypass lock screen',
    'remove FRP lock',
    'frp bypass',
    'bypass activation lock',
    'brute force the PIN',
    'crack password hash',
    'extract encryption key from keystore',
    'disable knox security',
    'use exploit to bypass auth',
    'erase frp partition',
  ];
  for (const cmd of blocked) {
    const r = classifyCommand(cmd);
    assert.equal(r.classification, Classification.BLOCKED, `expected BLOCKED for: ${cmd}`);
    assert.equal(r.allowed, false);
  }
});

test('BLOCKED intent cannot be smuggled via benign command + malicious intent', () => {
  const r = classifyCommand('adb devices', { intent: 'to bypass the lock screen' });
  assert.equal(r.classification, Classification.BLOCKED);
});

test('assertExecutable throws on BLOCKED and cannot be forced', () => {
  assert.throws(() => assertExecutable('bypass activation lock'), /BLOCKED/);
});

test('matchBlockedRule returns a transparent reason', () => {
  const rule = matchBlockedRule('remove activation lock');
  assert.ok(rule);
  assert.match(rule.reason, /Activation Lock/i);
});

test('ownership gate requires every clause', () => {
  assert.equal(evaluateAuthorization({}).authorized, false);
  const all = Object.fromEntries(OWNERSHIP_CLAUSES.map((c) => [c.key, true]));
  assert.equal(evaluateAuthorization(all).authorized, true);
  const partial = { ...all, official_only: false };
  const res = evaluateAuthorization(partial);
  assert.equal(res.authorized, false);
  assert.deepEqual(res.missing, ['official_only']);
});

test('destructive authorization needs full auth AND explicit confirmation', () => {
  const all = Object.fromEntries(OWNERSHIP_CLAUSES.map((c) => [c.key, true]));
  assert.equal(authorizeDestructive({ affirmations: all, explicitDestructiveConfirmation: false }).allowed, false);
  assert.equal(authorizeDestructive({ affirmations: {}, explicitDestructiveConfirmation: true }).allowed, false);
  assert.equal(authorizeDestructive({ affirmations: all, explicitDestructiveConfirmation: true }).allowed, true);
});
