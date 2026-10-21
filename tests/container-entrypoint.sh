#!/bin/sh
# Start the actual inherited entrypoint using only synthetic configuration.
set -eu
image=${1:-dujiaoka-security-test}
container=dujiaoka-entrypoint-test
fixture=$(mktemp -d)
cleanup(){ docker rm -f "$container" >/dev/null 2>&1 || true; rm -rf "$fixture"; }
trap cleanup EXIT INT TERM
printf '%s\n' 'APP_NAME=SecurityTest' 'APP_ENV=testing' 'APP_DEBUG=false' \
 'APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=' \
 'APP_URL=http://localhost' 'CACHE_DRIVER=array' 'SESSION_DRIVER=array' \
 'QUEUE_CONNECTION=sync' 'DB_CONNECTION=mysql' 'DB_HOST=127.0.0.1' \
 'DB_DATABASE=synthetic_unused' 'DB_USERNAME=synthetic' 'DB_PASSWORD=synthetic' > "$fixture/.env"
# Docker copies only test fixture files. No production network or mounts.
docker create --name "$container" --network none --tmpfs /dujiaoka/storage --entrypoint /start.sh "$image" >/dev/null
docker cp "$fixture/.env" "$container:/dujiaoka/.env"
printf '%s\n' '<?php if (PHP_SAPI !== "fpm-fcgi" || PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 5 || PHP_VERSION_ID < 80512 || PHP_VERSION !== getenv("PHP_VERSION")) { http_response_code(500); exit; } echo "entrypoint-ready:".PHP_VERSION;' > "$fixture/index.php"
docker cp "$fixture/index.php" "$container:/dujiaoka/public/index.php"
docker start "$container" >/dev/null
attempt=0
version=$(docker exec "$container" php -r 'echo PHP_VERSION;')
until [ "$(docker exec "$container" curl -fsS http://127.0.0.1/ 2>/dev/null || true)" = "entrypoint-ready:$version" ]; do
 attempt=$((attempt+1))
 if [ "$attempt" -ge 25 ]; then docker logs "$container"; exit 1; fi
 sleep 1
done
docker exec "$container" test -f /dujiaoka/install.lock
docker exec "$container" test -d /dujiaoka/storage/app
docker exec "$container" php-fpm --version
# Supervisor's RUNNING state requires each process to survive its startup period.
sleep 3
for service in php-fpm nginx queue; do
 docker exec "$container" supervisorctl -c /etc/supervisord.conf status "$service" | grep -E "^$service[[:space:]]+RUNNING"
done
printf 'PASS: Nginx served patched PHP FPM %s; FPM, Nginx and queue are running\n' "$version"
printf '%s\n' 'PASS: inherited entrypoint initializes storage and starts serving'
