#!/bin/sh
# Native-architecture CI: disposable MariaDB + official MCP client, no external network.
set -eu
image=${1:-dujiaoka-security-test}
network=dujiaoka-mcp-test-net
database=dujiaoka-mcp-db
app=mcp-app
made_db=false
made_app=false
cleanup() {
  if [ "$made_app" = true ]; then docker rm -f "$app" >/dev/null 2>&1 || true; fi
  if [ "$made_db" = true ]; then docker rm -f "$database" >/dev/null 2>&1 || true; fi
  docker network rm "$network" >/dev/null 2>&1 || true
}
npm ci --prefix tests/mcp-client --no-audit --no-fund
docker pull node:24-alpine@sha256:ebfe2f90462722a7a4de65e91990e97fe0d401c70e0e762c5b53302f905ec1c1 >/dev/null
docker network create --internal "$network" >/dev/null
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
docker create --name "$database" --network "$network" --memory 512m --tmpfs /var/lib/mysql \
  -e MYSQL_ROOT_PASSWORD=test-root-only -e MYSQL_DATABASE=dujiaoka_mcp_test -e MYSQL_USER=test -e MYSQL_PASSWORD=test-only-password \
  mariadb@sha256:07e06f2e7ae9dfc63707a83130a62e00167c827f08fcac7a9aa33f4b6dc34e0e >/dev/null
made_db=true
docker start "$database" >/dev/null
attempt=0
until docker exec "$database" mysqladmin ping -h 127.0.0.1 -ptest-root-only --silent >/dev/null 2>&1; do
  attempt=$((attempt+1)); if [ "$attempt" -ge 30 ]; then exit 1; fi; sleep 2
done
run_php() {
  docker run --rm --network "$network" --entrypoint php \
    -e APP_ENV=testing -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    -e DB_CONNECTION=mysql -e DB_HOST="$database" -e DB_DATABASE=dujiaoka_mcp_test -e DB_USERNAME=test -e DB_PASSWORD=test-only-password \
    -e CACHE_DRIVER=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=database -e MCP_SYNTHETIC=1 "$image" "$@"
}
run_php tests/mcp-bootstrap.php
run_php tests/mcp-concurrency.php
run_php tests/mcp-notification-locks.php
run_php tests/mcp-cli-smoke.php
run_php tests/mcp-bootstrap.php
docker create --name "$app" --network "$network" --entrypoint php \
  -e APP_ENV=testing -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
  -e DB_CONNECTION=mysql -e DB_HOST="$database" -e DB_DATABASE=dujiaoka_mcp_test -e DB_USERNAME=test -e DB_PASSWORD=test-only-password \
  -e CACHE_DRIVER=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=database -e MCP_SYNTHETIC=1 -e SHOP_MCP_ALLOWED_HOSTS=pay.example.test \
  "$image" -S 0.0.0.0:18769 -t public public/index.php >/dev/null
made_app=true
docker start "$app" >/dev/null
attempt=0
until [ "$(docker exec "$app" curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:18769/api/admin/mcp || true)" = 401 ]; do
  attempt=$((attempt+1)); if [ "$attempt" -ge 20 ]; then exit 1; fi; sleep 1
done
docker run --rm --network "$network" -v "$PWD/tests:/tests:ro" \
  -e MCP_SDK_ROOT=/tests/mcp-client/node_modules/@modelcontextprotocol/sdk -e MCP_TEST_URL=http://mcp-app:18769/api/admin/mcp \
  node:24-alpine@sha256:ebfe2f90462722a7a4de65e91990e97fe0d401c70e0e762c5b53302f905ec1c1 node /tests/mcp-sdk-smoke.mjs
