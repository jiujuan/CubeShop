<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\SysUser;
use App\Services\Common\OperationLogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 角色权限管理（V1.1 F04 / T-022）
 * 权限：role.manage（超管专属）
 *
 * 内置角色（super_admin / operator / customer）不可删除；被账号引用的角色不可删除。
 */
class RoleController extends Controller
{
    use ApiResponse;

    /** 内置角色（不允许删除/重命名） */
    private const BUILTIN_ROLES = ['super_admin', 'operator'];

    /** 角色中文标签 */
    private const ROLE_LABELS = [
        'super_admin' => '超级管理员',
        'operator' => '运营',
    ];

    /** 权限码模块中文标签 */
    private const MODULE_LABELS = [
        'product' => '商品',
        'category' => '分类',
        'order' => '订单',
        'refund' => '退款',
        'dashboard' => '数据概览',
        'report' => '报表',
        'review' => '评价',
        'user' => '用户',
        'account' => '账号',
        'role' => '角色',
        'inventory' => '库存',
        'address' => '地址',
        'config' => '系统配置',
        'log' => '日志',
    ];

    public function __construct(private OperationLogService $opLog)
    {
    }

    /** 角色列表 GET /admin/roles */
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions:id,name')
            ->orderBy('id')
            ->get()
            ->map(function (Role $role) {
                $userCount = SysUser::query()->role($role->name)->count();

                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                    'label' => $role->display_name ?: (self::ROLE_LABELS[$role->name] ?? $role->name),
                    'builtin' => in_array($role->name, self::BUILTIN_ROLES, true),
                    'permissions' => $role->permissions->pluck('name')->all(),
                    'user_count' => $userCount,
                ];
            });

        return $this->success([
            'roles' => $roles,
            'permission_groups' => $this->permissionGroups(),
        ]);
    }

    /** 权限码分组（供前端权限树） GET /admin/permissions */
    public function permissions(): JsonResponse
    {
        return $this->success(['groups' => $this->permissionGroups()]);
    }

    /** 新增角色 POST /admin/roles */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:32', 'alpha_dash', 'unique:roles,name'],
            'display_name' => ['required', 'string', 'max:64'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role = DB::transaction(function () use ($data) {
            // 注意：不能用 Role::create()——spatie 的静态 create 会丢弃 display_name 等附加字段
            $role = new Role([
                'name' => $data['name'],
                'display_name' => $data['display_name'],
                'guard_name' => 'web',
            ]);
            $role->save();
            $role->syncPermissions($data['permissions'] ?? []);

            return $role;
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->opLog->record($request->user()->id, 'role', 'create', 'roles', $role->id, [
            'name' => $role->name,
            'display_name' => $role->display_name,
            'permissions' => $data['permissions'] ?? [],
        ]);

        return $this->success(['id' => $role->id], '角色已创建');
    }

    /** 编辑角色（名称 + 权限） PUT /admin/roles/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $role = Role::find($id);
        if (! $role) {
            throw BusinessException::notFound('角色不存在');
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:32', 'alpha_dash', 'unique:roles,name,'.$id],
            // 中文名仅用于展示，内置角色也允许修改（不影响权限判断）
            'display_name' => ['sometimes', 'string', 'max:64'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        if (isset($data['name']) && in_array($role->name, self::BUILTIN_ROLES, true)) {
            throw BusinessException::forbidden('内置角色不允许重命名');
        }

        $before = [
            'name' => $role->name,
            'display_name' => $role->display_name,
            'permissions' => $role->permissions->pluck('name')->all(),
        ];

        DB::transaction(function () use ($role, $data) {
            if (isset($data['name'])) {
                $role->name = $data['name'];
            }
            if (array_key_exists('display_name', $data)) {
                $role->display_name = $data['display_name'];
            }
            if (isset($data['name']) || array_key_exists('display_name', $data)) {
                $role->save();
            }
            if (isset($data['permissions'])) {
                $role->syncPermissions($data['permissions']);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role->refresh();
        $this->opLog->record($request->user()->id, 'role', 'update', 'roles', $role->id, [
            'before' => $before,
            'after' => [
                'name' => $role->name,
                'display_name' => $role->display_name,
                'permissions' => $role->permissions->pluck('name')->all(),
            ],
        ]);

        return $this->success(null, '角色已更新');
    }

    /** 删除角色 DELETE /admin/roles/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $role = Role::find($id);
        if (! $role) {
            throw BusinessException::notFound('角色不存在');
        }

        if (in_array($role->name, self::BUILTIN_ROLES, true)) {
            throw BusinessException::forbidden('内置角色不允许删除');
        }

        $userCount = SysUser::query()->role($role->name)->count();
        if ($userCount > 0) {
            throw BusinessException::conflict("该角色下仍有 {$userCount} 个账号，无法删除");
        }

        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->opLog->record($request->user()->id, 'role', 'delete', 'roles', $id, ['name' => $role->name]);

        return $this->success(null, '角色已删除');
    }

    /** 权限码按模块分组 */
    private function permissionGroups(): array
    {
        return Permission::orderBy('name')->pluck('name')
            ->groupBy(fn (string $name) => explode('.', $name)[0])
            ->map(fn ($items, $module) => [
                'module' => $module,
                'label' => self::MODULE_LABELS[$module] ?? $module,
                'permissions' => $items->values()->all(),
            ])
            ->values()
            ->all();
    }
}
