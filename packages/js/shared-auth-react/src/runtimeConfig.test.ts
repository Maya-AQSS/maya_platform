import { afterEach, describe, expect, it, vi } from 'vitest';
import { hasRuntimeConfig, publicConfig } from './runtimeConfig';

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
});
