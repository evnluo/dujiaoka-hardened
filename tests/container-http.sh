#!/bin/sh
# Exercise the shipped Nginx/PHP configuration with disposable synthetic files.
set -eu
image=${1:-dujiaoka-security-test}
container=dujiaoka-http-security-test
trap 'docker rm -f "$container" >/dev/null 2>&1 || true' EXIT INT TERM
docker run -d --name "$container" --network none --entrypoint sh "$image" -c '
  printf "<?php echo \"front-controller-ok\";" > /dujiaoka/public/index.php
  mkdir -p /dujiaoka/public/uploads
  printf "<?php echo \"must-not-execute\";" > /dujiaoka/public/uploads/probe.php
  printf "synthetic-hidden-file" > /dujiaoka/public/.probe
  php-fpm7 -D
  exec nginx -g "daemon off;"
' >/dev/null
attempt=0
until docker exec "$container" curl -fsS http://127.0.0.1/index.php >/dev/null 2>&1; do
  attempt=$((attempt+1))
  if [ "$attempt" -ge 20 ]; then printf '%s\n' 'HTTP test service did not start' >&2; exit 1; fi
  sleep 1
done
body=$(docker exec "$container" curl -fsS http://127.0.0.1/)
[ "$body" = front-controller-ok ]
code=$(docker exec "$container" curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/uploads/probe.php)
[ "$code" = 404 ]
code=$(docker exec "$container" curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/.probe)
[ "$code" = 403 ]
printf '%s\n' 'PASS: front controller works; uploaded PHP and hidden files denied'
