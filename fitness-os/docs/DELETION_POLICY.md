# Deletion Policy

Guiding rule (Canonical Model §20): **operational and historical data must never
disappear because a parent row was deleted by mistake.** Foreign keys therefore
default to `ON DELETE RESTRICT`. `ON DELETE CASCADE` is used only for tightly
owned *configuration/detail* subtrees, and each use is listed here.

## RESTRICT (default — 37 foreign keys)

Every cross-aggregate reference and all operational history is RESTRICT,
including every foreign key into the protected tables called out by the mission:
`members`, `program_versions`, `workout_sessions`, `workout_sets`,
`progression_events`, `audit_events`.

Consequences (verified by tests):
- A `member` with any workout/assignment/measurement history **cannot be
  hard-deleted**. Use soft-delete via `members.status` instead.
- An `exercise` referenced by a program prescription or a performed workout
  **cannot be deleted**.
- A `program_version` referenced by an assignment (or whose prescribed rows have
  been used in a workout) **cannot be deleted**, preserving assigned history.
- `workout_sessions` → `workout_exercises` → `workout_sets` are RESTRICT all the
  way down, so performed history is never cascade-erased.
- `progression_events` and `audit_events` are append-only and protected.

## CASCADE (12 foreign keys — owned config/detail only)

These children are exclusively owned by their parent and carry no independent
operational history, so cascading cleanup is safe:

| Child | Parent | Why safe |
|-------|--------|----------|
| assessment_metrics | assessments | detail rows of one assessment |
| assessment_flags | assessments | detail rows of one assessment |
| exercise_muscles | exercises | descriptor of the exercise |
| exercise_equipment | exercises | descriptor of the exercise |
| exercise_media | exercises | descriptor of the exercise |
| exercise_instructions | exercises | descriptor of the exercise |
| program_phases | program_versions | structure inside one version |
| program_weeks | program_phases | structure inside one version |
| program_sessions | program_weeks | structure inside one version |
| program_session_exercises | program_sessions | structure inside one version |
| set_prescriptions | program_session_exercises | prescription rows of one exercise slot |
| assignment_session_state | program_assignments | transient per-assignment state |

Note the interaction that keeps this safe for programs: although a version's
phase/week/session/exercise subtree cascades, a version (or a
`program_session_exercise`) that has actually been **used** is still protected —
`program_assignments.program_version_id` and
`workout_exercises.program_session_exercise_id` are RESTRICT, so an in-use
version cannot be deleted in the first place. Cascading therefore only ever
cleans up an **unused draft**.

## Soft delete

Where a "delete" is really a lifecycle change (members, coaches, branches,
templates, goals, assignments), use the `status` column rather than a row delete.
