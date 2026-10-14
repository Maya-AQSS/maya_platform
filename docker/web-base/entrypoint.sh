#!/bin/sh
# maya-web-entrypoint — entrypoint común de los frontends de Maya (maya/web-base).
#
# 1. Genera /tmp/maya/www/config.js con las variables MAYA_PUBLIC_*:
#      MAYA_PUBLIC_API_URL=https://api.dms.ceedcv.es/api/v1
#      → window.__MAYA_CONFIG__ = {"API_URL":"https://api.dms.ceedcv.es/api/v1"};
# 2. Renderiza el bloque server de nginx en /tmp/maya/nginx/default.conf,
#    añadiendo la cabecera Content-Security-Policy si MAYA_CSP está definida.
# 3. Arranca nginx (CMD).
#
# Solo escribe en /tmp: la imagen funciona con readOnlyRootFilesystem.
set -eu

OUT_WWW=/tmp/maya/www
OUT_NGINX=/tmp/maya/nginx
TEMPLATE=/etc/nginx/maya/default.conf.template

log() { printf '[maya-web-entrypoint] %s\n' "$*" >&2; }

mkdir -p "$OUT_WWW" "$OUT_NGINX"

# ─── 1. config.js ────────────────────────────────────────────────────────────
# Escapa \ y " para que el valor sea una cadena JSON válida. Las claves solo
# admiten [A-Z0-9_], que es lo que produce un nombre de variable de entorno.
json_escape() {
    printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'
}

{
    printf '// Generado por maya-web-entrypoint al arrancar el contenedor. No editar.\n'
    printf 'window.__MAYA_CONFIG__ = Object.freeze({'
    first=1
    for entry in $(env | grep '^MAYA_PUBLIC_' | cut -d= -f1 | sort); do
        key="${entry#MAYA_PUBLIC_}"
        value="$(printenv "$entry")"
        [ "$first" = 1 ] || printf ','
        first=0
        printf '\n  "%s": "%s"' "$key" "$(json_escape "$value")"
    done
    printf '\n});\n'
} > "$OUT_WWW/config.js"

count=$(env | grep -c '^MAYA_PUBLIC_' || true)
log "config.js generado con ${count} claves"

# ─── 2. server block ─────────────────────────────────────────────────────────
if [ -n "${MAYA_CSP:-}" ]; then
    # Sin comillas dobles dentro de una CSP válida; las simples son parte de la sintaxis.
    MAYA_CSP_HEADER="add_header Content-Security-Policy \"${MAYA_CSP}\" always;"
    log "CSP activa"
else
    MAYA_CSP_HEADER="# CSP desactivada (MAYA_CSP vacía)"
fi
export MAYA_CSP_HEADER

# Solo se sustituye ${MAYA_CSP_HEADER}; los $uri, $host... de nginx quedan intactos.
envsubst '${MAYA_CSP_HEADER}' < "$TEMPLATE" > "$OUT_NGINX/default.conf"

nginx -t -c /etc/nginx/nginx.conf >/dev/null 2>&1 || {
    nginx -t -c /etc/nginx/nginx.conf
    exit 1
}

# ─── 3. nginx ────────────────────────────────────────────────────────────────
exec "$@"
