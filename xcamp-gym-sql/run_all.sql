-- Run from the project root (xcamp-gym-sql/) so the relative SOURCE paths
-- resolve:  cd xcamp-gym-sql && mysql < run_all.sql
SOURCE sql/00_init.sql;
SOURCE sql/01_tables.sql;
SOURCE sql/02_procedures.sql;
SOURCE sql/03_triggers.sql;
SOURCE sql/04_events.sql;
SOURCE sql/05_views.sql;
-- Skip trigger side effects while the fixed-ID seed loads, then re-enable.
SET @seeding = 1;
SOURCE sql/06_seed_data.sql;
SET @seeding = NULL;
SOURCE sql/07_test_queries.sql;

-- Restore the session state saved in 00_init.sql (valid here because run_all
-- runs as a single client session; per-file runners like deploy.sh don't need it).
SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;
SET SQL_MODE = @OLD_SQL_MODE;
