# ceedcv-maya/shared-profile-laravel

User profile endpoints for Laravel + Keycloak: GET /me, PUT /me/locale, configurable resolvers, FormRequest validation.

Part of the [ceedcv-maya/maya_platform](https://github.com/Maya-AQSS/maya_platform) mono-repo. Distributed independently for reuse outside the Maya ecosystem.

## Installation

```bash
composer require ceedcv-maya/shared-profile-laravel
```

Adds `GET /me` and `PUT /me/locale` endpoints to your Laravel app. Resolver is configurable to bind to your user model.

```php
// config/profile.php
return [
    'user_resolver' => \App\Resolvers\KeycloakUserResolver::class,
    'locale_writer' => \App\Resolvers\DbLocaleWriter::class,
];
```

## Keycloak ↔ Odoo user link

The Keycloak user id (the JWT `sub`) is stored in Odoo as **`res_users.oauth_uid`** (set by Odoo's Keycloak login and by user-worker). It is the only link key, in every environment. The old `res_users.keycloak_user_id` column only existed in development databases and is no longer read.

- `Migrations::academicAssignments()`: `user_study_types`, `user_studies` and `user_course_modules` (`user_id` = `sub`). Since `2026_10_15_000001_read_academic_assignments_from_maya_apps` they read the views `maya_apps.v_app_user_*`, which already resolve the user by `oauth_uid` on Odoo's side; the older foreign tables on Odoo tables (`res_users_kc_fdw`…) are dropped. Apps only need `php artisan migrate`.
- `Migrations::users()`: the local `users` view reads `maya_apps.v_app_users` (`id` = `res_users.oauth_uid`).

## Odoo is read through `maya_apps` views only (D17)

Every foreign table towards Odoo (`odoo_server`) reads a view of the `maya_apps` schema in `maya_erp`, never an Odoo table: the FDW user (`maya_fdw_reader`) can only read that schema. The common views live in `ceedcv-maya/shared-platform-laravel` (`database/views`) and each app's deployment creates them with `php artisan maya:views` (see its README). The `2026_10_15_000001_*` migrations of each group repoint the foreign tables (`users`, `teams`, `academic-catalogs`, `languages`: `ALTER FOREIGN TABLE … OPTIONS (SET schema_name 'maya_apps', SET table_name …)` with the same columns; `academic-assignments`: new foreign tables). Deploy them together with the views job (maya-common ≥ 0.3.0, `views.enabled: true`): without the views, the foreign tables fail when queried.


## TypeScript / build notes
PSR-4 autoload from `src/`. Service providers are registered via Laravel package discovery (no manual provider registration needed).

## License

MIT — see [LICENSE](LICENSE).

## Reporting issues

The canonical source lives in [Maya-AQSS/maya_platform](https://github.com/Maya-AQSS/maya_platform). File issues there; this read-only split repo is only the published artifact.
