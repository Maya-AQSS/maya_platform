/// <reference path="./env.d.ts" />
/**
 * bootstrapRealtime — factory canónica para inicializar el cliente Echo/Reverb.
 *
 * Extrae el patrón boilerplate de `src/lib/realtimeBootstrap.ts` que se repite en
 * cada microservicio Maya. El slug del servicio se pasa como argumento, lo que
 * hace la función reutilizable en los cinco frontends.
 *
 * Configuración (por orden de prioridad): `options`, configuración pública en
 * ejecución (`window.__MAYA_CONFIG__.REVERB_*`, generada por maya/web-base desde
 * `MAYA_PUBLIC_REVERB_*`) y `import.meta.env.VITE_REVERB_*`. Lo que falte se
 * deriva del hostname actual con la convención de hosts de Maya.
 *
 * @example
 * // src/lib/realtimeBootstrap.ts (en cualquier app Maya)
 * import { bootstrapRealtime } from '@ceedcv-maya/shared-realtime-react';
 * import { getBearerToken } from '../api/http';
 *
 * export function bootstrapApp(): void {
 *   bootstrapRealtime('dms', getBearerToken);
 * }
 */
import { createEcho } from './createEcho';
import type { ReverbBootstrapConfig } from './createEcho';

/**
 * Resolver for the bearer token. Matches the `getBearerToken` signature
 * returned by `createApiClient` in `@ceedcv-maya/shared-auth-react`.
 */
type BearerTokenResolver = ReverbBootstrapConfig['getBearerToken'];

declare global {
  interface Window {
    __MAYA_CONFIG__?: Readonly<Record<string, string | undefined>>;
  }
}

/** Clave pública: runtime (`window.__MAYA_CONFIG__`) primero, `VITE_<clave>` después. */
function publicConfig(key: string): string | undefined {
  const runtime = typeof window !== 'undefined' ? window.__MAYA_CONFIG__?.[key] : undefined;
  if (typeof runtime === 'string' && runtime.trim() !== '') return runtime.trim();
  const env = import.meta.env as Record<string, string | undefined>;
  const fromEnv = env[`VITE_${key}`];
  return typeof fromEnv === 'string' && fromEnv.trim() !== '' ? fromEnv.trim() : undefined;
}

const DEV_SUFFIXES = ['.nip.io', '.sslip.io', '.localhost', '.maya.test', '.local', '.internal'];

/**
 * Origen de un servicio hermano. Duplicado de `peerOriginFor` de
 * shared-auth-react para mantener este paquete sin esa dependencia; ambos
 * implementan la misma convención (`dash` en desarrollo, `subdomain-api` en
 * producción: `api.<app>.<dominio>`).
 */
function peerOrigin(targetService: string): string {
  const { protocol, hostname } = window.location;
  const firstDot = hostname.indexOf('.');
  if (firstDot === -1) return `${protocol}//${hostname}`;

  const explicit = publicConfig('PEER_HOST_PATTERN');
  const host = hostname.toLowerCase();
  let pattern: 'dash' | 'subdomain-api';
  if (explicit === 'dash' || explicit === 'subdomain-api') pattern = explicit;
  else if (host.startsWith('api.')) pattern = 'subdomain-api';
  else if (DEV_SUFFIXES.some((s) => host.endsWith(s)) || /^\d+\.\d+\.\d+\.\d+$/.test(host)) pattern = 'dash';
  else pattern = host.split('.')[0].includes('-') ? 'dash' : 'subdomain-api';

  if (pattern === 'subdomain-api') {
    const labels = hostname.split('.');
    if (labels[0].toLowerCase() === 'api') labels.shift();
    const domain = labels.slice(1).join('.');
    const match = /^(.*?)(?:-(api|reverb))?$/.exec(targetService);
    const app = match?.[1] ?? targetService;
    return match?.[2] ? `${protocol}//api.${app}.${domain}` : `${protocol}//${app}.${domain}`;
  }

  const firstSegment = hostname.substring(0, firstDot);
  const domainSuffix = hostname.substring(firstDot);
  const lastDash = firstSegment.lastIndexOf('-');
  const slotPrefix = lastDash !== -1 ? firstSegment.substring(0, lastDash + 1) : '';

  return `${protocol}//${slotPrefix}${targetService}${domainSuffix}`;
}

export interface BootstrapRealtimeOptions {
  /**
   * Overrides for individual Reverb settings. When not provided, values are
   * read from the runtime config (`REVERB_*`) and then `import.meta.env.VITE_REVERB_*`.
   * Mainly useful for testing without setting env vars.
   */
  appKey?: string;
  host?: string;
  scheme?: string;
  port?: string;
  /** Endpoint de autorización de canales privados (`…/api/v1/broadcasting/auth`). */
  authEndpoint?: string;
}

/**
 * Reads the configuration and wires up the Echo singleton for the given service slug.
 * No-ops when the Reverb app key is absent or empty.
 *
 * @param serviceSlug    - Service name used to derive `<slug>-reverb` and
 *                         `<slug>-api` peer origins (e.g. `'authorization'`,
 *                         `'dms'`, `'dashboard'`).
 * @param getBearerToken - Async resolver for the Keycloak JWT. Passed directly
 *                         to `createEcho` for per-request fresh tokens.
 * @param options        - Optional overrides (mainly for testing).
 */
export function bootstrapRealtime(
  serviceSlug: string,
  getBearerToken: BearerTokenResolver,
  options?: BootstrapRealtimeOptions,
): void {
  const appKey = (options?.appKey ?? publicConfig('REVERB_APP_KEY'))?.trim();
  if (!appKey) return; // sin config no hay realtime

  const rawHost = options?.host ?? publicConfig('REVERB_HOST');
  const host = rawHost?.trim() || new URL(peerOrigin(`${serviceSlug}-reverb`)).hostname;

  const rawScheme = options?.scheme ?? publicConfig('REVERB_SCHEME');
  const scheme = (rawScheme === 'http' ? 'http' : 'https') as 'http' | 'https';

  const rawPort = options?.port ?? publicConfig('REVERB_PORT');
  const port = Number.parseInt(rawPort ?? '', 10) || (scheme === 'https' ? 443 : 80);

  const rawAuthEndpoint = options?.authEndpoint ?? publicConfig('REVERB_AUTH_ENDPOINT');
  const authEndpoint =
    rawAuthEndpoint?.trim() || `${peerOrigin(`${serviceSlug}-api`)}/api/v1/broadcasting/auth`;

  createEcho({ appKey, host, port, scheme, authEndpoint, getBearerToken });
}
