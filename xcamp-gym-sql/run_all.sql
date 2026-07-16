-- =============================================================================
-- xcamp-gym-sql : run_all.sql
-- -----------------------------------------------------------------------------
-- Loads the full project in order. Run from the mysql client with this file's
-- directory as the working directory (SOURCE uses relative paths):
--
--   cd xcamp-gym-sql
--   mysql -u root -p < run_all.sql
--     -- or, interactively:  mysql -u root -p  then:  SOURCE run_all.sql;
--
-- Session state saved in 00_init.sql is restored at the end.
-- =============================================================================

SOURCE 00_init.sql;
SOURCE 01_tables.sql;
SOURCE 02_procedures.sql;
SOURCE 03_triggers.sql;
SOURCE 04_events.sql;
SOURCE 05_views.sql;
SOURCE 06_seed_data.sql;
SOURCE 07_test_queries.sql;

-- Restore the session settings captured in 00_init.sql.
SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;
SET SQL_MODE = @OLD_SQL_MODE;

SELECT 'xcamp_gym: run_all complete' AS status;
