-- Runs once, the first time the database container starts with an empty
-- volume. The image has already created the `atlas` database from
-- POSTGRES_DB; this adds the database the test suite uses.
--
-- atlas_test is separate on purpose. Every test empties the tables it
-- touches, so the suite must never point at the database you work in.
-- See phpunit.pgsql.xml.

CREATE DATABASE atlas_test OWNER atlas;

\connect atlas
CREATE EXTENSION IF NOT EXISTS timescaledb;

\connect atlas_test
CREATE EXTENSION IF NOT EXISTS timescaledb;
