<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Maya\Platform\Database\PostgresFdwMigration;

/**
 * Las asignaciones académicas leen vistas de `maya_apps`, ya resueltas por `oauth_uid` (D17, D26 R4).
 *
 * Hasta 2026_10_14_000001, las vistas locales `user_study_types`, `user_studies` y
 * `user_course_modules` cruzaban aquí cinco tablas de Odoo leídas tal cual (res_users con oauth_uid,
 * res_company_users_rel, maya_core_study, maya_core_subject_employee_rel, maya_core_employee). Con
 * D17 `maya_fdw_reader` ya no lee tablas de Odoo, y menos res_users: el cruce lo hacen las vistas
 * comunes de `maya_apps` (shared-platform-laravel, `php artisan maya:views`), que ya devuelven
 * `user_id` = `sub` de Keycloak:
 *
 *   user_study_types    ← user_study_types_fdw    → maya_apps.v_app_user_study_types
 *   user_studies        ← user_studies_fdw        → maya_apps.v_app_user_studies
 *   user_course_modules ← user_course_modules_fdw → maya_apps.v_app_user_course_modules
 *
 * Mismas columnas que antes (id, user_id, <ámbito>_id, created_at, updated_at) y mismos valores
 * (comprobado el 15-10 contra el Odoo de desarrollo). Las cinco tablas foráneas viejas se borran.
 *
 * - `testing` / BD `*_test`: no-op (tablas físicas).
 * - resto: drop de las vistas y tablas foráneas viejas; tablas foráneas nuevas y vistas locales
 *   que solo las pasan. `down()` vuelve al estado de 2026_10_14_000001.
 */
return new class extends Migration
{
    private const FDW_SERVER = 'odoo_server';

    /** vista local => [tabla foránea, vista de maya_apps, columna del ámbito] */
    private const VIEWS = [
        'user_study_types' => ['user_study_types_fdw', 'v_app_user_study_types', 'study_type_id'],
        'user_studies' => ['user_studies_fdw', 'v_app_user_studies', 'study_id'],
        'user_course_modules' => ['user_course_modules_fdw', 'v_app_user_course_modules', 'module_id'],
    ];

    /** Tablas foráneas de 000003–000005 y 2026_10_14_000001 => tabla de Odoo y columnas */
    private const LEGACY_FOREIGN_TABLES = [
        'res_users_kc_fdw' => ['res_users', 'id INTEGER, oauth_uid VARCHAR(255), active BOOLEAN'],
        'res_company_users_rel_fdw' => ['res_company_users_rel', 'cid INTEGER, user_id INTEGER'],
        'maya_core_study_for_user_studies_fdw' => [
            'maya_core_study', 'id INTEGER, company_id INTEGER, active BOOLEAN, create_date TIMESTAMP, write_date TIMESTAMP',
        ],
        'maya_core_subject_employee_rel_fdw' => [
            'maya_core_subject_employee_rel',
            'id INTEGER, subject_id INTEGER, employee_id INTEGER, study_id INTEGER, create_date TIMESTAMP, write_date TIMESTAMP',
        ],
        'maya_core_employee_fdw' => ['maya_core_employee', 'id INTEGER, user_id INTEGER'],
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

        if (! PostgresFdwMigration::ensurePostgresFdwExtension('academic assignments from maya_apps')) {
            return;
        }

        $this->dropViews();
        foreach (array_keys(self::LEGACY_FOREIGN_TABLES) as $table) {
            PostgresFdwMigration::dropForeignTableIfExists($table);
        }

        foreach (self::VIEWS as $view => [$foreignTable, $remoteView, $scopeColumn]) {
            PostgresFdwMigration::dropForeignTableIfExists($foreignTable);
            DB::statement("
                CREATE FOREIGN TABLE {$foreignTable} (
                    id TEXT, user_id TEXT, {$scopeColumn} TEXT, created_at TIMESTAMP, updated_at TIMESTAMP
                )
                SERVER ".self::FDW_SERVER."
                OPTIONS (schema_name 'maya_apps', table_name '{$remoteView}')
            ");
            DB::statement("
                CREATE OR REPLACE VIEW {$view} AS
                SELECT id, user_id, {$scopeColumn}, created_at, updated_at
                FROM {$foreignTable}
            ");
        }

        $this->readOnly(array_column(self::VIEWS, 0));
    }

    public function down(): void
    {
        if ($this->isTestEnv()) {
            return;
        }

        if (! PostgresFdwMigration::ensurePostgresFdwExtension('academic assignments by oauth_uid')) {
            return;
        }

        $this->dropViews();
        foreach (self::VIEWS as [$foreignTable]) {
            PostgresFdwMigration::dropForeignTableIfExists($foreignTable);
        }

        // Estado de 2026_10_14_000001: tablas de Odoo leídas tal cual y el cruce aquí.
        foreach (self::LEGACY_FOREIGN_TABLES as $table => [$odooTable, $columns]) {
            DB::statement("
                CREATE FOREIGN TABLE IF NOT EXISTS {$table} ({$columns})
                SERVER ".self::FDW_SERVER."
                OPTIONS (schema_name 'public', table_name '{$odooTable}')
            ");
        }

        DB::statement("
            CREATE OR REPLACE VIEW user_study_types AS
            SELECT
                md5('ust_' || u.oauth_uid || '_' || rcr.cid::text)::uuid::text AS id,
                u.oauth_uid               AS user_id,
                rcr.cid::text             AS study_type_id,
                NULL::timestamp           AS created_at,
                NULL::timestamp           AS updated_at
            FROM res_company_users_rel_fdw rcr
            JOIN res_users_kc_fdw u ON u.id = rcr.user_id
            WHERE u.oauth_uid IS NOT NULL
              AND u.oauth_uid <> ''
              AND u.active = true
              AND rcr.cid <> 1
        ");
        DB::statement("
            CREATE OR REPLACE VIEW user_studies AS
            SELECT
                md5('us_' || u.oauth_uid || '_' || s.id::text)::uuid::text AS id,
                u.oauth_uid         AS user_id,
                s.id::text          AS study_id,
                s.create_date       AS created_at,
                s.write_date        AS updated_at
            FROM res_company_users_rel_fdw rcr
            JOIN res_users_kc_fdw u ON u.id = rcr.user_id
            JOIN maya_core_study_for_user_studies_fdw s ON s.company_id = rcr.cid
            WHERE u.oauth_uid IS NOT NULL
              AND u.oauth_uid <> ''
              AND u.active = true
              AND s.active = true
              AND rcr.cid <> 1
        ");
        DB::statement("
            CREATE OR REPLACE VIEW user_course_modules AS
            SELECT
                md5('ucm_' || u.oauth_uid || '_' || ser.study_id::text || '_' || ser.subject_id::text)::uuid::text AS id,
                u.oauth_uid                                                     AS user_id,
                (ser.study_id::text || '_' || ser.subject_id::text)             AS module_id,
                ser.create_date                                                  AS created_at,
                ser.write_date                                                   AS updated_at
            FROM maya_core_subject_employee_rel_fdw ser
            JOIN maya_core_employee_fdw e ON e.id = ser.employee_id
            JOIN res_users_kc_fdw u ON u.id = e.user_id
            WHERE u.oauth_uid IS NOT NULL
              AND u.oauth_uid <> ''
              AND u.active = true
        ");

        $this->readOnly(array_keys(self::LEGACY_FOREIGN_TABLES));
    }

    private function dropViews(): void
    {
        foreach (array_keys(self::VIEWS) as $view) {
            DB::statement('DROP VIEW IF EXISTS '.$view);
        }
    }

    /** @param list<string> $foreignTables */
    private function readOnly(array $foreignTables): void
    {
        $appUser = config('database.connections.pgsql.username');
        if (! is_string($appUser) || $appUser === '') {
            return;
        }

        try {
            foreach ($foreignTables as $table) {
                DB::statement('REVOKE INSERT, UPDATE, DELETE ON '.$table.' FROM "'.$appUser.'"');
                DB::statement('GRANT SELECT ON '.$table.' TO "'.$appUser.'"');
            }
        } catch (\Throwable $e) {
            logger()->warning('FDW academic assignments (maya_apps) grants: '.$e->getMessage());
        }
    }
};
