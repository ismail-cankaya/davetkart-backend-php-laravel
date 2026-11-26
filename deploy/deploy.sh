#!/usr/bin/env bash
# DavetKart backend deploy. Ayrinti: docs/rehber/deploy/deploy.md
# Calistirma (sunucuda, 'deploy' kullanicisiyla): bash deploy/deploy.sh
set -Eeuo pipefail   # -E: ERR tuzagi artisan() gibi fonksiyonlarin icinde de calissin

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

# Yeniden baslatildiysak (asagida) asil "onceki surum" ilk calistirmadan gelir.
PREV="${DAVETKART_DEPLOY_PREV:-$(git rev-parse --short HEAD)}"

# Faz 20.4 (K264): kod `git pull` ile goclerden ONCE yerine gelir; yarim semayla cevap verilmesin.
# Ikinci calistirmada (yeniden baslatma, ya da onceki deploy bakimda kaldiysa) yalnizca secenekleri yeniler.
step "Bakim kipi aciliyor (API 503 + Retry-After)"
artisan down --retry=60
IN_MAINTENANCE=1

on_error() {
    echo "HATA: deploy yarida kesildi. Onceki surum: $PREV (geri alma: docs/rehber/deploy/deploy.md §4)" >&2
    if [ "$IN_MAINTENANCE" = 1 ]; then
        echo "Site BAKIM KIPINDE kaliyor (503). Sebebi duzeltip deploy.sh'i yeniden calistir; 'artisan up' ile yarim semayi acma." >&2
    fi
}
trap on_error ERR

step "Kod cekiliyor (onceki surum: $PREV)"
git pull --ff-only
NEW="$(git rev-parse --short HEAD)"

# Bash betigi calisirken dosyadan okur; git pull bu dosyayi degistirdiyse geri kalan
# adimlar ESKI surumle kosar. deploy/ degistiyse betigi yeni haliyle bastan baslat (bir kez).
if [ -z "${DAVETKART_DEPLOY_PREV:-}" ] && ! git diff --quiet "$PREV" "$NEW" -- deploy/; then
    step "deploy/ betikleri degisti: yeni surumle yeniden baslatiliyor"
    DAVETKART_DEPLOY_PREV="$PREV" exec bash "$APP_DIR/deploy/deploy.sh" "$@"
fi

# Etiket = "bu commit frontend'in ayni etiketli commit'iyle birlikte dogrulandi" (K149).
# Etiketsiz commit UYARI verir, deploy'u DURDURMAZ.
TAG="$(git describe --tags --exact-match HEAD 2>/dev/null || echo etiketsiz)"
step "Surum: $NEW ($TAG)"
if [ "$TAG" = "etiketsiz" ]; then
    echo "UYARI: $NEW etiketli bir surum degil; hangi frontend commit'iyle dogrulandigi kayitli degil." >&2
fi

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

# Goc bitti, FPM yeni kodda: artik tutarli cevap verilebilir.
step "Bakim kipi kapaniyor"
artisan up
IN_MAINTENANCE=0

step "Saglik kontrolu"
curl -fsS --max-time 10 --resolve "$HEALTH_HOST:443:127.0.0.1" "https://$HEALTH_HOST/api/ping"
echo

step "Backend bitti: $PREV -> $NEW ($TAG)"

# Backend ONCE: yeni frontend yeni API'ye guvenebilsin (API geriye uyumlu yazilir).
trap - ERR   # buradan sonraki hata backend'in degil; frontend betigi kendi mesajini yazar
bash "$APP_DIR/deploy/deploy-frontend.sh"
