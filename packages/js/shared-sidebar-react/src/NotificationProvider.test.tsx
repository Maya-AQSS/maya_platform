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
import { useNotifications } from './useNotifications'

// Usuario autenticado fijo: el provider solo conecta con token y sub.
vi.mock('@ceedcv-maya/shared-auth-react', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@ceedcv-maya/shared-auth-react')>()),
  useAuth: () => ({ token: 'jwt-de-prueba', user: { sub: 'user-1' } }),
}))

;(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllEnvs()
  vi.unstubAllGlobals()
  vi.useRealTimers()
  delete window.__MAYA_CONFIG__
})

/**
 * /config.js de una app que no es el dashboard (dms en K3s, los MAYA_PUBLIC_* del
 * chart): su propio Reverb y el del dashboard (D37).
 */
const DMS_CONFIG = {
  API_URL: 'https://api.dms.ceedcv/api/v1',
  DASHBOARD_API_URL: 'https://api.dashboard.ceedcv',
  REVERB_HOST: 'api.dms.ceedcv',
  REVERB_PORT: '443',
  REVERB_SCHEME: 'https',
  REVERB_AUTH_ENDPOINT: 'https://api.dms.ceedcv/api/v1/broadcasting/auth',
  REVERB_APP_KEY: 'dms-key',
  DASHBOARD_REVERB_HOST: 'api.dashboard.ceedcv',
  DASHBOARD_REVERB_PORT: '443',
  DASHBOARD_REVERB_SCHEME: 'https',
  DASHBOARD_REVERB_APP_KEY: 'dashboard-key',
}

/** /config.js del propio dashboard. */
const DASHBOARD_CONFIG = {
  API_URL: 'https://api.dashboard.ceedcv/api/v1',
  DASHBOARD_API_URL: 'https://api.dashboard.ceedcv',
  REVERB_HOST: 'api.dashboard.ceedcv',
  REVERB_PORT: '443',
  REVERB_SCHEME: 'https',
  REVERB_AUTH_ENDPOINT: 'https://api.dashboard.ceedcv/api/v1/broadcasting/auth',
  REVERB_APP_KEY: 'dashboard-key',
}

const DASHBOARD_REVERB = {
  host: 'api.dashboard.ceedcv',
  key: 'dashboard-key',
  port: 443,
  scheme: 'https',
  authEndpoint: 'https://api.dashboard.ceedcv/api/v1/broadcasting/auth',
}

describe('notificationReverbConfig', () => {
  it('returns null (polling) without the dashboard Reverb key', () => {
    expect(notificationReverbConfig()).toBeNull()
    window.__MAYA_CONFIG__ = { DASHBOARD_REVERB_HOST: 'api.dashboard.ceedcv' }
    expect(notificationReverbConfig()).toBeNull()
  })

  it('never uses the own Reverb of an app that is not the dashboard', () => {
    const { DASHBOARD_REVERB_APP_KEY: _key, ...withoutDashboardKey } = DMS_CONFIG
    window.__MAYA_CONFIG__ = withoutDashboardKey
    expect(notificationReverbConfig()).toBeNull()
  })

  it('reads the dashboard Reverb from the runtime config (/config.js)', () => {
    vi.stubEnv('VITE_DASHBOARD_REVERB_HOST', 'baked.example')
    window.__MAYA_CONFIG__ = DMS_CONFIG
    expect(notificationReverbConfig()).toEqual(DASHBOARD_REVERB)
  })

  it('derives the host from DASHBOARD_API_URL, with 443 and https by default', () => {
    window.__MAYA_CONFIG__ = {
      API_URL: 'https://api.audit.ceedcv/api/v1',
      DASHBOARD_API_URL: 'https://api.dashboard.ceedcv/',
      DASHBOARD_REVERB_APP_KEY: 'dashboard-key',
    }
    expect(notificationReverbConfig()).toEqual(DASHBOARD_REVERB)
  })

  it('defaults to port 80 with http', () => {
    window.__MAYA_CONFIG__ = {
      DASHBOARD_API_URL: 'http://dashboard-api.maya.test',
      DASHBOARD_REVERB_APP_KEY: 'local-key',
      DASHBOARD_REVERB_SCHEME: 'http',
    }
    expect(notificationReverbConfig()).toMatchObject({ host: 'dashboard-api.maya.test', port: 80, scheme: 'http' })
  })

  it('falls back to VITE_DASHBOARD_REVERB_*', () => {
    vi.stubEnv('VITE_DASHBOARD_API_URL', 'https://desarrollo-dashboard-api.maya.test')
    vi.stubEnv('VITE_DASHBOARD_REVERB_HOST', 'desarrollo-dashboard-reverb.maya.test')
    vi.stubEnv('VITE_DASHBOARD_REVERB_APP_KEY', 'local-key')
    expect(notificationReverbConfig()).toEqual({
      host: 'desarrollo-dashboard-reverb.maya.test',
      key: 'local-key',
      port: 443,
      scheme: 'https',
      authEndpoint: 'https://desarrollo-dashboard-api.maya.test/api/v1/broadcasting/auth',
    })
  })

  it('uses its own Reverb in the dashboard (the same server)', () => {
    window.__MAYA_CONFIG__ = DASHBOARD_CONFIG
    expect(notificationReverbConfig()).toEqual(DASHBOARD_REVERB)
  })

  it('keeps the previous defaults of the own Reverb in the dashboard (8080, http)', () => {
    vi.stubEnv('VITE_API_URL', 'http://localhost:8000/api/v1')
    vi.stubEnv('VITE_DASHBOARD_API_URL', 'http://localhost:8000')
    vi.stubEnv('VITE_REVERB_HOST', 'localhost')
    vi.stubEnv('VITE_REVERB_APP_KEY', 'local-key')
    expect(notificationReverbConfig()).toEqual({
      host: 'localhost',
      key: 'local-key',
      port: 8080,
      scheme: 'http',
      authEndpoint: 'http://localhost:8000/api/v1/broadcasting/auth',
    })
  })

  it('uses DASHBOARD_REVERB_* in the dashboard when its own Reverb is missing', () => {
    window.__MAYA_CONFIG__ = {
      API_URL: 'https://api.dashboard.ceedcv/api/v1',
      DASHBOARD_API_URL: 'https://api.dashboard.ceedcv',
      DASHBOARD_REVERB_APP_KEY: 'dashboard-key',
    }
    expect(notificationReverbConfig()).toEqual(DASHBOARD_REVERB)
  })
})

// ─── Provider con un Echo de mentira ───────────────────────────────────────

type Callback = (...args: unknown[]) => void

class FakeChannel {
  name: string
  onSubscribed: Callback[] = []
  onError: Callback[] = []
  listeners = new Map<string, Callback>()
  constructor(name: string) {
    this.name = name
  }
  listen(event: string, cb: Callback) {
    this.listeners.set(event, cb)
    return this
  }
  stopListening(event: string) {
    this.listeners.delete(event)
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
  emit(event: string, payload: unknown) {
    this.listeners.get(event)?.(payload)
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

function mockRealtimeModules() {
  return vi.spyOn(notificationRealtimeModules, 'load').mockResolvedValue({
    Echo: FakeEcho as never,
    Pusher: class {} as never,
  })
}

let container: HTMLDivElement
let root: Root
let seen: NotificationChannelContextValue

function Probe() {
  seen = useNotificationChannel()
  return null
}

async function renderProvider(children = <Probe />) {
  await act(async () => {
    root.render(<NotificationProvider>{children}</NotificationProvider>)
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

  it('does not connect to the own Reverb of an app that is not the dashboard', async () => {
    const { DASHBOARD_REVERB_APP_KEY: _key, ...withoutDashboardKey } = DMS_CONFIG
    window.__MAYA_CONFIG__ = withoutDashboardKey
    const load = mockRealtimeModules()
    await renderProvider()
    expect(load).not.toHaveBeenCalled()
    expect(seen).toEqual({ channel: null, wsConnected: false })
  })

  it('connects to the dashboard Reverb and authorizes the channel in dashboard-api with the SPA token', async () => {
    window.__MAYA_CONFIG__ = DMS_CONFIG
    mockRealtimeModules()

    await renderProvider()

    const [echo] = FakeEcho.instances
    expect(echo.options).toMatchObject({
      broadcaster: 'reverb',
      key: 'dashboard-key',
      wsHost: 'api.dashboard.ceedcv',
      wsPort: 443,
      wssPort: 443,
      forceTLS: true,
      authEndpoint: 'https://api.dashboard.ceedcv/api/v1/broadcasting/auth',
      auth: { headers: { Authorization: 'Bearer jwt-de-prueba', Accept: 'application/json' } },
    })
    const [channel] = echo.channels
    expect(channel.name).toBe('notifications.user-1')
    expect(seen.channel).toBe(channel)
  })

  it('only reports wsConnected once the subscription is confirmed', async () => {
    window.__MAYA_CONFIG__ = DMS_CONFIG
    mockRealtimeModules()

    await renderProvider()
    const [echo] = FakeEcho.instances
    const [channel] = echo.channels
    expect(seen.wsConnected).toBe(false) // aún sin confirmar: sigue el polling

    await act(async () => channel.onSubscribed.forEach((cb) => cb()))
    expect(seen.wsConnected).toBe(true)

    await act(async () => echo.stateListeners.forEach((cb) => cb({ previous: 'connected', current: 'unavailable' })))
    expect(seen.wsConnected).toBe(false) // conexión perdida: vuelve el polling

    await act(async () => channel.onSubscribed.forEach((cb) => cb()))
    expect(seen.wsConnected).toBe(true) // resuscrito al reconectar
  })

  it('keeps polling when the channel authorization fails', async () => {
    window.__MAYA_CONFIG__ = DMS_CONFIG
    mockRealtimeModules()

    await renderProvider()
    const [channel] = FakeEcho.instances[0].channels

    await act(async () => channel.onError.forEach((cb) => cb({ status: 403 })))
    expect(seen.wsConnected).toBe(false)
  })
})

// ─── Barra lateral: consulta periódica de respaldo ─────────────────────────

describe('useNotifications with the dashboard Reverb', () => {
  const POLL_MS = 1_000
  let bell: ReturnType<typeof useNotifications>

  function Bell() {
    bell = useNotifications({ dashboardApiUrl: 'https://api.dashboard.ceedcv', pollMs: POLL_MS })
    return null
  }

  function jsonResponse(body: unknown) {
    return { ok: true, json: async () => body } as Response
  }

  beforeEach(() => {
    FakeEcho.instances = []
    container = document.createElement('div')
    root = createRoot(container)
  })

  afterEach(async () => {
    await act(async () => root.unmount())
  })

  it('polls dashboard-api until Reverb confirms the subscription, and again when the connection drops', async () => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
    window.__MAYA_CONFIG__ = DMS_CONFIG
    mockRealtimeModules()
    const fetchMock = vi.fn(async (url: string) =>
      jsonResponse(url.endsWith('/unread-count') ? { data: { unread: 0 } } : { data: [] }),
    )
    vi.stubGlobal('fetch', fetchMock)

    await renderProvider(<Bell />)
    // Carga inicial por HTTP (lista + contador) contra dashboard-api, con el token de la SPA.
    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(fetchMock).toHaveBeenCalledWith('https://api.dashboard.ceedcv/api/v1/notifications?per_page=10', {
      headers: { Accept: 'application/json', Authorization: 'Bearer jwt-de-prueba' },
    })

    // Sin confirmar la suscripción: sigue la consulta periódica.
    await act(async () => vi.advanceTimersByTime(POLL_MS))
    expect(fetchMock).toHaveBeenCalledTimes(4)

    // Reverb confirma: se para la consulta periódica.
    const [echo] = FakeEcho.instances
    const [channel] = echo.channels
    await act(async () => channel.onSubscribed.forEach((cb) => cb()))
    await act(async () => vi.advanceTimersByTime(POLL_MS * 3))
    expect(fetchMock).toHaveBeenCalledTimes(4)

    // Llega una notificación por el WebSocket del dashboard.
    await act(async () =>
      channel.emit('.notification.created', {
        id: 7,
        app: 'maya-dms',
        type: 'document.shared',
        title: 'Documento compartido',
        created_at: '2026-10-14T10:00:00Z',
        read_at: null,
      }),
    )
    expect(bell.notifications.map((n) => n.id)).toEqual([7])
    expect(bell.unreadCount).toBe(1)

    // Se pierde la conexión: vuelve la consulta periódica.
    await act(async () => echo.stateListeners.forEach((cb) => cb({ previous: 'connected', current: 'unavailable' })))
    await act(async () => vi.advanceTimersByTime(POLL_MS))
    expect(fetchMock).toHaveBeenCalledTimes(6)
  })
})
