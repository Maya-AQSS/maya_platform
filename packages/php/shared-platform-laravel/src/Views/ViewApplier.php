<?php

declare(strict_types=1);

namespace Maya\Platform\Views;

use Illuminate\Database\ConnectionInterface;

/**
 * Aplica las vistas de `maya_apps` en una sola transacción (D17): si una falla, no cambia ninguna.
 *
 * Por cada vista: `CREATE OR REPLACE VIEW`, `COMMENT ON VIEW` con su dueño y revisión, y
 * `GRANT SELECT` al lector de los FDW. Antes de nada fija `search_path = pg_catalog, pg_temp`
 * (las vistas califican todas sus tablas) y un `lock_timeout`, para no quedarse esperando detrás
 * de una consulta larga mientras bloquea las nuevas.
 *
 * El comentario evita dos problemas de que varias apps apliquen las mismas vistas comunes:
 *  - una app con una versión más vieja de maya_platform no pisa una vista con una revisión mayor
 *    (PostgreSQL no deja quitar columnas con CREATE OR REPLACE: fallaría su despliegue);
 *  - una app no puede redefinir una vista que mantiene otra (otro dueño): error.
 */
final class ViewApplier
{
    private const MARKER = 'maya:views';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly string $reader,
        private readonly string $lockTimeout = '15s',
    ) {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $reader) !== 1) {
            throw new \InvalidArgumentException("Rol lector no válido: {$reader}");
        }
        if (preg_match('/^\d+(ms|s|min)?$/', $lockTimeout) !== 1) {
            throw new \InvalidArgumentException("lock_timeout no válido: {$lockTimeout}");
        }
    }

    /**
     * @param  list<ViewDefinition>  $views  en orden de aplicación (las comunes primero)
     * @return array{applied: list<string>, skipped: list<string>}
     */
    public function apply(array $views): array
    {
        return $this->db->transaction(function () use ($views): array {
            $this->db->statement("SET LOCAL lock_timeout = '{$this->lockTimeout}'");
            $this->db->statement('SET LOCAL search_path = pg_catalog, pg_temp');

            $existing = $this->existing();
            $applied = [];
            $skipped = [];

            foreach ($views as $view) {
                $current = $existing[$view->name] ?? null;

                if ($current !== null && $current['owner'] !== null && $current['owner'] !== $view->owner) {
                    throw new InvalidViewFile(sprintf(
                        '%s es de «%s»; «%s» no puede redefinirla (%s).',
                        $view->qualifiedName(), $current['owner'], $view->owner, basename($view->file),
                    ));
                }

                if ($current !== null && $current['revision'] !== null && $current['revision'] > $view->revision) {
                    $skipped[] = sprintf('%s (revisión %d en la BD, %d aquí)', $view->name, $current['revision'], $view->revision);

                    continue;
                }

                $this->db->unprepared($view->sql);
                $this->db->statement(sprintf(
                    'COMMENT ON VIEW %s IS %s',
                    $view->qualifiedName(),
                    self::literal(self::marker($view->owner, $view->revision)),
                ));
                $this->db->statement(sprintf('GRANT SELECT ON %s TO %s', $view->qualifiedName(), $this->reader));
                $applied[] = $view->name;
            }

            return ['applied' => $applied, 'skipped' => $skipped];
        });
    }

    public static function marker(string $owner, int $revision): string
    {
        return sprintf('%s owner=%s revision=%d', self::MARKER, $owner, $revision);
    }

    /**
     * @return array{owner: string|null, revision: int|null}
     */
    public static function parseMarker(?string $comment): array
    {
        if ($comment !== null
            && preg_match('/^'.preg_quote(self::MARKER, '/').' owner=(.*) revision=(\d+)$/', $comment, $m) === 1) {
            return ['owner' => $m[1], 'revision' => (int) $m[2]];
        }

        return ['owner' => null, 'revision' => null];
    }

    /**
     * Vistas que ya hay en maya_apps, con el dueño y la revisión de su comentario.
     *
     * @return array<string, array{owner: string|null, revision: int|null}>
     */
    private function existing(): array
    {
        $rows = $this->db->select(
            "SELECT c.relname AS name, obj_description(c.oid, 'pg_class') AS comment
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = ? AND c.relkind = 'v'",
            [MayaAppsViews::SCHEMA],
        );

        $existing = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $existing[(string) $row['name']] = self::parseMarker(isset($row['comment']) ? (string) $row['comment'] : null);
        }

        return $existing;
    }

    private static function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
