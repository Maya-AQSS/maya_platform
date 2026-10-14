import { afterEach, describe, expect, it, vi } from 'vitest';
import { hasRuntimeConfig, publicConfig, publicKeycloakConfig, requirePublicConfig } from './runtimeConfig';

afterEach(() => {
  vi.unstubAllEnvs();
  delete window.__MAYA_CONFIG__;
});

describe('publicConfig', () => {
  it('returns undefined when neither runtime config nor env define the key', () => {
    expect(publicConfig('API_URL')).toBeUndefined();
    expect(hasRuntimeConfig()).toBe(false);
  });

  it('falls back to VITE_<key> from import.meta.env', () => {
    vi.stubEnv('VITE_API_URL', ' https://api.dms.maya.test/api/v1 ');
    expect(publicConfig('API_URL')).toBe('https://api.dms.maya.test/api/v1');
  });

  it('prefers window.__MAYA_CONFIG__ over the env (same image, any environment)', () => {
    vi.stubEnv('VITE_API_URL', 'https://baked.example/api/v1');
    window.__MAYA_CONFIG__ = { API_URL: 'https://api.dms.ceedcv.es/api/v1' };
    expect(publicConfig('API_URL')).toBe('https://api.dms.ceedcv.es/api/v1');
    expect(hasRuntimeConfig()).toBe(true);
  });

  it('ignores empty runtime values and uses the env', () => {
    vi.stubEnv('VITE_KEYCLOAK_REALM', 'maya');
    window.__MAYA_CONFIG__ = { KEYCLOAK_REALM: '  ' };
    expect(publicConfig('KEYCLOAK_REALM')).toBe('maya');
  });

  it('reads the /config.js that maya/web-base generates from MAYA_PUBLIC_*', () => {
    // Mismo formato que docker/web-base/entrypoint.sh (MAYA_PUBLIC_<CLAVE> → "<CLAVE>").
    const configJs = [
      '// Generado por maya-web-entrypoint al arrancar el contenedor. No editar.',
      'window.__MAYA_CONFIG__ = Object.freeze({',
      '  "API_URL": "https://api.dms.ceedcv/api/v1",',
      '  "DASHBOARD_API_URL": "https://api.dashboard.ceedcv",',
      '  "KEYCLOAK_CLIENT_ID": "maya-dms",',
      '  "KEYCLOAK_REALM": "CEED",',
      '  "KEYCLOAK_URL": "https://keycloak.ceedcv"',
      '});',
    ].join('\n');
    new Function(configJs)();

    expect(hasRuntimeConfig()).toBe(true);
    expect(publicConfig('API_URL')).toBe('https://api.dms.ceedcv/api/v1');
    expect(publicConfig('DASHBOARD_API_URL')).toBe('https://api.dashboard.ceedcv');
    expect(publicKeycloakConfig()).toEqual({
      url: 'https://keycloak.ceedcv',
      realm: 'CEED',
      clientId: 'maya-dms',
    });
  });
});

describe('requirePublicConfig', () => {
  it('returns the value when the key is set', () => {
    window.__MAYA_CONFIG__ = { KEYCLOAK_URL: 'https://keycloak.ceedcv' };
    expect(requirePublicConfig('KEYCLOAK_URL')).toBe('https://keycloak.ceedcv');
  });

  it('throws naming both sources when the key is missing', () => {
    expect(() => requirePublicConfig('KEYCLOAK_URL')).toThrow(
      'Missing public config KEYCLOAK_URL: MAYA_PUBLIC_KEYCLOAK_URL (/config.js) or VITE_KEYCLOAK_URL (/config.js not loaded)',
    );
  });

  it('does not blame /config.js when it was loaded but lacks the key', () => {
    window.__MAYA_CONFIG__ = { API_URL: 'https://api.dms.ceedcv/api/v1' };
    expect(() => requirePublicConfig('KEYCLOAK_REALM')).toThrow(
      /^Missing public config KEYCLOAK_REALM: MAYA_PUBLIC_KEYCLOAK_REALM \(\/config\.js\) or VITE_KEYCLOAK_REALM$/,
    );
  });
});

describe('publicKeycloakConfig', () => {
  it('falls back to VITE_KEYCLOAK_* under Vite (no /config.js)', () => {
    vi.stubEnv('VITE_KEYCLOAK_URL', 'https://dev-keycloak.10.224.237.230.nip.io');
    vi.stubEnv('VITE_KEYCLOAK_REALM', 'CEED');
    vi.stubEnv('VITE_KEYCLOAK_CLIENT_ID', 'maya-audit');
    expect(publicKeycloakConfig()).toEqual({
      url: 'https://dev-keycloak.10.224.237.230.nip.io',
      realm: 'CEED',
      clientId: 'maya-audit',
    });
  });

  it('fails fast when a key is missing', () => {
    window.__MAYA_CONFIG__ = { KEYCLOAK_URL: 'https://keycloak.ceedcv', KEYCLOAK_REALM: 'CEED' };
    expect(() => publicKeycloakConfig()).toThrow('Missing public config KEYCLOAK_CLIENT_ID');
  });
});
