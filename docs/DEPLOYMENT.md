# Deployment & Operations

> Evidence: `run_all.sql`, `deploy.sh`, `reset_and_deploy.sh`, `update.sh`, `backup.sh`, `restore.sh`, `backup.cron.example`, `sql/00_init.sql`. `[VERIFIED]`

## 1. Configuration (environment variables) `[VERIFIED — scripts + db.php]`
| Var | Default | Used by |
|---|---|---|
| DB_HOST | 127.0.0.1 | app + scripts |
| DB_PORT | 3306 | app + scripts |
| DB_NAME | xcamp_gym | app + scripts |
| DB_USER | root (scripts) / `xcamp_admin` (db.php) | — |
| DB_PASS | empty (scripts) / `ChangeThisPass123` (db.php fallback ⚠) | — |
| DB_SEED | 1 | deploy.sh (0 = skip seed + test queries) |
| SESSION_SECURE | auto | db.php cookie `secure` |
| BACKUP_DIR / BACKUP_KEEP | `./backups` / 14 | backup.sh |
| FORCE | 0 | restore.sh |
| SERVE_HOST / SERVE_PORT | localhost / 8000 | update.sh |

## 2. Schema load order `[VERIFIED — run_all.sql]`
`00_init → 01_tables → 02_procedures → 03_triggers → 04_events → 05_views → (SET @seeding=1) 06_seed_data (SET @seeding=NULL) → 08…19 → 07_test_queries`.
- **Seeding guard:** `@seeding=1` wraps the seed load so triggers don't duplicate the fixed-ID seed rows; unset afterward. `[VERIFIED]`
- Core tables are `DROP;CREATE` (rebuilt); module tables are `CREATE IF NOT EXISTS` (additive). `[VERIFIED]`
- `00_init.sql` saves `FOREIGN_KEY_CHECKS`/`SQL_MODE`; `run_all.sql` restores them at the end (valid because it's one client session). `[VERIFIED]`

## 3. Two ways to load
- **Single session:** `cd xcamp-gym-sql && mysql < run_all.sql` (relative SOURCE paths). `[VERIFIED]`
- **Per-file runner:** `./deploy.sh` — env-configured, colored logging to `logs/`, honors `DB_SEED`, runs each file independently (doesn't rely on the session-state save/restore). `[VERIFIED — deploy.sh]`

## 4. Running the app `[VERIFIED — index.php header, update.sh]`
Dev server: `cd dashboard && DB_USER=… DB_PASS=… php -S 0.0.0.0:8000`. `update.sh` orchestrates: `--deploy` (load schema), `--reset` (drop+reload via reset_and_deploy.sh), `--no-server` (skip serving), default serves on `SERVE_HOST:SERVE_PORT`. Production web server / process manager: **not specified** `[UNKNOWN]`.

## 5. Backup & restore `[VERIFIED]`
- **backup.sh:** requires `mysqldump` + `gzip`; writes credentials to a `mktemp` `--defaults-file` with `chmod 600` (password never on the command line); output `.sql.gz` in `BACKUP_DIR`; prunes to the newest `BACKUP_KEEP` (default 14); `NO_COLOR=1` for clean logs.
- **restore.sh:** restores a chosen `.sql.gz`; destructive, so guarded by `FORCE=1`.
- **cron (backup.cron.example):** daily 02:30 example, plus a cleaner variant sourcing secrets from `/etc/xcamp-gym.env` (chmod 600), and a 6-hourly option. Explicitly warns not to put the DB password in a shared file and that cron doesn't load `~/.bashrc`. `[VERIFIED]`

## 6. Operational preconditions / gotchas `[VERIFIED]`
- **Event scheduler must be ON** for `ev_daily_retention_scan` (`SET GLOBAL event_scheduler=ON` needs privilege). Disabled → retention automation silently stops (SECURITY S-05).
- `deploy.sh` uses `set -Eeuo pipefail` (fails fast). `[VERIFIED]`
- Default script `DB_USER=root` with empty password assumes local trusted MySQL; override for real deployments.
- `logs/` is created by deploy/backup for run + backup logs.

## 7. Suggested production checklist (advisory — not applied)
1. Set real `DB_USER`/`DB_PASS` env (remove reliance on db.php fallback — S-01).
2. Run `setup_logins.sql`, then **rotate** the demo admin/coach passwords (S-02).
3. Deploy with `DB_SEED=0` for a clean production dataset.
4. Enable the MySQL event scheduler (S-05).
5. Front with a real web server over TLS; set `SESSION_SECURE=1` behind a TLS proxy.
6. Schedule `backup.sh` via cron with secrets in a chmod-600 env file; test `restore.sh`.
