<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `res_lang_fdw` lee `maya_apps.v_app_languages` en lugar de la tabla `res_lang` de Odoo (D17).
 *
 * La vista (esquema `maya_apps` de `maya_erp`, la crea el despliegue de cada app con
 * `php artisan maya:views`) tiene las mismas columnas que la tabla foránea: la vista local
 * `languages` no cambia.
 *
 * - `testing` / BD `*_test`: no-op (tabla física sembrada).
 * - resto: `ALTER FOREIGN TABLE … OPTIONS (SET schema_name, SET table_name)` (si la tabla existe).
 */
return new class extends Migration
{
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

        DB::statement("ALTER FOREIGN TABLE IF EXISTS res_lang_fdw OPTIONS (SET schema_name 'maya_apps', SET table_name 'v_app_languages')");
    }

    public function down(): void
    {
        if ($this->isTestEnv()) {
            return;
        }

        DB::statement("ALTER FOREIGN TABLE IF EXISTS res_lang_fdw OPTIONS (SET schema_name 'public', SET table_name 'res_lang')");
    }
};
