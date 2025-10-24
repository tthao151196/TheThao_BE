#!/usr/bin/env bash
set -e

echo "🔗 storage:link"
php artisan storage:link || true

echo "🧹 clear & cache"
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear  || true
php artisan config:cache || true
php artisan route:cache  || true
php artisan view:cache   || true

# migrate nếu có DB
if [[ -n "${DB_HOST}" ]]; then
  echo "🗄️ migrate --force"
  php artisan migrate --force || true
fi

echo "🚀 apache2-foreground"
exec apache2-foreground
