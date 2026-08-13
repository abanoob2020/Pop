# Security Assessment (Evidence-Based)

> Scope: static reading of the code as-is. No exploitation was performed. Severities are the reviewer's assessment of production risk. `[VERIFIED]` = the code fact; the severity/impact judgement is `[INFERRED]`.
> This is documentation only — **no code was changed**.

## Controls present (strengths) `[VERIFIED]`
| Control | Where |
|---|---|
| Prepared statements (PDO) for user input | all pages (sampled: login, revenue, finance, hr, checkin) |
| Password hashing: bcrypt via `password_verify` | login.php:43 |
| CSRF tokens, constant-time `hash_equals` | db.php `csrf_check` |
| Output encoding `h()` = `htmlspecialchars(ENT_QUOTES,'UTF-8')` | db.php:49, used across templates |
| Login rate limiting (5 / 15 min / IP+email) | login.php:9–11,33–37 |
| Session hardening (HttpOnly, SameSite=Lax, Secure-aware) | db.php:9–22 |
| Session fixation defense (`session_regenerate_id(true)`) | login.php:62 |
| Least-data member portal (queries scoped to session member) | portal.php |
| Coach data scoping with write-path re-check | revenue.php:23–27 |
| Referential integrity + audit_logs table | 01_tables.sql |
| Backups over a temp `--defaults-file` (chmod 600), not CLI password | backup.sh |

## Findings

### S-01 — Hardcoded DB password fallback — **MEDIUM** `[VERIFIED]`
`db.php:58` → `$pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'ChangeThisPass123';`
Also a default `DB_USER` fallback `xcamp_admin` (db.php:57). If the app runs without `DB_PASS` set, it silently uses a known password committed to the repo. **Impact:** predictable credential if env is misconfigured. **Note (do not change here):** should fail closed rather than fall back.

### S-02 — Default seed credentials displayed on the login page — **MEDIUM** `[VERIFIED]`
`login.php:88–92` prints `admin@xcamp.com/admin123` and `coach1@xcamp.com/coach123`. Combined with `setup_logins.sql` seeding those accounts, a deployment that forgets to rotate them is trivially accessible. **Impact:** full admin takeover if seeds survive to production.

### S-03 — `reception` has management privileges — **LOW/MEDIUM (policy)** `[VERIFIED]`
`is_manager()` includes `reception` (db.php:83), granting reception access to finance, analytics, HR (salaries), promos, and all-member data. May exceed intended least privilege. **Impact:** front-desk role sees payroll & financials. Confirm this is intended.

### S-04 — Rate-limit store is shared-temp, file-based — **LOW** `[VERIFIED]`
`login.php` uses `sys_get_temp_dir()/xcamp_login_attempts.json`. Per-host, not shared across app servers; world-readable temp dir on some systems; no lock beyond `LOCK_EX` on write. **Impact:** rate limiting is bypassable in a multi-node deployment and the file is low-integrity. Adequate for single-host.

### S-05 — Event scheduler dependency is silent — **LOW (operational)** `[VERIFIED]`
`ev_daily_retention_scan` only runs if `event_scheduler=ON` (04_events.sql). If disabled, expiry/inactivity automation stops with no visible error. **Impact:** stale member statuses; missed retention flags.

### S-06 — Dynamic SQL string interpolation in scope filter — **LOW** `[VERIFIED]`
`revenue.php:46` builds `' WHERE m.coach_id = ' . $myCoach`. `$myCoach` is `(int)`-cast from session, so not attacker-controlled here — **not currently exploitable**. Flagged as a pattern: string-concatenated SQL relying on an int cast is fragile if the source ever changes. Prefer bound parameters. Similar interpolation appears in finance.php month filters (`$MFILTER_*`) — verify those inputs are equally constrained. `[INFERRED — needs a second pass on finance.php filters]`

### S-07 — `provision_admin.php` reachability — **UNKNOWN → verify** `[UNKNOWN]`
A provisioning endpoint exists (58 lines). Whether it self-disables after first use / requires a secret was **not line-read**. If reachable unauthenticated in production it is a privilege-escalation vector. **Action:** read `provision_admin.php` before sign-off.

### S-08 — Verbose DB error surfaced to UI — **LOW** `[VERIFIED]`
Pages catch DB exceptions and echo `$e->getMessage()` into an error box (e.g. login.php:73, most pages). Can leak schema/host details. **Impact:** information disclosure. Consider generic messages in production.

## Not assessed / out of scope this pass `[UNKNOWN]`
- CSRF coverage on **every** POST handler (sampled, not exhaustive).
- Authorization on member portal write endpoints beyond the read scoping.
- File-upload paths (`photo_ref` exists in progress_tracking) — no upload handler traced.
- Transport/TLS, web-server config, secrets management in deployment.

## Priority for remediation (advisory only — not applied)
1. S-02 (rotate/remove default creds) and S-01 (remove password fallback) — highest.
2. S-07 (verify provisioning endpoint).
3. S-03 (confirm reception privilege policy).
4. S-05 (monitor event scheduler), S-08 (generic errors), S-06/S-04 (hardening).
