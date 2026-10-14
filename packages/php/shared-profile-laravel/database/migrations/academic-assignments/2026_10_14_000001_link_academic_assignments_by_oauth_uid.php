<?php

use Maya\Platform\Database\PostgresFdwMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enlaza las asignaciones académicas con Keycloak por `res_users.oauth_uid` (D26 R4, D19).
 *
 * El usuario de Keycloak (`sub` del JWT) es en Odoo `res_users.oauth_uid`: lo rellena el
 * inicio de sesión de Odoo con Keycloak y user-worker al dar de alta al usuario. La
 * columna `keycloak_user_id` que usaban 000003/000004/000005 solo existía en los Odoo de
 * desarrollo: en producción no existe y las tres vistas fallaban al consultarse.
 *
 * Esta migración redefine la foreign table compartida `res_users_kc_fdw` con `oauth_uid`
 * y vuelve a crear las tres vistas que dependen de ella:
 *   - `user_study_types`    (res_company_users_rel)
 *   - `user_studies`        (res_company_users_rel + maya_core_study)
 *   - `user_course_modules` (maya_core_subject_employee_rel + maya_core_employee)
 * con las mismas columnas que antes (`user_id` = `sub` de Keycloak). Las migraciones
 * 000003–000005 no se tocan: en las BDs donde ya corrieron no se vuelven a ejecutar, y en
 * una instalación nueva corren antes que esta, que deja el mismo resultado.
 *
 * Rutas:
 * - `testing`: no-op (tablas físicas con `user_id`, sin FDW).
 * - `local|staging|production`: drop de las vistas y de `res_users_kc_fdw` (CASCADE) y
 *   recreación con `oauth_uid`. Las demás foreign tables se crean solo si faltan.
 */
return new class extends Migration
{
    private const USER_STUDY_TYPES      = 'user_study_types';
    private const USER_STUDIES          = 'user_studies';
    private const USER_COURSE_MODULES   = 'user_course_modules';
    private const USERS_KC_FDW_TABLE    = 'res_users_kc_fdw';
    private const COMPANY_REL_FDW_TABLE = 'res_company_users_rel_fdw';
    private const STUDY_FDW_TABLE       = 'maya_core_study_for_user_studies_fdw';
    private const SUBJECT_REL_FDW_TABLE = 'maya_core_subject_employee_rel_fdw';
    private const EMPLOYEE_FDW_TABLE    = 'maya_core_employee_fdw';
    private const FDW_SERVER            = 'odoo_server';

    /** Columna de `res_users` con el `sub` de Keycloak. */
    private const USER_KEY = 'oauth_uid';

    /** La de 000003–000005, solo para `down()`. */
    private const LEGACY_USER_KEY = 'keycloak_user_id';

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

        $this->redefine(self::USER_KEY);
    }

    public function down(): void
    {
        if ($this->isTestEnv()) {
            return;
        }

        // Vuelve al estado de 000003–000005 (solo funciona en un Odoo con keycloak_user_id).
        $this->redefine(self::LEGACY_USER_KEY);
    }

    private function redefine(string $userKey): void
    {
        if (! PostgresFdwMigration::ensurePostgresFdwExtension('academic assignments by '.$userKey)) {
            return;
        }

        foreach ([self::USER_COURSE_MODULES, self::USER_STUDIES, self::USER_STUDY_TYPES] as $view) {
            DB::statement('DROP VIEW IF EXISTS '.$view);
        }

        // CASCADE: arrastra cualquier otra vista sobre la columna vieja.
        PostgresFdwMigration::dropForeignTableIfExists(self::USERS_KC_FDW_TABLE);
        DB::statement('
            CREATE FOREIGN TABLE '.self::USERS_KC_FDW_TABLE.' (
                id INTEGER, '.$userKey.' VARCHAR(255), active BOOLEAN
            )
            SERVER '.self::FDW_SERVER.'
            OPTIONS (schema_name \'public\', table_name \'res_users\')
        ');

        // Las demás foreign tables no cambian; se crean solo si faltan (idempotente).
        $this->ensureForeignTable(self::COMPANY_REL_FDW_TABLE, 'cid INTEGER, user_id INTEGER', 'res_company_users_rel');
        $this->ensureForeignTable(
            self::STUDY_FDW_TABLE,
            'id INTEGER, company_id INTEGER, active BOOLEAN, create_date TIMESTAMP, write_date TIMESTAMP',
            'maya_core_study',
        );
        $this->ensureForeignTable(
            self::SUBJECT_REL_FDW_TABLE,
            'id INTEGER, subject_id INTEGER, employee_id INTEGER, study_id INTEGER, '
                .'create_date TIMESTAMP, write_date TIMESTAMP',
            'maya_core_subject_employee_rel',
        );
        $this->ensureForeignTable(self::EMPLOYEE_FDW_TABLE, 'id INTEGER, user_id INTEGER', 'maya_core_employee');

        // Mismas vistas que 000003–000005, con la clave de usuario como parámetro.
        // El root CEEDCV (res_company id=1) no es un tipo de estudio.
        DB::statement("
            CREATE OR REPLACE VIEW ".self::USER_STUDY_TYPES." AS
            SELECT
                md5('ust_' || u.{$userKey} || '_' || rcr.cid::text)::uuid::text AS id,
                u.{$userKey}              AS user_id,
                rcr.cid::text             AS study_type_id,
                NULL::timestamp           AS created_at,
                NULL::timestamp           AS updated_at
            FROM ".self::COMPANY_REL_FDW_TABLE." rcr
            JOIN ".self::USERS_KC_FDW_TABLE." u ON u.id = rcr.user_id
            WHERE u.{$userKey} IS NOT NULL
              AND u.{$userKey} <> ''
              AND u.active = true
              AND rcr.cid <> 1
        ");

        DB::statement("
            CREATE OR REPLACE VIEW ".self::USER_STUDIES." AS
            SELECT
                md5('us_' || u.{$userKey} || '_' || s.id::text)::uuid::text AS id,
                u.{$userKey}        AS user_id,
                s.id::text          AS study_id,
                s.create_date       AS created_at,
                s.write_date        AS updated_at
            FROM ".self::COMPANY_REL_FDW_TABLE." rcr
            JOIN ".self::USERS_KC_FDW_TABLE." u ON u.id = rcr.user_id
            JOIN ".self::STUDY_FDW_TABLE." s ON s.company_id = rcr.cid
            WHERE u.{$userKey} IS NOT NULL
              AND u.{$userKey} <> ''
              AND u.active = true
              AND s.active = true
              AND rcr.cid <> 1
        ");

        DB::statement("
            CREATE OR REPLACE VIEW ".self::USER_COURSE_MODULES." AS
            SELECT
                md5('ucm_' || u.{$userKey} || '_' || ser.study_id::text || '_' || ser.subject_id::text)::uuid::text AS id,
                u.{$userKey}                                                    AS user_id,
                (ser.study_id::text || '_' || ser.subject_id::text)             AS module_id,
                ser.create_date                                                  AS created_at,
                ser.write_date                                                   AS updated_at
            FROM ".self::SUBJECT_REL_FDW_TABLE." ser
            JOIN ".self::EMPLOYEE_FDW_TABLE." e ON e.id = ser.employee_id
            JOIN ".self::USERS_KC_FDW_TABLE." u ON u.id = e.user_id
            WHERE u.{$userKey} IS NOT NULL
              AND u.{$userKey} <> ''
              AND u.active = true
        ");

        $appUser = config('database.connections.pgsql.username');
        if (is_string($appUser) && $appUser !== '') {
            try {
                foreach ([
                    self::USERS_KC_FDW_TABLE,
                    self::COMPANY_REL_FDW_TABLE,
                    self::STUDY_FDW_TABLE,
                    self::SUBJECT_REL_FDW_TABLE,
                    self::EMPLOYEE_FDW_TABLE,
                ] as $ft) {
                    DB::statement('REVOKE INSERT, UPDATE, DELETE ON '.$ft.' FROM "'.$appUser.'"');
                    DB::statement('GRANT SELECT ON '.$ft.' TO "'.$appUser.'"');
                }
            } catch (\Throwable $e) {
                logger()->warning('FDW academic assignments ('.$userKey.') grants: '.$e->getMessage());
            }
        }
    }

    private function ensureForeignTable(string $name, string $columnsSql, string $remoteTable): void
    {
        DB::statement('
            CREATE FOREIGN TABLE IF NOT EXISTS '.$name.' (
                '.$columnsSql.'
            )
            SERVER '.self::FDW_SERVER.'
            OPTIONS (schema_name \'public\', table_name \''.$remoteTable.'\')
        ');
    }
};
