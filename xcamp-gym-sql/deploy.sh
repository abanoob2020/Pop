#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SQL_DIR="$ROOT_DIR/sql"
LOG_DIR="$ROOT_DIR/logs"
mkdir -p "$LOG_DIR"

: "${DB_HOST:=127.0.0.1}"
: "${DB_PORT:=3306}"
: "${DB_USER:=root}"
: "${DB_PASS:=}"
: "${DB_NAME:=xcamp_gym}"
: "${DB_SEED:=1}"

# ── Mode: --bootstrap (full build) or --migrate (safe additive only) ──────────
MODE=""
for arg in "$@"; do
  case "$arg" in
    --bootstrap) MODE="bootstrap" ;;
    --migrate)   MODE="migrate"   ;;
    -h|--help)
      cat <<'HELP'
Usage: deploy.sh --bootstrap | --migrate

  --bootstrap   Full schema build: drops and recreates all tables, loads
                procedures/triggers/views, seeds demo data (unless DB_SEED=0).
                USE ONLY for first-time setup or dev resets.

  --migrate     Additive upgrades only: runs CREATE TABLE IF NOT EXISTS
                migrations (08→19+). Refuses any file containing DROP TABLE
                or TRUNCATE. Takes a backup before running.

Environment variables: DB_HOST DB_PORT DB_USER DB_PASS DB_NAME DB_SEED
HELP
      exit 0 ;;
    *) echo "Unknown option: $arg (try --help)" >&2; exit 1 ;;
  esac
done

if [[ -z "$MODE" ]]; then
  cat >&2 <<'ERR'
ERROR: Explicit mode required.

  deploy.sh --bootstrap    First-time / dev rebuild (DESTRUCTIVE)
  deploy.sh --migrate      Production-safe additive upgrades

Refusing to run without a mode to prevent accidental data loss.
ERR
  exit 1
fi

# ── Colour output ─────────────────────────────────────────────────────────────
USE_COLOR=0
if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then USE_COLOR=1; fi
if [[ "$USE_COLOR" -eq 1 ]]; then
  RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[0;33m'
  BLUE=$'\033[0;34m'; BOLD=$'\033[1m'; NC=$'\033[0m'
else
  RED=""; GREEN=""; YELLOW=""; BLUE=""; BOLD=""; NC=""
fi

log_info() { printf "%b[INFO]%b %s\n" "$BLUE" "$NC" "$1"; }
log_ok()   { printf "%b[OK]%b %s\n" "$GREEN" "$NC" "$1"; }
log_warn() { printf "%b[WARN]%b %s\n" "$YELLOW" "$NC" "$1"; }
log_err()  { printf "%b[ERR]%b %s\n" "$RED" "$NC" "$1" >&2; }

# ── Build file list per mode ──────────────────────────────────────────────────
BOOTSTRAP_FILES=(
  "00_init.sql"
  "01_tables.sql"
  "02_procedures.sql"
  "03_triggers.sql"
  "04_events.sql"
  "05_views.sql"
)
if [[ "$DB_SEED" != "0" ]]; then
  BOOTSTRAP_FILES+=("06_seed_data.sql")
fi

MIGRATE_FILES=()

ADDITIVE_FILES=(
  "08_workout_v2.sql"
  "09_nutrition_v2.sql"
  "10_member_portal.sql"
  "11_finance_pos.sql"
  "12_checkin_qr.sql"
  "13_coach_hr.sql"
  "14_pt_sessions.sql"
  "15_referrals.sql"
  "16_assessments.sql"
  "17_assessment_clinical.sql"
  "18_recipes.sql"
  "19_training_max.sql"
)

SEED_EXTRA=()
if [[ "$DB_SEED" != "0" ]]; then
  SEED_EXTRA+=("20_seed_demo_portal.sql" "07_test_queries.sql")
fi

if [[ "$MODE" == "bootstrap" ]]; then
  FILES=("${BOOTSTRAP_FILES[@]}" "${ADDITIVE_FILES[@]}" "${SEED_EXTRA[@]}")
else
  FILES=("${MIGRATE_FILES[@]}" "${ADDITIVE_FILES[@]}")
fi

# ── Content guard (migrate mode): refuse destructive SQL ──────────────────────
if [[ "$MODE" == "migrate" ]]; then
  for f in "${FILES[@]}"; do
    filepath="$SQL_DIR/$f"
    [[ -f "$filepath" ]] || { log_err "Missing file: sql/$f"; exit 1; }
    if grep -qiE '^\s*(DROP\s+TABLE|DROP\s+DATABASE|TRUNCATE\s)' "$filepath"; then
      log_err "BLOCKED: $f contains DROP TABLE/DATABASE or TRUNCATE — not allowed in --migrate mode."
      log_err "If this is intentional, use --bootstrap (DESTRUCTIVE) instead."
      exit 1
    fi
  done
  log_ok "Content guard passed: no destructive statements in migration files."
fi

# ── Require files exist ──────────────────────────────────────────────────────
require_file() {
  local f="$SQL_DIR/$1"
  [[ -f "$f" ]] || { log_err "Missing file: sql/$1"; exit 1; }
}
for f in "${FILES[@]}"; do
  require_file "$f"
done

# ── MySQL connection arrays ───────────────────────────────────────────────────
MYSQL_CMD=(mysql --protocol=tcp -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER"
  --database="$DB_NAME" --default-character-set=utf8mb4 --silent --show-warnings)
if [[ -n "$DB_PASS" ]]; then
  MYSQL_CMD+=(--password="$DB_PASS")
fi

SERVER_CMD=(mysql --protocol=tcp -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER"
  --default-character-set=utf8mb4)
if [[ -n "$DB_PASS" ]]; then
  SERVER_CMD+=(--password="$DB_PASS")
fi

# ── Bootstrap guard: refuse on a populated database ──────────────────────────
if [[ "$MODE" == "bootstrap" ]]; then
  tbl_count=$("${SERVER_CMD[@]}" -N -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'" 2>/dev/null || echo 0)
  if [[ "$tbl_count" -gt 0 ]]; then
    log_err "REFUSED: '$DB_NAME' has $tbl_count tables — --bootstrap is for EMPTY databases only."
    log_err "For dev resets use dev_reset_DESTRUCTIVE.sh (drops the DB first)."
    log_err "For production upgrades use --migrate."
    exit 1
  fi
fi

# ── Mandatory backup before migrate ──────────────────────────────────────────
if [[ "$MODE" == "migrate" ]]; then
  log_info "Taking mandatory pre-migration backup ..."
  if [[ -x "$ROOT_DIR/backup.sh" ]]; then
    DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_USER="$DB_USER" \
      DB_PASS="$DB_PASS" DB_NAME="$DB_NAME" \
      bash "$ROOT_DIR/backup.sh"
    log_ok "Pre-migration backup complete."
  else
    log_err "backup.sh not found or not executable — cannot proceed without a backup."
    exit 1
  fi
fi

# ── Run SQL files ─────────────────────────────────────────────────────────────
run_sql() {
  local file="$1"
  log_info "Running $file"
  if [[ "$file" == "06_seed_data.sql" ]]; then
    { printf 'SET @seeding=1;\n'; cat "$SQL_DIR/$file"; } \
      | "${MYSQL_CMD[@]}" >> "$LOG_DIR/deploy.log" 2>&1
  else
    "${MYSQL_CMD[@]}" < "$SQL_DIR/$file" >> "$LOG_DIR/deploy.log" 2>&1
  fi
  log_ok "Finished $file"
}

: > "$LOG_DIR/deploy.log"

if [[ "$MODE" == "bootstrap" ]]; then
  if [[ "$DB_SEED" == "0" ]]; then
    log_info "Starting BOOTSTRAP deployment (DB_SEED=0, no demo data) for database: $DB_NAME"
  else
    log_info "Starting BOOTSTRAP deployment (with demo data) for database: $DB_NAME"
  fi
else
  log_info "Starting MIGRATE deployment (additive only) for database: $DB_NAME"
fi

"${SERVER_CMD[@]}" -e \
  "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >/dev/null

for f in "${FILES[@]}"; do
  run_sql "$f"
done

# ── Record migrations in schema_migrations ────────────────────────────────────
"${MYSQL_CMD[@]}" <<'MIGRATION_TABLE'
CREATE TABLE IF NOT EXISTS schema_migrations (
  filename     VARCHAR(255) NOT NULL PRIMARY KEY,
  applied_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deploy_mode  VARCHAR(20)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
MIGRATION_TABLE

for f in "${FILES[@]}"; do
  "${MYSQL_CMD[@]}" -e \
    "INSERT INTO schema_migrations (filename, deploy_mode) VALUES ('$f', '$MODE')
     ON DUPLICATE KEY UPDATE applied_at = CURRENT_TIMESTAMP, deploy_mode = '$MODE';"
done

log_ok "Deployment ($MODE) completed successfully."
