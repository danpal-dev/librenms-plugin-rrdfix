<?php

namespace App\Plugins\RrdFix\Http;

use App\Plugins\RrdFix\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RunController
{
    public function __invoke(Request $request): \Illuminate\Http\RedirectResponse
    {
        $result = (new Page)->run([
            'device' => (string) $request->input('device', ''),
            'start' => (string) $request->input('start', ''),
            'end' => (string) $request->input('end', ''),
            'availability' => (string) $request->input('availability', 'up'),
            'no_dbm' => $request->boolean('no_dbm'),
            'scan_nan' => $request->boolean('scan_nan'),
            'scan_start' => (string) $request->input('scan_start', ''),
            'scan_end' => (string) $request->input('scan_end', ''),
        ]);

        return redirect(url('plugin/RrdFix'))->with([
            'rrd_fix_flash' => $result['flash'],
            'rrd_fix_flash_type' => $result['flash_type'],
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $page = new Page;
        $status = [
            'running' => $running = $page->isRunning(),
            'log_content' => $page->readLog(),
        ];

        $pidInfo = null;
        if ($running && function_exists('shell_exec')) {
            $ps = shell_exec("ps -eo pid=,etime=,pcpu=,pmem=,args= | grep -E 'python3 .*rrd-fix\.py' | grep -v grep");
            if ($ps && preg_match('/^\s*(\d+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(.+?)\s*$/m', trim($ps), $m)) {
                $pidInfo = [
                    'pid' => (int) $m[1],
                    'etime' => $m[2],
                    'cpu' => $m[3],
                    'mem' => $m[4],
                    'args' => $m[5],
                ];
            }
        }
        $status['process'] = $pidInfo;

        return response()->json($status);
    }
}
