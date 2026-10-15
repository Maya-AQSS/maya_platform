<?php

declare(strict_types=1);

namespace Maya\Platform\Views;

/**
 * Un fichero de vistas no cumple el contrato de `maya:views` (D17). Se detecta antes de conectar.
 */
final class InvalidViewFile extends \RuntimeException
{
}
