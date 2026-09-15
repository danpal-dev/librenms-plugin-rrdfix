<?php

namespace App\Plugins\RrdFix;

use Illuminate\Contracts\Auth\Authenticatable;
use App\Plugins\Hooks\MenuEntryHook;

class Menu extends MenuEntryHook
{
    public function authorize(?Authenticatable $user): bool
    {
        return $user !== null && ($user->can('plugin.admin') || $user->can('admin'));
    }

    public function data(): array
    {
        return [];
    }
}