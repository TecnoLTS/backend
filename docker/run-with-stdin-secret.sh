#!/bin/sh
set -eu

secret_name="${1:-}"
shift || true
case "${secret_name}" in
  DB_WORKER_PASSWORD|DB_WORKER_PASSWORD_IDENTITY_PLATFORM)
    ;;
  *)
    echo "Unsupported stdin secret target" >&2
    exit 64
    ;;
esac
if [ "$#" -eq 0 ]; then
  echo "Missing one-shot worker command" >&2
  exit 64
fi

IFS= read -r secret_value
if [ -z "${secret_value}" ]; then
  echo "Missing one-shot worker secret" >&2
  exit 65
fi
export "${secret_name}=${secret_value}"
unset secret_value
exec "$@"
