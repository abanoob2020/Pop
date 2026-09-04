# Production Database Privileges — Xcamp Gym

## Accounts

| Account | Purpose | When active |
|---------|---------|-------------|
| `xcamp_app` | PHP application runtime | Always |
| `xcamp_deploy` | Schema migrations | Maintenance window only |
| `xcamp_backup` | Backups (mysqldump) | Cron / on-demand |

## 1. Create accounts

```sql
-- Application account (least privilege)
CREATE USER 'xcamp_app'@'%' IDENTIFIED BY '<strong-password>';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE
  ON xcamp_gym.* TO 'xcamp_app'@'%';

-- Deploy account (DDL capable, used only during maintenance)
CREATE USER 'xcamp_deploy'@'%' IDENTIFIED BY '<strong-password>';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE,
      CREATE, ALTER, DROP, INDEX, CREATE ROUTINE, ALTER ROUTINE, TRIGGER, EVENT
  ON xcamp_gym.* TO 'xcamp_deploy'@'%';

-- Backup account (read-only + LOCK for consistent dumps)
CREATE USER 'xcamp_backup'@'%' IDENTIFIED BY '<strong-password>';
GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER
  ON xcamp_gym.* TO 'xcamp_backup'@'%';

FLUSH PRIVILEGES;
```

## 2. PHP application config

Set in the environment (`.env` or systemd unit):
```
DB_USER=xcamp_app
DB_PASS=<app-password>
```

The application account **cannot** DROP, CREATE, or ALTER tables. A SQL
injection exploit is limited to data operations — it cannot destroy schema.

## 3. Maintenance window procedure

### Before migration

```bash
# 1. Announce maintenance window
# 2. Run backup
DB_USER=xcamp_backup DB_PASS='***' ./backup.sh

# 3. Run migrations with deploy account
DB_USER=xcamp_deploy DB_PASS='***' ./deploy.sh --migrate

# 4. Run reparenting check
DB_USER=xcamp_backup DB_PASS='***' mysql xcamp_gym < sql/check_reparenting.sql
```

### If migration fails

```bash
# Restore from backup
DB_USER=xcamp_deploy DB_PASS='***' ./restore.sh --latest --force
```

## 4. Cloud SQL notes

- Cloud SQL does **not** support `SUPER` privilege. Use `cloudsqlsuperuser`
  role or Cloud SQL Admin API for `SET GLOBAL event_scheduler`.
- Cloud SQL enforces SSL by default — add `--ssl-mode=REQUIRED` or configure
  `require_secure_transport` in the instance flags.
- For Cloud SQL Auth Proxy, accounts connect via `127.0.0.1` — adjust the
  `@'%'` host specifier if restricting by source IP.

## 5. Verification

```sql
-- Confirm app account has no DDL
SHOW GRANTS FOR 'xcamp_app'@'%';
-- Expected: GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON `xcamp_gym`.* ...

-- Confirm deploy account has DDL
SHOW GRANTS FOR 'xcamp_deploy'@'%';
-- Expected: ... CREATE, ALTER, DROP, INDEX, CREATE ROUTINE, ...
```

## 6. Emergency: revoke deploy access

If the deploy account is compromised or a deployment must be blocked:

```sql
REVOKE CREATE, ALTER, DROP, INDEX, CREATE ROUTINE, ALTER ROUTINE, TRIGGER, EVENT
  ON xcamp_gym.* FROM 'xcamp_deploy'@'%';
FLUSH PRIVILEGES;
```

Re-grant with the full `GRANT` statement from section 1 when ready.
