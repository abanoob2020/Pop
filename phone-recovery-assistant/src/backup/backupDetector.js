/**
 * @file Backup detection (PHASE 3). Enumerates OFFICIAL, user-owned backup
 *       locations to check BEFORE any destructive step. It never accesses,
 *       downloads, or decrypts protected data — it only tells the user where a
 *       backup would live and what to check, based on platform/vendor.
 */

import { Platform, normalizeVendor } from '../device/deviceIdentity.js';

/**
 * @typedef {Object} BackupSource
 * @property {string} name
 * @property {string} where          Where the user checks it (official URL/app).
 * @property {string} note
 */

/**
 * @typedef {Object} BackupReport
 * @property {boolean} anyPossible          Whether any official backup source may exist.
 * @property {BackupSource[]} sources
 * @property {string} recommendedPath       Human guidance for recovery ordering.
 */

/**
 * Suggest official backup sources for a device. This is advisory only; the tool
 * cannot confirm a backup exists without the user signing in to their own
 * account through the official service.
 * @param {import('../device/deviceIdentity.js').DeviceIdentity} identity
 * @returns {BackupReport}
 */
export function detectBackups(identity) {
  /** @type {BackupSource[]} */
  const sources = [];

  if (identity.platform === Platform.APPLE) {
    sources.push(
      { name: 'iCloud Backup', where: 'https://www.icloud.com / device Settings', note: 'Check Settings → [name] → iCloud → iCloud Backup on any of your Apple devices.' },
      { name: 'Finder / iTunes Backup', where: 'Your Mac (Finder) or PC (iTunes)', note: 'A previous local backup may exist on a computer you synced with.' },
    );
  } else if (identity.platform === Platform.ANDROID) {
    sources.push(
      { name: 'Google One / Android Backup', where: 'https://one.google.com/storage', note: 'Device backups (apps, settings, SMS) tied to your Google account.' },
      { name: 'Google Photos', where: 'https://photos.google.com', note: 'Photos/videos if backup & sync was on.' },
      { name: 'Google Drive', where: 'https://drive.google.com', note: 'Documents and app data backups.' },
    );
    const vendor = normalizeVendor(identity.manufacturer);
    if (vendor === 'samsung') {
      sources.push({ name: 'Samsung Cloud', where: 'https://support.samsungcloud.com', note: 'Samsung account cloud backup, if enabled.' });
    } else if (vendor === 'xiaomi' || vendor === 'redmi' || vendor === 'poco') {
      sources.push({ name: 'Mi Cloud', where: 'https://i.mi.com', note: 'Mi account cloud backup, if enabled.' });
    } else if (vendor === 'huawei' || vendor === 'honor') {
      sources.push({ name: 'Huawei/Honor Cloud', where: 'https://cloud.huawei.com', note: 'Manufacturer cloud backup, if enabled.' });
    } else if (vendor) {
      sources.push({ name: `${cap(vendor)} Cloud`, where: 'Manufacturer official cloud', note: 'Manufacturer cloud backup, if enabled.' });
    }
  }

  const anyPossible = sources.length > 0;
  const recommendedPath = anyPossible
    ? 'Verify these official backups (sign in with YOUR account) before any factory reset, so data can be restored afterward.'
    : 'No standard backup location is known for this platform. Do not perform a destructive step until you have checked for any backup you may own.';

  return { anyPossible, sources, recommendedPath };
}

/**
 * @param {string} s
 * @returns {string}
 */
function cap(s) {
  return s.charAt(0).toUpperCase() + s.slice(1);
}
