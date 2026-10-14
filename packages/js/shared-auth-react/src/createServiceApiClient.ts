/**
 * createServiceApiClient — factory para crear un `ApiClient` apuntando a un
 * servicio hermano del ecosistema Maya.
 *
 * Encapsula el patrón repetido en los cinco microservicios:
 *   - resolución de baseUrl via `peerOrigin('<slug>-api')/api/v1` (o env override)
 *   - delegación a `createApiClient` del mismo paquete
 *
 * @example
 * // src/api/http.ts (en cualquier app Maya)
 * import { createServiceApiClient, publicConfig } from '@ceedcv-maya/shared-auth-react';
 * import { oidcAuthService } from '../auth/oidcAdapter';
 *
 * export const { apiFetchJson, apiGetJson, buildApiUrl, getBearerToken } =
 *   createServiceApiClient('dashboard-api', oidcAuthService.keycloak, publicConfig('API_URL'));
 */
import type Keycloak from 'keycloak-js';
import { createApiClient, type ApiClient } from './apiClient';
import { resolveServiceUrl } from './peerService';

/**
 * Creates an authenticated API client for a named peer service.
 *
 * @param serviceSlug  - The service name as it appears in the hostname
 *                       (e.g. `'dashboard-api'`, `'authorization-api'`).
 *                       Used by `peerOrigin()` to derive the base URL when no
 *                       `envOverride` is provided.
 * @param keycloak     - Keycloak instance used to attach Bearer tokens.
 * @param envOverride  - Optional explicit base URL (e.g. `publicConfig('API_URL')`).
 *                       When defined and non-empty, it takes precedence over the
 *                       derived peer origin. The `/api/v1` path segment must be
 *                       included in the override.
 */
export function createServiceApiClient(
  serviceSlug: string,
  keycloak: Keycloak,
  envOverride?: string,
): ApiClient {
  const resolvedBase = resolveServiceUrl(envOverride, serviceSlug);
  // If envOverride already includes /api/v1 use it as-is; otherwise append.
  const baseUrl = resolvedBase.includes('/api/')
    ? resolvedBase
    : `${resolvedBase}/api/v1`;

  return createApiClient(keycloak, baseUrl);
}
