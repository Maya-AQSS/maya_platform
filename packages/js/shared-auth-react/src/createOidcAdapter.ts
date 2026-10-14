/**
 * createOidcAdapter — factory para inicializar el cliente OIDC (Keycloak).
 *
 * Extrae el patrón boilerplate de `src/auth/oidcAdapter.ts` que existe en cada
 * uno de los cinco microservicios Maya. Devuelve los tres primitivos que el resto
 * de la app necesita.
 *
 * @example
 * // src/auth/oidcAdapter.ts  (en cualquier app Maya)
 * import { createOidcAdapter, publicKeycloakConfig } from '@ceedcv-maya/shared-auth-react';
 *
 * // KEYCLOAK_URL/REALM/CLIENT_ID de /config.js (MAYA_PUBLIC_*) o, en Vite, de VITE_*.
 * export const { oidcAuthService, appendBearerAuthorization, triggerSignIn } =
 *   createOidcAdapter(publicKeycloakConfig());
 */
import { AuthService } from './authService';
import type { KeycloakConfig } from './types';

export interface OidcAdapter {
  /** Configured AuthService instance. Use `.keycloak` to pass to `createApiClient`. */
  oidcAuthService: AuthService;
  /**
   * Attaches `Authorization: Bearer <token>` to a headers object.
   * Renews the token if expiring within 30 s.
   */
  appendBearerAuthorization: (headers: Record<string, string>) => Promise<void>;
  /** Triggers the Keycloak login redirect flow. */
  triggerSignIn: () => void;
}

export function createOidcAdapter(config: KeycloakConfig): OidcAdapter {
  const oidcAuthService = new AuthService(config);

  const appendBearerAuthorization = (headers: Record<string, string>): Promise<void> =>
    oidcAuthService.appendBearerAuthorization(headers);

  const triggerSignIn = (): void => oidcAuthService.triggerSignIn();

  return { oidcAuthService, appendBearerAuthorization, triggerSignIn };
}
