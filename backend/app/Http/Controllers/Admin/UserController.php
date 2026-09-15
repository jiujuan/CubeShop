<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SysUser;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 后台用户管理（API 文档 8.8，权限 user.manage）
 *
 * 管理对象：前台购买商品注册的买家账号（sys_user，角色 customer），
 * 支持切换查看管理员账号。超级管理员账号受保护，不允许在此禁用/编辑。
 */
class UserController extends Controller
{
    use ApiResponse;

    /** 有效订单状态（用于消费统计） */
    private const PAID_STATUSES = [
        Order::STATUS_PAID,
        Order::STATUS_SHIPPED,
        Order::STATUS_COMPLETED,
    ];

    public function __construct(
        private readonly OperationLogService $operationLog,
    ) {}

    /**
     * 用户列表（多条件筛选）
     * GET /admin/users?keyword=&status=&role=&start_time=&end_time=&page=&page_size=
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'integer', 'in:0,1'],
            'role' => ['nullable', 'string', 'in:customer,admin,all'],
            'start_time' => ['nullable', 'date'],
            'end_time' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->buildQuery($data)
            ->withCount(['orders as order_count' => fn ($q) => $q->whereIn('status', self::PAID_STATUSES)])
            ->withSum(['orders as total_paid' => fn ($q) => $q->whereIn('status', self::PAID_STATUSES)], 'pay_amount')
            ->orderByDesc('id')
            ->paginate(min($data['page_size'] ?? 20, 100), ['*'], 'page', $data['page'] ?? 1);

        $paginator->through(fn (SysUser $user) => $this->brief($user));

        return $this->paginated($paginator);
    }

    /**
     * 用户详情（含订单统计与最近订单）
     * GET /admin/users/{id}
     */
    public function show(int $id): JsonResponse
    {
        $user = $this->withOrderStats(SysUser::query())->find($id);

        if (! $user) {
            throw BusinessException::notFound('用户不存在');
        }

        return $this->success($this->detail($user));
    }

    /**
     * 编辑用户资料（昵称 / 手机号 / 邮箱）
     * PUT /admin/users/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'nickname' => ['nullable', 'string', 'max:64'],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('sys_user', 'phone')->ignore($id)],
            'email' => ['nullable', 'email', 'max:128', Rule::unique('sys_user', 'email')->ignore($id)],
        ]);

        $user = SysUser::query()->find($id);
        if (! $user) {
            throw BusinessException::notFound('用户不存在');
        }

        if ($user->hasRole('super_admin')) {
            throw BusinessException::forbidden('超级管理员账号不允许在此操作');
        }

        $changes = [];
        foreach (['nickname', 'phone', 'email'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $new = $data[$field] === '' ? null : $data[$field];
            if ($new !== $user->{$field}) {
                $changes[$field] = ['from' => $user->{$field}, 'to' => $new];
            }
        }

        if (! $changes) {
            return $this->success($this->detail($this->withOrderStats(SysUser::query())->find($id)), '未修改任何内容');
        }

        $user->fill(collect($changes)->map(fn ($c) => $c['to'])->all())->save();

        $this->operationLog->record(
            $request->user()->id,
            'user',
            'update_user',
            'sys_user',
            $user->id,
            sprintf('编辑用户 %s（#%d）：%s', $user->username, $user->id, json_encode($changes, JSON_UNESCAPED_UNICODE)),
        );

        return $this->success($this->detail($this->withOrderStats(SysUser::query())->find($id)), '资料已更新');
    }

    /**
     * 启用 / 禁用（禁用立即吊销全部 Token 强制下线）
     * PUT /admin/users/{id}/status  body: { status: 0|1 }
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'integer', 'in:0,1'],
        ]);

        $user = SysUser::query()->find($id);
        if (! $user) {
            throw BusinessException::notFound('用户不存在');
        }

        if ($user->hasRole('super_admin')) {
            throw BusinessException::forbidden('超级管理员账号不允许在此操作');
        }

        if ($data['status'] === 0 && $user->id === $request->user()->id) {
            throw BusinessException::badRequest('不能禁用当前登录账号');
        }

        if ($user->status !== $data['status']) {
            $user->status = $data['status'];
            $user->save();

            // 禁用后立即吊销全部 Token，强制下线
            if ($data['status'] === 0) {
                $user->tokens()->delete();
            }

            $this->operationLog->record(
                $request->user()->id,
                'user',
                $data['status'] === 1 ? 'enable_user' : 'disable_user',
                'sys_user',
                $user->id,
                sprintf('%s用户 %s（#%d）', $data['status'] === 1 ? '启用' : '禁用', $user->username, $user->id),
            );
        }

        return $this->success(
            $this->brief($this->withOrderStats(SysUser::query())->find($id)),
            $data['status'] === 1 ? '已启用' : '已禁用',
        );
    }

    /** 列表/详情共用筛选 */
    private function buildQuery(array $data)
    {
        return SysUser::query()
            // 角色范围：默认买家（customer）；admin=后台账号；all=不过滤
            ->when($data['role'] ?? 'customer', function ($q, $role) {
                if ($role === 'customer') {
                    $q->role('customer');
                } elseif ($role === 'admin') {
                    $q->role(['super_admin', 'operator']);
                }
            })
            ->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when($data['keyword'] ?? null, function ($q, $keyword) {
                $q->where(function ($q) use ($keyword) {
                    $q->where('username', 'like', '%'.$keyword.'%')
                        ->orWhere('nickname', 'like', '%'.$keyword.'%')
                        ->orWhere('phone', 'like', '%'.$keyword.'%')
                        ->orWhere('email', 'like', '%'.$keyword.'%');
                });
            })
            ->when($data['start_time'] ?? null, fn ($q, $start) => $q->where('created_at', '>=', $start))
            ->when($data['end_time'] ?? null, fn ($q, $end) => $q->where('created_at', '<=', $end));
    }

    /** 附带订单统计的查询（列表 brief / 详情 detail 共用） */
    private function withOrderStats($query)
    {
        return $query
            ->withCount(['orders as order_count' => fn ($q) => $q->whereIn('status', self::PAID_STATUSES)])
            ->withSum(['orders as total_paid' => fn ($q) => $q->whereIn('status', self::PAID_STATUSES)], 'pay_amount');
    }

    /** 列表项结构 */
    private function brief(SysUser $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'nickname' => $user->nickname,
            'avatar' => $user->avatar,
            'phone' => $user->phone,
            'email' => $user->email,
            'status' => $user->status,
            'roles' => $user->getRoleNames()->all(),
            'order_count' => (int) ($user->order_count ?? 0),
            'total_paid' => (string) ($user->total_paid ?? '0'),
            'last_login_at' => $user->last_login_at?->format('Y-m-d H:i:s'),
            'last_login_ip' => $user->last_login_ip,
            'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /** 详情结构 */
    private function detail(SysUser $user): array
    {
        return $this->brief($user) + [
            'recent_orders' => Order::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(fn (Order $order) => [
                    'id' => $order->id,
                    'order_no' => $order->order_no,
                    'status' => $order->status,
                    'status_label' => Order::STATUS_LABELS[$order->status] ?? $order->status,
                    'pay_amount' => (string) $order->pay_amount,
                    'created_at' => $order->created_at?->format('Y-m-d H:i:s'),
                ])->all(),
        ];
    }
}
