#!/usr/bin/env bash
# =============================================================================
# DESTRUCTIVE — dev/test ONLY
# =============================================================================
# Drops the ENTIRE database and rebuilds from scratch via deploy.sh --bootstrap.
# NEVER run this against a production database.
#
# Safety gates (both required):
#   1. XCAMP_DEV_RESET=1 environment variable
#   2. Interactive confirmation (bypass with FORCE=1 for CI/automation)
# =============================================================================
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_DIR="$ROOT_DIR/logs"
mkdir -p "$LOG_DIR"

: "${DB_HOST:=127.0.0.1}"
: "${DB_PORT:=3306}"
: "${DB_USER:=root}"
: "${DB_PASS:=}"
: "${DB_NAME:=xcamp_gym}"
: "${FORCE:=0}"

export DB_HOST DB_PORT DB_USER DB_PASS DB_NAME

USE_COLOR=0
if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then USE_COLOR=1; fi
if [[ "$USE_COLOR" -eq 1 ]]; then
  RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[0;33m'
  BLUE=$'\033[0;34m'; NC=$'\033[0m'
else
  RED=""; GREEN=""; YELLOW=""; BLUE=""; NC=""
fi
log_info() { printf "%b[INFO]%b %s\n" "$BLUE" "$NC" "$1"; }
log_ok()   { printf "%b[OK]%b %s\n" "$GREEN" "$NC" "$1"; }
log_err()  { printf "%b[ERR]%b %s\n" "$RED" "$NC" "$1" >&2; }

# ── Gate 1: environment variable ──────────────────────────────────────────────
if [[ "${XCAMP_DEV_RESET:-}" != "1" ]]; then
  log_err "REFUSED: Set XCAMP_DEV_RESET=1 to confirm this is a dev/test environment."
  log_err "This script DROPS the entire '$DB_NAME' database. It is not for production."
  exit 1
fi

# ── Gate 2: interactive confirmation ──────────────────────────────────────────
if [[ -z "$DB_NAME" ]]; then
  log_err "DB_NAME is empty"
  exit 1
fi

if [[ "$FORCE" != "1" ]]; then
  printf "%b⚠️  WARNING: This will DROP DATABASE '%s' and ALL its data.%b\n" "$RED" "$DB_NAME" "$NC"
  printf "Type the database name to confirm: "
  read -r confirm
  if [[ "$confirm" != "$DB_NAME" ]]; then
    log_err "Confirmation failed — aborting."
    exit 1
  fi
fi

# ── Execute ───────────────────────────────────────────────────────────────────
if [[ -n "$DB_PASS" ]]; then
  MYSQL_CMD=(mysql --protocol=tcp -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" --password="$DB_PASS")
else
  MYSQL_CMD=(mysql --protocol=tcp -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER")
fi

log_info "Dropping and recreating database: $DB_NAME"
"${MYSQL_CMD[@]}" -e \
  "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >/dev/null

log_info "Running full bootstrap deployment ..."
"$ROOT_DIR/deploy.sh" --bootstrap

log_ok "Dev reset completed successfully."
