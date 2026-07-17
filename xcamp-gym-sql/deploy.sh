#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_DIR="$ROOT_DIR/logs"
mkdir -p "$LOG_DIR"

: "${DB_HOST:=127.0.0.1}"
: "${DB_PORT:=3306}"
: "${DB_USER:=root}"
: "${DB_PASS:=}"
: "${DB_NAME:=xcamp_gym}"

FILES=(
  "00_init.sql"
  "01_tables.sql"
  "02_procedures.sql"
  "03_triggers.sql"
  "04_events.sql"
  "05_views.sql"
  "06_seed_data.sql"
  "07_test_queries.sql"
)

USE_COLOR=0
if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then
  USE_COLOR=1
fi

if [[ "$USE_COLOR" -eq 1 ]]; then
  RED=$'\033[0;31m'
  GREEN=$'\033[0;32m'
  YELLOW=$'\033[0;33m'
  BLUE=$'\033[0;34m'
  BOLD=$'\033[1m'
  NC=$'\033[0m'
else
  RED=""
  GREEN=""
  YELLOW=""
  BLUE=""
  BOLD=""
  NC=""
fi

log_info() { printf "%b[INFO]%b %s\n" "$BLUE" "$NC" "$1"; }
log_ok()   { printf "%b[OK]%b %s\n" "$GREEN" "$NC" "$1"; }
log_warn()  { printf "%b[WARN]%b %s\n" "$YELLOW" "$NC" "$1"; }
log_err()   { printf "%b[ERR]%b %s\n" "$RED" "$NC" "$1" >&2; }

require_file() {
  local f="$ROOT_DIR/$1"
  [[ -f "$f" ]] || { log_err "Missing file: $1"; exit 1; }
}

for f in "${FILES[@]}"; do
  require_file "$f"
done

MYSQL_CMD=(mysql --protocol=tcp -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" --database="$DB_NAME" --default-character-set=utf8mb4 --silent --show-warnings)
if [[ -n "$DB_PASS" ]]; then
  MYSQL_CMD+=(--password="$DB_PASS")
fi

# Server-level connection (no --database), used for connectivity check + ensuring
# the target database exists before the --database connections below run.
SERVER_CMD=(mysql --protocol=tcp -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" --default-character-set=utf8mb4)
if [[ -n "$DB_PASS" ]]; then
  SERVER_CMD+=(--password="$DB_PASS")
fi

run_sql() {
  local file="$1"
  log_info "Running $file"
  if [[ "$file" == "06_seed_data.sql" ]]; then
    # Each file runs in its own connection, so set @seeding in the same pipe as
    # the seed to suppress trigger side effects while the fixed-ID rows load.
    { printf 'SET @seeding=1;\n'; cat "$ROOT_DIR/$file"; } \
      | "${MYSQL_CMD[@]}" >> "$LOG_DIR/deploy.log" 2>&1
  else
    "${MYSQL_CMD[@]}" < "$ROOT_DIR/$file" >> "$LOG_DIR/deploy.log" 2>&1
  fi
  log_ok "Finished $file"
}

: > "$LOG_DIR/deploy.log"

log_info "Starting deployment for database: $DB_NAME"

# Verify connectivity and make sure the database exists (00_init.sql also creates
# it, but the --database connections need it to exist to connect at all).
"${SERVER_CMD[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >/dev/null

for f in "${FILES[@]}"; do
  run_sql "$f"
done

log_ok "Deployment completed successfully"
