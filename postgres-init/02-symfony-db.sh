#!/bin/bash
set -euo pipefail

# This runs only on first init of the Postgres volume.
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
DO \$\$
BEGIN
   IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${POSTGRES_USER_SYM}') THEN
      CREATE ROLE "${POSTGRES_USER_SYM}" LOGIN PASSWORD '${POSTGRES_PASSWORD_SYM}';
   END IF;
END
\$\$;

DO \$\$
BEGIN
   IF NOT EXISTS (SELECT FROM pg_database WHERE datname = '${POSTGRES_DB_SYM}') THEN
      CREATE DATABASE "${POSTGRES_DB_SYM}" OWNER "${POSTGRES_USER_SYM}";
   END IF;
END
\$\$;
EOSQL
