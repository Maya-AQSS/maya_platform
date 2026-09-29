import { publicConfig } from './runtimeConfig';

/**
 * Convención de hosts de Maya. Hay dos patrones:
 *
 *  - `dash` (desarrollo): `<slot-prefix>-<servicio>.<dominio>`
 *      desarrollo-ceedcv-dms.192.168.2.1.nip.io → dashboard-api: desarrollo-ceedcv-dashboard-api.192.168.2.1.nip.io
 *
 *  - `subdomain-api` (producción, patrón del centro): `<app>.<dominio>` para la SPA
 *    y `api.<app>.<dominio>` para la API y el WebSocket (Reverb va en /app del host api).
 *      dms.ceedcv.es → dashboard-api: api.dashboard.ceedcv.es · audit: audit.ceedcv.es · dms-reverb: api.dms.ceedcv.es
 *
 * El patrón se toma de la configuración pública (`PEER_HOST_PATTERN`, es decir
 * `MAYA_PUBLIC_PEER_HOST_PATTERN` / `VITE_PEER_HOST_PATTERN`) y, si no está,
 * se deduce del hostname actual.
 */
export type PeerHostPattern = 'dash' | 'subdomain-api';

export interface PeerLocation {
  protocol: string;
  hostname: string;
}

const DEV_SUFFIXES = ['.nip.io', '.sslip.io', '.localhost', '.maya.test', '.local', '.internal'];

export function detectPeerHostPattern(hostname: string, explicit?: string): PeerHostPattern {
  if (explicit === 'dash' || explicit === 'subdomain-api') return explicit;

  const host = hostname.toLowerCase();
  if (host.startsWith('api.')) return 'subdomain-api';
  if (!host.includes('.')) return 'dash';
  if (DEV_SUFFIXES.some((s) => host.endsWith(s)) || /^\d+\.\d+\.\d+\.\d+$/.test(host)) return 'dash';
  // Un guion en el primer segmento delata un slot-prefix o el patrón antiguo <app>-api.
  if (host.split('.')[0].includes('-')) return 'dash';
  return 'subdomain-api';
}

/**
 * Origen de un servicio hermano a partir de una `location` dada (función pura,
 * testeable). `targetService` sigue la nomenclatura histórica: `dashboard`,
 * `dashboard-api`, `dashboard-reverb`.
 */
export function peerOriginFor(
  targetService: string,
  loc: PeerLocation,
  pattern?: PeerHostPattern,
): string {
  const { protocol, hostname } = loc;

  // Sin dominio (p. ej. 'localhost'): mismo origen.
  const firstDot = hostname.indexOf('.');
  if (firstDot === -1) return `${protocol}//${hostname}`;

  const resolved = pattern ?? detectPeerHostPattern(hostname, publicConfig('PEER_HOST_PATTERN'));

  if (resolved === 'subdomain-api') {
    const labels = hostname.split('.');
    if (labels[0].toLowerCase() === 'api') labels.shift();
    const domain = labels.slice(1).join('.');
    const match = /^(.*?)(?:-(api|reverb))?$/.exec(targetService);
    const app = match?.[1] ?? targetService;
    const kind = match?.[2];
    return kind ? `${protocol}//api.${app}.${domain}` : `${protocol}//${app}.${domain}`;
  }

  const firstSegment = hostname.substring(0, firstDot); // 'desarrollo-ceedcv-dms'
  const domainSuffix = hostname.substring(firstDot); // '.192.168.2.1.nip.io'
  const lastDash = firstSegment.lastIndexOf('-');
  const slotPrefix = lastDash !== -1 ? firstSegment.substring(0, lastDash + 1) : '';

  return `${protocol}//${slotPrefix}${targetService}${domainSuffix}`;
}

/** Origen de un servicio hermano desde `window.location`. */
export function peerOrigin(targetService: string): string {
  return peerOriginFor(targetService, window.location);
}

/**
 * Resuelve la URL de un servicio hermano: si el valor explícito (env o
 * configuración en ejecución) está definido y no vacío, se prefiere; en caso
 * contrario se deriva del hostname.
 */
export function resolveServiceUrl(envValue: string | undefined, targetService: string): string {
  const trimmed = envValue?.trim();
  if (trimmed) return trimmed.replace(/\/$/, '');
  return peerOrigin(targetService);
}
