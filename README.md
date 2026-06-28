# Backend de ParaMascotas (`backend`)

API principal en PHP del workspace.

## Despliegues

Despliegue completo del workspace:

```bash
cd /home/admincenter/contenedores
./deploy.sh
```

Despliegue individual del backend desde la raiz:

```bash
cd /home/admincenter/contenedores
./scripts/deploy.sh backend
```

Despliegue individual desde este repo:

```bash
cd /home/admincenter/contenedores/backend
./scripts/deploy.sh
```

Si necesitas bootstrap de esquema en QA, ejecutalo desde la raiz:

```bash
cd /home/admincenter/contenedores
RUN_DB_SETUP=1 ./scripts/deploy.sh backend
```

El backend no necesita un runtime hot separado.
Los cambios se validan redeployando el componente con el modo correspondiente.

## Contexto importante

- El archivo real es `entorno/.env`.
- La plantilla versionada vive en `templates/entorno/.env.example`.
- Billing SRI vive dentro de este runtime bajo `platform-core/Billing`.
- No hay upstream fiscal paralelo en el workspace orquestado.
- Las bases logicas principales viven en el PostgreSQL compartido y se resuelven por `ConnectionRegistry`.

## Operaciones de cuidado

Reset de ventas:

```bash
./scripts/reset_sales_data.sh qa --yes
```

Ese script borra ventas, movimientos y auditorias operativas; usarlo solo cuando haga falta de forma explicita.

## Validacion

```bash
php scripts/check_modular_routes.php
docker exec backend-api php scripts/check_module_databases.php
```

## Post-prune total

Si ejecutaste `docker system prune -a --volumes` y borraste tambien la data local de PostgreSQL del workspace, el orden limpio recomendado es:

```bash
cd /home/admincenter/contenedores
./scripts/deploy.sh db
RUN_DB_SETUP=1 SEED_QA_CATALOG=1 ./scripts/deploy.sh backend
```

El deploy backend ahora reconstruye primero su imagen antes de levantar el worker fiscal, para evitar fallos por imagen local ausente despues de un wipe total.
