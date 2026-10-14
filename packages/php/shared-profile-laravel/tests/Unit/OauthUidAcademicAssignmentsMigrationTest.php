<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

// Migración 2026_10_14_000001 (D26 R4, D19): las asignaciones académicas se enlazan con
// Keycloak por res_users.oauth_uid. Sin PostgreSQL en los tests: se captura el SQL que
// manda a DB::statement y se comprueba su forma.

const OAUTH_UID_MIGRATIONS_DIR = __DIR__.'/../../database/migrations/academic-assignments';
const OAUTH_UID_MIGRATION = '2026_10_14_000001_link_academic_assignments_by_oauth_uid.php';

function oauthUidMigration(): object
{
    return require OAUTH_UID_MIGRATIONS_DIR.'/'.OAUTH_UID_MIGRATION;
}

/**
 * Ejecuta $run en el entorno dado con DB::statement capturado. Devuelve el SQL enviado,
 * con los espacios normalizados.
 *
 * @return list<string>
 */
function captureMigrationSql(callable $run, string $environment = 'production'): array
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

/** Posición de la primera sentencia que contiene $needle (falla si no hay ninguna). */
function sqlIndexOf(array $statements, string $needle): int
{
    foreach ($statements as $i => $sql) {
        if (str_contains($sql, $needle)) {
            return $i;
        }
    }

    throw new RuntimeException("Ninguna sentencia contiene: {$needle}");
}

beforeEach(function (): void {
    config([
        'database.connections.pgsql.database' => 'maya_dms',
        'database.connections.pgsql.username' => 'maya_dms_app',
    ]);
});

it('runs after the migrations it fixes (000003-000005)', function (): void {
    $files = array_map('basename', glob(OAUTH_UID_MIGRATIONS_DIR.'/*.php'));
    sort($files);

    expect(end($files))->toBe(OAUTH_UID_MIGRATION);
});

it('is a no-op in the testing environment (physical tables with user_id)', function (): void {
    expect(captureMigrationSql(fn () => oauthUidMigration()->up(), 'testing'))->toBe([]);
});

it('is a no-op against a *_test database', function (): void {
    config(['database.connections.pgsql.database' => 'maya_dms_test']);

    expect(captureMigrationSql(fn () => oauthUidMigration()->up()))->toBe([]);
});

it('redefines res_users_kc_fdw with oauth_uid and recreates the three views on it', function (): void {
    $sql = captureMigrationSql(fn () => oauthUidMigration()->up());
    $all = implode("\n", $sql);

    expect($all)->not->toContain('keycloak_user_id');

    $dropFt = sqlIndexOf($sql, 'DROP FOREIGN TABLE IF EXISTS res_users_kc_fdw CASCADE');
    $createFt = sqlIndexOf($sql, 'CREATE FOREIGN TABLE res_users_kc_fdw ( id INTEGER, oauth_uid VARCHAR(255), active BOOLEAN )');
    expect($sql[$createFt])->toContain("table_name 'res_users'");
    expect($dropFt)->toBeLessThan($createFt);

    foreach (['user_study_types', 'user_studies', 'user_course_modules'] as $view) {
        $drop = sqlIndexOf($sql, "DROP VIEW IF EXISTS {$view}");
        $create = sqlIndexOf($sql, "CREATE OR REPLACE VIEW {$view} AS");
        expect($drop)->toBeLessThan($dropFt);
        expect($create)->toBeGreaterThan($createFt);
        expect($sql[$create])
            ->toContain('u.oauth_uid AS user_id')
            ->toContain('JOIN res_users_kc_fdw u ON u.id = ')
            ->toContain("WHERE u.oauth_uid IS NOT NULL AND u.oauth_uid <> '' AND u.active = true");
    }

    expect($sql[sqlIndexOf($sql, 'CREATE OR REPLACE VIEW user_study_types AS')])
        ->toContain("md5('ust_' || u.oauth_uid || '_' || rcr.cid::text)::uuid::text AS id")
        ->toContain('rcr.cid <> 1');
    expect($sql[sqlIndexOf($sql, 'CREATE OR REPLACE VIEW user_course_modules AS')])
        ->toContain("(ser.study_id::text || '_' || ser.subject_id::text) AS module_id");
});

it('only creates the other foreign tables if they are missing', function (): void {
    $sql = captureMigrationSql(fn () => oauthUidMigration()->up());

    foreach ([
        'res_company_users_rel_fdw' => 'res_company_users_rel',
        'maya_core_study_for_user_studies_fdw' => 'maya_core_study',
        'maya_core_subject_employee_rel_fdw' => 'maya_core_subject_employee_rel',
        'maya_core_employee_fdw' => 'maya_core_employee',
    ] as $foreignTable => $remote) {
        $create = $sql[sqlIndexOf($sql, "CREATE FOREIGN TABLE IF NOT EXISTS {$foreignTable} (")];
        expect($create)->toContain("table_name '{$remote}'");
        expect(implode("\n", $sql))->not->toContain("DROP FOREIGN TABLE IF EXISTS {$foreignTable}");
    }
});

it('keeps the foreign tables read-only for the app user', function (): void {
    $sql = captureMigrationSql(fn () => oauthUidMigration()->up());

    expect($sql)
        ->toContain('REVOKE INSERT, UPDATE, DELETE ON res_users_kc_fdw FROM "maya_dms_app"')
        ->toContain('GRANT SELECT ON res_users_kc_fdw TO "maya_dms_app"');
});

it('reverts to keycloak_user_id on down() (the state left by 000003-000005)', function (): void {
    $sql = captureMigrationSql(fn () => oauthUidMigration()->down());
    $all = implode("\n", $sql);

    expect($all)->not->toContain('oauth_uid');
    expect($sql)->toContain(
        "CREATE FOREIGN TABLE res_users_kc_fdw ( id INTEGER, keycloak_user_id VARCHAR(255), active BOOLEAN ) "
        ."SERVER odoo_server OPTIONS (schema_name 'public', table_name 'res_users')"
    );
    expect($sql[sqlIndexOf($sql, 'CREATE OR REPLACE VIEW user_studies AS')])
        ->toContain('u.keycloak_user_id AS user_id');
});
