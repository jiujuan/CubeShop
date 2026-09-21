<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\AuthLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 后台认证日志（登录 / 注册 / 登出，含失败明细）
 *
 * 排障入口：按事件 / 身份 / 成功失败 / 标识 / 时间筛选，详情展示完整字段与扩展明细。
 * 权限：log.auth.view（超管 + 运营）。
 *
 * 安全：fail_reason 为内部明细（验证码错误 / 账号锁定 / 密码错 / 注销等），
 * 仅后台授权管理员可见，不向普通用户端暴露（SEC-08 约束的是对外响应，这里属内部审计）。
 */
class AuthLogController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event' => ['nullable', Rule::in([AuthLog::EVENT_LOGIN, AuthLog::EVENT_REGISTER, AuthLog::EVENT_LOGOUT])],
            'actor_type' => ['nullable', Rule::in([AuthLog::ACTOR_ADMIN, AuthLog::ACTOR_CUSTOMER])],
            'success' => ['nullable', 'boolean'],
            'identifier' => ['nullable', 'string', 'max:120'],
            'fail_reason' => ['nullable', 'string', 'max:64'],
            'created_from' => ['nullable', 'date_format:Y-m-d'],
            'created_to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AuthLog::query()
            ->when($data['event'] ?? null, fn ($q, $v) => $q->where('event', $v))
            ->when($data['actor_type'] ?? null, fn ($q, $v) => $q->where('actor_type', $v))
            ->when(isset($data['success']), fn ($q) => $q->where('success', (bool) $data['success']))
            ->when($data['identifier'] ?? null, fn ($q, $v) => $q->where('identifier', 'like', "%{$v}%"))
            ->when($data['fail_reason'] ?? null, fn ($q, $v) => $q->where('fail_reason', 'like', "%{$v}%"))
            ->when($data['created_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($data['created_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->orderByDesc('id');

        $page = $query->paginate((int) ($data['page_size'] ?? 15));
        $page->through(fn (AuthLog $log) => $this->row($log));

        return $this->paginated($page);
    }

    public function show(int $id): JsonResponse
    {
        $log = AuthLog::find($id);
        if (! $log) {
            throw BusinessException::notFound('日志不存在');
        }

        return $this->success($this->row($log, withDetail: true));
    }

    // ==================== 出口 ====================

    /** @return array<string, mixed> */
    private function row(AuthLog $log, bool $withDetail = false): array
    {
        $row = [
            'id' => $log->id,
            'event' => $log->event,
            'event_label' => match ($log->event) {
                AuthLog::EVENT_LOGIN => '登录',
                AuthLog::EVENT_REGISTER => '注册',
                AuthLog::EVENT_LOGOUT => '登出',
                default => $log->event,
            },
            'actor_type' => $log->actor_type,
            'actor_label' => $log->actor_type === AuthLog::ACTOR_ADMIN ? '管理员' : '买家',
            'user_id' => $log->user_id,
            'identifier' => $log->identifier,
            'success' => (bool) $log->success,
            'success_label' => $log->success ? '成功' : '失败',
            'fail_reason' => $log->fail_reason,
            'ip' => $log->ip,
            'user_agent' => $log->user_agent,
            'device_id' => $log->device_id,
            'token_id' => $log->token_id,
            'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
        ];

        if ($withDetail) {
            $row['detail'] = $log->detail;
        }

        return $row;
    }
}
