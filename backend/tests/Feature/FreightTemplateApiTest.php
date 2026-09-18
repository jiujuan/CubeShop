<?php

use App\Models\FreightTemplate;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRoles();

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin',
        'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];
});

// ============ 权限 ============

test('未登录访问运费模板列表返回 401', function () {
    $this->getJson('/api/admin/freight-templates')->assertUnauthorized();
});

// ============ 创建 ============

test('管理员可创建 fixed 模板', function () {
    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/freight-templates', [
        'name' => '普通快递', 'mode' => 'fixed', 'rules' => ['amount' => '8.00'], 'status' => 1,
    ]);

    $res->assertStatus(201)
        ->assertJsonPath('data.name', '普通快递')
        ->assertJsonPath('data.mode', 'fixed')
        ->assertJsonPath('data.mode_label', '固定运费')
        ->assertJsonPath('data.rules.amount', '8.00');
});

test('创建 weight 模板', function () {
    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/freight-templates', [
        'name' => '重量计费', 'mode' => 'weight',
        'rules' => ['first_weight_g' => 1000, 'first_fee' => '8.00', 'step_weight_g' => 1000, 'step_fee' => '2.00'],
    ]);

    $res->assertStatus(201)->assertJsonPath('data.mode_label', '按重量');
});

test('创建 region 模板（合法省 code）', function () {
    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/freight-templates', [
        'name' => '偏远地区加价', 'mode' => 'region',
        'rules' => [
            'default' => ['amount' => '8.00'],
            'areas' => [['provinces' => ['540000', '650000'], 'amount' => '20.00']],
        ],
    ]);

    $res->assertStatus(201)->assertJsonPath('data.mode_label', '按地区');
});

// ============ 规则校验（fail-closed 422） ============

test('region 规则含非法省 code 返回 422', function () {
    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/freight-templates', [
        'name' => '坏规则', 'mode' => 'region',
        'rules' => ['areas' => [['provinces' => ['990000'], 'amount' => '20.00']]],
    ]);

    $res->assertStatus(422);
    expect(FreightTemplate::where('name', '坏规则')->exists())->toBeFalse();
});

test('region 规则 areas 为空返回 422', function () {
    $this->withHeaders($this->adminAuth)->postJson('/api/admin/freight-templates', [
        'name' => '坏规则', 'mode' => 'region', 'rules' => ['areas' => []],
    ])->assertStatus(422);
});

test('weight 规则缺续重字段返回 422', function () {
    $this->withHeaders($this->adminAuth)->postJson('/api/admin/freight-templates', [
        'name' => '坏规则', 'mode' => 'weight',
        'rules' => ['first_weight_g' => 1000, 'first_fee' => '8.00'],
    ])->assertStatus(422);
});

test('fixed 规则金额为负返回 422', function () {
    $this->withHeaders($this->adminAuth)->postJson('/api/admin/freight-templates', [
        'name' => '坏规则', 'mode' => 'fixed', 'rules' => ['amount' => '-1'],
    ])->assertStatus(422);
});

// ============ 列表 / 编辑 / 删除 ============

test('列表分页且支持 mode 筛选', function () {
    FreightTemplate::create(['name' => 'A 固定', 'mode' => 'fixed', 'rules' => ['amount' => '8.00']]);
    FreightTemplate::create(['name' => 'B 重量', 'mode' => 'weight',
        'rules' => ['first_weight_g' => 1000, 'first_fee' => '8.00', 'step_weight_g' => 1000, 'step_fee' => '2.00']]);

    $all = $this->withHeaders($this->adminAuth)->getJson('/api/admin/freight-templates');
    $all->assertOk()->assertJsonPath('data.pagination.total', 2);

    $filtered = $this->withHeaders($this->adminAuth)->getJson('/api/admin/freight-templates?mode=weight');
    expect($filtered->json('data.list'))->toHaveCount(1)
        ->and($filtered->json('data.list.0.name'))->toBe('B 重量');
});

test('编辑模板：不传 mode 时沿用既有 mode 校验 rules', function () {
    $t = FreightTemplate::create(['name' => '区域模板', 'mode' => 'region',
        'rules' => ['areas' => [['provinces' => ['540000'], 'amount' => '20.00']]]]);

    // 只改费用，未传 mode → 按 region 校验，合法省 code 通过
    $ok = $this->withHeaders($this->adminAuth)->putJson("/api/admin/freight-templates/{$t->id}", [
        'rules' => ['areas' => [['provinces' => ['650000'], 'amount' => '25.00']]],
    ]);
    $ok->assertOk()->assertJsonPath('data.rules.areas.0.amount', '25.00');

    // 换成非法省 code → 422
    $this->withHeaders($this->adminAuth)->putJson("/api/admin/freight-templates/{$t->id}", [
        'rules' => ['areas' => [['provinces' => ['990000'], 'amount' => '25.00']]],
    ])->assertStatus(422);
});

test('删除模板', function () {
    $t = FreightTemplate::create(['name' => '待删除', 'mode' => 'fixed', 'rules' => ['amount' => '8.00']]);

    $this->withHeaders($this->adminAuth)->deleteJson("/api/admin/freight-templates/{$t->id}")->assertOk();

    expect(FreightTemplate::find($t->id))->toBeNull();
});

test('status 传 0 创建为停用态', function () {
    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/freight-templates', [
        'name' => '停用模板', 'mode' => 'fixed', 'rules' => ['amount' => '8.00'], 'status' => 0,
    ]);

    expect($res->json('data.status'))->toBe(0);
});

// ============ 全局默认（Stage4 修复：模板需有引用才生效） ============

test('设为全局默认后，未绑定模板的商品按该模板 region 规则计费（上海 5 元）', function () {
    $tpl = FreightTemplate::create([
        'name' => '江浙沪优惠', 'mode' => 'region', 'status' => 1,
        'rules' => ['areas' => [['provinces' => ['310000', '320000', '330000', '340000'], 'amount' => '5.00']], 'default' => ['amount' => '10.00']],
    ]);

    $res = $this->withHeaders($this->adminAuth)->postJson("/api/admin/freight-templates/{$tpl->id}/set-default");
    $res->assertOk()->assertJsonPath('data.default_id', $tpl->id);

    // 复刻用户场景：登录买家 + 上海地址 → freight-preview 应命中江浙沪 area 5 元
    $cap2 = app(CaptchaService::class)->generate();
    $buyerAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/register', [
        'username' => 'buyer_frt_'.uniqid(), 'password' => 'Test@1234', 'password_confirmation' => 'Test@1234',
        'code' => $cap2['debug_code'], 'captcha_id' => $cap2['captcha_id'],
    ])->json('data.token')];
    $addrId = $this->withHeaders($buyerAuth)->postJson('/api/user/addresses', [
        'contact_name' => '张三', 'contact_phone' => '13800138000',
        'province' => '上海市', 'city' => '上海市', 'district' => '黄浦区', 'detail_address' => '南京东路 1 号',
        'is_default' => true,
    ])->json('data.id');

    $sku = createTestSku(price: '50.00');
    $body = $this->withHeaders($buyerAuth)->postJson('/api/orders/freight-preview', [
        'items' => [['sku_id' => $sku->id, 'quantity' => 1]],
        'address_id' => $addrId,
    ])->json('data');

    expect($body['freight_amount'])->toBe('5.00')
        ->and($body['detail'][0]['source'])->toBe('region_area');

    // 游客无地址 → 无法按省匹配 → 落模板 default 10 元（合理降级，非 bug）
    $guest = $this->postJson('/api/freight/estimate', [
        'items' => [['sku_id' => $sku->id, 'quantity' => 1]],
    ])->json('data');
    expect($guest['freight_amount'])->toBe('10.00')
        ->and($guest['detail'][0]['source'])->toBe('region_default');

    // 列表返回 default_id
    $this->withHeaders($this->adminAuth)->getJson('/api/admin/freight-templates')
        ->assertJsonPath('data.default_id', $tpl->id);
});

test('停用的模板不能设为全局默认', function () {
    $tpl = FreightTemplate::create(['name' => '停用模板', 'mode' => 'fixed', 'status' => 0, 'rules' => ['amount' => '5.00']]);

    $res = $this->withHeaders($this->adminAuth)->postJson("/api/admin/freight-templates/{$tpl->id}/set-default");

    $res->assertStatus(400);
    // 迁移 000057 预置空串行：未设默认 = ''（getInt 归一为 0）
    expect(app(\App\Services\Common\ConfigService::class)->get('order.freight_template_id'))->toBe('');
});

test('取消全局默认后回到旧口径固定运费', function () {
    $tpl = FreightTemplate::create([
        'name' => '区域模板', 'mode' => 'region', 'status' => 1,
        'rules' => ['areas' => [['provinces' => ['310000'], 'amount' => '5.00']], 'default' => ['amount' => '10.00']],
    ]);
    app(\App\Services\Common\ConfigService::class)->set('order.freight_template_id', (string) $tpl->id);

    $res = $this->withHeaders($this->adminAuth)->postJson('/api/admin/freight-templates/clear-default');
    $res->assertOk()->assertJsonPath('data.default_id', 0);

    $sku = createTestSku(price: '50.00');
    $body = $this->postJson('/api/freight/estimate', [
        'items' => [['sku_id' => $sku->id, 'quantity' => 1]],
    ])->json('data');

    expect($body['freight_amount'])->toBe('10.00'); // 旧口径 freight_default
});
