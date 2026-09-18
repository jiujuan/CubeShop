<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    use ApiResponse;

    /**
     * 健康检查：GET /api/health
     */
    public function index()
    {
        $dbOk = false;
        $dbError = null;

        try {
            DB::select('SELECT 1');
            $dbOk = true;
        } catch (\Throwable $e) {
            $dbError = config('app.debug') ? $e->getMessage() : 'database unavailable';
        }

        $data = [
            'status' => $dbOk ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'time' => now()->toIso8601String(),
            'database' => [
                'ok' => $dbOk,
                'error' => $dbError,
            ],
        ];

        return $dbOk
            ? $this->success($data)
            : $this->fail('数据库连接异常', 503, $data, 503);
    }
}
