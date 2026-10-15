<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `teams_fdw` y `team_members_fdw` leen `maya_apps.v_dms_teams` y `maya_apps.v_dms_team_members` (D17).
 *
 * Mismas vistas y columnas que antes, ahora en el esquema `maya_apps` de `maya_erp`, que crea el
 * despliegue de cada app (`php artisan maya:views`); `maya_fdw_reader` solo lee ese esquema. Las
 * vistas locales `teams` y `team_members` no cambian.
 *
 * - `testing` / BD `*_test`: no-op (tablas físicas).
 * - resto: `ALTER FOREIGN TABLE … OPTIONS (SET schema_name, SET table_name)` (si la tabla existe).
 */
return new class extends Migration
{
    private const FOREIGN_TABLES = [
        'teams_fdw' => 'v_dms_teams',
        'team_members_fdw' => 'v_dms_team_members',
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

        foreach (self::FOREIGN_TABLES as $table => $view) {
            DB::statement("ALTER FOREIGN TABLE IF EXISTS {$table} OPTIONS (SET schema_name 'maya_apps', SET table_name '{$view}')");
        }
    }

    public function down(): void
    {
        if ($this->isTestEnv()) {
            return;
        }

        foreach (self::FOREIGN_TABLES as $table => $view) {
            DB::statement("ALTER FOREIGN TABLE IF EXISTS {$table} OPTIONS (SET schema_name 'public', SET table_name '{$view}')");
        }
    }
};
