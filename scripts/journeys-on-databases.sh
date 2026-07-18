#!/usr/bin/env bash
#
# Run the Runabout journey suite (or any PHPUnit selection) against MySQL,
# MariaDB and PostgreSQL in throwaway Docker containers — the local mirror of
# the CI database matrix in .github/workflows/tests.yml.
#
# Journeys use one connection and per-trail transaction rollback, but on a real
# engine every write exercises the advisory / parent-row lock and, on Postgres,
# the range-exclusion constraint — code paths that SQLite silently no-ops. This
# is how we get shuffled bitemporal orderings in front of a real database
# without waiting for CI.
#
# Usage:
#   scripts/journeys-on-databases.sh                 # --group journey on all engines
#   scripts/journeys-on-databases.sh tests/Integration/Concurrency
#   ENGINES="pgsql" scripts/journeys-on-databases.sh # a single engine
#   KEEP=1 scripts/journeys-on-databases.sh          # leave containers running
#
set -euo pipefail

# What to run. Defaults to the journey group; extra args are passed to phpunit.
PHPUNIT_ARGS=("$@")
if [ ${#PHPUNIT_ARGS[@]} -eq 0 ]; then
    PHPUNIT_ARGS=(--group journey)
fi

ENGINES="${ENGINES:-mysql mariadb pgsql}"
KEEP="${KEEP:-0}"

MYSQL_CONTAINER=btmp-mysql
MARIADB_CONTAINER=btmp-mariadb
PG_CONTAINER=btmp-pg

cleanup() {
    if [ "$KEEP" = "1" ]; then
        echo "KEEP=1 — leaving containers running."
        return
    fi
    echo "Removing containers..."
    docker rm -f "$MYSQL_CONTAINER" "$MARIADB_CONTAINER" "$PG_CONTAINER" >/dev/null 2>&1 || true
}
trap cleanup EXIT

start_container() {
    case "$1" in
        mysql)
            docker rm -f "$MYSQL_CONTAINER" >/dev/null 2>&1 || true
            docker run -d --name "$MYSQL_CONTAINER" -p 127.0.0.1:33306:3306 \
                -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=testing mysql:8.0 >/dev/null
            ;;
        mariadb)
            docker rm -f "$MARIADB_CONTAINER" >/dev/null 2>&1 || true
            docker run -d --name "$MARIADB_CONTAINER" -p 127.0.0.1:33307:3306 \
                -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=yes -e MARIADB_DATABASE=testing mariadb:10.11 >/dev/null
            ;;
        pgsql)
            docker rm -f "$PG_CONTAINER" >/dev/null 2>&1 || true
            docker run -d --name "$PG_CONTAINER" -p 127.0.0.1:54320:5432 \
                -e POSTGRES_PASSWORD=postgres -e POSTGRES_DB=testing postgres:16 >/dev/null
            ;;
    esac
}

wait_ready() {
    local engine="$1" i
    for i in $(seq 1 45); do
        case "$engine" in
            mysql) docker exec "$MYSQL_CONTAINER" mysqladmin ping -h127.0.0.1 --silent >/dev/null 2>&1 && return 0 ;;
            mariadb) docker exec "$MARIADB_CONTAINER" mariadb-admin ping --silent >/dev/null 2>&1 && return 0 ;;
            pgsql) docker exec "$PG_CONTAINER" pg_isready -q >/dev/null 2>&1 && return 0 ;;
        esac
        sleep 2
    done
    echo "  ✗ $engine did not become healthy in time" >&2
    return 1
}

run_engine() {
    local engine="$1"
    case "$engine" in
        mysql)   DB_CONNECTION=mysql   DB_HOST=127.0.0.1 DB_PORT=33306 DB_USERNAME=root     DB_PASSWORD= ;;
        mariadb) DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=33307 DB_USERNAME=root     DB_PASSWORD= ;;
        pgsql)   DB_CONNECTION=pgsql   DB_HOST=127.0.0.1 DB_PORT=54320 DB_USERNAME=postgres DB_PASSWORD=postgres ;;
        *) echo "Unknown engine: $engine" >&2; return 2 ;;
    esac

    echo "==> $engine"
    start_container "$engine"
    wait_ready "$engine"

    DB_CONNECTION="$DB_CONNECTION" DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" \
        DB_DATABASE=testing DB_USERNAME="$DB_USERNAME" DB_PASSWORD="$DB_PASSWORD" \
        vendor/bin/phpunit "${PHPUNIT_ARGS[@]}"
}

failed=()
for engine in $ENGINES; do
    if run_engine "$engine"; then
        echo "  ✓ $engine passed"
    else
        echo "  ✗ $engine FAILED"
        failed+=("$engine")
    fi
done

if [ ${#failed[@]} -ne 0 ]; then
    echo "FAILED on: ${failed[*]}"
    exit 1
fi

echo "All engines passed: $ENGINES"
