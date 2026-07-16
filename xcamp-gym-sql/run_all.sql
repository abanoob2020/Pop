-- Load order 00 -> 07. @seeding is set around the seed so the AFTER-INSERT
-- triggers (created in 03) skip while the curated, explicitly-keyed seed loads,
-- then unset so triggers behave normally at runtime.
SOURCE 00_init.sql;
SOURCE 01_tables.sql;
SOURCE 02_procedures.sql;
SOURCE 03_triggers.sql;
SOURCE 04_events.sql;
SOURCE 05_views.sql;
SET @seeding = 1;
SOURCE 06_seed_data.sql;
SET @seeding = NULL;
SOURCE 07_test_queries.sql;
