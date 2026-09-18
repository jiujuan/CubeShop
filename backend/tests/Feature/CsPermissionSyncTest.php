<?php

use App\Models\CsTicketType;
use App\Models\Notification;
use App\Models\SysUser;
use App\Models\User;
use App\Services\Cs\CsNotificationService;
use App\Services\Cs\CsTicketService;
use App\Services\Notification\NotificationService;
use App\Support\AdminRole;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * CS-103 权限双路径一致性 + 工单通知可达性回归（P1 修复）
 *
 * 客服中心权限有两条独立落地路径：
 *   ① 全新安装 → `RolePermissionSeeder`（决定 operator 的权限清单）
 *   ② 存量库升级 → 迁移 `2026_09_17_000039_add_cs_permissions.php`（曾误授予 operator）
 *
 * 两条路径对 operator 必须都不授予 cs.* —— 运营与客服是两条职责线，一期由超管兜底。
 * 曾出现的问题：000039 授予 operator 三个 cs 权限而 Seeder 一个没给，于是
 * 「存量库升级」的 operator 能进客服工作台，而「全新安装」的 operator 收到新工单通知却打不开（403）。
 *
 * 因此这里同时锁定：
 *   - 两条路径结果一致（都不给 operator），且 000040 能把存量库纠回一致；
 *   - 新工单通知按「持有 cs.ticket.view 的账号」投递，不写死角色名（有通知必能打开）。
 */

/** CS 一期三个权限码（与 RolePermissionSeeder::PERMISSIONS 一致） */
const CS_PERMISSIONS = ['cs.ticket.view', 'cs.ticket.handle', 'cs.faq.manage'];

function csPermissionNamesOf(string $role): array
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    return Role::findByName($role, 'web')
        ->permissions->pluck('name')->sort()->values()->all();
}

function csRunMigration(string $file): void
{
    /** @var \Illuminate\Database\Migrations\Migration $migration */
    $migration = require database_path('migrations/'.$file);

    $migration->up();
}

it('权限码清单：RolePermissionSeeder::PERMISSIONS 含客服中心三个权限码', function () {
    foreach (CS_PERMISSIONS as $permission) {
        expect(RolePermissionSeeder::PERMISSIONS)->toContain($permission);
    }
});

it('全新安装：Seeder 结束后超管持有三个权限、operator 一个都没有', function () {
    $this->seed(RolePermissionSeeder::class);

    foreach (CS_PERMISSIONS as $permission) {
        expect(csPermissionNamesOf('super_admin'))->toContain($permission);
        expect(csPermissionNamesOf('operator'))->not->toContain($permission);
    }
});

it('存量库升级：跑过 000039 与 000040 后，operator 权限与全新安装一致（均无 cs.*）', function () {
    $this->seed(RolePermissionSeeder::class);
    $expected = csPermissionNamesOf('operator');

    // 模拟存量库：先执行曾误授权的 000039，再由 000040 纠回
    csRunMigration('2026_09_17_000039_add_cs_permissions.php');
    csRunMigration('2026_09_17_000040_revoke_cs_permissions_from_operator.php');

    $actual = csPermissionNamesOf('operator');

    expect($actual)->toBe($expected);
    foreach (CS_PERMISSIONS as $permission) {
        expect($actual)->not->toContain($permission);
    }
});

it('回收迁移幂等：连续执行两次 up() 结果稳定且不报错', function () {
    $this->seed(RolePermissionSeeder::class);
    csRunMigration('2026_09_17_000039_add_cs_permissions.php');

    csRunMigration('2026_09_17_000040_revoke_cs_permissions_from_operator.php');
    $once = csPermissionNamesOf('operator');

    csRunMigration('2026_09_17_000040_revoke_cs_permissions_from_operator.php');

    expect(csPermissionNamesOf('operator'))->toBe($once);
});

it('新工单通知按权限投递：超管收到、operator 收不到（不再「有通知打不开」）', function () {
    $this->seed(RolePermissionSeeder::class);

    $buyer = User::create([
        'username' => 'csnt'.uniqid(), 'phone' => '13800002222',
        'password' => bcrypt('Test@1234'), 'status' => 1,
    ]);
    $type = CsTicketType::create([
        'name' => '售前咨询', 'code' => 'pre_sale', 'require_order' => false, 'sort' => 1, 'is_active' => true,
    ]);

    app(CsTicketService::class)->createTicket($buyer, [
        'type_id' => $type->id, 'title' => '咨询问题', 'content' => '请帮忙处理',
    ]);

    $admin = SysUser::where('username', 'admin')->firstOrFail();
    $operator = SysUser::where('username', 'operator')->firstOrFail();

    $newTicketNotifications = fn (int $userId) => Notification::query()
        ->where('type', NotificationService::TYPE_CS_TICKET_NEW)
        ->where('receiver_type', Notification::RECEIVER_ADMIN)
        ->where('user_id', $userId)
        ->count();

    expect($newTicketNotifications($admin->id))->toBe(1);
    expect($newTicketNotifications($operator->id))->toBe(0);
});

it('按权限投递的降级：无人持有 cs.ticket.view 时不抛异常', function () {
    // 不 seed：库中没有任何账号持有 cs.ticket.view
    $buyer = User::create([
        'username' => 'csnt'.uniqid(), 'phone' => '13800003333',
        'password' => bcrypt('Test@1234'), 'status' => 1,
    ]);
    $type = CsTicketType::create([
        'name' => '售前咨询', 'code' => 'pre_sale', 'require_order' => false, 'sort' => 1, 'is_active' => true,
    ]);

    $ticket = app(CsTicketService::class)->createTicket($buyer, [
        'type_id' => $type->id, 'title' => '咨询问题', 'content' => '请帮忙处理',
    ]);

    expect(app(CsNotificationService::class)->notifyNewTicket($ticket))->toBe(0);
});

// ---------- CS-117 缺陷 #4：客服角色（cs_agent）同样有两条落地路径 ----------

it('角色身份唯一来源：AdminRole 的标识/中文名与 Seeder 建出的角色一一对应', function () {
    $this->seed(RolePermissionSeeder::class);

    foreach (AdminRole::BUILTIN as $name) {
        expect(Role::findByName($name, 'web')->display_name)->toBe(AdminRole::LABELS[$name]);
    }

    // 库里的后台角色就是 AdminRole::BUILTIN 这几个（多一个少一个都说明有人漏改）
    expect(Role::query()->where('guard_name', 'web')->pluck('name')->sort()->values()->all())
        ->toBe(collect(AdminRole::BUILTIN)->sort()->values()->all());
});

it('客服角色双路径一致：Seeder 与迁移 000041 得到同样的角色与权限', function () {
    $this->seed(RolePermissionSeeder::class);
    $fresh = csPermissionNamesOf(AdminRole::CS_AGENT);

    expect($fresh)->toBe(['cs.faq.manage', 'cs.ticket.handle', 'cs.ticket.view']);

    // 模拟「升级前」的存量库：角色不存在（权限码已由 000039 建好）
    $role = Role::findByName(AdminRole::CS_AGENT, 'web');
    DB::table('role_has_permissions')->where('role_id', $role->id)->delete();
    DB::table('model_has_roles')->where('role_id', $role->id)->delete();
    $role->delete();
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    csRunMigration('2026_09_17_000041_add_cs_agent_role.php');

    expect(csPermissionNamesOf(AdminRole::CS_AGENT))->toBe($fresh);
    expect(Role::findByName(AdminRole::CS_AGENT, 'web')->display_name)->toBe('客服');
});

it('客服角色迁移幂等：连续执行两次 up() 结果稳定且不报错', function () {
    $this->seed(RolePermissionSeeder::class);
    $once = csPermissionNamesOf(AdminRole::CS_AGENT);

    csRunMigration('2026_09_17_000041_add_cs_agent_role.php');
    csRunMigration('2026_09_17_000041_add_cs_agent_role.php');

    expect(csPermissionNamesOf(AdminRole::CS_AGENT))->toBe($once);
});
