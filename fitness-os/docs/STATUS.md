# XCAMP Gym OS — Authoritative Status

Updated: 2026-09-17

## Current baseline

- Repository: `abanoob2020/Pop`
- Base branch: `claude/xcamp-fitness-os-schema-3tk05h`
- Base commit: `f0f32d639d6c9259ea5aa9297a1e3567b2db96a9`
- Existing review: PR #44, open against `main`
- Canonical schema on the base: 38 tables, Alembic head `0012`
- Base PR claim: 23 tests; GitHub currently reports no check runs on its head

Claims about migration `0013` or `53/53` tests are not present on the verified remote baseline and must not be used as implementation facts until their commits are located.

## Active mission

[`M-001 — Walking Skeleton v0.1`](missions/M-001_WALKING_SKELETON.md)

Current phase: implementation and verification.

## Scope state

| Area | State |
|---|---|
| Constitution and authority | Active |
| Walking Skeleton | Active mission |
| Retention production workflow | Next, blocked by M-001 gate |
| Staff Execution OS | Frozen |
| Sales/lead distribution | Frozen |
| Coach/PT Engine | Frozen |
| Finance Control | Frozen |
| Marketing/Growth | Frozen |
| Cloud migration/optimization | Frozen |
| AI decision layer | Frozen |

## Known blockers

1. No production MOS read-only connector has been authorized or verified; M-001 uses supplied snapshots only.
2. No named task assignee directory exists in the active Fitness OS layer.
3. Pilot thresholds require calibration on current real data before production approval.
4. PR #44 is not yet merged; this mission must preserve a clean dependency on that baseline.

## Decision log

- Continue the existing Fitness OS; do not open another product or repository.
- Keep legacy PHP/MySQL isolated and frozen.
- Keep MOS immutable/read-only.
- Finish one vertical chain before Sales, PT, Finance, Growth, AI, or infrastructure expansion.
