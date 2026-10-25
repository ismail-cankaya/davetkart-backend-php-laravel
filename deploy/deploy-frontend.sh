#!/usr/bin/env bash
# DavetKart frontend deploy (React + Vite). Ayrinti: docs/rehber/deploy/deploy-frontend.md
# Calistirma (sunucuda, 'deploy' ile): bash deploy/deploy-frontend.sh  (deploy.sh de sonunda cagirir)
set -euo pipefail

FRONT_DIR="/var/www/davetkart/frontend"
HEALTH_HOST="davetkart.com"
KEEP_OLD_ASSETS_DAYS=14

# Uretim derleme ayarlari BURADA (depoda) durur; sunucuda gizli .env.production yok.
# VITE_ degerleri tarayiciya gomulur: buraya ASLA sir yazilmaz.
export VITE_API_BASE_URL=/api
export VITE_PLACES_SEARCH_ENABLED=false

step() { printf '\n==> [frontend] %s\n' "$1"; }

if [ "$(id -u)" -eq 0 ]; then
    echo "root ile calistirma; 'deploy' kullanicisiyla calistir." >&2
    exit 1
fi

cd "$FRONT_DIR"
# Gecici derleme klasorleri 'git status'u kirletmesin (depoya degil, yalnizca bu klona yazilir).
grep -qx 'dist.old/' .git/info/exclude 2>/dev/null || printf 'dist.new/\ndist.old/\ndist.bad/\n' >> .git/info/exclude

step "Calisma agaci temiz mi?"
if ! git diff --quiet || ! git diff --cached --quiet; then
    echo "Sunucuda commit'lenmemis degisiklik var; deploy durdu:" >&2
    git status --short >&2
    exit 1
fi

PREV="$(git rev-parse --short HEAD)"
trap 'echo "HATA: frontend deploy yarida kesildi. Yayindaki site DEGISMEDI (dist/ yerinde). Onceki surum: $PREV" >&2' ERR

step "Kod cekiliyor (onceki surum: $PREV)"
git pull --ff-only
NEW="$(git rev-parse --short HEAD)"

step "npm ci (--ignore-scripts: bagimliliklarin kurulum betikleri calismaz)"
npm ci --ignore-scripts --no-audit --no-fund

step "Derleme: dist.new/ (yayindaki dist/ bu sirada dokunulmaz)"
rm -rf dist.new
npx --no-install vite build --outDir dist.new --emptyOutDir
[ -f dist.new/index.html ] || { echo "dist.new/index.html yok; derleme eksik." >&2; false; }

step "Eski asset'ler korunuyor (acik sekmeler eski parcalari yukleyebilsin)"
if [ -d dist/assets ]; then
    cp -an dist/assets/. dist.new/assets/
    # Kopyalananlar eski tarihini korur, yeni derlenenler bugunun tarihini tasir:
    # yalnizca N gunden eski KOPYALAR silinir.
    find dist.new/assets -type f -mtime +"$KEEP_OLD_ASSETS_DAYS" -delete
fi

step "Yer degistirme: dist -> dist.old, dist.new -> dist"
rm -rf dist.old
if [ -d dist ]; then mv dist dist.old; fi
mv dist.new dist

step "Saglik kontrolu"
curl -fsS --max-time 10 --resolve "$HEALTH_HOST:443:127.0.0.1" "https://$HEALTH_HOST/" | grep -q '<div id="root">' \
    || { echo "Ana sayfa beklenen HTML'i dondurmedi. Geri al: mv dist dist.bad && mv dist.old dist" >&2; false; }

step "Bitti: $PREV -> $NEW"
