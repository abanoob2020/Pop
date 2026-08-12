/**
 * @file Minimal, dependency-free interactive prompts over readline. Isolated
 *       here so the rest of the code stays pure/testable. This module NEVER
 *       asks for a PIN or password (per policy) and NEVER echoes secrets.
 */

import { createInterface } from 'node:readline';

/**
 * Ask a yes/no question. Defaults to "no" on empty input (fail safe).
 * @param {string} question
 * @param {Object} [io]
 * @param {NodeJS.ReadableStream} [io.input]
 * @param {NodeJS.WritableStream} [io.output]
 * @returns {Promise<boolean>}
 */
export async function confirm(question, io = {}) {
  const rl = createInterface({ input: io.input ?? process.stdin, output: io.output ?? process.stdout });
  try {
    const answer = await new Promise((resolve) => rl.question(`${question} [y/N] `, resolve));
    return /^y(es)?$/i.test(String(answer).trim());
  } finally {
    rl.close();
  }
}

/**
 * Require the user to type an exact phrase (used for destructive double-confirm).
 * @param {string} phrase
 * @param {Object} [io]
 * @returns {Promise<boolean>}
 */
export async function typeToConfirm(phrase, io = {}) {
  const rl = createInterface({ input: io.input ?? process.stdin, output: io.output ?? process.stdout });
  try {
    const answer = await new Promise((resolve) =>
      rl.question(`Type exactly "${phrase}" to proceed: `, resolve),
    );
    return String(answer).trim() === phrase;
  } finally {
    rl.close();
  }
}
