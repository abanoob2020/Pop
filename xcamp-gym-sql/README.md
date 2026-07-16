# xcamp-gym-sql

A self-contained **MySQL 8.0+** database for a gym / personal-coaching business:
members, coaches, plans & memberships, payments, attendance, assessments,
workout & nutrition programming, retention/CRM, and an audit trail — plus the
stored procedures, triggers, scheduled events, and reporting views that drive it.

## Requirements

- **MySQL 8.0+** (uses `CHECK` constraints, `JSON` columns, the event scheduler).
- A client that supports `SOURCE` (the standard `mysql` CLI) to run `run_all.sql`.
- To let scheduled events fire, the **event scheduler** must be on:
  ```sql
  SET GLOBAL event_scheduler = ON;   -- needs SUPER / SYSTEM_VARIABLES_ADMIN
  ```
  `04_events.sql` attempts this automatically; on managed hosts where the
  privilege isn't granted, the events are still created and will run once the
  scheduler is enabled by an admin.

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

`run_all.sql` runs every file in order and then restores the session's
`FOREIGN_KEY_CHECKS` / `SQL_MODE`. The scripts are re-runnable: objects are
dropped-then-created and seed data is cleared before re-insertion.

## File layout

| File | Purpose |
| ---- | ------- |
| `00_init.sql` | Create the `xcamp_gym` database (utf8mb4), save & set session `FOREIGN_KEY_CHECKS` / `SQL_MODE`. |
| `01_tables.sql` | All 20 tables (InnoDB, foreign keys, indexes, `CHECK` constraints). |
| `02_procedures.sql` | Business workflows as stored procedures. |
| `03_triggers.sql` | Defaults + audit logging via triggers. |
| `04_events.sql` | Scheduled housekeeping/automation events. |
| `05_views.sql` | Reporting / convenience views. |
| `06_seed_data.sql` | Deterministic sample data across every table. |
| `07_test_queries.sql` | Labelled read-only checks + procedure demos. |
| `run_all.sql` | Sources everything in order; restores session state. |

## Schema overview

```
users ─< coaches ─< members ─< memberships >─ plans
                       │            │
                       │            └─< payments
                       ├─< assessments ─< retention_flags ─< tasks
                       ├─< injury_history     (tasks also → members, coaches)
                       ├─< daily_attendance
                       ├─< followups
                       ├─< progress_tracking
                       ├─< workout_plans ─< workout_sessions
                       ├─< nutrition_plans
                       ├─< supplements
                       ├─< messages_log
                       └─< milestones
audit_logs (written by triggers; actor → users)
```

### Tables

- **users** — staff/auth accounts (admin, manager, coach, reception).
- **coaches** — trainers, optionally linked to a `users` login.
- **members** — gym clients, with a rich lifecycle `status`.
- **plans** — membership catalog (price, duration, type).
- **memberships** — a member's subscription to a plan (start/end, status, sessions).
- **payments** — money in, linked to member (and optionally membership).
- **assessments** — body/fitness measurements over time.
- **injury_history** — injuries and resolution.
- **daily_attendance** — check-ins (one row per member per day).
- **followups** — coach/reception outreach and outcomes.
- **progress_tracking** — self/coach-logged measurements.
- **workout_plans / workout_sessions** — training programming and logged sessions.
- **nutrition_plans / supplements** — diet programming and supplement stacks.
- **retention_flags** — churn/risk signals.
- **tasks** — internal ops/CRM to-dos.
- **messages_log** — communication history (SMS/WhatsApp/email/app).
- **milestones** — member achievements.
- **audit_logs** — change trail written by triggers.

## Stored procedures (`02_procedures.sql`)

- `sp_register_member(...)` — create a member + first membership + payment atomically (`OUT` new member id).
- `sp_renew_membership(member_id, plan_id, price, method)` — extend from the latest end date + payment.
- `sp_record_payment(member_id, membership_id, amount, method, reference)`.
- `sp_check_in(member_id, source)` / `sp_check_out(member_id)`.
- `sp_freeze_membership(member_id)`.
- `sp_flag_member(member_id, flag_type, severity, reason)`.
- `sp_complete_followup(followup_id, outcome)`.

Validation errors are raised with `SIGNAL SQLSTATE '45000'`.

## Triggers (`03_triggers.sql`)

- Default `memberships.end_date` from the plan duration.
- Keep `members.status` in sync when a membership is created.
- Write `audit_logs` rows on payment inserts and on membership/member status changes.
- Default `daily_attendance.attend_date` from the check-in time.

## Events (`04_events.sql`)

- `ev_expire_memberships` (daily) — expire past-due memberships and cascade member status.
- `ev_flag_inactive_members` (daily) — flag members with no attendance in 14 days.
- `ev_close_stale_followups` (daily) — overdue pending follow-ups → `missed`.
- `ev_purge_old_messages` (monthly) — delete `messages_log` older than 12 months.

## Views (`05_views.sql`)

`vw_active_members`, `vw_expiring_memberships`, `vw_member_attendance_30d`,
`vw_revenue_by_month`, `vw_coach_roster`, `vw_at_risk_members`, `vw_open_followups`.

## Verifying the load

```sql
USE xcamp_gym;
SHOW TABLES;                                        -- 20 tables
SHOW PROCEDURE STATUS WHERE Db = 'xcamp_gym';       -- 8 procedures
SHOW TRIGGERS;                                      -- 6 triggers
SHOW EVENTS;                                        -- 4 events
SHOW FULL TABLES WHERE Table_type = 'VIEW';         -- 7 views
```

`07_test_queries.sql` (run automatically by `run_all.sql`) exercises each view
and the key procedures.
