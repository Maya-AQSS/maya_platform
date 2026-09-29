# @ceedcv-maya/shared-auth-react

Keycloak OIDC authentication for React: hooks (useAuth, useOidcSession), AuthContext, configured Axios apiClient with auto-refresh, return-to flow, peer-service discovery.

Part of the [ceedcv-maya/maya_platform](https://github.com/Maya-AQSS/maya_platform) mono-repo. Distributed independently for reuse outside the Maya ecosystem.

## Installation

```bash
npm install @ceedcv-maya/shared-auth-react keycloak-js axios @tanstack/react-query
```

## Quick start

```tsx
import { AuthProvider, useAuth, createApiClient } from '@ceedcv-maya/shared-auth-react'

const api = createApiClient({ baseURL: import.meta.env.VITE_API_URL })

export function App() {
  return (
    <AuthProvider config={{ url: 'https://keycloak.example.org', realm: 'CEED', clientId: 'my-app' }}>
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

### Runtime configuration (production)

In production (K3s), the frontend image (`maya/web-base`) serves `/config.js` which sets `window.__MAYA_CONFIG__` from `MAYA_PUBLIC_*` environment variables. The same Docker image runs in all environments without rebuilding:

```tsx
import { publicConfig, hasRuntimeConfig } from '@ceedcv-maya/shared-auth-react'

publicConfig('KEYCLOAK_REALM')    // 'CEED' in production (from /config.js)
publicConfig('API_URL')           // 'https://api.dms.ceedcv.es/api/v1' in production
hasRuntimeConfig()                // true if /config.js was loaded
```

In development (Vite), values come from `VITE_*` env vars. See [`runtimeConfig.ts`](src/runtimeConfig.ts).

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
