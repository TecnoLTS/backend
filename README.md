# Backend de ParaMascotas (`backend`)

API principal en PHP del workspace.

## Fuente canonica de IVA por tenant

`tenant_runtime_registry.tenants[*].ecommerce_configuration` es la unica fuente
operativa para `defaultTaxRate`, `purchaseVatCreditCurrentRate` y
`purchaseVatCreditCarryforwardRate`. Catalogo, checkout, inventario, BI y la
pantalla tributaria consumen la proyeccion firmada de ese registro; las filas
legacy de `Setting` ya no participan en lecturas runtime.

Antes del primer despliegue de este corte se debe revisar el plan y ejecutar una
migracion explicita. No hay fallback silencioso cuando falta un valor y toda
divergencia exige elegir `--prefer=legacy` o `--prefer=canonical`:

```bash
php scripts/migrate_vat_rate_to_tenant_registry.php \
  --missing-default-rate=15 \
  --missing-credit-current=60 \
  --missing-credit-carryforward=40

php scripts/migrate_vat_rate_to_tenant_registry.php --execute \
  --maintenance-window-confirmed \
  --expected-revision=REVISION_DEL_PLAN \
  --prefer=legacy \
  --missing-default-rate=15 \
  --missing-credit-current=60 \
  --missing-credit-carryforward=40
```

El plan informa `startingRevision`. La ejecucion exige esa revision exacta y
una ventana de mantenimiento que drene escrituras legacy; si cambia el registro
o no puede sincronizarse la proyeccion firmada, el cutover falla cerrado.

La lectura anonima de `/api/products` puede conservar la proyeccion fiscal
anterior durante un maximo de 15 segundos por el cache FastCGI. El `PUT` fiscal
devuelve ese limite como `catalogTaxProjectionMaxStalenessSeconds` solo cuando
`catalogTaxProjectionSynchronized=true`. Si la mutacion canonica fue confirmada
pero fallo la proyeccion firmada, responde `projectionReconciliationRequired=true`,
readiness queda degradado y su siguiente reconciliacion reintenta el snapshot;
nunca se informa falsamente que la mutacion fue revertida. Checkout y operaciones
autenticadas no usan el cache FastCGI.

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
- Dashboard y ecommerce usan cookies de auth/CSRF distintas por superficie. El backend solo acepta `X-Auth-Surface` desde un proxy interno autenticado; la lectura de cookies legacy requiere `AUTH_LEGACY_COOKIE_FALLBACK_ENABLED=true` y viene apagada por defecto.
- `DASHBOARD_ADMIN_HOST` pertenece al tenant principal y debe viajar junto con `PRIMARY_SITE_DOMAIN` para resolver host/origin sin `TENANT_NOT_FOUND`.
- `GET /openapi.json` genera OpenAPI 3.1 desde todos los registries `src/Modules/*/routes.php`. Los `paths` y `servers` describen solo contratos publicos tenantizados de APISIX; `x-internal-path` conserva la correspondencia 1:1 con el Router sin publicar el upstream. El inventario cubre 250/250 operaciones. El catalogo semantico reusable tipa 123/123 operaciones exigibles y 75/75 request bodies: toda API publica o externa y las mutaciones criticas de auth, tenants, usuarios, productos, pedidos, Billing y Loyalty. Las proyecciones admin no criticas que aun no tienen DTO de negocio conservan explicitamente el envelope de transporte conservador.

## Operaciones de cuidado

Reset de ventas:

```bash
./scripts/reset_sales_data.sh qa --yes
```

Ese script borra ventas, movimientos y auditorias operativas; usarlo solo cuando haga falta de forma explicita.

## Validacion

```bash
php scripts/check_modular_routes.php
php scripts/check_openapi_contract.php
php scripts/check_openapi_contract.php --self-test
../scripts/check-module-databases.sh
./scripts/check_operational_runtime.sh
```

El check OpenAPI falla si falta o sobra una ruta frente a los registries, si
deriva `config/routes.php`, si un handler/capability es invalido, si se duplica
un `operationId`, si deriva la cobertura declarada o si una superficie semantica
exigible vuelve a `GenericRequestObject`, `StandardSuccessEnvelope` o a un DTO
distinto del catalogado. `--self-test` prueba esas fronteras con mutaciones en
memoria y no modifica archivos ni datos.

`check_operational_runtime.sh` valida sintaxis, el modelo Compose y el contrato
del supervisor/healthcheck de los workers sin desplegar contenedores.

## Capacidad y salud operativa

El API genera el pool PHP-FPM al arrancar desde variables `PHP_FPM_*`. Los
defaults dejan 10 hijos, reciclan cada 500 solicitudes y registran en stderr
peticiones que excedan 5 segundos. Los limites de CPU, memoria y PIDs de API,
Nginx y workers tambien son parametrizables desde `entorno/.env`; la lista
completa y sus defaults estan en `templates/entorno/.env.example`.

Los workers se ejecutan mediante `docker/periodic-worker.sh`. Cada ciclo deja
estado atomico en el tmpfs del contenedor y emite una linea JSON con duracion y
codigo de salida. El healthcheck queda `unhealthy` si nunca hubo un ciclo
exitoso, si el ultimo ciclo fallo o si el ultimo exito excede
`intervalo + margen`. El margen se configura con
`BILLING_WORKER_HEALTH_GRACE_SECONDS` y
`WALLET_WORKER_HEALTH_GRACE_SECONDS`. `send()` y `sendHtml()` solo aceptan el
payload en `EmailOutbox`; `backend-mailer-worker` lo reclama de forma justa por
tenant con leases, reintentos con jitter y DLQ. Los adjuntos siguen sincronos y
su fila de auditoria nunca persiste el binario. El health del Mailer combina el
ultimo ciclo con edad de backlog, leases vencidos y DLQ. Un ciclo fallido reintenta en 60 segundos
por defecto, sin esperar el intervalo normal de 15/60 minutos.

Para inspeccionar el pool sin publicarlo por APISIX:

```bash
docker exec backend-http wget -qO- http://127.0.0.1:8080/internal/fpm-status
```

Benchmark GET reproducible por el contrato publico:

```bash
RESOLVE_IP=192.168.100.229 REQUESTS=50 CONCURRENCY=10 \
  ./scripts/benchmark-api.sh
```

Puede conservar las muestras con `OUTPUT_FILE=/ruta/reporte.tsv`. Es una prueba
de capacidad controlada: empezar con concurrencia baja y no usarla como prueba
de estres sobre produccion sin ventana operativa.

El collector liviano produce un snapshot en formato Prometheus con estado,
salud, CPU, memoria y PIDs de los contenedores; probes de API por APISIX; y,
despues del siguiente deploy, contadores del pool PHP-FPM:

```bash
RESOLVE_IP=192.168.100.229 \
  OUTPUT_FILE=/tmp/paramascotas-runtime.prom \
  ./scripts/collect_runtime_metrics.sh
```

No abre puertos ni necesita Prometheus/Grafana. El archivo puede ser consumido
por un textfile collector o un agente existente. `check_runtime_slo.sh` agrega
alertas ejecutables: exige contenedores saludables, 100% de probes exitosos y
p95 maximo de 1 segundo para health / 2 segundos para catalogo por defecto.

```bash
./scripts/check_runtime_slo.sh --preflight
RESOLVE_IP=192.168.100.229 ./scripts/check_runtime_slo.sh
```

Cuando incumple imprime lineas `ALERT ...` y devuelve exit 1, por lo que puede
integrarse con cron, systemd, CI o el monitor institucional sin acoplar este
runtime a un stack pesado. APISIX ya registra JSON con request ID y tiempos; el
Nginx interno ahora agrega `request_time`, upstream/status/response time, y los
workers emiten un evento JSON por ciclo.

La evidencia de rendimiento sostenido usa una mezcla ponderada y no destructiva
de storefront, dashboard y APIs publicas durante al menos 600 segundos. Conserva
muestras HTTP, p50/p95/p99/RPS y snapshots de CPU, memoria, PHP-FPM, conexiones,
esperas de locks y transacciones largas PostgreSQL:

```bash
OUTPUT_DIR=/ruta/durable/load-$(date -u +%Y%m%dT%H%M%SZ) \
RESOLVE_IP=192.168.100.229 DURATION_SECONDS=600 CONCURRENCY=8 \
./scripts/run_sustained_mixed_load.sh
```

`ALLOW_SHORT_TEST=true` existe solo para verificar el arnes; una ejecucion menor
de diez minutos no cuenta como evidencia arquitectonica.

## Almacenamiento stateless y frontera de secretos

`STORAGE_DRIVER=local` conserva en QA las rutas existentes bajo
`/var/www/html/storage` y `/var/www/html/public/uploads`. En production el
driver por defecto es `s3`; `REQUIRE_HA=true` hace que el preflight rechace
siempre filesystem local. El driver S3-compatible usa SigV4, endpoint/bucket y
prefijos por scope (`artifacts` y `uploads`). Las credenciales pueden venir de
variables o de archivos `_FILE`; el preflight solo valida presencia y nunca
contacta al proveedor ni imprime secretos.

Para uploads S3, `OBJECT_STORAGE_PUBLIC_BASE_URL` es obligatorio, debe usar
HTTPS y debe apuntar al prefijo publico que representa
`${OBJECT_STORAGE_PREFIX}/uploads`. `POST /api/admin/catalog/images` recibe el
lote WebP ya procesado por el frontend, vuelve a validar firma, nombre y
tamano, y publica atomica/compensadamente bajo
`tenants/{tenant_id}/{products|brands|categories}/...`. El frontend no recibe
endpoint privado ni credenciales S3; solo conserva la URL publica del CDN.

Billing guarda certificados, XML y RIDE mediante la abstraccion; las imagenes
de recompensas usan el scope `uploads` y siguen sirviendose por la misma ruta
API. El resolver de compatibilidad traduce las URLs historicas `/uploads/...`
al prefijo CDN cuando el driver activo es S3, sin cambiar las rutas API
protegidas ni las URLs externas.

Los objetos locales preexistentes se migran con inventario determinista,
journal durable reanudable y verificacion remota por tamano y SHA-256 mediante
GET. El proceso nunca borra el origen ni sobrescribe silenciosamente un objeto
distinto. Excluye secretos runtime (`wallet/service-account.json`) y llaves
`.key`/`.pem`; los certificados `.p12`/`.pfx` se incluyen, pero `--execute`
falla cerrado hasta que el operador ateste KMS/cifrado, versionado y retencion
del bucket real con `OBJECT_STORAGE_PRIVATE_KMS_VERIFIED=true`.

```bash
# 1. Inventario local no mutante, aun antes de configurar S3.
php scripts/migrate_local_storage_to_s3.php --dry-run --scope=all

# 2. Con STORAGE_DRIVER=s3 y credenciales por _FILE, copiar y verificar.
# El manifest debe vivir fuera de storage/uploads y conservarse con el cambio.
OBJECT_STORAGE_PRIVATE_KMS_VERIFIED=true \
php scripts/migrate_local_storage_to_s3.php --execute \
  --manifest=/ruta/durable/receipts/storage-migration.jsonl

# 3. Repetir en modo verificacion antes del cutover; usa el mismo inventario.
php scripts/migrate_local_storage_to_s3.php --verify-only \
  --manifest=/ruta/durable/receipts/storage-migration.jsonl
```

Si el plan cambia porque un archivo local fue modificado, se genera un nuevo
journal; nunca se reutiliza el receipt de otro plan. Tras el gate de
verificacion se activa S3/CDN, se valida el catalogo por APISIX y se conserva
el origen local durante la ventana de rollback definida por operaciones.

Las columnas fiscales `client_branches.certificate_password` y
`client_branches.mail_password` guardan exclusivamente envelopes autenticados
`pmbillenc:v1` (AES-256-GCM con DEK aleatoria y DEK envuelta por una KEK
versionada). La KEK no viaja en variables: API y worker fiscal reciben solo
`BILLING_SECRET_KEYRING_FILE`, montado read-only. El keyring local QA tiene
formato `{version:1,active_key_id,keys:{id:base64-de-32-bytes}}`. Como Compose
local monta secrets de archivo conservando los permisos del origen, el deploy
lo deja en un directorio `0700` y como `owner:GID-runtime 0440`, y prueba su
lectura con la identidad real del contenedor antes de detener servicios. En
Kubernetes proviene de Secret/KMS y se copia a tmpfs `0600` propiedad del
runtime. La interfaz
`DataKeyWrapper` permite reemplazar el keyring por un adaptador KMS sin cambiar
el formato de datos.

La primera adopcion con disponibilidad continua usa
compatibility/expand/backfill/contract. No se puede saltar la primera etapa:
una imagen antigua interpreta un envelope como si fuera un password.

1. Suspender el worker/backup y desplegar la nueva imagen completa con
   `BILLING_SECRET_LEGACY_READ_ENABLED=true` y
   `BILLING_SECRET_WRITE_MODE=legacy`. La imagen ya puede leer ambos formatos,
   pero conserva escrituras legacy hasta que no quede ningun pod antiguo.
2. Con el 100% de pods en la imagen dual-read, desplegar la misma imagen con
   `BILLING_SECRET_LEGACY_READ_ENABLED=true` y
   `BILLING_SECRET_WRITE_MODE=encrypted`; esperar nuevamente el rollout total.
3. Ejecutar el Job privilegiado
   `php scripts/migrate_billing_secrets.php --execute`.
4. Exigir `php scripts/check_billing_secret_storage.php --require-contract` y
   su receipt `BILLING_SECRET_STORAGE_GATE ... plaintext=0`.
5. Desplegar con `BILLING_SECRET_LEGACY_READ_ENABLED=false` y
   `BILLING_SECRET_WRITE_MODE=encrypted`, verificar readiness y reanudar
   worker/backups. Nunca habilitar escritura cifrada mientras quede una imagen
   antigua, ni ejecutar backfill antes de completar expand.

Compose QA hace un cutover drenado cuando `RUN_DB_SETUP=1`: detiene runtimes,
migra y valida dentro de la transaccion de bootstrap y arranca en modo estricto.
La migracion es idempotente y su rollback seguro es transaccional: no escribe
plaintext de vuelta. Si el rollout contract falla, se vuelve temporalmente a
la imagen expand conservando keyring y ciphertext; no se elimina una clave
anterior mientras existan backups dentro de su retencion. La rotacion tambien
es escalonada: primero se distribuye la nueva KEK inactiva y se reinician todos
los procesos; solo despues se activa y se hace un segundo rollout. Asi, un pod
con el active id anterior ya conoce la clave nueva y puede abrir envelopes de
un pod actualizado.

```bash
php scripts/manage_billing_secret_keyring.php add-key \
  --file=/ruta/segura/billing-secret-keyring.json --key-id=kek-2026-02
# Actualizar Secret y completar rollout 1; active_key_id sigue sin cambiar.
php scripts/manage_billing_secret_keyring.php activate-key \
  --file=/ruta/segura/billing-secret-keyring.json --key-id=kek-2026-02
# Actualizar Secret, completar rollout 2, migrar/rewrap y ejecutar el gate.
# Retirar una KEK antigua solo cuando no exista ningun envelope ni backup
# retenido que la necesite:
php scripts/manage_billing_secret_keyring.php retire-key \
  --file=/ruta/segura/billing-secret-keyring.json --key-id=kek-anterior \
  --attestation-file=/ruta/privada/billing-storage-gate.log \
  --confirm-backup-retention-expired
```

```bash
php scripts/check_storage_configuration.php
php src/Infrastructure/Storage/Tests/check_storage_drivers.php
php src/Modules/CatalogInventory/Tests/check_catalog_image_storage.php
```

Compose ya no inyecta `entorno/.env` completo. API recibe solo credenciales DB
de aplicacion; los workers reciben exclusivamente el rol worker. Cuando se usa
`RUN_DB_SETUP=1`, el deploy prepara roles, sincroniza bases, ejecuta bootstrap
en un contenedor one-shot con admin/FDW efimeros, aplica y comprueba FORCE RLS,
y solo entonces recrea API y workers. Esas credenciales no quedan en el runtime
final.

Los `container_name` y scripts actuales siguen orientados a una instancia
Compose; object storage elimina el acoplamiento de artefactos del backend, pero
no constituye por si solo autoscaling ni alta disponibilidad del orquestador.

## Post-prune total

Si ejecutaste `docker system prune -a --volumes` y borraste tambien la data local de PostgreSQL del workspace, el orden limpio recomendado es:

```bash
cd /home/admincenter/contenedores
./scripts/deploy.sh db
RUN_DB_SETUP=1 SEED_QA_CATALOG=1 ./scripts/deploy.sh backend
```

El deploy backend ahora reconstruye primero su imagen antes de levantar el worker fiscal, para evitar fallos por imagen local ausente despues de un wipe total.
