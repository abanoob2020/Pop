# Production Database Privileges — Xcamp Gym

## Accounts

| Account | Purpose | Default state |
|---------|---------|---------------|
| `xcamp_app` | PHP application runtime | DML only — always active |
| `xcamp_deploy` | Schema migrations | **DML only** — DDL granted only during maintenance, revoked after |
| `xcamp_backup` | Backups (mysqldump) | Read-only — always active |

## 1. Create accounts (DML-only by default)

```sql
-- Application account (least privilege)
CREATE USER 'xcamp_app'@'%' IDENTIFIED BY '<strong-password>';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE
  ON xcamp_gym.* TO 'xcamp_app'@'%';

-- Deploy account (starts with DML only — DDL granted per maintenance window)
CREATE USER 'xcamp_deploy'@'%' IDENTIFIED BY '<strong-password>';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE
  ON xcamp_gym.* TO 'xcamp_deploy'@'%';

-- Backup account (read-only + LOCK for consistent dumps)
CREATE USER 'xcamp_backup'@'%' IDENTIFIED BY '<strong-password>';
GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER
  ON xcamp_gym.* TO 'xcamp_backup'@'%';

FLUSH PRIVILEGES;
```

**xcamp_deploy has NO DDL privileges at rest.** It cannot DROP, CREATE,
or ALTER tables outside a maintenance window.

## 2. PHP application config

Set in the environment (`.env` or systemd unit):
```
DB_USER=xcamp_app
DB_PASS=<app-password>
```

The application account **cannot** DROP, CREATE, or ALTER tables. A SQL
injection exploit is limited to data operations — it cannot destroy schema.

## 3. Maintenance window procedure

### Open the window

```sql
-- Step 1: Grant DDL to the deploy account (run as DBA / root)
GRANT CREATE, ALTER, DROP, INDEX, CREATE ROUTINE, ALTER ROUTINE, TRIGGER, EVENT
  ON xcamp_gym.* TO 'xcamp_deploy'@'%';
FLUSH PRIVILEGES;
```

### Run migrations

```bash
# Step 2: Backup (uses read-only account)
DB_USER=xcamp_backup DB_PASS='***' ./backup.sh

# Step 3: Apply migrations (deploy account now has DDL)
DB_USER=xcamp_deploy DB_PASS='***' ./deploy.sh --migrate

# Step 4: Reparenting check
DB_USER=xcamp_backup DB_PASS='***' mysql xcamp_gym < sql/check_reparenting.sql
```

### Close the window

```sql
-- Step 5: REVOKE DDL immediately after migration (run as DBA / root)
REVOKE CREATE, ALTER, DROP, INDEX, CREATE ROUTINE, ALTER ROUTINE, TRIGGER, EVENT
  ON xcamp_gym.* FROM 'xcamp_deploy'@'%';
FLUSH PRIVILEGES;
```

**This step is not optional.** The deploy account must return to DML-only
after every maintenance window.

### If migration fails

```bash
# Restore from backup (requires DDL — do this BEFORE revoking)
DB_USER=xcamp_deploy DB_PASS='***' ./restore.sh --latest --force
# Then revoke DDL as in step 5
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

-- Confirm deploy account has NO DDL at rest
SHOW GRANTS FOR 'xcamp_deploy'@'%';
-- Expected: GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON `xcamp_gym`.* ...
-- NOT expected: CREATE, ALTER, DROP (if present, run the REVOKE from §3 step 5)

-- Negative test: deploy.sh --migrate with DML-only account should fail on
-- any file that attempts DDL (CREATE TABLE, DROP PROCEDURE, etc.)
-- This confirms the privilege boundary actually works.
```

## 6. Emergency: block all deployments

If the deploy account is compromised or deployments must be stopped immediately:

```sql
-- Revoke even DML if needed
REVOKE ALL PRIVILEGES ON xcamp_gym.* FROM 'xcamp_deploy'@'%';
FLUSH PRIVILEGES;
```

Re-grant DML with `GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE` when ready.
