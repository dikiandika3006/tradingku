#!/usr/bin/env bash
# Auto-deploy untuk Hostinger, dipanggil cron setiap 5 menit:
#   cd <app> && git pull -q origin master && bash deploy.sh >> storage/logs/deploy.log 2>&1
# Hanya bekerja bila commit berubah sejak deploy terakhir.
set -e
export PATH="$HOME/bin:/usr/local/bin:/usr/bin:/bin:$PATH"
cd "$(dirname "$0")"

STAMP=storage/app/last_deploy
HEAD=$(git rev-parse HEAD)
[ -f "$STAMP" ] && [ "$(cat "$STAMP")" = "$HEAD" ] && exit 0

echo "[$(date '+%F %T')] deploy $HEAD"
composer install --no-dev --optimize-autoloader --no-interaction -q
php artisan migrate --force
php artisan optimize
[ -e public/storage ] || ln -s ../storage/app/public public/storage
echo "$HEAD" > "$STAMP"
echo "[$(date '+%F %T')] selesai"
