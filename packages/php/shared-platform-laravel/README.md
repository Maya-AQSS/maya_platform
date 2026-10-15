# ceedcv-maya/shared-platform-laravel

Cross-cutting platform utilities for Laravel microservices: PostgreSQL FDW migrations helpers, locale providers, shared contracts.

Part of the [ceedcv-maya/maya_platform](https://github.com/Maya-AQSS/maya_platform) mono-repo. Distributed independently for reuse outside the Maya ecosystem.

## Installation

```bash
composer require ceedcv-maya/shared-platform-laravel
```

Provides PostgreSQL FDW migration helpers and locale resolution traits used across Laravel microservices.

## Vistas de lectura sobre Odoo: esquema `maya_apps` (D17)

Las apps leen Odoo por `postgres_fdw`, **solo a través de vistas** del esquema `maya_apps` de la BD de
Odoo (`maya_erp`), nunca sus tablas. Cada vista es de la app que la usa y se crea en su despliegue;
las que usan varias apps son comunes y viven en este paquete.

| Rol | Qué puede hacer |
|---|---|
| `maya_apps_views` | Dueño de las vistas: lee las tablas de Odoo (menos las de secretos y las columnas `password`, `totp_secret` y `oauth_access_token` de `res_users`) y solo puede crear en `maya_apps`. Lo usa el Job `views` de cada app. |
| `maya_fdw_reader` | Usuario de los servidores FDW de las apps hacia `maya_erp`: solo `SELECT` sobre `maya_apps` (la vista se ejecuta con los permisos de su dueño). Ninguna tabla de Odoo, ninguna escritura (D21). |

En producción los crea el playbook `05.5_setup_databases.yml` (etiqueta `maya_apps`); en desarrollo, `IaC/dev/sql`.

**Dónde está cada vista.** Comunes: `database/views/` de este paquete. Propias: `database/views/` de la
app. Todas devuelven el usuario como `res_users.oauth_uid` (el `sub` de Keycloak, D26 R4) y están
escritas sobre las tablas del Odoo 19 de producción (`maya_core` 945144d, `maya_attendance` 17caa70).

| Vista común | La lee (shared-profile-laravel) | Sobre |
|---|---|---|
| `v_app_users` | `users_fdw` → `users` | `maya_core_employee`, `res_users`, `res_partner` |
| `v_dms_teams` | `teams_fdw` → `teams` | `maya_core_team` (`owner_id` = oauth_uid de `create_uid`) |
| `v_dms_team_members` | `team_members_fdw` → `team_members` | `maya_core_employee_maya_core_team_rel` |
| `v_app_user_study_types` | `user_study_types_fdw` → `user_study_types` | `res_company_users_rel`, `res_company`, `res_users` |
| `v_app_user_studies` | `user_studies_fdw` → `user_studies` | ídem + `maya_core_study` |
| `v_app_user_course_modules` | `user_course_modules_fdw` → `user_course_modules` | `maya_core_subject_employee_rel`, `maya_core_employee` |
| `v_app_study_types` | `res_company_fdw` → `study_types` | `res_company` (hijas) |
| `v_app_studies` | `studies_fdw` → `studies` | `maya_core_study` (activos) |
| `v_app_subjects` | `course_modules_subject_fdw` → `course_modules` | `maya_core_subject` |
| `v_app_study_subjects` | `course_modules_rel_fdw` → `course_modules` | `maya_core_study_maya_core_subject_rel` |
| `v_app_languages` | `res_lang_fdw` → `languages` | `res_lang` (activos) |

Propias de una app, hoy solo del dashboard: `v_app_employee_profile` y `v_app_attendances` (tramos de
entrada/salida a partir de las marcas de `maya_attendance`). `v_app_bookings` no existe hasta que
`maya_booking` tenga una versión publicada (D27).

**Contrato de un fichero.** `<vista>.sql` (o `NN_<vista>.sql` para fijar el orden) con **una sola**
sentencia `CREATE OR REPLACE VIEW maya_apps.<vista> AS …`, todas las tablas calificadas
(`public.res_users`, `maya_apps.v_…`: se aplica con `search_path = pg_catalog`) y, opcional, una línea
`-- revision: N` (1 si falta). Una app no puede redefinir una vista común.

**Cómo se aplican.** `php artisan maya:views` (`--dry-run` valida y muestra el SQL sin conectar;
`--path`, `--connection`): en **una transacción** aplica las comunes y después las de la app; por cada
una, `CREATE OR REPLACE VIEW`, `COMMENT ON VIEW` (`maya:views owner=<app> revision=N`) y
`GRANT SELECT … TO maya_fdw_reader`. Si una falla, no cambia ninguna. Conecta con la conexión
`maya_apps` (`config/maya-views.php`, si la app no la define): host, puerto y BD de `FDW_ODOO_*` (o
`MAYA_APPS_DB_*`), usuario `maya_apps_views` y contraseña `MAYA_APPS_VIEWS_PASSWORD`. En k8s lo lanza el
Job `views` de maya-common ≥ 0.3.0 (`views.enabled: true` y la clave en `vault.keys`), antes de `migrate`.

- **Revisión**: varias apps aplican las mismas vistas comunes, cada una con su versión de maya_platform.
  Si la BD tiene una revisión mayor (la puso una app con un maya_platform más nuevo), se conserva y se
  avisa. Una vista común solo cambia **añadiendo columnas al final** y subiendo `revision`.
- **Dueño**: la vista de una app no la puede redefinir otra (error).
- **Cambiar la forma de una vista** (renombrar o cambiar el tipo de una columna, quitarla): PostgreSQL
  no lo permite con `CREATE OR REPLACE`. O una vista nueva (`v_…_v2`) y la tabla foránea a ella en una
  migración, o borrar la vieja a mano (`DROP VIEW maya_apps.…`) justo antes del despliegue.
- **Actualizar módulos de Odoo** (D17): PostgreSQL no deja cambiar una columna de la que depende una
  vista. Antes, en desarrollo y con las apps; en producción, **borrar las vistas de `maya_apps`**, actualizar
  los módulos y volver a desplegar las apps (`helm upgrade` vuelve a pasar el Job `views`):

  ```sql
  -- como postgres, en maya_erp
  DO $$ DECLARE v record; BEGIN
    FOR v IN SELECT format('%I.%I', schemaname, viewname) AS n FROM pg_views WHERE schemaname = 'maya_apps' LOOP
      EXECUTE 'DROP VIEW IF EXISTS ' || v.n || ' CASCADE';
    END LOOP;
  END $$;
  ```

  Mientras tanto las apps no leen Odoo (las consultas FDW fallan): hacerlo en la ventana de mantenimiento.

**Tablas foráneas.** Las migraciones `2026_10_15_000001_*` de shared-profile-laravel (grupos `users`,
`teams`, `academic-catalogs`, `languages`: `ALTER FOREIGN TABLE … OPTIONS (SET schema_name 'maya_apps',
SET table_name …)`; `academic-assignments`: tablas foráneas nuevas sobre las vistas `v_app_user_*`) y las
de cada app las apuntan a `maya_apps`. Las vistas locales no cambian de columnas.


## AbstractFdwRepository

Base class for repositories that provide read-only access to PostgreSQL Foreign Data Wrapper tables. Extend it and implement `modelClass()` to get `findById`, `findByIdOrFail`, `exists`, `pluckForFilter`, and `all` for free.

```php
class StudyTypeRepository extends AbstractFdwRepository
{
    protected function modelClass(): string
    {
        return StudyType::class;
    }
}
```

### Excepciones documentadas

The following code sites are **exempt** from the mandatory repository-layer rule and may access Eloquent/DB directly:

- `AppServiceProvider::boot()` — guard/driver registration (`Auth::viaRequest`, `Auth::extend`) must run in boot, before the repository layer is available.
- Keycloak / JWT user resolver closures — resolved during the authentication bootstrap, before the HTTP kernel dispatches to a controller.
- Database connection bootstrap — `database.php` config and initial connection establishment happen before the service container is fully wired.

These exemptions are architectural and intentional; do not expand them without an explicit ADR.

## TypeScript / build notes
PSR-4 autoload from `src/`. Service providers are registered via Laravel package discovery (no manual provider registration needed).

## License

MIT — see [LICENSE](LICENSE).

## Reporting issues

The canonical source lives in [Maya-AQSS/maya_platform](https://github.com/Maya-AQSS/maya_platform). File issues there; this read-only split repo is only the published artifact.
