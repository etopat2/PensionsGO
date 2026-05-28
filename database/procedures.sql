-- Pension Workflow System Stored Routines
-- Rerunnable / idempotent routine bundle
--
-- Notes for maintainers:
-- 1) As of the current application state, no persistent stored procedures,
--    functions, triggers, or events are required by the app runtime.
-- 2) The application relies on PHP-side ensure/repair logic instead of
--    database-resident business routines.
-- 3) This file is intentionally rerunnable and acts as the reserved location
--    for future durable routines if the project introduces them later.
-- 4) When adding routines here, use the same pattern:
--      DROP ... IF EXISTS
--      DELIMITER $$
--      CREATE ...
--      DELIMITER ;

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

CREATE DATABASE IF NOT EXISTS `pension_db`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;
USE `pension_db`;

-- -------------------------------------------------------------------
-- No persistent stored routines are currently required by PensionApp.
-- This script is intentionally a no-op so it can be executed safely
-- during bootstrap or repeated migration runs without side effects.
-- -------------------------------------------------------------------

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
