<?php

use App\Models\CsQuickReply;
use App\Models\CsTicketType;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * CS-203：快捷回复模板接口集成测试
 *
 * 鉴权矩阵：
 * - admin（super_admin，含 cs.faq.manage + cs.ticket.handle）→ 可读可管理
 * - operator（运营，无 cs.*）→ 403
 * - 仅持 cs.ticket.handle 的客服账号 → 可读下拉（200），不可管理（403）
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $this->type = CsTicketType::create([
        'name' => '售后', 'code' => 'aftersale', 'is_active' => true, 'sort' => 1,
    ]);

    $this->adminAuth = loginAs('admin', 'Admin@123');
    $this->operatorAuth = loginAs('operator', 'Operator@123');
    $this->handleAuth = adminWith(['cs.ticket.handle']); // 仅可取用，不可管理
});

function loginAs(string $username, string $password): array
{
    $cap = app(CaptchaService::class)->generate();
    $token = test()->postJson('/api/auth/login', [
        'username' => $username, 'password' => $password,
        'captcha_id' => $cap['captcha_id'], 'captcha_code' => $cap['debug_code'],
    ])->json('data.token');

    return ['Authorization' => 'Bearer '.$token];
}

function adminWith(array $permissions): array
{
    $user = \App\Models\SysUser::create([
        'username' => 'csq'.uniqid(), 'password' => 'Cs@12345', 'nickname' => 'CS', 'status' => 1,
    ]);
    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return loginAs($user->username, 'Cs@12345');
}

// ---------- 管理 CRUD ----------

it('管理员可创建快捷回复', function () {
    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/cs/quick-replies', [
        'title' => '开场白', 'content' => '您好，很高兴为您服务', 'type_id' => null, 'sort' => 0,
    ]);

    $res->assertCreated()->assertJsonPath('data.title', '开场白');
    expect(CsQuickReply::count())->toBe(1);
    expect(CsQuickReply::first()->created_by)->not->toBeNull();
});

it('管理员可编辑快捷回复', function () {
    $r = CsQuickReply::create(['title' => '旧', 'content' => '旧内容', 'type_id' => null, 'sort' => 0]);

    $res = $this->withHeaders($this->adminAuth)->putJson("/api/admin/cs/quick-replies/{$r->id}", [
        'title' => '新', 'sort' => 5,
    ]);

    $res->assertOk()->assertJsonPath('data.title', '新')->assertJsonPath('data.sort', 5);
    expect(CsQuickReply::find($r->id)->content)->toBe('旧内容'); // 未传 content 保持不变
    expect(CsQuickReply::find($r->id)->updated_by)->not->toBeNull();
});

it('管理员可删除快捷回复（物理删除）', function () {
    $r = CsQuickReply::create(['title' => '待删', 'content' => 'x', 'type_id' => null, 'sort' => 0]);

    $this->withHeaders($this->adminAuth)->deleteJson("/api/admin/cs/quick-replies/{$r->id}")->assertOk();
    expect(CsQuickReply::find($r->id))->toBeNull();
});

it('管理列表返回全量并按 sort/id 排序且带 type_name', function () {
    CsQuickReply::create(['title' => '通用B', 'content' => 'x', 'type_id' => null, 'sort' => 1]);
    CsQuickReply::create(['title' => '专属', 'content' => 'x', 'type_id' => $this->type->id, 'sort' => 3]);

    $res = $this->withHeaders($this->adminAuth)->getJson('/api/admin/cs/quick-replies');
    $res->assertOk();
    expect($res->json('data.0.type_name'))->toBeNull();   // 通用模板
    expect($res->json('data.1.type_name'))->toBe('售后');  // 专属模板
});

// ---------- 类型筛选（工作台下拉） ----------

it('按 type_id 取用返回通用 + 该类型专属，排除其他类型', function () {
    CsQuickReply::create(['title' => '通用', 'content' => 'x', 'type_id' => null, 'sort' => 1]);
    CsQuickReply::create(['title' => '专属', 'content' => 'x', 'type_id' => $this->type->id, 'sort' => 2]);
    CsQuickReply::create(['title' => '其他', 'content' => 'x', 'type_id' => 999, 'sort' => 1]);

    $res = $this->withHeaders($this->adminAuth)->getJson("/api/admin/cs/quick-replies?type_id={$this->type->id}");

    $res->assertOk();
    expect($res->json('data'))->toHaveCount(2)
        ->and(collect($res->json('data'))->pluck('title')->all())->toBe(['通用', '专属']);
});

// ---------- 权限矩阵 ----------

it('无 cs 权限的运营访问读取接口返回 403', function () {
    $this->withHeaders($this->operatorAuth)->getJson('/api/admin/cs/quick-replies')->assertForbidden();
});

it('无 cs 权限的运营提交管理接口返回 403', function () {
    $this->withHeaders($this->operatorAuth)
        ->postJson('/api/admin/cs/quick-replies', ['title' => 'x', 'content' => 'y'])
        ->assertForbidden();
});

it('仅持 cs.ticket.handle 的客服可取用下拉但不可管理', function () {
    CsQuickReply::create(['title' => '通用', 'content' => 'x', 'type_id' => null, 'sort' => 0]);

    $this->withHeaders($this->handleAuth)->getJson('/api/admin/cs/quick-replies')->assertOk();
    $this->withHeaders($this->handleAuth)
        ->postJson('/api/admin/cs/quick-replies', ['title' => 'x', 'content' => 'y'])
        ->assertForbidden();
});

// ---------- 校验 ----------

it('非法 type_id 返回 422', function () {
    $this->withHeaders($this->adminAuth)
        ->postJson('/api/admin/cs/quick-replies', ['title' => 'x', 'content' => 'y', 'type_id' => 999999])
        ->assertStatus(422);
});

it('模板内容超长返回 422', function () {
    $this->withHeaders($this->adminAuth)
        ->postJson('/api/admin/cs/quick-replies', ['title' => 'x', 'content' => str_repeat('长', 2001)])
        ->assertStatus(422);
});

it('标题缺失返回 422', function () {
    $this->withHeaders($this->adminAuth)
        ->postJson('/api/admin/cs/quick-replies', ['content' => 'y'])
        ->assertStatus(422);
});
