#!/usr/bin/env bash

set -euo pipefail

echo "Operacion deshabilitada: el backend no restaura SQL directamente." >&2
echo "Usa /home/admincenter/contenedores/basesdedatos/scripts/restore-from-backup.sh y sus confirmaciones canonicas." >&2
exit 1
