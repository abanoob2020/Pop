# Unknowns (Evidence Gaps)

> Items that could **not** be confirmed from the files read this pass. Each says what evidence would close it. Nothing here is guessed.

| # | Unknown | Why unresolved | How to close |
|---|---|---|---|
| U-01 | Does the app actually write `audit_logs`, and on which actions? | Table + ENUM (`login/logout/export/…`) exist, but no INSERT into `audit_logs` was traced in the sampled PHP. | grep all PHP for `audit_logs`; read logout.php and export paths. |
| U-02 | `provision_admin.php` behavior & reachability | 58-line file not line-read; security-relevant. | Read `provision_admin.php` fully (SECURITY S-07). |
| U-03 | `captains.php` write-path authorization details | Largest file (1506 lines), only header + guard read. | Full read of program/nutrition/progress POST handlers. |
| U-04 | Member portal write authorization (self-log, renewal request) | Read scoping confirmed; write-side ownership checks not exhaustively verified. | Read portal.php POST handlers in full. |
| U-05 | Who sets member status `upgraded`? | ENUM value exists; no procedure/trigger sets it. | grep PHP for `'upgraded'`; likely a manual/CRM action. |
| U-06 | Finance month filters `$MFILTER_PAY/$MFILTER_POS` construction | Referenced in finance.php:113–125; interpolated into SQL. | Read finance.php:100–130 to confirm inputs are constrained (SECURITY S-06). |
| U-07 | Discount code full validation (limits, expiry, per-user) | `discount_evaluate` only partially read. | Read db.php:156–210 fully. |
| U-08 | Assessment scoring: how `risk_score`/`classification` are computed | Consumed by triggers; computation likely in assess.php, not read. | Read assess.php scoring section. |
| U-09 | Views vs `coaches` table population | Views JOIN `coaches`; `coaches.user_id`→`users`. Relationship of `users.role='coach'` to `coaches` rows confirmed at login, but seed coverage unknown. | Read 06_seed_data.sql + setup_logins.sql. |
| U-10 | Nutrition v2 / recipes / training_max module internals | Enumerated (tables) but bodies/columns not line-read. | Read 09,18,19 + recipes.php/portal nutrition. |
| U-11 | Production runtime (web server, PHP-FPM, process mgr) | Only dev `php -S` documented. | Deployment docs/infra outside repo. |
| U-12 | Test coverage / CI | `07_test_queries.sql` is smoke SQL; no PHP tests or CI config found. | grep for `.github/`, phpunit, etc. (none seen). |

**Count: 12 open unknowns.** None blocks the high-confidence claims in DATABASE/BUSINESS_RULES/FINANCIAL_RULES/AUTH_RBAC; all concern depth in leaf modules or operational context.
