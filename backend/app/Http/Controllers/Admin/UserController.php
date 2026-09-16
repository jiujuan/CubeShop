<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 后台用户管理（API 文档 8.8，权限 user.manage）
 *
 * 管理对象：buyers —— 前台注册的买家（表 users）。
 * 后台管理员账号请使用「账号管理」（Admin\AccountController，表 sys_user）。
 *
 * V1.1 用户表拆分后，本接口**只服务买家**：
 * 管理员与买家已分属两张表，ID 各自独立，混列会造成 ID 撞号与操作歧义，
 * 因此原 role=customer|admin|all 的混合视图已下线。
 */
class UserController extends Controller
{
    use ApiResponse;

    /** 有效订单状态（用于消费统计）：已支付 / 待发货 / 已发货 / 已完成 */
    private const PAID_STATUSES = [
        Order::STATUS_PAID,
        Order::STATUS_PENDING_SHIP,
        Order::STATUS_SHIPPED,
        Order::STATUS_COMPLETED,
    ];

    public function __construct(
        private readonly OperationLogService $operationLog,
    ) {}

    /**
     * 用户列表（多条件筛选）
     * GET /admin/users?keyword=&status=&start_time=&end_time=&page=&page_size=
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'integer', 'in:0,1'],
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

        $paginator->through(fn (User $user) => $this->brief($user));

        return $this->paginated($paginator);
    }

    /**
     * 用户详情（含订单统计与最近订单）
     * GET /admin/users/{id}
     */
    public function show(int $id): JsonResponse
    {
        $user = $this->withOrderStats(User::query())->find($id);

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
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($id)],
            'email' => ['nullable', 'email', 'max:128', Rule::unique('users', 'email')->ignore($id)],
        ]);

        $user = User::query()->find($id);
        if (! $user) {
            throw BusinessException::notFound('用户不存在');
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
            return $this->success($this->detail($this->withOrderStats(User::query())->find($id)), '未修改任何内容');
        }

        $user->fill(collect($changes)->map(fn ($c) => $c['to'])->all())->save();

        $this->operationLog->record(
            $request->user()->id,
            'user',
            'update_user',
            'users',
            $user->id,
            sprintf('编辑用户 %s（#%d）：%s', $user->username, $user->id, json_encode($changes, JSON_UNESCAPED_UNICODE)),
        );

        return $this->success($this->detail($this->withOrderStats(User::query())->find($id)), '资料已更新');
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

        $user = User::query()->find($id);
        if (! $user) {
            throw BusinessException::notFound('用户不存在');
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
                'users',
                $user->id,
                sprintf('%s用户 %s（#%d）', $data['status'] === 1 ? '启用' : '禁用', $user->username, $user->id),
            );
        }

        return $this->success(
            $this->brief($this->withOrderStats(User::query())->find($id)),
            $data['status'] === 1 ? '已启用' : '已禁用',
        );
    }

    /** 列表/详情共用筛选 */
    private function buildQuery(array $data)
    {
        return User::query()
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
    private function brief(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'nickname' => $user->nickname,
            'avatar' => $user->avatar,
            'phone' => $user->phone,
            'email' => $user->email,
            'status' => $user->status,
            // 买家不参与 spatie 权限体系，无角色
            'roles' => [],
            'order_count' => (int) ($user->order_count ?? 0),
            'total_paid' => (string) ($user->total_paid ?? '0'),
            'last_login_at' => $user->last_login_at?->format('Y-m-d H:i:s'),
            'last_login_ip' => $user->last_login_ip,
            'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /** 详情结构 */
    private function detail(User $user): array
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
