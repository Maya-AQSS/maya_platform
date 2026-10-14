# Publicación y consumo

## Flujo de release (resumen)

1. PR a `main` con los cambios.
2. CI verde (ci-php, ci-js).
3. Squash & merge.
4. Trigger manual del workflow `Release` en Actions con la nueva versión.
5. Workflow:
   - bump de `version` en todos los paquetes
   - actualiza CHANGELOG
   - crea tag `vX.Y.Z`
   - push
6. El tag dispara `split.yml` → los 13 repos read-only reciben commits + tag.
7. Consumidores actualizan su `composer require` / `package.json` al nuevo tag.

## Setup actual (en producción)

### Secrets organizacionales en GitHub

| Secret | Permisos | Uso |
|--------|----------|-----|
| `SPLIT_TOKEN` | Fine-grained PAT con `contents:write` en `Maya-AQSS/shared-*` | Push a los repos split |
| `PACKAGIST_TOKEN` | Token Packagist | Auto-publish en Packagist (detecta tags) |
| `NPM_TOKEN` | Token npm | Auto-publish en npm (workflow `publish-npm.yml`) |

### Repos read-only

Los 13 repos `Maya-AQSS/shared-*` se crean **una vez** vacíos. El split los
llena automáticamente.

```bash
for pkg in shared-auth-laravel shared-http-laravel shared-messaging-laravel \
           shared-platform-laravel shared-profile-laravel; do
  gh repo create "Maya-AQSS/$pkg" --public \
    --description "Read-only mirror of packages/php/$pkg from maya_platform. Do NOT PR here."
done

for pkg in shared-auth-react shared-dashboard-react shared-i18n-react \
           shared-layout-react shared-profile-react shared-sidebar-react \
           shared-ui-react; do
  gh repo create "Maya-AQSS/$pkg" --public \
    --description "Read-only mirror of packages/js/$pkg from maya_platform. Do NOT PR here."
done
```

Cada repo read-only debe tener:

- Issues **deshabilitados** (los bugs se reportan en `maya_platform`).
- Branch protection en `main` que solo permita pushes del bot del split.
- README auto-generado por el split que apunte al mono-repo.

## Cómo consumen los servicios (estado actual)

### Laravel (Composer) — desde Packagist

Cada `composer.json` de servicio instala directamente desde Packagist:

```jsonc
{
  "require": {
    "ceedcv-maya/shared-auth-laravel": "^0.21",
    "ceedcv-maya/shared-http-laravel": "^0.21"
  }
}
```

Sin `repositories` VCS. Excepción: durante desarrollo de features del editor,
`maya_dms` puede pinear `shared-editor-laravel` al repo split si necesita la última versión:

```jsonc
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/Maya-AQSS/shared-editor-laravel" }
  ]
}
```

### React (npm/pnpm) — desde npm registry

```jsonc
{
  "dependencies": {
    "@ceedcv-maya/shared-auth-react": "^0.21.0",
    "@ceedcv-maya/shared-ui-react": "^0.21.0"
  }
}
```

Sin `github:` refs. Versionado desde registry.npmjs.org, detectado automáticamente
por el workflow `publish-npm.yml`.

## Desarrollo local con overrides

Para iterar sin ciclo commit → tag → reinstall, cada servicio soporta
overrides que apuntan al checkout local de `maya_platform`.

### Composer — `composer-merge-plugin`

Cada servicio Laravel tiene:

- `composer.json` con `"wikimedia/composer-merge-plugin"` en require-dev y
  declaración `"include": ["composer.local.json"]` en `extra.merge-plugin`.
- `composer.local.dist.json` versionado, plantilla:

  ```json
  {
    "repositories": {
      "maya-auth": {
        "type": "path",
        "url": "../../maya_platform/packages/php/shared-auth-laravel"
      }
    }
  }
  ```

- `composer.local.json` **gitignored**. El desarrollador lo copia desde el
  `.dist`: `cp composer.local.dist.json composer.local.json && composer update`.

Cuando existe, sus `repositories` sobreescriben los VCS del `composer.json`
base — Composer prefiere `path` sobre `vcs` automáticamente.

### npm — link condicional

Para JS proponemos un Makefile target en cada servicio:

```makefile
# maya_<service>/frontend/Makefile
link-platform:
\tpnpm link --global ../../../maya_platform/packages/js/shared-auth-react
\tpnpm link --global @ceedcv-maya/shared-auth-react
\t# ... repetir para cada paquete

unlink-platform:
\tpnpm unlink --global @ceedcv-maya/shared-auth-react
\tpnpm install
```

Una alternativa más limpia: convertir cada `<servicio>/frontend` en un
miembro de un workspace pnpm raíz que incluye `maya_platform/packages/js/*`.
Lo abordaremos cuando estabilicemos el flujo.

## Flujo actual (tag → release)

```
tag vX.Y.Z en maya_platform
    ↓
release.yml
    ├→ bump version (todos los paquetes)
    ├→ actualiza CHANGELOG
    └→ push
         ↓
split.yml (disparado por tag)
    └→ subtree split a Maya-AQSS/shared-* 
         ↓
Packagist (detecta tag automáticamente)
    ├→ publica ceedcv-maya/shared-*
    └→ disponible en composer install
         
publish-npm.yml (manual o disparado tras split)
    └→ pnpm publish -r (con provenance)
         ↓
npm registry (registry.npmjs.org)
    ├→ publica @ceedcv-maya/shared-*
    └→ disponible en npm install
