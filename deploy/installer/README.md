# Instalador de SIDES v2

Instala SIDES v2 en el servidor de una droguería. Si en ese servidor ya hay un **SIDES
viejo** (legacy), además lo reemplaza: trae sus datos, toma la API que usa el SIAD y deja el
viejo guardado para poder volver.

```bash
cd /ruta/a/sides_v2
bash deploy/installer/instalar.sh --simular     # primero: dice qué haría, sin cambiar nada
bash deploy/installer/instalar.sh               # instala (y reemplaza al SIDES viejo si lo hay)
bash deploy/installer/instalar.sh --revertir    # devuelve el SIDES viejo a su sitio
```

Se corre por SSH como el usuario de la cuenta del hosting, **nunca como root**. Mismo estilo
que el instalador de SEPED v2 (`seped_v2/deploy/installer`).

## ⚠️ No está probado en un servidor real

Se probó de punta a punta en local, con una copia de la estructura real de la base y los
datos reales de un SIDES viejo (dromarko): detección, migración, reemplazo, comprobación,
reversión y manejo del cron. **No se ha corrido en un hosting real.** Antes de usarlo con
una droguería en producción:

1. Corre `--simular` y lee todo lo que dice.
2. Pruébalo primero en QA o en una cuenta descartable.
3. Haz el reemplazo en un momento sin pedidos a medias (ver "Lo que no pasa").

## Antes de correrlo

- **SEPED v2 de esa droguería ya instalado y al día.** SIDES v2 no tiene base propia: usa la
  de SEPED v2, y el esquema (`sides_*`, `pedido`, `sso_pases`...) lo crean las migraciones
  de SEPED. El instalador nunca corre `migrate`; si falta una tabla, se detiene y lo dice.
- SIDES v2 clonado **fuera** de la carpeta del SIDES viejo (si está dentro, se detiene).
- En el PATH: PHP 8.4.1 o más nuevo (`--php-bin=` si no es `/usr/bin/php84`), `composer`,
  `npm` (Node 20+), `mysql`, `mysqldump`. `curl` y `crontab` son opcionales.

## Qué hace

1. **SEPED v2**: pide su carpeta, lee de su `.env` la base y los datos de Reverb, y comprueba
   que la base tenga las tablas de SIDES.
2. **SIDES viejo**: lo busca en la cuenta (una carpeta con `index.php` y `aplication/` cuya
   API tenga `get_pedido`). Si no hay, hace una instalación nueva y termina en el paso 4.
3. **Datos**: código de la sede, dirección pública (propone la del SIDES viejo) y cómo publicar.
4. **Instala SIDES v2**: `composer install --no-dev`, `.env` desde
   `templates/env.template`, clave, `storage:link`, `view:cache`, `npm ci`, `npm run build`.
5. **Revisa la API del SIAD** (solo informa):
   - Las cuatro rutas que usa el SIAD (`get_pedido`, `get_pedido_recibido`,
     `get_pedido_facturando`, `upd_pedido`) las atiende SIDES v2 en la misma dirección.
   - Lista las rutas que el SIDES viejo tenía y v2 **no** tiene (`get_cfg`, `insert_pedido`,
     `v1/...`), con cuántas veces aparecen en los registros web si se pueden leer.
   - Muestra la última llamada del SIAD que registró el SIDES viejo.
6. **Avisa si hay pedidos en PICKING o PACKING** en el SIDES viejo y pide confirmar.
7. **Trae los datos** (ver abajo), con respaldo antes.
8. **Reemplaza**: mueve la carpeta del SIDES viejo a `<carpeta>.legacy-<fecha>` y publica
   SIDES v2 en su lugar. Pide escribir `REEMPLAZAR`.
9. **Cron**: comenta (no borra) las tareas del SIDES viejo y agrega el programador de v2.
   Muestra las líneas y pide confirmar.
10. **Comprueba** que `/up` y `/api/get_pedido` respondan 200. Si no, ofrece devolver el
    SIDES viejo en el momento.

## Cómo toma la API del SIAD

El SIDES viejo atendía al SIAD en `https://<dominio>/api/get_pedido`, sin token. SIDES v2
queda publicado en esa misma carpeta y con `SIAD_API_ABIERTA=true`, así que responde en
**las mismas direcciones** y al SIAD no hay que cambiarle nada.

Esa entrada no pide token, igual que la vieja. El instalador también genera un token y, al
terminar, imprime la dirección con token (`/api/siad/<token>`). Cuando se pueda, cambiar el
`dominioapi` del SIAD a esa y poner `SIAD_API_ABIERTA=false` en el `.env`.

## Qué datos trae

Las tablas de `tablas_legacy.txt`: usuarios, configuración de la sede, filtros del monitor,
rutas, guías, historial de picking y packing, cestas, etiquetas y lotes de batch picking.

- Se copian las columnas que existen en los dos lados; lo demás queda en su valor por defecto.
- **Solo se llena una tabla que esté vacía.** Si ya tiene filas se deja como está, así que
  correr el instalador dos veces no duplica nada.
- **Usuarios**: entran los que no existen por correo, con su misma clave. El FT que crea
  SEPED no se toca. Si un `id` choca con uno existente, ese usuario entra con `id` nuevo.
- Antes de escribir se guardan dos respaldos en `storage/app/legacy_backups/`: la base
  vieja completa y las tablas `sides_*` como estaban.
- La base vieja solo se lee. Para copiar se usan tablas de paso `zz_legacy_*` en la base
  compartida, que se borran al terminar; un seguro impide que el volcado toque una tabla que
  no sea de paso (en esa base hay tablas de SEPED con los mismos nombres: `users`, `cfg`).

## Lo que no pasa

- **Los pedidos a medio trabajar.** `pedido` y `pedren` no se traen: SIDES v2 usa los de
  SEPED v2. Lo que ya se escaneó de un pedido en PICKING o PACKING (recipiente, operario,
  productos revisados) se pierde y ese pedido vuelve a empezar en v2.
- Las rutas de la API que v2 no atiende (paso 5).
- Si el SIDES viejo usaba otro código de sede que SEPED v2, el instalador lo avisa pero no
  lo corrige.

## Cómo publica (enlace o puente)

- **enlace** (recomendado): la carpeta del dominio pasa a ser un enlace a `public/`.
  Actualizar SIDES v2 después es solo `git pull` y `npm run build`.
- **puente**: copia `public/` a la carpeta del dominio y ajusta su `index.php`. Para
  hosting que no sigue enlaces. Después de cada actualización hay que correr
  `bash deploy/installer/instalar.sh --republicar`.

Si el enlace no se puede crear o no se lee, el instalador devuelve el SIDES viejo y avisa.

## Volver atrás

```bash
bash deploy/installer/instalar.sh --revertir
```

Quita SIDES v2 de la carpeta del dominio, devuelve el SIDES viejo y ofrece restaurar el cron
anterior. **No deshace los datos**: lo migrado a `sides_*` se queda, y los pedidos que v2
haya trabajado mientras estuvo activo no vuelven al SIDES viejo.

## Repetir sin escribir todo

Cada pregunta se salta si su variable ya viene exportada: `SEPED_DIR`, `LEGACY_DIR` (`-`
= no hay), `SIDES_CODISB`, `APP_URL`, `SEPED_URL` (`-` = vacío), `METODO_PUBLICAR`,
`BUSCAR_EN` (dónde buscar el SIDES viejo; por defecto `$HOME`).

## Archivos

```
instalar.sh              Punto de entrada: instalar, --simular, --revertir, --republicar
lib/common.sh            Mensajes, preguntas, .env y MySQL
lib/legacy.sh            SIDES viejo: detección, API, datos, reemplazo, cron, reversión
tablas_legacy.txt        Qué tablas se traen y cómo se llaman en v2
templates/env.template   .env de producción
```

Lo que deja en la instalación: `storage/instalador_reemplazo.estado` (lo usa `--revertir`),
`storage/crontab_antes_del_reemplazo.txt`, `storage/app/legacy_backups/` y `.env.anterior`
si ya había un `.env`.
