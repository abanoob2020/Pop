/**
 * @file Central registry of intents and command shapes that this tool
 *       will NEVER assist with. This is the enforcement core of the
 *       project's ethical boundary: recovery for legal owners via OFFICIAL
 *       methods only, and absolutely no circumvention of vendor security.
 *
 * If a command matches anything here it is classified BLOCKED and must not
 * be executed under any circumstance, regardless of user confirmation.
 */

/**
 * Human-readable list of protections this tool refuses to bypass. Used in
 * documentation, the ownership gate, and user-facing explanations.
 * @type {readonly string[]}
 */
export const PROTECTED_MECHANISMS = Object.freeze([
  'Secure Boot',
  'Activation Lock',
  'FRP (Factory Reset Protection)',
  'iCloud Lock',
  'Google Account Protection',
  'Samsung Knox',
  'Android Verified Boot (AVB / dm-verity)',
  'Lock screen (PIN / password / pattern / biometrics)',
  'Device encryption (FBE / FDE)',
  'Encryption keys / keystore',
  'Manufacturer security partitions',
]);

/**
 * Each entry describes a forbidden intent. `patterns` are matched
 * case-insensitively against a normalized command string and against the
 * declared human intent of an operation. `reason` is surfaced to the user
 * so the refusal is transparent, never silent.
 *
 * @typedef {Object} BlockedRule
 * @property {string} id
 * @property {string} reason
 * @property {RegExp[]} patterns
 */

/** @type {readonly BlockedRule[]} */
export const BLOCKED_RULES = Object.freeze([
  {
    id: 'bypass-lock',
    reason: 'Attempting to bypass the lock screen is not supported. Only official, owner-authenticated recovery is allowed.',
    patterns: [
      /\bbypass\b.*\block\b/i,
      /\block\b.*\bbypass\b/i,
      /\bunlock\b.*\bwithout\b.*(pin|password|pattern|passcode|credential)/i,
      /\bremove\b.*\block\s*screen\b/i,
      /\bpattern\b.*\b(unlock|bypass|remove)\b/i,
      /gesture\.key/i,
      /\blocksettings\b/i,
      /\bpassword\.key\b/i,
    ],
  },
  {
    id: 'disable-security',
    reason: 'Disabling device security mechanisms is not supported.',
    patterns: [
      /\bdisable\b.*\b(security|knox|verified\s*boot|dm-verity|avb|secure\s*boot)\b/i,
      /\bdefeat\b.*\b(security|protection)\b/i,
      /\bturn\s*off\b.*\b(secure\s*boot|verified\s*boot)\b/i,
    ],
  },
  {
    id: 'remove-frp',
    reason: 'Removing Factory Reset Protection (FRP) / Google Account Protection is not supported.',
    patterns: [
      /\bfrp\b.*\b(bypass|remove|unlock|disable|reset)\b/i,
      /\b(bypass|remove|unlock|disable)\b.*\bfrp\b/i,
      /factory\s*reset\s*protection.*\b(bypass|remove)\b/i,
      /google\s*account.*\b(bypass|remove\s*lock)\b/i,
    ],
  },
  {
    id: 'remove-activation-lock',
    reason: 'Removing Activation Lock / iCloud Lock is not supported.',
    patterns: [
      /\bactivation\s*lock\b.*\b(bypass|remove|unlock|disable)\b/i,
      /\b(bypass|remove|unlock)\b.*\bactivation\s*lock\b/i,
      /\bicloud\b.*\b(bypass|unlock|remove\s*lock|removal)\b/i,
      /\bimei\b.*\bunlock\b/i,
    ],
  },
  {
    id: 'crack-credentials',
    reason: 'Cracking, guessing, or brute forcing a PIN/password is not supported.',
    patterns: [
      /\bbrute[-\s]?force\b/i,
      /\bcrack\b.*\b(pin|password|passcode|credential|hash)\b/i,
      /\bguess\b.*\b(pin|password|passcode)\b/i,
      /\bdictionary\s*attack\b/i,
      /hashcat|john\s*the\s*ripper|\bjohn\b.*hash/i,
      /\btry\s*all\b.*\b(pin|passcode|combination)/i,
    ],
  },
  {
    id: 'extract-keys',
    reason: 'Extracting encryption keys or protected key material is not supported.',
    patterns: [
      /\bextract\b.*\b(encryption\s*key|keystore|keymaster|key\s*material)\b/i,
      /\bdump\b.*\b(key|keystore|trustzone|tee)\b/i,
      /\bkeychain\b.*\b(dump|extract)\b/i,
      /\bdecrypt\b.*\bwithout\b.*(key|password|credential)/i,
    ],
  },
  {
    id: 'modify-security-partitions',
    reason: 'Modifying bootloader or security partitions to defeat protection is not supported.',
    patterns: [
      /\bflash\b.*\b(frp|persist|security)\b.*\b(bypass|zero|erase)\b/i,
      /\berase\b.*\b(frp|persist)\b/i,
      /\bwrite\b.*\bsecurity\s*partition\b/i,
      /\bunlock\s*bootloader\b.*\b(bypass|frp|activation)\b/i,
      /\btamper\b.*\b(boot|security)\b/i,
    ],
  },
  {
    id: 'exploit',
    reason: 'Using an exploit or vulnerability to defeat authentication is not supported.',
    patterns: [
      /\bexploit\b.*\b(auth|lock|security|bypass|vuln)/i,
      /\b(cve-\d{4}-\d+)\b.*\b(bypass|unlock|root)\b/i,
      /\bprivilege\s*escalation\b.*\b(bypass|unlock)\b/i,
      /\bpayload\b.*\b(bypass|unlock|root\s*exploit)\b/i,
    ],
  },
]);

/**
 * Normalizes a command/intent string for matching: collapses whitespace,
 * lowercases, and trims. Kept deliberately simple and dependency-free.
 * @param {string} input
 * @returns {string}
 */
export function normalize(input) {
  return String(input ?? '').replace(/\s+/g, ' ').trim().toLowerCase();
}

/**
 * Returns the first BlockedRule that matches the given text, or null.
 * @param {string} text A command string and/or its declared intent.
 * @returns {BlockedRule | null}
 */
export function matchBlockedRule(text) {
  const normalized = normalize(text);
  for (const rule of BLOCKED_RULES) {
    if (rule.patterns.some((re) => re.test(normalized))) {
      return rule;
    }
  }
  return null;
}
