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

## Notes
- Enable event scheduler if using events.
- Seed data uses fixed IDs for repeatable installs.
- Triggers are thin and call procedures.
- run_all.sql sets @seeding=1 around 06_seed_data.sql so the triggers skip
  while the fixed-ID seed loads, then unsets it so triggers run normally.
