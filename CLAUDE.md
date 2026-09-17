# XCAMP Gym OS — Repository Operating Contract

This repository contains two deliberately separated systems:

- `fitness-os/`: the new PostgreSQL/Python XCAMP Gym OS. This is the active product.
- `xcamp-gym-sql/`: the legacy PHP/MySQL application. It is frozen unless a mission explicitly names it.

The repository is not greenfield. Never infer current state from an old chat or report; inspect the branch, migrations, tests, and CI first.

## Non-negotiable rules

1. MOS is an external read-only source. No code may write to MOS.
2. Production changes require an explicit owner gate. Development and tests use fixtures or staging only.
3. Work on one named mission at a time. A mission must define scope, acceptance tests, and its exit gate.
4. Prefer deterministic rules before AI. Every decision must expose its inputs, policy version, and reason codes.
5. Every operational task must ultimately have an owner, deadline, status, and outcome. A prototype may use an owner role only when its mission explicitly says it is not production-ready.
6. Preserve auditability, idempotency, provenance, and rollback paths.
7. Do not add CRM, PT, camera, marketing, finance, infrastructure migration, or AI scope unless the active mission explicitly authorizes it.

## Current mission

Read `fitness-os/docs/STATUS.md` and the mission it links before changing code.

## Verification

From `fitness-os/`:

```bash
python -m pytest tests/unit
python -m pytest
```

The full suite requires PostgreSQL. CI is the authoritative clean-environment run.
