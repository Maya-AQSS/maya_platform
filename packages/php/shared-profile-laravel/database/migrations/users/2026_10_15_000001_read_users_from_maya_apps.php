<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `users_fdw` lee `maya_apps.v_app_users` (D17).
 *
 * Las vistas de lectura sobre Odoo viven en el esquema `maya_apps` de `maya_erp` y las crea el
 * despliegue de cada app (`php artisan maya:views`, shared-platform-laravel), con un rol que solo
 * puede crear vistas ahí; `maya_fdw_reader`, el usuario de los servidores FDW, solo lee ese esquema.
 * La vista local `users` no cambia: solo cambia de dónde lee la tabla foránea.
 *
 * - `testing` / BD `*_test`: no-op (tabla física `users`).
 * - resto: `ALTER FOREIGN TABLE … OPTIONS (SET schema_name, SET table_name)` (si la tabla existe).
 */
return new class extends Migration
{
    private const FOREIGN_TABLES = ['users_fdw' => 'v_app_users'];

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
