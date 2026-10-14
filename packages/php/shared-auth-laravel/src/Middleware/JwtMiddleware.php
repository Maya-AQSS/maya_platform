<?php

namespace Maya\Auth\Middleware;

use Maya\Auth\Contracts\JwksServiceInterface;
use Maya\Auth\Http\AuthErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\RegisteredClaims;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class JwtMiddleware
{
    /** Claim `typ` de los tokens de acceso de Keycloak. */
    private const ACCESS_TOKEN_TYPE = 'Bearer';

    public function __construct(
        private readonly JwksServiceInterface $jwksService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Evita reutilizar un usuario resuelto en una request previa (tests multi-actor).
        Auth::forgetGuards();

        $token = $this->extractToken($request);

        if ($token === null) {
            if (app()->environment('local') && config('auth.dev_bypass_auth', false)) {
                $this->setCurrentUser($request, [
                    'sub' => '00000000-0000-0000-0000-000000000001',
                    'email' => 'dev@maya.localhost',
                    'name' => 'Dev User',
                    'realm_access' => ['roles' => ['super-admin', 'admin', 'user']],
                ]);
                return $next($request);
            }

            $this->logAuthFailure($request, null, 'Missing Authorization header');
            return AuthErrorResponse::missingToken();
        }

        // Configuration guard — fail loudly before processing any token in non-testing environments
        $audience = config('auth.jwt_audience');
        if ((! is_string($audience) || trim($audience) === '') && ! app()->environment('testing')) {
            throw new RuntimeException('AUTH_JWT_AUDIENCE is not configured');
        }

        try {
            $claims = $this->validateAndExtractClaims($token);
            $this->setCurrentUser($request, $claims);
        } catch (\Throwable $e) {
            $this->logAuthFailure($request, $token, $e->getMessage());
            return AuthErrorResponse::unauthenticated();
        }

        return $next($request);
    }

    private function logAuthFailure(Request $request, ?string $token, string $reason): void
    {
        // Token caducado = ruido esperado en cada ráfaga de refetchOnWindowFocus.
        // Lo bajamos a `info` para que el canal `rabbit` (filtro >= warning) no lo
        // reenvíe a maya.logs, pero sigue en el log diario para auditoría local.
        $isExpired = str_contains($reason, 'The token is expired');
        $level = $isExpired ? 'info' : 'warning';

        Log::log($level, 'JWT authentication failed', [
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
            'reason'     => $reason,
            'error_code' => $isExpired ? 'JWT-EXPIRED' : 'JWT-INVALID',
        ]);
    }

    private function extractToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return substr($header, 7);
    }

    private function validateAndExtractClaims(string $rawToken): array
    {
        // Extraer kid del header sin validar aún (necesitamos la clave primero)
        $parts = explode('.', $rawToken);

        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed JWT');
        }

        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $kid    = $header['kid'] ?? null;

        if ($kid === null) {
            throw new RuntimeException('JWT missing kid header');
        }

        $publicKey = InMemory::plainText($this->jwksService->getPublicKey($kid));

        $config = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText('verification-only'), // signing key no se usa en validación
            $publicKey,
        );

        $constraints = [
            new SignedWith(new Sha256(), $publicKey),
            new IssuedBy(config('auth.jwt_issuer')),
            new LooseValidAt(new class implements ClockInterface {
                public function now(): \DateTimeImmutable
                {
                    return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                }
            })
        ];

        $config->setValidationConstraints(...$constraints);

        $token = $config->parser()->parse($rawToken);
        $config->validator()->assert($token, ...$config->validationConstraints());

        // LooseValidAt solo comprueba `exp` si viene: un token sin caducidad valdría para
        // siempre. StrictValidAt no sirve porque exige también `nbf`, que Keycloak no emite.
        if (! $token->claims()->has(RegisteredClaims::EXPIRATION_TIME)) {
            throw new RuntimeException('JWT missing exp claim');
        }

        $claims = $token->claims()->all();
        $this->assertAccessTokenType($claims);
        $this->assertAudienceOrAuthorizedParty($claims, config('auth.jwt_audience'));

        return $claims;
    }

    /**
     * Solo se aceptan tokens de acceso. Keycloak emite varios tipos de token con el mismo
     * emisor y los distingue por el claim `typ`: `Bearer` (acceso), `ID` (ID token),
     * `Refresh`, `Offline`, `Logout`... El ID token se firma con la misma clave RS256 y
     * lleva el cliente de la SPA en `aud` y `azp`, así que sin esta comprobación pasaría
     * como token de acceso.
     *
     * Un token sin `typ` también se rechaza: Keycloak lo pone siempre, así que un token
     * sin él no es un token de acceso de Keycloak.
     *
     * @param array<string, mixed> $claims
     */
    private function assertAccessTokenType(array $claims): void
    {
        $type = $claims['typ'] ?? null;

        if ($type !== self::ACCESS_TOKEN_TYPE) {
            throw new RuntimeException(sprintf(
                'JWT is not an access token. expected typ=%s, typ=%s',
                self::ACCESS_TOKEN_TYPE,
                is_string($type) ? $type : 'null',
            ));
        }
    }

    /**
     * Keycloak suele emitir "azp" para public clients y no siempre incluye "aud".
     * Aceptamos cualquiera de los dos para la validación de audiencia esperada.
     * $expectedAudience puede ser una lista separada por comas para aceptar tokens
     * de múltiples servicios (ej. sidebar compartido entre SPAs).
     *
     * @param array<string, mixed> $claims
     */
    private function assertAudienceOrAuthorizedParty(array $claims, mixed $expectedAudience): void
    {
        if (! is_string($expectedAudience) || trim($expectedAudience) === '') {
            return; // empty audience: skip check (only reachable in testing env — production guard is in handle())
        }

        $rawAllowed = array_filter(array_map('trim', explode(',', $expectedAudience)));
        if (count($rawAllowed) > 10) {
            Log::warning('jwt.audience_config_oversized', ['count' => count($rawAllowed)]);
        }
        $allowed = array_slice($rawAllowed, 0, 10);
        $azp = is_string($claims['azp'] ?? null) ? $claims['azp'] : null;
        $audClaim = $claims['aud'] ?? null;

        $audiences = [];
        if (is_string($audClaim) && $audClaim !== '') {
            $audiences[] = $audClaim;
        } elseif (is_array($audClaim)) {
            foreach ($audClaim as $audience) {
                if (is_string($audience) && $audience !== '') {
                    $audiences[] = $audience;
                }
            }
        }

        foreach ($allowed as $expected) {
            if (in_array($expected, $audiences, true) || $azp === $expected) {
                return;
            }
        }

        throw new RuntimeException(
            sprintf(
                'JWT audience mismatch. expected=%s, aud=%s, azp=%s',
                $expectedAudience,
                json_encode($audiences, JSON_UNESCAPED_SLASHES) ?: '[]',
                $azp ?? 'null'
            )
        );
    }

    /**
     * Construye el perfil del usuario a partir de los claims JWT y lo cachea en Redis (TTL 15 min).
     * El perfil se deposita en el atributo 'jwt_user' del request.
     * Auth::user() / $request->user() lo resuelven de forma diferida a través del guard
     * 'api' (jwt-token) registrado en AppServiceProvider::boot().
     */
    private function setCurrentUser(Request $request, array $claims): void
    {
        $userId = $claims['sub'] ?? null;

        if ($userId === null) {
            throw new RuntimeException('JWT missing sub claim');
        }

        // Include a hash of security-sensitive claims so a role revocation in Keycloak
        // causes a cache miss on the next request (instead of serving stale roles for 15 min).
        // JSON_THROW_ON_ERROR prevents a silent json_encode failure from producing sha1('')
        // which would make all failing users share the same cache key (identity confusion).
        $claimHash = substr(sha1(json_encode([
            $claims['realm_access']['roles'] ?? [],
            $claims['scope'] ?? '',
            $claims['email'] ?? '',
        ], JSON_THROW_ON_ERROR)), 0, 8);

        $cacheKey = "jwt_user:{$userId}:{$claimHash}";

        $profile = Cache::remember($cacheKey, 300, function () use ($claims) {
            return [
                'id'                  => $claims['sub'],
                'email'               => $claims['email'] ?? null,
                'name'                => $claims['name'] ?? null,
                'first_name'          => $claims['given_name'] ?? null,
                'last_name'           => $claims['family_name'] ?? null,
                'username'            => $claims['preferred_username'] ?? $claims['username'] ?? null,
                'department'          => $claims['department'] ?? $claims['departamento'] ?? null,
                'organization_id'     => $claims['organization_id'] ?? $claims['org_id'] ?? null,
                'roles'               => $claims['realm_access']['roles'] ?? [],
                'locale'              => $claims['locale'] ?? null,
                'scope'               => $claims['scope'] ?? '',
                // El contexto académico (study_type_ids/study_ids/module_ids/team_ids) NO
                // viene del JWT — se resuelve en AcademicDataReader leyendo Odoo vía FDW.
                // Mantener aquí sólo identidad y permisos de Keycloak.
            ];
        });

        $request->attributes->set('jwt_user', $profile);

        $allowedLocales = ['es', 'va', 'en'];
        $locale = $profile['locale'] ?? null;
        if ($locale && in_array($locale, $allowedLocales, true)) {
            App::setLocale($locale);
        }
    }
}
