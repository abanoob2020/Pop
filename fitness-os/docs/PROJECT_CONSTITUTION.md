# XCAMP Gym OS Constitution v1.0

Status: active for development. Production authority remains with the owner.

## Mission

Protect and recover revenue by converting observed business signals into assigned, verified, measurable human action.

## Center of gravity

The first proven loop is:

`Member -> Attendance/Renewal Risk -> Task -> Human Action -> Outcome -> Recovered Revenue`

Technology choices serve this loop. They do not define the roadmap.

## Authority

| Decision | Authority |
|---|---|
| Business policy and production gate | Owner |
| Mission scope and acceptance evidence | Owner-approved mission record |
| Deterministic evaluation and audit | System |
| Task execution and recorded outcome | Assigned staff member |
| Recommendations | AI/advisory tools only |

No AI component may approve its own production policy or silently change a business rule.

## Invariants

1. MOS remains external and read-only. Supplied exports or a separately approved read-only connector are the only inputs.
2. XCAMP owns its internal identifiers, policies, tasks, outcomes, and audit history.
3. Source records retain provenance and content hashes.
4. Reprocessing the same source snapshot under the same policy is idempotent.
5. Evaluation is reproducible against an explicit `as_of` timestamp; wall-clock time is not a hidden input.
6. Invalid or ambiguous input fails closed.
7. Production releases require evidence from tests plus an explicit owner gate.
8. Legacy code is frozen unless the active mission explicitly includes it.

## Delivery discipline

- One active mission.
- One bounded branch.
- Acceptance criteria written before expansion.
- No feature is complete without verification evidence.
- Failed gates remain failed; they are not reworded as progress.
- New modules stay frozen until the current gate passes.

## Frozen scope

Until the Retention Core Loop is proven on a controlled pilot, do not expand into Sales CRM, PT automation, Coach AI, cameras, nutrition, SaaS packaging, native mobile applications, Cloud migration, or multi-agent production control.
