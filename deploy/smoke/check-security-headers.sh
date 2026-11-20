#!/usr/bin/env bash
# Guvenlik basliklari duman testi (SEC-04, K178). Ayrinti: docs/rehber/deploy/smoke/check-security-headers.md
# Kullanim: bash deploy/smoke/check-security-headers.sh [-k] [--resolve HOST:PORT:ADRES] [--skip-api] <kok-url>
# Cikis: 0 hepsi yerinde · 1 eksik ya da yinelenen baslik · 2 kullanim ya da baglanti hatasi.
set -euo pipefail

usage() { sed -n '3,4p' "$0" | sed 's/^# //' >&2; exit 2; }

CURL_OPTS=()
SKIP_API=0
BASE=""
while [ $# -gt 0 ]; do
    case "$1" in
        -k | --insecure) CURL_OPTS+=(-k) ;;
        --resolve) [ $# -ge 2 ] || usage; CURL_OPTS+=(--resolve "$2"); shift ;;
        --skip-api) SKIP_API=1 ;;
        -*) usage ;;
        *) [ -z "$BASE" ] || usage; BASE="${1%/}" ;;
    esac
    shift
done
[ -n "$BASE" ] || usage

FAIL=0
problems=()

# Yonlendirme izlenmez: her yol kendi yanitiyla sinanir. Ad ve deger kucuk harfe cevrilir.
fetch_headers() {
    curl -sS --max-time 15 -o /dev/null -D - ${CURL_OPTS[@]+"${CURL_OPTS[@]}"} "$BASE$1" \
        | tr -d '\r' | tr '[:upper:]' '[:lower:]'
}

count_header() { printf '%s\n' "$1" | grep -c "^$2:" || true; }

# $1 basliklar · $2 ad · $3 degerde aranan (istege bagli). Tam bir kez olmali: iki kaynak = yanlis katman.
expect_once() {
    local n
    n=$(count_header "$1" "$2")
    if [ "$n" -ne 1 ]; then
        problems+=("$2: $n kez (1 bekleniyordu)")
    elif [ -n "${3:-}" ] && ! printf '%s\n' "$1" | grep "^$2:" | grep -qF -- "$3"; then
        problems+=("$2: \"$3\" yok")
    fi
}

expect_none() {
    local n
    n=$(count_header "$1" "$2")
    [ "$n" -eq 0 ] || problems+=("$2: burada olmamali ($n kez)")
}

get() {
    local h
    if ! h=$(fetch_headers "$1") || [ -z "$h" ]; then
        echo "HATA: $BASE$1 alinamadi" >&2
        exit 2
    fi
    printf '%s' "$h"
}

report() {
    local status
    status=$(printf '%s\n' "$2" | head -n1 | cut -d' ' -f2)
    if [ ${#problems[@]} -eq 0 ]; then
        printf 'OK     %-34s %s\n' "$1" "$status"
    else
        FAIL=1
        printf 'EKSIK  %-34s %s\n' "$1" "$status"
        printf '         - %s\n' "${problems[@]}"
    fi
    problems=()
}

# SPA yaniti: Nginx snippet'i (K173-K176).
check_spa() {
    local h
    h=$(get "$1")
    expect_once "$h" strict-transport-security "max-age="
    expect_once "$h" x-content-type-options "nosniff"
    expect_once "$h" referrer-policy
    expect_once "$h" x-frame-options
    expect_once "$h" permissions-policy
    expect_once "$h" content-security-policy "frame-ancestors"
    # Tam politika ya rapor kipinde (gozlem donemi) ya da zorlaniyor (K175).
    if [ "$(count_header "$h" content-security-policy-report-only)" -eq 0 ]; then
        [ "$(count_header "$h" content-security-policy)" -ne 1 ] || expect_once "$h" content-security-policy "default-src"
    else
        expect_once "$h" content-security-policy-report-only "default-src"
    fi
    report "$1" "$h"
}

# API yaniti: basliklar YALNIZCA Laravel'den (K85). Nginx'inkiler buraya sizarsa sayilar 2 olur.
check_api() {
    local h
    h=$(get "$1")
    case "$BASE" in https://*) expect_once "$h" strict-transport-security "max-age=" ;; esac
    expect_once "$h" x-content-type-options "nosniff"
    expect_once "$h" x-frame-options
    expect_once "$h" referrer-policy
    expect_once "$h" content-security-policy "default-src 'none'"
    expect_none "$h" content-security-policy-report-only
    expect_none "$h" permissions-policy
    report "$1" "$h"
}

INDEX=$(curl -sS --max-time 15 ${CURL_OPTS[@]+"${CURL_OPTS[@]}"} "$BASE/") || { echo "HATA: $BASE/ alinamadi" >&2; exit 2; }
ASSET=$(printf '%s' "$INDEX" | grep -oE '/assets/[^"]+\.js' | head -n1 || true)
[ -n "$ASSET" ] || { echo "HATA: $BASE/ yanitinda /assets/*.js yok (SPA mi?)" >&2; exit 2; }

echo "Guvenlik basliklari: $BASE"
check_spa /                          # = /index.html
check_spa /__duman-testi/spa-yolu    # try_files -> /index.html
check_spa "$ASSET"                   # ^~ /assets/
check_spa /favicon.svg               # location / (kok dizindeki dosya)
check_spa /.env                      # nokta ile baslayan yol (404)
[ "$SKIP_API" -eq 1 ] || check_api /api/ping

if [ "$FAIL" -ne 0 ]; then
    echo "SONUC: eksik ya da yinelenen baslik var (rehber: docs/rehber/deploy/smoke/check-security-headers.md)" >&2
    exit 1
fi
echo "SONUC: tamam"
