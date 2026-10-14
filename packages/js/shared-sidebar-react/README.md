# @ceedcv-maya/shared-sidebar-react

Sidebar primitives + shared favorites for React: useSharedFavorites hook (cross-app), favorite-button widget, configurable backend endpoint.

Part of the [ceedcv-maya/maya_platform](https://github.com/Maya-AQSS/maya_platform) mono-repo. Distributed independently for reuse outside the Maya ecosystem.

## Installation

```bash
npm install @ceedcv-maya/shared-sidebar-react @ceedcv-maya/shared-auth-react @ceedcv-maya/shared-layout-react
```

```tsx
import { useSharedFavorites, FavoriteButton } from '@ceedcv-maya/shared-sidebar-react'

function Item({ id }) {
  return <FavoriteButton entityType="document" entityId={id} backendUrl="https://api.example.org" />
}
```

## Notificaciones en tiempo real (Reverb del dashboard)

Las notificaciones de la barra lateral (`NotificationProvider` + `useNotifications` /
`NotificationsBell`) las emite **maya_dashboard** (worker de `notifications.ingest`) por **su**
Reverb, así que las cinco apps se conectan a ese Reverb y no al suyo (D37). El canal privado
`private-notifications.{sub}` se autoriza en dashboard-api
(`<DASHBOARD_API_URL>/api/v1/broadcasting/auth`) con el token de la SPA, que lleva la audiencia
`maya-dashboard`; dashboard-api lo concede si `{sub}` es el `sub` del token.

Configuración pública en ejecución (`/config.js`, `window.__MAYA_CONFIG__`; en el chart,
`MAYA_PUBLIC_<clave>` de `frontend.env`) o, en Vite, `VITE_<clave>`:

| Clave | Por defecto | Uso |
| --- | --- | --- |
| `DASHBOARD_REVERB_APP_KEY` | — (sin ella, solo consulta periódica) | Clave pública de la app de Reverb del dashboard |
| `DASHBOARD_REVERB_HOST` | host de `DASHBOARD_API_URL` | Host del WebSocket (`api.dashboard.ceedcv`, Reverb en `/app`) |
| `DASHBOARD_REVERB_PORT` | `443` (`80` con `http`) | Puerto |
| `DASHBOARD_REVERB_SCHEME` | `https` | `https` (wss) o `http` (ws) |
| `DASHBOARD_API_URL` | derivada del host actual (`dashboard-api`) | dashboard-api, sin `/api/v1`: autorización del canal y consulta periódica |

En el propio dashboard (su `API_URL` tiene el mismo origen que `DASHBOARD_API_URL`) se usa su
`REVERB_*`, que es el mismo servidor, y, si falta, `DASHBOARD_REVERB_*`. Una app que no es el
dashboard nunca se conecta a su propio Reverb, por el que no llega ninguna notificación.

La consulta periódica a dashboard-api (`pollMs`, 60 s por defecto) es el respaldo: solo se para
cuando Reverb confirma la suscripción y vuelve si la autorización falla o se cae la conexión.

La CSP del frontend debe permitir `wss://<DASHBOARD_REVERB_HOST>` y `https://<host de
DASHBOARD_API_URL>` en `connect-src`, y el Reverb del dashboard debe admitir el origen de la app.

## Peer dependencies

This package expects the following sibling packages to be installed by the consumer:

- `@ceedcv-maya/shared-auth-react`
- `@ceedcv-maya/shared-layout-react`

## Styling — required setup

This package uses Tailwind v4 utility classes and design tokens (`bg-odoo-purple`, `bg-ui-card`, `text-text-primary`, …) defined in [`@ceedcv-maya/shared-styles`](https://www.npmjs.com/package/@ceedcv-maya/shared-styles). Without it the components will render unstyled.

```bash
npm install @ceedcv-maya/shared-styles
```

```css
/* src/index.css */
@import "tailwindcss";
@import "@ceedcv-maya/shared-styles";

/* Tailwind v4 must scan the package source so it generates the
   utility classes used inside this library. */
@source "../node_modules/@ceedcv-maya/shared-sidebar-react/src/**/*.{ts,tsx}";
```

If you also consume other `@ceedcv-maya/shared-*-react` packages, add an `@source` line for each of them.

## TypeScript / build notes
This package ships TypeScript source (`src/index.ts` as entry). Consumers using Vite or Webpack with `ts-loader` work out of the box. Next.js consumers must add this package to `transpilePackages` in `next.config.js`.

## License

MIT — see [LICENSE](LICENSE).

## Reporting issues

The canonical source lives in [Maya-AQSS/maya_platform](https://github.com/Maya-AQSS/maya_platform). File issues there; this read-only split repo is only the published artifact.
