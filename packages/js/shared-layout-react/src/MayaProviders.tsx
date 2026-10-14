import { useMemo, useEffect } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter } from 'react-router-dom'
import { withTranslation } from 'react-i18next'
import { AuthProvider } from '@ceedcv-maya/shared-auth-react'
import { UserProfileProvider } from '@ceedcv-maya/shared-profile-react'
import { NotificationProvider } from '@ceedcv-maya/shared-sidebar-react'
import { bootstrapRealtime } from '@ceedcv-maya/shared-realtime-react'
import { AuthLoadingScreen, AppErrorFallback, ErrorBoundary, ToastProvider } from '@ceedcv-maya/shared-ui-react'
import type { MayaProvidersProps } from './appShell.types'

// ErrorBoundary expects the withTranslation HOC props (t, i18n, tReady);
// wrap it once so MayaProviders can render it with just children/fallback.
const TranslatedErrorBoundary = withTranslation('common')(ErrorBoundary)

/**
 * Unified provider stack for Maya apps.
 *
 * Replaces the boilerplate in each app's `main.tsx`:
 * - QueryClientProvider (configurable staleTime/retry)
 * - AuthProvider (Keycloak)
 * - BrowserRouter
 * - UserProfileProvider (requires fetchProfile)
 * - NotificationProvider
 * - Optional ToastProvider
 * - Optional ErrorBoundary with AppErrorFallback
 * - Optional extra app-specific providers
 * - bootstrapRealtime side-effect on mount
 * - unhandledrejection logging on mount
 */
export function MayaProviders({
  authService,
  serviceSlug,
  fetchProfile,
  children,
  extraProviders: ExtraProviders,
  withToasts = false,
  withErrorBoundary = true,
  queryClientOptions,
  errorFallbackProps,
}: MayaProvidersProps) {
  const queryClient = useMemo(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            staleTime: queryClientOptions?.staleTime ?? 60_000,
            retry: queryClientOptions?.retry ?? 1,
          },
        },
      }),
    // QueryClient is intentionally created once — options are read at mount only.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [],
  )

  // bootstrapRealtime: wires up Echo for the service slug.
  // No-ops when REVERB_APP_KEY is absent (/config.js or VITE_REVERB_APP_KEY).
  useEffect(() => {
    bootstrapRealtime(serviceSlug, () => Promise.resolve(authService.keycloak.token ?? null))
  }, [serviceSlug, authService])

  // Global unhandled-promise error logger (matches pattern in all 5 apps).
  useEffect(() => {
    const handler = (event: PromiseRejectionEvent) => {
      // biome-ignore lint/suspicious/noConsole: intentional error logging
      console.error('[MayaProviders] unhandledrejection', event.reason)
    }
    window.addEventListener('unhandledrejection', handler)
    return () => window.removeEventListener('unhandledrejection', handler)
  }, [])

  const errorFallback = (
    <AppErrorFallback
      heading={errorFallbackProps?.heading}
      description={errorFallbackProps?.description}
      reloadLabel={errorFallbackProps?.reloadLabel}
    />
  )

  const inner = (
    <QueryClientProvider client={queryClient}>
      <AuthProvider keycloak={authService.keycloak as Parameters<typeof AuthProvider>[0]['keycloak']}>
        <BrowserRouter>
          <UserProfileProvider fetchProfile={fetchProfile}>
            <NotificationProvider>
              {withToasts ? (
                <ToastProvider>
                  {ExtraProviders ? (
                    <ExtraProviders>{children}</ExtraProviders>
                  ) : (
                    children
                  )}
                </ToastProvider>
              ) : ExtraProviders ? (
                <ExtraProviders>{children}</ExtraProviders>
              ) : (
                children
              )}
            </NotificationProvider>
          </UserProfileProvider>
        </BrowserRouter>
      </AuthProvider>
    </QueryClientProvider>
  )

  if (withErrorBoundary) {
    return <TranslatedErrorBoundary fallback={errorFallback}>{inner}</TranslatedErrorBoundary>
  }

  return <>{inner}</>
}

// Re-export AuthLoadingScreen so apps don't need to import from two packages.
export { AuthLoadingScreen }

// Convenience type re-export
export type { MayaProvidersProps } from './appShell.types'
