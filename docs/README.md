# Xcamp Gym — Knowledge Base (Evidence-Extracted)

> **Single Source of Truth** produced by a repository knowledge-extraction pass over `xcamp-gym-sql/`.
> **Documentation only — the application was not modified.** Every claim is tagged `[VERIFIED]` (read directly from code), `[INFERRED]` (logical deduction from evidence), `[UNKNOWN]` (insufficient evidence), or `[CONFLICTING]`.

## What this system is (one line)
A **PHP 8 + MySQL 8 gym / coaching operations platform** — staff dashboard + member portal — with subscriptions, payments, POS, PT booking, QR check-in, assessments→program generation, retention automation (DB triggers), payroll, and BI analytics. `[VERIFIED — git log + code]`

## ⚠️ Top-level conflict (read first)
- **`CLAUDE.md` (repo root) states the project is "greenfield… only a README.md and no source code."** This is **false / stale**: the repo contains a full application (`xcamp-gym-sql/`, ~7,585 PHP + ~1,987 SQL lines, merged PRs up to #37). → see `CONFLICTS.md` C-01. `[CONFLICTING]`
- **The prior chat analysis (Excel exports of a real gym, 446 members) is NOT this codebase's data.** This app ships with **seed/test data** (`06_seed_data.sql`, fixed IDs). The two are unrelated artifacts. `[VERIFIED]`

## Document index
| Doc | Contents |
|---|---|
| `SYSTEM_OVERVIEW.md` | purpose, users, modules, core workflows |
| `ARCHITECTURE.md` | layers, runtime, mermaid diagram |
| `DATABASE.md` | 48 tables, 11 procedures, 8 triggers, 1 event, views + data dictionary |
| `BUSINESS_RULES.md` | event-driven automation rules (triggers→procedures) |
| `FINANCIAL_RULES.md` | revenue/MRR/collected/projected/outstanding, POS, expenses |
| `AUTH_RBAC.md` | authentication + role/permission matrix |
| `SECURITY.md` | findings (CSRF/XSS/SQLi/session/secrets) with severity |
| `FILE_INVENTORY.md` | file map + criticality tiers |
| `DEPLOYMENT.md` | deploy/backup/restore/update scripts, cron |
| `UNKNOWNS.md` | evidence gaps |
| `CONFLICTS.md` | contradictions between docs/comments/code |
| `EVIDENCE_INDEX.md` | claim → source file:line |
| `EXECUTIVE_SUMMARY.md` | condensed findings + next investigation |

## DOCUMENTATION COMPLETENESS
Method: coverage = files/areas **read in full or grep-enumerated with evidence** ÷ total in scope. Not averaged to hide gaps.

| Area | Coverage | Basis |
|---|---|---|
| Repository discovery | **100%** | `git ls-files`, `wc -l` all files; counts self-audited |
| Database schema | **~92%** | 48 `CREATE TABLE` enumerated; 20 core read in full w/ FKs; module column bodies partly line-read |
| Stored logic (proc/trig/view/event) | **~98%** | 02_procedures, 03_triggers, 04_events, 05_views **all read in full** |
| Business logic | **~90%** | event-driven rules fully verified; PHP module write-paths partly read |
| Financial logic | **~95%** | all helper formulas (training.php) + revenue.php + finance.php income/P&L read in full |
| Auth & RBAC | **~95%** | db.php + login.php read in full; member_login not line-read |
| Security | **~80%** | core controls verified; per-page input validation sampled; provision_admin unaudited |
| PHP feature modules (captains/portal/etc.) | **~40%** | listed + purpose from headers; largest files (captains.php 1506) not line-read |
| Traceability | **~90%** | key rules traced rule→code→table in `EVIDENCE_INDEX.md` |

**Unknowns identified:** see `UNKNOWNS.md` (12). **Conflicts identified:** see `CONFLICTS.md` (5, one critical). **Critical findings:** see `EXECUTIVE_SUMMARY.md`.

> **Self-audit note:** headline object counts (29 PHP files · 48 tables · 11 procedures · 8 triggers · 1 event · 13 views) were re-verified with `grep -c` against source after drafting. The two files still <50% covered (`captains.php`, `provision_admin.php`) are the first targets for a second reading pass.
