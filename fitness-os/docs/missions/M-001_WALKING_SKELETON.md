# Mission M-001 — Walking Skeleton v0.1

Status: renewal-only retrospective calibration; full attendance gate remains blocked.

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
- [x] Clean CI run passes the full PostgreSQL-backed suite (`33 passed`).
- [x] Owner requests continuation with offline real-data calibration (2026-09-17).
- [ ] Timestamped member check-ins and complete coverage available for absence calibration.
- [ ] Owner approves thresholds for operational use. No such approval is inferred from calibration.

## Data quality extension

Absence evaluation now requires declared complete attendance coverage through
the evaluation instant and for at least the threshold window. Missing coverage
is unavailable, not zero risk. A never-observed visit is only flagged over the
fully covered interval inside the current subscription episode. Renewal remains
independently evaluable when absence is unavailable.

Membership dates are inclusive business-local dates. The pilot excludes frozen,
suspended, expired and future-only subscriptions. Future contracts still require
manual review before contact. Scores are heuristic test weights, not calibrated
probabilities. Draft tasks are run-scoped, not persistent operational tasks.

The workbook adapter inherits the prior workbook's conservative core-product
classification and quarantines members with missing episode dates. Its synthetic
export-row identifiers and date-only analysis anchor must not be promoted into a
production MOS connector. Results never prove revenue recovered or causal lift.

## Exit gate

M-001 passes only after clean CI evidence and owner acceptance of the calibration run. Passing M-001 authorizes design of the next mission; it does not authorize production deployment.

## Next mission if passed

M-002: Retention Vertical Slice — database-backed risk evaluations, named task assignment, deadlines, status transitions, outcomes, and revenue-recovery attribution.
