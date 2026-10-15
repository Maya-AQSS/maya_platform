<?php

/*
| Vistas de lectura de las apps en el esquema maya_apps de maya_erp (D17): `php artisan maya:views`.
| Las crea el rol maya_apps_views (dueño de las vistas); maya_fdw_reader (el de las tablas foráneas)
| solo puede leerlas. Host, puerto y BD son los de Odoo que ya usan las apps (FDW_ODOO_*); la
| contraseña llega del Secret / Vault (clave MAYA_APPS_VIEWS_PASSWORD), solo al Job `views`.
*/
return [
    // Conexión con la que se aplican las vistas. Si la app no la define en config/database.php, se
    // usa `database` de aquí.
    'connection' => env('MAYA_APPS_DB_CONNECTION', 'maya_apps'),

    'database' => [
        'driver' => 'pgsql',
        'host' => env('MAYA_APPS_DB_HOST', env('FDW_ODOO_HOST', env('DB_HOST', '127.0.0.1'))),
        'port' => env('MAYA_APPS_DB_PORT', env('FDW_ODOO_PORT', '5432')),
        'database' => env('MAYA_APPS_DB_DATABASE', env('FDW_ODOO_DATABASE', 'maya_erp')),
        'username' => env('MAYA_APPS_DB_USERNAME', 'maya_apps_views'),
        'password' => env('MAYA_APPS_VIEWS_PASSWORD', ''),
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'maya_apps',
        'sslmode' => env('MAYA_APPS_DB_SSLMODE', env('DB_SSLMODE', 'prefer')),
    ],

    // Rol al que se concede SELECT sobre cada vista (el usuario de los servidores FDW hacia maya_erp).
    'reader' => env('MAYA_APPS_READER_ROLE', 'maya_fdw_reader'),

    // Vistas de la app: null = database/views.
    'path' => null,

    // Quién mantiene las vistas de la app (va en su comentario): null = config('app.name').
    'owner' => env('MAYA_VIEWS_OWNER'),

    // Espera máxima por los bloqueos de las vistas que se reemplazan.
    'lock_timeout' => env('MAYA_APPS_LOCK_TIMEOUT', '15s'),
];
