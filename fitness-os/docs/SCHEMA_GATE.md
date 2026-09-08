# XCAMP Fitness OS — Schema Gate Report v1.0 (Staging)

Scope: Canonical Data Model v1.0 → real PostgreSQL schema via SQLAlchemy 2.x +
Alembic, executed and tested on **staging only**. No production migration, no
merge, no API/UI/AI, no MOS write path.

## Gate verdicts by axis

| Axis | Verdict | Notes |
|------|---------|-------|
| Schema | **PASS** | 12 domains → 38 tables; UUID PKs; UTC `TIMESTAMPTZ`. |
| Relationships | **PASS** | Real FKs across all aggregates; member↔MOS via `external_references`. |
| Migrations | **PASS** | Linear chain 0001→0012; single head; autogenerate drift = 0. |
| Indexes | **PASS** | All canonical access-pattern indexes present; no over-indexing. |
| Data Integrity | **PASS** | FK/unique/round-trip enforced at DB level and tested. |
| MOS Isolation | **PASS** | Read-only mirror only; no MOS client; no outbound write. |
| Security | **PASS** | No secrets in git; least-privilege `xcamp_app`; target printed w/o password. |
| Rollback | **PASS** | `upgrade → downgrade base → upgrade head` verified (0 → 38 tables). |
| Tests | **PASS** | 23 automated tests pass against a migrated DB. |

## A. Files changed / added

| File | Purpose |
|------|---------|
| `fitness-os/app/config.py` | Env-driven DB settings; safe (password-free) summary. |
| `fitness-os/app/db/base.py` | Declarative Base + constraint naming convention. |
| `fitness-os/app/db/mixins.py` | UUID PK + UTC timestamp mixins. |
| `fitness-os/app/db/session.py` | Engine / session factory. |
| `fitness-os/app/db/models/*.py` | 12 domain model modules (source of truth). |
| `fitness-os/migrations/env.py` | Runtime URL from env; prints target w/o password. |
| `fitness-os/migrations/helpers.py` | Shared migration column builders. |
| `fitness-os/migrations/versions/001..012_*.py` | Per-domain migrations. |
| `fitness-os/tests/*.py` | Automated schema/integrity/isolation tests. |
| `fitness-os/scripts/print_target.py` | Pre-flight target print + prod guard. |
| `fitness-os/scripts/migrate_staging.sh` | Guarded `alembic upgrade head`. |
| `fitness-os/docs/*.md` | Deletion policy, architecture conflict, this gate. |
| `fitness-os/{alembic.ini,requirements.txt,pyproject.toml,.env.example,.gitignore}` | Tooling/config. |

## B. Tables created (38)

- **Identity (5):** members, external_references, branches, coaches, coach_member_assignments
- **MOS mirror (2):** membership_snapshots, attendance_events
- **Fitness profile (2):** member_fitness_profiles, member_goals
- **Assessment (3):** assessments, assessment_metrics, assessment_flags
- **Exercise library (5):** exercises, exercise_muscles, exercise_equipment, exercise_media, exercise_instructions
- **Programming (7):** program_templates, program_versions, program_phases, program_weeks, program_sessions, program_session_exercises, set_prescriptions
- **Assignments (2):** program_assignments, assignment_session_state
- **Workout execution (3):** workout_sessions, workout_exercises, workout_sets
- **Progression (4):** progression_policies, assignment_progression_rules, progression_state, progression_events
- **Progress (1):** body_measurements
- **Coaching (2):** coach_notes, coaching_interventions
- **Audit (2):** audit_events, domain_events

## C. Migration chain

| revision | down_revision | purpose |
|----------|---------------|---------|
| 0001 | (none) | identity |
| 0002 | 0001 | MOS read-only mirror |
| 0003 | 0002 | fitness profile |
| 0004 | 0003 | assessment interface (generic) |
| 0005 | 0004 | exercise library (+ media licensing) |
| 0006 | 0005 | programming (template→version→…→prescription) |
| 0007 | 0006 | program assignments |
| 0008 | 0007 | workout execution (prescribed vs performed) |
| 0009 | 0008 | progression engine |
| 0010 | 0009 | progress intelligence (body_measurements) |
| 0011 | 0010 | coaching |
| 0012 | 0011 | audit & domain events |

Single head: `0012`. Autogenerate drift check against models: **no changes**.

## D. Key constraints

- **Unique:** `external_references (source_system, source_entity, external_id)`;
  `program_versions (template_id, version_number)`;
  `progression_state (program_assignment_id, exercise_id)`;
  `exercises.slug`; `progression_policies.code`;
  `member_fitness_profiles.member_id`.
- **Foreign keys:** 49 total — **37 RESTRICT** (all history/cross-aggregate),
  **12 CASCADE** (documented owned-config subtrees).
- **Assignment → version, not template:** `program_assignments.program_version_id`
  (RESTRICT); there is deliberately no `template_id` on assignments.

## E. Indexes (new, by access pattern)

`members.phone`; `external_references` triple (via unique);
`membership_snapshots.member_id`, `.expiry_date`;
`attendance_events.member_id`, `.checked_in_at`;
`program_assignments.member_id`, `.program_version_id`;
`workout_sessions.member_id`, `.started_at`;
`workout_sets.workout_exercise_id`;
`progression_events.assignment_id`, `.exercise_id`;
plus FK-supporting indexes on child tables and audit lookups
(`audit_events.request_id`, `.occurred_at`; `domain_events.aggregate_id`, `.occurred_at`).
No speculative/over-indexing.

## F. Tests

| Test | Result | Evidence |
|------|--------|----------|
| schema creation — all model tables exist / column parity / UUID PKs | PASS | reflect migrated DB vs `Base.metadata` |
| unique MOS reference — `MOS/member/2636` twice fails | PASS | IntegrityError on 2nd insert |
| foreign keys — workout/exercise/version/set for missing parent fails; valid chain succeeds | PASS | IntegrityError on orphans |
| program version history — assignment bound to version, not template | PASS | new version doesn't alter assignment |
| workout separation — editing `actual_*` doesn't change prescription | PASS | prescription untouched after edit |
| deletion policy — member/exercise history RESTRICT; assessment children CASCADE | PASS | RESTRICT raises; CASCADE cleans |
| migration round-trip — upgrade/downgrade/upgrade | PASS | 0 → 38 → 0 → 38 tables |
| MOS protection — no outbound write / MOS client / http import | PASS | static source scan |

Total: **23 passed**.

## G. Security review

| Severity | Finding | Location | Recommendation |
|----------|---------|----------|----------------|
| Info | No secrets committed; DB URL assembled from env vars only. | `app/config.py`, `.env.example` | Keep `.env` git-ignored (already is). |
| Info | Runtime role is least-privilege `xcamp_app` (no SUPERUSER/CREATEDB/CREATEROLE); `postgres` not used at runtime. | staging role | Mirror the same role on real Cloud SQL staging. |
| Info | No MOS client, no `requests/httpx` write calls, no outbound write integration. | whole package | Enforced by `tests/test_mos_protection.py`. |
| Low | Test-suite admin URL needs CREATE/DROP DATABASE. | `tests/conftest.py` | Use a dedicated test/staging admin role, never a production superuser. |

No high/critical findings.

## H. Staging evidence

- Database: **xcamp_os_staging** (local PostgreSQL 16 staging stand-in; see
  `ARCHITECTURE_CONFLICT.md` for why, and how to run on real Cloud SQL).
- Alembic head: **0012**
- Base tables: **38** (+ `alembic_version`)
- Runtime role `xcamp_app`: `rolsuper=f, rolcreatedb=f, rolcreaterole=f, rolcanlogin=t`
- FK delete rules: **RESTRICT 37 / CASCADE 12**

## I. Final verdict

**READY FOR SCHEMA REVIEW**

(Explicitly *not* production-ready and not authorized for production migration,
merge to `main`, API/UI/AI, or any MOS write. Awaiting explicit owner approval.)
