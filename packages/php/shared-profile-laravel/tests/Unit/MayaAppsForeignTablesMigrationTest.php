<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

// Migraciones 2026_10_15_000001_* (D17): las tablas foráneas hacia Odoo leen vistas del esquema
// maya_apps de maya_erp, nunca tablas de Odoo. Sin PostgreSQL: se captura el SQL de DB::statement.

const MAYA_APPS_MIGRATIONS = __DIR__.'/../../database/migrations';

function mayaAppsMigration(string $group, string $name): object
{
    return require MAYA_APPS_MIGRATIONS."/{$group}/2026_10_15_000001_{$name}.php";
}

/** @return list<string> SQL enviado, con los espacios normalizados */
function captureMayaAppsSql(callable $run, string $environment = 'production'): array
{
    $statements = [];
    DB::shouldReceive('statement')->andReturnUsing(function (string $sql) use (&$statements): bool {
        $statements[] = preg_replace('/\s+/', ' ', trim($sql));

        return true;
    });

    app()->detectEnvironment(fn () => $environment);
    try {
        $run();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }

    return $statements;
}

beforeEach(function (): void {
    config([
        'database.connections.pgsql.database' => 'maya_dashboard',
        'database.connections.pgsql.username' => 'dashboard_user',
    ]);
});

dataset('repuntes', [
    'users' => ['users', 'read_users_from_maya_apps', ['users_fdw' => ['v_app_users', 'v_app_users']]],
    'teams' => ['teams', 'read_teams_from_maya_apps', [
        'teams_fdw' => ['v_dms_teams', 'v_dms_teams'],
        'team_members_fdw' => ['v_dms_team_members', 'v_dms_team_members'],
    ]],
    'catálogos' => ['academic-catalogs', 'read_academic_catalogs_from_maya_apps', [
        'res_company_fdw' => ['v_app_study_types', 'res_company'],
        'studies_fdw' => ['v_app_studies', 'maya_core_study'],
        'course_modules_subject_fdw' => ['v_app_subjects', 'maya_core_subject'],
        'course_modules_rel_fdw' => ['v_app_study_subjects', 'maya_core_study_maya_core_subject_rel'],
    ]],
    'idiomas' => ['languages', 'read_languages_from_maya_apps', ['res_lang_fdw' => ['v_app_languages', 'res_lang']]],
]);

it('is the last migration of its group', function (string $group, string $name): void {
    $files = array_map('basename', glob(MAYA_APPS_MIGRATIONS."/{$group}/*.php"));
    sort($files);

    expect(end($files))->toBe("2026_10_15_000001_{$name}.php");
})->with('repuntes');

it('repoints each foreign table to its maya_apps view, and back on down()', function (string $group, string $name, array $tables): void {
    // Una sola captura (el doble de DB es uno por test): up(), una marca y down()
    $sql = captureMayaAppsSql(function () use ($group, $name): void {
        mayaAppsMigration($group, $name)->up();
        DB::statement('-- down');
        mayaAppsMigration($group, $name)->down();
    });
    $mark = array_search('-- down', $sql, true);
    $up = array_slice($sql, 0, $mark);
    $down = array_slice($sql, $mark + 1);

    $expectedUp = [];
    $expectedDown = [];
    foreach ($tables as $table => [$view, $before]) {
        $expectedUp[] = "ALTER FOREIGN TABLE IF EXISTS {$table} OPTIONS (SET schema_name 'maya_apps', SET table_name '{$view}')";
        $expectedDown[] = "ALTER FOREIGN TABLE IF EXISTS {$table} OPTIONS (SET schema_name 'public', SET table_name '{$before}')";
    }

    expect($up)->toBe($expectedUp)->and($down)->toBe($expectedDown);
})->with('repuntes');

it('does nothing in testing or against a *_test database', function (string $group, string $name): void {
    expect(captureMayaAppsSql(fn () => mayaAppsMigration($group, $name)->up(), 'testing'))->toBe([]);

    config(['database.connections.pgsql.database' => 'maya_dashboard_test']);
    expect(captureMayaAppsSql(fn () => mayaAppsMigration($group, $name)->up()))->toBe([]);
})->with([
    ['users', 'read_users_from_maya_apps'],
    ['teams', 'read_teams_from_maya_apps'],
    ['academic-catalogs', 'read_academic_catalogs_from_maya_apps'],
    ['languages', 'read_languages_from_maya_apps'],
    ['academic-assignments', 'read_academic_assignments_from_maya_apps'],
]);

it('reads the academic assignments from maya_apps views already keyed by oauth_uid', function (): void {
    $sql = captureMayaAppsSql(fn () => mayaAppsMigration('academic-assignments', 'read_academic_assignments_from_maya_apps')->up());
    $all = implode("\n", $sql);

    // Ninguna tabla de Odoo leída tal cual: las cinco tablas foráneas viejas se borran
    foreach (['res_users_kc_fdw', 'res_company_users_rel_fdw', 'maya_core_study_for_user_studies_fdw',
        'maya_core_subject_employee_rel_fdw', 'maya_core_employee_fdw'] as $legacy) {
        expect($sql)->toContain("DROP FOREIGN TABLE IF EXISTS {$legacy} CASCADE");
    }
    expect($all)->not->toContain("schema_name 'public'")->not->toContain('oauth_uid');

    foreach ([
        'user_study_types' => ['v_app_user_study_types', 'study_type_id'],
        'user_studies' => ['v_app_user_studies', 'study_id'],
        'user_course_modules' => ['v_app_user_course_modules', 'module_id'],
    ] as $view => [$remote, $scope]) {
        expect($sql)
            ->toContain("DROP VIEW IF EXISTS {$view}")
            ->toContain("CREATE FOREIGN TABLE {$view}_fdw ( id TEXT, user_id TEXT, {$scope} TEXT, created_at TIMESTAMP, updated_at TIMESTAMP ) "
                ."SERVER odoo_server OPTIONS (schema_name 'maya_apps', table_name '{$remote}')")
            ->toContain("CREATE OR REPLACE VIEW {$view} AS SELECT id, user_id, {$scope}, created_at, updated_at FROM {$view}_fdw")
            ->toContain("REVOKE INSERT, UPDATE, DELETE ON {$view}_fdw FROM \"dashboard_user\"");
    }
});

it('goes back to the oauth_uid join over Odoo tables on down() (state of 2026_10_14_000001)', function (): void {
    $sql = captureMayaAppsSql(fn () => mayaAppsMigration('academic-assignments', 'read_academic_assignments_from_maya_apps')->down());

    expect($sql)
        ->toContain('DROP FOREIGN TABLE IF EXISTS user_studies_fdw CASCADE')
        ->toContain("CREATE FOREIGN TABLE IF NOT EXISTS res_users_kc_fdw (id INTEGER, oauth_uid VARCHAR(255), active BOOLEAN) "
            ."SERVER odoo_server OPTIONS (schema_name 'public', table_name 'res_users')");
    expect(implode("\n", $sql))->not->toContain('keycloak_user_id')->not->toContain('maya_apps');
});
