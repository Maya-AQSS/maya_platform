/**
 * Cross-subdomain session overrides — canal compartido entre todas las
 * apps del ecosistema mientras vivan bajo `*.localhost` (o el dominio
 * padre real en producción).
 *
 * Problema: cada subdominio (`dashboard.maya.test`,
 * `logs.maya.test`, …) tiene su PROPIO `localStorage`, así que el
 * cambio de idioma o de cualquier atributo editable desde `/profile` no
 * se ve en las otras apps abiertas. Como no hay backend que persista
 * (mock), la única fuente de verdad mientras dura la sesión SSO es el
 * navegador.
 *
 * Solución: una cookie pequeña con `Domain=localhost; Path=/; SameSite=Lax`
 * (visible para todos los subdominios), que sirve de canal one-way del
 * editor (dashboard /profile) al resto. Cada app, en sitios estratégicos
 * (mount, focus, refresh de token), la lee y si su contador `v` supera
 * al cacheado en `localStorage`, mergea los campos sobre el perfil
 * cacheado (`maya_user_profile`) y dispara `maya:locale-changed` si el
 * idioma cambió.
 *
 * Persistencia real (Odoo / DB) llegará cuando el endpoint write esté
 * implementado; este módulo desaparecerá cuando exista un canal backend
 * compartido (Redis con TTL, BroadcastChannel sobre dominio común, …).
 */
import { getDomain } from 'tldts'
import { publicConfig } from './runtimeConfig'
import { readCachedUserProfile, USER_PROFILE_STORAGE_KEY, writeCachedUserProfile } from './userProfileCache'

const COOKIE_NAME = 'maya_session_overrides'

/** localStorage key con el último `v` que esta app ya aplicó. */
export const OVERRIDES_VERSION_STORAGE_KEY = 'maya_user_profile_v'

const COOKIE_MAX_AGE_SECONDS = 24 * 60 * 60

const LOCALE_CHANGED_EVENT = 'maya:locale-changed'
const OVERRIDES_EVENT = 'maya:profile-overrides'

export interface SessionOverrides {
  /** Monotonic counter — incrementa con cada `writeOverrides`. */
  v: number
  /** BCP-47 short tag (`es`, `va`, `en`). Preferencia del usuario logado. */
  locale?: string
  /** `dark` | `light`. Preferencia del DISPOSITIVO — persiste entre logins. */
  theme?: string
  /**
   * Timestamp ms del último logout cross-app. Las pestañas que aún sigan
   * autenticadas lo detectan al ganar foco y cierran sesión también.
   */
  loggedOut?: number
  /** Otros campos editables desde /profile (employee_type, …). */
  [key: string]: unknown
}

/**
 * Campos que se preservan entre logins. El resto se elimina cuando se
 * llama a `clearSessionScopedOverrides()`.
 *
 * `theme` es preferencia del navegador/dispositivo (igual que
 * `prefers-color-scheme`): si el usuario A puso modo oscuro y luego B se
 * loguea, B verá oscuro hasta que él lo cambie. El locale, en cambio,
 * sí es preferencia del usuario y se reinicia (B se encuentra el suyo
 * cargado desde Odoo en el próximo `/me`).
 */
const PERSISTENT_KEYS: ReadonlyArray<keyof SessionOverrides> = ['theme']

/**
 * Sufijos PSL IP-based: servicios que mapean IPs a subdominios. Están en la
 * Public Suffix List, así que una cookie con `Domain=<suffix>` sería rechazada
 * por Chromium o, si la acepta, expondría el canal a millones de hosts ajenos
 * al ecosistema. En esos casos, si el host incluye un IPv4 dotted-quad debajo
 * del sufijo, scopeamos la cookie a `<ip>.<sufijo>` — lo comparten todos los
 * subdominios del ecosistema sin filtrar a `*.nip.io` ajenos.
 */
const IP_BASED_PSL_SUFFIXES = ['nip.io', 'sslip.io', 'xip.io'] as const

/**
 * Si `labels` contiene un IPv4 dotted-quad (4 octetos `0..255`) al final,
 * devuelve esos 4 labels unidos. Si no, `null`.
 */
function extractIPv4DottedQuad(labels: string[]): string | null {
  if (labels.length < 4) return null
  const last4 = labels.slice(-4)
  const isOctet = (s: string): boolean => {
    if (!/^\d{1,3}$/.test(s)) return false
    const n = Number.parseInt(s, 10)
    return n >= 0 && n <= 255
  }
  return last4.every(isOctet) ? last4.join('.') : null
}

/**
 * Calcula el atributo `Domain` para la cookie cross-subdomain a partir de
 * `window.location.hostname`. Función **input-driven**: no requiere variables
 * de entorno por defecto, se adapta sola a dev/staging/prod.
 *
 * Algoritmo:
 *   1. Override explícito vía la clave pública `SESSION_COOKIE_DOMAIN`
 *      (`MAYA_PUBLIC_` en /config.js o `VITE_`; escape hatch).
 *   2. PSL via `tldts.getDomain(host)` — devuelve el dominio registrable
 *      (el label inmediatamente debajo del sufijo público). Funciona con TLDs
 *      compuestos (`.co.uk`, `.com.mx`, …) sin parchear nada.
 *   3. Refinado IP-PSL: si el sufijo PSL es uno de `nip.io | sslip.io | xip.io`
 *      y entre `domain` y el sufijo hay un IPv4 dotted-quad, devolvemos
 *      `<ip>.<sufijo>` para compartir entre subdominios del mismo IP sin
 *      filtrar a otros IPs (`192.168.2.1.nip.io` ≠ `10.0.0.1.nip.io`).
 *   4. Fallback paranoico: si `tldts` no resuelve nada usable (host puro IP,
 *      `.localhost`, etc.) devolvemos el host concreto — la cookie será
 *      per-host (no rompe nada, solo desactiva el cross-subdomain).
 *
 * Defensa de seguridad: **nunca** se devuelve un sufijo PSL puro como
 * `Domain` — eso filtraría la cookie a todos los hosts bajo ese sufijo.
 */
function getCookieDomain(): string {
  if (typeof window === 'undefined') return 'maya.test'
  const host = window.location.hostname

  const fromConfig = publicConfig('SESSION_COOKIE_DOMAIN')
  if (fromConfig) return fromConfig

  const registrable = getDomain(host, { allowPrivateDomains: false })

  // Refinado IP-PSL: con `allowPrivateDomains: false`, tldts colapsa hosts bajo
  // wildcards DNS basados en IP (nip.io/sslip.io/xip.io) a ese sufijo como
  // dominio REGISTRABLE (p.ej. `nip.io`), no como `suffix` (que es `io`). Ese
  // registrable es a su vez un public suffix: usarlo como `Domain` haría que el
  // navegador RECHAZARA la cookie → no se compartiría entre subdominios. Por eso
  // comparamos contra `registrable` (no contra el public suffix) y reconstruimos
  // `<ip>.<registrable>` para scopear la cookie al IP compartido por las apps del
  // slot sin filtrarla a otros IPs (`192.168.2.1.nip.io` ≠ `10.0.0.1.nip.io`).
  if (registrable && IP_BASED_PSL_SUFFIXES.includes(registrable as (typeof IP_BASED_PSL_SUFFIXES)[number])) {
    const beforeSuffix = host.slice(0, host.length - registrable.length - 1) // sin el '.<registrable>'
    const ip = extractIPv4DottedQuad(beforeSuffix.split('.'))
    if (ip) return `${ip}.${registrable}`
    return host
  }

  if (registrable) return registrable

  return host
}

/**
 * Lee `document.cookie` y devuelve el valor de la cookie `name`.
 *
 * Si hay varias cookies con el mismo nombre — escenario habitual durante una
 * migración de scoping (p.ej., una cookie stale per-host de versiones previas
 * conviviendo con la nueva cookie compartida) — el navegador devuelve todas en
 * `document.cookie` ordenadas por especificidad (la más específica primero).
 * Eso provocaba "doble cambio": el writer escribía la nueva con `v` mayor pero
 * el reader devolvía la stale más específica.
 *
 * Para tolerar ese caso, parseamos todas las coincidencias y devolvemos la que
 * tenga mayor `v`. Si ninguna parsea como JSON con `v` numérico, devolvemos la
 * primera (compatibilidad con cookies no-JSON).
 */
function readCookieRaw(name: string): string | null {
  if (typeof document === 'undefined') return null
  const cookies = document.cookie.split(';')
  const prefix = `${name}=`
  const matches: string[] = []
  for (const raw of cookies) {
    const c = raw.trim()
    if (c.startsWith(prefix)) {
      matches.push(decodeURIComponent(c.slice(prefix.length)))
    }
  }
  if (matches.length === 0) return null
  if (matches.length === 1) return matches[0]
  let bestValue = matches[0]
  let bestV = -1
  for (const candidate of matches) {
    try {
      const parsed = JSON.parse(candidate) as unknown
      const v = (parsed && typeof parsed === 'object' && 'v' in parsed && typeof (parsed as { v?: unknown }).v === 'number')
        ? (parsed as { v: number }).v
        : -1
      if (v > bestV) {
        bestV = v
        bestValue = candidate
      }
    } catch {
      /* candidato no-JSON — ignorar para elegir por `v` */
    }
  }
  return bestValue
}

function isSecureContext(): boolean {
  if (typeof window === 'undefined') return false
  return window.location.protocol === 'https:'
}

function writeCookieRaw(name: string, value: string, maxAgeSeconds: number): void {
  if (typeof document === 'undefined') return
  const domain = getCookieDomain()
  // Limpieza defensiva: si existe una cookie stale del mismo nombre scopeada al
  // host concreto (versiones previas del código), la borramos antes de escribir
  // la nueva compartida — así no convive una variante per-host (más específica)
  // que sombree a la nueva en `document.cookie`.
  const host = typeof window !== 'undefined' ? window.location.hostname : ''
  if (host && host !== domain) {
    const secureSuffix = isSecureContext() ? '; Secure' : ''
    document.cookie = `${name}=; Path=/; Domain=${host}; Max-Age=0; SameSite=Lax${secureSuffix}`
    // Algunos navegadores también guardan una variante sin atributo Domain
    // (cookie host-only auténtica). La borramos también.
    document.cookie = `${name}=; Path=/; Max-Age=0; SameSite=Lax${secureSuffix}`
  }
  const parts = [
    `${name}=${encodeURIComponent(value)}`,
    `Path=/`,
    `Domain=${domain}`,
    `Max-Age=${maxAgeSeconds}`,
    `SameSite=Lax`,
  ]
  if (isSecureContext()) parts.push('Secure')
  document.cookie = parts.join('; ')
}

function deleteCookieRaw(name: string): void {
  if (typeof document === 'undefined') return
  const domain = getCookieDomain()
  const secureSuffix = isSecureContext() ? '; Secure' : ''
  document.cookie = `${name}=; Path=/; Domain=${domain}; Max-Age=0; SameSite=Lax${secureSuffix}`
}

export function readOverrides(): SessionOverrides | null {
  const raw = readCookieRaw(COOKIE_NAME)
  if (!raw) return null
  try {
    const parsed = JSON.parse(raw) as unknown
    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed) && typeof (parsed as { v?: unknown }).v === 'number') {
      return parsed as SessionOverrides
    }
  } catch {
    /* cookie corrupta — la tratamos como vacía */
  }
  return null
}

/**
 * Persiste un patch en la cookie cross-subdomain. Incrementa `v` para que
 * las demás apps detecten el cambio al consultar la cookie.
 */
export function writeOverrides(patch: Omit<Partial<SessionOverrides>, 'v'>): SessionOverrides {
  const current = readOverrides() ?? { v: 0 }
  const next: SessionOverrides = {
    ...current,
    ...patch,
    v: current.v + 1,
  }
  writeCookieRaw(COOKIE_NAME, JSON.stringify(next), COOKIE_MAX_AGE_SECONDS)
  // Marca esta app como «ya aplicó» la versión que acaba de escribir para
  // que el listener local no haga merge redundante.
  try {
    localStorage.setItem(OVERRIDES_VERSION_STORAGE_KEY, String(next.v))
  } catch {
    /* localStorage lleno o no disponible — no crítico. */
  }
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new Event(OVERRIDES_EVENT))
  }
  return next
}

/**
 * Limpia los overrides ligados a la sesión del usuario (locale, loggedOut,
 * campos de empleado editables, …) PRESERVANDO los que son preferencia
 * del dispositivo (`theme`).
 *
 * Se invoca desde `AuthContext.logout`. Si tras filtrar no queda ningún
 * campo persistente, borra la cookie entera.
 */
export function clearSessionScopedOverrides(): void {
  const current = readOverrides()
  if (current) {
    const persistent: Partial<SessionOverrides> = {}
    for (const key of PERSISTENT_KEYS) {
      const value = current[key]
      if (value !== undefined) persistent[key] = value as never
    }

    if (Object.keys(persistent).length > 0) {
      const next: SessionOverrides = { ...persistent, v: current.v + 1 }
      writeCookieRaw(COOKIE_NAME, JSON.stringify(next), COOKIE_MAX_AGE_SECONDS)
    } else {
      deleteCookieRaw(COOKIE_NAME)
    }
  } else {
    deleteCookieRaw(COOKIE_NAME)
  }

  try {
    localStorage.removeItem(OVERRIDES_VERSION_STORAGE_KEY)
  } catch {
    /* idem */
  }
}

/**
 * Alias retro-compatible. Mantenido para los callers existentes; usa
 * {@see clearSessionScopedOverrides}.
 */
export function clearOverrides(): void {
  clearSessionScopedOverrides()
}

/**
 * Marca la cookie con `loggedOut: Date.now()` para que las otras apps
 * abiertas (que recuperen foco) cierren sesión también. Debe llamarse
 * ANTES de `keycloak.logout()` (que redirige y mata el JS de esta tab)
 * para que el evento `maya:profile-overrides` se dispare.
 */
export function signalLogoutAcrossApps(): void {
  writeOverrides({ loggedOut: Date.now() })
}

/** Lee el timestamp del último logout cross-app o `0` si no hay. */
export function readLogoutSignal(): number {
  const overrides = readOverrides()
  if (!overrides) return 0
  const value = overrides.loggedOut
  return typeof value === 'number' && Number.isFinite(value) ? value : 0
}

function getAppliedVersion(): number {
  try {
    const raw = localStorage.getItem(OVERRIDES_VERSION_STORAGE_KEY)
    if (!raw) return 0
    const n = Number.parseInt(raw, 10)
    return Number.isFinite(n) ? n : 0
  } catch {
    return 0
  }
}

function setAppliedVersion(v: number): void {
  try {
    localStorage.setItem(OVERRIDES_VERSION_STORAGE_KEY, String(v))
  } catch {
    /* no-op */
  }
}

/**
 * Lee la cookie de overrides y, si es más reciente que la versión que esta
 * app ya aplicó, mergea sus campos en `maya_user_profile` y notifica al
 * sistema de i18n vía `maya:locale-changed` (consumido por createI18n).
 *
 * Devuelve `true` si aplicó cambios.
 */
export function applyOverridesFromCookie(): boolean {
  const overrides = readOverrides()
  if (!overrides) return false

  const applied = getAppliedVersion()
  if (overrides.v <= applied) return false

  const profile = readCachedUserProfile()
  if (profile) {
    // Filtra los campos de control que no son atributos del perfil
    // (`v`, `loggedOut`, `theme`) — solo se mergean datos de empleado.
    const { v: _v, loggedOut: _l, theme: _t, ...profileFields } = overrides
    writeCachedUserProfile({ ...profile, ...profileFields })
  }

  setAppliedVersion(overrides.v)

  if (typeof window !== 'undefined' && typeof overrides.locale === 'string') {
    window.dispatchEvent(
      new CustomEvent(LOCALE_CHANGED_EVENT, { detail: overrides.locale }),
    )
  }
  return true
}

/**
 * Hook idempotente que conecta esta app al canal cross-subdomain.
 * Llamarlo una sola vez (en `UserProfileProvider`) tras el primer fetch
 * de `/me`. Devuelve la función de limpieza para `useEffect`.
 */
export function installOverridesListeners(): () => void {
  if (typeof window === 'undefined') {
    return () => {
      /* no-op SSR */
    }
  }

  // Primera pasada: si ya hay overrides cuando montamos, aplicarlos antes
  // de que el usuario interactúe.
  applyOverridesFromCookie()

  const onVisibility = (): void => {
    if (document.visibilityState === 'visible') {
      applyOverridesFromCookie()
    }
  }
  const onOverrides = (): void => {
    applyOverridesFromCookie()
  }
  // Si otra pestaña de ESTA app cambia `maya_user_profile_v` directamente,
  // re-leemos.
  const onStorage = (e: StorageEvent): void => {
    if (e.key === OVERRIDES_VERSION_STORAGE_KEY || e.key === USER_PROFILE_STORAGE_KEY) {
      applyOverridesFromCookie()
    }
  }

  document.addEventListener('visibilitychange', onVisibility)
  window.addEventListener(OVERRIDES_EVENT, onOverrides)
  window.addEventListener('storage', onStorage)

  return () => {
    document.removeEventListener('visibilitychange', onVisibility)
    window.removeEventListener(OVERRIDES_EVENT, onOverrides)
    window.removeEventListener('storage', onStorage)
  }
}
