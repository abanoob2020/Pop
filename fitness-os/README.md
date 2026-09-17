# XCAMP Fitness OS — Canonical Schema + Walking Skeleton (Staging Only)

A **new, self-contained** PostgreSQL data layer for the XCAMP Fitness OS
Canonical Data Model v1.0, built with **SQLAlchemy 2.x + Alembic**. It lives
alongside — and is fully independent of — the existing `xcamp-gym-sql` PHP/MySQL
application. It does not modify, read from a write path into, or otherwise touch
**MOS**, which remains **IMMUTABLE / READ-ONLY**.

> Scope guard: this package is **staging only**. It contains no production
> migration path, no frontend, no AI, no MCP, no auto-collector, and no MOS
> client. See [`docs/SCHEMA_GATE.md`](docs/SCHEMA_GATE.md) for the review gate.

Project authority, scope, and the single active mission are defined in
[`docs/PROJECT_CONSTITUTION.md`](docs/PROJECT_CONSTITUTION.md) and
[`docs/STATUS.md`](docs/STATUS.md). Those files supersede older chat summaries.

## Layout

```
fitness-os/
├─ app/
│  ├─ config.py                # env-driven DB settings (no secrets in git)
│  └─ db/
│     ├─ base.py               # Declarative Base + naming convention
│     ├─ mixins.py             # UUID PK + UTC timestamp mixins
│     ├─ session.py            # engine / session factory
│     └─ models/               # 12 domains → 38 tables (source of truth)
├─ migrations/
│  ├─ env.py                   # runtime URL from env; prints target (no pw)
│  ├─ helpers.py               # shared column builders
│  └─ versions/001..012_*.py   # one migration per canonical domain
├─ tests/                      # schema, FK, uniqueness, versioning, MOS guard…
├─ examples/                   # synthetic input only; no production member data
├─ app/walking_skeleton/       # deterministic calibration pipeline + CLI
├─ scripts/
│  ├─ print_target.py          # print host/db/user (no password) + guard
│  └─ migrate_staging.sh       # guarded `alembic upgrade head`
├─ docs/
│  ├─ DELETION_POLICY.md
│  ├─ ARCHITECTURE_CONFLICT.md
│  └─ SCHEMA_GATE.md
├─ alembic.ini · requirements.txt · pyproject.toml · .env.example
```

## The 12 domains (migration chain)

| Rev | Migration | Tables |
|-----|-----------|--------|
| 0001 | identity | members, external_references, branches, coaches, coach_member_assignments |
| 0002 | mos_readonly_mirror | membership_snapshots, attendance_events |
| 0003 | fitness_profile | member_fitness_profiles, member_goals |
| 0004 | assessment_interface | assessments, assessment_metrics, assessment_flags |
| 0005 | exercise_library | exercises, exercise_muscles, exercise_equipment, exercise_media, exercise_instructions |
| 0006 | programming | program_templates, program_versions, program_phases, program_weeks, program_sessions, program_session_exercises, set_prescriptions |
| 0007 | program_assignments | program_assignments, assignment_session_state |
| 0008 | workout_execution | workout_sessions, workout_exercises, workout_sets |
| 0009 | progression_engine | progression_policies, assignment_progression_rules, progression_state, progression_events |
| 0010 | progress_intelligence | body_measurements |
| 0011 | coaching | coach_notes, coaching_interventions |
| 0012 | audit_events | audit_events, domain_events |

## Design rules

- **UUID primary keys**, generated in the database (`gen_random_uuid()`).
- **UTC** timezone-aware timestamps (`TIMESTAMPTZ`, `now()` default).
- **MOS ids never become primary keys** — they live only in
  `external_references`, unique on `(source_system, source_entity, external_id)`.
- **Prescribed vs performed** are separate: `set_prescriptions` (immutable
  prescription) vs `workout_sets.actual_*` (performed). Execution never
  overwrites a prescription.
- Members are assigned to an **immutable `program_version`**, never a mutable
  template.
- Real **foreign keys** enforce integrity; deletes default to **RESTRICT** to
  protect operational history (see [`docs/DELETION_POLICY.md`](docs/DELETION_POLICY.md)).

## Running (staging)

Requires PostgreSQL 15+ (validated on 16). Configure via environment variables
(never commit real credentials — see `.env.example`):

```bash
python -m venv .venv && . .venv/bin/activate
pip install -r requirements.txt

export FITNESS_DB_HOST=... FITNESS_DB_PORT=5432 \
       FITNESS_DB_NAME=xcamp_os_staging \
       FITNESS_DB_USER=xcamp_app FITNESS_DB_PASSWORD=***

./scripts/migrate_staging.sh          # prints target (no password), then upgrade head
```

The runtime user must be a least-privilege role (e.g. `xcamp_app`) — **not**
`postgres`, and without `SUPERUSER` / `CREATEDB` / `CREATEROLE`.

## Tests

The suite builds a throwaway database with the real migration chain and asserts
schema shape, uniqueness, foreign keys, version history, prescribed/performed
separation, deletion policy, the migration round-trip, and MOS isolation.

```bash
export FITNESS_TEST_ADMIN_URL="postgresql+psycopg://postgres@127.0.0.1:5432/postgres"
export FITNESS_TEST_DB_NAME=xcamp_os_test
python -m pytest
```

## Walking Skeleton v0.1

Run the synthetic, read-only calibration path:

```bash
python -m app.walking_skeleton.cli examples/mos_snapshot.sample.json \
  --output-dir .local/walking-skeleton-runs
```

The command produces canonical JSON. Re-running the same snapshot under the
same policy is a no-op with the same run id and bytes. This harness does not
connect to MOS or write to the operational database. Its pilot thresholds are
not production policy; see
[`docs/missions/M-001_WALKING_SKELETON.md`](docs/missions/M-001_WALKING_SKELETON.md).

## Not in scope (hard stop)

No production migration, no merge to `main` beyond review, no Fitness API,
no UI, no AI, no MCP, no auto-collector, and no write path to MOS.
