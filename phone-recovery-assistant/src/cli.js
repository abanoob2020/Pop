#!/usr/bin/env node
/**
 * @file CLI entry point. Wires detection → dashboard → dry-run → gated
 *       recovery. Uses the real CLI transport if device tooling is present,
 *       otherwise degrades to the null transport (honest "no device").
 *
 * Commands:
 *   (default) | dashboard   Detect + show dashboard + dry-run.
 *   dry-run                 Detect + print the full dry-run report.
 *   options                 List all official recovery methods for the device.
 *   recover                 Interactive, gated official-recovery flow.
 *
 * This CLI performs NO destructive action automatically. Destructive methods
 * are gated behind ownership authorization + explicit double confirmation, and
 * even then the physical step is left to the user following official docs.
 */

import { RecoveryEngine } from './recovery/recoveryEngine.js';
import { createCliTransport, createNullTransport } from './device/transport.js';
import { selectProvider } from './providers/registry.js';
import { AuditLog } from './logging/auditLog.js';
import {
  renderDashboard,
  renderDryRun,
  renderOwnershipGate,
  renderProtectionNotice,
} from './ui/dashboard.js';
import { OWNERSHIP_CLAUSES } from './safety/ownershipGate.js';
import { confirm, typeToConfirm } from './ui/prompts.js';
import { isDestructiveLevel } from './recovery/recoveryLevels.js';

const color = Boolean(process.stdout.isTTY);

/**
 * Choose a transport. `--no-device` forces the null transport (useful for demo
 * and CI). Otherwise attempt the real CLI transport, which self-reports tools
 * as unavailable when missing.
 * @returns {import('./device/transport.js').Transport}
 */
function pickTransport() {
  if (process.argv.includes('--no-device')) return createNullTransport();
  return createCliTransport();
}

async function main() {
  const cmd = process.argv[2] && !process.argv[2].startsWith('--') ? process.argv[2] : 'dashboard';
  const audit = new AuditLog({ filePath: process.env.PRA_AUDIT_LOG || null });
  const engine = new RecoveryEngine({ transport: pickTransport(), audit });

  const identity = await engine.detect();
  const dryRun = engine.dryRun(identity);

  switch (cmd) {
    case 'dashboard':
      console.log(renderDashboard({ identity, dryRun, progress: identity.usbConnected ? 0.4 : 0.05, color }));
      console.log('\n' + renderProtectionNotice(color));
      if (!identity.usbConnected) {
        console.log(
          '\nNo connected device detected. Connect the phone (with official drivers/tools) or run `dry-run` to preview.',
        );
      }
      break;

    case 'dry-run':
      console.log(renderDryRun(dryRun, color));
      break;

    case 'options':
      printOptions(identity);
      break;

    case 'recover':
      await recoverFlow(engine, identity, dryRun);
      break;

    default:
      console.log(`Unknown command: ${cmd}`);
      console.log('Usage: phone-recovery-assistant [dashboard|dry-run|options|recover] [--no-device]');
      process.exitCode = 2;
  }
}

/**
 * @param {import('./device/deviceIdentity.js').DeviceIdentity} identity
 */
function printOptions(identity) {
  const provider = selectProvider(identity);
  if (!provider) {
    console.log('No supported official recovery provider for this device.');
    console.log('Provide: manufacturer, model number, OS, whether you know the linked account, and the phone\'s current screen.');
    return;
  }
  console.log(`Provider: ${provider.name}\n`);
  for (const m of provider.methods(identity)) {
    console.log(`• [L${m.level}] ${m.title}`);
    console.log(`    ${m.description}`);
    console.log(`    Data preserved: ${m.dataPreserved ? 'yes' : 'no'} | Risk: ${m.riskLevel}`);
    if (m.accountRequirements.length) console.log(`    Accounts: ${m.accountRequirements.join('; ')}`);
    if (m.officialReferences.length) console.log(`    Official: ${m.officialReferences.join(' , ')}`);
    console.log('');
  }
}

/**
 * Interactive, safety-gated recovery flow.
 * @param {RecoveryEngine} engine
 * @param {import('./device/deviceIdentity.js').DeviceIdentity} identity
 * @param {import('./recovery/dryRun.js').DryRunReport} dryRun
 */
async function recoverFlow(engine, identity, dryRun) {
  console.log(renderProtectionNotice(color));
  console.log('');

  if (identity.state === 'UNKNOWN' || !identity.usbConnected) {
    console.log(
      'No clearly identified, connected device. Stopping safely.\n' +
        'Please provide manufacturer, model number, OS, whether you know the linked Google/Apple account, whether a backup exists, and the current screen of the phone.',
    );
    return;
  }

  const provider = selectProvider(identity);
  if (!provider || !dryRun.recommendedMethodId) {
    console.log('No safe, supported official recovery path is available in the current state.');
    return;
  }

  const method = provider.methods(identity).find((m) => m.id === dryRun.recommendedMethodId);
  if (!method) {
    console.log('No applicable method. Stopping.');
    return;
  }

  // Ownership authorization.
  console.log(renderOwnershipGate({}, color));
  /** @type {Record<string, boolean>} */
  const affirmations = {};
  for (const clause of OWNERSHIP_CLAUSES) {
    // eslint-disable-next-line no-await-in-loop
    affirmations[clause.key] = await confirm(clause.text);
    if (!affirmations[clause.key]) {
      console.log('Authorization not granted. Stopping safely — no action taken.');
      return;
    }
  }

  const destructive = isDestructiveLevel(method.level) || method.requiresFactoryReset;
  let explicitDestructiveConfirmation = false;
  if (destructive) {
    console.log(
      '\n⚠️  WARNING: This procedure may DELETE ALL DATA on the device.\n' +
        (method.accountRequirements.length
          ? `   Note: after this, you will still need: ${method.accountRequirements.join('; ')}.\n`
          : ''),
    );
    const first = await confirm('Do you understand this may erase all data?');
    if (!first) {
      console.log('Not confirmed. Stopping safely.');
      return;
    }
    explicitDestructiveConfirmation = await typeToConfirm('ERASE MY DEVICE');
    if (!explicitDestructiveConfirmation) {
      console.log('Phrase did not match. Stopping safely.');
      return;
    }
  }

  const result = await engine.execute({
    identity,
    methodId: method.id,
    affirmations,
    explicitDestructiveConfirmation,
  });

  console.log(`\n[${result.status.toUpperCase()}] ${result.message}`);
  if (result.status === 'ok') {
    console.log('\nFollow the official references above. This tool will not perform the destructive step for you.');
  }
}

main().catch((err) => {
  // Fail safe: never leave the device mid-operation on an unexpected error.
  console.error('\nUnexpected error — stopping safely. No further action will be attempted.');
  console.error(String(err && err.message ? err.message : err));
  process.exitCode = 1;
});
