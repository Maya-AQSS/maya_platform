# syntax=docker/dockerfile:1
#
# maya/web-base — imagen base común de los frontends (SPA React/Vite) de Maya.
#
# nginx sin privilegios (uid 101) sirviendo ficheros estáticos en el 8080.
# Las apps solo copian su `dist/` a /usr/share/nginx/html.
#
# Configuración en ejecución (no hace falta reconstruir la imagen por entorno):
#   - Toda variable de entorno MAYA_PUBLIC_<CLAVE>=<valor> se publica en
#     /config.js como window.__MAYA_CONFIG__.<CLAVE>. La SPA la lee al arrancar.
#   - MAYA_CSP: si está definida, se envía como cabecera Content-Security-Policy.
#
# Rutas escribibles: solo /tmp (emptyDir en k8s); el resto puede ser de solo lectura.
#
# Build local:
#   docker build -t maya/web-base:1.29 docker/web-base

ARG NGINX_VERSION=1.29
FROM nginxinc/nginx-unprivileged:${NGINX_VERSION}-alpine

LABEL org.opencontainers.image.title="maya/web-base" \
      org.opencontainers.image.description="Base nginx sin privilegios para los frontends de Maya" \
      org.opencontainers.image.source="https://github.com/Maya-AQSS/maya_platform" \
      org.opencontainers.image.vendor="CEEDCV"

USER root

# Sustituimos la configuración y el entrypoint de la imagen oficial: la nuestra
# renderiza la plantilla en /tmp (raíz de solo lectura) y genera /config.js.
RUN rm -rf /etc/nginx/conf.d /etc/nginx/templates /docker-entrypoint.d /docker-entrypoint.sh \
    && rm -rf /usr/share/nginx/html/* \
    && mkdir -p /etc/nginx/maya /tmp/maya/nginx /tmp/maya/www \
    && chown -R nginx:nginx /tmp/maya /usr/share/nginx/html

COPY nginx/nginx.conf             /etc/nginx/nginx.conf
COPY nginx/default.conf.template  /etc/nginx/maya/default.conf.template
COPY entrypoint.sh                /usr/local/bin/maya-web-entrypoint
RUN chmod 0755 /usr/local/bin/maya-web-entrypoint

# Página mínima para poder validar la base sin una app encima.
RUN printf '<!doctype html><title>maya/web-base</title><p>maya/web-base</p>\n' > /usr/share/nginx/html/index.html

USER 101:101

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD ["curl", "-fsS", "-o", "/dev/null", "http://127.0.0.1:8080/healthz"]

ENTRYPOINT ["/usr/local/bin/maya-web-entrypoint"]
CMD ["nginx", "-c", "/etc/nginx/nginx.conf", "-g", "daemon off;"]
