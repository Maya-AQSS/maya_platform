# Imágenes base de Maya

Dos imágenes base que fijan el runtime de todas las apps. Las apps no instalan
nada de sistema: copian su código (y `vendor/` o `dist/`) encima.

| Imagen | Base | Para | Target |
|---|---|---|---|
| `maya/php-base:8.4` | `php:8.4-fpm-alpine` | backends Laravel (api, worker, reverb) | `base` |
| `maya/php-base:8.4-pdf` | `maya/php-base:8.4` + WeasyPrint | backends que generan PDF (dms) | `pdf` |
| `maya/web-base:1.29` | `nginxinc/nginx-unprivileged:1.29-alpine` | frontends React/Vite | — |

Se publican en el registry local como `<registry>/maya/php-base:8.4-<AAAAMMDD>`
(y `8.4-pdf-<AAAAMMDD>`, `web-base:1.29-<AAAAMMDD>`), más los tags flotantes
`8.4`, `8.4-pdf` y `1.29`. **Los Dockerfile de las apps reciben la base por
`--build-arg MAYA_PHP_BASE=…` / `MAYA_WEB_BASE=…`**; en local usan `maya/php-base:8.4`
y `maya/web-base:1.29` construidas con:

```bash
docker build -t maya/php-base:8.4     --target base docker/php-base
docker build -t maya/php-base:8.4-pdf --target pdf  docker/php-base
docker build -t maya/web-base:1.29                  docker/web-base
```

## `maya/php-base`

**Contenido.** PHP 8.4 FPM con `pdo_pgsql`, `pgsql`, `mbstring`, `exif`, `pcntl`,
`bcmath`, `gd` (jpeg/webp/freetype), `sockets`, `zip`, `redis` (phpredis 6.2) y
OPcache con JIT; nginx, supervisord, tini, tzdata y procps. `php.ini` de
producción (`docker/php-base/php/zz-maya.ini`), pool php-fpm en `127.0.0.1:9000`
(`maya-pool.conf`), nginx en `:8080` sirviendo `public/` (`nginx/nginx.conf`).

**Contrato con la app.**

| | |
|---|---|
| Directorio | `/var/www/html` (`MAYA_APP_DIR`) |
| Usuario | `www-data`, uid/gid **82**, sin root |
| Puerto | **8080** (nginx en el rol `api`, Reverb en el rol `reverb`) |
| Rutas escribibles | `storage/`, `bootstrap/cache`, `/tmp`. Todo lo demás puede ser `readOnlyRootFilesystem: true`. En k8s se montan como `emptyDir`; el entrypoint recrea la estructura de `storage/` al arrancar. |
| Liveness barata | `GET /healthz` → 200 desde nginx sin pasar por PHP |
| Secretos | variables de entorno, o `/vault/secrets/config` con líneas `export VAR="…"` (Vault Agent) |
| Logs | stdout/stderr (`LOG_CHANNEL=stderr` por defecto) |

**Entrypoint `maya-entrypoint`.** `CMD ["<rol>", args…]` o `CONTAINER_ROLE=<rol>`.
Un rol pasado como argumento tiene prioridad sobre `CONTAINER_ROLE`, que es solo el
valor por defecto que cada imagen lleva grabado (`api`, `worker`, `reverb`).

| Rol | Ejecuta |
|---|---|
| `api` | supervisord → php-fpm + nginx (parada graceful con QUIT) |
| `worker` | `php artisan $MAYA_WORKER_CMD`, o `php artisan <args>` si se pasan |
| `scheduler` | `php artisan schedule:work` |
| `reverb` | `php artisan reverb:start --host=0.0.0.0 --port=8080` |
| `migrate` | `php artisan migrate --force` |
| `artisan` | `php artisan <args>` (seeds, comandos puntuales) |
| `exec` | `<args>` tal cual, sin calentar cachés (depuración) |

Antes del proceso final (salvo `exec`): carga los secretos de Vault, recrea los
directorios escribibles, espera opcionalmente a la BD (`MAYA_WAIT_FOR_DB=1`) y
regenera `package:discover`, `config:cache`, `route:cache`, `event:cache` y
`view:cache`. **Cualquier fallo aborta el arranque**: mejor un CrashLoopBackOff con
la causa en los logs que un pod arrancado con la caché rota.

Ejemplos en el chart:

```yaml
# Deployment worker (imagen *-worker): usa el comando grabado en la imagen
args: []
# Job de migración: misma imagen worker
args: ["migrate"]
# Seed de referencia
args: ["artisan", "db:seed", "--force", "--class=ProductionSeeder"]
# Scheduler
args: ["scheduler"]
```

**Dockerfile de una app.** Es idéntico en todas; solo cambia el bloque de
parámetros (`MAYA_APP`, `MAYA_WORKER_CMD`, límites PHP y, en dms, la base `-pdf`).
Genera tres imágenes con `--target api|worker|reverb`. En build valida que la app
es capaz de generar sus cachés con un entorno mínimo sin base de datos: si una
`config/*.php` lanza una excepción o hay rutas con closures, falla el build.

## `maya/web-base`

**Contenido.** nginx sin privilegios (uid 101) en `:8080`, raíz `/usr/share/nginx/html`,
SPA fallback, `assets/` inmutables un año, `index.html` sin caché, cabeceras de
seguridad y `GET /healthz`. Solo escribe en `/tmp`.

**Configuración en ejecución.** Al arrancar, `maya-web-entrypoint` genera
`/config.js` con todas las variables `MAYA_PUBLIC_*`:

```bash
MAYA_PUBLIC_API_URL=https://api.dms.ceedcv.es/api/v1
MAYA_PUBLIC_KEYCLOAK_URL=https://keycloak.ceedcv.es
MAYA_PUBLIC_KEYCLOAK_REALM=CEED
```

```js
// GET /config.js  (Cache-Control: no-store)
window.__MAYA_CONFIG__ = Object.freeze({
  "API_URL": "https://api.dms.ceedcv.es/api/v1",
  "KEYCLOAK_REALM": "CEED",
  "KEYCLOAK_URL": "https://keycloak.ceedcv.es"
});
```

La SPA lo carga con `<script src="/config.js"></script>` antes de su bundle y lee
`window.__MAYA_CONFIG__` (con `import.meta.env.VITE_*` como respaldo en desarrollo).
Así **la misma imagen sirve para cualquier entorno**. Mientras las apps no lo
lean, los `VITE_*` siguen horneándose en el build (bloque "transitorio" del
Dockerfile de cada frontend).

`MAYA_CSP`, si está definida, se envía como `Content-Security-Policy`. Ejemplo:

```text
default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self' https://api.dms.ceedcv.es https://keycloak.ceedcv.es wss://api.dms.ceedcv.es; frame-src https://keycloak.ceedcv.es; frame-ancestors 'none'; base-uri 'self'; form-action 'self'
```

## Publicación

Workflow `.github/workflows/build-base-images.yml` (runners de GitHub): se lanza al cambiar
`docker/**` o `charts/maya-common/**` en `main`, a mano, y el primer lunes de cada mes (parches
de seguridad de Alpine y PHP). Construye, pasa las comprobaciones (`php -m`, `nginx -t`,
`php-fpm -t`, arranque con raíz de solo lectura) y publica en **GHCR**
(`ghcr.io/maya-aqss/php-base`, `web-base` y el chart `maya-common`). Las apps construyen
sobre esas bases en su propio workflow (`build-app.yml`, también en GitHub) y solo sus
imágenes finales llegan a la registry interna (`10.224.237.240:5000`): las copia por digest
el workflow `dev-deploy.yml` del repo IaC (ver `IaC/dev/PLAN.md`). Tras publicar una base
nueva, las apps la adoptan en su siguiente build.
