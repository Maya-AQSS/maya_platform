# Arquitectura — Mono-repo + Sub-tree Split

## Modelo

```
┌─────────────────────────────────────────────────────────────┐
│  maya_platform (mono-repo de desarrollo)                    │
│  - todo el código vive aquí                                 │
│  - CI valida los 13 paquetes                                │
│  - PRs cross-package en un solo lugar                       │
└────────────────────────┬────────────────────────────────────┘
                         │ tag vX.Y.Z
                         │ split (sub-tree) + release.yml
                         ▼
┌────────────────────────────────────────────────────────────┐
│  13 repos read-only (Maya-AQSS/shared-*)                   │
│  - generados automáticamente (split)                        │
│  - tags semver propagados                                  │
└────────────────────────────────────────────────────────────┘
         │ (Packagist auto-detecta)  │ (npm publish.yml)
         ▼                           ▼
   ┌──────────────┐           ┌──────────────┐
   │  Packagist   │           │  npm registry│
   │ ceedcv-maya/ │           │@ceedcv-maya/ │
   └──────┬───────┘           └──────┬───────┘
          │                          │
          └──────────────┬───────────┘
                         ▼
        ┌────────────────────────────────────────┐
        │  5 microservicios (producción)         │
        │  - composer: ceedcv-maya/* ^0.21       │
        │  - npm: @ceedcv-maya/* ^0.21.0         │
        │  sin VCS repos (desarrollo: composer   │
        │    local.json / pnpm link)             │
        └────────────────────────────────────────┘
```

## Por qué este modelo

- **Refactor cross-package en un solo commit.** Cambiar `shared-auth-laravel`
  + `shared-auth-react` simultáneamente es una operación atómica.
- **CI unificada.** Una matriz que conoce los 13 paquetes, no 13 CIs separados.
- **Distribución natural.** Los consumidores instalan desde repos limpios y
  dedicados, sin ver el ruido del mono-repo.
- **Publicación automatizada en Packagist/npm.** Cada tag dispara workflows
  que publican en ambos registries. Los consumidores instalan desde registries
  públicos sin VCS repos (excepto desarrollo local, donde usan `composer.local.json`).

## Cómo funciona el split

Usamos [`danharrin/monorepo-split-github-action`](https://github.com/marketplace/actions/monorepo-split-github-action)
que envuelve `splitsh/lite`. En cada push a `main` y en cada tag `v*.*.*`:

1. La acción extrae el sub-tree de cada `packages/{php,js}/*`.
2. Lo empuja como commits limpios al repo read-only correspondiente.
3. Si es un tag, lo propaga al repo target.

El historial del repo split es **una vista filtrada** del mono-repo — no se
puede editar directamente. Los PRs llegan a `maya_platform`, nunca a los
splits.

## Versionado

- **Sincronizado** (Laravel-style): todos los paquetes comparten la misma
  versión. Bump de 0.1.0 → 0.2.0 afecta a los 13. Es lo que hace `monorepo-builder`.
- Esto evita drift: si vives en `^0.1`, todos tus paquetes Maya están alineados.
- Cuando un paquete necesite vida independiente (raro), lo sacaremos del
  mono-repo a su propio repo.

## Dependencias inter-paquete

- **PHP**: actualmente ninguna. Si añadimos: `monorepo-builder` sincroniza
  automáticamente la versión en cada `composer.json`.
- **JS**: durante dev usamos `workspace:*` (resuelto por pnpm). En release,
  `monorepo-builder` lo equivalente para JS sería... manual. Por ahora,
  bumpeamos versiones JS a mano en cada release (proceso: ver `versioning.md`).

## Diferencias con un "mono-repo Yarn/Nx"

- No usamos Nx/Turborepo. La complejidad no compensa para 13 paquetes.
- `pnpm` + workspace YAML es suficiente.
- No hay cache distribuida — los tests son rápidos.
