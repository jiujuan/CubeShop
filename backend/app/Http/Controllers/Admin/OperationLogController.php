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
        // user_id 为混合语义列（买家与管理员都写入），按 actor_type 选择来源表解析操作人
        $query = SysOperationLog::query()->with([
            'admin:id,username,nickname',
            'customer:id,username,nickname',
        ]);

        // V1.1 T-022：新增 operator_id 别名（兼容既有 user_id），并支持 start/end 简写
        if ($userId = ($request->integer('operator_id') ?: $request->integer('user_id'))) {
            $query->where('user_id', $userId);
        }
        if ($actorType = $request->input('actor_type')) {
            $query->where('actor_type', $actorType);
        }
        if ($module = $request->input('module')) {
            $query->where('module', $module);
        }
        if ($action = $request->input('action')) {
            $query->where('action', $action);
        }
        if ($start = ($request->input('start') ?: $request->input('start_time'))) {
            $query->where('created_at', '>=', $this->normalizeStart($start));
        }
        if ($end = ($request->input('end') ?: $request->input('end_time'))) {
            $query->where('created_at', '<=', $this->normalizeEnd($end));
        }

        $pageSize = min(max($request->integer('page_size', 20), 1), 100);
        $logs = $query->orderByDesc('id')->paginate($pageSize);

        return $this->paginated($logs->through(fn (SysOperationLog $log) => [
            'id' => $log->id,
            'user' => ($log->actor_type === SysOperationLog::ACTOR_CUSTOMER ? $log->customer : $log->admin)
                ?->only(['id', 'username', 'nickname']),
            'actor_type' => $log->actor_type ?? SysOperationLog::ACTOR_ADMIN,
            'module' => $log->module,
            'action' => $log->action,
            'target_type' => $log->target_type,
            'target_id' => $log->target_id,
            'content' => $log->content,
            'ip' => $log->ip,
            'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
        ]));
    }

    /**
     * 纯日期（Y-m-d）起点补 00:00:00；含时间的原样返回
     */
    private function normalizeStart(string $value): string
    {
        return strlen($value) === 10 ? $value.' 00:00:00' : $value;
    }

    /**
     * 纯日期（Y-m-d）终点补 23:59:59，避免漏掉当天记录
     */
    private function normalizeEnd(string $value): string
    {
        return strlen($value) === 10 ? $value.' 23:59:59' : $value;
    }
}
