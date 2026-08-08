#!/usr/bin/env bash
# =============================================================================
# xcamp-gym-sql : backup.sh — نسخة احتياطية مضغوطة لقاعدة البيانات (mysqldump)
# -----------------------------------------------------------------------------
# ينشئ ملف .sql.gz مؤرَّخًا في backups/ (أو $BACKUP_DIR)، بترميز utf8mb4 وبنية
# متّسقة (single-transaction لعدم قفل الجداول أثناء التشغيل)، ثم يشذّب النسخ
# الأقدم مُبقيًا آخر $BACKUP_KEEP نسخة. آمن للتشغيل الدوري عبر cron.
#
# الإعداد عبر متغيّرات البيئة (نفس متغيّرات النشر):
#   DB_HOST(127.0.0.1) DB_PORT(3306) DB_USER(root) DB_PASS() DB_NAME(xcamp_gym)
#   BACKUP_DIR($ROOT/backups)  BACKUP_KEEP(14)  عدد النسخ المُحتفظ بها.
#
# أمثلة:
#   DB_USER=appuser DB_PASS='***' ./backup.sh
#   BACKUP_KEEP=30 DB_USER=appuser DB_PASS='***' ./backup.sh
# =============================================================================
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

: "${DB_HOST:=127.0.0.1}"
: "${DB_PORT:=3306}"
: "${DB_USER:=root}"
: "${DB_PASS:=}"
: "${DB_NAME:=xcamp_gym}"
: "${BACKUP_DIR:=$ROOT_DIR/backups}"
: "${BACKUP_KEEP:=14}"

USE_COLOR=0
if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then USE_COLOR=1; fi
if [[ "$USE_COLOR" -eq 1 ]]; then
  RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[0;33m'; BLUE=$'\033[0;34m'; NC=$'\033[0m'
else
  RED=""; GREEN=""; YELLOW=""; BLUE=""; NC=""
fi
log_info() { printf "%b[INFO]%b %s\n" "$BLUE" "$NC" "$1"; }
log_ok()   { printf "%b[OK]%b %s\n" "$GREEN" "$NC" "$1"; }
log_warn() { printf "%b[WARN]%b %s\n" "$YELLOW" "$NC" "$1"; }
log_err()  { printf "%b[ERR]%b %s\n" "$RED" "$NC" "$1" >&2; }

command -v mysqldump >/dev/null 2>&1 || { log_err "mysqldump غير متوفّر في PATH"; exit 1; }
command -v gzip >/dev/null 2>&1 || { log_err "gzip غير متوفّر في PATH"; exit 1; }
[[ -n "$DB_NAME" ]] || { log_err "DB_NAME فارغ"; exit 1; }

mkdir -p "$BACKUP_DIR"

# مصادقة عبر ملف مؤقّت (0600) بدل تمرير كلمة المرور على سطر الأوامر (تُرى في ps).
CNF="$(mktemp)"
chmod 600 "$CNF"
cleanup() { rm -f "$CNF"; }
trap cleanup EXIT
{
  printf '[client]\n'
  printf 'host=%s\n' "$DB_HOST"
  printf 'port=%s\n' "$DB_PORT"
  printf 'user=%s\n' "$DB_USER"
  [[ -n "$DB_PASS" ]] && printf 'password=%s\n' "$DB_PASS"
  printf 'default-character-set=utf8mb4\n'
} > "$CNF"

STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="$BACKUP_DIR/${DB_NAME}-${STAMP}.sql.gz"
TMP="$OUT.partial"

log_info "بدء النسخ الاحتياطي لقاعدة: $DB_NAME → $OUT"

# --single-transaction: لقطة متّسقة دون قفل (InnoDB). --routines/--triggers/--events:
# لأن المخطط يعتمد إجراءات مخزّنة وtriggers وevents. الكتابة إلى ملف .partial ثم
# نقلها ذرّيًا حتى لا يبقى نصف ملف لو انقطع التشغيل.
if mysqldump --defaults-extra-file="$CNF" \
      --single-transaction --quick --routines --triggers --events \
      --default-character-set=utf8mb4 \
      --databases "$DB_NAME" 2>"$BACKUP_DIR/.last_error" \
    | gzip -9 > "$TMP"; then
  mv -f "$TMP" "$OUT"
  rm -f "$BACKUP_DIR/.last_error"
  SIZE="$(du -h "$OUT" | cut -f1)"
  log_ok "اكتمل النسخ ($SIZE): $OUT"
else
  rm -f "$TMP"
  log_err "فشل النسخ الاحتياطي:"
  [[ -s "$BACKUP_DIR/.last_error" ]] && cat "$BACKUP_DIR/.last_error" >&2
  exit 1
fi

# ── تشذيب النسخ الأقدم مع إبقاء آخر BACKUP_KEEP ──
if [[ "$BACKUP_KEEP" -gt 0 ]]; then
  mapfile -t ALL < <(ls -1t "$BACKUP_DIR/${DB_NAME}-"*.sql.gz 2>/dev/null || true)
  if (( ${#ALL[@]} > BACKUP_KEEP )); then
    for old in "${ALL[@]:BACKUP_KEEP}"; do
      rm -f "$old" && log_info "حذف نسخة قديمة: $(basename "$old")"
    done
  fi
  log_info "النسخ المحفوظة: $(( ${#ALL[@]} > BACKUP_KEEP ? BACKUP_KEEP : ${#ALL[@]} )) / حدّ $BACKUP_KEEP"
fi

log_ok "تمّت العملية بنجاح"
