import { afterEach, describe, expect, it, vi } from 'vitest';
import { writeOverrides } from './sessionOverrides';

/** Cabeceras Set-Cookie que escribe writeOverrides (sin tocar el almacén de jsdom). */
function captureCookieWrites(): string[] {
  const writes: string[] = [];
  vi.spyOn(document, 'cookie', 'set').mockImplementation((value: string) => {
    writes.push(value);
  });
  return writes;
}

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllEnvs();
  delete window.__MAYA_CONFIG__;
});

describe('session overrides cookie domain', () => {
  it('uses SESSION_COOKIE_DOMAIN from the runtime config (/config.js)', () => {
    const writes = captureCookieWrites();
    window.__MAYA_CONFIG__ = { SESSION_COOKIE_DOMAIN: 'maya.example' };

    writeOverrides({ locale: 'es' });

    expect(writes.at(-1)).toContain('Domain=maya.example');
  });

  it('falls back to VITE_SESSION_COOKIE_DOMAIN', () => {
    const writes = captureCookieWrites();
    vi.stubEnv('VITE_SESSION_COOKIE_DOMAIN', 'vite.example');

    writeOverrides({ locale: 'va' });

    expect(writes.at(-1)).toContain('Domain=vite.example');
  });

  it('derives the domain from the host when nothing is configured', () => {
    const writes = captureCookieWrites();

    writeOverrides({ locale: 'en' });

    expect(writes.at(-1)).toContain(`Domain=${window.location.hostname}`);
  });
});
