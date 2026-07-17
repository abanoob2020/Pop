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

# Export so the child deploy.sh inherits the same connection/config even when
# these came from the in-script defaults rather than the command line.
export DB_HOST DB_PORT DB_USER DB_PASS DB_NAME

USE_COLOR=0
if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then
  USE_COLOR=1
fi

if [[ "$USE_COLOR" -eq 1 ]]; then
  RED=$'\033[0;31m'
  GREEN=$'\033[0;32m'
  YELLOW=$'\033[0;33m'
  BLUE=$'\033[0;34m'
  NC=$'\033[0m'
else
  RED=""
  GREEN=""
  YELLOW=""
  BLUE=""
  NC=""
fi

log_info() { printf "%b[INFO]%b %s\n" "$BLUE" "$NC" "$1"; }
log_ok()   { printf "%b[OK]%b %s\n" "$GREEN" "$NC" "$1"; }
log_err()  { printf "%b[ERR]%b %s\n" "$RED" "$NC" "$1" >&2; }

if [[ -z "$DB_NAME" ]]; then
  log_err "DB_NAME is empty"
  exit 1
fi

if [[ -n "$DB_PASS" ]]; then
  MYSQL_BASE=(mysql --protocol=tcp -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" --password="$DB_PASS")
  MYSQL_TEST=(mysql --protocol=tcp -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" --password="$DB_PASS" -e)
else
  MYSQL_BASE=(mysql --protocol=tcp -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER")
  MYSQL_TEST=(mysql --protocol=tcp -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -e)
fi

log_info "Dropping and recreating database: $DB_NAME"
"${MYSQL_TEST[@]}" "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >/dev/null

log_info "Running deployment"
"$ROOT_DIR/deploy.sh"

log_ok "Reset and deploy completed successfully"
