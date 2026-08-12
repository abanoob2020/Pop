/**
 * @file Transport abstraction over device tooling (adb / fastboot /
 *       libimobiledevice). This layer ONLY exposes read-only probes. It never
 *       runs, and offers no method to run, any state-changing or destructive
 *       command — those go through the recovery engine + CommandSafetyValidator.
 *
 * The default transport reports "unavailable" for every tool that is not
 * installed, so on a machine with no device tooling the whole system degrades
 * gracefully to UNKNOWN / no-device instead of pretending.
 */

/**
 * @typedef {Object} ProbeResult
 * @property {boolean} available   Whether the underlying tool exists.
 * @property {string=} raw         Raw tool output (already length-bounded).
 * @property {string=} error
 */

/**
 * @typedef {Object} Transport
 * @property {() => Promise<ProbeResult>} adbDevices
 * @property {() => Promise<ProbeResult>} fastbootDevices
 * @property {() => Promise<ProbeResult>} appleDevices
 * @property {(prop: string) => Promise<ProbeResult>} adbGetProp
 */

/**
 * Create a transport that treats all device tools as unavailable. This is the
 * correct behavior in any environment without a connected phone, and the safe
 * default for tests.
 * @returns {Transport}
 */
export function createNullTransport() {
  const unavailable = async () => ({ available: false, error: 'no device tooling available' });
  return {
    adbDevices: unavailable,
    fastbootDevices: unavailable,
    appleDevices: unavailable,
    adbGetProp: unavailable,
  };
}

/**
 * Build a transport backed by real CLI tools. Commands are strictly read-only
 * and every one is validated by CommandSafetyValidator before spawning.
 *
 * NOTE: This does not auto-install anything. If a tool is missing the probe
 * simply reports it as unavailable.
 *
 * @param {Object} [deps]
 * @param {(cmd: string, args: string[]) => Promise<{stdout: string}>} [deps.run]
 *        Injectable spawner (defaults to node:child_process execFile).
 * @returns {Transport}
 */
export function createCliTransport(deps = {}) {
  const run = deps.run ?? defaultRun;

  /**
   * @param {string} cmd
   * @param {string[]} args
   * @param {string} intent
   * @returns {Promise<ProbeResult>}
   */
  const probe = async (cmd, args, intent) => {
    // Import lazily to keep this module usable in pure-logic test contexts.
    const { assertExecutable } = await import('../safety/commandSafetyValidator.js');
    const full = `${cmd} ${args.join(' ')}`;
    try {
      assertExecutable(full, { intent }); // throws on BLOCKED
    } catch (err) {
      return { available: false, error: /** @type {Error} */ (err).message };
    }
    try {
      const { stdout } = await run(cmd, args);
      return { available: true, raw: String(stdout).slice(0, 4096) };
    } catch (err) {
      const e = /** @type {NodeJS.ErrnoException} */ (err);
      if (e && e.code === 'ENOENT') {
        return { available: false, error: `${cmd} not installed` };
      }
      return { available: false, error: e?.message ?? 'probe failed' };
    }
  };

  return {
    adbDevices: () => probe('adb', ['devices', '-l'], 'list connected android devices'),
    fastbootDevices: () => probe('fastboot', ['devices'], 'list connected fastboot devices'),
    appleDevices: () => probe('idevice_id', ['-l'], 'list connected apple devices'),
    adbGetProp: (prop) =>
      probe('adb', ['shell', 'getprop', String(prop)], 'read a read-only device property'),
  };
}

/**
 * Default spawner using execFile (no shell — avoids injection).
 * @param {string} cmd
 * @param {string[]} args
 * @returns {Promise<{stdout: string}>}
 */
async function defaultRun(cmd, args) {
  const { execFile } = await import('node:child_process');
  const { promisify } = await import('node:util');
  const execFileAsync = promisify(execFile);
  return execFileAsync(cmd, args, { timeout: 8000, windowsHide: true });
}
