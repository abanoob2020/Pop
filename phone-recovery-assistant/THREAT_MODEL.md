# Threat Model

This document frames what Phone Recovery Assistant protects, who it must resist,
and how its design mitigates misuse. The tool is deliberately narrow: it aids a
**legal owner** with **official** recovery and refuses everything else.

## Assets

| Asset | Why it matters |
|------|----------------|
| Device security mechanisms | Must remain intact; the tool must never help defeat them. |
| User data on the device | Preserve when possible; erase only with explicit, informed consent. |
| Secrets (PIN, passwords, tokens, keys) | Must never be captured, logged, or transmitted. |
| Identifiers (IMEI, serial) | Minimize exposure; redact in all logs/output. |
| The audit log | Local record of actions; must not itself leak secrets. |

## Actors

- **Legitimate owner (intended user).** Locked out of their own device; wants
  official recovery, ideally without data loss.
- **Misuse actor (explicitly out of scope).** Someone attempting to unlock a
  device they do not own, or to bypass a protection. The tool is designed to be
  useless for this: it offers no bypass, and gates destructive actions behind
  ownership affirmations and manufacturer account/proof-of-ownership steps that a
  non-owner cannot satisfy.
- **The tool operator environment.** May or may not have official device tooling;
  may lose USB connectivity at any time.

## Trust boundaries

- **Device tooling (`adb`/`fastboot`/Apple tools):** external, user-provided.
  The transport layer only issues **read-only** probes and validates each through
  `CommandSafetyValidator` before spawning.
- **User input:** treated cautiously. Unknown commands fail closed to `WARNING`;
  destructive intents and circumvention intents are blocked regardless of input.
- **Filesystem (audit log):** local; written only after redaction.

## Threats & mitigations

| Threat | Mitigation |
|-------|-----------|
| Tool is repurposed to bypass a protection | `blockedPatterns.js` + `CommandSafetyValidator`; `BLOCKED` cannot be forced; provider content is tested to contain no bypass. |
| Non-owner uses it to unlock a found/stolen phone | Ownership gate; official paths require the linked account or manufacturer proof-of-ownership, which a non-owner lacks; no lock-screen circumvention exists. |
| Accidental data loss | Data-preservation-first ladder; backup detection before destructive steps; double confirmation; no automatic destructive execution. |
| Secret leakage via logs | Deep redaction of sensitive keys/values; the tool never requests a PIN/password. |
| Identifier exposure | IMEI/serial reduced to a short suffix in any output/log. |
| Malicious/unknown device state | `UNKNOWN` is a fail-safe terminal state; engine stops and requests information. |
| USB disconnect mid-operation | Detection swallows transport errors into a safe `UNKNOWN`; the flow stops rather than continuing blindly. |
| Supply-chain compromise | Zero runtime dependencies; nothing is auto-installed; no external network calls without user action. |
| Policy drift (a future edit introduces a bypass) | Automated test asserts no provider method classifies as `BLOCKED`; engine re-validates method intent at execution time. |

## Explicit residual limitations

- The tool cannot and will not recover a device whose only path forward requires
  defeating a protection. In that case it states there is no safe, supported way
  to preserve data and shows only official options.
- It cannot verify ownership cryptographically; it relies on the owner's
  attestation plus the fact that official recovery inherently requires
  owner-only credentials or manufacturer proof-of-ownership.
