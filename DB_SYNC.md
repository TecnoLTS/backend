# DB sync (respaldos fuera de Git)

Los dumps de base de datos pueden contener PII de clientes, pedidos, direcciones y tokens operativos. No deben viajar con Git.

## 1) Hook de Git

El hook `.githooks/pre-commit` bloquea commits que intenten incluir `db/backup.sql` o `db/backups/`.

## 2) Configura conexión a la BD

Si usas Docker:

```bash
export DB_DOCKER_CONTAINER=basesdedatos
export DB_DATABASE=paramascotasec
export DB_USERNAME=postgres
```

Si usas cliente local:

```bash
export DB_HOST=localhost
export DB_PORT=5432
export DB_DATABASE=paramascotasec
export DB_USERNAME=postgres
export DB_PASSWORD=change-this-to-a-strong-password
```

Usa la misma clave definida en `paramascotas-DB/.env`. Si mantienes `POSTGRES_BIND_IP=127.0.0.1`, para acceso remoto usa tunel SSH.

## 3) Dump manual

```bash
OUT_FILE=/home/admincenter/secure-backups/backend/db-$(date +%Y%m%d-%H%M%S).sql ./scripts/db_dump.sh
```

## 4) Restaurar en otro entorno

```bash
IN_FILE=/home/admincenter/secure-backups/backend/db-YYYYMMDD-HHMMSS.sql ./scripts/db_restore.sh
```
