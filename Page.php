<?php

namespace App\Plugins\RrdFix;

use App\Models\Device;
use App\Models\Plugin;
use App\Plugins\Hooks\PageHook;
use App\Plugins\RrdFix\Support\PendingRun;
use Illuminate\Contracts\Auth\Authenticatable;

class Page extends PageHook
{
    private const SCRIPT_PATH = __DIR__ . '/rrd-fix.py';
    private const LOG_PATH = '/opt/librenms/storage/logs/rrd-fix.log';
    private const RRD_DIR = '/opt/librenms/rrd';

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
            // Gate plugin.admin no definido o error de DB — continuar con fallback
        }

        try {
            return (bool) $user->can('admin');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function data(): array
    {
        try {
            return $this->buildData();
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }

            $msg = 'Error al cargar RrdFix: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';

            return [
                'title' => 'Corrección de RRD',
                'plugin_name' => 'RrdFix',
                'content_view' => 'RrdFix::resources.views.page',
                'settings' => [],
                'devices' => collect(),
                'log_path' => self::LOG_PATH,
                'log_content' => "=== INTERNAL ERROR ===\n" . $msg,
                'running' => false,
                'form' => PendingRun::emptyForm(),
                'flash' => $msg,
                'flash_type' => 'danger',
                'docs' => $this->safeDocBundle(),
                'plugin_version' => '1.1.1',
                'status_url' => $this->safeStatusUrl(),
            ];
        }
    }

    private function buildData(): array
    {
        $settings = $this->getSettings();
        $pendingRun = PendingRun::fromSettings($settings);
        $result = null;
        if ($pendingRun !== null) {
            // Consume first so refreshing cannot launch the same correction twice.
            try {
                $plugin = Plugin::where('plugin_name', 'RrdFix')->first();
                if ($plugin !== null) {
                    $plugin->settings = [];
                    $plugin->save();
                }
            } catch (\Throwable $e) {
                if (function_exists('report')) {
                    report($e);
                }
            }

            try {
                $result = $this->run($pendingRun);
            } catch (\Throwable $e) {
                if (function_exists('report')) {
                    report($e);
                }
                $result = ['flash' => 'Error al iniciar rrd-fix: ' . $e->getMessage(), 'flash_type' => 'danger'];
            }
        }

        try {
            $devices = Device::query()
                ->select(['device_id', 'hostname', 'sysName'])
                ->orderBy('hostname')
                ->get();
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }
            $devices = collect();
        }

        return [
            'title' => 'Corrección de RRD',
            'content_view' => 'RrdFix::resources.views.page',
            'devices' => $devices,
            'log_path' => self::LOG_PATH,
            'log_content' => $this->readLog(),
            'running' => $this->isRunning(),
            'form' => $pendingRun ?? PendingRun::emptyForm(),
            'flash' => $result['flash'] ?? null,
            'flash_type' => $result['flash_type'] ?? 'info',
            'docs' => $this->safeDocBundle(),
            'plugin_version' => '1.1.1',
            'status_url' => $this->safeStatusUrl(),
        ];
    }

    private function safeStatusUrl(): string
    {
        try {
            if (function_exists('route') && app('router')->has('rrdfix.status')) {
                return route('rrdfix.status');
            }
        } catch (\Throwable $e) {
            // ignore — fallback a url cruda
        }

        return url('/rrdfix/status');
    }

    private function safeDocBundle(): array
    {
        return [
            'guia'        => self::readDoc(__DIR__ . '/GUÍA.md'),
            'faq'         => self::readDoc(__DIR__ . '/FAQ.md'),
            'instalacion' => self::readDoc(__DIR__ . '/INSTALACIÓN.md'),
            'arquitectura'=> self::readDoc(__DIR__ . '/ARQUITECTURA.md'),
        ];
    }

    public function run(array $form): array
    {
        if ($this->isRunning()) {
            return ['flash' => 'Ya hay una corrección en ejecución.', 'flash_type' => 'warning'];
        }

        $device = trim($form['device']);
        $start = $this->normalizeDate((string) $form['start']);
        $end = $this->normalizeDate((string) $form['end']);
        $fill = $form['availability'] === 'down' ? 0 : 100;

        if (! preg_match('/\A[[:alnum:]_.:-]+\z/', $device) || ! Device::where('hostname', $device)->exists()) {
            return ['flash' => 'Selecciona un dispositivo válido.', 'flash_type' => 'danger'];
        }

        if ($start === null || $end === null) {
            return ['flash' => 'Indica correctamente la fecha y hora de inicio y fin.', 'flash_type' => 'danger'];
        }

        if ($start > $end) {
            return ['flash' => 'La fecha de inicio debe ser anterior a la fecha de fin.', 'flash_type' => 'danger'];
        }

        if (! in_array($form['availability'], ['up', 'down'], true)) {
            return ['flash' => 'Selecciona si el dispositivo estuvo caído o no estuvo caído.', 'flash_type' => 'danger'];
        }

        $pythonArgs = [
            'python3', '-u', self::SCRIPT_PATH,
            '--ip', $device, '--start', $start, '--end', $end,
            '--rrd-dir', self::RRD_DIR, '--avail-fill', (string) $fill,
        ];
        if ($form['no_dbm']) {
            $pythonArgs[] = '--no-dbm';
        }
        if ($form['scan_nan']) {
            $pythonArgs[] = '--scan-nan';
        }
        if ($form['scan_start'] !== '') {
            $scanStart = $this->normalizeDate($form['scan_start']);
            if ($scanStart === null) {
                return ['flash' => 'La fecha inicial del escaneo NaN no es válida.', 'flash_type' => 'danger'];
            }
            $pythonArgs[] = '--scan-start';
            $pythonArgs[] = $scanStart;
        }
        if ($form['scan_end'] !== '') {
            $scanEnd = $this->normalizeDate($form['scan_end']);
            if ($scanEnd === null) {
                return ['flash' => 'La fecha final del escaneo NaN no es válida.', 'flash_type' => 'danger'];
            }
            $pythonArgs[] = '--scan-end';
            $pythonArgs[] = $scanEnd;
        }

        $logDirectory = dirname(self::LOG_PATH);
        if (! is_dir($logDirectory) && ! mkdir($logDirectory, 0775, true) && ! is_dir($logDirectory)) {
            return ['flash' => 'No se puede crear el directorio del log de rrd-fix.', 'flash_type' => 'danger'];
        }

        $pythonCommand = implode(' ', array_map('escapeshellarg', $pythonArgs));
        $backgroundCommand = $pythonCommand . ' >> ' . escapeshellarg(self::LOG_PATH) . ' 2>&1 &';

        $currentUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $librenmsUid = $currentUid !== null && function_exists('posix_getpwnam')
            ? (posix_getpwnam('librenms')['uid'] ?? null)
            : null;
        $needsSudo = $currentUid === null || $librenmsUid === null || $currentUid !== $librenmsUid;

        $command = $needsSudo
            ? 'sudo -n -u librenms -- sh -lc ' . escapeshellarg($backgroundCommand)
            : 'sh -lc ' . escapeshellarg($backgroundCommand);
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            return ['flash' => 'No se pudo iniciar rrd-fix. Revisa el log.', 'flash_type' => 'danger'];
        }

        return ['flash' => 'rrd-fix ejecutado. Revisa el log para ver el resultado.', 'flash_type' => 'success'];
    }

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);

        return $date !== false && $date->format('Y-m-d H:i:s') === $value;
    }

    private function normalizeDate(string $value): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);

        return $date === false ? null : $date->format('Y-m-d H:i:s');
    }

    public function isRunning(): bool
    {
        exec('ps -eo user=,pid=,args= | grep -E ' . escapeshellarg('^[^ ]*[[:space:]]+[0-9]+[[:space:]]+.*python3 .*rrd-fix\.py') . ' | grep -v grep', $output, $code);

        foreach ($output as $line) {
            if (str_contains($line, self::SCRIPT_PATH)) {
                return true;
            }
        }

        return false;
    }

    public function readLog(): string
    {
        if (! is_readable(self::LOG_PATH)) {
            return '';
        }

        $lines = file(self::LOG_PATH, FILE_IGNORE_NEW_LINES);

        return implode("\n", array_slice($lines ?: [], -200));
    }

    private static function readDoc(string $path): array
    {
        if (! is_readable($path)) {
            return ['title' => basename($path), 'lines' => [], 'empty' => true];
        }
        $raw    = file_get_contents($path);
        $raw    = str_replace(["\r\n", "\r"], "\n", $raw);
        $title  = '';
        $body   = ltrim($raw);
        if (preg_match('/\A#\s+(.+?)\n/s', $body, $m)) {
            $title = trim($m[1]);
            $body  = ltrim(substr($body, strlen($m[0])));
        }
        $lines = explode("\n", $body);

        return ['title' => $title ?: basename($path), 'lines' => $lines, 'empty' => trim($body) === ''];
    }

    /** @return array<string, mixed> */
    private function getSettings(): array
    {
        try {
            return app(\LibreNMS\Interfaces\Plugins\PluginManagerInterface::class)->getSettings('RrdFix');
        } catch (\Throwable $e) {
            // Fallback directo a BD — evita desactivar el plugin si el
            // PluginManager aún no está ligado en el contenedor.
        }

        try {
            $plugin = Plugin::where('plugin_name', 'RrdFix')->first(['settings']);
            if ($plugin !== null) {
                $s = $plugin->settings;
                if (is_array($s)) {
                    return $s;
                }
                if (is_string($s)) {
                    $decoded = json_decode($s, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return [];
    }
}
