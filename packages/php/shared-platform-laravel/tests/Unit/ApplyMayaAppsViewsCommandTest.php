<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Maya\Platform\Console\Commands\ApplyMayaAppsViews;

// php artisan maya:views (D17): vistas comunes del paquete + las de la app, en una transacción, como
// maya_apps_views. Sin PostgreSQL: la conexión se sustituye por un doble.

const COMMAND_VIEWS_FIXTURES = __DIR__.'/../Fixtures/views/app';

beforeEach(function (): void {
    config(['app.name' => 'Maya Dashboard']);
});

it('is registered in the artisan kernel', function (): void {
    expect(Artisan::all())->toHaveKey('maya:views')
        ->and(Artisan::all()['maya:views'])->toBeInstanceOf(ApplyMayaAppsViews::class);
});

it('defines the maya_apps connection as maya_apps_views on Odoo\'s database by default', function (): void {
    expect(config('maya-views.connection'))->toBe('maya_apps')
        ->and(config('maya-views.database.driver'))->toBe('pgsql')
        ->and(config('maya-views.database.username'))->toBe('maya_apps_views')
        ->and(config('maya-views.database.database'))->toBe('maya_erp')
        ->and(config('maya-views.reader'))->toBe('maya_fdw_reader');
});

it('validates and prints the SQL without connecting on --dry-run, common views first', function (): void {
    DB::shouldReceive('connection')->never();

    $this->artisan('maya:views', ['--dry-run' => true, '--path' => COMMAND_VIEWS_FIXTURES])
        ->expectsOutputToContain('-- maya_apps.v_app_users (maya_platform, revisión 1)')
        ->expectsOutputToContain('-- maya_apps.v_app_test_attendances (Maya Dashboard, revisión 2)')
        ->expectsOutputToContain('GRANT SELECT ON maya_apps.v_app_test_profile TO maya_fdw_reader;')
        ->assertExitCode(0);
});

it('applies common and app views through the maya_apps connection', function (): void {
    $log = [];
    $db = Mockery::mock(ConnectionInterface::class);
    $db->shouldReceive('transaction')->once()->andReturnUsing(fn (Closure $cb) => $cb());
    $db->shouldReceive('select')->andReturn([]);
    $db->shouldReceive('statement')->andReturnUsing(function (string $sql) use (&$log): bool {
        $log[] = $sql;

        return true;
    });
    $db->shouldReceive('unprepared')->andReturnUsing(function (string $sql) use (&$log): bool {
        $log[] = $sql;

        return true;
    });
    DB::shouldReceive('connection')->once()->with('maya_apps')->andReturn($db);

    $this->artisan('maya:views', ['--path' => COMMAND_VIEWS_FIXTURES])
        ->expectsOutputToContain('vistas aplicadas')
        ->assertExitCode(0);

    $creates = array_values(array_filter($log, fn (string $sql) => str_starts_with($sql, 'CREATE OR REPLACE VIEW')));
    $grants = array_values(array_filter($log, fn (string $sql) => str_starts_with($sql, 'GRANT SELECT')));

    expect($creates[0])->toStartWith('CREATE OR REPLACE VIEW maya_apps.v_app_')
        ->and(end($creates))->toStartWith('CREATE OR REPLACE VIEW maya_apps.v_app_test_attendances')
        ->and(count($creates))->toBe(count($grants))
        ->and($log)->toContain("COMMENT ON VIEW maya_apps.v_app_test_attendances IS 'maya:views owner=Maya Dashboard revision=2'")
        ->and(config('database.connections.maya_apps.username'))->toBe('maya_apps_views');
});

it('fails, before connecting, when an app view has the name of a common one', function (): void {
    DB::shouldReceive('connection')->never();
    $dir = sys_get_temp_dir().'/maya-views-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/v_app_users.sql', 'CREATE OR REPLACE VIEW maya_apps.v_app_users AS SELECT 1');

    $this->artisan('maya:views', ['--path' => $dir])
        ->expectsOutputToContain('es una vista común de maya_platform')
        ->assertExitCode(1);
});

it('fails, before connecting, on an invalid file', function (): void {
    DB::shouldReceive('connection')->never();
    $dir = sys_get_temp_dir().'/maya-views-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/v_x.sql', 'CREATE OR REPLACE VIEW public.v_x AS SELECT 1');

    $this->artisan('maya:views', ['--path' => $dir])->assertExitCode(1);
});

it('reports a failed transaction and exits with an error', function (): void {
    $db = Mockery::mock(ConnectionInterface::class);
    $db->shouldReceive('transaction')->once()->andThrow(new RuntimeException('cannot change data type of view column "id"'));
    DB::shouldReceive('connection')->once()->with('maya_apps')->andReturn($db);

    $this->artisan('maya:views', ['--path' => COMMAND_VIEWS_FIXTURES])
        ->expectsOutputToContain('no se ha cambiado ninguna vista')
        ->assertExitCode(1);
});

it('refuses a connection that is not PostgreSQL', function (): void {
    DB::shouldReceive('connection')->never();

    $this->artisan('maya:views', ['--path' => COMMAND_VIEWS_FIXTURES, '--connection' => 'sqlite'])
        ->expectsOutputToContain('no es pgsql')
        ->assertExitCode(1);
});
