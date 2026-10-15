<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Maya\Platform\Views\InvalidViewFile;
use Maya\Platform\Views\ViewApplier;
use Maya\Platform\Views\ViewDefinition;

// Sin PostgreSQL: una conexión falsa registra lo que se le manda, en orden.

/**
 * @param  list<array{name: string, comment: string|null}>  $existing  vistas que ya hay en maya_apps
 * @return array{0: ConnectionInterface, 1: ArrayObject<int, string>}
 */
function fakeMayaAppsConnection(array $existing = []): array
{
    $log = new ArrayObject();
    $db = Mockery::mock(ConnectionInterface::class);
    $db->shouldReceive('transaction')->once()->andReturnUsing(function (Closure $callback) use ($log) {
        $log[] = 'BEGIN';
        try {
            $result = $callback();
        } catch (Throwable $e) {
            $log[] = 'ROLLBACK';
            throw $e;
        }
        $log[] = 'COMMIT';

        return $result;
    });
    $db->shouldReceive('statement')->andReturnUsing(function (string $sql) use ($log): bool {
        $log[] = $sql;

        return true;
    });
    $db->shouldReceive('unprepared')->andReturnUsing(function (string $sql) use ($log): bool {
        $log[] = $sql;

        return true;
    });
    $db->shouldReceive('select')->andReturnUsing(function (string $sql, array $bindings) use ($log, $existing): array {
        $log[] = 'SELECT existentes '.implode(',', $bindings);

        return array_map(fn (array $row) => (object) $row, $existing);
    });

    return [$db, $log];
}

function viewDef(string $name, int $revision = 1, string $owner = 'maya_platform'): ViewDefinition
{
    return new ViewDefinition($name, "CREATE OR REPLACE VIEW maya_apps.{$name} AS SELECT 1", $revision, $owner, "/x/{$name}.sql");
}

it('applies every view in one transaction: create, comment and grant to the reader', function (): void {
    [$db, $log] = fakeMayaAppsConnection();

    $result = (new ViewApplier($db, 'maya_fdw_reader'))->apply([viewDef('v_a'), viewDef('v_b', 3, 'Maya Dashboard')]);

    expect($result)->toBe(['applied' => ['v_a', 'v_b'], 'skipped' => []])
        ->and($log->getArrayCopy())->toBe([
            'BEGIN',
            "SET LOCAL lock_timeout = '15s'",
            'SET LOCAL search_path = pg_catalog, pg_temp',
            'SELECT existentes maya_apps',
            'CREATE OR REPLACE VIEW maya_apps.v_a AS SELECT 1',
            "COMMENT ON VIEW maya_apps.v_a IS 'maya:views owner=maya_platform revision=1'",
            'GRANT SELECT ON maya_apps.v_a TO maya_fdw_reader',
            'CREATE OR REPLACE VIEW maya_apps.v_b AS SELECT 1',
            "COMMENT ON VIEW maya_apps.v_b IS 'maya:views owner=Maya Dashboard revision=3'",
            'GRANT SELECT ON maya_apps.v_b TO maya_fdw_reader',
            'COMMIT',
        ]);
});

it('keeps a view whose revision in the database is newer (another app on a newer maya_platform)', function (): void {
    [$db, $log] = fakeMayaAppsConnection([
        ['name' => 'v_a', 'comment' => 'maya:views owner=maya_platform revision=2'],
        ['name' => 'v_b', 'comment' => 'maya:views owner=maya_platform revision=1'],
    ]);

    $result = (new ViewApplier($db, 'maya_fdw_reader'))->apply([viewDef('v_a', 1), viewDef('v_b', 1)]);

    expect($result['applied'])->toBe(['v_b'])
        ->and($result['skipped'])->toBe(['v_a (revisión 2 en la BD, 1 aquí)'])
        ->and(implode("\n", $log->getArrayCopy()))->not->toContain('VIEW maya_apps.v_a AS');
});

it('refuses to redefine a view kept by another app and rolls everything back', function (): void {
    [$db, $log] = fakeMayaAppsConnection([
        ['name' => 'v_b', 'comment' => 'maya:views owner=Maya DMS revision=1'],
    ]);

    expect(fn () => (new ViewApplier($db, 'maya_fdw_reader'))->apply([viewDef('v_a'), viewDef('v_b', 1, 'Maya Dashboard')]))
        ->toThrow(InvalidViewFile::class, 'es de «Maya DMS»');

    expect($log->getArrayCopy())->toContain('ROLLBACK')->not->toContain('COMMIT');
});

it('replaces views without a maya:views comment (created by hand) and quotes the owner', function (): void {
    [$db, $log] = fakeMayaAppsConnection([['name' => 'v_a', 'comment' => null]]);

    (new ViewApplier($db, 'maya_fdw_reader', '5s'))->apply([viewDef('v_a', 1, "O'Brien")]);

    expect($log->getArrayCopy())
        ->toContain("SET LOCAL lock_timeout = '5s'")
        ->toContain("COMMENT ON VIEW maya_apps.v_a IS 'maya:views owner=O''Brien revision=1'");
});

it('validates the reader role and the lock timeout', function (string $reader, string $timeout): void {
    $db = Mockery::mock(ConnectionInterface::class);
    $db->shouldNotReceive('transaction');

    new ViewApplier($db, $reader, $timeout);
})->with([
    ['maya_fdw_reader; DROP ROLE x', '15s'],
    ['maya_fdw_reader', "15s'; SELECT 1; --"],
])->throws(InvalidArgumentException::class);

it('parses its own markers and ignores other comments', function (): void {
    expect(ViewApplier::parseMarker(ViewApplier::marker('Log Management', 7)))->toBe(['owner' => 'Log Management', 'revision' => 7])
        ->and(ViewApplier::parseMarker('vista de maya_infra'))->toBe(['owner' => null, 'revision' => null])
        ->and(ViewApplier::parseMarker(null))->toBe(['owner' => null, 'revision' => null]);
});
