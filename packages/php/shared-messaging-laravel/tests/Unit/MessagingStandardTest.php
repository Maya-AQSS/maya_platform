<?php

/*
 * Estándar de mensajería Maya: el paquete y el catálogo (maya_platform/messaging/topology.yaml)
 * tienen que decir lo mismo. Si se añade o renombra un exchange o una cola en uno solo de los
 * dos sitios, este test falla. Solo corre dentro del monorepo (en el repo partido para Packagist
 * no existe el catálogo).
 */

$topologyPath = dirname(__DIR__, 5) . '/messaging/topology.yaml';

/** @return array{exchanges: list<string>, queues: list<string>} */
function mayaTopologyNames(string $path): array
{
    $section = null;
    $names = ['exchanges' => [], 'queues' => []];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^([a-z_]+):\s*$/', $line, $m)) {
            $section = $m[1];
            continue;
        }
        if (isset($names[$section]) && preg_match('/^  ([a-z0-9_.-]+):\s*$/', $line, $m)) {
            $names[$section][] = $m[1];
        }
    }

    return $names;
}

it('declares in config every queue of the standard catalog, with the same name', function () use ($topologyPath) {
    $catalog = mayaTopologyNames($topologyPath)['queues'];
    $configured = array_values(config('messaging.queues'));

    sort($catalog);
    sort($configured);
    expect($configured)->toBe($catalog);
})->skip(fn () => ! is_file($topologyPath), 'fuera del monorepo maya_platform');

it('publishes only to exchanges of the standard catalog', function () use ($topologyPath) {
    $catalog = mayaTopologyNames($topologyPath)['exchanges'];
    $configured = config('messaging.exchanges');

    // maya.alerts existe en el paquete (AlertPublisher) pero ninguna app lo usa todavía: entra en
    // el catálogo cuando la primera app publique alertas (ESTANDAR.md § Añadir un exchange).
    unset($configured['alerts']);

    foreach ($configured as $exchange) {
        expect($catalog)->toContain($exchange);
    }
})->skip(fn () => ! is_file($topologyPath), 'fuera del monorepo maya_platform');
