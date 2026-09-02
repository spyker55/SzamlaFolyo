#!/usr/bin/env bash
#
# Telepítés a nethely tárhelyre. SSH-ról futtatandó, a projekt gyökeréből:
#
#   cd ~/szamlafolyo && ./deploy.sh
#
# Előfeltétel egyszer, a legelső telepítéskor:
#   - a webcím gyökérkönyvtára (docroot) a projekt `public/` mappájára mutasson
#   - a `.env` fel legyen töltve (a .env.example alapján), és `php artisan key:generate` lefusson
#   - a három cron sor be legyen állítva (lásd README)

set -euo pipefail

# A tárhelyen az SSH alapértelmezett PHP-je régebbi lehet, mint a webcímé.
# Ha nálad más az elérési út, itt írd át:
PHP="${PHP_BIN:-php}"

echo "→ Kód frissítése"
git pull --ff-only

echo "→ Függőségek (fejlesztői csomagok nélkül)"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

echo "→ Karbantartási mód"
$PHP artisan down --render="errors::503" || true

echo "→ Adatbázis"
$PHP artisan migrate --force

echo "→ Gyorsítótárak"
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan event:cache

$PHP artisan up

echo "✓ Kész."
