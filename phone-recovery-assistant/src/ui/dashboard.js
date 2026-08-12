/**
 * @file Dashboard rendering (PHASE 9). Pure string builders — no I/O — so the
 *       UI is fully testable. A dark/modern ANSI theme is applied when the
 *       output stream is a TTY that supports color.
 */

import { OWNERSHIP_CLAUSES } from '../safety/ownershipGate.js';
import { PROTECTED_MECHANISMS } from '../safety/blockedPatterns.js';

const C = {
  reset: '\x1b[0m',
  dim: '\x1b[2m',
  bold: '\x1b[1m',
  cyan: '\x1b[36m',
  green: '\x1b[32m',
  yellow: '\x1b[33m',
  red: '\x1b[31m',
  gray: '\x1b[90m',
};

/**
 * @param {boolean} color
 * @param {keyof typeof C} name
 * @param {string} text
 * @returns {string}
 */
function paint(color, name, text) {
  return color ? `${C[name]}${text}${C.reset}` : text;
}

/**
 * @param {number} pct  0..1
 * @param {number} [width]
 * @returns {string}
 */
export function progressBar(pct, width = 20) {
  const clamped = Math.max(0, Math.min(1, pct));
  const filled = Math.round(clamped * width);
  return '█'.repeat(filled) + '░'.repeat(width - filled);
}

/**
 * Render the main dashboard.
 * @param {Object} params
 * @param {import('../device/deviceIdentity.js').DeviceIdentity} params.identity
 * @param {import('../recovery/dryRun.js').DryRunReport} params.dryRun
 * @param {number} [params.progress] 0..1
 * @param {boolean} [params.color]
 * @returns {string}
 */
export function renderDashboard({ identity, dryRun, progress = 0, color = false }) {
  const line = paint(color, 'gray', '─'.repeat(46));
  const title = paint(color, 'cyan', paint(color, 'bold', 'PHONE RECOVERY ASSISTANT'));
  const rec = dryRun.recommendedTitle ?? 'Awaiting device details';

  const riskColor = /** @type {keyof typeof C} */ (
    dryRun.riskLevel === 'High' ? 'red' : dryRun.riskLevel === 'Medium' ? 'yellow' : 'green'
  );

  return [
    `┌${'─'.repeat(46)}┐`,
    `│ ${title}`,
    `├${'─'.repeat(46)}┤`,
    `│ Platform     : ${identity.platform}`,
    `│ Manufacturer : ${identity.manufacturer ?? '—'}`,
    `│ Model        : ${identity.model ?? '—'}`,
    `│ Model No.    : ${identity.modelNumber ?? '—'}`,
    `│ OS           : ${identity.osVersion ?? '—'}`,
    `│ State        : ${paint(color, 'bold', identity.state)}`,
    `│ USB          : ${identity.usbConnected ? 'connected' : 'not connected'}`,
    `│`,
    `│ Recovery Status`,
    `│ ${paint(color, 'cyan', progressBar(progress))}`,
    `│`,
    `│ Recommended  : ${rec}`,
    `│ Risk         : ${paint(color, riskColor, dryRun.riskLevel)}`,
    `│ Data loss    : ${dryRun.potentialDataLoss ? paint(color, 'yellow', 'possible') : 'not expected'}`,
    `│`,
    `│ [ Start Safe Recovery ]  [ Dry Run ]  [ View Options ]`,
    `└${'─'.repeat(46)}┘`,
    line,
  ].join('\n');
}

/**
 * Render the ownership authorization screen (PHASE 1).
 * @param {Record<string, boolean>} [affirmations]
 * @param {boolean} [color]
 * @returns {string}
 */
export function renderOwnershipGate(affirmations = {}, color = false) {
  const header = paint(color, 'bold', 'Device Ownership & Recovery Authorization');
  const clauses = OWNERSHIP_CLAUSES.map((c) => {
    const mark = affirmations[c.key] ? paint(color, 'green', '[x]') : '[ ]';
    return `  ${mark} ${c.text}`;
  });
  return [header, '', ...clauses, ''].join('\n');
}

/**
 * Render the "we will not bypass" notice.
 * @param {boolean} [color]
 * @returns {string}
 */
export function renderProtectionNotice(color = false) {
  const header = paint(color, 'yellow', 'This tool will NOT bypass any of the following:');
  const items = PROTECTED_MECHANISMS.map((m) => `  • ${m}`);
  return [header, ...items].join('\n');
}

/**
 * Render a full dry-run report (PHASE 6).
 * @param {import('../recovery/dryRun.js').DryRunReport} r
 * @param {boolean} [color]
 * @returns {string}
 */
export function renderDryRun(r, color = false) {
  const h = (t) => paint(color, 'cyan', paint(color, 'bold', t));
  const lines = [
    h('DRY RUN — no action will be performed on the device'),
    '',
    `Device        : ${r.device.manufacturer ?? '—'} ${r.device.model ?? ''}`.trim(),
    `State         : ${r.detectedState}`,
    `Provider      : ${r.providerName ?? '—'}`,
    `Recovery path : ${r.recoveryPath}`,
    `Required tools: ${r.requiredTools.length ? r.requiredTools.join(', ') : '—'}`,
    `Data loss     : ${r.potentialDataLoss ? 'POSSIBLE' : 'not expected'}`,
    `Accounts      : ${r.accountRequirements.length ? r.accountRequirements.join('; ') : '—'}`,
    `Risk level    : ${r.riskLevel}`,
    `Confirmation  : ${r.requiresDoubleConfirmation ? 'DOUBLE confirmation required' : r.requiresConfirmation ? 'required' : 'none'}`,
    '',
    'Estimated steps:',
    ...r.estimatedSteps.map((s, i) => `  ${i + 1}. ${s}`),
    '',
    'Backups:',
    r.backups.anyPossible
      ? r.backups.sources.map((s) => `  • ${s.name} — ${s.where}`).join('\n')
      : '  • None known for this platform.',
    `  → ${r.backups.recommendedPath}`,
    '',
    paint(color, 'gray', r.summaryLine),
  ];
  return lines.join('\n');
}
