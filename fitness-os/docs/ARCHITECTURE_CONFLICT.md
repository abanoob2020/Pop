# Architecture: Current vs Canonical — Conflict & Resolution

Per Canonical Model §26/§29, real conflicts between the existing code and the
canonical requirement are documented rather than guessed.

## Current architecture (discovered)

- Repository `Pop` contains `xcamp-gym-sql`: a **PHP 8.x + MySQL 8.0**
  application (raw SQL files: tables, stored procedures, triggers, events,
  views; a PHP `dashboard/`).
- No Python, no SQLAlchemy, no Alembic, no PostgreSQL usage.
- `CLAUDE.md` described the repo as "greenfield"; that is stale — the PHP/MySQL
  app already exists.

## Canonical requirement (mission)

- **PostgreSQL 15**, **SQLAlchemy 2.x**, **Alembic**, UUID primary keys, UTC
  timestamps, split migrations `001..012`.

## Conflict

The canonical stack (PostgreSQL + Python/SQLAlchemy/Alembic) differs entirely
from the current stack (MySQL + PHP + raw SQL). Interpreted literally, "convert
the model to a real schema" could be read as *rewrite the existing app*.

## Resolution (least-risk, no constitutional change)

The Fitness OS Canonical Data Model is delivered as a **new, isolated Python
package** at `fitness-os/`, independent of `xcamp-gym-sql`:

- It does **not** modify the PHP/MySQL app, its SQL, or its database.
- It does **not** read or write **MOS** — MOS data is only *mirrored* via
  observed, read-only snapshot tables (`membership_snapshots`,
  `attendance_events`) with no MOS client anywhere in the code.
- It targets a **separate staging database** (`xcamp_os_staging`) and a
  least-privilege role (`xcamp_app`).

This satisfies the canonical requirement exactly (PostgreSQL 15+/SQLAlchemy
2.x/Alembic, the 001–012 split, UUID PKs, UTC) while touching nothing that could
put MOS or the existing product at risk. No change to the XCAMP "constitution"
is required, so no owner approval gate was triggered on this point.

## Environment note (staging execution)

The mission references Cloud SQL instance `xcamp-os-db` and database
`xcamp_os_staging`. This build was executed against a **local PostgreSQL 16
instance acting as the staging stand-in** (the sandbox cannot reach the real
Cloud SQL instance, and no production credentials are present — by design). The
migrations, tests, and round-trip were all validated there. Running the identical
`alembic upgrade head` against the real Cloud SQL `xcamp_os_staging` requires the
owner to supply staging credentials in the GCP environment; the guarded
`scripts/migrate_staging.sh` prints the target (without the password) and refuses
production-looking database names before it runs.

PostgreSQL version: the canonical requirement is 15; validation used 16, which is
backward-compatible for every feature used here (`gen_random_uuid()` is core
since 13, `JSONB`, `TIMESTAMPTZ`, UUID). No 16-only feature is used.
