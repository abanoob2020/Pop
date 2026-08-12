# Recovery Policy

The rules every recovery path in this tool must follow.

## Prime directive

Assist the **legal owner** using **official** methods only. Never bypass a vendor
protection. If preserving data is impossible via official means in the current
state, say so and present only official options.

## Ownership & authorization (required first)

Before any destructive step, the owner must affirm all clauses of the
*Device Ownership & Recovery Authorization* (`src/safety/ownershipGate.js`):

1. This device belongs to me.
2. I have the legal right to recover access to it.
3. I understand some recovery methods may result in data loss.
4. I understand this tool will NOT bypass Activation Lock, FRP, Secure Boot, or
   any vendor protection.
5. I agree to perform official recovery procedures only.

Destructive operations additionally require a separate, explicit confirmation.

## The recovery ladder (data-preservation-first)

Try lower levels first; only escalate when a level is impossible.

| Level | Name | Data | Notes |
|------:|------|------|------|
| 1 | Official recovery without data loss | Preserved | Best case. |
| 2 | Linked-account recovery | Preserved | Uses the account tied to the device, where the platform allows. |
| 3 | Manufacturer / service center | Usually not | Official proof-of-ownership process. |
| 4 | Official recovery mode | Often not | Vendor recovery/restore procedure. |
| 5 | Factory reset | **Erased** | **Absolute last resort.** |

Before Level 5, the tool shows a prominent warning:
*"This action may delete all data on the device,"* checks for official backups,
and requires the additional explicit confirmation.

## Command classification

Every command passes `CommandSafetyValidator`:

- **SAFE** — read-only/diagnostic.
- **WARNING** — reversible state change, or unknown command (fail closed).
- **DESTRUCTIVE** — may erase data; requires double confirmation.
- **BLOCKED** — purpose is to circumvent a protection; **never executed.**

## Backups (before anything destructive)

Check official, owner-owned backups first:

- **Apple:** iCloud Backup; Finder/iTunes backup.
- **Android:** Google One/Android Backup; Google Photos; Google Drive; and the
  vendor cloud (Samsung Cloud, Mi Cloud, Huawei/Honor Cloud, etc.).

The tool only points the owner to official locations to check with **their own**
account. It never accesses, downloads, or decrypts protected data.

## Post-reset reality (stated honestly)

A factory reset does **not** remove FRP / Google Account Protection or Activation
Lock. After a reset, the device will still require the previously linked account.
The tool makes this clear so the owner does not reset a device they cannot then
reactivate.

## Fail-safe

On USB disconnect, unknown device, unexpected state, unsupported model, or
authentication/recovery failure, the tool **stops safely** and does not attempt
further steps automatically.

## Information the tool may request (never a PIN/password)

Manufacturer · model number · operating system · whether the owner knows the
linked Google/Apple account · whether a backup exists · the phone's current
screen/state. The tool never asks for a PIN, passcode, or password.
