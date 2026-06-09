#!/usr/bin/env bash
# Run the PHPUnit suite against every database backend in db-compose.yml.
# Brings the containers up (if not already), runs each connection, prints a
# summary, and leaves the containers running for re-runs. Tear down with:
#   docker compose -f tooling/db-compose.yml down -v
#
# Usage: tooling/test-all-backends.sh [extra phpunit args...]
set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

if docker compose version >/dev/null 2>&1; then
    COMPOSE=(docker compose)
else
    COMPOSE=(docker-compose)
fi

COMPOSE_FILE=tooling/db-compose.yml

echo "==> bringing database backends up"
"${COMPOSE[@]}" -f "$COMPOSE_FILE" up -d --wait || exit 1

# name|DB_CONNECTION|host|port|database|username|password
backends=(
    "sqlite|sqlite|||:memory:||"
    "mysql|mysql|127.0.0.1|33306|testing|root|password"
    "mariadb|mariadb|127.0.0.1|33307|testing|root|password"
    "pgsql|pgsql|127.0.0.1|15432|testing|postgres|password"
)

declare -a results
overall=0

for entry in "${backends[@]}"; do
    IFS='|' read -r name conn host port db user pass <<<"$entry"
    echo
    echo "==================== $name ===================="

    DB_CONNECTION="$conn" DB_HOST="$host" DB_PORT="$port" \
        DB_DATABASE="$db" DB_USERNAME="$user" DB_PASSWORD="$pass" \
        vendor/bin/phpunit "$@"

    if [ $? -eq 0 ]; then
        results+=("  $name: PASS")
    else
        results+=("  $name: FAIL")
        overall=1
    fi
done

echo
echo "==================== summary ===================="
printf '%s\n' "${results[@]}"
exit $overall
