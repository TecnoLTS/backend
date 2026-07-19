#!/usr/bin/env bash

set -euo pipefail

echo "Operacion deshabilitada: el backend no mantiene un flujo paralelo de dumps SQL sin cifrar." >&2
echo "Usa /home/admincenter/contenedores/basesdedatos/scripts/backup-and-stop.sh con el alcance requerido." >&2
exit 1
