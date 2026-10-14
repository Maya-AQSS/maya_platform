/**
 * createApiClient — Cliente HTTP autenticado respaldado por Keycloak.
 *
 * Encapsula el patrón repetido en maya_dms/frontend/src/api/http.ts
 * y maya_logs/frontend/src/api/http.ts. Cada proyecto instancia un cliente
 * con su propio baseUrl y la misma instancia Keycloak de su oidcAdapter.
 *
 * @example
 * // src/api/http.ts
 * import { createApiClient } from '@ceedcv-maya/shared-auth-react'
 * import { oidcAuthService } from '../auth/oidcAdapter'
 *
 * export const { apiFetchJson, apiGetJson, buildApiUrl, getBearerToken, ApiHttpError } =
 *   createApiClient(oidcAuthService.keycloak, publicConfig('API_URL'))
 */
import type Keycloak from 'keycloak-js'

export class ApiHttpError extends Error {
  readonly status: number

  constructor(message: string, status: number) {
    super(message)
    this.name = 'ApiHttpError'
    this.status = status
  }
}

/**
 * Builds an {@link ApiHttpError} from a raw `Response` for code paths that
 * bypass `apiFetchJson` — multipart uploads and blob downloads. Extracts
 * `message` from the JSON body, falling back to `response.statusText` when
 * the body is absent or not JSON.
 */
export async function apiErrorFromResponse(response: Response): Promise<ApiHttpError> {
  let message = response.statusText
  try {
    const body = (await response.json()) as { message?: string }
    if (body?.message) {
      message = body.message
    }
  } catch {
    /* keep statusText */
  }
  return new ApiHttpError(message, response.status)
}

export type ApiFetchOptions = {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
}

export interface ApiClient {
  apiFetchJson: <T>(path: string, options?: ApiFetchOptions) => Promise<T>
  apiGetJson: <T>(path: string) => Promise<T>
  buildApiUrl: (path: string) => string
  getBearerToken: () => Promise<string | null>
}

// Module-level guard: si N widgets reciben 401 simultáneamente (despertar de
// pestaña, refetchOnWindowFocus), solo el primero dispara logout — los demás
// se silencian. Reset on full page reload.
let logoutInFlight = false

function forceLogout(keycloak: Keycloak): void {
  if (logoutInFlight) return
  logoutInFlight = true
  // keycloak.logout() invalida la cookie SSO y redirige al login. Más seguro
  // que keycloak.login() (que puede re-autenticar silenciosamente vía SSO y
  // ocultar el problema).
  try {
    keycloak.logout()
  } catch {
    /* adapter sin inicializar — el reload del navegador limpiará el estado */
  }
}

export function createApiClient(keycloak: Keycloak, baseUrl: string): ApiClient {
  function normalizeBase(url: string): string {
    return (url ?? '').replace(/\/$/, '')
  }

  function buildApiUrl(path: string): string {
    if (path.startsWith('http')) return path
    const base = normalizeBase(baseUrl)
    return `${base}/${path.replace(/^\//, '')}`
  }

  async function appendBearer(headers: Record<string, string>): Promise<void> {
    if (!keycloak.authenticated) return
    try {
      await keycloak.updateToken(30)
    } catch {
      // refresh_token muerto o sesión SSO caducada — logout y abortar.
      forceLogout(keycloak)
      throw new ApiHttpError('Session expired', 401)
    }
    // Race-guard: si updateToken resolvió pero el token sigue caducado
    // (clock skew, refresh silencioso fallido), no enviamos el bearer muerto.
    if (keycloak.isTokenExpired(0)) {
      forceLogout(keycloak)
      throw new ApiHttpError('Session expired', 401)
    }
    if (keycloak.token) {
      headers.Authorization = `Bearer ${keycloak.token}`
    }
  }

  async function authHeaders(jsonBody: boolean): Promise<HeadersInit> {
    const headers: Record<string, string> = { Accept: 'application/json' }
    if (jsonBody) headers['Content-Type'] = 'application/json'
    await appendBearer(headers)
    return headers
  }

  async function parseErrorMessage(response: Response): Promise<string> {
    const ct = response.headers.get('content-type') ?? ''
    if (ct.includes('application/json')) {
      try {
        const body = (await response.json()) as unknown
        return extractErrorString(body) ?? response.statusText
      } catch {
        return response.statusText
      }
    }
    return response.statusText
  }

  /**
   * Extrae un mensaje de error usable (string) de un cuerpo JSON arbitrario.
   * Soporta los formatos comunes:
   *   - {"message": "..."} (Laravel)
   *   - {"error": "..."}
   *   - {"error": {"message": "...", ...}} (detalle anidado)
   *   - {"errors": {"field": ["..."]}} (validation)
   * Si no encuentra nada legible, retorna null.
   */
  function extractErrorString(body: unknown, depth = 0): string | null {
    // Límite de profundidad: un body anidado maliciosamente no debe agotar la pila.
    if (depth > 5) return null
    if (body === null || body === undefined) return null
    if (typeof body === 'string') return body
    if (typeof body !== 'object') return String(body)

    const obj = body as Record<string, unknown>
    if (typeof obj.message === 'string' && obj.message !== '') return obj.message
    if (typeof obj.error === 'string' && obj.error !== '') return obj.error
    if (obj.error && typeof obj.error === 'object') {
      const nested = extractErrorString(obj.error, depth + 1)
      if (nested !== null) return nested
    }
    if (obj.errors && typeof obj.errors === 'object') {
      const first = Object.values(obj.errors as Record<string, unknown>)[0]
      if (Array.isArray(first) && typeof first[0] === 'string') return first[0]
      const nested = extractErrorString(obj.errors, depth + 1)
      if (nested !== null) return nested
    }
    return null
  }

  async function apiFetchJson<T>(path: string, options: ApiFetchOptions = {}): Promise<T> {
    const method = options.method ?? 'GET'
    const hasBody = options.body !== undefined && method !== 'GET'
    const url = buildApiUrl(path)

    const response = await fetch(url, {
      method,
      headers: await authHeaders(hasBody),
      body: hasBody ? JSON.stringify(options.body) : undefined,
    })

    if (!response.ok) {
      if (response.status === 401) forceLogout(keycloak)
      const msg = await parseErrorMessage(response)
      throw new ApiHttpError(msg || `HTTP ${response.status}`, response.status)
    }

    if (response.status === 204) return undefined as T

    const text = await response.text()
    if (text === '') return undefined as T

    return JSON.parse(text) as T
  }

  async function apiGetJson<T>(path: string): Promise<T> {
    return apiFetchJson<T>(path, { method: 'GET' })
  }

  async function getBearerToken(): Promise<string | null> {
    const headers: Record<string, string> = {}
    await appendBearer(headers)
    if (!headers.Authorization) return null
    return headers.Authorization.replace(/^Bearer\s+/i, '')
  }

  return { apiFetchJson, apiGetJson, buildApiUrl, getBearerToken }
}
