#!/usr/bin/env bash
# Auto-deploy untuk Hostinger, dipanggil cron (hPanel > Advanced > Cron Jobs) setiap 5 menit:
#   cd <app> && bash deploy.sh >> storage/logs/deploy.log 2>&1
# Mengambil commit terbaru dari GitHub, lalu hanya bekerja bila commit berubah
# sejak deploy terakhir. Semua kegagalan (termasuk git pull) tercatat di deploy.log.
# Dibungkus { ... } supaya bash membaca seluruh skrip dulu; git pull bisa
# mengubah file ini saat sedang berjalan.
{
set -e
export PATH="$HOME/bin:/usr/local/bin:/usr/bin:/bin:$PATH"
cd "$(dirname "$0")"

# Perubahan izin file (chmod) di server bukan perubahan kode.
git config core.fileMode false

if ! git pull -q origin master; then
    echo "[$(date '+%F %T')] GAGAL git pull. File yang diubah langsung di server:"
    git status -s
    exit 1
fi

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
exit
}
