#!/usr/bin/env bash
# DavetKart Nginx guncellemesi: snippet + site dosyasi -> 'nginx -t' -> reload. Test kirmiziysa eskiler geri konur.
# Ayrinti: docs/rehber/deploy/nginx/install.md · Calistirma (sunucuda, sudo yetkili kullanici): sudo bash deploy/nginx/install.sh
set -euo pipefail

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NGINX_DIR="${NGINX_DIR:-/etc/nginx}"   # yalnizca yerel denemede degisir
SITE="$NGINX_DIR/sites-available/davetkart.conf"
SNIPPET="$NGINX_DIR/snippets/davetkart-security-headers.conf"

step() { printf '\n==> [nginx] %s\n' "$1"; }

if [ "$NGINX_DIR" = /etc/nginx ] && [ "$(id -u)" -ne 0 ]; then
    echo "root yetkisi gerekli: sudo bash deploy/nginx/install.sh" >&2
    exit 1
fi
# Ilk kurulum (sertifika, site baglantisi, 'default'un silinmesi) bu betigin isi degil: rehber deploy/nginx/davetkart.md §5.
if [ ! -e "$NGINX_DIR/sites-enabled/davetkart.conf" ] || [ ! -d "$NGINX_DIR/snippets" ]; then
    echo "$NGINX_DIR/sites-enabled/davetkart.conf ya da $NGINX_DIR/snippets/ yok: ilk kurulum icin rehber §5." >&2
    exit 1
fi

BACKUP="$(mktemp -d)"
step "Yedek: $BACKUP"
for f in "$SITE" "$SNIPPET"; do
    if [ -f "$f" ]; then cp -p "$f" "$BACKUP/"; fi
done

# Yedekte olani geri koyar; yedekte yoksa (ilk kez kurulan dosya) kaldirir.
restore() {
    local f
    for f in "$SITE" "$SNIPPET"; do
        if [ -f "$BACKUP/$(basename "$f")" ]; then cp -p "$BACKUP/$(basename "$f")" "$f"; else rm -f "$f"; fi
    done
}

step "Kurulum: once snippet, sonra site (site snippet'i include eder)"
install -m 644 "$SRC_DIR/snippets/davetkart-security-headers.conf" "$SNIPPET"
install -m 644 "$SRC_DIR/davetkart.conf" "$SITE"

step "nginx -t"
if ! nginx -t; then
    restore
    echo "nginx -t kirmizi: eski dosyalar geri kondu, reload YAPILMADI. Yayindaki site degismedi." >&2
    exit 1
fi

step "reload (kesintisiz)"
systemctl reload nginx

step "Bitti. Simdi duman testi: bash deploy/smoke/check-security-headers.sh https://davetkart.com"
