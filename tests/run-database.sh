#!/bin/sh
# Disposable DB on an internal network; no ports, production mounts, or real credentials.
set -eu
image=${1:-dujiaoka-security-test}
cleanup() {
  docker rm -f dujiaoka-security-db >/dev/null 2>&1 || true
  docker network rm dujiaoka-security-net >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM
docker network create --internal dujiaoka-security-net >/dev/null
docker run -d --name dujiaoka-security-db --network dujiaoka-security-net --memory 512m \
  --tmpfs /var/lib/mysql -e MYSQL_ROOT_PASSWORD=test-root-only \
  -e MYSQL_DATABASE=dujiaoka_security_test -e MYSQL_USER=test \
  -e MYSQL_PASSWORD=test-only-password mariadb@sha256:07e06f2e7ae9dfc63707a83130a62e00167c827f08fcac7a9aa33f4b6dc34e0e >/dev/null
attempt=0
until docker exec dujiaoka-security-db mysqladmin ping -h 127.0.0.1 -ptest-root-only --silent >/dev/null 2>&1; do
  attempt=$((attempt+1))
  if [ "$attempt" -ge 30 ]; then printf '%s\n' 'Test database failed to start' >&2; exit 1; fi
  sleep 2
done
docker run --rm --network dujiaoka-security-net \
  -e DB_HOST=dujiaoka-security-db -e DB_DATABASE=dujiaoka_security_test \
  --entrypoint php "$image" tests/database-security.php
