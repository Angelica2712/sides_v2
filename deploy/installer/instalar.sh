#!/usr/bin/env bash
# Instalador de SIDES v2. Si en el servidor ya hay un SIDES viejo (legacy), lo reemplaza:
# trae sus datos, toma la API que usa el SIAD y deja el viejo guardado para poder volver.
#
#   bash deploy/installer/instalar.sh              Instala (y reemplaza al SIDES viejo si lo hay)
#   bash deploy/installer/instalar.sh --simular    Recorre todo y dice que haria, sin cambiar nada
#   bash deploy/installer/instalar.sh --revertir   Devuelve el SIDES viejo a su sitio
#   bash deploy/installer/instalar.sh --republicar Vuelve a copiar public/ (solo metodo "puente")
#
# Opciones:
#   --php-bin=/usr/bin/php84   Binario de PHP (8.4 o mas nuevo). Por defecto /usr/bin/php84 o php.
#   COMPOSER_BIN=composer      Variable de entorno: como se llama a Composer.
#
# Se corre por SSH como el usuario de la cuenta del hosting, nunca como root.
# Ver deploy/installer/README.md antes de usarlo en un servidor real.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALL_DIR="$(cd "$DIR/../.." && pwd)"
ARCHIVO_ESTADO="$INSTALL_DIR/storage/instalador_reemplazo.estado"

ACCION="instalar"
for arg in "$@"; do
    case "$arg" in
        --simular) export SIMULAR=1 ;;
        --revertir) ACCION="revertir" ;;
        --republicar) ACCION="republicar" ;;
        --php-bin=*) export PHP_BIN="${arg#--php-bin=}" ;;
        -h|--help) sed -n '2,15p' "$0"; exit 0 ;;
        *) echo "Argumento no reconocido: $arg" >&2; exit 1 ;;
    esac
done

# shellcheck source=lib/common.sh
source "$DIR/lib/common.sh"
# shellcheck source=lib/legacy.sh
source "$DIR/lib/legacy.sh"

if [ "$(id -u)" = "0" ]; then
    log_error "No corras el instalador como root: usa el usuario de la cuenta del hosting."
    exit 1
fi

if [ -z "${PHP_BIN:-}" ]; then
    if [ -x /usr/bin/php84 ]; then PHP_BIN=/usr/bin/php84; else PHP_BIN=php; fi
fi
export PHP_BIN
COMPOSER_BIN="${COMPOSER_BIN:-composer}"

require_bin "$PHP_BIN" "Indica el binario con --php-bin=/ruta/a/php."
if ! "$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.4.1", ">=") ? 0 : 1);'; then
    log_error "SIDES v2 necesita PHP 8.4.1 o mas nuevo; $PHP_BIN es $("$PHP_BIN" -r 'echo PHP_VERSION;'). Usa --php-bin=."
    exit 1
fi

[ "$SIMULAR" = "1" ] && log_warn "MODO SIMULACION: no se cambia nada, solo se muestra lo que se haria."

case "$ACCION" in
    revertir) revertir_reemplazo; exit 0 ;;
    republicar) republicar; exit 0 ;;
esac

if [ -f "$ARCHIVO_ESTADO" ]; then
    log_error "Esta instalacion ya reemplazo a un SIDES viejo (ver $ARCHIVO_ESTADO)."
    log_error "Para deshacerlo: bash deploy/installer/instalar.sh --revertir"
    exit 1
fi

require_bin mysql
require_bin mysqldump
# shellcheck disable=SC2086
require_bin ${COMPOSER_BIN%% *} "Indica como llamarlo con COMPOSER_BIN=..."
require_bin npm "Hace falta Node 20 o mas nuevo para compilar los estilos."

# ---------------------------------------------------------------------------------------
log_step "1. SEPED v2 de esta drogueria"
# SIDES v2 no tiene base propia: usa la de SEPED v2, que tiene que estar instalado primero.

ask SEPED_DIR "Carpeta donde esta instalado SEPED v2 (la que tiene artisan y .env)"
SEPED_DIR="${SEPED_DIR%/}"
SEPED_ENV="$SEPED_DIR/.env"
[ -f "$SEPED_ENV" ] || { log_error "No existe $SEPED_ENV. Instala primero SEPED v2."; exit 1; }

DB_HOST="$(leer_env_var "$SEPED_ENV" DB_HOST)"
DB_PORT="$(leer_env_var "$SEPED_ENV" DB_PORT)"
DB_DATABASE="$(leer_env_var "$SEPED_ENV" DB_DATABASE)"
DB_USERNAME="$(leer_env_var "$SEPED_ENV" DB_USERNAME)"
DB_PASSWORD="$(leer_env_var "$SEPED_ENV" DB_PASSWORD)"
DB_PORT="${DB_PORT:-3306}"

if ! mysql_v2 -e 'SELECT 1' >/dev/null 2>&1; then
    log_error "No se pudo entrar a la base $DB_DATABASE con los datos de $SEPED_ENV."
    exit 1
fi

for tabla in pedido pedren sides_cfg sides_users sides_pedido_operacion sides_pedren_operacion sso_pases; do
    if ! existe_tabla_v2 "$tabla"; then
        log_error "La base $DB_DATABASE no tiene la tabla $tabla."
        log_error "Actualiza SEPED v2 (git pull + artisan migrate) y vuelve a correr el instalador."
        exit 1
    fi
done
log_ok "Base compartida: $DB_DATABASE (tiene las tablas de SIDES)."

SEPED_URL_DEFECTO="$(leer_env_var "$SEPED_ENV" APP_URL)"
SIDES_CODISB_DEFECTO="$(mysql_v2 -N -e 'SELECT codisb FROM cfg ORDER BY 1 LIMIT 1' 2>/dev/null || true)"

# ---------------------------------------------------------------------------------------
log_step "2. SIDES viejo en este servidor"

elegir_legacy
if [ -n "$LEGACY_DIR" ]; then
    cargar_legacy
else
    log_info "Sin SIDES viejo: instalacion nueva."
fi

# ---------------------------------------------------------------------------------------
log_step "3. Datos de esta instalacion"

ask SIDES_CODISB "Codigo de la sede (codisb)" "$SIDES_CODISB_DEFECTO"
case "${LEG_APP_URL:-}" in
    ''|*localhost*|*127.0.0.1*) APP_URL_DEFECTO="" ;;
    *) APP_URL_DEFECTO="$LEG_APP_URL" ;;
esac
ask APP_URL "Direccion publica de SIDES (https://...)" "$APP_URL_DEFECTO"
APP_URL="${APP_URL%/}"
ask_opcional SEPED_URL "Direccion publica de SEPED v2 (para el boton Ir a SEPED del FT)" "$SEPED_URL_DEFECTO"
SEPED_URL="${SEPED_URL%/}"

if [ -n "$LEGACY_DIR" ]; then
    echo "Como se publica SIDES v2 en la carpeta del dominio ($LEGACY_DIR):"
    echo "  enlace: la carpeta pasa a ser un enlace a public/ (recomendado)"
    echo "  puente: se copia public/ (para hosting que no sigue enlaces; hay que usar --republicar al actualizar)"
    ask METODO_PUBLICAR "Metodo" "enlace"
    case "$METODO_PUBLICAR" in
        enlace|puente) ;;
        *) log_error "Metodo desconocido: $METODO_PUBLICAR (enlace o puente)."; exit 1 ;;
    esac
fi

# ---------------------------------------------------------------------------------------
log_step "4. Instalar SIDES v2 en $INSTALL_DIR"

cd "$INSTALL_DIR"
# shellcheck disable=SC2086
hacer "composer install (sin paquetes de desarrollo)" $COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction

generar_env() {
    local destino="$INSTALL_DIR/.env" abierta="false"
    # Con SIDES viejo, la API queda abierta en /api para que el SIAD siga igual.
    [ -n "$LEGACY_DIR" ] && abierta="true"

    cp "$DIR/templates/env.template" "$destino"
    local clave valor
    while IFS='=' read -r clave valor; do
        sed -i "s#{{$clave}}#$(sed_escape "$valor")#g" "$destino"
    done <<VALORES
APP_URL=$APP_URL
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_DATABASE=$DB_DATABASE
DB_USERNAME=$DB_USERNAME
DB_PASSWORD=$DB_PASSWORD
SIDES_CODISB=$SIDES_CODISB
SEPED_URL=$SEPED_URL
REVERB_APP_ID=$(leer_env_var "$SEPED_ENV" REVERB_APP_ID)
REVERB_APP_KEY=$(leer_env_var "$SEPED_ENV" REVERB_APP_KEY)
REVERB_APP_SECRET=$(leer_env_var "$SEPED_ENV" REVERB_APP_SECRET)
REVERB_HOST=$(leer_env_var "$SEPED_ENV" REVERB_HOST)
REVERB_PORT=$(leer_env_var "$SEPED_ENV" REVERB_PORT)
REVERB_SCHEME=$(leer_env_var "$SEPED_ENV" REVERB_SCHEME)
SIAD_API_TOKENS=$(random_token 24)
SIAD_API_ABIERTA=$abierta
VALORES
    chmod 600 "$destino"
}

if [ -f "$INSTALL_DIR/.env" ] && ! confirm "Ya existe un .env en SIDES v2. Reemplazarlo (el actual se guarda como .env.anterior)"; then
    log_info "Se conserva el .env que ya estaba."
else
    [ -f "$INSTALL_DIR/.env" ] && hacer "Guardar el .env actual como .env.anterior" cp "$INSTALL_DIR/.env" "$INSTALL_DIR/.env.anterior"
    hacer "Generar .env (base y Reverb de SEPED v2, token nuevo para el SIAD)" generar_env
fi

if [ "$SIMULAR" = "1" ] || [ -z "$(leer_env_var "$INSTALL_DIR/.env" APP_KEY)" ]; then
    hacer "Generar la clave de la aplicacion" artisan key:generate --force
fi

hacer "Permisos de storage y bootstrap/cache" chmod -R u+rwX,g+rwX "$INSTALL_DIR/storage" "$INSTALL_DIR/bootstrap/cache"
[ -e "$INSTALL_DIR/public/storage" ] || hacer "Enlace public/storage (logos de la drogueria)" artisan storage:link
hacer "Limpiar configuracion en cache" artisan config:clear
# Las vistas compiladas van antes de los estilos: Tailwind las lee para saber que clases usar.
hacer "Compilar las vistas" artisan view:cache
hacer "npm ci" npm ci --no-audit --no-fund
hacer "npm run build" npm run build

[ "$SIMULAR" = "1" ] || log_ok "SIDES v2 instalado."

# ---------------------------------------------------------------------------------------
if [ -z "$LEGACY_DIR" ]; then
    log_step "Listo: instalacion nueva"
    cat <<FIN
Falta, desde el panel del hosting:
  - Apuntar el dominio de SIDES a:  $INSTALL_DIR/public
  - Tarea programada (cron), cada minuto:
      cd $INSTALL_DIR && $PHP_BIN artisan schedule:run >> /dev/null 2>&1

En el SIAD, la direccion de la API (dominioapi) es:
  $APP_URL/api/siad/$( [ "$SIMULAR" = "1" ] && echo '<token>' || leer_env_var "$INSTALL_DIR/.env" SIAD_API_TOKENS )

La sede $SIDES_CODISB necesita su fila en sides_cfg para que SEPED le mande pedidos:
se crea entrando a SIDES con el usuario FT, en Administracion.
FIN
    exit 0
fi

# ---------------------------------------------------------------------------------------
analizar_api
pedidos_en_proceso_legacy

if confirm "Traer los datos del SIDES viejo (usuarios, configuracion, rutas, guias, historial)"; then
    migrar_datos
else
    log_warn "No se trajeron datos: los usuarios del SIDES viejo no podran entrar a SIDES v2."
fi

if reemplazar_legacy; then
    [ "$SIMULAR" = "1" ] || verificar_sitio
fi

log_step "Listo"
TOKEN_SIAD="$( [ "$SIMULAR" = "1" ] && echo '<token>' || leer_env_var "$INSTALL_DIR/.env" SIAD_API_TOKENS )"
cat <<FIN
Revisa a mano antes de avisarle a la drogueria:
  - Entrar a $APP_URL/login con un usuario del SIDES viejo (misma clave de antes).
  - Que el monitor muestre los pedidos y que el SIAD siga bajando y facturando.

El SIAD sigue llamando a $APP_URL/api/... sin cambios. Esa entrada no pide token, igual que
el SIDES viejo. Cuando se pueda, cambia el dominioapi del SIAD a:
  $APP_URL/api/siad/$TOKEN_SIAD
y despues pon SIAD_API_ABIERTA=false en $INSTALL_DIR/.env

Para volver al SIDES viejo:  bash deploy/installer/instalar.sh --revertir
FIN
