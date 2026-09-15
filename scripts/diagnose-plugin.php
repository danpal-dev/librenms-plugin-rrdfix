#!/usr/bin/env php
<?php
/**
 * ============================================================================
 * 🔍 LibreNMS Plugin DIAGNOSE — compara RrdFix contra plugin de referencia
 * (por defecto Reports, que SI funciona) y te dice EXACTAMENTE qué difiere.
 *
 * Uso en el servidor destino (p.ej. moni2):
 *   cd /opt/librenms
 *   sudo -u librenms php app/Plugins/RrdFix/scripts/diagnose-plugin.php
 *
 * Puedes pasar otro plugin como referencia:
 *   sudo -u librenms php app/Plugins/RrdFix/scripts/diagnose-plugin.php MigrateRrdRetention
 * ============================================================================
 */
declare(strict_types=1);

$librenmsDir = dirname(__DIR__, 4);   // ... /opt/librenms
$autoload = $librenmsDir . '/vendor/autoload.php';
if (! is_file($autoload)) {
    $librenmsDir = '/opt/librenms';
    $autoload = $librenmsDir . '/vendor/autoload.php';
}
if (! is_file($autoload)) {
    fwrite(STDERR, "No se encontró vendor/autoload.php. Ejecuta desde /opt/librenms.\n");
    exit(2);
}
require $autoload;

$boot = $librenmsDir . '/bootstrap/app.php';
if (is_file($boot)) {
    $app = require $boot;
    if (method_exists($app, 'make')) {
        try {
            $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
            $kernel->bootstrap();
        } catch (Throwable) {
            // No importa si no se puede boot Laravel — hacemos diagnóstico PHP puro.
        }
    }
}

$target   = 'RrdFix';
$refName  = $argv[1] ?? (is_dir($librenmsDir . '/app/Plugins/Reports') ? 'Reports' : 'FlowbiteTheme');

echo "============================================================\n";
echo "🔍 Plugin Diagnose · target={$target}   reference={$refName}\n";
echo "============================================================\n";

// -------- helpers -----------------------------------------------------------
$ok    = fn(string $m) => "   \033[1;32m[ OK ]\033[0m  {$m}";
$warn  = fn(string $m) => "   \033[1;33m[WARN]\033[0m  {$m}";
$err   = fn(string $m) => "   \033[1;31m[ERR ]\033[0m  {$m}";
$diff  = fn(string $m) => "   \033[1;35m[DIFF]\033[0m  {$m}";

function sigsFor(string $plugin, string $librenmsDir): array {
    $out = [];
    foreach (['Settings', 'Menu', 'Page', 'DeviceOverview'] as $hook) {
        $file = "{$librenmsDir}/app/Plugins/{$plugin}/{$hook}.php";
        if (! is_file($file)) {
            continue;
        }
        $class = "App\\Plugins\\{$plugin}\\{$hook}";
        if (! class_exists($class)) {
            require_once $file;
        }
        if (! class_exists($class)) {
            $out[$hook] = ['error' => "clase {$class} no cargable"];
            continue;
        }
        $rf = new ReflectionClass($class);
        foreach (['authorize', 'data'] as $m) {
            if (! $rf->hasMethod($m)) {
                $out[$hook][$m] = 'MÉTODO FALTANTE';
                continue;
            }
            $rm = $rf->getMethod($m);
            $params = [];
            foreach ($rm->getParameters() as $p) {
                $type = $p->getType() instanceof ReflectionNamedType
                    ? $p->getType()->getName()
                    : ($p->getType() ? (string)$p->getType() : 'mixed');
                $nullable = $p->getType() instanceof ReflectionNamedType && $p->getType()->allowsNull() ? '?' : '';
                $default  = $p->isOptional()
                    ? (' = ' . var_export($p->isDefaultValueAvailable() ? $p->getDefaultValue() : null, true))
                    : '';
                $params[] = "{$nullable}{$type} \${$p->getName()}{$default}";
            }
            $return = $rm->getReturnType() instanceof ReflectionNamedType ? ': ' . $rm->getReturnType()->getName() : '';
            $out[$hook][$m] = "{$m}(" . implode(', ', $params) . "){$return}";
        }
    }
    return $out;
}

// -------- 1. archivos -------------------------------------------------------
echo "\n1. Estructura de directorios:\n";
foreach ([$target, $refName] as $p) {
    $d = "{$librenmsDir}/app/Plugins/{$p}";
    if (! is_dir($d)) {
        echo $err("{$p}: directorio no existe en {$d}");
        continue;
    }
    $phpTop = glob("{$d}/*.php");
    $owner  = posix_getpwuid(fileowner($d))['name'] ?? '?';
    $writable = is_writable($d) ? 'si' : 'no';
    echo $ok("{$p}: existe · owner={$owner} · writable={$writable} · " . count($phpTop) . " archivos hook");
    foreach ($phpTop as $f) {
        echo "      + " . basename($f) . "\n";
    }
}

// -------- 2. DB -------------------------------------------------------------
echo "\n2. Base de datos (plugins tabla):\n";
try {
    $rows = \App\Models\Plugin::whereIn('plugin_name', [$target, $refName])
        ->get(['plugin_name', 'plugin_active', 'version', 'plugin_id']);
    foreach ($rows as $r) {
        $active = (int) $r->plugin_active === 1 ? 'ACTIVO' : 'DESACTIVADO';
        $color  = (int) $r->plugin_active === 1 ? 'ok' : 'err';
        echo($$color)("{$r->plugin_name}: {$active} · version={$r->version} · id={$r->plugin_id}");
    }
    foreach ([$target, $refName] as $p) {
        if (! $rows->contains('plugin_name', $p)) {
            echo $err("{$p}: NO EXISTE en tabla plugins");
        }
    }
} catch (Throwable $e) {
    echo $warn("No se pudo consultar DB: " . $e::class . ' ' . $e->getMessage());
}

// -------- 3. Signatures -----------------------------------------------------
echo "\n3. Signatures de métodos hooks (authorize / data):\n";
$tgt = sigsFor($target, $librenmsDir);
$ref = sigsFor($refName,  $librenmsDir);
$allHooks = array_unique(array_merge(array_keys($tgt), array_keys($ref)));
sort($allHooks);
foreach ($allHooks as $h) {
    echo "   [{$h}]\n";
    foreach (['authorize', 'data'] as $m) {
        $t = $tgt[$h][$m] ?? '(no aplica)';
        $r = $ref[$h][$m] ?? '(no aplica)';
        if ($t === '(no aplica)' && $r === '(no aplica)') {
            continue;
        }
        if ($t === $r) {
            echo "     {$m}: " . $ok('idéntica: ' . $t) . "\n";
        } else {
            echo "     {$m}: " . $diff("{$target} → {$t}") . "\n";
            echo "           " . $diff("{$refName} → {$r}") . "\n";
        }
    }
}

// -------- 4. PluginManager --------------------------------------------------
echo "\n4. PluginManager · hooks publicados + namespace views:\n";
try {
    $manager = app(\LibreNMS\Interfaces\Plugins\PluginManagerInterface::class);
    $hooksClasses = [
        'Settings'    => \LibreNMS\Interfaces\Plugins\Hooks\SettingsHook::class,
        'Menu'        => \LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook::class,
        'Page'        => \LibreNMS\Interfaces\Plugins\Hooks\SinglePageHook::class,
    ];
    foreach ([$target, $refName] as $p) {
        $enabled = $manager->pluginEnabled($p) ? 'true' : 'FALSE';
        echo "   {$p}: enabled={$enabled} · " . ($enabled === 'true' ? 'ok' : 'ERR') . "\n";
        foreach ($hooksClasses as $label => $iface) {
            // hasHooks(string $hookType, array $args, ?string $onlyPlugin): bool
            $has = $manager->hasHooks($iface, []) ? '?' : 'NO public';
            // Mejor: contar hooksFor (acepta plugin en 3er arg)
            try {
                $count = $manager->hooksFor($iface, [], $p)->count();
                $has = $count > 0 ? "publicado ({$count})" : 'NO public (0)';
            } catch (Throwable) {
                // ignore
            }
            echo "      hook {$label}: {$has}\n";
        }
    }
    // Verifica namespace registrado
    $finder = app('view')->getFinder();
    $rf = new ReflectionObject($finder);
    $nsViewsProp = $rf->getProperty('hints');
    $nsViewsProp->setAccessible(true);
    $hints = $nsViewsProp->getValue($finder);
    foreach ([$target, $refName] as $p) {
        $ok = isset($hints[$p]) && count($hints[$p]) > 0;
        $paths = $ok ? implode(', ', $hints[$p]) : '(sin registrar)';
        echo $ok ? "   views namespace: {$p} = {$paths}\n" : $err("views namespace {$p} NO REGISTRADO! PluginManager no publicó ningún hook") . "\n";
    }
} catch (Throwable $e) {
    echo $warn("No se pudo inspeccionar PluginManager: " . $e::class . ' ' . $e->getMessage());
}

// -------- 5. Ruta rrdfix.status --------------------------------------------------
echo "\n5. Ruta 'rrdfix.status' registrada:\n";
try {
    $router = app('router');
    $found = false;
    foreach ($router->getRoutes() as $rt) {
        if ($rt->getName() === 'rrdfix.status') {
            $found = true;
            echo $ok("GET {$rt->uri()} · name=rrdfix.status · action=" . $rt->getActionName());
            break;
        }
    }
    if (! $found) {
        echo $err("Ruta 'rrdfix.status' NO REGISTRADA");
        echo "        → Settings.php no ejecutó Route::get() en constructor o la caché de rutas no la tiene";
        echo "        → Solución: cd /opt/librenms && sudo -u librenms php artisan route:clear && sudo systemctl reload phpX.X-fpm";
    }
} catch (Throwable $e) {
    echo $warn("No se pudo listar rutas: " . $e::class . ' ' . $e->getMessage());
}

// -------- 6. Composición PHP (error_reporting, errores fatales) --------
echo "\n6. Sintaxis PHP de los hooks:\n";
$bad = 0;
foreach (['Settings', 'Menu', 'Page'] as $h) {
    $f = "{$librenmsDir}/app/Plugins/{$target}/{$h}.php";
    if (! is_file($f)) {
        echo $err("{$target}/{$h}.php NO EXISTE");
        $bad++;
        continue;
    }
    exec("php -l " . escapeshellarg($f) . " 2>&1", $out, $code);
    if ($code !== 0) {
        echo $err("{$target}/{$h}.php → " . trim(implode(' ', $out)));
        $bad++;
    } else {
        echo $ok("{$target}/{$h}.php");
    }
}

echo "\n============================================================\n";
if ($bad === 0) {
    echo $ok("Fin diagnóstico sin errores de sintaxis.\n");
} else {
    echo $err("Hay {$bad} problemas — revisa las líneas anteriores.\n");
}
echo "Siguiente paso: si hay diferencias en signatures, ejecuta:\n";
echo "   cd /opt/librenms/app/Plugins/RrdFix\n";
echo "   sudo -u librenms git pull origin master\n";
echo "   cd /opt/librenms\n";
echo "   sudo -u librenms php artisan optimize:clear\n";
echo "   phpv=\$(php -r 'echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;')\n";
echo "   sudo systemctl reload php\${phpv}-fpm\n";
