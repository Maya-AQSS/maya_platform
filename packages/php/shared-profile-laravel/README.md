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

- `Migrations::academicAssignments()`: `user_study_types`, `user_studies` and `user_course_modules` join `res_users` by `oauth_uid` (`user_id` = `sub`). Migration `2026_10_14_000001_link_academic_assignments_by_oauth_uid` redefines the shared foreign table `res_users_kc_fdw` and the three views on databases where the older migrations already ran; on a fresh install it runs after them with the same result. Apps only need `php artisan migrate`.
- `Migrations::users()`: the local `users` view reads the remote view `v_app_users`, which lives in Odoo's database (not in this package). Its `id` column must be `res_users.oauth_uid`.


## TypeScript / build notes
PSR-4 autoload from `src/`. Service providers are registered via Laravel package discovery (no manual provider registration needed).

## License

MIT — see [LICENSE](LICENSE).

## Reporting issues

The canonical source lives in [Maya-AQSS/maya_platform](https://github.com/Maya-AQSS/maya_platform). File issues there; this read-only split repo is only the published artifact.
