/**
 * @file Local, append-only audit log (PHASE 8). Records what happened without
 *       ever persisting secrets. All entries are passed through redaction.
 *
 * The log is intentionally local-only: this tool never transmits logs to any
 * external service.
 */

import { appendFile, mkdir } from 'node:fs/promises';
import { dirname } from 'node:path';
import { redact } from './redaction.js';

/**
 * @typedef {Object} AuditEntry
 * @property {string} timestamp        ISO-8601 UTC.
 * @property {string} deviceModel
 * @property {string} deviceState
 * @property {string} operation
 * @property {string} result           'success' | 'failure' | 'blocked' | 'cancelled' | ...
 * @property {string=} error
 * @property {boolean=} userConfirmation
 * @property {Record<string, unknown>=} details
 */

export class AuditLog {
  /**
   * @param {Object} [opts]
   * @param {string} [opts.filePath] Where to persist. If omitted, in-memory only.
   * @param {() => Date} [opts.now]  Clock injection for tests.
   */
  constructor(opts = {}) {
    this.filePath = opts.filePath ?? null;
    this._now = opts.now ?? (() => new Date());
    /** @type {AuditEntry[]} */
    this.entries = [];
  }

  /**
   * Record an event. The entry is redacted before it is stored or written.
   * @param {Omit<AuditEntry, 'timestamp'> & { timestamp?: string }} entry
   * @returns {Promise<AuditEntry>}
   */
  async record(entry) {
    const raw = {
      timestamp: entry.timestamp ?? this._now().toISOString(),
      deviceModel: entry.deviceModel ?? 'unknown',
      deviceState: entry.deviceState ?? 'UNKNOWN',
      operation: entry.operation ?? 'unspecified',
      result: entry.result ?? 'unknown',
      error: entry.error,
      userConfirmation: entry.userConfirmation,
      details: entry.details,
    };

    const safe = /** @type {AuditEntry} */ (redact(raw));
    this.entries.push(safe);

    if (this.filePath) {
      await mkdir(dirname(this.filePath), { recursive: true });
      await appendFile(this.filePath, JSON.stringify(safe) + '\n', 'utf8');
    }
    return safe;
  }

  /**
   * @returns {readonly AuditEntry[]}
   */
  getEntries() {
    return this.entries.slice();
  }
}
