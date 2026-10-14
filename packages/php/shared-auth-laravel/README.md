# ceedcv-maya/shared-auth-laravel

Keycloak/OIDC JWT authentication middleware for Laravel: JWKS caching, RequirePermissionMiddleware, AppToAppAuthenticator, configurable user resolver.

Part of the [ceedcv-maya/maya_platform](https://github.com/Maya-AQSS/maya_platform) mono-repo. Distributed independently for reuse outside the Maya ecosystem.

## Installation

```bash
composer require ceedcv-maya/shared-auth-laravel
```

```php
// routes/api.php
use Maya\Auth\Middleware\AuthenticateJwt;
use Maya\Auth\Middleware\RequirePermission;

Route::middleware([AuthenticateJwt::class, RequirePermission::class.':users.read'])->group(function () {
    Route::get('/me', fn () => auth()->user());
});
```

```env
KEYCLOAK_URL=https://keycloak.example.org
KEYCLOAK_REALM=my-realm
KEYCLOAK_CLIENT_ID=my-app
```

## Token validation (`JwtMiddleware`)

A request passes only with a Keycloak **access token** that meets all of these:

| Check | Rule | Config |
|---|---|---|
| Signature | RS256 with the JWKS key named by the `kid` header (JWKS cached) | `auth.jwks_url`, `auth.jwks_cache_ttl` |
| Issuer | `iss` equals the configured issuer | `auth.jwt_issuer` |
| Expiry | `exp` is **required** and must be in the future (also `iat`/`nbf` when present); no clock leeway | — |
| Token type | `typ` claim is exactly `Bearer` (Keycloak access token). ID tokens (`ID`), refresh tokens (`Refresh`, `Offline`) and tokens without `typ` are rejected | — |
| Audience | `aud` contains, or `azp` equals, one of the comma-separated values (one per app: its own `client_id`) | `auth.jwt_audience` (required outside `testing`) |

Any failure returns `401` (`WWW-Authenticate: Bearer error="invalid_token"`) and logs `JWT authentication failed`: `info` with `error_code=JWT-EXPIRED` for expired tokens, `warning` with `error_code=JWT-INVALID` for everything else.

Tests that mint their own JWTs (instead of bypassing the middleware) must add the `typ: Bearer` and `exp` claims.


## TypeScript / build notes
PSR-4 autoload from `src/`. Service providers are registered via Laravel package discovery (no manual provider registration needed).

## License

MIT — see [LICENSE](LICENSE).

## Reporting issues

The canonical source lives in [Maya-AQSS/maya_platform](https://github.com/Maya-AQSS/maya_platform). File issues there; this read-only split repo is only the published artifact.
