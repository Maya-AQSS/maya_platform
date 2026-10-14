#!/usr/bin/env bash
# maya-entrypoint — entrypoint común de los backends Laravel de Maya (maya/php-base).
#
# Uso:  maya-entrypoint <rol> [args...]        (o CONTAINER_ROLE=<rol>)
#
# Roles:
#   api        nginx + php-fpm (supervisord), puerto 8080
#   worker     php artisan $MAYA_WORKER_CMD   (o `php artisan <args>` si se pasan)
#   scheduler  php artisan schedule:work
#   reverb     php artisan reverb:start 0.0.0.0:8080
#   migrate    php artisan migrate --force
#   artisan    php artisan <args>              (jobs puntuales: seeds, comandos)
#   exec       <args> tal cual                 (depuración; no calienta cachés)
#
# Variables:
#   CONTAINER_ROLE          rol por defecto de la imagen (api|worker|reverb); un rol
#                           pasado como primer argumento tiene prioridad (así el Job de
#                           migración usa la imagen worker con args: ["migrate"])
#   MAYA_WORKER_CMD         comando artisan por defecto del rol worker (lo fija la imagen de cada app)
#   MAYA_WAIT_FOR_DB        1 → espera a que DB_HOST:DB_PORT acepte TCP antes de arrancar
#   MAYA_WAIT_FOR_DB_TIMEOUT segundos de espera (60)
#   MAYA_SKIP_CACHE         1 → no regenera las cachés de Laravel (solo depuración)
#
# Reglas:
#   - No hay `composer install` ni `migrate` implícitos: vendor va en la imagen
#     y las migraciones las lanza el Job de Helm con el rol migrate.
#   - Los secretos llegan por entorno o por Vault Agent (/vault/secrets/config).
#   - Un fallo al cachear la configuración aborta el arranque: preferimos un
#     CrashLoopBackOff con la causa en los logs a arrancar con una caché rota.
set -euo pipefail

APP_DIR="${MAYA_APP_DIR:-/var/www/html}"
cd "$APP_DIR"

log()  { printf '[maya-entrypoint] %s\n' "$*" >&2; }
die()  { log "ERROR: $*"; exit 64; }

is_role() {
    case "$1" in api|worker|scheduler|reverb|migrate|artisan|exec) return 0 ;; *) return 1 ;; esac
}

# ─── 1. Secretos inyectados por Vault Agent (export VAR="...") ───────────────
if [ -f /vault/secrets/config ]; then
    set -a
    # shellcheck disable=SC1091
    . /vault/secrets/config
    set +a
    log "secretos cargados desde /vault/secrets/config"
fi

# ─── 2. Rol ──────────────────────────────────────────────────────────────────
ARG_ROLE=""
if [ "$#" -gt 0 ] && is_role "$1"; then
    ARG_ROLE="$1"
    shift
fi
ROLE="${ARG_ROLE:-${CONTAINER_ROLE:-api}}"
is_role "$ROLE" || die "rol '$ROLE' no reconocido (api|worker|scheduler|reverb|migrate|artisan|exec)"

# ─── 3. Directorios escribibles (emptyDir en k8s → arrancan vacíos) ──────────
mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/testing \
    storage/logs \
    bootstrap/cache \
    /tmp/nginx /tmp/supervisor

# ─── 4. Espera opcional a la base de datos ───────────────────────────────────
if [ "${MAYA_WAIT_FOR_DB:-0}" = "1" ]; then
    timeout="${MAYA_WAIT_FOR_DB_TIMEOUT:-60}"
    log "esperando a ${DB_HOST:-127.0.0.1}:${DB_PORT:-5432} (máx. ${timeout}s)"
    for ((i = 0; i < timeout; i++)); do
        if php -r '$s=@fsockopen(getenv("DB_HOST")?:"127.0.0.1",(int)(getenv("DB_PORT")?:5432),$e,$m,2); if(!$s){exit(1);} fclose($s);'; then
            break
        fi
        sleep 1
    done
    (( i < timeout )) || die "la base de datos no responde tras ${timeout}s"
fi

# ─── 5. Cachés de Laravel con el entorno real del pod ────────────────────────
# bootstrap/cache viene vacío (emptyDir), así que hay que regenerar también la
# caché de paquetes. Sin `|| true`: si algo falla, queremos verlo aquí.
if [ "$ROLE" != "exec" ] && [ "${MAYA_SKIP_CACHE:-0}" != "1" ]; then
    php artisan package:discover --ansi --no-interaction
    php artisan config:cache     --ansi --no-interaction
    php artisan route:cache      --ansi --no-interaction
    php artisan event:cache      --ansi --no-interaction
    # Las APIs puras no tienen vistas Blade.
    if [ -d resources/views ]; then
        php artisan view:cache   --ansi --no-interaction
    fi
fi

# ─── 6. Proceso final ────────────────────────────────────────────────────────
log "rol=${ROLE}"
case "$ROLE" in
    api)
        exec supervisord -c /etc/supervisor/maya-api.conf
        ;;
    worker)
        if [ "$#" -gt 0 ]; then
            exec php artisan "$@"
        fi
        [ -n "${MAYA_WORKER_CMD:-}" ] || die "rol worker sin comando: define MAYA_WORKER_CMD o pasa argumentos"
        # shellcheck disable=SC2206
        cmd=( $MAYA_WORKER_CMD )
        exec php artisan "${cmd[@]}"
        ;;
    scheduler)
        exec php artisan schedule:work --no-interaction "$@"
        ;;
    reverb)
        exec php artisan reverb:start --host=0.0.0.0 --port="${MAYA_REVERB_PORT:-8080}" "$@"
        ;;
    migrate)
        exec php artisan migrate --force --no-interaction "$@"
        ;;
    artisan)
        [ "$#" -gt 0 ] || die "rol artisan sin comando"
        exec php artisan "$@"
        ;;
    exec)
        [ "$#" -gt 0 ] || die "rol exec sin comando"
        exec "$@"
        ;;
esac
