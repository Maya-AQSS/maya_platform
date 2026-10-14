<?php

declare(strict_types=1);

namespace Maya\Platform\Database;

use Illuminate\Support\Facades\DB;

/**
 * Doble de prueba de `Maya\Platform\Database\PostgresFdwMigration`
 * (ceedcv-maya/shared-platform-laravel). Las migraciones de este paquete lo usan, pero
 * en las apps llega por shared-platform-laravel y aquí no está instalado. Reproduce el
 * SQL del original para lo que usan las migraciones probadas. tests/Pest.php lo carga
 * solo si la clase real no existe.
 */
final class PostgresFdwMigration
{
    public static function ensurePostgresFdwExtension(?string $catalogLabel = null): bool
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgres_fdw');

        return true;
    }

    public static function dropForeignTableIfExists(string $fdwTableName): void
    {
        DB::statement('DROP FOREIGN TABLE IF EXISTS '.$fdwTableName.' CASCADE');
    }
}
