<?php

namespace App\Plugins\RrdFix\Support;

class PendingRun
{
    /** @return array<string, mixed>|null */
    public static function fromSettings(array $settings): ?array
    {
        if (($settings['rrd_fix_action'] ?? null) !== 'run') {
            return null;
        }

        return [
            'device' => (string) ($settings['device'] ?? ''),
            'start' => (string) ($settings['start'] ?? ''),
            'end' => (string) ($settings['end'] ?? ''),
            'availability' => (string) ($settings['availability'] ?? 'up'),
            'no_dbm' => filter_var($settings['no_dbm'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'scan_nan' => filter_var($settings['scan_nan'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'scan_start' => (string) ($settings['scan_start'] ?? ''),
            'scan_end' => (string) ($settings['scan_end'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    public static function emptyForm(): array
    {
        return [
            'device' => '',
            'start' => '',
            'end' => '',
            'availability' => 'up',
            'no_dbm' => false,
            'scan_nan' => false,
            'scan_start' => '',
            'scan_end' => '',
        ];
    }
}
