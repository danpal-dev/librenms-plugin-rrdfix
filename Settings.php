<?php

namespace App\Plugins\RrdFix;

use App\Plugins\Hooks\SettingsHook;
use Illuminate\Contracts\Auth\Authenticatable;

class Settings extends SettingsHook
{
    public function __construct()
    {
        if (app()->routesAreCached()) {
            return;
        }

        \Illuminate\Support\Facades\Route::middleware(['web', 'auth'])->group(function () {
            \Illuminate\Support\Facades\Route::get('rrdfix/status', [\App\Plugins\RrdFix\Http\RunController::class, 'status'])->name('rrdfix.status');
        });
    }

    public function authorize(?Authenticatable $user): bool
    {
        return $user !== null && ($user->can('plugin.admin') || $user->can('admin'));
    }

    public function data(array $settings): array
    {
        return [
            'settings' => $settings,
        ];
    }
}
