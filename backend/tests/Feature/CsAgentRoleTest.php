<?php

use App\Models\CsFaqArticle;
use App\Models\CsFaqCategory;
use App\Models\CsTicketType;
use App\Models\Notification;
use App\Models\SysUser;
use App\Models\User;
use App\Services\Common\CaptchaService;
use App\Services\Cs\CsTicketService;
use App\Services\Notification\NotificationService;
use App\Support\AdminRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * CS-117 缺陷 #4 回归：独立「客服」角色（cs_agent）
 *
 * 缺陷表现：一期只有 super_admin / operator 两个后台角色，且 operator 明确不持有 cs.*，
 * 于是客服工作台只能由超管使用 —— 想给客服开号就得把商品/订单/退款/营销权限一起放出去。
 *
 * 修复后应满足：
 *   ① cs_agent 角色存在、中文名「客服」、只持有客服中心三个权限（无任何经营数据）；
 *   ② 超管可在「管理员账号」页创建客服账号（角色白名单已放开）；
 *   ③ 客服账号能开工单、回复、维护帮助中心，但打不开含销售额的工作台；
 *   ④ 转交名单按 cs.ticket.view 过滤 —— 客服无需 account.manage 也能转交；
 *   ⑤ 新工单通知按权限投递，客服「有通知也能打开」。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    /** 登录并返回鉴权头（买家端/后台共用 /api/auth/login，需图形验证码） */
    $this->loginAs = function (string $username, string $password): array {
        $cap = app(CaptchaService::class)->generate();

        $token = $this->postJson('/api/auth/login', [
            'username' => $username,
            'password' => $password,
            'captcha_id' => $cap['captcha_id'],
            'captcha_code' => $cap['debug_code'],
        ])->json('data.token');

        return ['Authorization' => 'Bearer '.$token];
    };

    $this->adminAuth = ($this->loginAs)('admin', 'Admin@123');
    $this->operatorAuth = ($this->loginAs)('operator', 'Operator@123');

    /** 经「管理员账号」接口创建客服账号（顺带验证角色白名单已放开） */
    $this->createAgent = function (string $username = 'kefu01', string $password = 'Kefu@1234'): SysUser {
        $this->withHeaders($this->adminAuth)->postJson('/api/admin/accounts', [
            'username' => $username,
            'password' => $password,
            'nickname' => '客服小美',
            'roles' => [AdminRole::CS_AGENT],
        ])->assertOk()->assertJsonPath('code', 0);

        return SysUser::where('username', $username)->firstOrFail();
    };
});

it('客服角色已内置：中文名「客服」，权限只有客服中心三个（无任何经营数据）', function () {
    $role = Role::findByName(AdminRole::CS_AGENT, 'web');

    expect($role->display_name)->toBe('客服');

    $permissions = $role->permissions->pluck('name')->sort()->values()->all();
    expect($permissions)->toBe(['cs.faq.manage', 'cs.ticket.handle', 'cs.ticket.view']);

    // 经营数据一律不给（工作台含今日销售额，最小权限）
    foreach (['dashboard.view', 'order.view', 'product.view', 'refund.view', 'payment.view', 'report.view'] as $forbidden) {
        expect($permissions)->not->toContain($forbidden);
    }
});

it('角色管理接口把客服标为内置角色（不可重命名/删除）', function () {
    $data = $this->withHeaders($this->adminAuth)->getJson('/api/admin/roles')->json('data');
    $byName = collect($data['roles'])->keyBy('name');

    expect($byName[AdminRole::CS_AGENT]['builtin'])->toBeTrue()
        ->and($byName[AdminRole::CS_AGENT]['label'])->toBe('客服');

    $id = $byName[AdminRole::CS_AGENT]['id'];
    $this->withHeaders($this->adminAuth)->putJson("/api/admin/roles/{$id}", ['name' => 'cs2'])
        ->assertStatus(403)->assertJsonPath('code', 40003);
    $this->withHeaders($this->adminAuth)->deleteJson("/api/admin/roles/{$id}")
        ->assertStatus(403)->assertJsonPath('code', 40003);
});

it('账号管理：可创建客服账号、可在列表中筛选到，且该账号能登录', function () {
    $agent = ($this->createAgent)();
    expect($agent->hasRole(AdminRole::CS_AGENT))->toBeTrue();

    $res = $this->withHeaders($this->adminAuth)->getJson('/api/admin/accounts?role='.AdminRole::CS_AGENT)->assertOk();
    expect(array_column($res->json('data.list'), 'username'))->toBe(['kefu01']);

    // cs_agent 现在属于后台内置角色，因此必须出现在管理员列表（此前会被 ADMIN_ROLES 过滤掉）
    $all = $this->withHeaders($this->adminAuth)->getJson('/api/admin/accounts')->json('data.list');
    expect(array_column($all, 'username'))->toContain('kefu01');

    // 非后台角色名仍被拒绝
    $this->withHeaders($this->adminAuth)->postJson('/api/admin/accounts', [
        'username' => 'nobody', 'password' => 'Abcd1234', 'roles' => ['customer'],
    ])->assertStatus(422);

    expect(($this->loginAs)('kefu01', 'Kefu@1234'))->toHaveKey('Authorization');
});

it('客服账号：工单与帮助中心可用，经营数据（工作台）不可用', function () {
    ($this->createAgent)();
    $agentAuth = ($this->loginAs)('kefu01', 'Kefu@1234');

    $buyer = User::create([
        'username' => 'csticket'.uniqid(), 'phone' => '138'.random_int(10000000, 99999999),
        'password' => bcrypt('Test@1234'), 'status' => 1,
    ]);
    $type = CsTicketType::create([
        'name' => '物流问题', 'code' => 'logistics', 'require_order' => false, 'sort' => 1, 'is_active' => true,
    ]);
    $ticket = app(CsTicketService::class)->createTicket($buyer, [
        'type_id' => $type->id, 'title' => '包裹没收到', 'content' => '三天了还没物流',
    ]);

    // 工单：查看 + 处理
    $this->withHeaders($agentAuth)->getJson('/api/admin/cs/tickets')->assertOk();
    $this->withHeaders($agentAuth)->getJson('/api/admin/cs/tickets/'.$ticket->id)->assertOk();
    $this->withHeaders($agentAuth)->postJson('/api/admin/cs/tickets/'.$ticket->id.'/messages', [
        'content' => '已为您核实物流',
    ])->assertOk();
    $this->withHeaders($agentAuth)->putJson('/api/admin/cs/tickets/'.$ticket->id.'/status', [
        'status' => 'processing',
    ])->assertOk();

    // 帮助中心：分类 / 文章维护
    $this->withHeaders($agentAuth)->getJson('/api/admin/cs/faq/categories')->assertOk();
    $this->withHeaders($agentAuth)->postJson('/api/admin/cs/faq/articles', [
        'category_id' => CsFaqCategory::create(['name' => '物流帮助', 'sort' => 1, 'is_active' => true])->id,
        'title' => '配送时效', 'content_md' => '48 小时内发货。', 'status' => CsFaqArticle::STATUS_DRAFT,
    ])->assertCreated();

    // 经营数据：一处都进不去
    $this->withHeaders($agentAuth)->getJson('/api/admin/dashboard')->assertForbidden();
    $this->withHeaders($agentAuth)->getJson('/api/admin/orders')->assertForbidden();
    $this->withHeaders($agentAuth)->getJson('/api/admin/accounts')->assertForbidden();
});

it('operator 与客服的职责边界不变：运营仍无任何客服权限', function () {
    $this->withHeaders($this->operatorAuth)->getJson('/api/admin/cs/tickets')->assertForbidden();
    $this->withHeaders($this->operatorAuth)->getJson('/api/admin/cs/faq/categories')->assertForbidden();

    // 反向：客服也拿不到运营权限
    ($this->createAgent)();
    $agentAuth = ($this->loginAs)('kefu01', 'Kefu@1234');
    $this->withHeaders($agentAuth)->getJson('/api/admin/products')->assertForbidden();
    $this->withHeaders($agentAuth)->getJson('/api/admin/refunds')->assertForbidden();
});

it('转交名单按权限过滤：客服无需 account.manage 即可读到，运营无权限', function () {
    ($this->createAgent)();
    $agentAuth = ($this->loginAs)('kefu01', 'Kefu@1234');

    $res = $this->withHeaders($agentAuth)->getJson('/api/admin/cs/assignees')->assertOk();
    $usernames = array_column($res->json('data'), 'username');

    // 持有 cs.ticket.view 的账号：超管（全权限）+ 客服本人
    expect($usernames)->toContain('kefu01')->toContain('admin')
        ->and($usernames)->not->toContain('operator');

    // 运营没有 cs.ticket.handle，看不到名单
    $this->withHeaders($this->operatorAuth)->getJson('/api/admin/cs/assignees')->assertForbidden();
});

it('新工单通知按权限投递：客服能收到（有通知且能打开）', function () {
    $agent = ($this->createAgent)();

    $buyer = User::create([
        'username' => 'csnotify'.uniqid(), 'phone' => '138'.random_int(10000000, 99999999),
        'password' => bcrypt('Test@1234'), 'status' => 1,
    ]);
    $type = CsTicketType::create([
        'name' => '售前咨询', 'code' => 'pre_sale', 'require_order' => false, 'sort' => 1, 'is_active' => true,
    ]);
    $ticket = app(CsTicketService::class)->createTicket($buyer, [
        'type_id' => $type->id, 'title' => '想咨询尺码', 'content' => '这件有 L 码吗',
    ]);

    $countFor = fn (int $userId) => Notification::query()
        ->where('type', NotificationService::TYPE_CS_TICKET_NEW)
        ->where('receiver_type', Notification::RECEIVER_ADMIN)
        ->where('user_id', $userId)
        ->count();

    expect($countFor($agent->id))->toBe(1);

    // 通知里的跳转链接指向客服工作台，而客服确实能打开该页面（不再「有通知打不开」）
    $notification = Notification::query()
        ->where('user_id', $agent->id)
        ->where('type', NotificationService::TYPE_CS_TICKET_NEW)
        ->firstOrFail();
    expect($notification->link)->toContain('/cs/tickets');

    $agentAuth = ($this->loginAs)('kefu01', 'Kefu@1234');
    $this->withHeaders($agentAuth)->getJson('/api/admin/cs/tickets/'.$ticket->id)->assertOk();
});
