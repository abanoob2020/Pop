# Phone Recovery Assistant

A safety-first assistant that guides the **legal owner** of a locked phone
through **official, non-destructive-first** recovery paths.

It is built around one hard rule: **it never bypasses vendor security.** It will
not defeat Activation Lock, FRP / Google Account Protection, Secure Boot, Samsung
Knox, Android Verified Boot, the lock screen, or device encryption. It does not
brute-force PINs, use exploits, or run bypass tools. If a requested action's
purpose is to circumvent a protection, the tool classifies it as `BLOCKED` and
refuses.

> If a device cannot be officially recovered without erasing data, the tool says
> so plainly and shows only the official options:
> *"There is no safe, supported way to regain access while preserving data in the
> current state."*

## What it does

- **Identifies** the device from *actual* probe data (never guesses a model):
  platform, manufacturer, model, model number, serial/IMEI (redacted in logs),
  OS version, boot/connection state, USB state.
- **Gates on ownership**: a five-clause *Device Ownership & Recovery
  Authorization* must be affirmed before any destructive step.
- **Prefers data preservation**: a five-level ladder (official non-destructive →
  linked account → manufacturer service → official recovery mode → factory reset
  as an absolute last resort).
- **Detects official backups** (iCloud/Finder; Google/Photos/Drive; vendor cloud)
  to check *before* anything destructive.
- **Dry Run**: previews device, state, recovery path, required tools, potential
  data loss, account requirements, steps, and risk — performing nothing.
- **Validates every command** through a `CommandSafetyValidator`
  (`SAFE` / `WARNING` / `DESTRUCTIVE` / `BLOCKED`).
- **Audit logs** locally, with strict redaction of secrets.
- **Fails safe**: on USB disconnect, unknown device, unexpected state,
  unsupported model, or auth/recovery failure, it stops and hands control back.

## What it will NOT do

Bypass or remove: Secure Boot · Activation Lock · iCloud Lock · FRP / Google
Account Protection · Samsung Knox · Android Verified Boot · the lock screen ·
device encryption · encryption keys · manufacturer security partitions. It also
will not brute-force credentials, use exploits, or modify security partitions to
defeat protection. See [`RECOVERY_POLICY.md`](./RECOVERY_POLICY.md) and
[`SECURITY.md`](./SECURITY.md).

## Requirements

- Node.js ≥ 20. **Zero runtime dependencies.**
- Optional, user-provided **official** device tooling (`adb`, `fastboot`,
  Apple's `libimobiledevice`/Finder/iTunes). The tool **does not install**
  anything and treats missing tools as "unavailable" rather than pretending a
  device is present.

## Usage

```bash
# Show the dashboard (auto-detects; honest "no device" when nothing is connected)
node src/cli.js dashboard

# Preview only — performs nothing on the device
node src/cli.js dry-run

# List all official recovery methods for the detected device
node src/cli.js options

# Interactive, safety-gated official recovery flow
node src/cli.js recover

# Force the "no device" transport (demo / CI)
node src/cli.js dashboard --no-device
```

Set `PRA_AUDIT_LOG=/path/to/audit.log` to persist the (redacted) audit log.

Even in the `recover` flow, the tool **does not perform destructive steps
automatically**. It validates authorization and produces a vetted plan with
official references for the owner to carry out.

## Architecture

```
src/
  device/     deviceIdentity.js  deviceState.js  transport.js
  safety/     commandSafetyValidator.js  ownershipGate.js  blockedPatterns.js
  recovery/   recoveryLevels.js  recoveryEngine.js  dryRun.js
  providers/  recoveryProvider.js  samsung.js  google.js  xiaomi.js
              apple.js  genericAndroid.js  registry.js
  backup/     backupDetector.js
  logging/    auditLog.js  redaction.js
  ui/         dashboard.js  prompts.js
  utils/      result.js
  cli.js
test/         (node:test; mocks only — no real device is ever used)
```

The `RecoveryProvider` abstraction lets new vendors be added without touching the
safety core. Each provider declares its **official** methods, required
drivers/software, whether data is preserved, account requirements, risks, and the
exact confirmation each method needs.

## Testing

```bash
npm test
```

Tests cover device detection, provider selection, command classification,
destructive-operation confirmation, redaction, unknown/unsupported devices,
failed recovery, USB disconnect, and user cancellation — all with mocks. See
[`TEST_PLAN.md`](./TEST_PLAN.md).

## Documents

- [`SECURITY.md`](./SECURITY.md) — security posture and non-goals.
- [`THREAT_MODEL.md`](./THREAT_MODEL.md) — assets, actors, and mitigations.
- [`RECOVERY_POLICY.md`](./RECOVERY_POLICY.md) — the recovery ladder and rules.
- [`TEST_PLAN.md`](./TEST_PLAN.md) — what is tested and how.

## License

MIT.
