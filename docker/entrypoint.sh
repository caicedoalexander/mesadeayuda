#!/bin/bash
set -e

echo "==> Ensuring directory permissions..."
chown -R www-data:www-data tmp logs webroot/uploads config/google 2>/dev/null || true
chmod -R 775 tmp logs webroot/uploads config/google 2>/dev/null || true

# If a command is passed (e.g. from docker-compose `command:`), run it directly.
# Used historically by the worker container; the web service hits the default branch below.
if [ "$#" -gt 0 ]; then
    echo "==> Running command: $@"
    exec "$@"
fi

# Default: start web server (PHP-FPM + Nginx)
# Configure PHP-FPM to use unix socket
echo '[www]
listen = /run/php-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660' > /usr/local/etc/php-fpm.d/zz-socket.conf

echo "==> Starting PHP-FPM..."
php-fpm -D

echo "==> Starting Nginx..."
exec nginx -g "daemon off;"
