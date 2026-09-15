<?php

declare(strict_types=1);

use App\Plugins\RrdFix\Support\PendingRun;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($message . PHP_EOL
            . 'Esperado: ' . var_export($expected, true) . PHP_EOL
            . 'Recibido: ' . var_export($actual, true));
    }
}

$basePath = dirname(__DIR__, 4);
$app = new Application($basePath);

// Regression del 404: la ruta oficial debe ganar frente al catch-all heredado.
$router = new Router(new Dispatcher($app), $app);
$router->post('plugin/settings/{plugin}', fn () => 'plugin update')->name('plugin.update');
$router->any('/{path?}', fn () => 'legacy 404')->where('path', '.*');
$matched = $router->getRoutes()->match(Request::create('/plugin/settings/RrdFix', 'POST'));
assertSameValue('plugin.update', $matched->getName(), 'El POST de RrdFix cayó en la ruta heredada.');

$view = file_get_contents(dirname(__DIR__) . '/resources/views/page.blade.php');
if (! str_contains((string) $view, "route('plugin.update'")
    || ! str_contains((string) $view, 'name="settings[rrd_fix_action]" value="run"')
    || ! str_contains((string) $view, 'name="settings[device]"')
    || str_contains((string) $view, 'plugin/RrdFix/run')) {
    throw new RuntimeException('El formulario no usa correctamente la ruta oficial del plugin.');
}

// El controlador oficial guarda settings; la página consume este contrato.
$storedSettings = [
    'rrd_fix_action' => 'run',
    'device' => '192.0.2.10',
    'start' => '2026-09-15T08:00',
    'end' => '2026-09-15T09:00',
    'availability' => 'down',
    'no_dbm' => '1',
    'scan_nan' => '1',
    'scan_start' => '2026-09-01T00:00',
    'scan_end' => '2026-09-15T23:59',
];
assertSameValue([
    'device' => '192.0.2.10',
    'start' => '2026-09-15T08:00',
    'end' => '2026-09-15T09:00',
    'availability' => 'down',
    'no_dbm' => true,
    'scan_nan' => true,
    'scan_start' => '2026-09-01T00:00',
    'scan_end' => '2026-09-15T23:59',
], PendingRun::fromSettings($storedSettings), 'La acción guardada no conservó los parámetros de RrdFix.');
assertSameValue(null, PendingRun::fromSettings([]), 'Una página normal intentaría ejecutar RrdFix.');
assertSameValue(null, PendingRun::fromSettings(['rrd_fix_action' => 'other']), 'Se aceptó una acción desconocida.');

echo "PASS: ruta oficial, precedencia sobre 404, formulario y consumo seguro de parámetros.\n";
