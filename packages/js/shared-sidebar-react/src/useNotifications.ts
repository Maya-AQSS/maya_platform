import { useCallback, useEffect, useRef, useState } from 'react'
import { useAuth } from '@ceedcv-maya/shared-auth-react'
import { useNotificationChannel } from './NotificationProvider'

export type SharedNotificationSeverity = 'critical' | 'high' | 'medium' | 'low' | 'info'

export interface SharedNotification {
  id: number
  app: string
  type: string
  title: string
  body: string
  /** Clave i18n del título (`notifications.<type>.title`) para re-resolver en cliente. */
  title_key: string | null
  /** Clave i18n del cuerpo (`notifications.<type>.body`) para re-resolver en cliente. */
  body_key: string | null
  /** Params de interpolación de las claves i18n. */
  params: Record<string, unknown>
  severity: SharedNotificationSeverity
  url: string | null
  /**
   * Frontend que hospeda el recurso de `url` (token peerOrigin: `dms`, `logs`,
   * `audit`, `dashboard`…). Permite cross-navegar al recurso en SU app cuando se
   * pulsa la notificación desde otra app. Null → `url` relativa al app actual.
   */
  target_app: string | null
  read_at: string | null
  created_at: string
  metadata?: Record<string, unknown>
}

const SEVERITIES: SharedNotificationSeverity[] = ['critical', 'high', 'medium', 'low', 'info']

function parseSeverity(value: unknown): SharedNotificationSeverity {
  return SEVERITIES.includes(value as SharedNotificationSeverity)
    ? (value as SharedNotificationSeverity)
    : 'info'
}

export interface UseNotificationsOptions {
  dashboardApiUrl: string
  /** Poll interval in ms for fresh notifications. Set 0 to disable. */
  pollMs?: number
  token?: string | null
  userSub?: string | null
}

function useAuthSafe() {
  try {
    return useAuth()
  } catch {
    return null
  }
}

function useNotificationChannelSafe() {
  try {
    return useNotificationChannel()
  } catch {
    return { channel: null, wsConnected: false }
  }
}

/** Normaliza una fila/payload de notificación, preservando claves i18n + params. */
function parseNotification(row: Record<string, unknown>): SharedNotification {
  return {
    id: Number(row.id),
    app: String(row.app ?? ''),
    type: String(row.type ?? ''),
    title: String(row.title ?? ''),
    body: row.body != null ? String(row.body) : '',
    title_key: row.title_key != null ? String(row.title_key) : null,
    body_key: row.body_key != null ? String(row.body_key) : null,
    params:
      row.params != null && typeof row.params === 'object'
        ? (row.params as Record<string, unknown>)
        : {},
    severity: parseSeverity(row.severity),
    url: row.url != null ? String(row.url) : null,
    target_app: row.target_app != null ? String(row.target_app) : null,
    read_at: row.read_at != null ? String(row.read_at) : null,
    created_at: String(row.created_at ?? ''),
    metadata:
      row.metadata != null && typeof row.metadata === 'object'
        ? (row.metadata as Record<string, unknown>)
        : undefined,
  }
}

/** Lista paginada del dashboard: `{ data: Notification[], ...meta }`. */
function parseNotificationList(payload: unknown): SharedNotification[] {
  if (!payload || typeof payload !== 'object') return []
  const root = payload as Record<string, unknown>
  const items = root.data
  if (!Array.isArray(items)) return []
  return items
    .filter((row): row is Record<string, unknown> => row != null && typeof row === 'object')
    .map((row) => parseNotification(row))
    .filter((n) => Number.isFinite(n.id))
}

/** Contador: envelope `{ data: { unread: number } }` (RespondsWithEnvelope). */
function parseUnreadCount(payload: unknown): number {
  if (!payload || typeof payload !== 'object') return 0
  const root = payload as Record<string, unknown>
  const nested = root.data
  if (nested != null && typeof nested === 'object') {
    const unread = (nested as Record<string, unknown>).unread
    if (unread != null) return Number(unread)
  }
  if (root.unread != null) return Number(root.unread)
  return 0
}

/**
 * Fetches the latest notifications for the current user from the maya_dashboard
 * backend. The list and the unread count are exposed together so a <Bell/>
 * component can render a badge without a second round-trip.
 *
 * Si `NotificationProvider` está en el árbol y el Reverb del dashboard está
 * configurado (`DASHBOARD_REVERB_*` en `/config.js` o `VITE_DASHBOARD_REVERB_*`;
 * en el propio dashboard, su `REVERB_*`), escucha el canal WebSocket
 * `private-notifications.{userId}` y, confirmada la suscripción, deja de hacer
 * polling. La carga inicial siempre usa HTTP para hidratar la lista.
 * El fallback a polling HTTP es automático cuando no hay WebSocket disponible.
 *
 * La interfaz externa del hook NO cambia (retrocompatibilidad garantizada).
 */
export function useNotifications({
  dashboardApiUrl,
  pollMs = 60_000,
  token: tokenOverride,
}: UseNotificationsOptions) {
  const auth = useAuthSafe()
  const token = tokenOverride ?? auth?.token ?? null

  const { channel, wsConnected } = useNotificationChannelSafe()

  const [notifications, setNotifications] = useState<SharedNotification[]>([])
  const [unreadCount, setUnreadCount] = useState(0)

  const fetchRef = useRef<() => Promise<void>>(async () => {})

  const refetch = useCallback(async () => {
    if (!token || !dashboardApiUrl) {
      setNotifications([])
      setUnreadCount(0)
      return
    }
    const base = dashboardApiUrl.replace(/\/$/, '')
    try {
      const [listResp, countResp] = await Promise.all([
        fetch(`${base}/api/v1/notifications?per_page=10`, {
          headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
        }),
        fetch(`${base}/api/v1/notifications/unread-count`, {
          headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
        }),
      ])
      if (listResp.ok) {
        const data = await listResp.json()
        setNotifications(parseNotificationList(data))
      }
      if (countResp.ok) {
        const data = await countResp.json()
        setUnreadCount(parseUnreadCount(data))
      }
    } catch {
      /* network error — keep previous state */
    }
  }, [token, dashboardApiUrl])

  fetchRef.current = refetch

  // Carga inicial siempre por HTTP (hidrata la lista antes de que llegue el primer evento WS).
  useEffect(() => {
    refetch()
  }, [refetch])

  // Polling HTTP — solo activo cuando NO hay WebSocket disponible.
  useEffect(() => {
    if (wsConnected) return
    if (!pollMs || pollMs <= 0) return
    const id = setInterval(() => { fetchRef.current() }, pollMs)
    return () => clearInterval(id)
  }, [pollMs, wsConnected])

  // WebSocket — escucha eventos del canal compartido por NotificationProvider.
  useEffect(() => {
    if (!channel) return

    // Evento de nueva notificación (nombre del evento broadcast en Laravel)
    channel.listen('.notification.created', (data: unknown) => {
      const envelope = data as { notification?: unknown } | Record<string, unknown>
      const raw =
        envelope != null && typeof envelope === 'object' && 'notification' in envelope
          ? (envelope as { notification: unknown }).notification
          : envelope
      if (raw == null || typeof raw !== 'object') return
      const n = parseNotification(raw as Record<string, unknown>)
      if (Number.isFinite(n.id)) {
        setNotifications((prev) => {
          if (prev.some((existing) => existing.id === n.id)) return prev
          return [n, ...prev]
        })
        if (!n.read_at) {
          setUnreadCount((c) => c + 1)
        }
      }
    })

    // Evento de notificación marcada como leída
    channel.listen('.notification.read', (data: unknown) => {
      const payload = data as { id?: number }
      if (payload.id != null) {
        setNotifications((prev) =>
          prev.map((n) =>
            n.id === payload.id ? { ...n, read_at: new Date().toISOString() } : n,
          ),
        )
        setUnreadCount((c) => Math.max(0, c - 1))
      }
    })

    // Evento de "marcar todo como leído"
    channel.listen('.notification.all-read', () => {
      const now = new Date().toISOString()
      setNotifications((prev) => prev.map((n) => (n.read_at ? n : { ...n, read_at: now })))
      setUnreadCount(0)
    })

    return () => {
      channel.stopListening('.notification.created')
      channel.stopListening('.notification.read')
      channel.stopListening('.notification.all-read')
    }
  }, [channel])

  const markRead = useCallback(
    async (id: number) => {
      if (!token || !dashboardApiUrl) return
      const base = dashboardApiUrl.replace(/\/$/, '')
      try {
        const resp = await fetch(`${base}/api/v1/notifications/${id}/read`, {
          method: 'POST',
          headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
        })
        if (resp.ok) {
          setNotifications((prev) =>
            prev.map((n) => (n.id === id ? { ...n, read_at: new Date().toISOString() } : n)),
          )
          setUnreadCount((c) => Math.max(0, c - 1))
        }
      } catch {
        /* no-op */
      }
    },
    [token, dashboardApiUrl],
  )

  const markAllRead = useCallback(async () => {
    if (!token || !dashboardApiUrl) return
    const base = dashboardApiUrl.replace(/\/$/, '')
    try {
      const resp = await fetch(`${base}/api/v1/notifications/mark-all-read`, {
        method: 'POST',
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      })
      if (resp.ok) {
        const now = new Date().toISOString()
        setNotifications((prev) => prev.map((n) => (n.read_at ? n : { ...n, read_at: now })))
        setUnreadCount(0)
      }
    } catch {
      /* no-op */
    }
  }, [token, dashboardApiUrl])

  return { notifications, unreadCount, refetch, markRead, markAllRead }
}
