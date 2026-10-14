<?php

use Maya\Platform\Database\PostgresFdwMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vista local `users` proyectada desde `odoo.public.v_app_users` vía postgres_fdw.
 *
 * Fuente de verdad: Odoo (read-only). Las 5 apps del ecosistema Maya
 * (authorization, audit, dms, logs, dashboard) consumen el mismo subconjunto
 * de usuarios resuelto en la vista canónica `v_app_users` definida en
 * `maya_infra/docker/postgres/seeds/create-odoo-views.sql`. Ninguna app
 * escribe en estas filas.
 *
 * Rutas:
 * - `testing`:                   tabla física `users` (factories pueden insertar).
 * - `local|staging|production`:  FDW + vista pass-through hacia
 *                                `odoo.public.v_app_users`.
 *
 * Configuración del FDW:
 * - Por defecto apunta a `maya_infra_postgres:5432/odoo` (entorno local del
 *   ecosistema). Cualquier app puede sobreescribir vía
 *   `config('database.fdw.users.*')` (claves `host`, `port`, `database`,
 *   `username`, `password`, `schema`, `table`) — útil en staging/prod cuando
 *   Odoo vive en otro host.
 *
 * Columnas expuestas en la vista local `users`:
 *   id            — UUID de Keycloak (varchar 255); en Odoo, `res_users.oauth_uid`
 *                   (D26 R4): `v_app_users` debe proyectarlo como `id`
 *   name          — display_name de Odoo
 *   email         — correo único
 *   first_name    — nombre (maya_core_employee.name)
 *   last_name     — apellidos (maya_core_employee.surname)
 *   username      — login Odoo
 *   employee_id   — PK de maya_core_employee (varchar)
 *   dni           — DNI del empleado
 *   employee_type — tipo de empleado (pas, teacher, ...)
 *   is_active     — activo en Odoo
 */
return new class extends Migration
{
    private const VIEW_NAME  = 'users';
    private const FDW_TABLE  = 'users_fdw';
    private const FDW_SERVER = 'odoo_server';

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
            $this->createTestingTable();
            return;
        }

        $this->setupFdw();
    }

    public function down(): void
    {
        if ($this->isTestEnv()) {
            DB::statement('DROP TABLE IF EXISTS '.self::VIEW_NAME);
            return;
        }

        PostgresFdwMigration::dropViewOrTableInPublic(self::VIEW_NAME);
        PostgresFdwMigration::dropForeignTableIfExists(self::FDW_TABLE);
        PostgresFdwMigration::dropFdwServerAndUserMapping(self::FDW_SERVER);
    }

    private function createTestingTable(): void
    {
        // Tabla física `users` para entornos de testing. Incluye `created_at`
        // / `updated_at` porque las factories de Eloquent escriben timestamps
        // por defecto. La vista FDW remota no expone estas columnas; en
        // testing son nullable para no romper inserts sin timestamps.
        DB::statement('
            CREATE TABLE IF NOT EXISTS users (
                id             VARCHAR(255) PRIMARY KEY,
                name           VARCHAR(255),
                email          VARCHAR(255) UNIQUE NOT NULL,
                first_name     VARCHAR(150),
                last_name      VARCHAR(150),
                username       VARCHAR(150),
                employee_id    VARCHAR(64),
                dni            VARCHAR(32),
                employee_type  VARCHAR(64),
                is_active      BOOLEAN NOT NULL DEFAULT TRUE,
                created_at     TIMESTAMP NULL,
                updated_at     TIMESTAMP NULL
            )
        ');
    }

    private function setupFdw(): void
    {
        // Defaults: BD `odoo` del propio servidor maya_infra_postgres.
        // Cualquier app puede sobreescribir vía config('database.fdw.users.*').
        $host     = (string) config('database.fdw.users.host', env('DB_HOST', 'maya_infra_postgres'));
        $port     = (string) config('database.fdw.users.port', '5432');
        $database = (string) config('database.fdw.users.database', 'odoo');
        $username = (string) config('database.fdw.users.username', 'maya');
        $password = (string) config('database.fdw.users.password', 'secret');
        $schema   = (string) config('database.fdw.users.schema', 'public');
        $source   = (string) config('database.fdw.users.table', 'v_app_users');

        if (! PostgresFdwMigration::ensurePostgresFdwExtension('users catalog')) {
            return;
        }

        PostgresFdwMigration::createFdwServerWithUserMapping(
            self::FDW_SERVER,
            $host,
            $port,
            $database,
            $username,
            $password,
        );

        // La vista canónica en Odoo expone `display_name`, pero localmente
        // proyectamos como `name` (compatible con el modelo Eloquent histórico).
        $foreignColumnsSql = 'id VARCHAR(255), email VARCHAR(255), display_name VARCHAR(255), '
            .'first_name VARCHAR(150), last_name VARCHAR(150), username VARCHAR(150), '
            .'employee_id VARCHAR(64), dni VARCHAR(32), employee_type VARCHAR(64), '
            .'is_active BOOLEAN';

        $viewSelectSql = 'id, display_name AS name, email, first_name, last_name, username, '
            .'employee_id, dni, employee_type, is_active';

        PostgresFdwMigration::createForeignTableWithPassThroughView(
            self::VIEW_NAME,
            $foreignColumnsSql,
            $viewSelectSql,
            self::FDW_SERVER,
            $schema,
            $source,
        );
    }
};
