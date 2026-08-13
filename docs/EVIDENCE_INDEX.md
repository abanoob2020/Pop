# Evidence Index (Claim → Source)

> Traceability map: each key claim in this KB → the file:line it was read from. All `[VERIFIED]` unless noted.

## Architecture / stack
| Claim | Source |
|---|---|
| PHP, no framework, `require db.php` per page | every `dashboard/*.php` line 6–7 |
| PDO, ERRMODE_EXCEPTION, FETCH_ASSOC | db.php:59–62 |
| MySQL 8, utf8mb4_unicode_ci, InnoDB | sql/00_init.sql:11–13; sql/01_tables.sql (ENGINE=InnoDB) |
| Env-var config + fallbacks | db.php:54–58 |

## Database
| Claim | Source |
|---|---|
| 20 core tables | sql/01_tables.sql (CREATE TABLE ×20) |
| 28 module tables (48 total) | sql/08–19, 11 (grep CREATE TABLE) |
| 11 procedures | sql/02_procedures.sql:26–217 |
| 8 AFTER INSERT triggers, `@seeding` guard | sql/03_triggers.sql:29–101, header:8–11 |
| `ev_daily_retention_scan` EVERY 1 DAY | sql/04_events.sql:17–39 |
| 13 views | sql/05_views.sql:18–309 |
| Member delete cascades / plan RESTRICT / coach SET NULL | sql/01_tables.sql FK constraints |

## Business rules
| Rule | Source |
|---|---|
| Onboarding 3-task workflow, renewal −7 days | 02_procedures.sql:74–83 |
| Payment paid/failed/partial handling | 02_procedures.sql:85–103 |
| ≥3 absences/7d → at_risk + urgent call | 02_procedures.sql:127–140 |
| Assessment risk ≥80 corrective / ≥60 at_risk | 02_procedures.sql:150–158 |
| Injury high/critical → pause plans + member | 02_procedures.sql:167–172 |
| Weight drop >2kg → milestone; body_fat>30 → flag | 02_procedures.sql:193–201 |
| Daily expire + inactivity flag | 04_events.sql:22–38 |

## Financial
| Formula | Source |
|---|---|
| `monthlyize` = price/days×30 | training.php:720–723 |
| `renewal_likelihood` weights | training.php:729–737 |
| `payment_outstanding` unpaid=full/partial=half | training.php:739–744 |
| `net_profit` = income−expenses | training.php:762 |
| MRR/collected/projected assembly | revenue.php:59–81 |
| P&L income = payments.amount + pos_sales.total | finance.php:113–117 |
| Expense categories | finance.php:15 |
| Commission = base×rate/100, base=Σ member payments | hr.php:21–23,67 |
| Payroll → expenses(salaries) | hr.php:75–76 |
| PT_PRICE=150 | db.php:109 |

## Auth / RBAC / security
| Claim | Source |
|---|---|
| bcrypt `password_verify` | login.php:43 |
| Rate limit 5/900s file-based | login.php:9–11,14–25 |
| `session_regenerate_id(true)` | login.php:62 |
| Session cookie HttpOnly/SameSite/Secure | db.php:9–22 |
| CSRF `hash_equals` | db.php (`csrf_check`) |
| `is_manager` = admin/manager/reception | db.php:81–83 |
| `require_role` redirect to captains | db.php:76–79 |
| Nav manager-only links | db.php:213–229 |
| Coach scope int-cast interpolation | revenue.php:46 |
| Hardcoded DB pass fallback | db.php:58 |
| Displayed default creds | login.php:88–92 |

## Conflicts
| Conflict | Source |
|---|---|
| C-01 greenfield claim | CLAUDE.md (root) vs xcamp-gym-sql/* |
| C-02 collected definitions | revenue.php:71 vs finance.php:113 |
| C-03 ENUM mismatch | 01_tables.sql:98,115 |
| C-04 coaches vs users.role | 01_tables.sql:45–56; login.php:56–59 |
| C-05 financial helpers in training.php | training.php:720–762 |

## Deployment
| Claim | Source |
|---|---|
| Load order + seeding guard | run_all.sql |
| deploy DB_SEED toggle | deploy.sh |
| backup mysqldump.gz + retention | backup.sh |
| cron examples + env-file guidance | backup.cron.example |
| event_scheduler ON requirement | 04_events.sql:11 |
