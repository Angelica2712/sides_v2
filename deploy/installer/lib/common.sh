#!/usr/bin/env bash
# Funciones compartidas por los scripts de deploy/installer/. Se cargan con
# `source`, no se ejecutan solas. Mismo estilo que el instalador de seped_v2.

log_info()  { printf '\033[36m[info]\033[0m  %s\n' "$1"; }
log_ok()    { printf '\033[32m[ok]\033[0m    %s\n' "$1"; }
log_warn()  { printf '\033[33m[aviso]\033[0m %s\n' "$1" >&2; }
log_error() { printf '\033[31m[error]\033[0m %s\n' "$1" >&2; }
log_step()  { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }

# SIMULAR=1 (--simular): no se cambia nada; cada accion solo se anuncia.
SIMULAR="${SIMULAR:-0}"

# hacer <descripcion> <comando...> -- corre el comando, o lo anuncia si se esta simulando.
hacer() {
    local descripcion="$1"; shift
    if [ "$SIMULAR" = "1" ]; then
        printf '\033[35m[simula]\033[0m %s\n' "$descripcion"
        return 0
    fi
    log_info "$descripcion"
    "$@"
}

# require_bin <comando> [mensaje de ayuda]
require_bin() {
    if ! command -v "$1" >/dev/null 2>&1; then
        log_error "Falta el comando '$1' en el PATH.${2:+ $2}"
        exit 1
    fi
}

# Las preguntas aceptan la respuesta por adelantado: si la variable ya viene con valor
# (exportada antes de correr el instalador), no se pregunta. Sirve para repetir una
# instalacion sin volver a escribir todo y para las pruebas.

# ask <variable> <pregunta> [valor_por_defecto]
# No usar para contraseñas -- ver ask_secret.
ask() {
    local __var="$1" __prompt="$2" __default="${3:-}" __input
    if [ -n "${!__var:-}" ]; then
        log_info "$__prompt: ${!__var}"
        return 0
    fi
    if [ -n "$__default" ]; then
        read -r -p "$__prompt [$__default]: " __input </dev/tty
    else
        read -r -p "$__prompt: " __input </dev/tty
    fi
    __input="${__input:-$__default}"
    if [ -z "$__input" ]; then
        log_error "Este dato es obligatorio."
        exit 1
    fi
    printf -v "$__var" '%s' "$__input"
}

# ask_opcional <variable> <pregunta> [valor_por_defecto] -- como ask, pero acepta vacio.
# Para dejarlo vacio por adelantado: exportar la variable con el valor "-".
ask_opcional() {
    local __var="$1" __prompt="$2" __default="${3:-}" __input
    if [ -n "${!__var:-}" ]; then
        [ "${!__var}" = "-" ] && printf -v "$__var" '%s' ''
        log_info "$__prompt: ${!__var:-(vacio)}"
        return 0
    fi
    if [ -n "$__default" ]; then
        read -r -p "$__prompt [$__default]: " __input </dev/tty
    else
        read -r -p "$__prompt (opcional): " __input </dev/tty
    fi
    printf -v "$__var" '%s' "${__input:-$__default}"
}

# confirm <pregunta> -- devuelve 0 (si) o 1 (no). Con RESPONDER_SI=1 contesta que si
# a todo (solo para pruebas: en un servidor real hay que leer cada pregunta).
confirm() {
    local __resp
    if [ "${RESPONDER_SI:-0}" = "1" ]; then
        log_info "$1 -> si (RESPONDER_SI=1)"
        return 0
    fi
    read -r -p "$1 [s/N]: " __resp </dev/tty
    case "$__resp" in
        [sSyY]*) return 0 ;;
        *) return 1 ;;
    esac
}

# sed_escape <texto> -- escapa & / \ y # para usarlo como reemplazo en 's#patron#reemplazo#'.
sed_escape() {
    printf '%s' "$1" | sed 's/[&\]/\\&/g; s/#/\\#/g'
}

# random_token [bytes] -- token hex aleatorio.
random_token() {
    local bytes="${1:-32}"
    "$PHP_BIN" -r "echo bin2hex(random_bytes($bytes));"
}

# leer_env_var <archivo.env> <CLAVE> -- imprime el valor (sin comillas) o "" si no existe.
leer_env_var() {
    local archivo="$1" clave="$2"
    [ -f "$archivo" ] || { log_error "No existe el archivo .env: $archivo"; exit 1; }
    local linea valor
    linea="$(grep -E "^${clave}=" "$archivo" | tail -n1 || true)"
    valor="${linea#*=}"
    valor="${valor%$'\r'}"
    valor="${valor%\"}"; valor="${valor#\"}"
    valor="${valor%\'}"; valor="${valor#\'}"
    printf '%s' "$valor"
}

# poner_env_var <archivo.env> <CLAVE> <valor> -- reemplaza la linea CLAVE=... o la agrega.
poner_env_var() {
    local archivo="$1" clave="$2" valor="$3"
    if grep -qE "^${clave}=" "$archivo"; then
        sed -i "s#^${clave}=.*#${clave}=$(sed_escape "$valor")#" "$archivo"
    else
        printf '%s=%s\n' "$clave" "$valor" >> "$archivo"
    fi
}

# Las contraseñas de MySQL van por MYSQL_PWD y no por --password=: asi no quedan a la
# vista en la lista de procesos del servidor.

# mysql_v2 [args de mysql...] -- contra la base compartida con SEPED v2 (variables DB_*).
mysql_v2() {
    MYSQL_PWD="$DB_PASSWORD" mysql --host="$DB_HOST" --port="${DB_PORT:-3306}" --user="$DB_USERNAME" "$DB_DATABASE" "$@"
}

# mysql_legacy [args de mysql...] -- contra la base del SIDES viejo (variables LEG_DB_*). Solo lectura.
mysql_legacy() {
    MYSQL_PWD="$LEG_DB_PASSWORD" mysql --host="$LEG_DB_HOST" --port="${LEG_DB_PORT:-3306}" --user="$LEG_DB_USERNAME" "$LEG_DB_DATABASE" "$@"
}

# mysqldump_legacy [args de mysqldump...] -- respaldo de solo lectura de la base vieja.
mysqldump_legacy() {
    MYSQL_PWD="$LEG_DB_PASSWORD" mysqldump --host="$LEG_DB_HOST" --port="${LEG_DB_PORT:-3306}" --user="$LEG_DB_USERNAME" \
        --single-transaction --quick --no-tablespaces --skip-triggers "$LEG_DB_DATABASE" "$@"
}

# mysqldump_v2 [tablas...]
mysqldump_v2() {
    MYSQL_PWD="$DB_PASSWORD" mysqldump --host="$DB_HOST" --port="${DB_PORT:-3306}" --user="$DB_USERNAME" \
        --single-transaction --quick --no-tablespaces --skip-triggers "$DB_DATABASE" "$@"
}

# existe_tabla_v2 <tabla> / existe_tabla_legacy <tabla>
existe_tabla_v2() {
    [ "$(mysql_v2 -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$1'")" = "1" ]
}

existe_tabla_legacy() {
    [ "$(mysql_legacy -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$1'")" = "1" ]
}

# artisan <args...> -- artisan de la instalacion nueva.
artisan() {
    "$PHP_BIN" "$INSTALL_DIR/artisan" "$@"
}
