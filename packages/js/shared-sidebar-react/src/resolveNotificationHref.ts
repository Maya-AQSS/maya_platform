/**
 * Resuelve el destino de click de una notificación de forma universal (válida
 * desde CUALQUIER app del ecosistema, no solo el dashboard).
 *
 * El backend guarda `url` relativa al frontend de `target_app` (token peerOrigin:
 * `dms`, `logs`, `audit`, `dashboard`…). El problema que resuelve: si estás en
 * dms y pulsas una notificación cuyo recurso vive en otra app, la `url` relativa
 * se resolvía contra el host de dms → caías en el panel de dms. Aquí derivamos el
 * origen del app destino desde el hostname actual y construimos una URL absoluta,
 * de modo que el recurso se abre en SU app (cross-app), igual que ya hacía el
 * resolver del dashboard (`resolveResourceTarget`).
 */

/** Posición del navegador necesaria para derivar orígenes hermanos. */
export interface BrowserLocation {
  protocol: string
  hostname: string
}

/**
 * Convención Maya: cada servicio se sirve en
 *   `<slot-prefix>-<service-name>.<domain-suffix>`
 *
 * Deriva el origen de un servicio hermano (mismo slot/dominio) desde el hostname
 * actual. Self-target → mismo origen; foreign-target → origen del otro app.
 * Espejo de `lib/peerService.peerOrigin` de los frontends, pero puro (inyecta
 * `location`) para ser testeable y reutilizable dentro del paquete compartido.
 */
export function peerOrigin(targetService: string, loc: BrowserLocation): string {
  const { protocol } = loc
  // En producción (`api.<app>.<dominio>`) el navegador nunca corre en el host api,
  // pero por robustez se ignora esa etiqueta: las SPAs siempre son `<app>.<dominio>`.
  const hostname = loc.hostname.replace(/^api\./i, '')

  // Fallback para entornos sin sub-dominio (ej. 'localhost').
  const firstDot = hostname.indexOf('.')
  if (firstDot === -1) return `${protocol}//${hostname}`

  const firstSegment = hostname.substring(0, firstDot) // 'desarrollo-ceedcv-dms'
  const domainSuffix = hostname.substring(firstDot) // '.192.168.2.1.nip.io'

  // El service-name actual es el último token tras el último `-`; lo previo es el
  // slot-prefix (puede ser vacío si no hay prefijo).
  const lastDash = firstSegment.lastIndexOf('-')
  const slotPrefix = lastDash !== -1 ? firstSegment.substring(0, lastDash + 1) : ''

  return `${protocol}//${slotPrefix}${targetService}${domainSuffix}`
}

/**
 * Calcula el href final de click-through de una notificación.
 *
 * Reglas:
 *  - sin `url` → `null` (no navegable).
 *  - `url` absoluta (`http(s)://…`) → se usa tal cual.
 *  - `target_app` vacío/null → relativa al app actual (comportamiento legacy).
 *  - `target_app` presente → absoluta vía `peerOrigin(target_app)`. Si coincide
 *    con el app actual, `peerOrigin` reconstruye el mismo origen (recarga al
 *    recurso correcto); si es otra app, cross-navega a su origen.
 */
export function resolveNotificationHref(
  url: string | null,
  targetApp: string | null | undefined,
  loc: BrowserLocation,
): string | null {
  if (!url) return null
  if (/^https?:\/\//i.test(url)) return url
  if (!targetApp) return url
  const base = peerOrigin(targetApp, loc)
  return `${base}${url.startsWith('/') ? '' : '/'}${url}`
}
