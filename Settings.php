<?php

namespace App\Plugins\RrdFix;

use App\Plugins\Hooks\SettingsHook;
use Illuminate\Contracts\Auth\Authenticatable;

class Settings extends SettingsHook
{
    public function __construct()
    {
        try {
            $app = app();
            if ($app->routesAreCached()) {
                return;
            }

            \Illuminate\Support\Facades\Route::middleware(['web', 'auth'])->group(function () {
                \Illuminate\Support\Facades\Route::get(
                    'rrdfix/status',
                    [\App\Plugins\RrdFix\Http\RunController::class, 'status']
                )->name('rrdfix.status');
            });
        } catch (\Throwable $e) {
            // No dejar que un error en el registro de rutas desactive el plugin.
            // El registro de rutas se reintenta en cada request si las rutas
            // no están cacheadas.
            if (function_exists('report')) {
                report($e);
            }
        }
    }

    /**
     * NOTE: la firma DEBE incluir array $settings = [] porque el PluginManager
     * llama a app()->call([$this,'authorize'], ['settings' => [...], ...])
     * y si el método no acepta el parámetro, puede fallar en algunas
     * versiones de LibreNMS/Laravel y desactivar el plugin.
     *
     * Se usa exactamente la misma firma que Reports/FlowbiteTheme/WebSSH
     * (plugins que SÍ funcionan correctamente).
     */
    public function authorize(?Authenticatable $user, array $settings = []): bool
    {
        if ($user === null) {
            return false;
        }

        try {
            if ($user->can('plugin.admin')) {
                return true;
            }
        } catch (\Throwable $e) {
            // Gate no definido o error de DB — continuar con el fallback.
        }

        try {
            return (bool) $user->can('admin');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function data(array $settings): array
    {
        return [
            'settings' => $settings,
        ];
    }
}
