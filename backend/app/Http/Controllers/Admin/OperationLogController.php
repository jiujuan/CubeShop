<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SysOperationLog;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class OperationLogController extends Controller
{
    use ApiResponse;

    /**
     * 操作日志查询（API 文档 8.7，权限 log.view）
     * GET /admin/operation-logs
     */
    public function index(Request $request)
    {
        $query = SysOperationLog::query()->with('user:id,username,nickname');

        if ($userId = $request->integer('user_id')) {
            $query->where('user_id', $userId);
        }
        if ($module = $request->input('module')) {
            $query->where('module', $module);
        }
        if ($action = $request->input('action')) {
            $query->where('action', $action);
        }
        if ($start = $request->input('start_time')) {
            $query->where('created_at', '>=', $start);
        }
        if ($end = $request->input('end_time')) {
            $query->where('created_at', '<=', $end);
        }

        $pageSize = min(max($request->integer('page_size', 20), 1), 100);
        $logs = $query->orderByDesc('id')->paginate($pageSize);

        return $this->paginated($logs->through(fn (SysOperationLog $log) => [
            'id' => $log->id,
            'user' => $log->user?->only(['id', 'username', 'nickname']),
            'module' => $log->module,
            'action' => $log->action,
            'target_type' => $log->target_type,
            'target_id' => $log->target_id,
            'content' => $log->content,
            'ip' => $log->ip,
            'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
        ]));
    }
}
