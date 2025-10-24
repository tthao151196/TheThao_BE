#!/usr/bin/env bash
set -e

echo "🔗 php artisan storage:link"
php artisan storage:link || true

echo "🔎 php artisan package:discover"
php artisan package:discover --ansi || true

echo "🧹 clear & cache"
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear  || true

php artisan config:cache || true
php artisan route:cache  || true
php artisan view:cache   || true

# Migrate nếu có DB, không fail toàn bộ nếu lỗi
if [[ -n "${DB_HOST}" ]]; then
  echo "🗄️ php artisan migrate --force"
  php artisan migrate --force || true
fi

echo "🚀 apache2-foreground"
exec apache2-foreground
