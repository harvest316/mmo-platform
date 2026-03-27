-- PostgreSQL schema initialization for mmo database
-- Run once after PostgreSQL is set up:
--   psql -d mmo -f ~/code/mmo-platform/db/pg-init-schemas.sql

-- Extensions
CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;

-- Schemas (matching SQLite ATTACH aliases for minimal query changes)
CREATE SCHEMA IF NOT EXISTS m333;      -- 333Method application data (was sites.db)
CREATE SCHEMA IF NOT EXISTS ops;       -- 333Method operations (was ops.db)
CREATE SCHEMA IF NOT EXISTS tel;       -- 333Method telemetry (was telemetry.db)
CREATE SCHEMA IF NOT EXISTS msgs;      -- Shared cross-project messages (was messages.db + suppression.db)
CREATE SCHEMA IF NOT EXISTS twostep;   -- 2Step application data (was 2step.db)
-- CREATE SCHEMA IF NOT EXISTS admanager;  -- Future: AdManager

-- Grant ownership to jason (peer auth user)
ALTER SCHEMA m333 OWNER TO jason;
ALTER SCHEMA ops OWNER TO jason;
ALTER SCHEMA tel OWNER TO jason;
ALTER SCHEMA msgs OWNER TO jason;
ALTER SCHEMA twostep OWNER TO jason;

-- Verify
SELECT schema_name FROM information_schema.schemata
WHERE schema_name IN ('m333', 'ops', 'tel', 'msgs', 'twostep')
ORDER BY schema_name;
