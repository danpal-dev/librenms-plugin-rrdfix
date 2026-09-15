<?php

namespace App\Plugins\RrdFix;

use App\Plugins\Hooks\SettingsHook;
use App\Plugins\RrdFix\Http\RunController;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:plugin.admin'])->group(function () {
    Route::get('rrdfix/status', [RunController::class, 'status'])->name('rrdfix.status');
});

class Settings extends SettingsHook
{
    public function authorize(?Authenticatable $user): bool
    {
        return $user !== null && $user->can('plugin.admin');
    }

    public function data(array $settings = []): array
    {
        return [];
    }
}
