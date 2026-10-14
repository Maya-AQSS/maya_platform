<?php

/**
 * Generates deterministic test fixtures for JwksService tests.
 *
 * Run once (or to regenerate after key rotation):
 *   php tests/Fixtures/generate-fixtures.php
 *
 * Outputs:
 *   - jwks.json          — JWKS payload as Keycloak would serve it
 *   - expected-pem.pem   — The correct PEM for the test key (reference)
 *   - jwt-valid.txt      — RS256 JWT signed with the test private key
 *   - jwt-expired.txt    — RS256 JWT with exp in the past
 *
 * Los tokens llevan `typ: Bearer` (token de acceso de Keycloak): JwtMiddleware
 * rechaza los demás tipos. El emisor es el de tests/TestCase.php.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

$dir = __DIR__;

// --- Load the pre-generated RSA key pair ---
$privateKeyPem = file_get_contents("{$dir}/test-private.pem");
$publicKeyPem  = file_get_contents("{$dir}/test-public.pem");

// --- Extract JWK components from the public key ---
$publicKey = openssl_pkey_get_public($publicKeyPem);
$keyDetails = openssl_pkey_get_details($publicKey);

function base64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

$n = base64url($keyDetails['rsa']['n']);
$e = base64url($keyDetails['rsa']['e']);

$kid = 'test-key-2026';

// --- JWKS ---
$jwks = [
    'keys' => [
        [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n'   => $n,
            'e'   => $e,
        ],
    ],
];

file_put_contents("{$dir}/jwks.json", json_encode($jwks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// --- Expected PEM (authoritative reference from OpenSSL) ---
file_put_contents("{$dir}/expected-pem.pem", $publicKeyPem);

// --- JWT tokens ---
$config = Configuration::forAsymmetricSigner(
    new Sha256(),
    InMemory::plainText($privateKeyPem),
    InMemory::plainText($publicKeyPem),
);

$now = new DateTimeImmutable('2026-05-09T12:00:00Z');
$issuer = 'http://keycloak.localhost/realms/maya';

// Valid token (expires in 10 years — test vector, not production)
$validToken = $config->builder()
    ->issuedBy($issuer)
    ->permittedFor('maya-test-service')
    ->relatedTo('test-user-uuid-0001')
    ->withClaim('typ', 'Bearer')
    ->withClaim('azp', 'maya-test-service')
    ->withClaim('realm_access', ['roles' => ['admin', 'user']])
    ->withClaim('email', 'test@maya.localhost')
    ->withClaim('name', 'Test User')
    ->withHeader('kid', $kid)
    ->expiresAt($now->modify('+10 years'))
    ->issuedAt($now)
    ->getToken(new Sha256(), InMemory::plainText($privateKeyPem));

file_put_contents("{$dir}/jwt-valid.txt", $validToken->toString());

// Expired token (exp = 1 second after issuance)
$expiredToken = $config->builder()
    ->issuedBy($issuer)
    ->permittedFor('maya-test-service')
    ->relatedTo('test-user-uuid-0001')
    ->withClaim('typ', 'Bearer')
    ->withHeader('kid', $kid)
    ->expiresAt($now->modify('-1 hour'))
    ->issuedAt($now->modify('-2 hours'))
    ->getToken(new Sha256(), InMemory::plainText($privateKeyPem));

file_put_contents("{$dir}/jwt-expired.txt", $expiredToken->toString());

// Wrong-audience token (aud = 'other-service', azp unset)
$wrongAudToken = $config->builder()
    ->issuedBy($issuer)
    ->permittedFor('completely-other-service')
    ->relatedTo('test-user-uuid-0001')
    ->withClaim('typ', 'Bearer')
    ->withHeader('kid', $kid)
    ->expiresAt($now->modify('+10 years'))
    ->issuedAt($now)
    ->getToken(new Sha256(), InMemory::plainText($privateKeyPem));

file_put_contents("{$dir}/jwt-wrong-audience.txt", $wrongAudToken->toString());

// No-kid token (header without kid)
$noKidConfig = Configuration::forAsymmetricSigner(
    new Sha256(),
    InMemory::plainText($privateKeyPem),
    InMemory::plainText($publicKeyPem),
);

$noKidToken = $noKidConfig->builder()
    ->issuedBy($issuer)
    ->relatedTo('test-user-uuid-0001')
    ->withClaim('typ', 'Bearer')
    ->expiresAt($now->modify('+10 years'))
    ->issuedAt($now)
    ->getToken(new Sha256(), InMemory::plainText($privateKeyPem));

file_put_contents("{$dir}/jwt-no-kid.txt", $noKidToken->toString());

echo "Fixtures generated in {$dir}:" . PHP_EOL;
echo "  jwks.json" . PHP_EOL;
echo "  expected-pem.pem" . PHP_EOL;
echo "  jwt-valid.txt" . PHP_EOL;
echo "  jwt-expired.txt" . PHP_EOL;
echo "  jwt-wrong-audience.txt" . PHP_EOL;
echo "  jwt-no-kid.txt" . PHP_EOL;
echo "Kid: {$kid}" . PHP_EOL;
