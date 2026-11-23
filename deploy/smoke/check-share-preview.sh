#!/usr/bin/env bash
# Paylasim onizlemesi duman testi (FE-04, K230). Ayrinti: docs/rehber/deploy/smoke/check-share-preview.md
# Kullanim: bash deploy/smoke/check-share-preview.sh [-k] <kok-url> <yayindaki-davetiye-id>
set -euo pipefail
CURL=(curl -sS --max-time 15)
if [ "${1:-}" = "-k" ]; then CURL+=(-k); shift; fi
[ $# -eq 2 ] || { sed -n '3p' "$0" | sed 's/^# //' >&2; exit 2; }
BASE="${1%/}"; ID="$2"; FAIL=0

bot=$("${CURL[@]}" -A "WhatsApp/2.23.20.0" "$BASE/invite/$ID")
human=$("${CURL[@]}" -A "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0" "$BASE/invite/$ID")
taslak=$("${CURL[@]}" -A "TelegramBot (like TwitterBot)" "$BASE/invite/00000000000000000000000000")

printf '%s' "$bot" | grep -q 'property="og:title"' && echo "OK   bot -> OG sayfasi" || { echo "HATA bot OG etiketi almadi"; FAIL=1; }
printf '%s' "$bot" | grep -q 'id="root"' && { echo "HATA bot SPA aldi"; FAIL=1; } || true
printf '%s' "$human" | grep -q 'id="root"' && echo "OK   tarayici -> SPA" || { echo "HATA tarayici SPA almadi"; FAIL=1; }
printf '%s' "$taslak" | grep -q 'DavetKart — Dijital Davetiye' && echo "OK   olmayan davetiye -> genel onizleme" || { echo "HATA olmayan davetiye genel onizleme vermedi"; FAIL=1; }

[ "$FAIL" -eq 0 ] && echo "SONUC: tamam" || { echo "SONUC: hata" >&2; exit 1; }
