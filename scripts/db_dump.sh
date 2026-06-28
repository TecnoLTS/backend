#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEFAULT_BACKUP_DIR="${ROOT_DIR}/../secure-backups/backend"
OUT_FILE="${OUT_FILE:-$DEFAULT_BACKUP_DIR/db-$(date +%Y%m%d-%H%M%S).sql}"

DB_NAME="${DB_DATABASE:-paramascotasec}"
DB_USER="${DB_USERNAME:-postgres}"
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-5432}"
DB_PASS="${DB_PASSWORD:-}"
DB_CONTAINER="${DB_DOCKER_CONTAINER:-}"

mkdir -p "$(dirname "$OUT_FILE")"

if [[ -n "$DB_CONTAINER" ]]; then
  docker exec -i "$DB_CONTAINER" pg_dump -U "$DB_USER" -d "$DB_NAME" --no-owner --no-privileges > "$OUT_FILE"
  exit 0
fi

if command -v pg_dump >/dev/null 2>&1; then
  PGPASSWORD="$DB_PASS" pg_dump -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" --no-owner --no-privileges > "$OUT_FILE"
  exit 0
fi

echo "Error: pg_dump not found. Set DB_DOCKER_CONTAINER or install PostgreSQL client tools." >&2
exit 1
