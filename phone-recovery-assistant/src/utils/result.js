/**
 * @file Tiny Result helpers for explicit success/failure without exceptions.
 */

/**
 * @template T
 * @typedef {{ ok: true, value: T } | { ok: false, error: string }} Result
 */

/**
 * @template T
 * @param {T} value
 * @returns {Result<T>}
 */
export function ok(value) {
  return { ok: true, value };
}

/**
 * @param {string} error
 * @returns {Result<never>}
 */
export function err(error) {
  return { ok: false, error };
}
