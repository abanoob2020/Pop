#!/usr/bin/env bash
# Guarded staging migration runner for XCAMP Fitness OS.
#
#   * Prints the target (no password) and refuses production-looking names.
#   * Runs `alembic upgrade head` ONLY after the guard passes.
#
# Usage:
#   FITNESS_DB_HOST=... FITNESS_DB_PORT=... FITNESS_DB_NAME=xcamp_os_staging \
#   FITNESS_DB_USER=xcamp_app FITNESS_DB_PASSWORD=*** ./scripts/migrate_staging.sh
#
set -euo pipefail
cd "$(dirname "$0")/.."

echo ">> Pre-flight target check"
python scripts/print_target.py

TARGET="${FITNESS_DB_NAME:-xcamp_os_staging}"
if [[ "$TARGET" == "xcamp_os" || "$TARGET" == *"_prod" ]]; then
  echo "ABORT: refusing to migrate a production-looking database ($TARGET)." >&2
  exit 2
fi

echo ">> Running: alembic upgrade head  (target: $TARGET)"
alembic upgrade head

echo ">> Current revision:"
alembic current
echo ">> Done."
