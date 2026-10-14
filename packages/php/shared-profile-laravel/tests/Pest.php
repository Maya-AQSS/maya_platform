<?php

use Tests\TestCase;

// Las migraciones usan Maya\Platform\Database\PostgresFdwMigration (shared-platform-laravel,
// que este paquete no instala): en los tests, un doble con el mismo SQL.
if (! class_exists(\Maya\Platform\Database\PostgresFdwMigration::class)) {
    require_once __DIR__.'/Stubs/PostgresFdwMigration.php';
}

uses(TestCase::class)->in('Unit');
