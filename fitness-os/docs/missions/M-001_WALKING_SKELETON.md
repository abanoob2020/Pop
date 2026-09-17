# Mission M-001 — Walking Skeleton v0.1

Status: implementation complete; awaiting CI and owner review.

## Objective

Prove a narrow, deterministic path from a supplied MOS snapshot to explainable retention tasks and an auditable canonical output.

## Authorized scope

`Snapshot -> Validate -> Normalize -> Evaluate -> Create Task Drafts -> Audit -> Persist Artifact`

This mission is calibration-only. It does not contact members, assign named staff, write to the operational database, call MOS, or run in production.

## Pilot policy

- Attendance gap: at least 10 complete days since the last recorded visit.
- Renewal window: active membership expires within 0–30 days.
- Combined signal score: attendance `50`, renewal `30`.
- Task owner is a placeholder role (`retention_desk`), not a production assignment.

These thresholds are test policy, not final business policy. Promotion requires a separate owner decision using real-data calibration evidence.

## Acceptance criteria

- [x] Explicit snapshot, observation time, and evaluation time.
- [x] Stable normalization independent of source row order.
- [x] Deterministic UUIDs, hashes, tasks, and output bytes.
- [x] Identical retry is a no-op; conflicting retry fails closed.
- [x] Unknown references, duplicate source IDs, future events, and timezone-free timestamps fail closed.
- [x] Every decision includes reason codes and policy identity.
- [x] MOS access is supplied-snapshot/read-only; no client or write path exists.
- [x] Unit tests cover the success path and injected negative cases.
- [ ] Clean CI run passes the full PostgreSQL-backed suite.
- [ ] Owner accepts or rejects the pilot thresholds for real-data calibration.

## Exit gate

M-001 passes only after clean CI evidence and owner acceptance of the calibration run. Passing M-001 authorizes design of the next mission; it does not authorize production deployment.

## Next mission if passed

M-002: Retention Vertical Slice — database-backed risk evaluations, named task assignment, deadlines, status transitions, outcomes, and revenue-recovery attribution.
