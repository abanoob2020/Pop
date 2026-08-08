#!/usr/bin/env bash
# =============================================================================
# xcamp-gym-sql : restore.sh — استعادة القاعدة من نسخة backup.sh (.sql.gz أو .sql)
# -----------------------------------------------------------------------------
# يستعيد ملف نسخة أنشأه backup.sh. الملف يتضمّن CREATE DATABASE + USE (أُنشئ بـ
# --databases) فيعيد بناء القاعدة كما كانت. عملية مُدمِّرة: تكتب فوق البيانات
# الحالية، لذا يطلب تأكيدًا صريحًا (أو مرّر --force / FORCE=1 للتشغيل غير التفاعلي).
#
# الاستخدام:
#   ./restore.sh <ملف-النسخة>
#   ./restore.sh backups/xcamp_gym-20260808-120000.sql.gz
#   ./restore.sh --latest                 # أحدث نسخة في BACKUP_DIR
#   FORCE=1 ./restore.sh --latest         # بلا سؤال تأكيد (لأتمتة/سكربتات)
#
# الإعداد عبر متغيّرات البيئة (نفس متغيّرات النشر):
#   DB_HOST DB_PORT DB_USER DB_PASS DB_NAME  BACKUP_DIR($ROOT/backups)  FORCE(0)
# =============================================================================
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

: "${DB_HOST:=127.0.0.1}"
: "${DB_PORT:=3306}"
: "${DB_USER:=root}"
: "${DB_PASS:=}"
: "${DB_NAME:=xcamp_gym}"
: "${BACKUP_DIR:=$ROOT_DIR/backups}"
: "${FORCE:=0}"

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

command -v mysql >/dev/null 2>&1 || { log_err "mysql غير متوفّر في PATH"; exit 1; }

# ── تحديد ملف النسخة ──
FILE=""
for arg in "$@"; do
  case "$arg" in
    --force) FORCE=1 ;;
    --latest)
      FILE="$(ls -1t "$BACKUP_DIR/${DB_NAME}-"*.sql.gz 2>/dev/null | head -1 || true)"
      [[ -n "$FILE" ]] || { log_err "لا توجد نسخ في $BACKUP_DIR"; exit 1; }
      ;;
    -*) log_err "خيار غير معروف: $arg"; exit 1 ;;
    *)  FILE="$arg" ;;
  esac
done

if [[ -z "$FILE" ]]; then
  log_err "حدّد ملف النسخة أو استخدم --latest"
  echo "الاستخدام: ./restore.sh <ملف.sql.gz> | --latest" >&2
  exit 1
fi
[[ -f "$FILE" ]] || { log_err "الملف غير موجود: $FILE"; exit 1; }

# ── تأكيد (عملية مُدمِّرة) ──
if [[ "$FORCE" != "1" ]]; then
  log_warn "ستُستبدَل قاعدة «$DB_NAME» على $DB_HOST:$DB_PORT بمحتوى:"
  echo "        $FILE"
  read -r -p "اكتب اسم القاعدة للتأكيد ($DB_NAME): " CONFIRM
  if [[ "$CONFIRM" != "$DB_NAME" ]]; then
    log_err "أُلغيت الاستعادة (لم يتطابق التأكيد)."
    exit 1
  fi
fi

# مصادقة عبر ملف مؤقّت (0600) بدل سطر الأوامر.
CNF="$(mktemp)"; chmod 600 "$CNF"
trap 'rm -f "$CNF"' EXIT
{
  printf '[client]\n'
  printf 'host=%s\n' "$DB_HOST"
  printf 'port=%s\n' "$DB_PORT"
  printf 'user=%s\n' "$DB_USER"
  [[ -n "$DB_PASS" ]] && printf 'password=%s\n' "$DB_PASS"
  printf 'default-character-set=utf8mb4\n'
} > "$CNF"

# التحقّق من الاتصال قبل الاستعادة.
mysql --defaults-extra-file="$CNF" -e "SELECT 1;" >/dev/null 2>&1 \
  || { log_err "تعذّر الاتصال بخادم القاعدة — راجع بيانات الاتصال."; exit 1; }

log_info "بدء الاستعادة من: $FILE"

# فكّ الضغط إن كان .gz، ثم مرّره لـ mysql. الملف يحوي CREATE/USE للقاعدة نفسها.
if [[ "$FILE" == *.gz ]]; then
  if gzip -dc "$FILE" | mysql --defaults-extra-file="$CNF"; then
    log_ok "اكتملت الاستعادة إلى «$DB_NAME»."
  else
    log_err "فشلت الاستعادة."; exit 1
  fi
else
  if mysql --defaults-extra-file="$CNF" < "$FILE"; then
    log_ok "اكتملت الاستعادة إلى «$DB_NAME»."
  else
    log_err "فشلت الاستعادة."; exit 1
  fi
fi

# تحقّق سريع: عدد الجداول بعد الاستعادة.
CNT="$(mysql --defaults-extra-file="$CNF" -N -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME';" 2>/dev/null || echo '?')"
log_info "عدد الجداول في «$DB_NAME» بعد الاستعادة: $CNT"
log_ok "تمّت العملية بنجاح"
