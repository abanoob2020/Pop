-- =============================================================================
-- xcamp-gym-sql : 00_init.sql
-- -----------------------------------------------------------------------------
-- Creates the xcamp_gym database and prepares the session for a clean load.
-- Session state (FOREIGN_KEY_CHECKS, SQL_MODE) is saved here and restored at
-- the end of run_all.sql.
--
-- Engine target: MySQL 8.0+
-- =============================================================================

CREATE DATABASE IF NOT EXISTS xcamp_gym
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE xcamp_gym;

SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;
SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
