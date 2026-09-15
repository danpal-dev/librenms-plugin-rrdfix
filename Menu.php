<?php

namespace App\Plugins\RrdFix;

use Illuminate\Contracts\Auth\Authenticatable;
use App\Plugins\Hooks\MenuEntryHook;

class Menu extends MenuEntryHook
{
    public function authorize(?Authenticatable $user, array $settings = []): bool
    {
        return $user !== null && $user->can('plugin.admin');
    }

    public function data(array $settings = []): array
    {
        return [];
    }
}