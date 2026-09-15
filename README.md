# SIDES v2

Sistema de despacho (picking, packing, guías, rutas, monitor, Batch Picking) que unifica los tres SIDES legacy: **dromarko, droactiva y mastranto**. Laravel 12, PHP 8.2+.

## Relación con SEPED v2

SEPED y SIDES son **dos aplicaciones separadas** (cada una con su repo y su subdominio) que comparten **una sola base de datos**.

```
seped_v2 (repo)                         sides_v2 (este repo)
  - App SEPED                             - App SIDES
  - Migraciones de TODAS las tablas       - SIN migraciones propias
    (SEPED + sides_*)                     - Modelos App\Models\Sides\*
               \                            /  y lectura de App\Models\Seped\*
                \                          /
                   Base de datos compartida
```

- **El esquema lo maneja seped_v2.** En este proyecto no se crean migraciones ni se corre `php artisan migrate`. Si SIDES necesita una tabla o columna nueva, se agrega como migración en seped_v2.
- **Las tablas de SEPED no se modifican estructuralmente.**
- SIDES escribe en SEPED solo lo mismo que el legacy:
  - `pedido`: `estado`, `documento`, `fecrecibido`, `fecpicking`, `fecpacking`, `fecfacturado`, `feccompletado`.
  - `pedren`: `cantdesp`, `estado_desp` (al cerrar el packing).
- Todo lo demás del trabajo de SIDES va en `sides_*`, en especial `sides_pedido_operacion` y `sides_pedren_operacion`.
- SIDES **no** edita cantidades, productos, precios ni totales de los pedidos.

Opciones por sucursal: Batch Picking/Packing (`sides_cfg.procAlcabalaPicking`, legacy mastranto) y API externa v1 (claves en `sides_api_keys`, legacy dromarko).

## Requisitos locales

- PHP 8.2+ (en esta PC: `C:\php84\php.exe`; el PHP de XAMPP es 8.1 y no sirve).
- Composer, Node 22.
- MariaDB/MySQL con la base compartida ya migrada desde seped_v2 (local: `seped_v2_dev`).

## Instalación

```bash
C:\php84\php.exe C:\composer\composer.phar install
copy .env.example .env
C:\php84\php.exe artisan key:generate
npm install
C:\php84\php.exe artisan serve --port=8766
```

Antes, en seped_v2: `php artisan migrate` contra la misma base.

## Documentación de diseño

En `C:\xampp\htdocs\sides_unificado`: `comparacion_migraciones_sides_v2.md` (esquema y decisiones), `sides_feature_inventory.md`, `sides_v2_realtime_pedidos.md`, `00_HANDOFF_SIDES_V2.md`.
