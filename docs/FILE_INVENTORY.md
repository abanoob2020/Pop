# File Inventory & Criticality

> Counts from `wc -l` / `git ls-files`. `[VERIFIED]`
> Criticality tiers: **T1** = core infra / auth / money (break = system down or data/financial risk); **T2** = major feature; **T3** = supporting/leaf.

## Repository layout
```
Pop/
├── CLAUDE.md            ⚠ stale "greenfield" claim (CONFLICTS C-01)
├── README.md
├── docs/                ← this knowledge base (documentation only)
└── xcamp-gym-sql/
    ├── run_all.sql      single-session loader
    ├── deploy.sh reset_and_deploy.sh update.sh backup.sh restore.sh
    ├── backup.cron.example
    ├── README.md
    ├── sql/   (20 files, ~1,987 lines)
    └── dashboard/ (29 PHP + 2 setup SQL, ~7,585 PHP lines)
```

## PHP (dashboard/) — by size & tier `[VERIFIED — wc -l]`
| Lines | File | Tier | Role |
|---:|---|:--:|---|
| 474 | db.php | **T1** | connection, auth, CSRF, session, shared UI, helpers |
| 96 | login.php | **T1** | staff auth + rate limit |
| 77 | member_login.php | **T1** | member auth |
| 294 | finance.php | **T1** | P&L, POS, expenses (money) |
| 188 | revenue.php | **T1** | MRR/collected/projected/outstanding |
| 260 | hr.php | **T1** | payroll/commission → expenses |
| 1506 | captains.php | **T2** | coach workspace: programs/nutrition/progress |
| 764 | training.php | **T2** | pure functions: 1RM, zones, overload, plateau, **money/finance helpers** (T1 logic) |
| 456 | assess.php | **T2** | member assessment capture |
| 442 | portal.php | **T2** | member self-service portal |
| 266 | assessment_print.php | T3 | printable assessment |
| 254 | crm.php | **T2** | lifecycle funnel/pipeline |
| 247 | qr.php | **T2** | pure-PHP QR generator |
| 237 | progression.php | T2 | adaptive progression |
| 230 | recipes.php | T2 | recipes + portion macros |
| 220 | pt.php | **T2** | PT booking from shifts |
| 216 | retention.php | **T2** | anti-churn interventions |
| 200 | session.php | T3 | session detail |
| 195 | templates.php | T2 | program templates |
| 167 | athlete.php / promos.php | T2/T3 | athlete view / referrals+codes |
| 158 | analytics.php | T2 | executive BI |
| 142 | index.php | **T1** | management dashboard (KPIs) |
| 108 | checkin.php | T2 | QR/phone attendance |
| 93 | calendar.php | T3 | scheduling |
| 58 | provision_admin.php | **T1** | admin provisioning (⚠ verify — SECURITY S-07) |
| 55 | account.php | T3 | staff account |
| 5–10 | logout.php, member_logout.php | T3 | session teardown |
| — | setup_logins.sql, setup_member_logins.sql | T3 | seed login accounts |

## SQL (sql/) — by load order `[VERIFIED]`
| File | Tier | Contents |
|---|:--:|---|
| 00_init.sql | **T1** | DB create, session state |
| 01_tables.sql | **T1** | 20 core tables |
| 02_procedures.sql | **T1** | 11 procedures (business logic) |
| 03_triggers.sql | **T1** | 8 triggers |
| 04_events.sql | T2 | daily retention scan |
| 05_views.sql | T2 | 13 reporting views |
| 06_seed_data.sql | T3 | fixed-ID demo seed |
| 07_test_queries.sql | T3 | smoke queries |
| 08–19 | T2 | feature modules (workout v2, nutrition v2, portal, finance/POS, QR, coach HR, PT, referrals, assessments, clinical, recipes, training max) |

## Ops scripts `[VERIFIED]`
| Script | Tier | Purpose |
|---|:--:|---|
| deploy.sh | **T1** | idempotent per-file load (env-configured, `DB_SEED` toggle) |
| reset_and_deploy.sh | T2 | drop+reload |
| update.sh | T2 | deploy/reset/serve orchestrator (`--deploy/--reset/--no-server`) |
| backup.sh | **T1** | mysqldump.gz + retention (`BACKUP_KEEP=14`) |
| restore.sh | **T1** | restore from gz (guarded by `FORCE`) |
| backup.cron.example | T3 | cron templates + env-file guidance |

## Highest-priority files to fully audit next
`captains.php` (1506, largest, coach write paths), `training.php` (mixes financial helpers with training math — T1 logic in a T2-named file), `finance.php` (money + dynamic filters), `provision_admin.php` (security), `portal.php` (member write authorization).
