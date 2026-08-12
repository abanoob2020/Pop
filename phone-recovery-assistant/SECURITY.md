# Security Policy & Posture

Phone Recovery Assistant is a **defensive, owner-only** recovery aid. Its entire
design goal is to help a legitimate owner regain access through **official**
channels while **never** weakening or circumventing device security.

## Non-negotiable non-goals

This project will never implement, integrate, document, or assist with bypassing:

- Secure Boot
- Activation Lock / iCloud Lock
- FRP (Factory Reset Protection) / Google Account Protection
- Samsung Knox protections
- Android Verified Boot (AVB / dm-verity)
- The lock screen (PIN / password / pattern / biometrics)
- Device encryption (FBE / FDE) or encryption keys
- Manufacturer security partitions

It will not brute-force or guess credentials, use exploits to defeat
authentication, or modify the bootloader/security partitions to circumvent
protection. These intents are enumerated in
`src/safety/blockedPatterns.js` and enforced by `CommandSafetyValidator`.

## Enforcement mechanisms

- **Command classification** (`src/safety/commandSafetyValidator.js`): every
  command is classified `SAFE` / `WARNING` / `DESTRUCTIVE` / `BLOCKED`. A
  `BLOCKED` classification throws and cannot be forced through, even with user
  confirmation. Both the command *and its declared intent* are checked, so a
  benign command with a circumvention purpose is still blocked.
- **Ownership gate** (`src/safety/ownershipGate.js`): destructive operations
  require all authorization clauses plus a separate explicit confirmation.
- **Fail-safe states** (`src/device/deviceState.js`): `UNKNOWN` is terminal —
  it is never converted into a bypass path; the engine stops.
- **No automatic destructive execution**: even after authorization, the engine
  returns a vetted plan; it does not wipe or reflash a device on its own.

## Data handling

- **Local only.** The tool does not transmit logs or device data to any external
  service.
- **Redaction** (`src/logging/redaction.js`): PINs, passwords, Apple/Google
  passwords, tokens, and encryption keys are never written to logs. IMEIs and
  serials are reduced to a short non-identifying suffix.
- The tool **never asks the user for a PIN or password.**

## Supply chain

- **Zero runtime dependencies.** The attack surface from third-party packages is
  eliminated by design.
- The tool does not install device tooling or connect to external services
  without the user's action; missing tools are reported as unavailable.

## Reporting

If you believe a change would let this tool bypass a protection, or you find a
redaction gap, open an issue describing the concern (without including any real
secrets or identifiers). Circumvention feature requests are out of scope and will
be declined.
