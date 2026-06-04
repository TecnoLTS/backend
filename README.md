# Backend de ParaMascotas (`paramascotasec-backend`) 🐕

Núcleo lógico en PHP y API para e-commerce.

## 🏭 1. Entorno de Producción
Actualiza el código base forzando migraciones pero desactiva outputs inseguros de testing.

```bash
cd /home/admincenter/contenedores/paramascotasec-backend
./scripts/deploy-production.sh
```

---

## 🛠️ 2. Entorno de Desarrollo
Habilita la depuración para el equipo de codificación y expone de forma dócil la base.

```bash
cd /home/admincenter/contenedores/paramascotas-DB
./scripts/deploy-development.sh

cd /home/admincenter/contenedores/paramascotasec-backend
./scripts/deploy-development.sh
```

*(Si la Base de Datos ha sido limpiada en tus desarrollos, recuerda forzar su bootstrapping):*
```bash
RUN_DB_SETUP=1 ./scripts/deploy-development.sh
```

---

## 📌 3. Datos Relevantes y Contexto a Tomar en Cuenta

*   **Configuracion Local:**
    El backend usa solo `entorno/.env` y `entorno/servidor.env`. No hay fallback a `.env` o `.env.development` en la raiz del repo.
    Las plantillas versionadas viven en `templates/entorno/.env.example` y `templates/entorno/servidor.env.example`; si falta un archivo real, el deploy crea una copia y aborta para que completes valores reales.

*   **Archivos Más Importantes al Programar:**
    *   Lógica Multi-empresa (Multi-Tenant): `config/tenants.php`
    *   Reglas de Productos: `src/Controllers/ProductController.php`
    *   Procesamiento de Compras: `src/Controllers/OrderController.php`
*   **Peligro Crítico: Reseteo de Métricas (Ventas):**
    Existe un script brutal que reestablece únicamente indicadores de rendimiento:
    ```bash
    ./scripts/reset_sales_data.sh development --yes
    ```
    *Cuidado extremo:* Este script **BORRA** `Order`, `OrderItem`, `PosShift`, `PosMovement`, y `DiscountAudit`. Además purga `AuthSecurityEvent`, recompone masivamente `Product.quantity` a su valor base nativo (`initial_quantity`) y fija tu contador (`Product.sold=0`). 
    **Deja 100% INTACTO:** Clientes, Configuración y Catálogo. Un mal uso arruina inventarios enteros.
*   **Utilitarios Retirados (Legacy):**
    `create_tenant_dbs.sh` y `seed_tenant_baseline.sh` son códigos obsoletos del backend en repositorios unificados. No se enlazan en la rutina diaria de Paramascotas.
