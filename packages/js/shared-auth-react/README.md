# @ceedcv-maya/shared-auth-react

Keycloak OIDC authentication for React: hooks (useAuth, useOidcSession), AuthContext, configured Axios apiClient with auto-refresh, return-to flow, peer-service discovery.

Part of the [ceedcv-maya/maya_platform](https://github.com/Maya-AQSS/maya_platform) mono-repo. Distributed independently for reuse outside the Maya ecosystem.

## Installation

```bash
npm install @ceedcv-maya/shared-auth-react keycloak-js axios @tanstack/react-query
```

## Quick start

```tsx
import { AuthProvider, useAuth, createApiClient, publicConfig, publicKeycloakConfig } from '@ceedcv-maya/shared-auth-react'

const api = createApiClient({ baseURL: publicConfig('API_URL') })

export function App() {
  return (
    <AuthProvider config={publicKeycloakConfig()}>
      <Dashboard />
    </AuthProvider>
  )
}

function Dashboard() {
  const { user, logout } = useAuth()
  return <div>Hi {user?.name}</div>
}
```

## Features

### Runtime configuration (`/config.js`)

In K3s (development and production), the frontend image (`maya/web-base`) serves `/config.js`, generated when the container starts from its `MAYA_PUBLIC_*` environment variables (the `frontend.env` values of the app chart, through `maya-common`):

```js
// /config.js — MAYA_PUBLIC_API_URL=https://api.dms.ceedcv/api/v1 becomes:
window.__MAYA_CONFIG__ = Object.freeze({ "API_URL": "https://api.dms.ceedcv/api/v1", ... });
```

So one image per version runs in every environment and is promoted by digest (D26 R6). Under Vite (`npm run dev`, tests) there is no `/config.js` and every key falls back to `VITE_<KEY>`.

**How an app uses it**

1. `frontend/index.html`: load `/config.js` in `<head>`, **before** the bundle, as a plain (non-module) script so it runs first:

   ```html
   <script src="/config.js"></script>
   <script type="module" src="/src/main.tsx"></script>
   ```

   It is same-origin, so the CSP `script-src 'self'` allows it (an inline script would be blocked). For Vite (`npm run dev`, `vite preview`) add a `frontend/public/config.js` with only comments: it does **not** define `window.__MAYA_CONFIG__`, so the `VITE_*` values apply, and it avoids a 404 and the `vite build` warning about a script without `type="module"`. In the image, nginx serves the generated `/config.js` instead of that file.

2. Read every public value through the package instead of `import.meta.env`:

   | Today | With runtime config |
   |---|---|
   | `createOidcAdapter({ url: import.meta.env.VITE_KEYCLOAK_URL, realm: …, clientId: … })` | `createOidcAdapter(publicKeycloakConfig())` |
   | `createServiceApiClient('dms-api', keycloak, import.meta.env.VITE_API_URL)` | `createServiceApiClient('dms-api', keycloak, publicConfig('API_URL'))` |
   | `resolveServiceUrl(import.meta.env.VITE_DASHBOARD_API_URL, 'dashboard-api')` | `resolveServiceUrl(publicConfig('DASHBOARD_API_URL'), 'dashboard-api')` |
   | `resolveServiceUrl(import.meta.env.VITE_DASHBOARD_URL, 'dashboard')` | `resolveServiceUrl(publicConfig('DASHBOARD_URL'), 'dashboard')` |

3. Build with `runtime_config: true` in `build-app.yml` (one frontend image, no `-dev` variant, no environment `VITE_*` baked in).

```ts
import { publicConfig, requirePublicConfig, publicKeycloakConfig, hasRuntimeConfig } from '@ceedcv-maya/shared-auth-react'

publicConfig('API_URL')            // window.__MAYA_CONFIG__.API_URL ?? import.meta.env.VITE_API_URL (undefined if neither)
requirePublicConfig('API_URL')     // same, but throws if missing
publicKeycloakConfig()             // { url, realm, clientId } from KEYCLOAK_URL/REALM/CLIENT_ID (throws if one is missing)
hasRuntimeConfig()                 // true if /config.js was loaded
```

**Keys** (chart `frontend.env` → `/config.js` → `publicConfig(<KEY>)`, fallback `VITE_<KEY>`):

| Key (`MAYA_PUBLIC_<KEY>`) | Read by |
|---|---|
| `KEYCLOAK_URL`, `KEYCLOAK_REALM`, `KEYCLOAK_CLIENT_ID` | the app (`publicKeycloakConfig()`) |
| `API_URL` | the app (its API client); without it, `peerOrigin('<slug>-api')/api/v1` |
| `DASHBOARD_URL`, `DASHBOARD_API_URL` | the app (`resolveServiceUrl`); without them, derived with `peerOrigin` |
| `PEER_HOST_PATTERN` | `peerService` (`dash` / `subdomain-api`; otherwise detected from the hostname) |
| `REVERB_APP_KEY`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`, `REVERB_AUTH_ENDPOINT` | `bootstrapRealtime` (`@ceedcv-maya/shared-realtime-react`) |
| `DASHBOARD_REVERB_APP_KEY`, `DASHBOARD_REVERB_HOST`, `DASHBOARD_REVERB_PORT`, `DASHBOARD_REVERB_SCHEME` | `NotificationProvider` (`@ceedcv-maya/shared-sidebar-react`): the sidebar listens to the dashboard's Reverb (D37); see its README |
| `SESSION_COOKIE_DOMAIN` | `sessionOverrides` (optional override of the shared cookie domain) |
| `APP_NAME` | nobody yet (set by the charts) |

Any other key works the same way (`MAYA_PUBLIC_FOO` → `publicConfig('FOO')`). See [`runtimeConfig.ts`](src/runtimeConfig.ts).

### Peer service discovery

The package includes `peerService` for resolving other Maya apps' URLs. Two host patterns are supported:

**Dash pattern (development):**
```
dms.192.168.2.1.nip.io → api: dms-api.192.168.2.1.nip.io, reverb: dms-reverb.192.168.2.1.nip.io
```

**Subdomain-api pattern (production):**
```
dms.ceedcv.es → api: api.dms.ceedcv.es, reverb: api.dms.ceedcv.es/app
```

```tsx
import { peerOrigin, detectPeerHostPattern } from '@ceedcv-maya/shared-auth-react'

peerOrigin('dashboard-api')       // https://api.dashboard.ceedcv.es (production)
peerOrigin('dms')                 // https://dms.ceedcv.es (production SPA)
```

Pattern is detected from the current hostname or can be set via `PEER_HOST_PATTERN` env var. See [`peerService.ts`](src/peerService.ts).

## TypeScript / build notes
This package ships TypeScript source (`src/index.ts` as entry). Consumers using Vite or Webpack with `ts-loader` work out of the box. Next.js consumers must add this package to `transpilePackages` in `next.config.js`.

## License

MIT — see [LICENSE](LICENSE).

## Reporting issues

The canonical source lives in [Maya-AQSS/maya_platform](https://github.com/Maya-AQSS/maya_platform). File issues there; this read-only split repo is only the published artifact.
