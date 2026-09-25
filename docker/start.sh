#!/bin/sh
set -eu
cd /dujiaoka
if [ ! -f /dujiaoka/.env ]; then
    printf '%s\n' 'Missing mounted /dujiaoka/.env; refusing to start.' >&2
    exit 1
fi
# Keep existing APP_KEY, DB, Redis, uploads and order data. Never run migrations at startup.
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache public/uploads /run/nginx
chown -R application:application storage bootstrap/cache public/uploads
if [ "${INSTALL:-false}" != true ]; then printf '%s\n' ok > install.lock; fi
# Invalidate only local compiled artifacts, never flush shared Redis/cache keys.
rm -f bootstrap/cache/config.php bootstrap/cache/routes*.php
php artisan package:discover --ansi
nginx -t
php-fpm -t
exec supervisord -n -c /etc/supervisord.conf
