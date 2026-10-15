<?php

declare(strict_types=1);

namespace Maya\Platform\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Maya\Platform\Views\InvalidViewFile;
use Maya\Platform\Views\MayaAppsViews;
use Maya\Platform\Views\ViewApplier;
use Maya\Platform\Views\ViewDefinition;

/**
 * Crea o actualiza las vistas de lectura de la app en `maya_apps` de `maya_erp` (D17).
 *
 * Aplica, en una transacción, las vistas comunes de shared-platform-laravel y las de la app
 * (`database/views/*.sql`), conectando como `maya_apps_views` (conexión `maya_apps`, ver
 * config/maya-views.php). Lo lanza el Job `views` del chart maya-common antes de `migrate`.
 */
class ApplyMayaAppsViews extends Command
{
    protected $signature = 'maya:views
        {--path= : Directorio de las vistas de la app (por defecto database/views)}
        {--connection= : Conexión de BD (por defecto maya-views.connection, «maya_apps»)}
        {--dry-run : Valida los ficheros y muestra el SQL sin conectar}';

    protected $description = 'Crea o actualiza las vistas de lectura de la app en maya_apps de maya_erp (D17)';

    public function handle(): int
    {
        try {
            $views = $this->views();
        } catch (InvalidViewFile $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            foreach ($views as $view) {
                $this->line(sprintf('-- %s (%s, revisión %d): %s', $view->qualifiedName(), $view->owner, $view->revision, $view->file));
                $this->line($view->sql.';');
                $this->line('GRANT SELECT ON '.$view->qualifiedName().' TO '.$this->reader().';');
            }
            $this->info(sprintf('%d vistas válidas (sin conectar).', count($views)));

            return self::SUCCESS;
        }

        $name = (string) ($this->option('connection') ?: config('maya-views.connection', 'maya_apps'));
        if (config("database.connections.{$name}") === null) {
            config(["database.connections.{$name}" => config('maya-views.database')]);
        }
        if (config("database.connections.{$name}.driver") !== 'pgsql') {
            $this->error("La conexión {$name} no es pgsql.");

            return self::FAILURE;
        }

        try {
            $applier = new ViewApplier(
                DB::connection($name),
                $this->reader(),
                (string) config('maya-views.lock_timeout', '15s'),
            );
            $result = $applier->apply($views);
        } catch (\Throwable $e) {
            $this->error(MayaAppsViews::SCHEMA.': no se ha cambiado ninguna vista (transacción deshecha). '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($result['skipped'] as $skipped) {
            $this->warn('Se conserva la de la BD, más nueva: '.$skipped);
        }
        $this->info(sprintf(
            '%s: %d vistas aplicadas (%s).',
            MayaAppsViews::SCHEMA,
            count($result['applied']),
            implode(', ', $result['applied']),
        ));

        return self::SUCCESS;
    }

    /**
     * Las comunes primero (las de la app pueden usarlas), después las de la app.
     *
     * @return list<ViewDefinition>
     */
    private function views(): array
    {
        $common = MayaAppsViews::fromDirectory(MayaAppsViews::commonPath(), MayaAppsViews::COMMON_OWNER);
        $path = (string) ($this->option('path') ?: config('maya-views.path') ?: database_path('views'));
        $app = MayaAppsViews::fromDirectory($path, $this->owner());

        $commonNames = array_map(static fn (ViewDefinition $v): string => $v->name, $common);
        foreach ($app as $view) {
            if (in_array($view->name, $commonNames, true)) {
                throw new InvalidViewFile(sprintf(
                    '%s: %s es una vista común de maya_platform; la app no puede redefinirla.',
                    basename($view->file), $view->qualifiedName(),
                ));
            }
        }

        return [...$common, ...$app];
    }

    private function owner(): string
    {
        $owner = trim((string) (config('maya-views.owner') ?: config('app.name', 'app')));

        return $owner === '' || $owner === MayaAppsViews::COMMON_OWNER ? 'app' : $owner;
    }

    private function reader(): string
    {
        return (string) config('maya-views.reader', 'maya_fdw_reader');
    }
}
