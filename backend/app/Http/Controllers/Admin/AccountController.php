<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\SysUser;
use App\Services\Common\OperationLogService;
use App\Support\AdminRole;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * 管理员账号管理（V1.1 F04 / T-022）
 * 权限：account.manage（超管专属）
 *
 * 管理对象：后台账号（super_admin / operator / cs_agent，见 App\Support\AdminRole）。
 * 安全约束：不能禁用/删除自己；不能移除最后一个超管。
 */
class AccountController extends Controller
{
    use ApiResponse;

    /** 后台角色白名单（唯一来源：App\Support\AdminRole::BUILTIN） */
    private const ADMIN_ROLES = AdminRole::BUILTIN;

    public function __construct(private OperationLogService $opLog)
    {
    }

    /** 列表 GET /admin/accounts */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['nullable', 'string', 'max:64'],
            'role' => ['nullable', 'string', Rule::in(AdminRole::BUILTIN)],
            'status' => ['nullable', 'integer', 'in:0,1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = SysUser::query()
            ->role(self::ADMIN_ROLES)
            ->when($data['role'] ?? null, fn ($q, $role) => $q->role($role))
            ->when(isset($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when($data['keyword'] ?? null, function ($q, $kw) {
                $q->where(function ($sub) use ($kw) {
                    $sub->where('username', 'like', '%'.$kw.'%')
                        ->orWhere('nickname', 'like', '%'.$kw.'%')
                        ->orWhere('email', 'like', '%'.$kw.'%');
                });
            })
            ->orderByDesc('id')
            ->paginate(min($data['page_size'] ?? 20, 100), ['*'], 'page', $data['page'] ?? 1);

        $paginator->through(fn (SysUser $u) => $this->brief($u));

        return $this->paginated($paginator);
    }

    /** 新增 GET 角色选项 / POST /admin/accounts */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:32', 'unique:sys_user,username'],
            'password' => ['required', 'string', ...$this->passwordRules()],
            'nickname' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:128', 'unique:sys_user,email'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:sys_user,phone'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(self::ADMIN_ROLES)],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = SysUser::create([
                'username' => $data['username'],
                'password' => Hash::make($data['password']),
                'nickname' => $data['nickname'] ?? $data['username'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'status' => 1,
            ]);
            $user->syncRoles($data['roles']);

            return $user;
        });

        $this->opLog->record($request->user()->id, 'account', 'create', 'sys_user', $user->id, [
            'username' => $user->username,
            'roles' => $data['roles'],
            'password' => '***', // 脱敏
        ]);

        return $this->success(['id' => $user->id], '账号已创建');
    }

    /** 编辑 PUT /admin/accounts/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->find($id);

        $data = $request->validate([
            'nickname' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:128', Rule::unique('sys_user', 'email')->ignore($id)],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('sys_user', 'phone')->ignore($id)],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(self::ADMIN_ROLES)],
        ]);

        // 若变更角色，需保护「最后一个超管」
        if (isset($data['roles']) && $user->hasRole('super_admin') && ! in_array('super_admin', $data['roles'], true)) {
            $this->guardLastSuperAdmin($user);
        }

        $before = [
            'nickname' => $user->nickname,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => $user->getRoleNames()->all(),
        ];

        DB::transaction(function () use ($user, $data) {
            $user->fill(array_intersect_key($data, array_flip(['nickname', 'email', 'phone'])))->save();
            if (isset($data['roles'])) {
                $user->syncRoles($data['roles']);
            }
        });

        $user->refresh();
        $this->opLog->record($request->user()->id, 'account', 'update', 'sys_user', $user->id, [
            'before' => $before,
            'after' => [
                'nickname' => $user->nickname,
                'email' => $user->email,
                'phone' => $user->phone,
                'roles' => $user->getRoleNames()->all(),
            ],
        ]);

        return $this->success($this->brief($user), '账号已更新');
    }

    /** 启用/禁用 POST /admin/accounts/{id}/status */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'integer', 'in:0,1']]);
        $user = $this->find($id);

        if ($data['status'] === 0) {
            if ($user->id === $request->user()->id) {
                throw BusinessException::badRequest('不能禁用当前登录账号');
            }
            if ($user->hasRole('super_admin')) {
                $this->guardLastSuperAdmin($user);
            }
        }

        if ($user->status !== $data['status']) {
            $user->status = $data['status'];
            $user->save();

            // 禁用立即吊销 Token，强制下线
            if ($data['status'] === 0) {
                $user->tokens()->delete();
            }

            $this->opLog->record($request->user()->id, 'account', $data['status'] ? 'enable' : 'disable', 'sys_user', $user->id, [
                'username' => $user->username,
            ]);
        }

        return $this->success($this->brief($user), $data['status'] ? '已启用' : '已禁用');
    }

    /** 重置密码 POST /admin/accounts/{id}/reset-password */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', ...$this->passwordRules()],
        ]);

        $user = $this->find($id);

        $user->password = Hash::make($data['password']);
        $user->save();
        // 重置密码后吊销该账号全部 Token
        $user->tokens()->delete();

        $this->opLog->record($request->user()->id, 'account', 'reset_password', 'sys_user', $user->id, [
            'username' => $user->username,
            'password' => '***',
        ]);

        return $this->success(null, '密码已重置，该账号需重新登录');
    }

    // ---------- internals ----------

    private function find(int $id): SysUser
    {
        $user = SysUser::query()->role(self::ADMIN_ROLES)->find($id);
        if (! $user) {
            throw BusinessException::notFound('管理员账号不存在');
        }

        return $user;
    }

    /** 保护：不能移除最后一个超管 */
    private function guardLastSuperAdmin(SysUser $target): void
    {
        $count = SysUser::query()
            ->role('super_admin')
            ->where('status', 1)
            ->whereKeyNot($target->id)
            ->count();

        if ($count === 0) {
            throw BusinessException::conflict('必须保留至少一个启用的超级管理员账号');
        }
    }

    /** 密码强度：8~32 位，须同时包含字母与数字 */
    private function passwordRules(): array
    {
        return ['min:8', 'max:32', 'regex:/^(?=.*[A-Za-z])(?=.*\d).+$/'];
    }

    private function brief(SysUser $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'nickname' => $user->nickname,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => (int) $user->status,
            'roles' => $user->getRoleNames()->all(),
            'last_login_at' => $user->last_login_at?->format('Y-m-d H:i:s'),
            'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
