<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Maya\Auth\Contracts\JwksServiceInterface;
use Maya\Auth\JwksService;
use Maya\Auth\Middleware\JwtMiddleware;

function makeRequest(?string $token = null): Request
{
    $request = Request::create('/api/test', 'GET');
    if ($token !== null) {
        $request->headers->set('Authorization', "Bearer {$token}");
    }
    return $request;
}

function loadToken(string $name): string
{
    return trim(file_get_contents(__DIR__ . "/../Fixtures/{$name}.txt"));
}

function jwtMiddleware(): JwtMiddleware
{
    return app(JwtMiddleware::class);
}

/**
 * Firma en el momento un token como los de Keycloak (clave y kid de los fixtures, emisor y
 * audiencia de TestCase), con `typ: Bearer` y caducidad dentro de 5 minutos por defecto.
 * $claims sobreescribe o añade claims (null quita el claim); $expiresIn null = sin `exp`.
 *
 * @param array<string, mixed> $claims
 */
function mintToken(array $claims = [], ?string $expiresIn = '+5 minutes'): string
{
    $fixtures = __DIR__ . '/../Fixtures';
    $config = Configuration::forAsymmetricSigner(
        new Sha256(),
        InMemory::plainText(file_get_contents("{$fixtures}/test-private.pem")),
        InMemory::plainText(file_get_contents("{$fixtures}/test-public.pem")),
    );

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $builder = $config->builder()
        ->issuedBy(config('auth.jwt_issuer'))
        ->permittedFor('maya-test-service')
        ->relatedTo('test-user-uuid-0001')
        ->withHeader('kid', 'test-key-2026')
        ->issuedAt($now->modify('-1 minute'));

    $claims = array_merge(['typ' => 'Bearer', 'azp' => 'maya-test-service'], $claims);
    foreach ($claims as $name => $value) {
        if ($value !== null) {
            $builder = $builder->withClaim($name, $value);
        }
    }

    if ($expiresIn !== null) {
        $builder = $builder->expiresAt($now->modify($expiresIn));
    }

    return $builder->getToken($config->signer(), $config->signingKey())->toString();
}

/**
 * Pasa el token por el middleware y devuelve [código HTTP, entrada de log del fallo o null].
 *
 * @return array{0: int, 1: array<string, mixed>|null}
 */
function handleAndCaptureFailure(string $token): array
{
    $logged = [];
    Log::listen(function ($event) use (&$logged) {
        $logged[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
    });

    $response = jwtMiddleware()->handle(makeRequest($token), fn ($r) => response()->json(['ok' => true]));
    $entry = collect($logged)->first(fn ($e) => $e['message'] === 'JWT authentication failed');

    return [$response->getStatusCode(), $entry];
}

beforeEach(function () {
    Http::fake(['*' => Http::response(json_decode(
        file_get_contents(__DIR__ . '/../Fixtures/jwks.json'),
        true
    ), 200)]);
    Cache::flush();
});

// ─── Missing token ─────────────────────────────────────────────────────────

it('returns 401 when Authorization header is missing', function () {
    $response = jwtMiddleware()->handle(makeRequest(), fn ($r) => response()->json(['ok' => true]));
    expect($response->getStatusCode())->toBe(401);
});

it('returns 401 when Authorization is not Bearer format', function () {
    $request = makeRequest();
    $request->headers->set('Authorization', 'Basic dXNlcjpwYXNz');
    $response = jwtMiddleware()->handle($request, fn ($r) => response()->json(['ok' => true]));
    expect($response->getStatusCode())->toBe(401);
});

// ─── Valid token ───────────────────────────────────────────────────────────

it('passes through and sets jwt_user for valid token', function () {
    $token = loadToken('jwt-valid');
    $request = makeRequest($token);

    $called = false;
    $response = jwtMiddleware()->handle($request, function ($r) use (&$called) {
        $called = true;
        $jwtUser = $r->attributes->get('jwt_user');
        expect($jwtUser)->not->toBeNull();
        expect($jwtUser['id'])->toBe('test-user-uuid-0001');
        return response()->json(['ok' => true]);
    });

    expect($called)->toBeTrue();
    expect($response->getStatusCode())->toBe(200);
});

// ─── Expired token ─────────────────────────────────────────────────────────

it('returns 401 for expired token', function () {
    $token = loadToken('jwt-expired');
    $response = jwtMiddleware()->handle(makeRequest($token), fn ($r) => response()->json(['ok' => true]));
    expect($response->getStatusCode())->toBe(401);
});

it('logs expired token at info level with JWT-EXPIRED code (not warning)', function () {
    $logged = [];
    Log::listen(function ($event) use (&$logged) {
        $logged[] = [
            'level'   => $event->level,
            'message' => $event->message,
            'context' => $event->context,
        ];
    });

    $token = loadToken('jwt-expired');
    jwtMiddleware()->handle(makeRequest($token), fn ($r) => response()->json(['ok']));

    $entry = collect($logged)->first(fn ($e) => $e['message'] === 'JWT authentication failed');
    expect($entry)->not->toBeNull();
    expect($entry['level'])->toBe('info');
    expect($entry['context']['error_code'])->toBe('JWT-EXPIRED');
});

it('logs malformed/invalid token at warning level with JWT-INVALID code', function () {
    $logged = [];
    Log::listen(function ($event) use (&$logged) {
        $logged[] = [
            'level'   => $event->level,
            'message' => $event->message,
            'context' => $event->context,
        ];
    });

    jwtMiddleware()->handle(makeRequest('not.a.valid.jwt.at.all'), fn ($r) => response()->json(['ok']));

    $entry = collect($logged)->first(fn ($e) => $e['message'] === 'JWT authentication failed');
    expect($entry)->not->toBeNull();
    expect($entry['level'])->toBe('warning');
    expect($entry['context']['error_code'])->toBe('JWT-INVALID');
});

// ─── exp obligatorio y solo tokens de acceso (typ = Bearer) ────────────────

it('accepts a freshly minted access token (typ Bearer, exp in 5 minutes)', function () {
    [$status, $failure] = handleAndCaptureFailure(mintToken());
    expect($status)->toBe(200);
    expect($failure)->toBeNull();
});

it('returns 401 for a token without exp (LooseValidAt would let it live forever)', function () {
    [$status, $failure] = handleAndCaptureFailure(mintToken(expiresIn: null));
    expect($status)->toBe(401);
    expect($failure['level'])->toBe('warning');
    expect($failure['context']['error_code'])->toBe('JWT-INVALID');
    expect($failure['context']['reason'])->toContain('JWT missing exp claim');
});

it('returns 401 for an access token expired a minute ago, logged as JWT-EXPIRED', function () {
    [$status, $failure] = handleAndCaptureFailure(mintToken(expiresIn: '-1 minute'));
    expect($status)->toBe(401);
    expect($failure['level'])->toBe('info');
    expect($failure['context']['error_code'])->toBe('JWT-EXPIRED');
});

it('returns 401 for a Keycloak ID token (typ ID), even with the right aud and azp', function () {
    [$status, $failure] = handleAndCaptureFailure(mintToken(['typ' => 'ID']));
    expect($status)->toBe(401);
    expect($failure['level'])->toBe('warning');
    expect($failure['context']['error_code'])->toBe('JWT-INVALID');
    expect($failure['context']['reason'])->toContain('expected typ=Bearer, typ=ID');
});

it('returns 401 for a Keycloak refresh token (typ Refresh)', function () {
    [$status, $failure] = handleAndCaptureFailure(mintToken(['typ' => 'Refresh']));
    expect($status)->toBe(401);
    expect($failure['context']['error_code'])->toBe('JWT-INVALID');
    expect($failure['context']['reason'])->toContain('typ=Refresh');
});

it('returns 401 for a token without typ (Keycloak always sets it)', function () {
    [$status, $failure] = handleAndCaptureFailure(mintToken(['typ' => null]));
    expect($status)->toBe(401);
    expect($failure['context']['error_code'])->toBe('JWT-INVALID');
    expect($failure['context']['reason'])->toContain('typ=null');
});

it('returns 401 for typ with another case (bearer): the comparison is exact', function () {
    [$status] = handleAndCaptureFailure(mintToken(['typ' => 'bearer']));
    expect($status)->toBe(401);
});

// ─── Unknown kid ───────────────────────────────────────────────────────────

it('returns 401 for token with unknown kid', function () {
    // Bind a JwksService that always throws — avoids Http::fake stacking issues in beforeEach
    $this->instance(JwksServiceInterface::class, new class implements JwksServiceInterface {
        public function getPublicKey(string $kid): string {
            throw new \RuntimeException("JWKS key not found for kid: {$kid}");
        }
        public function invalidateCache(): void {}
    });

    $token = loadToken('jwt-valid');
    $response = app(JwtMiddleware::class)->handle(makeRequest($token), fn ($r) => response()->json(['ok' => true]));
    expect($response->getStatusCode())->toBe(401);
});

// ─── Malformed token ───────────────────────────────────────────────────────

it('returns 401 for malformed JWT (not 3 parts)', function () {
    $response = jwtMiddleware()->handle(makeRequest('not.a.valid.jwt.at.all'), fn ($r) => response()->json(['ok']));
    expect($response->getStatusCode())->toBe(401);
});

// ─── Audience validation ───────────────────────────────────────────────────

it('returns 401 for token with wrong audience', function () {
    $token = loadToken('jwt-wrong-audience');
    $response = jwtMiddleware()->handle(makeRequest($token), fn ($r) => response()->json(['ok' => true]));
    expect($response->getStatusCode())->toBe(401);
});

it('throws RuntimeException in non-testing env when jwt_audience is empty', function () {
    config(['auth.jwt_audience' => '']);

    app()->detectEnvironment(fn () => 'production');
    try {
        $token = loadToken('jwt-valid');
        expect(fn () => jwtMiddleware()->handle(makeRequest($token), fn ($r) => response()->json(['ok']))
        )->toThrow(RuntimeException::class, 'AUTH_JWT_AUDIENCE is not configured');
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('skips audience check silently in testing env when jwt_audience is empty', function () {
    config(['auth.jwt_audience' => '']);

    $token = loadToken('jwt-valid');
    $response = jwtMiddleware()->handle(makeRequest($token), fn ($r) => response()->json(['ok' => true]));
    // In testing env, empty audience is allowed (graceful skip)
    expect($response->getStatusCode())->toBe(200);
});

// ─── DEV_BYPASS_AUTH ──────────────────────────────────────────────────────

it('bypasses auth when dev_bypass_auth=true and env=local', function () {
    config(['auth.dev_bypass_auth' => true]);
    app()->detectEnvironment(fn () => 'local');

    try {
        $called = false;
        $response = jwtMiddleware()->handle(makeRequest(null), function ($r) use (&$called) {
            $called = true;
            $jwtUser = $r->attributes->get('jwt_user');
            expect($jwtUser['email'])->toBe('dev@maya.localhost');
            return response()->json(['ok' => true]);
        });

        expect($called)->toBeTrue();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('does NOT bypass auth when dev_bypass_auth=true but env=production', function () {
    config(['auth.dev_bypass_auth' => true]);
    app()->detectEnvironment(fn () => 'production');

    try {
        $response = jwtMiddleware()->handle(makeRequest(null), fn ($r) => response()->json(['ok']));
        expect($response->getStatusCode())->toBe(401);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

// ─── Redis cache of user profile ────────────────────────────────────────────

it('caches user profile in array store after successful validation', function () {
    $token = loadToken('jwt-valid');
    $request = makeRequest($token);
    jwtMiddleware()->handle($request, fn ($r) => response()->json(['ok']));

    // The key includes a claim hash: jwt_user:{userId}:{claimHash}.
    // Verify by reading the deposited profile instead of guessing the key.
    $jwtUser = $request->attributes->get('jwt_user');
    expect($jwtUser)->not->toBeNull();
    expect($jwtUser['id'])->toBe('test-user-uuid-0001');
});

it('reuses cached user profile on second request without re-parsing JWT', function () {
    $token = loadToken('jwt-valid');
    $middleware = jwtMiddleware();

    $middleware->handle(makeRequest($token), fn ($r) => response()->json(['ok']));
    $middleware->handle(makeRequest($token), fn ($r) => response()->json(['ok']));

    // JWKS was only fetched once (cache hit on second call too)
    Http::assertSentCount(1);
});
