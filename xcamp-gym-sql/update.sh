#!/usr/bin/env bash
# =============================================================================
# xcamp-gym-sql / update.sh
# -----------------------------------------------------------------------------
# تحديث اللوحة بأمر واحد: يسحب أحدث كود من الفرع، (اختياريًا) يعيد نشر قاعدة
# البيانات، ثم يعيد تشغيل سيرفر PHP للوحة.
#
# الاستخدام:
#   ./update.sh                 # سحب أحدث كود + (إعادة) تشغيل السيرفر
#   ./update.sh --deploy        # + إعادة نشر السكيمة والبيانات (deploy.sh)
#   ./update.sh --reset         # + إعادة بناء قاعدة البيانات من الصفر (reset_and_deploy.sh)
#   ./update.sh --no-server     # سحب الكود فقط بدون تشغيل السيرفر
#
# متغيّرات البيئة (نفس deploy.sh + خيارات السيرفر):
#   DB_HOST DB_PORT DB_USER DB_PASS DB_NAME
#   SERVE_HOST (افتراضي localhost)   SERVE_PORT (افتراضي 8000)
#   GIT_BRANCH (افتراضي الفرع الحالي)
# =============================================================================
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DASH_DIR="$ROOT_DIR/dashboard"

: "${DB_HOST:=127.0.0.1}"
: "${DB_PORT:=3306}"
: "${DB_USER:=root}"
: "${DB_PASS:=}"
: "${DB_NAME:=xcamp_gym}"
: "${SERVE_HOST:=localhost}"
: "${SERVE_PORT:=8000}"

DO_DEPLOY=0
DO_RESET=0
DO_SERVER=1
for arg in "$@"; do
  case "$arg" in
    --deploy)    DO_DEPLOY=1 ;;
    --reset)     DO_RESET=1 ;;
    --no-server) DO_SERVER=0 ;;
    -h|--help)
      sed -n '2,17p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    *) echo "خيار غير معروف: $arg (جرّب --help)" >&2; exit 1 ;;
  esac
done

USE_COLOR=0
if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then USE_COLOR=1; fi
if [[ "$USE_COLOR" -eq 1 ]]; then
  GREEN=$'\033[0;32m'; BLUE=$'\033[0;34m'; YELLOW=$'\033[0;33m'; RED=$'\033[0;31m'; NC=$'\033[0m'
else
  GREEN=""; BLUE=""; YELLOW=""; RED=""; NC=""
fi
log_info() { printf "%b[INFO]%b %s\n" "$BLUE" "$NC" "$1"; }
log_ok()   { printf "%b[OK]%b %s\n" "$GREEN" "$NC" "$1"; }
log_warn() { printf "%b[WARN]%b %s\n" "$YELLOW" "$NC" "$1"; }
log_err()  { printf "%b[ERR]%b %s\n" "$RED" "$NC" "$1" >&2; }

# --- 1) سحب أحدث كود ---------------------------------------------------------
if command -v git >/dev/null 2>&1 && git -C "$ROOT_DIR" rev-parse --git-dir >/dev/null 2>&1; then
  BRANCH="${GIT_BRANCH:-$(git -C "$ROOT_DIR" rev-parse --abbrev-ref HEAD)}"
  log_info "سحب أحدث كود من origin/$BRANCH ..."
  if ! git -C "$ROOT_DIR" diff --quiet || ! git -C "$ROOT_DIR" diff --cached --quiet; then
    log_warn "توجد تعديلات محلية غير محفوظة — تخطّي git pull حتى لا تُفقد. احفظها (commit/stash) ثم أعد المحاولة."
  elif git -C "$ROOT_DIR" pull --ff-only origin "$BRANCH"; then
    log_ok "الكود مُحدَّث."
  else
    log_warn "تعذّر تحديث الكود بأمان (fast-forward). راجع: git status — قد تحتاج merge/rebase يدويًا. أكمل بالكود الحالي."
  fi
else
  log_warn "هذا ليس مستودع git — تخطّي سحب الكود."
fi

# --- 2) قاعدة البيانات (اختياري) --------------------------------------------
if [[ "$DO_RESET" -eq 1 ]]; then
  log_info "إعادة بناء قاعدة البيانات من الصفر (reset_and_deploy.sh) ..."
  DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_USER="$DB_USER" DB_PASS="$DB_PASS" DB_NAME="$DB_NAME" \
    bash "$ROOT_DIR/reset_and_deploy.sh"
  log_ok "أُعيد بناء قاعدة البيانات."
elif [[ "$DO_DEPLOY" -eq 1 ]]; then
  log_info "إعادة نشر السكيمة والبيانات (deploy.sh) ..."
  DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_USER="$DB_USER" DB_PASS="$DB_PASS" DB_NAME="$DB_NAME" \
    bash "$ROOT_DIR/deploy.sh"
  log_ok "أُعيد نشر قاعدة البيانات."
else
  log_info "بدون تغيير في قاعدة البيانات (ذكاء الأحمال لا يحتاج migration). استخدم --deploy أو --reset عند الحاجة."
fi

# --- 3) سيرفر اللوحة ---------------------------------------------------------
if [[ "$DO_SERVER" -eq 0 ]]; then
  log_ok "تم — بدون تشغيل السيرفر (--no-server)."
  exit 0
fi

command -v php >/dev/null 2>&1 || { log_err "PHP غير مثبّت — لا يمكن تشغيل السيرفر."; exit 1; }

# أوقف أي سيرفر سابق على نفس المنفذ (بدأناه نحن) ثم شغّل واحدًا جديدًا
PATTERN="php -S ${SERVE_HOST}:${SERVE_PORT}"
if pgrep -f "$PATTERN" >/dev/null 2>&1; then
  log_info "إيقاف سيرفر PHP سابق على ${SERVE_HOST}:${SERVE_PORT} ..."
  pkill -f "$PATTERN" || true
  sleep 1
fi

log_info "تشغيل سيرفر اللوحة على http://${SERVE_HOST}:${SERVE_PORT} ..."
log_info "افتح المتصفح على العنوان أعلاه. أوقف السيرفر بـ Ctrl+C."
cd "$DASH_DIR"
exec env DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_USER="$DB_USER" DB_PASS="$DB_PASS" DB_NAME="$DB_NAME" \
  php -S "${SERVE_HOST}:${SERVE_PORT}"
