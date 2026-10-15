<?php

declare(strict_types=1);

namespace Maya\Profile;

/**
 * API componible de migraciones del paquete shared.
 *
 * Cada app del ecosistema declara en su `AppServiceProvider::boot()` qué
 * grupos de migraciones quiere cargar vía `loadMigrationsFrom`:
 *
 *   use Maya\Profile\Migrations as ProfileMigrations;
 *
 *   public function boot(): void
 *   {
 *       $this->loadMigrationsFrom(ProfileMigrations::academicAssignments());
 *       $this->loadMigrationsFrom(ProfileMigrations::teams());
 *       $this->loadMigrationsFrom(ProfileMigrations::userPermissions());
 *   }
 *
 * Política de unificación (ecosistema Maya, todas las apps son read-only;
 * Odoo es el único writer y la única fuente de integridad referencial):
 * - No hay FKs en estas migraciones. Las apps no son writers; Odoo
 *   garantiza consistencia upstream.
 * - Todos los IDs son VARCHAR(255). UUID-strings caben sin pérdida; otros
 *   formatos (slugs académicos como `ST_BACH`) también.
 * - El nombre de la vista remota de `user_resolved_permissions` se lee de
 *   `config('database.fdw.user_permissions.remote_view')` — cada app
 *   declara su `v_<app>_user_permissions`. El portal (maya_dashboard) usa
 *   `v_portal_user_permissions` (vista en maya_auth con permisos de todas
 *   las apps) para `permissions[]` en GET /me.
 */
final class Migrations
{
    /**
     * Catálogo canónico de usuarios federado desde Odoo:
     *  - vista local `users` (proyectada de `maya_apps.v_app_users` de maya_erp vía FDW).
     *
     * Odoo solo se lee por vistas del esquema `maya_apps` (D17), que crea el despliegue de cada
     * app con `php artisan maya:views` (shared-platform-laravel); lo mismo en los demás grupos.
     *
     * Tabla física en testing, FDW + vista pass-through en local/staging/prod.
     * Fuente read-only: ninguna app del ecosistema escribe en estas filas.
     */
    public static function users(): string
    {
        return dirname(__DIR__).'/database/migrations/users';
    }

    /**
     * Asignaciones usuario↔ámbito académico:
     *  - `user_study_types`
     *  - `user_studies`
     *  - `user_course_modules`
     *
     * `user_id` es el `sub` de Keycloak, que en Odoo es `res_users.oauth_uid` (D26 R4);
     * desde 2026_10_14_000001, no `keycloak_user_id` (no existe en producción). Desde
     * 2026_10_15_000001 leen `maya_apps.v_app_user_*`, que ya resuelven el usuario en Odoo.
     *
     * Tablas en testing, source+FDW+vista en local, FDW remoto en prod.
     */
    public static function academicAssignments(): string
    {
        return dirname(__DIR__).'/database/migrations/academic-assignments';
    }

    /**
     * Catálogos académicos proyectados desde Odoo:
     *  - `study_types`    ← `res_company` filtrando `parent_id IS NOT NULL`
     *                       (compañías hija de CEEDCV: FP/BACH/FPA). El id es
     *                       `res_company.id` y matchea con
     *                       `user_study_types.study_type_id`.
     *  - `studies`        ← `maya_core_study` (id, code, study_type_id, name,
     *                       active). `study_type_id` se proyecta desde
     *                       `company_id` (FK a `res_company`), NO desde
     *                       `grade` — el campo `grade` (GS/GM/NG) no se usa
     *                       en el ecosistema Maya.
     *  - `course_modules` ← cross-FDW JOIN de `maya_core_subject` con
     *                       `maya_core_study_maya_core_subject_rel`. El id es
     *                       compuesto `{study_id}_{subject_id}` y matchea con
     *                       `user_course_modules.module_id`.
     *
     * Desde 2026_10_15_000001 las tablas foráneas leen las vistas `maya_apps.v_app_study_types`,
     * `v_app_studies`, `v_app_subjects` y `v_app_study_subjects` (mismas columnas).
     *
     * El catálogo de `teams` ya está cubierto por la migración existente
     * en `teams/`.
     *
     * Read-only. Cargar en el AppServiceProvider de la app que lo consuma.
     */
    public static function academicCatalogs(): string
    {
        return dirname(__DIR__).'/database/migrations/academic-catalogs';
    }

    /**
     * Catálogo y membresías de equipos:
     *  - `teams`
     *  - `team_members`
     */
    public static function teams(): string
    {
        return dirname(__DIR__).'/database/migrations/teams';
    }

    /**
     * Vista materializada de permisos resueltos
     * (`user_resolved_permissions`) consumida por `RequirePermissionMiddleware`
     * y por el campo `permissions[]` de `/me`.
     *
     * Apps que tengan su propio modelo de permisos (caso `maya_dms` con
     * `user_permissions.permission_code`) NO deben cargar este grupo —
     * mantienen sus migraciones propias.
     */
    public static function userPermissions(): string
    {
        return dirname(__DIR__).'/database/migrations/user-permissions';
    }

    /**
     * Catálogo de idiomas activos proyectado desde Odoo `res.lang`:
     *  - vista local `languages` (code, name, is_default).
     *
     * Tabla física sembrada (es/va/en) en testing; FDW + vista en local/prod.
     * Lo consume el endpoint `GET /api/v1/languages` (selector de idioma del
     * perfil y formularios multiidioma). Read-only.
     */
    public static function languages(): string
    {
        return dirname(__DIR__).'/database/migrations/languages';
    }
}
