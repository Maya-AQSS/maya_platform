# Versionado

## Política

**Semver estricto** (`MAJOR.MINOR.PATCH`) con sincronización entre paquetes.

| Cambio | Bump | Ejemplo |
|--------|------|---------|
| Bug fix sin cambiar API | PATCH | 0.1.0 → 0.1.1 |
| Feature nueva, compatible hacia atrás | MINOR | 0.1.1 → 0.2.0 |
| Breaking change | MAJOR | 0.2.0 → 1.0.0 |

## Pre-1.0 (estado actual)

Mientras estemos en 0.x:

- Los **MINOR pueden incluir breaking changes** documentados en CHANGELOG.
  Esto es estándar semver en pre-1.0.
- Los **consumidores deben pinear a `^0.X`** (no `^0`).
- Para Composer: `"ceedcv-maya/shared-auth-laravel": "^0.1"` significa `>=0.1.0 <0.2`.
- Para npm con `github:`: pinea a tag concreto `#v0.1.0`, no `#main`.

## Política de soporte

- **Solo la versión más reciente** recibe parches mientras estemos en 0.x.
- Tras 1.0: soporte de la N-1 minor durante 3 meses.
- Solo PHP 8.4+, Node 20+, Laravel 11+, React 18+. Sin LTS-juggling.

## Sincronización entre paquetes

`monorepo-builder release X.Y.Z` aplica `X.Y.Z` a **todos los paquetes PHP**
y sincroniza dependencias mutuas. Garantiza que `shared-auth-laravel 0.2.0`
y `shared-profile-laravel 0.2.0` son compatibles entre sí por construcción.

Para JS la sincronización es manual hasta que añadamos un script equivalente.
El proceso de release la fuerza (ver `publishing.md`).

## Breaking changes — checklist

Antes de marcar algo como breaking:

- [ ] ¿Hay un deprecation path posible? Si sí, deprécialo primero en MINOR y
  rómpelo en el siguiente MAJOR.
- [ ] ¿Está documentado en CHANGELOG.md del paquete?
- [ ] ¿Hay guía de migración? Aunque sea un párrafo.
- [ ] ¿Los servicios consumidores están listos para el cambio o tienen flag
  de migración?

## CHANGELOG

Cada paquete tiene su propio `CHANGELOG.md` siguiendo
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/):

```md
## [Unreleased]

### Added
- ...

### Changed
- ...

### Fixed
- ...

## [0.1.0] - 2026-05-25

Initial release.
```

`monorepo-builder` añade automáticamente la sección de versión en cada
release.
