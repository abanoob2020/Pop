SOURCE 00_init.sql;
SOURCE 01_tables.sql;
SOURCE 02_procedures.sql;
SOURCE 03_triggers.sql;
SOURCE 04_events.sql;
SOURCE 05_views.sql;
SOURCE 06_seed_data.sql;
SOURCE 07_test_queries.sql;

-- Restore the session state saved in 00_init.sql (valid here because run_all
-- runs as a single client session; per-file runners like deploy.sh don't need it).
SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;
SET SQL_MODE = @OLD_SQL_MODE;
