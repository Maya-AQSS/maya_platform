<?php

declare(strict_types=1);

use Illuminate\Http\Middleware\HandleCors;
use Maya\Http\Support\CommonMiddleware;

/**
 * CommonMiddleware::register() is a configuration helper invoked inside
 * ->withMiddleware(). We test it by constructing a spy/double for the
 * Middleware configurator and asserting the correct methods are called.
 */

// ─── Minimal Middleware spy ───────────────────────────────────────────────────

/**
 * @return object A spy that records method calls on the Middleware configurator.
 */
function makeMiddlewareSpy(): object
{
    return new class
    {
        public array $calls = [];

        public function api(array $prepend = [], array $append = []): static
        {
            $this->calls[] = ['api', compact('prepend', 'append')];

            return $this;
        }

        public function trustProxies(string|array $at = '*', int $headers = 0): static
        {
            $this->calls[] = ['trustProxies', compact('at', 'headers')];

            return $this;
        }

        public function alias(array $aliases): static
        {
            $this->calls[] = ['alias', $aliases];

            return $this;
        }

        public function trimStrings(array $except = []): static
        {
            $this->calls[] = ['trimStrings', $except];

            return $this;
        }

        public function hasCall(string $method): bool
        {
            foreach ($this->calls as [$m]) {
                if ($m === $method) {
                    return true;
                }
            }

            return false;
        }

        public function getCallArgs(string $method): array
        {
            foreach ($this->calls as [$m, $args]) {
                if ($m === $method) {
                    return $args;
                }
            }

            return [];
        }
    };
}

// ─── Default registration ─────────────────────────────────────────────────────

it('registers CORS prepend on the api group by default', function (): void {
    $spy = makeMiddlewareSpy();

    CommonMiddleware::register($spy);

    expect($spy->hasCall('api'))->toBeTrue();
    $args = $spy->getCallArgs('api');
    expect($args['prepend'])->toContain(HandleCors::class);
});

it('registers trustProxies by default (fallback to * when TRUSTED_PROXIES unset)', function (): void {
    $spy = makeMiddlewareSpy();

    // Ensure env is not leaking from another test.
    putenv('TRUSTED_PROXIES');

    CommonMiddleware::register($spy);

    expect($spy->hasCall('trustProxies'))->toBeTrue();
    $args = $spy->getCallArgs('trustProxies');
    expect($args['at'])->toBe('*');
});

// ─── Alias registration ───────────────────────────────────────────────────────

it('does not call alias when no aliases are provided', function (): void {
    $spy = makeMiddlewareSpy();

    CommonMiddleware::register($spy, []);

    expect($spy->hasCall('alias'))->toBeFalse();
});

it('calls alias with provided aliases', function (): void {
    $spy = makeMiddlewareSpy();

    CommonMiddleware::register($spy, [
        'jwt' => stdClass::class,
        'permission' => stdClass::class,
    ]);

    expect($spy->hasCall('alias'))->toBeTrue();
    $args = $spy->getCallArgs('alias');
    expect($args)->toHaveKey('jwt');
    expect($args)->toHaveKey('permission');
});

// ─── TrimStrings except option ────────────────────────────────────────────────

it('does not call trimStrings when trimStringsExcept option is not provided', function (): void {
    $spy = makeMiddlewareSpy();

    CommonMiddleware::register($spy, [], []);

    expect($spy->hasCall('trimStrings'))->toBeFalse();
});

it('calls trimStrings when trimStringsExcept option is provided', function (): void {
    $spy = makeMiddlewareSpy();

    CommonMiddleware::register($spy, [], [
        'trimStringsExcept' => ['content.*', 'description.*'],
    ]);

    expect($spy->hasCall('trimStrings'))->toBeTrue();
    $args = $spy->getCallArgs('trimStrings');
    expect($args)->toContain('content.*');
    expect($args)->toContain('description.*');
});

// ─── trustProxies opt-out ─────────────────────────────────────────────────────

it('skips trustProxies when trustProxies option is false', function (): void {
    $spy = makeMiddlewareSpy();

    CommonMiddleware::register($spy, [], ['trustProxies' => false]);

    expect($spy->hasCall('trustProxies'))->toBeFalse();
});

it('still registers CORS even when trustProxies is disabled', function (): void {
    $spy = makeMiddlewareSpy();

    CommonMiddleware::register($spy, [], ['trustProxies' => false]);

    expect($spy->hasCall('api'))->toBeTrue();
});

// ─── trustProxies env resolution ──────────────────────────────────────────────

it('reads a single CIDR from the TRUSTED_PROXIES env var', function (): void {
    $spy = makeMiddlewareSpy();
    putenv('TRUSTED_PROXIES=10.42.0.0/16');

    try {
        CommonMiddleware::register($spy);
    } finally {
        putenv('TRUSTED_PROXIES');
    }

    $args = $spy->getCallArgs('trustProxies');
    expect($args['at'])->toBe('10.42.0.0/16');
});

it('splits comma-separated TRUSTED_PROXIES into an array', function (): void {
    $spy = makeMiddlewareSpy();
    putenv('TRUSTED_PROXIES=10.42.0.0/16, 10.0.0.0/8 ,  ');

    try {
        CommonMiddleware::register($spy);
    } finally {
        putenv('TRUSTED_PROXIES');
    }

    $args = $spy->getCallArgs('trustProxies');
    expect($args['at'])->toBe(['10.42.0.0/16', '10.0.0.0/8']);
});

it('falls back to * when TRUSTED_PROXIES is set but empty after trimming', function (): void {
    $spy = makeMiddlewareSpy();
    putenv('TRUSTED_PROXIES=   ,  , ');

    try {
        CommonMiddleware::register($spy);
    } finally {
        putenv('TRUSTED_PROXIES');
    }

    $args = $spy->getCallArgs('trustProxies');
    expect($args['at'])->toBe('*');
});

it('accepts an explicit string in the trustProxies option', function (): void {
    $spy = makeMiddlewareSpy();
    putenv('TRUSTED_PROXIES=10.0.0.0/8'); // explicit option must win

    try {
        CommonMiddleware::register($spy, [], ['trustProxies' => '192.168.1.0/24']);
    } finally {
        putenv('TRUSTED_PROXIES');
    }

    $args = $spy->getCallArgs('trustProxies');
    expect($args['at'])->toBe('192.168.1.0/24');
});

it('accepts an explicit array in the trustProxies option', function (): void {
    $spy = makeMiddlewareSpy();

    CommonMiddleware::register($spy, [], [
        'trustProxies' => ['192.168.1.10', '192.168.1.11'],
    ]);

    $args = $spy->getCallArgs('trustProxies');
    expect($args['at'])->toBe(['192.168.1.10', '192.168.1.11']);
});

// ─── Extra API prepend middleware ─────────────────────────────────────────────

it('appends extra middleware to the api prepend list', function (): void {
    $spy = makeMiddlewareSpy();

    CommonMiddleware::register($spy, [], [
        'apiPrepend' => [stdClass::class],
    ]);

    $args = $spy->getCallArgs('api');
    expect($args['prepend'])->toContain(HandleCors::class);
    expect($args['prepend'])->toContain(stdClass::class);
});

// ─── Does not crash ───────────────────────────────────────────────────────────

it('does not throw when called with all default parameters', function (): void {
    $spy = makeMiddlewareSpy();

    expect(fn () => CommonMiddleware::register($spy))->not->toThrow(Throwable::class);
});

it('does not throw with all options set', function (): void {
    $spy = makeMiddlewareSpy();

    expect(fn () => CommonMiddleware::register($spy, ['jwt' => stdClass::class], [
        'trustProxies' => true,
        'trimStringsExcept' => ['content.*'],
        'apiPrepend' => [stdClass::class],
    ]))->not->toThrow(Throwable::class);
});
