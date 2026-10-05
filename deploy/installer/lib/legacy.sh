#!/usr/bin/env bash
# Todo lo que tiene que ver con el SIDES viejo (legacy): encontrarlo, revisar la API que usa
# el SIAD, traer sus datos y reemplazarlo. Se carga con `source` desde instalar.sh.

# Prefijo de las tablas de paso que se crean (y se borran) en la base compartida al migrar.
PREFIJO_PASO="zz_legacy_"
# Marca que se le pone a las lineas del cron del SIDES viejo al desactivarlas.
MARCA_CRON="#SIDES-LEGACY# "
# Rutas de la API del SIDES viejo que SIDES v2 tambien atiende.
RUTAS_API_V2="get_pedido get_pedido_recibido get_pedido_facturando upd_pedido"

# buscar_legacy <carpeta> -- imprime las carpetas que parecen un SIDES viejo: tienen
# index.php y aplication/ (con una sola "p", asi se llama en el legacy) con la API del SIAD.
buscar_legacy() {
    local raiz="$1" api carpeta
    find "$raiz" -maxdepth 6 -type f -path '*/aplication/routes/api.php' \
        -not -path '*/node_modules/*' -not -path '*/vendor/*' -not -path '*.legacy-*' 2>/dev/null |
    while IFS= read -r api; do
        carpeta="$(dirname "$(dirname "$(dirname "$api")")")"
        if grep -q 'get_pedido' "$api" && [ -f "$carpeta/index.php" ]; then
            printf '%s\n' "$carpeta"
        fi
    done
}

# elegir_legacy -- deja en LEGACY_DIR la carpeta del SIDES viejo, o vacio si no hay.
elegir_legacy() {
    if [ -n "${LEGACY_DIR:-}" ]; then
        [ "$LEGACY_DIR" = "-" ] && LEGACY_DIR=""
        return 0
    fi

    local encontrados=() linea
    while IFS= read -r linea; do
        [ -n "$linea" ] && encontrados+=("$linea")
    done < <(buscar_legacy "${BUSCAR_EN:-$HOME}")

    if [ "${#encontrados[@]}" -eq 0 ]; then
        log_info "No se encontro ningun SIDES viejo en ${BUSCAR_EN:-$HOME}."
        ask_opcional LEGACY_DIR "Carpeta del SIDES viejo (vacio = no hay, instalacion nueva)"
        return 0
    fi

    echo "Se encontro el SIDES viejo en:"
    local i=1
    for linea in "${encontrados[@]}"; do
        echo "  $i) $linea"
        i=$((i + 1))
    done
    echo "  0) Ninguno: instalar SIDES v2 sin reemplazar nada"

    local opcion
    read -r -p "Cual se reemplaza [1]: " opcion </dev/tty
    opcion="${opcion:-1}"
    if [ "$opcion" = "0" ]; then
        LEGACY_DIR=""
    elif [[ "$opcion" =~ ^[0-9]+$ ]] && [ "$opcion" -le "${#encontrados[@]}" ]; then
        LEGACY_DIR="${encontrados[$((opcion - 1))]}"
    else
        log_error "Opcion invalida."
        exit 1
    fi
}

# cargar_legacy -- valida LEGACY_DIR y lee su .env (variables LEG_*).
cargar_legacy() {
    LEGACY_DIR="${LEGACY_DIR%/}"
    LEG_ENV="$LEGACY_DIR/aplication/.env"

    [ -f "$LEGACY_DIR/index.php" ] || { log_error "$LEGACY_DIR no tiene index.php: no parece un SIDES viejo."; exit 1; }
    [ -f "$LEG_ENV" ] || { log_error "No existe $LEG_ENV."; exit 1; }

    case "$INSTALL_DIR/" in
        "$LEGACY_DIR"/*)
            log_error "SIDES v2 ($INSTALL_DIR) esta DENTRO de la carpeta del SIDES viejo ($LEGACY_DIR)."
            log_error "Al reemplazar se moveria junto con el viejo. Clona SIDES v2 fuera de esa carpeta."
            exit 1
            ;;
    esac

    LEG_DB_HOST="$(leer_env_var "$LEG_ENV" DB_HOST)"
    LEG_DB_PORT="$(leer_env_var "$LEG_ENV" DB_PORT)"
    LEG_DB_DATABASE="$(leer_env_var "$LEG_ENV" DB_DATABASE)"
    LEG_DB_USERNAME="$(leer_env_var "$LEG_ENV" DB_USERNAME)"
    LEG_DB_PASSWORD="$(leer_env_var "$LEG_ENV" DB_PASSWORD)"
    LEG_APP_URL="$(leer_env_var "$LEG_ENV" APP_URL)"

    if ! mysql_legacy -e 'SELECT 1' >/dev/null 2>&1; then
        log_error "No se pudo entrar a la base del SIDES viejo ($LEG_DB_DATABASE) con los datos de $LEG_ENV."
        exit 1
    fi
    log_ok "SIDES viejo: $LEGACY_DIR (base $LEG_DB_DATABASE)."
}

# analizar_api -- compara la API del SIDES viejo con la de SIDES v2 y busca señales de uso.
# No cambia nada: solo informa.
analizar_api() {
    log_step "API del SIAD: que atendia el SIDES viejo y que atiende SIDES v2"

    local api="$LEGACY_DIR/aplication/routes/api.php" ruta faltan=()
    while IFS= read -r ruta; do
        [ -z "$ruta" ] && continue
        if printf ' %s ' "$RUTAS_API_V2" | grep -q " $ruta "; then
            log_ok "/api/$ruta  -> la atiende SIDES v2."
        else
            faltan+=("$ruta")
        fi
    done < <(grep -oE "Route::(get|post)\('/[A-Za-z0-9_/-]+'" "$api" | sed -E "s/.*\('\///; s/'$//" | sort -u)

    if [ "${#faltan[@]}" -gt 0 ]; then
        log_warn "El SIDES viejo tambien tenia estas rutas, que SIDES v2 NO atiende (algunas van bajo /api/v1):"
        local registros
        for ruta in "${faltan[@]}"; do
            # Señal de uso: cuantas veces aparece en los registros del servidor web, si se pueden leer.
            registros="$( (grep -rhsc "/api/$ruta" "$HOME/logs" "$HOME/access-logs" 2>/dev/null || true) | awk '{s += $1} END {print s + 0}')"
            printf '         /api/%s   (apariciones en los registros web: %s)\n' "$ruta" "${registros:-0}" >&2
        done
        log_warn "Si el SIAD u otro sistema usa alguna, dejara de responder al reemplazar. Revisalo antes."
    fi

    local log_viejo ultima
    log_viejo="$(ls -t "$LEGACY_DIR"/aplication/storage/logs/laravel*.log 2>/dev/null | head -n1 || true)"
    if [ -n "$log_viejo" ]; then
        ultima="$(grep -a 'API->' "$log_viejo" | tail -n1 | cut -c1-160 || true)"
        if [ -n "$ultima" ]; then
            log_info "Ultima llamada del SIAD que registro el SIDES viejo:"
            printf '         %s\n' "$ultima"
        else
            log_warn "El registro mas reciente del SIDES viejo no tiene llamadas del SIAD ($log_viejo)."
        fi
    fi

    log_info "SIDES v2 queda con SIAD_API_ABIERTA=true: responde en las mismas direcciones"
    log_info "($APP_URL/api/get_pedido, etc.), asi que al SIAD no hay que cambiarle nada."
}

# pedidos_en_proceso_legacy -- avisa si el SIDES viejo tiene pedidos a medio trabajar.
pedidos_en_proceso_legacy() {
    existe_tabla_legacy pedido || return 0

    local total
    total="$(mysql_legacy -N -e "SELECT COUNT(*) FROM pedido WHERE estado IN ('PICKING','PACKING')" 2>/dev/null || echo '?')"
    if [ "$total" != "0" ]; then
        log_warn "El SIDES viejo tiene $total pedido(s) en PICKING o PACKING."
        log_warn "Lo que ya se escaneo de esos pedidos NO pasa a SIDES v2 (recipiente, operario, productos"
        log_warn "revisados): en SIDES v2 vuelven a empezar. Lo ideal es reemplazar con el almacen sin pedidos a medias."
        confirm "Seguir de todas formas" || { log_info "Cancelado. No se cambio nada del SIDES viejo."; exit 0; }
    else
        log_ok "El SIDES viejo no tiene pedidos en PICKING ni PACKING."
    fi
}

# columnas_comunes <tabla_paso> <tabla_v2> [columna_a_excluir] -- lista `a`,`b`,... de las
# columnas que existen en las dos tablas.
columnas_comunes() {
    local excluir="${3:-}"
    mysql_v2 -N -e "SET SESSION group_concat_max_len = 200000;
        SELECT GROUP_CONCAT(CONCAT('\`', a.column_name, '\`') ORDER BY a.ordinal_position)
        FROM information_schema.columns a
        JOIN information_schema.columns b
          ON b.table_schema = a.table_schema AND b.table_name = '$1' AND b.column_name = a.column_name
        WHERE a.table_schema = DATABASE() AND a.table_name = '$2' AND a.column_name <> '$excluir'"
}

# cargar_tabla_de_paso <tabla_vieja> -- copia la tabla del SIDES viejo a la base compartida
# con el nombre zz_legacy_<tabla>. Se hace con un volcado y no leyendo de base a base porque
# el usuario de MySQL de v2 no siempre puede leer la base vieja.
cargar_tabla_de_paso() {
    local vieja="$1" paso="${PREFIJO_PASO}$1" archivo
    archivo="$(mktemp)"

    mysqldump_legacy --skip-add-drop-table --skip-add-locks --skip-disable-keys --skip-comments "$vieja" |
        sed -E "s/^(CREATE TABLE|INSERT INTO) \`$vieja\`/\1 \`$paso\`/" > "$archivo"

    # Seguro: en la base compartida hay tablas de SEPED con estos mismos nombres (users, cfg...).
    # Si quedara una sola sentencia apuntando a una tabla sin el prefijo, no se ejecuta nada.
    if grep -aE '^(DROP|CREATE|INSERT|REPLACE|LOCK|ALTER|TRUNCATE|DELETE|UPDATE|RENAME) ' "$archivo" | grep -avq "\`$paso\`"; then
        rm -f "$archivo"
        log_error "El volcado de '$vieja' tiene sentencias fuera de la tabla de paso. No se ejecuto nada."
        exit 1
    fi

    mysql_v2 -e "DROP TABLE IF EXISTS \`$paso\`"
    mysql_v2 < "$archivo"
    rm -f "$archivo"
}

# migrar_datos -- trae usuarios, configuracion, rutas, guias, historial... del SIDES viejo.
migrar_datos() {
    log_step "Datos del SIDES viejo -> base compartida"

    local respaldos="$INSTALL_DIR/storage/app/legacy_backups" sello
    sello="$(date +%Y%m%d-%H%M%S)"

    if [ "$SIMULAR" = "1" ]; then
        printf '\033[35m[simula]\033[0m %s\n' "Respaldo completo de $LEG_DB_DATABASE y de las tablas sides_* en $respaldos"
    else
        mkdir -p "$respaldos"
        log_info "Respaldo (solo lectura) de $LEG_DB_DATABASE..."
        mysqldump_legacy | gzip > "$respaldos/sides_viejo_${LEG_DB_DATABASE}_$sello.sql.gz"
        log_ok "Respaldo del SIDES viejo: $respaldos/sides_viejo_${LEG_DB_DATABASE}_$sello.sql.gz"
    fi

    local vieja nueva tablas_v2=()
    while read -r vieja nueva; do
        case "$vieja" in ''|'#'*) continue ;; esac
        existe_tabla_v2 "$nueva" && tablas_v2+=("$nueva")
    done < "$DIR/tablas_legacy.txt"

    if [ "$SIMULAR" != "1" ]; then
        mysqldump_v2 "${tablas_v2[@]}" | gzip > "$respaldos/sides_v2_antes_$sello.sql.gz"
        log_ok "Respaldo de las tablas sides_* antes de tocarlas: $respaldos/sides_v2_antes_$sello.sql.gz"
    fi

    local filas_viejas filas_nuevas cols cols_sin_id antes despues
    while read -r vieja nueva; do
        case "$vieja" in ''|'#'*) continue ;; esac

        if ! existe_tabla_legacy "$vieja"; then
            continue
        fi
        if ! existe_tabla_v2 "$nueva"; then
            log_warn "$vieja: la base compartida no tiene $nueva (falta actualizar SEPED v2). Se omite."
            continue
        fi

        filas_viejas="$(mysql_legacy -N -e "SELECT COUNT(*) FROM \`$vieja\`")"
        filas_nuevas="$(mysql_v2 -N -e "SELECT COUNT(*) FROM \`$nueva\`")"

        if [ "$filas_viejas" = "0" ]; then
            continue
        fi

        # Solo se llena una tabla vacia: si ya tiene filas es que ya se migro o ya se esta usando,
        # y mezclar chocaria los id. La excepcion son los usuarios (ahi ya esta el FT que crea SEPED).
        if [ "$nueva" != "sides_users" ] && [ "$filas_nuevas" != "0" ]; then
            log_warn "$vieja ($filas_viejas filas): $nueva ya tiene $filas_nuevas filas. Se deja como esta."
            continue
        fi

        if [ "$SIMULAR" = "1" ]; then
            printf '\033[35m[simula]\033[0m %s\n' "$vieja ($filas_viejas filas) -> $nueva"
            continue
        fi

        cargar_tabla_de_paso "$vieja"
        cols="$(columnas_comunes "${PREFIJO_PASO}$vieja" "$nueva")"
        antes="$filas_nuevas"

        if [ "$nueva" = "sides_users" ]; then
            # Primero conservando el id; los que choquen de id con uno que ya existe entran con id nuevo.
            # Nunca se toca un usuario que ya esta (mismo correo): el FT de SEPED queda intacto.
            cols_sin_id="$(columnas_comunes "${PREFIJO_PASO}$vieja" "$nueva" id)"
            mysql_v2 -e "SET SESSION sql_mode = '';
                INSERT IGNORE INTO \`$nueva\` ($cols)
                SELECT $cols FROM \`${PREFIJO_PASO}$vieja\` v
                WHERE NOT EXISTS (SELECT 1 FROM (SELECT email, id FROM \`$nueva\`) u WHERE u.email = v.email OR u.id = v.id);
                INSERT IGNORE INTO \`$nueva\` ($cols_sin_id)
                SELECT $cols_sin_id FROM \`${PREFIJO_PASO}$vieja\` v
                WHERE NOT EXISTS (SELECT 1 FROM (SELECT email FROM \`$nueva\`) u WHERE u.email = v.email);
                UPDATE \`$nueva\` SET codisb = '$SIDES_CODISB'
                WHERE (codisb IS NULL OR codisb = '') AND seped_user_id IS NULL;"
        else
            # sql_mode vacio: una fecha o un texto NULL del viejo entra con el valor por defecto
            # cuando la columna nueva no admite NULL, en vez de rechazar toda la tabla.
            mysql_v2 -e "SET SESSION sql_mode = '';
                INSERT IGNORE INTO \`$nueva\` ($cols) SELECT $cols FROM \`${PREFIJO_PASO}$vieja\`;"
        fi

        mysql_v2 -e "DROP TABLE IF EXISTS \`${PREFIJO_PASO}$vieja\`"
        despues="$(mysql_v2 -N -e "SELECT COUNT(*) FROM \`$nueva\`")"

        if [ "$((despues - antes))" -eq "$filas_viejas" ]; then
            log_ok "$vieja -> $nueva: $filas_viejas filas."
        else
            log_warn "$vieja -> $nueva: entraron $((despues - antes)) de $filas_viejas filas (el resto ya existia o no cabia)."
        fi
    done < "$DIR/tablas_legacy.txt"

    [ "$SIMULAR" = "1" ] && return 0

    # La sede: sin fila en sides_cfg, SEPED no le manda pedidos a SIDES.
    if [ "$(mysql_v2 -N -e "SELECT COUNT(*) FROM sides_cfg WHERE codisb = '$SIDES_CODISB'")" = "0" ]; then
        log_warn "sides_cfg no tiene fila para la sede $SIDES_CODISB. Sedes que si tiene:"
        mysql_v2 -N -e "SELECT CONCAT('         ', codisb, '  ', COALESCE(nombre, '')) FROM sides_cfg" >&2 || true
        log_warn "Si el SIDES viejo usaba otro codigo de sede, hay que corregirlo antes de usar SIDES v2."
    fi
}

# publicar_en <carpeta> -- deja SIDES v2 respondiendo en <carpeta> (la del dominio).
#   enlace: <carpeta> es un enlace a public/ (lo normal).
#   puente: se copia public/ y su index.php apunta a la instalacion (hosting que no sigue enlaces).
publicar_en() {
    local destino="$1"

    # Cada paso devuelve su fallo a mano: esta funcion se llama dentro de un `if`, y ahi
    # `set -e` no corta.
    if [ "$METODO_PUBLICAR" = "enlace" ]; then
        ln -s "$INSTALL_DIR/public" "$destino" || return 1
        return 0
    fi

    mkdir -p "$destino" || return 1
    : > "$destino/.sides_v2_puente" || return 1
    # Sin public/storage (es un enlace): se vuelve a crear abajo apuntando a la instalacion.
    (cd "$INSTALL_DIR/public" && tar cf - --exclude=./storage .) | (cd "$destino" && tar xf -) || return 1
    sed -i "s#__DIR__\.'/\.\./#'$(sed_escape "$INSTALL_DIR")/#g" "$destino/index.php" || return 1
    ln -s "$INSTALL_DIR/storage/app/public" "$destino/storage" ||
        log_warn "No se pudo enlazar $destino/storage: los logos de la drogueria no se veran hasta crearlo a mano."
}

# lineas_cron_legacy <archivo_crontab> -- lineas activas del cron que son del SIDES viejo:
# las que nombran su carpeta o llaman a su direccion.
lineas_cron_legacy() {
    # Direccion completa (con su carpeta, si la tiene) y no solo el dominio: si SIDES vivia en
    # una carpeta del mismo dominio que SEPED, el dominio solo tambien marcaria el cron de SEPED.
    local direccion="${LEG_APP_URL#*://}"
    direccion="${direccion%/}"
    grep -vE '^[[:space:]]*#' "$1" | grep -F -e "$LEGACY_DIR" ${direccion:+-e "$direccion"} || true
}

# ajustar_cron -- desactiva las tareas del SIDES viejo y agrega el programador de SIDES v2.
ajustar_cron() {
    if ! command -v crontab >/dev/null 2>&1; then
        log_warn "No hay 'crontab' en esta cuenta: revisa las tareas programadas desde el panel del hosting."
        return 0
    fi

    local actual nuevo linea_v2 viejas
    actual="$(mktemp)"; nuevo="$(mktemp)"
    crontab -l > "$actual" 2>/dev/null || : > "$actual"
    linea_v2="* * * * * cd $INSTALL_DIR && $PHP_BIN artisan schedule:run >> /dev/null 2>&1"
    viejas="$(lineas_cron_legacy "$actual")"

    if [ -n "$viejas" ]; then
        echo "Tareas programadas del SIDES viejo que se van a desactivar (quedan comentadas, no se borran):"
        printf '%s\n' "$viejas" | sed 's/^/    /'
    fi

    if [ -n "$viejas" ] && confirm "Desactivar esas tareas"; then
        while IFS= read -r linea; do
            if [ -n "$linea" ] && printf '%s\n' "$viejas" | grep -qxF -- "$linea"; then
                printf '%s%s\n' "$MARCA_CRON" "$linea"
            else
                printf '%s\n' "$linea"
            fi
        done < "$actual" > "$nuevo"
    else
        cp "$actual" "$nuevo"
    fi

    if ! grep -F "$INSTALL_DIR" "$nuevo" | grep -q 'schedule:run'; then
        printf '%s\n' "$linea_v2" >> "$nuevo"
        log_info "Se agrega el programador de SIDES v2 (rutas cada hora, webhooks cada minuto)."
    fi

    if cmp -s "$actual" "$nuevo"; then
        log_info "El cron no necesita cambios."
    else
        cp "$actual" "$INSTALL_DIR/storage/crontab_antes_del_reemplazo.txt"
        hacer "Instalar el cron nuevo (el anterior queda en storage/crontab_antes_del_reemplazo.txt)" crontab "$nuevo"
    fi
    rm -f "$actual" "$nuevo"
}

# reemplazar_legacy -- el corte: el SIDES viejo sale de la carpeta del dominio y entra SIDES v2.
reemplazar_legacy() {
    log_step "Reemplazo del SIDES viejo"

    local sello respaldo
    sello="$(date +%Y%m%d-%H%M%S)"
    respaldo="${LEGACY_DIR}.legacy-$sello"

    echo "Se va a hacer esto:"
    echo "  1. Mover   $LEGACY_DIR"
    echo "     a       $respaldo   (queda entero, por si hay que volver)"
    echo "  2. Publicar SIDES v2 en $LEGACY_DIR (metodo: $METODO_PUBLICAR)"
    echo "  3. Desactivar las tareas programadas del SIDES viejo y agregar la de SIDES v2"
    echo "Desde el paso 1 el SIAD y los usuarios ya hablan con SIDES v2."
    echo "Para deshacerlo: bash deploy/installer/instalar.sh --revertir"

    if [ "$SIMULAR" = "1" ]; then
        printf '\033[35m[simula]\033[0m %s\n' "No se mueve nada."
        return 0
    fi

    local palabra="${CONFIRMAR_REEMPLAZO:-}"
    if [ -z "$palabra" ]; then
        read -r -p "Escribe REEMPLAZAR para continuar: " palabra </dev/tty
    fi
    if [ "$palabra" != "REEMPLAZAR" ]; then
        log_info "Cancelado. SIDES v2 quedo instalado pero el SIDES viejo sigue en su sitio."
        return 1
    fi

    mv "$LEGACY_DIR" "$respaldo"
    # No basta con que publicar_en no falle: se comprueba que el index.php de SIDES v2 se lea
    # de verdad a traves de la carpeta del dominio (un enlace puede crearse y no servir).
    if ! publicar_en "$LEGACY_DIR" || ! grep -q 'bootstrap/app.php' "$LEGACY_DIR/index.php" 2>/dev/null; then
        log_error "No se pudo publicar SIDES v2 en $LEGACY_DIR. Se devuelve el SIDES viejo a su sitio."
        rm -rf "$LEGACY_DIR" 2>/dev/null || true
        mv "$respaldo" "$LEGACY_DIR"
        log_error "Nada cambio. Si el hosting no admite enlaces, vuelve a correr y elige el metodo 'puente'."
        exit 1
    fi

    # Lo que necesita --revertir para deshacer el corte.
    {
        printf 'LEGACY_DIR=%q\n' "$LEGACY_DIR"
        printf 'RESPALDO_DIR=%q\n' "$respaldo"
        printf 'METODO_PUBLICAR=%q\n' "$METODO_PUBLICAR"
        printf 'FECHA=%q\n' "$sello"
    } > "$ARCHIVO_ESTADO"

    log_ok "SIDES v2 publicado en $LEGACY_DIR. El SIDES viejo quedo en $respaldo."
    ajustar_cron
}

# verificar_sitio -- despues del corte: que el sitio y la API del SIAD respondan.
verificar_sitio() {
    log_step "Comprobacion"

    if ! command -v curl >/dev/null 2>&1; then
        log_warn "No hay 'curl': abre $APP_URL/login en el navegador y prueba el SIAD a mano."
        return 0
    fi

    local codigo fallo=0
    codigo="$(curl -sS -L -o /dev/null -m 30 -w '%{http_code}' "$APP_URL/up" 2>/dev/null || echo 000)"
    if [ "$codigo" = "200" ]; then
        log_ok "$APP_URL/up responde 200."
    else
        log_error "$APP_URL/up responde $codigo (se esperaba 200)."
        fallo=1
    fi

    # Consulta de solo lectura con un estado que no existe: debe contestar 200 con JSON, como el viejo.
    codigo="$(curl -sS -L -o /dev/null -m 30 -w '%{http_code}' "$APP_URL/api/get_pedido?codisb=$SIDES_CODISB&estado=__PRUEBA__" 2>/dev/null || echo 000)"
    if [ "$codigo" = "200" ]; then
        log_ok "La API del SIAD ($APP_URL/api/get_pedido) responde 200."
    else
        log_error "La API del SIAD responde $codigo (se esperaba 200)."
        fallo=1
    fi

    if [ "$fallo" = "1" ] && [ -f "$ARCHIVO_ESTADO" ]; then
        log_warn "Algo no responde. Revisa storage/logs/ de SIDES v2 antes de decidir."
        if confirm "Devolver ahora el SIDES viejo a su sitio"; then
            revertir_reemplazo
            exit 1
        fi
    fi
    return 0
}

# revertir_reemplazo -- deshace el corte: quita SIDES v2 de la carpeta del dominio y
# devuelve el SIDES viejo. Los datos migrados a las tablas sides_* no se tocan.
revertir_reemplazo() {
    log_step "Devolver el SIDES viejo a su sitio"

    [ -f "$ARCHIVO_ESTADO" ] || { log_error "No hay un reemplazo registrado ($ARCHIVO_ESTADO)."; exit 1; }
    # shellcheck disable=SC1090
    source "$ARCHIVO_ESTADO"

    [ -d "$RESPALDO_DIR" ] || { log_error "No existe $RESPALDO_DIR: no hay SIDES viejo que devolver."; exit 1; }

    if [ -L "$LEGACY_DIR" ]; then
        hacer "Quitar el enlace $LEGACY_DIR" rm "$LEGACY_DIR"
    elif [ -f "$LEGACY_DIR/.sides_v2_puente" ]; then
        hacer "Quitar la copia de SIDES v2 en $LEGACY_DIR" rm -rf "$LEGACY_DIR"
    elif [ -e "$LEGACY_DIR" ]; then
        log_error "$LEGACY_DIR no es lo que dejo el instalador (ni enlace ni copia marcada). No se toca."
        exit 1
    fi

    hacer "Mover $RESPALDO_DIR de vuelta a $LEGACY_DIR" mv "$RESPALDO_DIR" "$LEGACY_DIR"

    local cron_antes="$INSTALL_DIR/storage/crontab_antes_del_reemplazo.txt"
    if [ -f "$cron_antes" ] && command -v crontab >/dev/null 2>&1; then
        if confirm "Restaurar tambien el cron que habia antes del reemplazo"; then
            hacer "Restaurar el cron" crontab "$cron_antes"
        fi
    fi

    if [ "$SIMULAR" != "1" ]; then
        mv "$ARCHIVO_ESTADO" "$ARCHIVO_ESTADO.revertido-$(date +%Y%m%d-%H%M%S)"
        log_ok "El SIDES viejo esta otra vez en $LEGACY_DIR."
        log_warn "Los pedidos que SIDES v2 haya trabajado mientras estuvo activo no vuelven al SIDES viejo."
    fi
}

# republicar -- solo para el metodo "puente": vuelve a copiar public/ despues de actualizar
# SIDES v2 (git pull + npm run build), porque la carpeta del dominio es una copia.
republicar() {
    [ -f "$ARCHIVO_ESTADO" ] || { log_error "No hay un reemplazo registrado ($ARCHIVO_ESTADO)."; exit 1; }
    # shellcheck disable=SC1090
    source "$ARCHIVO_ESTADO"

    if [ "$METODO_PUBLICAR" != "puente" ]; then
        log_info "Esta instalacion se publico con enlace: no hay nada que volver a copiar."
        return 0
    fi
    [ -f "$LEGACY_DIR/.sides_v2_puente" ] || { log_error "$LEGACY_DIR no es una copia hecha por el instalador."; exit 1; }

    hacer "Quitar la copia anterior de $LEGACY_DIR" rm -rf "$LEGACY_DIR"
    hacer "Copiar public/ a $LEGACY_DIR" publicar_en "$LEGACY_DIR"
    [ "$SIMULAR" = "1" ] || log_ok "Publicado de nuevo en $LEGACY_DIR."
}
