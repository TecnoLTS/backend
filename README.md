# Backend de ParaMascotas (`paramascotasec-backend`)

API principal en PHP del workspace.

## Deploy del componente

Desde el repo:

```bash
cd /home/admincenter/contenedores/paramascotasec-backend
./scripts/deploy.sh development
./scripts/deploy.sh production
```

Desde la raiz del workspace:

```bash
cd /home/admincenter/contenedores
./scripts/deploy.sh development backend
./scripts/deploy.sh production backend
```

Si necesitas bootstrap de esquema en development:

```bash
RUN_DB_SETUP=1 ./scripts/deploy.sh development
```

El backend no necesita un runtime hot separado.
Los cambios se validan redeployando el componente con el modo correspondiente.

## Contexto importante

- El archivo real es `entorno/.env`.
- La plantilla versionada vive en `templates/entorno/.env.example`.
- Billing SRI vive dentro de este runtime bajo `platform-core/Billing`.
- `Facturador` ya no es upstream del workspace orquestado.
- Las bases logicas principales viven en el PostgreSQL compartido y se resuelven por `ConnectionRegistry`.

## Operaciones de cuidado

Reset de ventas:

```bash
./scripts/reset_sales_data.sh development --yes
```

Ese script borra ventas, movimientos y auditorias operativas; usarlo solo cuando haga falta de forma explicita.

## Validacion

```bash
php scripts/check_modular_routes.php
docker exec paramascotasec-backend-app php scripts/check_module_databases.php
```

## Post-prune total

Si ejecutaste `docker system prune -a --volumes` y borraste tambien la data local de PostgreSQL del workspace, el orden limpio recomendado es:

```bash
cd /home/admincenter/contenedores
./scripts/deploy.sh development db
RUN_DB_SETUP=1 SEED_DEVELOPMENT_CATALOG=1 ./scripts/deploy.sh development backend
```

El deploy backend ahora reconstruye primero su imagen antes de levantar el worker fiscal, para evitar fallos por imagen local ausente despues de un wipe total.
