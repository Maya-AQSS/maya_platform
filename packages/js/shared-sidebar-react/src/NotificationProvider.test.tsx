import { act } from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  NotificationProvider,
  notificationRealtimeModules,
  notificationReverbConfig,
  useNotificationChannel,
  type NotificationChannelContextValue,
} from './NotificationProvider'

// Usuario autenticado fijo: el provider solo conecta con token y sub.
vi.mock('@ceedcv-maya/shared-auth-react', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@ceedcv-maya/shared-auth-react')>()),
  useAuth: () => ({ token: 'jwt-de-prueba', user: { sub: 'user-1' } }),
}))

;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllEnvs()
  delete window.__MAYA_CONFIG__
})

/** /config.js de una app en K3s (los MAYA_PUBLIC_* del chart). */
const RUNTIME_CONFIG = {
  API_URL: 'https://api.dms.ceedcv/api/v1',
  REVERB_HOST: 'api.dms.ceedcv',
  REVERB_PORT: '443',
  REVERB_SCHEME: 'https',
  REVERB_AUTH_ENDPOINT: 'https://api.dms.ceedcv/api/v1/broadcasting/auth',
  REVERB_APP_KEY: 'dms-key',
}

describe('notificationReverbConfig', () => {
  it('returns null (polling) without host or key', () => {
    expect(notificationReverbConfig()).toBeNull()
    window.__MAYA_CONFIG__ = { REVERB_HOST: 'api.dms.ceedcv' }
    expect(notificationReverbConfig()).toBeNull()
  })

  it('reads the runtime config (/config.js)', () => {
    vi.stubEnv('VITE_REVERB_HOST', 'baked.example')
    window.__MAYA_CONFIG__ = RUNTIME_CONFIG
    expect(notificationReverbConfig()).toEqual({
      host: 'api.dms.ceedcv',
      key: 'dms-key',
      port: 443,
      scheme: 'https',
      authEndpoint: 'https://api.dms.ceedcv/api/v1/broadcasting/auth',
    })
  })

  it('falls back to VITE_REVERB_* with the previous defaults (8080, http)', () => {
    vi.stubEnv('VITE_REVERB_HOST', 'localhost')
    vi.stubEnv('VITE_REVERB_APP_KEY', 'local-key')
    expect(notificationReverbConfig()).toEqual({
      host: 'localhost',
      key: 'local-key',
      port: 8080,
      scheme: 'http',
      authEndpoint: '/api/broadcasting/auth',
    })
  })

  it('derives the auth endpoint from API_URL when REVERB_AUTH_ENDPOINT is missing', () => {
    window.__MAYA_CONFIG__ = {
      REVERB_HOST: 'api.audit.ceedcv',
      REVERB_APP_KEY: 'audit-key',
      API_URL: 'https://api.audit.ceedcv/api/v1/',
    }
    expect(notificationReverbConfig()?.authEndpoint).toBe('https://api.audit.ceedcv/api/v1/broadcasting/auth')
  })
})

// ─── Provider con un Echo de mentira ───────────────────────────────────────

type Callback = (...args: unknown[]) => void

class FakeChannel {
  name: string
  onSubscribed: Callback[] = []
  onError: Callback[] = []
  constructor(name: string) {
    this.name = name
  }
  listen() {
    return this
  }
  stopListening() {
    return this
  }
  subscribed(cb: Callback) {
    this.onSubscribed.push(cb)
    return this
  }
  error(cb: Callback) {
    this.onError.push(cb)
    return this
  }
}

class FakeEcho {
  static instances: FakeEcho[] = []
  options: Record<string, unknown>
  channels: FakeChannel[] = []
  stateListeners: Callback[] = []
  disconnected = false
  connector = {
    pusher: {
      connection: {
        bind: (event: string, cb: Callback) => {
          if (event === 'state_change') this.stateListeners.push(cb)
        },
      },
    },
  }
  constructor(options: Record<string, unknown>) {
    this.options = options
    FakeEcho.instances.push(this)
  }
  private(name: string) {
    const channel = new FakeChannel(name)
    this.channels.push(channel)
    return channel
  }
  disconnect() {
    this.disconnected = true
  }
}

let container: HTMLDivElement
let root: Root
let seen: NotificationChannelContextValue

function Probe() {
  seen = useNotificationChannel()
  return null
}

async function renderProvider() {
  await act(async () => {
    root.render(
      <NotificationProvider>
        <Probe />
      </NotificationProvider>,
    )
  })
}

describe('NotificationProvider', () => {
  beforeEach(() => {
    FakeEcho.instances = []
    container = document.createElement('div')
    root = createRoot(container)
  })

  afterEach(async () => {
    await act(async () => root.unmount())
  })

  it('does not load Echo without Reverb config (polling)', async () => {
    const load = vi.spyOn(notificationRealtimeModules, 'load')
    await renderProvider()
    expect(load).not.toHaveBeenCalled()
    expect(seen).toEqual({ channel: null, wsConnected: false })
  })

  it('connects with /config.js and only reports wsConnected once the subscription is confirmed', async () => {
    window.__MAYA_CONFIG__ = RUNTIME_CONFIG
    vi.spyOn(notificationRealtimeModules, 'load').mockResolvedValue({
      Echo: FakeEcho as never,
      Pusher: class {} as never,
    })

    await renderProvider()

    const [echo] = FakeEcho.instances
    expect(echo.options).toMatchObject({
      broadcaster: 'reverb',
      key: 'dms-key',
      wsHost: 'api.dms.ceedcv',
      wsPort: 443,
      wssPort: 443,
      forceTLS: true,
      authEndpoint: 'https://api.dms.ceedcv/api/v1/broadcasting/auth',
      auth: { headers: { Authorization: 'Bearer jwt-de-prueba', Accept: 'application/json' } },
    })
    const [channel] = echo.channels
    expect(channel.name).toBe('notifications.user-1')
    expect(seen.channel).toBe(channel)
    expect(seen.wsConnected).toBe(false) // aún sin confirmar: sigue el polling

    await act(async () => channel.onSubscribed.forEach((cb) => cb()))
    expect(seen.wsConnected).toBe(true)

    await act(async () => echo.stateListeners.forEach((cb) => cb({ previous: 'connected', current: 'unavailable' })))
    expect(seen.wsConnected).toBe(false) // conexión perdida: vuelve el polling

    await act(async () => channel.onSubscribed.forEach((cb) => cb()))
    expect(seen.wsConnected).toBe(true) // resuscrito al reconectar
  })

  it('keeps polling when the channel authorization fails', async () => {
    window.__MAYA_CONFIG__ = RUNTIME_CONFIG
    vi.spyOn(notificationRealtimeModules, 'load').mockResolvedValue({
      Echo: FakeEcho as never,
      Pusher: class {} as never,
    })

    await renderProvider()
    const [channel] = FakeEcho.instances[0].channels

    await act(async () => channel.onError.forEach((cb) => cb({ status: 403 })))
    expect(seen.wsConnected).toBe(false)
  })
})
