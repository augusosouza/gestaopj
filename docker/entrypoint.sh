#!/bin/sh
set -e
# Aplica migrations e cacheia configuração a cada subida do container.
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
exec "$@"
