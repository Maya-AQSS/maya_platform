/// <reference path="./env.d.ts" />
/**
 * Configuración pública en ejecución.
 *
 * En producción la imagen del frontend (maya/web-base) sirve `/config.js`, que
 * define `window.__MAYA_CONFIG__` a partir de las variables `MAYA_PUBLIC_*` del
 * contenedor. Así la misma imagen vale para cualquier entorno sin reconstruir.
 * En desarrollo (Vite) no existe y se usa `import.meta.env.VITE_<clave>`.
 *
 * @example
 *   publicConfig('API_URL')        // window.__MAYA_CONFIG__.API_URL ?? import.meta.env.VITE_API_URL
 *   publicConfig('KEYCLOAK_REALM') // 'CEED' en producción
 */
declare global {
  interface Window {
    __MAYA_CONFIG__?: Readonly<Record<string, string | undefined>>;
  }
}

type EnvLike = Record<string, string | undefined> | undefined;

// Acceso directo a import.meta.env: Vite lo sustituye estáticamente en build
// (y vitest lo stubea); una referencia indirecta no se reemplaza.
function viteEnv(): EnvLike {
  return import.meta.env as EnvLike;
}

/** Valor de una clave pública (sin prefijo): runtime primero, `VITE_<clave>` después. */
export function publicConfig(key: string): string | undefined {
  if (typeof window !== 'undefined') {
    const runtime = window.__MAYA_CONFIG__?.[key];
    if (typeof runtime === 'string' && runtime.trim() !== '') return runtime.trim();
  }
  const fromEnv = viteEnv()?.[`VITE_${key}`];
  return typeof fromEnv === 'string' && fromEnv.trim() !== '' ? fromEnv.trim() : undefined;
}

/** True si el frontend se sirve con configuración en ejecución (`/config.js` cargado). */
export function hasRuntimeConfig(): boolean {
  return typeof window !== 'undefined' && typeof window.__MAYA_CONFIG__ === 'object' && window.__MAYA_CONFIG__ !== null;
}
