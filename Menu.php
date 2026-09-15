<?php

namespace App\Plugins\RrdFix;

use Illuminate\Contracts\Auth\Authenticatable;
use App\Plugins\Hooks\MenuEntryHook;

class Menu extends MenuEntryHook
{
    /**
     * Firma idéntica a Reports/FlowbiteTheme/WebSSH.
     * PluginManager pasa SIEMPRE 'settings' a authorize() vía app()->call().
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
            // Gate no definido — continuar
        }

        try {
            return (bool) $user->can('admin');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function data(array $settings = []): array
    {
        return [];
    }
}