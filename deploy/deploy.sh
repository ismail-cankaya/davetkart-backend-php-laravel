#!/usr/bin/env bash
# DavetKart backend deploy. Ayrinti: docs/rehber/deploy/deploy.md
# Calistirma (sunucuda, 'deploy' kullanicisiyla): bash deploy/deploy.sh
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB_USER="www-data"
PHP_FPM_SERVICE="php8.5-fpm"
HEALTH_HOST="davetkart.com"

step() { printf '\n==> %s\n' "$1"; }
# artisan HER ZAMAN web kullanicisiyla: storage/ ve cache dosyalarinin sahibi karismasin.
# sudo -n: parola SORMA, yetki yoksa hemen hata ver (yetkiler: deploy/sudoers/davetkart-deploy).
artisan() { sudo -n -u "$WEB_USER" /usr/bin/php "$APP_DIR/artisan" "$@"; }

if [ "$(id -u)" -eq 0 ]; then
    echo "root ile calistirma; 'deploy' kullanicisiyla calistir." >&2
    exit 1
fi

cd "$APP_DIR"
[ -f .env ] || { echo ".env bulunamadi: $APP_DIR/.env" >&2; exit 1; }
if ! sudo -n -u "$WEB_USER" /usr/bin/php -v >/dev/null 2>&1; then
    echo "sudo yetkisi yok: /etc/sudoers.d/davetkart-deploy kurulu mu? (rehber: deploy/sudoers)" >&2
    exit 1
fi

step "Calisma agaci temiz mi?"
if ! git diff --quiet || ! git diff --cached --quiet; then
    echo "Sunucuda commit'lenmemis degisiklik var; deploy durdu:" >&2
    git status --short >&2
    exit 1
fi

PREV="$(git rev-parse --short HEAD)"
trap 'echo "HATA: deploy yarida kesildi. Onceki surum: $PREV (geri alma: docs/rehber/deploy/deploy.md)" >&2' ERR

step "Kod cekiliyor (onceki surum: $PREV)"
git pull --ff-only
NEW="$(git rev-parse --short HEAD)"

step "Composer bagimliliklari (--no-dev)"
composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
artisan package:discover --ansi

step "Onbellekler (config, route)"
artisan config:cache
artisan route:cache
sudo -n -u "$WEB_USER" /usr/bin/chmod 640 bootstrap/cache/config.php

step "Veritabani gocleri"
artisan migrate --force

step "PHP-FPM yeniden yukleniyor (OPcache temizligi)"
sudo -n /usr/bin/systemctl reload "$PHP_FPM_SERVICE"

step "Kuyruk iscisine yeniden dogma sinyali"
artisan queue:restart

step "Saglik kontrolu"
curl -fsS --max-time 10 --resolve "$HEALTH_HOST:443:127.0.0.1" "https://$HEALTH_HOST/api/ping"
echo

step "Backend bitti: $PREV -> $NEW"

# Backend ONCE: yeni frontend yeni API'ye guvenebilsin (API geriye uyumlu yazilir).
trap - ERR   # buradan sonraki hata backend'in degil; frontend betigi kendi mesajini yazar
bash "$APP_DIR/deploy/deploy-frontend.sh"
