<?php

declare(strict_types=1);

namespace Maya\Platform\Views;

/**
 * Vistas de lectura sobre Odoo en el esquema `maya_apps` de `maya_erp` (D17).
 *
 * Cada vista es un fichero `<nombre>.sql` (opcionalmente `NN_<nombre>.sql` para fijar el orden) con
 * una sola sentencia `CREATE OR REPLACE VIEW maya_apps.<nombre> AS …`, con todas las tablas
 * calificadas (`public.res_users`, `maya_apps.v_…`): se aplican con `search_path = pg_catalog`.
 * Las comunes están en este paquete (`database/views`); las de cada app, en su `database/views`.
 * Las aplica `php artisan maya:views` (Job `views` del chart maya-common), ver README.
 */
final class MayaAppsViews
{
    public const SCHEMA = 'maya_apps';

    /** Dueño de las vistas comunes (las de este paquete). */
    public const COMMON_OWNER = 'maya_platform';

    /** Directorio de las vistas comunes. */
    public static function commonPath(): string
    {
        return dirname(__DIR__, 2).'/database/views';
    }

    /**
     * Las vistas de un directorio, ordenadas por nombre de fichero. Un directorio que no existe no
     * tiene vistas.
     *
     * @return list<ViewDefinition>
     */
    public static function fromDirectory(string $dir, string $owner): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = glob(rtrim($dir, '/\\').'/*.sql') ?: [];
        sort($files, SORT_STRING);

        $views = [];
        $seen = [];
        foreach ($files as $file) {
            $view = self::fromFile($file, $owner);
            if (isset($seen[$view->name])) {
                throw new InvalidViewFile(sprintf(
                    '%s y %s definen la misma vista %s', basename($seen[$view->name]), basename($file), $view->qualifiedName(),
                ));
            }
            $seen[$view->name] = $file;
            $views[] = $view;
        }

        return $views;
    }

    public static function fromFile(string $file, string $owner): ViewDefinition
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            throw new InvalidViewFile("No se puede leer {$file}");
        }

        $base = basename($file);
        $expected = (string) preg_replace('/^\d+[_-]/', '', basename($file, '.sql'));
        [$code, $semicolons] = self::stripComments($raw);
        $code = trim($code);
        if (str_ends_with($code, ';')) {
            $code = rtrim(substr($code, 0, -1));
            $semicolons--;
        }

        if ($semicolons > 0) {
            throw new InvalidViewFile("{$base}: una sola sentencia por fichero (CREATE OR REPLACE VIEW).");
        }

        $pattern = '/^CREATE\s+OR\s+REPLACE\s+VIEW\s+'.self::SCHEMA.'\.([a-z_][a-z0-9_]*)(?=[\s(])/i';
        if (! preg_match($pattern, $code, $m)) {
            throw new InvalidViewFile(
                "{$base}: debe ser «CREATE OR REPLACE VIEW ".self::SCHEMA.".<nombre> AS …» (sin TEMP, RECURSIVE ni otro esquema)."
            );
        }

        $name = strtolower($m[1]);
        if ($name !== $expected) {
            throw new InvalidViewFile("{$base}: crea ".self::SCHEMA.".{$name}; el fichero tiene que llamarse {$name}.sql.");
        }

        $revision = preg_match('/^\s*--\s*revision:\s*(\d+)\s*$/mi', $raw, $r) === 1 ? (int) $r[1] : 1;

        return new ViewDefinition($name, $code, $revision, $owner, $file);
    }

    /**
     * Quita los comentarios (`--` y `/* *\/`) respetando literales, identificadores entre comillas y
     * cadenas `$tag$`. Devuelve el SQL sin comentarios y cuántos `;` hay fuera de ellos.
     *
     * @return array{0: string, 1: int}
     */
    public static function stripComments(string $sql): array
    {
        $out = '';
        $semicolons = 0;
        $len = strlen($sql);
        $i = 0;

        while ($i < $len) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($c === '-' && $next === '-') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;

                continue;
            }

            if ($c === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                $out .= ' ';

                continue;
            }

            if ($c === "'" || $c === '"') {
                $j = $i + 1;
                while ($j < $len) {
                    if ($sql[$j] === $c) {
                        if (($sql[$j + 1] ?? '') === $c) {
                            $j += 2;

                            continue;
                        }
                        break;
                    }
                    $j++;
                }
                $out .= substr($sql, $i, $j - $i + 1);
                $i = $j + 1;

                continue;
            }

            if ($c === '$' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $m, 0, $i) === 1) {
                $tag = $m[0];
                $end = strpos($sql, $tag, $i + strlen($tag));
                $j = $end === false ? $len : $end + strlen($tag);
                $out .= substr($sql, $i, $j - $i);
                $i = $j;

                continue;
            }

            if ($c === ';') {
                $semicolons++;
            }

            $out .= $c;
            $i++;
        }

        return [$out, $semicolons];
    }
}
