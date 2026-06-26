#!/usr/bin/env bash
set -euo pipefail

cat >&2 <<'EOF'
Este utilitario legacy quedo deshabilitado.

La topologia vigente usa un solo servicio PostgreSQL compartido y una base logica
por modulo o servicio, no una base por tenant.

- Los tenants del mismo modulo comparten la base de ese modulo.
- El aislamiento entre modulos se hace por base de datos y contratos API.
- Para sincronizar bases/roles del clúster compartido usa:

  cd /home/admincenter/contenedores/paramascotasec-DB
  ./scripts/sync-module-databases.sh development
  ./scripts/sync-module-databases.sh production

Si necesitas crear un modulo nuevo, registralo primero en:
  paramascotasec-DB/config/module-databases.json
EOF

exit 1
