<?php

declare(strict_types=1);

namespace Maya\Platform\Views;

/**
 * Una vista de lectura de `maya_apps` (D17), leída de un fichero `.sql`.
 */
final readonly class ViewDefinition
{
    /**
     * @param  string  $name  nombre de la vista, sin esquema (= nombre del fichero)
     * @param  string  $sql  la sentencia `CREATE OR REPLACE VIEW maya_apps.<name> …`, sin comentarios ni `;`
     * @param  int  $revision  `-- revision: N` del fichero (1 si no lo indica)
     * @param  string  $owner  quién la mantiene: `maya_platform` (comunes) o el nombre de la app
     * @param  string  $file  ruta del fichero
     */
    public function __construct(
        public string $name,
        public string $sql,
        public int $revision,
        public string $owner,
        public string $file,
    ) {}

    public function qualifiedName(): string
    {
        return MayaAppsViews::SCHEMA.'.'.$this->name;
    }
}
