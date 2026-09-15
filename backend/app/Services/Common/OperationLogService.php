<?php

namespace App\Services\Common;

use App\Models\SysOperationLog;
use Illuminate\Support\Facades\Request;

/**
 * 操作日志服务（架构文档 4.1.1 / 6.3）
 * 关键写操作记录：操作人、时间、模块、动作、目标、IP、UA
 */
class OperationLogService
{
    public function record(
        ?int $userId,
        string $module,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        mixed $content = null,
    ): ?SysOperationLog {
        try {
            return SysOperationLog::create([
                'user_id' => $userId,
                'module' => $module,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'content' => is_array($content) ? json_encode($content, JSON_UNESCAPED_UNICODE) : $content,
                'ip' => Request::ip(),
                'user_agent' => substr((string) Request::userAgent(), 0, 512),
            ]);
        } catch (\Throwable $e) {
            // 日志失败不影响主业务
            report($e);

            return null;
        }
    }
}
