# xcamp-gym-sql

A self-contained **MySQL 8.0+** database for a gym / personal-coaching business,
built around an **event-driven operations core**: domain inserts (a new member, a
membership, a payment, an attendance record, an assessment, an injury, a progress
log, a follow-up) fire triggers that call stored procedures to open tasks, raise
retention flags, log messages, and move members through their lifecycle. On top of
that sits a set of operational and coach-dashboard views.

## Requirements

- **MySQL 8.0+** (uses `CHECK` constraints, `JSON` columns, derived tables in
  views, the event scheduler).
- A client that supports `SOURCE` (the standard `mysql` CLI) to run `run_all.sql`.
- To let the scheduled event fire, the **event scheduler** must be on:
  ```sql
  SET GLOBAL event_scheduler = ON;   -- needs SUPER / SYSTEM_VARIABLES_ADMIN
  ```
  `04_events.sql` attempts this automatically; where the privilege isn't granted
  the event is still created and runs once the scheduler is enabled.

## Quick start

```bash
cd xcamp-gym-sql
mysql -u root -p < run_all.sql
```

Or interactively:

```sql
mysql -u root -p
SOURCE run_all.sql;
```

### Load order (important)

`run_all.sql` sources the files as **00 → 01 → 02 → 06 → 03 → 04 → 05 → 07**.
The seed data (`06`) is loaded **before** the triggers (`03`) are created,
because the triggers fan `INSERT` events into `tasks` / `messages_log` /
`retention_flags` / `milestones`, and the seed sets explicit primary keys for
those tables — loading the seed with triggers already active would collide. Seed
first, then install the triggers for real runtime use.

The scripts are re-runnable against a fresh database: objects are
dropped-then-created and seed rows use explicit keys.

## File layout

| File | Purpose |
| ---- | ------- |
| `00_init.sql` | Create `xcamp_gym` (utf8mb4); save & set session `FOREIGN_KEY_CHECKS` / `SQL_MODE`. |
| `01_tables.sql` | All 20 tables (InnoDB, foreign keys, indexes, `CHECK` constraints). |
| `02_procedures.sql` | Helper + `sp_handle_*_event` orchestrator procedures. |
| `03_triggers.sql` | AFTER-INSERT triggers that fan domain events into the procedures. |
| `04_events.sql` | `ev_daily_retention_scan` housekeeping event. |
| `05_views.sql` | Operational + coach-dashboard views. |
| `06_seed_data.sql` | Deterministic sample data across every table. |
| `07_test_queries.sql` | Read-only checks against the views + roll-ups. |
| `run_all.sql` | Sources everything (seed before triggers); restores session state. |

## Schema (20 tables)

```
users ─< coaches ─< members ─< memberships >─ plans
                       │            └─< payments
                       ├─< assessments ─< retention_flags ─< tasks
                       ├─< injury_history
                       ├─< daily_attendance
                       ├─< followups
                       ├─< progress_tracking
                       ├─< workout_plans ─< workout_sessions
                       ├─< nutrition_plans
                       ├─< supplements
                       ├─< messages_log
                       └─< milestones
audit_logs (actor → users)
```

- **users / coaches / members** — staff logins, trainers, and gym clients (rich
  member lifecycle `status`).
- **plans / memberships / payments** — catalog, subscriptions
  (`payment_status`, `renewal_status`), and money in.
- **assessments** — movement screen and `risk_score` / `classification`.
- **injury_history**, **daily_attendance** (`attended`, `attendance_date`),
  **progress_tracking** (`weight`, `body_fat`, `muscle_mass`, …).
- **followups** — outreach with `response_status`.
- **workout_plans / workout_sessions**, **nutrition_plans / supplements** —
  programming.
- **retention_flags** — churn/risk signals (optionally tied to an assessment).
- **tasks** — coach work items, often driven by a flag.
- **messages_log**, **milestones**, **audit_logs**.

## Event-driven logic

**Triggers (`03_triggers.sql`)** — all `AFTER INSERT`:

| Table | Calls | Effect |
| ----- | ----- | ------ |
| `members` | `sp_create_task`, `sp_log_message` | onboarding review task + welcome message |
| `memberships` | `sp_open_onboarding_workflow` | member → `onboarding`, opens review/assessment/renewal tasks |
| `payments` | `sp_handle_payment_event` | `paid` receipt msg / `failed` flag+task / `partial` task |
| `daily_attendance` | `sp_handle_attendance_event` | resolve attendance flags on attend; flag + `at_risk` after 3 absences/7d |
| `assessments` | `sp_handle_assessment_event` | `risk_score` ≥ 80 → corrective+critical; ≥ 60 → at_risk+high |
| `injury_history` | `sp_handle_injury_event` | high/critical → pause plan+member, medical-referral task |
| `progress_tracking` | `sp_handle_progress_event` | >2kg loss → milestone+message; high body-fat → flag |
| `followups` | `sp_handle_followup_event` | `no_response` → retry task; `booked`/`converted` → resolve flags |

**Procedures (`02_procedures.sql`)** — helpers `sp_create_task`,
`sp_create_retention_flag`, `sp_mark_member_status`, `sp_log_message`,
`sp_open_onboarding_workflow`, plus the seven `sp_handle_*_event` orchestrators.

**Event (`04_events.sql`)** — `ev_daily_retention_scan` (daily): expire
past-due memberships and raise `low_attendance` flags for members with no recent
attended visit.

## Views (`05_views.sql`)

Operational: `vw_assessment_summary`, `vw_progress_trends`, `vw_overdue_payments`,
`vw_membership_expiry_soon`, `vw_at_risk_members`, `vw_due_followups`,
`vw_daily_coach_queue`, `vw_member_operational_status`.
Dashboard: `vw_dashboard_kpis`, `vw_dashboard_coach_workload`,
`vw_dashboard_today_actions`, `vw_dashboard_risk_pipeline`, `vw_dashboard_renewals`.

## Verifying the load

```sql
USE xcamp_gym;
SHOW TABLES;                                        -- 20 tables
SHOW PROCEDURE STATUS WHERE Db = 'xcamp_gym';       -- 11 procedures
SHOW TRIGGERS;                                      -- 8 triggers
SHOW EVENTS;                                        -- 1 event
SHOW FULL TABLES WHERE Table_type = 'VIEW';         -- 13 views
```

`07_test_queries.sql` (run automatically by `run_all.sql`) selects from every
dashboard/operational view and rolls up open flags and tasks.
