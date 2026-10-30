#!/bin/sh
# Runs once, on the first start of an empty data volume (docker-entrypoint-initdb.d),
# and in CI against the pgvector service container (see .github/workflows/ci.yml).
#
# Product neutral: every name derives from POSTGRES_DB / POSTGRES_USER.
#   <db>        dev database        (web: public, worker: worker + rag)
#   <db>_test   Pest / vitest database
#   <db>_e2e    Playwright database (recreated by e2e/global-setup.ts on every run)
# Each gets the extensions vector, pg_trgm, unaccent and the service schemas rag + worker.
# EXTRA_DB_SUFFIXES (default "test e2e") lists the side databases; production sets it to "".
set -eu

: "${POSTGRES_USER:?POSTGRES_USER is required}"
: "${POSTGRES_DB:?POSTGRES_DB is required}"

# Quiet: no command tags / "already exists" notices — setup re-runs this script on every run.
psql_admin() {
  PGOPTIONS='-c client_min_messages=warning' psql -q -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --no-password "$@"
}

databases="$POSTGRES_DB"
for suffix in ${EXTRA_DB_SUFFIXES-test e2e}; do
  databases="$databases ${POSTGRES_DB}_${suffix}"
done

for db in $databases; do
  exists=$(psql_admin --dbname postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$db'")
  if [ "$exists" != "1" ]; then
    psql_admin --dbname postgres -c "CREATE DATABASE \"$db\" OWNER \"$POSTGRES_USER\""
  fi

  psql_admin --dbname "$db" <<SQL
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE SCHEMA IF NOT EXISTS rag AUTHORIZATION "$POSTGRES_USER";
CREATE SCHEMA IF NOT EXISTS worker AUTHORIZATION "$POSTGRES_USER";
SQL
  echo "init: database $db ready (vector, pg_trgm, unaccent; schemas rag, worker)"
done
