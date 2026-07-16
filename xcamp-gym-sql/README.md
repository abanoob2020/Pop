# Xcamp Gym SQL

## Execution order
1. 00_init.sql
2. 01_tables.sql
3. 02_procedures.sql
4. 03_triggers.sql
5. 04_events.sql
6. 05_views.sql
7. 06_seed_data.sql
8. 07_test_queries.sql

## Run
MySQL CLI:
mysql < run_all.sql

Or inside mysql shell:
SOURCE run_all.sql;

## Deploy scripts
- `deploy.sh` — runs the 8 SQL files in order against a server over TCP.
  Config via env: `DB_HOST` (127.0.0.1), `DB_PORT` (3306), `DB_USER` (root),
  `DB_PASS` (empty), `DB_NAME` (xcamp_gym). Logs to `logs/deploy.log`.
- `reset_and_deploy.sh` — drops and recreates `DB_NAME`, then calls `deploy.sh`.

Example:
```
DB_USER=root DB_PASS='your_password' ./reset_and_deploy.sh
```

## Notes
- Enable event scheduler if using events.
- Seed data uses fixed IDs for repeatable installs.
- Triggers are thin and call procedures.
- 06_seed_data.sql sets @seeding=1 while it loads (and resets it at the end)
  so the triggers skip during seeding; this works under run_all.sql, deploy.sh,
  or a manual load.

## ملاحظات مهمة
- `deploy.sh` يتأكد أولًا من وجود كل ملفات SQL قبل التنفيذ.
- `reset_and_deploy.sh` يحذف القاعدة ويعيد إنشاءها ثم يستدعي `deploy.sh`.
- إن لم ترد ألوانًا، استخدم `NO_COLOR=1`.
- إن لم ترد كلمة المرور في الأمر، استخدم `mysql_config_editor` أو ملف إعدادات محلي (مثل `~/.my.cnf`).
