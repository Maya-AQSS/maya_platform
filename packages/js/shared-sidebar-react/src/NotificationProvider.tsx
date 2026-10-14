import {
  createContext,
  useContext,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { publicConfig, resolveServiceUrl, useAuth } from '@ceedcv-maya/shared-auth-react'

// laravel-echo + pusher-js — importaciones dinámicas para no romper SSR/envs
// que no tengan las variables de entorno configuradas.
type EchoChannel = {
  listen: (event: string, cb: (data: unknown) => void) => EchoChannel
  stopListening: (event: string) => EchoChannel
  /** Suscripción confirmada por Reverb (laravel-echo ≥ 1.11). */
  subscribed?: (cb: () => void) => EchoChannel
  /** Error de suscripción (p. ej. autorización del canal rechazada). */
  error?: (cb: (error: unknown) => void) => EchoChannel
}

type LaravelEchoInstance = {
  private: (channel: string) => EchoChannel
  disconnect: () => void
  connector?: {
    pusher?: {
      connection?: {
        bind: (event: string, cb: (states: { previous: string; current: string }) => void) => void
      }
    }
  }
}

/** Constructor de Laravel Echo (peer dep dinámica). */
type EchoConstructor = new (options: Record<string, unknown>) => LaravelEchoInstance
/** Clase Pusher (peer dep dinámica); solo se asigna a `window.Pusher`. */
type PusherClass = new (...args: never[]) => unknown

export interface NotificationReverbConfig {
  host: string
  key: string
  port: number
  scheme: string
  /** Endpoint que autoriza el canal privado (`…/api/v1/broadcasting/auth` de dashboard-api). */
  authEndpoint: string
}

/**
 * Ruta de autorización de canales de dashboard-api: `Broadcast::routes()` con
 * prefijo `api/v1` (`RegistersFdwBootstrap` de shared-platform-laravel).
 */
const DASHBOARD_BROADCASTING_AUTH_PATH = '/api/v1/broadcasting/auth'

function parseUrl(url: string | undefined): URL | null {
  if (!url) return null
  try {
    return new URL(url)
  } catch {
    return null // relativa o mal formada
  }
}

/**
 * true si la app que monta el provider es el propio dashboard: su API
 * (`API_URL`) está en el mismo origen que dashboard-api (`DASHBOARD_API_URL`).
 */
function isDashboardApp(): boolean {
  const own = parseUrl(publicConfig('API_URL'))
  const dashboard = parseUrl(publicConfig('DASHBOARD_API_URL'))
  return own !== null && dashboard !== null && own.origin === dashboard.origin
}

/**
 * Reverb propio de la app (`REVERB_*`). Solo lo usa el dashboard, que es quien
 * emite las notificaciones: en él es el mismo servidor que el del dashboard.
 * Valores por defecto de antes: puerto 8080, http y `<API_URL>/broadcasting/auth`.
 */
function ownReverbConfig(): NotificationReverbConfig | null {
  const host = publicConfig('REVERB_HOST')
  const key = publicConfig('REVERB_APP_KEY')
  if (!host || !key) return null
  const port = Number(publicConfig('REVERB_PORT') ?? 8080)
  const scheme = publicConfig('REVERB_SCHEME') ?? 'http'
  const apiUrl = publicConfig('API_URL')
  const authEndpoint =
    publicConfig('REVERB_AUTH_ENDPOINT') ??
    (apiUrl ? `${apiUrl.replace(/\/+$/, '')}/broadcasting/auth` : '/api/broadcasting/auth')
  return { host, key, port, scheme, authEndpoint }
}

/**
 * Reverb del dashboard (D37): `DASHBOARD_REVERB_APP_KEY` (obligatoria),
 * `DASHBOARD_REVERB_HOST` (si falta, el host de `DASHBOARD_API_URL`),
 * `DASHBOARD_REVERB_PORT` (443; 80 con http) y `DASHBOARD_REVERB_SCHEME`
 * (https). El canal se autoriza en `<DASHBOARD_API_URL>/api/v1/broadcasting/auth`
 * con el token de la SPA, que lleva la audiencia `maya-dashboard`.
 *
 * `DASHBOARD_API_URL` se resuelve como el `dashboardApiUrl` de las apps
 * (`resolveServiceUrl(…, 'dashboard-api')`): si falta, se deriva del host actual.
 */
function dashboardReverbConfig(): NotificationReverbConfig | null {
  const key = publicConfig('DASHBOARD_REVERB_APP_KEY')
  if (!key) return null
  const apiUrl = resolveServiceUrl(publicConfig('DASHBOARD_API_URL'), 'dashboard-api').replace(/\/+$/, '')
  const host = publicConfig('DASHBOARD_REVERB_HOST') ?? parseUrl(apiUrl)?.hostname
  if (!host) return null
  const scheme = publicConfig('DASHBOARD_REVERB_SCHEME') ?? 'https'
  const port =
    Number.parseInt(publicConfig('DASHBOARD_REVERB_PORT') ?? '', 10) || (scheme === 'http' ? 80 : 443)
  return { host, key, port, scheme, authEndpoint: `${apiUrl}${DASHBOARD_BROADCASTING_AUTH_PATH}` }
}

/**
 * Configuración de Reverb de la barra lateral (D37). Las notificaciones las
 * emite el dashboard por SU Reverb, así que todas las apps se conectan a él
 * (`DASHBOARD_REVERB_*`) y autorizan el canal en dashboard-api. El dashboard
 * usa su propio Reverb (`REVERB_*`, el mismo servidor) y, si le falta, también
 * `DASHBOARD_REVERB_*`.
 *
 * Fuente: la configuración pública en ejecución (`/config.js`, `MAYA_PUBLIC_*`)
 * y, si falta, `VITE_*` (`publicConfig` de shared-auth-react). Una app que no
 * es el dashboard nunca usa su propio Reverb (por él no llega ninguna
 * notificación). Sin configuración devuelve null y el provider se queda en la
 * consulta periódica.
 */
export function notificationReverbConfig(): NotificationReverbConfig | null {
  if (isDashboardApp()) return ownReverbConfig() ?? dashboardReverbConfig()
  return dashboardReverbConfig()
}

/**
 * Carga de laravel-echo y pusher-js. `new Function` escapa el análisis estático
 * de Vite: son dependencias que solo hacen falta cuando Reverb está activo.
 * Los tests sustituyen `load`.
 *
 * @internal
 */
export const notificationRealtimeModules = {
  async load(): Promise<{ Echo: EchoConstructor; Pusher: PusherClass }> {
    const dynImport = new Function('s', 'return import(s)') as <T>(s: string) => Promise<{ default: T }>
    const [{ default: Echo }, { default: Pusher }] = await Promise.all([
      dynImport<EchoConstructor>('laravel-echo'),
      dynImport<PusherClass>('pusher-js'),
    ])
    return { Echo, Pusher }
  },
}

// ────────────────────────────────────────────────────────────────────────────
// Context
// ────────────────────────────────────────────────────────────────────────────

export interface NotificationChannelContextValue {
  /** Canal privado de notificaciones del usuario actual, o null si no disponible. */
  channel: EchoChannel | null
  /** true si la conexión WebSocket está activa. */
  wsConnected: boolean
}

const NotificationChannelContext = createContext<NotificationChannelContextValue>({
  channel: null,
  wsConnected: false,
})

export function useNotificationChannel(): NotificationChannelContextValue {
  return useContext(NotificationChannelContext)
}

// ────────────────────────────────────────────────────────────────────────────
// Provider
// ────────────────────────────────────────────────────────────────────────────

export interface NotificationProviderProps {
  children: ReactNode
}

/**
 * Provider que gestiona UNA SOLA conexión WebSocket por aplicación.
 * Todos los `useNotifications` en la misma app comparten el socket via context.
 *
 * Se conecta al Reverb del DASHBOARD, que es quien emite las notificaciones
 * (D37). Configuración pública (ver {@link notificationReverbConfig}):
 * `/config.js` (`MAYA_PUBLIC_*`) o, en Vite, `VITE_*`:
 *   DASHBOARD_REVERB_APP_KEY — clave pública del Reverb del dashboard (sin ella: polling)
 *   DASHBOARD_REVERB_HOST    — host (default: el de DASHBOARD_API_URL, p. ej. "api.dashboard.ceedcv")
 *   DASHBOARD_REVERB_PORT    — puerto (default 443; 80 con http)
 *   DASHBOARD_REVERB_SCHEME  — http o https (default "https")
 *   DASHBOARD_API_URL        — dashboard-api; el canal se autoriza en `<DASHBOARD_API_URL>/api/v1/broadcasting/auth`
 * En el propio dashboard se usa su `REVERB_*` (el mismo servidor).
 *
 * Si la configuración no está o la conexión falla, el provider no expone canal
 * (channel = null). `wsConnected` solo pasa a true cuando Reverb confirma la
 * suscripción, y vuelve a false si la suscripción falla o se pierde la conexión:
 * mientras tanto `useNotifications` sigue con el polling HTTP.
 *
 * El canal suscrito es `private-notifications.{userId}` usando el `sub` del
 * usuario autenticado via `useAuth()`; dashboard-api lo autoriza si coincide
 * con el `sub` del token.
 */
export function NotificationProvider({ children }: NotificationProviderProps) {
  let auth: ReturnType<typeof useAuth> | null = null
  try {
    // eslint-disable-next-line react-hooks/rules-of-hooks
    auth = useAuth()
  } catch {
    // Si no hay AuthProvider en el árbol, el provider funciona sin WS.
  }

  const token = auth?.token ?? null
  const userId = auth?.user?.sub ?? null

  const [channel, setChannel] = useState<EchoChannel | null>(null)
  const [wsConnected, setWsConnected] = useState(false)
  const echoRef = useRef<LaravelEchoInstance | null>(null)

  useEffect(() => {
    const config = notificationReverbConfig()
    if (!config || !token || !userId) {
      // Sin configuración o sin auth → no conectar
      return
    }

    let echo: LaravelEchoInstance | null = null
    let cancelled = false

    async function connect() {
      try {
        const { Echo, Pusher } = await notificationRealtimeModules.load()

        if (cancelled) return

        // Asignar Pusher al scope global — requerido por Laravel Echo con driver pusher.
        // Cast vía unknown: es una asignación global en runtime, el tipo es irrelevante
        // y evita el choque con la augmentación ambiente de `window.Pusher` (pusher-js).
        ;(window as unknown as { Pusher: unknown }).Pusher = Pusher

        const echoInstance = new Echo({
          broadcaster: 'reverb',
          key: config!.key,
          wsHost: config!.host,
          wsPort: config!.port,
          wssPort: config!.port,
          forceTLS: config!.scheme === 'https',
          enabledTransports: ['ws', 'wss'],
          authEndpoint: config!.authEndpoint,
          auth: {
            headers: {
              Authorization: `Bearer ${token}`,
              Accept: 'application/json',
            },
          },
        }) as unknown as LaravelEchoInstance

        if (cancelled) {
          echoInstance.disconnect()
          return
        }

        echoRef.current = echoInstance

        const privateChannel = echoInstance.private(`notifications.${userId}`)
        setChannel(privateChannel)
        // El polling solo se apaga cuando Reverb confirma la suscripción: si la
        // autorización falla o se cae la conexión, sigue (o vuelve) el polling.
        if (typeof privateChannel.subscribed === 'function') {
          privateChannel.subscribed(() => {
            if (!cancelled) setWsConnected(true)
          })
          privateChannel.error?.(() => {
            if (!cancelled) setWsConnected(false)
          })
          echoInstance.connector?.pusher?.connection?.bind('state_change', ({ current }) => {
            if (!cancelled && current !== 'connected') setWsConnected(false)
          })
        } else {
          setWsConnected(true)
        }
        echo = echoInstance
      } catch {
        // Fallo de conexión → modo polling (channel permanece null)
        setChannel(null)
        setWsConnected(false)
      }
    }

    void connect()

    return () => {
      cancelled = true
      if (echo) {
        echo.disconnect()
      } else if (echoRef.current) {
        echoRef.current.disconnect()
      }
      echoRef.current = null
      setChannel(null)
      setWsConnected(false)
    }
  }, [token, userId])

  return (
    <NotificationChannelContext.Provider value={{ channel, wsConnected }}>
      {children}
    </NotificationChannelContext.Provider>
  )
}
