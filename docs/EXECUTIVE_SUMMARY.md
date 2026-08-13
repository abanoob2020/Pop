# Executive Summary

> One-page condensation of the Xcamp Gym knowledge base. Evidence-tagged. **Documentation only — no application code was modified.**

## What it is
A **PHP 8 + MySQL 8, single-gym operations platform**: staff dashboard (management/reception/coaches) + member portal. Subscriptions, payments, POS, PT booking, QR check-in, assessments→programming, retention automation, payroll, and BI — server-rendered PHP with **substantial business logic pushed into the database** (11 procedures, 8 triggers, 1 daily event, 13 views). `[VERIFIED]`

## By the numbers `[VERIFIED]`
- ~7,585 PHP lines (29 pages) + ~1,987 SQL lines (20 files).
- 48 tables · 11 procedures · 8 AFTER INSERT triggers · 1 event · 13 views.
- Roles: admin, manager, coach, reception (+ separate member realm). Merged PRs up to #37 (git log).

## The defining design idea
**Event-driven retention.** Inserting a payment/attendance/assessment/injury/progress row fires a trigger → stored procedure that automatically changes member status, raises retention flags, creates prioritized coach tasks, and logs outreach — one source of truth regardless of which page caused the insert. A daily event expires lapsed memberships and flags inactivity. `[VERIFIED — BUSINESS_RULES.md]`

## Financial model `[VERIFIED — FINANCIAL_RULES.md]`
MRR = Σ(price/duration×30) over current contracts; projected revenue = price × a hand-tuned renewal-likelihood; outstanding = full/half by payment status; P&L = (paid payments + POS sales) − expenses, with coach salaries flowing into expenses. Sound and legible; the likelihood weights are heuristic, not statistical.

## Top findings (action items for maintainers — not applied here)
1. **C-01 (critical):** root `CLAUDE.md` wrongly calls the repo "greenfield." Anything trusting it will mis-plan. → CONFLICTS.
2. **S-02 (security, med):** login page shows default admin/coach creds seeded by `setup_logins.sql`; rotate before production.
3. **S-01 (security, med):** `db.php` falls back to a committed DB password `ChangeThisPass123` if `DB_PASS` unset; should fail closed.
4. **S-07 (verify):** `provision_admin.php` not yet audited — potential privilege-escalation surface.
5. **C-02 (data):** two different "collected/income" definitions (plan price vs actual payments) — don't conflate as one KPI.
6. **S-05 (ops):** retention automation silently stops if the MySQL event scheduler is off.

## Strengths `[VERIFIED]`
Prepared statements throughout · bcrypt + CSRF (`hash_equals`) + login rate limiting + hardened sessions · output encoding via `h()` · coach data scoping with write-path re-checks · re-runnable schema with a seeding guard · real backup/restore/cron ops package.

## Confidence
High on schema, stored logic, financial formulas, and auth (files read in full). Medium on the largest leaf modules (`captains.php` 1506 lines, portal write paths, provisioning) — see UNKNOWNS (12 open). No claim in this KB rests on an unread file.

## Next investigation (in priority order)
`provision_admin.php` (S-07) → `captains.php` write paths (U-03) → `finance.php` month-filter inputs (S-06/U-06) → `portal.php` write authorization (U-04) → `audit_logs` write coverage (U-01).
