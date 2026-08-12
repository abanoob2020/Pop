# Test Plan

All tests use Node's built-in runner (`node:test`) and **mocks only**. No real
device or device tooling is ever used (per policy). Run with `npm test`.

## Principles

- Deterministic and offline: a mock `Transport` supplies canned probe output
  (`test/mocks.js`); a null transport represents "no device".
- Safety-critical logic is pure and directly unit-tested.
- Fail-closed behavior is asserted, not assumed.

## Coverage matrix

| Area | Requirement | Test file |
|------|-------------|-----------|
| Device detection | adb-available / unauthorized / recovery / fastboot / apple / no-device | `test/device.test.js` |
| USB disconnect | transport throws mid-probe → safe `UNKNOWN`, not connected | `test/device.test.js` |
| Provider selection | Samsung/Google/Xiaomi/Apple routing; generic fallback | `test/providers.test.js` |
| Unsupported device | unknown Android vendor → generic provider | `test/providers.test.js` |
| Unknown device | `UNKNOWN` selects **no** provider (never forced) | `test/providers.test.js` |
| Data-preservation-first | first method is least destructive; resets flagged | `test/providers.test.js` |
| No-bypass invariant | no provider method classifies as `BLOCKED` | `test/providers.test.js` |
| Command classification | SAFE / WARNING / DESTRUCTIVE / BLOCKED; unknown fails closed | `test/safety.test.js` |
| Blocked intents | bypass, FRP, Activation Lock, brute force, exploit, key extraction | `test/safety.test.js` |
| Intent smuggling | benign command + malicious intent still BLOCKED | `test/safety.test.js` |
| Ownership gate | all clauses required | `test/safety.test.js` |
| Destructive confirmation | full auth **and** explicit confirmation required | `test/safety.test.js`, `test/engine.test.js` |
| Sensitive-data redaction | keys/values/IMEI redacted; audit log never stores secrets | `test/redaction.test.js` |
| Fail-safe engine | `UNKNOWN` → stop, no action | `test/engine.test.js` |
| Failed recovery | unknown method id → graceful `no-path` | `test/engine.test.js` |
| No auto-destruction | authorized destructive method returns a plan, not an action | `test/engine.test.js` |
| User cancellation | empty confirm defaults to false; wrong phrase aborts | `test/ui.test.js` |
| Dashboard/UI | honest `UNKNOWN` rendering; ownership clauses; dry-run output | `test/ui.test.js` |

## Running

```bash
npm test          # run once
npm run test:watch
```

## Manual smoke (no device required)

```bash
node src/cli.js dashboard --no-device
node src/cli.js dry-run --no-device
```

Both must report "no device" honestly and never claim a recovery path.

## Adding a provider

When adding a `RecoveryProvider`, the existing invariants automatically apply:

- `NO provider method describes a bypass` will fail the build if any new method's
  title/description classifies as `BLOCKED`.
- `data-preservation-first` ordering and factory-reset flagging are enforced.

Add provider-specific selection and method assertions alongside these.
