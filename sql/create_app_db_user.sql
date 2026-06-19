-- Least-privilege MySQL user for the Dental Clinic web application.
-- Run once as a DBA/admin account (e.g. root), then set credentials in .env.
--
-- Replace:
--   dental_app               — username
--   CHANGE_ME_STRONG         — strong random password (32+ chars)
--   u373759666_demo_dental   — your database name
--
-- The web app needs only DML on its schema. Schema changes (CREATE/ALTER) run via CLI
-- with an admin account: php migrations/run_schema_migrations.php

CREATE USER IF NOT EXISTS 'dental_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG';

-- Remove any inherited or default privileges before granting the minimum set.
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'dental_app'@'localhost';

GRANT SELECT, INSERT, UPDATE, DELETE
    ON `u373759666_demo_dental`.*
    TO 'dental_app'@'localhost';

-- Explicitly deny dangerous global capabilities (MySQL 8+).
-- Adjust host ('%' or specific IP) if the app server is not on localhost.
-- CREATE USER IF NOT EXISTS 'dental_app'@'10.0.0.5' IDENTIFIED BY 'CHANGE_ME_STRONG';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON `u373759666_demo_dental`.* TO 'dental_app'@'10.0.0.5';

FLUSH PRIVILEGES;

-- Verify (run as admin):
--   SHOW GRANTS FOR 'dental_app'@'localhost';
