import { describe, expect, it, vi, afterEach } from 'vitest';
import { detectPeerHostPattern, peerOrigin, peerOriginFor, resolveServiceUrl } from './peerService';

// Helper to mock window.location
function mockLocation(hostname: string, protocol = 'https:') {
  Object.defineProperty(window, 'location', {
    value: { protocol, hostname },
    writable: true,
    configurable: true,
  });
}

afterEach(() => {
  vi.restoreAllMocks();
  delete window.__MAYA_CONFIG__;
});

describe('detectPeerHostPattern', () => {
  it('honours an explicit pattern', () => {
    expect(detectPeerHostPattern('dms.ceedcv.es', 'dash')).toBe('dash');
    expect(detectPeerHostPattern('slot-dms.192.168.2.1.nip.io', 'subdomain-api')).toBe('subdomain-api');
  });

  it('treats dev suffixes, IPs and slot prefixes as dash', () => {
    expect(detectPeerHostPattern('desarrollo-ceedcv-dms.192.168.2.1.nip.io')).toBe('dash');
    expect(detectPeerHostPattern('dms.maya.test')).toBe('dash');
    expect(detectPeerHostPattern('dev-dms.local')).toBe('dash');
    expect(detectPeerHostPattern('localhost')).toBe('dash');
    expect(detectPeerHostPattern('dms-api.ceedcv.es')).toBe('dash');
  });

  it('treats production hosts as subdomain-api', () => {
    expect(detectPeerHostPattern('dms.ceedcv.es')).toBe('subdomain-api');
    expect(detectPeerHostPattern('api.dms.ceedcv.es')).toBe('subdomain-api');
  });
});

describe('peerOrigin (dash pattern, desarrollo)', () => {
  it('derives peer origin from slot-prefixed hostname', () => {
    mockLocation('desarrollo-ceedcv-dms.192.168.2.1.nip.io');
    expect(peerOrigin('dashboard-api')).toBe(
      'https://desarrollo-ceedcv-dashboard-api.192.168.2.1.nip.io',
    );
  });

  it('replaces the last service segment, keeping the slot prefix', () => {
    mockLocation('slot1-dms.maya.test');
    expect(peerOrigin('authorization-api')).toBe('https://slot1-authorization-api.maya.test');
  });

  it('handles single-segment hostnames (e.g. localhost) with no dot', () => {
    mockLocation('localhost');
    expect(peerOrigin('dashboard-api')).toBe('https://localhost');
  });

  it('preserves the protocol (http)', () => {
    mockLocation('dev-dms.local', 'http:');
    expect(peerOrigin('dms-api')).toBe('http://dev-dms-api.local');
  });

  it('handles a hostname with no slot prefix (service is only segment before first dot)', () => {
    mockLocation('dms.maya.test');
    expect(peerOrigin('logs-api')).toBe('https://logs-api.maya.test');
  });

  it('handles deep domain suffixes correctly', () => {
    mockLocation('ceedcv-dms.192.168.1.100.nip.io');
    expect(peerOrigin('authorization-reverb')).toBe(
      'https://ceedcv-authorization-reverb.192.168.1.100.nip.io',
    );
  });
});

describe('peerOrigin (subdomain-api pattern, producción)', () => {
  it('maps <app>-api to api.<app>.<domain>', () => {
    mockLocation('dms.ceedcv.es');
    expect(peerOrigin('dashboard-api')).toBe('https://api.dashboard.ceedcv.es');
  });

  it('maps <app>-reverb to the api host (Reverb lives under /app)', () => {
    mockLocation('dms.ceedcv.es');
    expect(peerOrigin('dms-reverb')).toBe('https://api.dms.ceedcv.es');
  });

  it('maps a plain app to <app>.<domain>', () => {
    mockLocation('dms.ceedcv.es');
    expect(peerOrigin('audit')).toBe('https://audit.ceedcv.es');
  });

  it('strips a leading api. label from the current host', () => {
    mockLocation('api.dms.ceedcv.es');
    expect(peerOrigin('dms')).toBe('https://dms.ceedcv.es');
    expect(peerOrigin('logs-api')).toBe('https://api.logs.ceedcv.es');
  });

  it('uses the runtime config PEER_HOST_PATTERN when present', () => {
    mockLocation('dms.ceedcv.es');
    window.__MAYA_CONFIG__ = { PEER_HOST_PATTERN: 'dash' };
    expect(peerOrigin('dashboard-api')).toBe('https://dashboard-api.ceedcv.es');
  });

  it('peerOriginFor accepts an explicit pattern', () => {
    expect(peerOriginFor('dashboard-api', { protocol: 'https:', hostname: 'dms.maya.test' }, 'subdomain-api')).toBe(
      'https://api.dashboard.maya.test',
    );
  });
});

describe('resolveServiceUrl', () => {
  it('returns env value (trimmed, trailing slash removed) when defined', () => {
    mockLocation('dms.maya.test');
    expect(resolveServiceUrl('https://my-api.example.com/  ', 'dashboard-api')).toBe(
      'https://my-api.example.com',
    );
  });

  it('falls back to peerOrigin when env value is undefined', () => {
    mockLocation('dms.maya.test');
    expect(resolveServiceUrl(undefined, 'dashboard-api')).toBe('https://dashboard-api.maya.test');
  });

  it('falls back to peerOrigin when env value is empty string', () => {
    mockLocation('dms.maya.test');
    expect(resolveServiceUrl('', 'dashboard-api')).toBe('https://dashboard-api.maya.test');
  });

  it('falls back to peerOrigin when env value is whitespace only', () => {
    mockLocation('dms.maya.test');
    expect(resolveServiceUrl('   ', 'dashboard-api')).toBe('https://dashboard-api.maya.test');
  });
});
