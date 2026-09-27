#!/bin/sh
# Synthetic inventory fixtures only; separate from the payment concurrency suite.
set -eu
image=${1:-dujiaoka-security-test}
network=dujiaoka-inventory-test-net
database=dujiaoka-inventory-test-db
created_database=false
cleanup() {
  if [ "$created_database" = true ]; then docker rm -f "$database" >/dev/null 2>&1 || true; fi
  docker network rm "$network" >/dev/null 2>&1 || true
}
# Fail rather than removing resources belonging to another test invocation.
docker network create --internal "$network" >/dev/null
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
docker create --name "$database" --network "$network" --memory 512m \
  --tmpfs /var/lib/mysql -e MYSQL_ROOT_PASSWORD=test-root-only \
  -e MYSQL_DATABASE=dujiaoka_inventory_test -e MYSQL_USER=test \
  -e MYSQL_PASSWORD=test-only-password mariadb@sha256:07e06f2e7ae9dfc63707a83130a62e00167c827f08fcac7a9aa33f4b6dc34e0e >/dev/null
created_database=true
docker start "$database" >/dev/null
attempt=0
until docker exec "$database" mysqladmin ping -h 127.0.0.1 -ptest-root-only --silent >/dev/null 2>&1; do
  attempt=$((attempt+1))
  if [ "$attempt" -ge 30 ]; then printf '%s\n' 'Test database failed to start' >&2; exit 1; fi
  sleep 2
done
docker run --rm --network "$network" \
  -e DB_HOST="$database" -e DB_DATABASE=dujiaoka_inventory_test \
  --entrypoint php "$image" tests/admin-inventory-concurrency.php
