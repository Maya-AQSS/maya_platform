# Maya Platform

Mono-repo de paquetes compartidos del ecosistema **Maya AQSS**. Aquí se desarrollan
todas las librerías transversales que consumen los 5 microservicios
(`maya_authorization`, `maya_audit`, `maya_logs`, `maya_dms`, `maya_dashboard`).

Los paquetes individuales se publican automáticamente como repos read-only
bajo la organización [`Maya-AQSS`](https://github.com/Maya-AQSS) mediante
sub-tree split (ver [`docs/architecture.md`](docs/architecture.md)).

## Paquetes

### PHP / Laravel — `packages/php/`

| Paquete | Composer | Propósito |
|---------|----------|-----------|
| `shared-auth-laravel` | `ceedcv-maya/shared-auth-laravel` | Middleware JWT/JWKS contra Keycloak |
| `shared-http-laravel` | `ceedcv-maya/shared-http-laravel` | Response envelope, health checks, base resources |
| `shared-messaging-laravel` | `ceedcv-maya/shared-messaging-laravel` | Publishers RabbitMQ (audit, logs, alerts) |
| `shared-platform-laravel` | `ceedcv-maya/shared-platform-laravel` | Helpers infra (FDW migrations, primitives) |
| `shared-profile-laravel` | `ceedcv-maya/shared-profile-laravel` | Endpoints `GET /me`, `PUT /me/locale` |

### React / TypeScript — `packages/js/`

| Paquete | npm | Propósito |
|---------|-----|-----------|
| `shared-auth-react` | `@ceedcv-maya/shared-auth-react` | Hooks/contextos Keycloak |
| `shared-dashboard-react` | `@ceedcv-maya/shared-dashboard-react` | Grid editable de widgets |
| `shared-i18n-react` | `@ceedcv-maya/shared-i18n-react` | Setup i18next + recursos comunes |
| `shared-layout-react` | `@ceedcv-maya/shared-layout-react` | AppLayout + Sidebar |
| `shared-profile-react` | `@ceedcv-maya/shared-profile-react` | Contexto de perfil + permisos |
| `shared-sidebar-react` | `@ceedcv-maya/shared-sidebar-react` | Favoritos, notificaciones, bell |
| `shared-ui-react` | `@ceedcv-maya/shared-ui-react` | Sistema de componentes (Button, Card, ...) |

## Imágenes base y CI de las apps — `docker/`

Además de los paquetes, este repo fija el **runtime de producción** de todas las apps:

| | |
|---|---|
| `docker/php-base` | `maya/php-base:8.4` (y `:8.4-pdf`): PHP-FPM + nginx + entrypoint con roles `api\|worker\|reverb\|migrate…` |
| `docker/web-base` | `maya/web-base:1.29`: nginx sin privilegios para las SPAs, con `/config.js` en ejecución |
| `.github/workflows/build-base-images.yml` | publica en GHCR las imágenes base y el chart `maya-common` (al cambiar `docker/**` o `charts/maya-common/**` y una vez al mes) |
| `.github/workflows/build-app.yml` | workflow reutilizable que cada app invoca: construye y publica en GHCR sus 4 imágenes (+ frontend `-dev`) y su chart, y pide a IaC el despliegue en el K3s de desarrollo |

El contrato (usuario, puertos, rutas escribibles, roles, variables) está en
[`docker/README.md`](docker/README.md).

## Quick start (desarrollo)

```bash
# Requisitos: PHP 8.4, Composer 2, Node 20+, pnpm 9+

git clone https://github.com/Maya-AQSS/maya_platform.git
cd maya_platform

# JS workspace
pnpm install
pnpm typecheck

# PHP packages (cada uno aislado)
composer install
composer validate-packages
```

## Cómo consumen los servicios estos paquetes

### En producción (desde Packagist y npm)

Los paquetes se publican automáticamente en **Packagist** (PHP) y **npm** (JS)
con cada tag `vX.Y.Z`. Los servicios los instalan sin VCS repos:

**PHP — `maya_*/backend/composer.json`:**
```json
{
  "require": {
    "ceedcv-maya/shared-auth-laravel": "^0.21",
    "ceedcv-maya/shared-http-laravel": "^0.21"
  }
}
```

**JS — `maya_*/frontend/package.json`:**
```json
{
  "dependencies": {
    "@ceedcv-maya/shared-auth-react": "^0.21.0",
    "@ceedcv-maya/shared-ui-react": "^0.21.0"
  }
}
```

**Excepción (VCS repo):** `maya_dms` requiere la última `shared-editor-laravel`
durante desarrollo de features del editor:
```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/Maya-AQSS/shared-editor-laravel" }
  ]
}
```

### En desarrollo local (hot-reload contra checkout)

Para iterar sin taggear, los servicios soportan **overrides condicionales**
que apuntan a este checkout de `maya_platform`. Consulta [`docs/publishing.md`](docs/publishing.md)
para `composer.local.json` (PHP) y `pnpm link` (JS).

## Documentación

- [`docs/architecture.md`](docs/architecture.md) — modelo mono-repo + sub-tree split
- [`docs/versioning.md`](docs/versioning.md) — política semver y compatibilidad
- [`docs/publishing.md`](docs/publishing.md) — flujo de release y consumo
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — convenciones, tests, PRs

## Estado

Pre-1.0. La API pública puede cambiar entre versiones menores hasta `1.0.0`.

## Licencia

MIT — ver [`LICENSE`](LICENSE).
