# System Overview

> Tags: `[VERIFIED]` read from code · `[INFERRED]` deduced · `[UNKNOWN]` no evidence · `[CONFLICTING]`

## 1. Purpose
Xcamp Gym is a **PHP 8 + MySQL 8 operations platform for a single gym / personal-coaching business**. It combines a **staff dashboard** (management, reception, coaches) with a **member self-service portal**. `[VERIFIED — dashboard/*.php headers, sql/*.sql]`

The system is not a generic CRM: its core value is **event-driven retention automation** — database triggers fire stored procedures that create tasks, raise retention flags, change member status, and log outreach messages automatically as payments, attendance, assessments, injuries and progress records are inserted. `[VERIFIED — sql/02_procedures.sql, sql/03_triggers.sql]`

## 2. Actor types
| Actor | Auth surface | Evidence |
|---|---|---|
| admin / manager / reception ("management") | staff login (`login.php`), `users` table | `[VERIFIED]` db.php:83 `is_manager()` = admin/manager/reception |
| coach (captain) | staff login, scoped to own members | `[VERIFIED]` captains.php, crm.php `$isCoach` |
| member | separate portal login (`member_login.php`), `member_auth` table | `[VERIFIED]` portal.php `require_member()`, sql/10_member_portal.sql |

## 3. Functional modules (by dashboard page)
`[VERIFIED — file header comments]`

| Page | Module | Audience |
|---|---|---|
| `index.php` | Management dashboard: KPIs, tasks, at-risk members, renewals | admin/manager/reception |
| `crm.php` | Member lifecycle: funnel + pipeline + follow-ups + renewals | all staff (coach scoped) |
| `retention.php` | Retention / anti-churn: risk interventions, win-back, churn analysis | all staff (coach scoped) |
| `captains.php` | Coach workspace: workout programs + nutrition + progress per member | coach (manager browses all) |
| `assess.php` / `assessment_print.php` | Full member assessment (PAR-Q, lifestyle, body composition), printable | all staff (coach scoped) |
| `training.php` | Load-intelligence pure functions (1RM Epley, training zones, progressive overload, plateau) | library (no DB) |
| `progression.php` | Progression tracking UI | staff |
| `templates.php` | Program templates | staff |
| `recipes.php` | Recipe library + portion macro calculator | staff |
| `pt.php` | Personal-training session booking (derived from coach shifts) | coach (manager browses) |
| `checkin.php` | Reception/kiosk QR + phone check-in → attendance | staff |
| `qr.php` | Pure-PHP QR code generator (byte mode, versions 1–4) | library |
| `finance.php` | Accounting + POS: P&L, point-of-sale w/ inventory, payments, expenses | admin/manager/reception |
| `revenue.php` | Revenue analytics: MRR, collected, projected, outstanding | staff (coach scoped) |
| `analytics.php` | Executive BI: growth, revenue, activity, coach performance trends | admin/manager/reception |
| `hr.php` | Coach HR: weekly shifts, payroll/commission, member capacity | admin/manager/reception |
| `promos.php` | Referrals + discount codes | admin/manager/reception |
| `portal.php` | Member portal: program/nutrition/progress, self-log, renewal request | member |
| `account.php` | Staff account settings | staff |
| `calendar.php`, `session.php`, `athlete.php` | Scheduling / session detail / athlete view | staff |
| `provision_admin.php` | One-time admin provisioning | setup |

## 4. Core end-to-end workflows `[VERIFIED — triggers + procedures]`
1. **New member** → insert `members` → trigger creates a manager-review task + welcome WhatsApp log; on first membership insert, status `new→onboarding` and a 3-task onboarding workflow opens (review, first assessment, renewal reminder 7 days before expiry).
2. **Payment** → insert `payments` → `sp_handle_payment_event`: `paid` marks membership paid + thank-you message; `failed` raises a high flag + urgent follow-up task; `partial` raises a high follow-up task.
3. **Attendance** → insert `daily_attendance` → attended reactivates/advances status and resolves attendance flags; ≥3 absences in 7 days raises a high `low_attendance` flag + urgent call task + status `at_risk`.
4. **Assessment** → insert `assessments` → risk ≥80 sets `corrective` + critical flag; ≥60 sets `at_risk` + high flag + program-update task.
5. **Injury** (high/critical) → pauses active workout plans, sets member `paused`, raises flag + urgent medical-referral task.
6. **Daily scan** (`ev_daily_retention_scan`) → expires lapsed memberships, flags members with no attendance in 7 days.

## 5. What this system is NOT
- Not multi-branch / multi-tenant — no tenant/branch key anywhere in the schema. `[VERIFIED — grep of 01_tables.sql + module tables]`
- Not connected to the prior Excel gym analysis (446 members). This app ships seed/test data (`06_seed_data.sql`, fixed IDs). `[VERIFIED]`
- Not the "greenfield / README-only" repo that root `CLAUDE.md` describes — see `CONFLICTS.md` C-01. `[CONFLICTING]`
