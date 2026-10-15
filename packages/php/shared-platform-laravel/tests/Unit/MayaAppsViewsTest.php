<?php

declare(strict_types=1);

use Maya\Platform\Views\InvalidViewFile;
use Maya\Platform\Views\MayaAppsViews;

// Contrato de los ficheros de vistas de maya_apps (D17): una sentencia CREATE OR REPLACE VIEW
// maya_apps.<nombre> por fichero <nombre>.sql, con revisión opcional.

const VIEWS_FIXTURES = __DIR__.'/../Fixtures/views/app';

/** Escribe un .sql temporal y devuelve su ruta. */
function viewFile(string $name, string $sql): string
{
    $dir = sys_get_temp_dir().'/maya-views-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/'.$name, $sql);

    return $dir.'/'.$name;
}

it('reads name, revision and the statement without comments or the final semicolon', function (): void {
    $view = MayaAppsViews::fromFile(VIEWS_FIXTURES.'/v_app_test_attendances.sql', 'Maya Dashboard');

    expect($view->name)->toBe('v_app_test_attendances')
        ->and($view->qualifiedName())->toBe('maya_apps.v_app_test_attendances')
        ->and($view->revision)->toBe(2)
        ->and($view->owner)->toBe('Maya Dashboard')
        ->and($view->sql)->toStartWith('CREATE OR REPLACE VIEW maya_apps.v_app_test_attendances AS')
        ->and($view->sql)->toEndWith("WHERE a.attendance_type = 'I'")
        ->and($view->sql)->toContain("'a;b'::text AS source")
        ->and($view->sql)->not->toContain('sub de Keycloak')
        ->and($view->sql)->not->toContain('empleado → usuario');
});

it('defaults to revision 1 and strips a numeric ordering prefix from the file name', function (): void {
    $view = MayaAppsViews::fromFile(VIEWS_FIXTURES.'/20_v_app_test_profile.sql', 'x');

    expect($view->name)->toBe('v_app_test_profile')->and($view->revision)->toBe(1);
});

it('lists a directory in file name order and ignores a missing directory', function (): void {
    $names = array_map(fn ($v) => $v->name, MayaAppsViews::fromDirectory(VIEWS_FIXTURES, 'x'));

    expect($names)->toBe(['v_app_test_profile', 'v_app_test_attendances'])
        ->and(MayaAppsViews::fromDirectory(VIEWS_FIXTURES.'/no-existe', 'x'))->toBe([]);
});

it('rejects a view outside maya_apps', function (string $sql): void {
    MayaAppsViews::fromFile(viewFile('v_x.sql', $sql), 'x');
})->with([
    'public' => ['CREATE OR REPLACE VIEW public.v_x AS SELECT 1'],
    'sin esquema' => ['CREATE OR REPLACE VIEW v_x AS SELECT 1'],
    'sin OR REPLACE' => ['CREATE VIEW maya_apps.v_x AS SELECT 1'],
    'temporal' => ['CREATE OR REPLACE TEMP VIEW maya_apps.v_x AS SELECT 1'],
    'otra cosa' => ['DROP VIEW maya_apps.v_x'],
])->throws(InvalidViewFile::class);

it('rejects more than one statement per file', function (): void {
    MayaAppsViews::fromFile(viewFile('v_x.sql', "CREATE OR REPLACE VIEW maya_apps.v_x AS SELECT 1;\nGRANT ALL ON maya_apps.v_x TO PUBLIC;"), 'x');
})->throws(InvalidViewFile::class, 'una sola sentencia');

it('rejects a file whose name does not match the view', function (): void {
    MayaAppsViews::fromFile(viewFile('v_y.sql', 'CREATE OR REPLACE VIEW maya_apps.v_x AS SELECT 1'), 'x');
})->throws(InvalidViewFile::class, 'v_x.sql');

it('rejects two files with the same view', function (): void {
    $file = viewFile('v_x.sql', 'CREATE OR REPLACE VIEW maya_apps.v_x AS SELECT 1');
    file_put_contents(dirname($file).'/10_v_x.sql', 'CREATE OR REPLACE VIEW maya_apps.v_x AS SELECT 2');

    MayaAppsViews::fromDirectory(dirname($file), 'x');
})->throws(InvalidViewFile::class, 'la misma vista');

it('keeps quoted identifiers and dollar-quoted strings intact', function (): void {
    [$code, $semicolons] = MayaAppsViews::stripComments("SELECT \"a;--b\", \$q\$x;/*y*/\$q\$ -- fin;\n/* c; */ FROM t");

    expect($semicolons)->toBe(0)
        ->and($code)->toContain('"a;--b"')
        ->and($code)->toContain('$q$x;/*y*/$q$')
        ->and($code)->not->toContain('fin');
});

it('ships common views that all follow the contract', function (): void {
    $views = MayaAppsViews::fromDirectory(MayaAppsViews::commonPath(), MayaAppsViews::COMMON_OWNER);
    $names = array_map(fn ($v) => $v->name, $views);

    expect($names)->toContain('v_app_users', 'v_dms_teams', 'v_dms_team_members', 'v_app_user_study_types',
        'v_app_user_studies', 'v_app_user_course_modules', 'v_app_study_types', 'v_app_studies',
        'v_app_subjects', 'v_app_study_subjects', 'v_app_languages');

    foreach ($views as $view) {
        // R4: nunca el espejo de desarrollo; tablas de Odoo siempre calificadas
        expect($view->sql)->not->toContain('keycloak_user_id')
            ->and($view->sql)->not->toMatch('/\b(FROM|JOIN)\s+(?!public\.|maya_apps\.|LATERAL\b)[a-z_]+/i')
            ->and($view->sql)->not->toMatch('/\b(password|totp_secret|oauth_access_token)\b/');
    }
});
