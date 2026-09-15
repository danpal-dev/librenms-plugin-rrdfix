<?php

namespace App\Plugins\RrdFix;

use Illuminate\Contracts\Auth\Authenticatable;
use App\Plugins\Hooks\MenuEntryHook;

class Menu extends MenuEntryHook
{
    public function authorize(?Authenticatable $user): bool
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

    public function data(): array
    {
        return [];
    }
}