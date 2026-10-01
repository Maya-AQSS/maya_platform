<?php

declare(strict_types=1);

namespace Maya\Http\Support;

use Illuminate\Http\Middleware\HandleCors;

/**
 * Registers the middleware configuration that is common across all Maya API services.
 *
 * ## Usage
 * ```php
 * // bootstrap/app.php
 * ->withMiddleware(function (Middleware $middleware): void {
 *     CommonMiddleware::register($middleware, [
 *         'jwt'        => \Maya\Auth\Middleware\JwtMiddleware::class,
 *         'permission' => \Maya\Auth\Middleware\RequirePermissionMiddleware::class,
 *     ]);
 * })
 * ```
 *
 * ## What it registers (always)
 * - `HandleCors::class` prepended to the `api` middleware group.
 * - `trustProxies(at: ...)` — required behind Traefik/load-balancers.
 *
 * ## Trusted proxies resolution (in order)
 * 1. Explicit `'trustProxies'` option (`string|array|false`).
 * 2. Env `TRUSTED_PROXIES` (comma-separated list of IP/CIDR), e.g.
 *    `10.42.0.0/16,10.224.236.0/23`.
 * 3. Fallback `'*'` — convenient for dev/Compose. **In production this is
 *    insecure**: always set `TRUSTED_PROXIES` to the CIDR Traefik connects
 *    from — the K3s pod CIDR (`10.42.0.0/16` by default). The maya-common
 *    chart sets it in the ConfigMap (`config.TRUSTED_PROXIES`).
 *
 * ## Options
 * | Key                 | Type                          | Default | Description                                                                  |
 * |---------------------|-------------------------------|---------|------------------------------------------------------------------------------|
 * | `trustProxies`      | `bool\|string\|array`         | `true`  | `false` = skip; `true` = resolve from env; `string\|array` = explicit value. |
 * | `trimStringsExcept` | `string[]`                    | `[]`    | If non-empty, calls `trimStrings(except: [...])`.                            |
 * | `apiPrepend`        | `class-string[]`              | `[]`    | Extra middleware prepended after HandleCors.                                 |
 *
 * ## Source provenance
 * Pattern extracted from the five Maya backend `bootstrap/app.php` files:
 *
 * | Service       | CORS prepend | trustProxies | trimStrings except     | Extra API prepend            |
 * |---------------|:---:|:---:|------------------------|------------------------------|
 * | maya_dms      |  ✓  |  —  | content.*, description.*, default_content.* | —         |
 * | maya_dashboard|  ✓  |  —  | —                      | —                            |
 * | maya_authorization | ✓ | — | —                     | —                            |
 * | maya_audit    |  ✓  |  ✓  | —                      | —                            |
 * | maya_logs     |  ✓  |  ✓  | —                      | SetLocaleFromAcceptLanguage  |
 *
 * Only `HandleCors` + `trustProxies` appear in 2+ services and are genuinely universal.
 * The rest are service-specific and must be passed as parameters.
 */
final class CommonMiddleware
{
    /**
     * Register common middleware on the given configurator.
     *
     * @param  object  $middleware  The Middleware configurator (Illuminate\Foundation\Configuration\Middleware or compatible).
     * @param  array<string, class-string>  $aliases  Alias → FQCN map for `$middleware->alias()`.
     * @param  array<string, mixed>  $options  See class docblock for supported keys.
     */
    public static function register(object $middleware, array $aliases = [], array $options = []): void
    {
        $trustProxies = $options['trustProxies'] ?? true;
        $trimStringsExcept = $options['trimStringsExcept'] ?? [];
        $apiPrepend = $options['apiPrepend'] ?? [];

        // 1. Trust proxies (Traefik / load-balancers)
        if ($trustProxies !== false) {
            $at = self::resolveTrustedProxies($trustProxies);
            $middleware->trustProxies(at: $at);
        }

        // 2. CORS must be first in the API group (before auth/throttle middleware)
        $prepend = array_merge([HandleCors::class], $apiPrepend);
        $middleware->api(prepend: $prepend);

        // 3. Alias registration (service-specific)
        if ($aliases !== []) {
            $middleware->alias($aliases);
        }

        // 4. TrimStrings exclusions (service-specific rich-content fields)
        if ($trimStringsExcept !== []) {
            $middleware->trimStrings(except: $trimStringsExcept);
        }
    }

    /**
     * Resolve the value passed to `trustProxies(at: ...)`.
     *
     * @param  mixed  $option  Raw option value (`true`, string, or array).
     * @return string|array<int, string>
     */
    private static function resolveTrustedProxies(mixed $option): string|array
    {
        if (is_string($option) && $option !== '') {
            return $option;
        }

        if (is_array($option) && $option !== []) {
            return array_values(array_map('strval', $option));
        }

        // Read from $_ENV first (phpdotenv may populate it without putenv,
        // in which case getenv() alone would miss it), then process env.
        $envValue = $_ENV['TRUSTED_PROXIES'] ?? getenv('TRUSTED_PROXIES');
        if ($envValue !== false && trim((string) $envValue) !== '') {
            $items = array_values(array_filter(array_map('trim', explode(',', (string) $envValue)), static fn (string $v): bool => $v !== ''));
            if ($items !== []) {
                return count($items) === 1 ? $items[0] : $items;
            }
        }

        // No explicit value and no TRUSTED_PROXIES: refuse the insecure '*'
        // fallback in production (trusting any proxy enables X-Forwarded-* spoofing).
        $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV');
        if (is_string($appEnv) && $appEnv === 'production') {
            throw new \RuntimeException(
                'TRUSTED_PROXIES must be set explicitly in production (the CIDR Traefik connects from, e.g. the K3s '
                .'pod CIDR such as 10.42.0.0/16). Refusing to trust all proxies ("*").'
            );
        }

        // Insecure fallback for dev/Compose only.
        return '*';
    }
}
