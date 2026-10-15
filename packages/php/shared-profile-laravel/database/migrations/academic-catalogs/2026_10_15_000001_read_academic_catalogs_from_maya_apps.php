<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los catálogos académicos leen vistas de `maya_apps` en lugar de tablas de Odoo (D17).
 *
 * Las tablas de Odoo que se leían tal cual pasan a vistas del esquema `maya_apps` de `maya_erp`
 * (las crea el despliegue de cada app, `php artisan maya:views`); `maya_fdw_reader` ya no lee
 * ninguna tabla de Odoo. Las vistas tienen las mismas columnas que las tablas foráneas, así que las
 * vistas locales (`study_types`, `studies`, `course_modules`) no cambian:
 *
 *   res_company_fdw            res_company                            → maya_apps.v_app_study_types
 *   studies_fdw                maya_core_study                        → maya_apps.v_app_studies
 *   course_modules_subject_fdw maya_core_subject                      → maya_apps.v_app_subjects
 *   course_modules_rel_fdw     maya_core_study_maya_core_subject_rel  → maya_apps.v_app_study_subjects
 *
 * - `testing` / BD `*_test`: no-op (tablas físicas).
 * - resto: `ALTER FOREIGN TABLE … OPTIONS (SET schema_name, SET table_name)` (si la tabla existe).
 */
return new class extends Migration
{
    /** tabla foránea => [vista de maya_apps, tabla de Odoo que leía antes] */
    private const FOREIGN_TABLES = [
        'res_company_fdw' => ['v_app_study_types', 'res_company'],
        'studies_fdw' => ['v_app_studies', 'maya_core_study'],
        'course_modules_subject_fdw' => ['v_app_subjects', 'maya_core_subject'],
        'course_modules_rel_fdw' => ['v_app_study_subjects', 'maya_core_study_maya_core_subject_rel'],
    ];

    private function isTestEnv(): bool
    {
        if (app()->environment('testing')) {
            return true;
        }
        $db = config('database.connections.pgsql.database');

        return is_string($db) && str_ends_with($db, '_test');
    }

    public function up(): void
    {
        if ($this->isTestEnv()) {
            return;
        }

        foreach (self::FOREIGN_TABLES as $table => [$view]) {
            DB::statement("ALTER FOREIGN TABLE IF EXISTS {$table} OPTIONS (SET schema_name 'maya_apps', SET table_name '{$view}')");
        }
    }

    public function down(): void
    {
        if ($this->isTestEnv()) {
            return;
        }

        foreach (self::FOREIGN_TABLES as $table => [, $odooTable]) {
            DB::statement("ALTER FOREIGN TABLE IF EXISTS {$table} OPTIONS (SET schema_name 'public', SET table_name '{$odooTable}')");
        }
    }
};
