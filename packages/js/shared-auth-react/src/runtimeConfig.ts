/// <reference path="./env.d.ts" />
/**
 * Configuración pública en ejecución.
 *
 * En producción la imagen del frontend (maya/web-base) sirve `/config.js`, que
 * define `window.__MAYA_CONFIG__` a partir de las variables `MAYA_PUBLIC_*` del
 * contenedor. Así la misma imagen vale para cualquier entorno sin reconstruir.
 * En desarrollo (Vite) no existe y se usa `import.meta.env.VITE_<clave>`.
 *
 * La app carga `/config.js` en su `index.html` antes del bundle (ver README):
 *   <script src="/config.js"></script>
 *
 * @example
 *   publicConfig('API_URL')        // window.__MAYA_CONFIG__.API_URL ?? import.meta.env.VITE_API_URL
 *   publicConfig('KEYCLOAK_REALM') // 'CEED' en producción
 *   createOidcAdapter(publicKeycloakConfig())
 */
import type { KeycloakConfig } from './types';

declare global {
  interface Window {
    __MAYA_CONFIG__?: Readonly<Record<string, string | undefined>>;
  }
}

/**
 * Claves públicas conocidas (sin prefijo). En el chart son `MAYA_PUBLIC_<clave>`
 * (`frontend.env`) y en Vite `VITE_<clave>`. Se admite cualquier otra clave propia
 * de una app; la lista solo sirve para autocompletar.
 */
export type PublicConfigKey =
  | 'APP_NAME'
  | 'API_URL'
  | 'KEYCLOAK_URL'
  | 'KEYCLOAK_REALM'
  | 'KEYCLOAK_CLIENT_ID'
  | 'DASHBOARD_URL'
  | 'DASHBOARD_API_URL'
  | 'PEER_HOST_PATTERN'
  | 'REVERB_HOST'
  | 'REVERB_PORT'
  | 'REVERB_SCHEME'
  | 'REVERB_APP_KEY'
  | 'REVERB_AUTH_ENDPOINT'
  // Reverb del dashboard, el que escucha la barra lateral de todas las apps (D37).
  | 'DASHBOARD_REVERB_HOST'
  | 'DASHBOARD_REVERB_PORT'
  | 'DASHBOARD_REVERB_SCHEME'
  | 'DASHBOARD_REVERB_APP_KEY'
  | 'SESSION_COOKIE_DOMAIN'
  | (string & {});

type EnvLike = Record<string, string | undefined> | undefined;

// Acceso directo a import.meta.env: Vite lo sustituye estáticamente en build
// (y vitest lo stubea); una referencia indirecta no se reemplaza.
function viteEnv(): EnvLike {
  return import.meta.env as EnvLike;
}

/** Valor de una clave pública (sin prefijo): runtime primero, `VITE_<clave>` después. */
export function publicConfig(key: PublicConfigKey): string | undefined {
  if (typeof window !== 'undefined') {
    const runtime = window.__MAYA_CONFIG__?.[key];
    if (typeof runtime === 'string' && runtime.trim() !== '') return runtime.trim();
  }
  const fromEnv = viteEnv()?.[`VITE_${key}`];
  return typeof fromEnv === 'string' && fromEnv.trim() !== '' ? fromEnv.trim() : undefined;
}

/**
 * Como {@link publicConfig}, pero falla si la clave no está: arrancar sin ella
 * (p. ej. sin Keycloak) dejaría la app en un bucle de errores opacos.
 */
export function requirePublicConfig(key: PublicConfigKey): string {
  const value = publicConfig(key);
  if (value === undefined) {
    throw new Error(
      `Missing public config ${key}: MAYA_PUBLIC_${key} (/config.js) or VITE_${key}` +
        (hasRuntimeConfig() ? '' : ' (/config.js not loaded)'),
    );
  }
  return value;
}

/**
 * Configuración de Keycloak para `createOidcAdapter` / `AuthProvider`:
 * `KEYCLOAK_URL`, `KEYCLOAK_REALM` y `KEYCLOAK_CLIENT_ID`, obligatorias.
 */
export function publicKeycloakConfig(): KeycloakConfig {
  return {
    url: requirePublicConfig('KEYCLOAK_URL'),
    realm: requirePublicConfig('KEYCLOAK_REALM'),
    clientId: requirePublicConfig('KEYCLOAK_CLIENT_ID'),
  };
}

/** True si el frontend se sirve con configuración en ejecución (`/config.js` cargado). */
export function hasRuntimeConfig(): boolean {
  return typeof window !== 'undefined' && typeof window.__MAYA_CONFIG__ === 'object' && window.__MAYA_CONFIG__ !== null;
}
