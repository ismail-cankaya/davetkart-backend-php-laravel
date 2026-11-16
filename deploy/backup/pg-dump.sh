#!/usr/bin/env bash
# DavetKart gece veritabani yedegi: pg_dump -Fc -> dogrula -> manifest -> S3.
# Ayrinti: docs/rehber/deploy/backup/pg-dump.md
# Sunucuda root calistirir; KURULU kopya: /usr/local/sbin/davetkart-pg-dump (cron: deploy/cron/davetkart-backup)
# Yerel deneme (S3'e gitmez): bash deploy/backup/pg-dump.sh --local <dizin>
set -Eeuo pipefail
umask 077

ENV_FILE="${BACKUP_ENV_FILE:-/etc/davetkart/backup.env}"
# Bu tablolar hem canli veritabaninda hem dokumun icindekiler listesinde olmak ZORUNDA.
REQUIRED_TABLES="users invitations orders rsvps"

log()  { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
fail() { log "HATA: $*" >&2; exit 1; }
step() { CURRENT_STEP="$1"; log "==> $1"; }

usage() {
    cat >&2 <<'EOF'
Kullanim: pg-dump.sh [--local <dizin>]
  (parametresiz)    Dokumu ve manifesti S3'e yukler; root ve /etc/davetkart/backup.env ister.
  --local <dizin>   S3 yerine <dizin>'e yazar (deneme). DRY_RUN=1 ayni kip, dizin: $BACKUP_LOCAL_DIR ya da ./backup-out
EOF
    exit "${1:-2}"
}

LOCAL_DIR=""
while [ $# -gt 0 ]; do
    case "$1" in
        --local) [ $# -ge 2 ] || usage; LOCAL_DIR="$2"; shift 2 ;;
        -h|--help) usage 0 ;;
        *) usage ;;
    esac
done
if [ -z "$LOCAL_DIR" ] && [ "${DRY_RUN:-0}" = "1" ]; then
    LOCAL_DIR="${BACKUP_LOCAL_DIR:-./backup-out}"
fi

CURRENT_STEP="baslangic"
WORK_DIR=""
SNAP_PROC=""
HEARTBEAT_URL=""
HEARTBEAT_FAIL_URL=""

# Kalp atisi adresi anahtar tasir: gunluge ASLA yazilmaz. Gonderilemezse yedek basarisiz sayilmaz.
heartbeat() {
    [ -n "$1" ] || return 0
    if curl -fsS --max-time 10 --retry 2 -o /dev/null "$1"; then
        log "kalp atisi gonderildi ($2)"
    else
        log "UYARI: kalp atisi gonderilemedi ($2)" >&2
    fi
}

on_exit() {
    local status=$?
    trap - EXIT
    [ -z "$SNAP_PROC" ] || kill "$SNAP_PROC" 2>/dev/null || true
    [ -z "$WORK_DIR" ] || rm -rf -- "$WORK_DIR"
    if [ "$status" -eq 0 ]; then
        heartbeat "$HEARTBEAT_URL" "basari"
    else
        log "HATA: yedek BASARISIZ (adim: $CURRENT_STEP, cikis: $status)" >&2
        heartbeat "$HEARTBEAT_FAIL_URL" "basarisizlik"
    fi
    exit "$status"
}
trap on_exit EXIT
trap 'log "HATA: satir $LINENO ($CURRENT_STEP)" >&2' ERR
trap 'exit 130' INT
trap 'exit 143' TERM

# --- Ayarlar ---------------------------------------------------------------
if [ -z "$LOCAL_DIR" ]; then
    [ "$(id -u)" -eq 0 ] || fail "S3 kipi root ister (anahtar dosyasini yalnizca root okur). Deneme icin: --local <dizin>"
    [ -f "$ENV_FILE" ] || fail "ayar dosyasi yok: $ENV_FILE"
    # Anahtar bu dosyada: sahibi root, grup ve digerleri icin hicbir izin olmamali.
    case "$(stat -c '%U:%a' "$ENV_FILE")" in
        root:600|root:400) ;;
        *) fail "$ENV_FILE izinleri root:600 olmali (su an: $(stat -c '%U:%a' "$ENV_FILE"))" ;;
    esac
fi
if [ -f "$ENV_FILE" ] && [ -r "$ENV_FILE" ]; then
    set -a
    # shellcheck source=/dev/null
    . "$ENV_FILE"
    set +a
fi
if [ -z "$LOCAL_DIR" ]; then   # bundan sonraki her hata (ayar hatasi dahil) basarisizlik adresine bildirilir
    HEARTBEAT_URL="${BACKUP_HEARTBEAT_URL:-}"
    HEARTBEAT_FAIL_URL="${BACKUP_HEARTBEAT_FAIL_URL:-}"
fi

DB_NAME="${BACKUP_DB_NAME:-davetkart}"
PG_OS_USER="${BACKUP_PG_OS_USER-postgres}"
SNAP_TIMEOUT="${BACKUP_SNAP_TIMEOUT:-300}"
LOCK_FILE="${BACKUP_LOCK_FILE:-/run/lock/davetkart-backup.lock}"
export PGAPPNAME="davetkart-backup"
export PGCONNECT_TIMEOUT="${PGCONNECT_TIMEOUT:-15}"

[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || fail "gecersiz veritabani adi: $DB_NAME"

# root isek PostgreSQL araclari 'postgres' sistem kullanicisiyla kosar (peer: parola yok).
# root degilsek (yerel deneme) libpq ortami kullanilir: PGHOST, PGUSER, PGPASSWORD ya da pgpass.
run_pg() {
    if [ -n "$PG_OS_USER" ] && [ "$(id -u)" -eq 0 ]; then
        runuser -u "$PG_OS_USER" -- "$@"
    else
        "$@"
    fi
}

need() { command -v "$1" >/dev/null 2>&1 || fail "gerekli arac bulunamadi: $1"; }
need pg_dump; need pg_restore; need psql; need sha256sum; need stat; need mktemp
if [ -n "$PG_OS_USER" ] && [ "$(id -u)" -eq 0 ]; then need runuser; fi

if [ -n "$LOCAL_DIR" ]; then
    mkdir -p -- "$LOCAL_DIR"
    LOCAL_DIR="$(cd -- "$LOCAL_DIR" && pwd)"
else
    need aws
    for name in BACKUP_S3_BUCKET AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_DEFAULT_REGION; do
        [ -n "${!name:-}" ] || fail "$ENV_FILE icinde $name bos"
    done
    [[ "$BACKUP_S3_BUCKET" =~ ^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$ ]] || fail "gecersiz bucket adi (yer tutucu mu kaldi?): $BACKUP_S3_BUCKET"
    if [ -n "$HEARTBEAT_URL$HEARTBEAT_FAIL_URL" ]; then need curl; fi
fi

# --- Tek kosu kilidi --------------------------------------------------------
if command -v flock >/dev/null 2>&1; then
    exec 9>"$LOCK_FILE"
    flock -n 9 || fail "baska bir yedek kosusu suruyor (kilit: $LOCK_FILE)"
elif [ -z "$LOCAL_DIR" ]; then
    fail "gerekli arac bulunamadi: flock"
else
    log "UYARI: flock yok, kilit atlandi (yalnizca --local kipinde kabul edilir)"
fi

STAMP="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
COMPACT="${STAMP//[-:]/}"
DUMP_NAME="$DB_NAME-$COMPACT.dump"
MANIFEST_NAME="$DB_NAME-$COMPACT.manifest.json"
WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/davetkart-backup.XXXXXX")"
DUMP_PATH="$WORK_DIR/$DUMP_NAME"
MANIFEST_PATH="$WORK_DIR/$MANIFEST_NAME"
TOC_PATH="$WORK_DIR/toc.txt"
cd /   # 'postgres' kullanicisi root'un dizinine giremez; psql bos yere uyari basmasin

# --- 1. Anlik goruntu oturumu: sayilar ve dokum AYNI ani gorur --------------
step "Anlik goruntu oturumu aciliyor ($DB_NAME)"
coproc SNAP { run_pg psql -X -q -At -w -v ON_ERROR_STOP=1 -d "$DB_NAME"; }
SNAP_OUT="${SNAP[0]}"
SNAP_IN="${SNAP[1]}"
SNAP_PROC="$SNAP_PID"

SNAP_REPLY=""
snap_ask() {   # tek satir cevap veren bir sorgu gonder; cevap SNAP_REPLY'de
    printf '%s\n' "$1" >&"$SNAP_IN"
    IFS= read -r -t "$SNAP_TIMEOUT" SNAP_REPLY <&"$SNAP_OUT" || fail "veritabani oturumu cevap vermedi: $1"
    SNAP_REPLY="${SNAP_REPLY%$'\r'}"   # Windows'ta yerel denemede satir sonu CRLF gelir
}

printf '%s\n' "BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY;" >&"$SNAP_IN"
snap_ask "SELECT pg_export_snapshot();"
SNAPSHOT_ID="$SNAP_REPLY"
[[ "$SNAPSHOT_ID" =~ ^[0-9A-Fa-f-]+$ ]] || fail "beklenmeyen anlik goruntu kimligi: $SNAPSHOT_ID"

snap_ask "SHOW server_version;"
SERVER_VERSION="${SNAP_REPLY%% *}"
[[ "$SERVER_VERSION" =~ ^[0-9][0-9A-Za-z.]*$ ]] || fail "beklenmeyen sunucu surumu: $SNAP_REPLY"

step "Satir sayilari aliniyor"
snap_ask "SELECT coalesce(string_agg(tablename, ' ' ORDER BY tablename), '') FROM pg_tables WHERE schemaname = 'public';"
TABLES="$SNAP_REPLY"
for required in $REQUIRED_TABLES; do
    case " $TABLES " in
        *" $required "*) ;;
        *) fail "zorunlu tablo veritabaninda yok: $required (yanlis veritabani mi: $DB_NAME?)" ;;
    esac
done

COUNT_LINES=()
TABLE_COUNT=0
ROW_TOTAL=0
for table in $TABLES; do
    [[ "$table" =~ ^[a-z_][a-z0-9_]*$ ]] || fail "beklenmeyen tablo adi: $table"
    snap_ask "SELECT count(*) FROM public.\"$table\";"
    [[ "$SNAP_REPLY" =~ ^[0-9]+$ ]] || fail "sayi bekleniyordu ($table): $SNAP_REPLY"
    COUNT_LINES+=("    \"$table\": $SNAP_REPLY")
    TABLE_COUNT=$((TABLE_COUNT + 1))
    ROW_TOTAL=$((ROW_TOTAL + SNAP_REPLY))
done

snap_ask "SELECT coalesce(max(migration), '') FROM public.migrations;"
LAST_MIGRATION="$SNAP_REPLY"
[[ "$LAST_MIGRATION" =~ ^[A-Za-z0-9_]*$ ]] || fail "beklenmeyen goc adi: $LAST_MIGRATION"

# --- 2. Dokum ---------------------------------------------------------------
step "Dokum aliniyor (pg_dump -Fc, anlik goruntu $SNAPSHOT_ID)"
run_pg pg_dump --format=custom --no-password --snapshot="$SNAPSHOT_ID" --dbname="$DB_NAME" > "$DUMP_PATH"

printf '%s\n' "COMMIT;" >&"$SNAP_IN"
exec {SNAP_IN}>&-
wait "$SNAP_PROC" || fail "anlik goruntu oturumu hatayla kapandi"
SNAP_PROC=""

# --- 3. Dogrulama: dosya okunuyor mu, bos mu, zorunlu tablolarin verisi icinde mi
step "Dokum dogrulaniyor (pg_restore --list)"
pg_restore --list "$DUMP_PATH" > "$TOC_PATH" || fail "dokum okunamiyor: pg_restore --list basarisiz"

DUMP_BYTES="$(stat -c %s "$DUMP_PATH")"
[ "$DUMP_BYTES" -gt 0 ] || fail "dokum dosyasi bos"

for required in $REQUIRED_TABLES; do
    grep -q "TABLE DATA public $required " "$TOC_PATH" || fail "dokumde '$required' tablosunun verisi yok"
done
TOC_ENTRIES="$(grep -cv '^;' "$TOC_PATH" || true)"

# --- 4. Manifest ------------------------------------------------------------
step "Manifest yaziliyor"
DUMP_SHA256="$(sha256sum "$DUMP_PATH")"
DUMP_SHA256="${DUMP_SHA256%% *}"
read -r _ _ PG_DUMP_VERSION _ < <(pg_dump --version)
PG_DUMP_VERSION="${PG_DUMP_VERSION%$'\r'}"
[[ "$PG_DUMP_VERSION" =~ ^[0-9][0-9A-Za-z.]*$ ]] || fail "beklenmeyen pg_dump surumu: $PG_DUMP_VERSION"
{
    printf '{\n'
    printf '  "schema": 1,\n'
    printf '  "createdAt": "%s",\n' "$STAMP"
    printf '  "database": "%s",\n' "$DB_NAME"
    printf '  "serverVersion": "%s",\n' "$SERVER_VERSION"
    printf '  "pgDumpVersion": "%s",\n' "$PG_DUMP_VERSION"
    printf '  "lastMigration": "%s",\n' "$LAST_MIGRATION"
    printf '  "dump": { "file": "%s", "bytes": %s, "sha256": "%s", "tocEntries": %s },\n' \
        "$DUMP_NAME" "$DUMP_BYTES" "$DUMP_SHA256" "$TOC_ENTRIES"
    printf '  "tables": {\n'
    for i in "${!COUNT_LINES[@]}"; do
        if [ "$i" -lt $((TABLE_COUNT - 1)) ]; then
            printf '%s,\n' "${COUNT_LINES[$i]}"
        else
            printf '%s\n' "${COUNT_LINES[$i]}"
        fi
    done
    printf '  }\n'
    printf '}\n'
} > "$MANIFEST_PATH"

# --- 5. Hedef: once dokum, sonra manifest (manifest varsa yedek tamdir) -----
if [ -n "$LOCAL_DIR" ]; then
    DEST="$LOCAL_DIR"
    step "Yerel dizine yaziliyor: $DEST"
    cp -- "$DUMP_PATH" "$DEST/$DUMP_NAME"
    cp -- "$MANIFEST_PATH" "$DEST/$MANIFEST_NAME"
else
    DEST="s3://$BACKUP_S3_BUCKET/daily/${STAMP:0:4}/${STAMP:5:2}/${STAMP:8:2}"
    step "S3'e yukleniyor: $DEST"
    aws s3 cp "$DUMP_PATH" "$DEST/$DUMP_NAME" --sse AES256 --only-show-errors
    aws s3 cp "$MANIFEST_PATH" "$DEST/$MANIFEST_NAME" --sse AES256 --only-show-errors
fi

CURRENT_STEP="bitti"
log "TAMAM: $DEST/$DUMP_NAME ($DUMP_BYTES bayt, $TABLE_COUNT tablo, $ROW_TOTAL satir, ${SECONDS} sn)"
