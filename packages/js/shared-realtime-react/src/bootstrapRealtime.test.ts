import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { bootstrapRealtime } from './bootstrapRealtime';
import * as createEchoModule from './createEcho';

// Mock window.location for peerOrigin (imported transitively via bootstrapRealtime)
function mockLocation(hostname: string, protocol = 'https:') {
  Object.defineProperty(window, 'location', {
    value: { protocol, hostname },
    writable: true,
    configurable: true,
  });
}

// Mock the createEcho function so no actual WebSocket is opened
const mockCreateEcho = vi.spyOn(createEchoModule, 'createEcho').mockReturnValue(
  {} as ReturnType<typeof createEchoModule.createEcho>,
);

beforeEach(() => {
  mockCreateEcho.mockClear();
  mockLocation('ceedcv-authorization.maya.test');
});

afterEach(() => {
  vi.unstubAllEnvs();
  delete window.__MAYA_CONFIG__;
  // Do NOT call vi.restoreAllMocks() — it would restore the module-level spy
  // back to the real createEcho, breaking subsequent tests.
});

describe('bootstrapRealtime', () => {
  it('does nothing when VITE_REVERB_APP_KEY is not set', () => {
    vi.stubEnv('VITE_REVERB_APP_KEY', '');
    bootstrapRealtime('authorization', async () => null);
    expect(mockCreateEcho).not.toHaveBeenCalled();
  });

  it('does nothing when VITE_REVERB_APP_KEY is whitespace only', () => {
    vi.stubEnv('VITE_REVERB_APP_KEY', '   ');
    bootstrapRealtime('authorization', async () => null);
    expect(mockCreateEcho).not.toHaveBeenCalled();
  });

  it('calls createEcho with correct defaults when only APP_KEY is set', () => {
    vi.stubEnv('VITE_REVERB_APP_KEY', 'test-key-123');
    mockLocation('ceedcv-authorization.maya.test');

    bootstrapRealtime('authorization', async () => 'token-abc');

    expect(mockCreateEcho).toHaveBeenCalledOnce();
    const config = mockCreateEcho.mock.calls[0][0];
    expect(config.appKey).toBe('test-key-123');
    // host should be derived from peerOrigin('<slug>-reverb')
    expect(config.host).toBe('ceedcv-authorization-reverb.maya.test');
    // default scheme is https
    expect(config.scheme).toBe('https');
    // default port for https is 443
    expect(config.port).toBe(443);
    // authEndpoint uses peerOrigin('<slug>-api') + /api/v1/broadcasting/auth
    expect(config.authEndpoint).toBe(
      'https://ceedcv-authorization-api.maya.test/api/v1/broadcasting/auth',
    );
  });

  it('uses VITE_REVERB_HOST when provided', () => {
    vi.stubEnv('VITE_REVERB_APP_KEY', 'test-key');
    vi.stubEnv('VITE_REVERB_HOST', 'custom-reverb.example.com');

    bootstrapRealtime('dms', async () => 'tok');

    const config = mockCreateEcho.mock.calls[0][0];
    expect(config.host).toBe('custom-reverb.example.com');
  });

  it('uses VITE_REVERB_SCHEME=http and default port 80', () => {
    vi.stubEnv('VITE_REVERB_APP_KEY', 'key');
    vi.stubEnv('VITE_REVERB_SCHEME', 'http');

    bootstrapRealtime('dms', async () => null);

    const config = mockCreateEcho.mock.calls[0][0];
    expect(config.scheme).toBe('http');
    expect(config.port).toBe(80);
  });

  it('uses VITE_REVERB_PORT when provided', () => {
    vi.stubEnv('VITE_REVERB_APP_KEY', 'key');
    vi.stubEnv('VITE_REVERB_PORT', '8080');

    bootstrapRealtime('dms', async () => null);

    const config = mockCreateEcho.mock.calls[0][0];
    expect(config.port).toBe(8080);
  });

  it('passes getBearerToken through to createEcho', async () => {
    vi.stubEnv('VITE_REVERB_APP_KEY', 'key');
    const getBearerToken = vi.fn().mockResolvedValue('my-token');

    bootstrapRealtime('authorization', getBearerToken);

    const config = mockCreateEcho.mock.calls[0][0];
    const result = await config.getBearerToken();
    expect(result).toBe('my-token');
    expect(getBearerToken).toHaveBeenCalledOnce();
  });

  it('uses serviceSlug to derive both reverb host and api authEndpoint', () => {
    vi.stubEnv('VITE_REVERB_APP_KEY', 'key');
    mockLocation('ceedcv-dms.maya.test');

    bootstrapRealtime('dms', async () => null);

    const config = mockCreateEcho.mock.calls[0][0];
    expect(config.host).toBe('ceedcv-dms-reverb.maya.test');
    expect(config.authEndpoint).toBe('https://ceedcv-dms-api.maya.test/api/v1/broadcasting/auth');
  });

  it('derives production hosts with the subdomain-api pattern (api.<app>.<domain>)', () => {
    vi.stubEnv('VITE_REVERB_APP_KEY', 'key');
    mockLocation('dms.ceedcv.es');

    bootstrapRealtime('dms', async () => null);

    const config = mockCreateEcho.mock.calls[0][0];
    expect(config.host).toBe('api.dms.ceedcv.es');
    expect(config.authEndpoint).toBe('https://api.dms.ceedcv.es/api/v1/broadcasting/auth');
  });

  it('honours options.authEndpoint', () => {
    vi.stubEnv('VITE_REVERB_APP_KEY', 'key');

    bootstrapRealtime('dms', async () => null, { authEndpoint: 'https://api.dms.example/api/v1/broadcasting/auth' });

    const config = mockCreateEcho.mock.calls[0][0];
    expect(config.authEndpoint).toBe('https://api.dms.example/api/v1/broadcasting/auth');
  });

  it('prefers the runtime config (window.__MAYA_CONFIG__) over baked env vars', () => {
    vi.stubEnv('VITE_REVERB_APP_KEY', 'baked-key');
    vi.stubEnv('VITE_REVERB_HOST', 'baked-reverb.example');
    window.__MAYA_CONFIG__ = {
      REVERB_APP_KEY: 'runtime-key',
      REVERB_HOST: 'api.dms.ceedcv.es',
      REVERB_AUTH_ENDPOINT: 'https://api.dms.ceedcv.es/api/v1/broadcasting/auth',
    };

    bootstrapRealtime('dms', async () => null);

    const config = mockCreateEcho.mock.calls[0][0];
    expect(config.appKey).toBe('runtime-key');
    expect(config.host).toBe('api.dms.ceedcv.es');
    expect(config.authEndpoint).toBe('https://api.dms.ceedcv.es/api/v1/broadcasting/auth');
  });
});
