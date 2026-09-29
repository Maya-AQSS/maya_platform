# syntax=docker/dockerfile:1
#
# maya/php-base — imagen base común de los backends Laravel de Maya.
#
# Garantiza que las 5 apps (y las futuras) corran con el mismo PHP, las mismas
# extensiones, la misma configuración de php-fpm/nginx y el mismo entrypoint.
# Las apps NO instalan nada de sistema: solo copian vendor/ y su código.
#
# Targets:
#   base  → php-fpm 8.4 + nginx + supervisord + tini + phpredis        (audit, authorization, dashboard, logs)
#   pdf   → base + WeasyPrint + fuentes                                 (dms)
#
# Contrato con las apps (ver README.md):
#   - Código en /var/www/html, usuario www-data (uid/gid 82), sin root.
#   - Rutas escribibles: storage/, bootstrap/cache, /tmp  (emptyDir en k8s;
#     el resto del sistema de ficheros puede montarse readOnlyRootFilesystem).
#   - Puerto 8080 (nginx en el rol api; Reverb en el rol reverb).
#   - Entrypoint `maya-entrypoint` con roles api|worker|scheduler|reverb|migrate|artisan|exec.
#
# Build local:
#   docker build -t maya/php-base:8.4     --target base docker/php-base
#   docker build -t maya/php-base:8.4-pdf --target pdf  docker/php-base

ARG PHP_VERSION=8.4

# ─────────────────────────────────────────────────────────────────────────────
FROM php:${PHP_VERSION}-fpm-alpine AS base

ARG PHPREDIS_VERSION=6.2.0

LABEL org.opencontainers.image.title="maya/php-base" \
      org.opencontainers.image.description="Base PHP-FPM + nginx para los backends Laravel de Maya" \
      org.opencontainers.image.source="https://github.com/Maya-AQSS/maya_platform" \
      org.opencontainers.image.vendor="CEEDCV"

# Paquetes de sistema (runtime) + extensiones PHP.
# Ya vienen compiladas en la imagen oficial: pdo, mbstring, opcache, curl,
# openssl, sodium, tokenizer, xml, dom, fileinfo, iconv, ctype, session.
#   procps  → pgrep para los livenessProbe de los workers.
#   tini    → PID 1 que reenvía señales y recoge zombies.
# hadolint ignore=DL3003,DL4006,SC2016
# (cd dentro del RUN para compilar phpredis; `curl | tar` falla igualmente si curl
#  falla porque tar no recibe un archivo válido; las comillas simples de php -r son intencionadas)
RUN set -eux; \
    apk add --no-cache \
        bash \
        curl \
        nginx \
        supervisor \
        tini \
        tzdata \
        procps \
        libpng libjpeg-turbo libwebp freetype \
        libxml2 \
        libzip \
        postgresql-libs; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev \
        libxml2-dev \
        libzip-dev \
        postgresql-dev \
        linux-headers; \
    docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        exif \
        pcntl \
        bcmath \
        gd \
        sockets \
        zip; \
    # phpredis (cache, sesiones, colas)
    curl -fsSL "https://github.com/phpredis/phpredis/archive/refs/tags/${PHPREDIS_VERSION}.tar.gz" \
        | tar -xz -C /tmp; \
    cd "/tmp/phpredis-${PHPREDIS_VERSION}"; \
    phpize && ./configure --enable-redis && make -j"$(nproc)" && make install; \
    docker-php-ext-enable redis; \
    cd / && rm -rf /tmp/phpredis-*; \
    apk del .build-deps; \
    # Comprobación: todas las extensiones que necesitan las apps están cargadas.
    php -r 'foreach (["pdo_pgsql","pgsql","mbstring","exif","pcntl","bcmath","gd","sockets","zip","redis","Zend OPcache"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "falta extensión $e\n"); exit(1); } }'

# bash con pipefail para todos los RUN (también los de las imágenes derivadas).
SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# ─── Configuración PHP / php-fpm ─────────────────────────────────────────────
# Sustituimos el pool por defecto (declara user/group, que php-fpm ignora con
# aviso cuando no corre como root).
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && rm -f /usr/local/etc/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/zz-docker.conf
# Numerados para que las apps puedan sobreescribir con 90-app.*: PHP carga conf.d en orden alfabético.
COPY php/50-maya.ini      /usr/local/etc/php/conf.d/50-maya.ini
COPY php/maya-pool.conf   /usr/local/etc/php-fpm.d/50-maya.conf

# ─── nginx + supervisord (rol api) ───────────────────────────────────────────
# (/var/log/nginx se conserva: el binario intenta abrir ahí su error_log
#  compilado antes de leer la configuración y, si no existe, emite un alert.)
RUN rm -rf /etc/nginx/http.d /etc/nginx/conf.d /etc/nginx/nginx.conf /var/lib/nginx/tmp
COPY nginx/nginx.conf           /etc/nginx/nginx.conf
COPY supervisor/maya-api.conf   /etc/supervisor/maya-api.conf

# ─── Entrypoint común ────────────────────────────────────────────────────────
COPY entrypoint.sh /usr/local/bin/maya-entrypoint
RUN chmod 0755 /usr/local/bin/maya-entrypoint

# Árbol mínimo de la app con permisos para www-data (uid 82). Las apps copian
# su código encima; storage/ y bootstrap/cache se montan como emptyDir en k8s.
ENV MAYA_APP_DIR=/var/www/html \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    TZ=Europe/Madrid
WORKDIR /var/www/html
RUN mkdir -p storage bootstrap/cache public /tmp/nginx /tmp/supervisor \
    && chown -R www-data:www-data /var/www/html /tmp/nginx /tmp/supervisor

# Puerto único de servicio: nginx (api) o Reverb (reverb).
EXPOSE 8080

USER 82:82

# Validación de la configuración con el usuario real de ejecución (php-fpm exige
# `user` en el pool cuando se lanza como root; como www-data no hace falta).
RUN nginx -t -c /etc/nginx/nginx.conf && php-fpm -t

ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/maya-entrypoint"]
CMD ["api"]

# ─────────────────────────────────────────────────────────────────────────────
# pdf: base + WeasyPrint. Solo para las apps que generan PDF (dms).
FROM base AS pdf

USER root

ARG WEASYPRINT_VERSION=68.0

LABEL org.opencontainers.image.title="maya/php-base (pdf)" \
      org.opencontainers.image.description="Base PHP-FPM + nginx + WeasyPrint para los backends Laravel de Maya"

RUN set -eux; \
    apk add --no-cache \
        python3 py3-pip \
        pango harfbuzz cairo gdk-pixbuf \
        fontconfig libffi shared-mime-info \
        ttf-dejavu ttf-liberation \
        font-roboto font-roboto-flex font-roboto-mono \
        font-opensans \
        font-noto font-noto-extra \
        font-inconsolata \
        font-droid font-mononoki; \
    pip3 install --no-cache-dir --break-system-packages "weasyprint~=${WEASYPRINT_VERSION}"; \
    weasyprint --version

# Fuentes Google adicionales (variable fonts oficiales) usadas por las plantillas.
# hadolint ignore=DL3003
RUN set -eux; \
    mkdir -p /usr/share/fonts/google && cd /usr/share/fonts/google; \
    for f in \
        'Inter.ttf|ofl/inter/Inter%5Bopsz%2Cwght%5D.ttf' \
        'Inter-Italic.ttf|ofl/inter/Inter-Italic%5Bopsz%2Cwght%5D.ttf' \
        'Lora.ttf|ofl/lora/Lora%5Bwght%5D.ttf' \
        'Lora-Italic.ttf|ofl/lora/Lora-Italic%5Bwght%5D.ttf' \
        'Merriweather.ttf|ofl/merriweather/Merriweather%5Bopsz%2Cwdth%2Cwght%5D.ttf' \
        'Merriweather-Italic.ttf|ofl/merriweather/Merriweather-Italic%5Bopsz%2Cwdth%2Cwght%5D.ttf'; \
    do \
        name="${f%%|*}"; path="${f#*|}"; \
        curl -fsSL --retry 3 -o "$name" "https://github.com/google/fonts/raw/main/${path}"; \
        test -s "$name"; \
    done; \
    fc-cache -f

USER 82:82
