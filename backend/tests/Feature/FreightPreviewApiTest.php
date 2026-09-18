<?php

use App\Models\FreightTemplate;
use App\Services\Common\CaptchaService;
use App\Services\Common\ConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * POST /api/orders/freight-preview（T-053 Stage 2）
 * 结算页运费实时预估：与下单同一套引擎（FreightService → FreightCalculator）。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);
    $cap = app(CaptchaService::class)->generate();
    $this->token = $this->postJson('/api/auth/register', [
        'username' => 'freightuser'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token');
    $this->auth = ['Authorization' => 'Bearer '.$this->token];

    $addr = $this->postJson('/api/user/addresses', [
        'contact_name' => '收件人', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'detail_address' => '科技路 1 号',
        'is_default' => true,
    ], $this->auth)->json('data');
    $this->addressId = $addr['id'] ?? $addr;

    $this->sku = createTestSku(stock: 10, price: '66.00');
});

test('未登录访问运费预览返回 401', function () {
    $this->postJson('/api/orders/freight-preview', [
        'items' => [['sku_id' => $this->sku->id, 'quantity' => 1]],
    ])->assertStatus(401);
});

test('无模板时走旧口径：固定运费 + 满额包邮', function () {
    $body = $this->postJson('/api/orders/freight-preview', [
        'items' => [['sku_id' => $this->sku->id, 'quantity' => 1]],
        'address_id' => $this->addressId,
    ], $this->auth)->json();

    expect($body['code'])->toBe(0)
        ->and($body['data']['freight_amount'])->toBe('10.00')
        ->and($body['data']['free_shipping'])->toBeFalse();

    // 达到包邮阈值 → 运费 0
    app(ConfigService::class)->set('order.free_shipping_threshold', '99.00');
    $body = $this->postJson('/api/orders/freight-preview', [
        'items' => [['sku_id' => $this->sku->id, 'quantity' => 2]],
        'address_id' => $this->addressId,
    ], $this->auth)->json();

    expect($body['data']['freight_amount'])->toBe('0.00')
        ->and($body['data']['free_shipping'])->toBeTrue();
});

test('region 模板按省 code 命中并计费', function () {
    $tpl = FreightTemplate::create([
        'name' => '区域模板', 'mode' => 'region', 'status' => 1,
        'rules' => ['areas' => [['provinces' => ['440000'], 'amount' => '8.00']]],
    ]);
    $this->sku->product->update(['freight_template_id' => $tpl->id]);

    $body = $this->postJson('/api/orders/freight-preview', [
        'items' => [['sku_id' => $this->sku->id, 'quantity' => 2]],
        'address_id' => $this->addressId,
    ], $this->auth)->json('data');

    expect($body['freight_amount'])->toBe('8.00')
        ->and($body['not_support'])->toBeFalse()
        ->and($body['detail'][0]['source'])->toBe('region_area');
});

test('region 模板未命中且无 default 返回 not_support 标记', function () {
    $tpl = FreightTemplate::create([
        'name' => '仅北京', 'mode' => 'region', 'status' => 1,
        'rules' => ['areas' => [['provinces' => ['110000'], 'amount' => '8.00']]],
    ]);
    $this->sku->product->update(['freight_template_id' => $tpl->id]);

    // 广东地址不在配送范围：接口不报错，返回 not_support 供前端禁用提交
    $body = $this->postJson('/api/orders/freight-preview', [
        'items' => [['sku_id' => $this->sku->id, 'quantity' => 1]],
        'address_id' => $this->addressId,
    ], $this->auth)->json('data');

    expect($body['not_support'])->toBeTrue()
        ->and($body['freight_amount'])->toBe('0.00');
});

test('模板停用时降级为旧口径固定运费', function () {
    $tpl = FreightTemplate::create([
        'name' => '已停用', 'mode' => 'fixed', 'status' => 0,
        'rules' => ['amount' => '5.00'],
    ]);
    $this->sku->product->update(['freight_template_id' => $tpl->id]);

    $body = $this->postJson('/api/orders/freight-preview', [
        'items' => [['sku_id' => $this->sku->id, 'quantity' => 1]],
        'address_id' => $this->addressId,
    ], $this->auth)->json('data');

    expect($body['freight_amount'])->toBe('10.00');
});

test('参数缺失返回 422', function () {
    $this->postJson('/api/orders/freight-preview', [], $this->auth)->assertStatus(422);
});

// ---------- Stage 3：公开预估接口 POST /api/freight/estimate ----------

test('公开预估接口：游客无模板走旧口径固定运费', function () {
    $body = $this->postJson('/api/freight/estimate', [
        'items' => [['sku_id' => $this->sku->id, 'quantity' => 1]],
    ])->json();

    expect($body['code'])->toBe(0)
        ->and($body['data']['freight_amount'])->toBe('10.00')
        ->and($body['data']['not_support'])->toBeFalse();
});

test('公开预估接口：游客 + region 模板（无 default）返回 not_support 标记', function () {
    $tpl = FreightTemplate::create([
        'name' => '仅北京', 'mode' => 'region', 'status' => 1,
        'rules' => ['areas' => [['provinces' => ['110000'], 'amount' => '8.00']]],
    ]);
    $this->sku->product->update(['freight_template_id' => $tpl->id]);

    $body = $this->postJson('/api/freight/estimate', [
        'items' => [['sku_id' => $this->sku->id, 'quantity' => 1]],
    ])->json('data');

    // 游客无法换算省 code → 不能命中 area 且无 default → 标记不可配送（前端展示「按地区计费」）
    expect($body['not_support'])->toBeTrue();
});

test('公开预估接口：登录用户传 address_id 可按省精确命中', function () {
    $tpl = FreightTemplate::create([
        'name' => '区域模板', 'mode' => 'region', 'status' => 1,
        'rules' => ['areas' => [['provinces' => ['440000'], 'amount' => '6.00']]],
    ]);
    $this->sku->product->update(['freight_template_id' => $tpl->id]);

    $body = $this->postJson('/api/freight/estimate', [
        'items' => [['sku_id' => $this->sku->id, 'quantity' => 2]],
        'address_id' => $this->addressId,
    ], $this->auth)->json('data');

    expect($body['freight_amount'])->toBe('6.00')
        ->and($body['detail'][0]['source'])->toBe('region_area');
});

test('公开预估接口：address_id 无法伪造他人地址（游客传入被忽略）', function () {
    $body = $this->postJson('/api/freight/estimate', [
        'items' => [['sku_id' => $this->sku->id, 'quantity' => 1]],
        'address_id' => 999999,
    ])->json('data');

    // 无模板 → 旧口径；他人/不存在地址不会泄露任何信息
    expect($body['freight_amount'])->toBe('10.00');
});

test('模板删除：仍有商品绑定时拒绝删除', function () {
    $cap = app(CaptchaService::class)->generate();
    $adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin',
        'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    $tpl = FreightTemplate::create(['name' => '被引用', 'mode' => 'fixed', 'rules' => ['amount' => '8.00']]);
    $this->sku->product->update(['freight_template_id' => $tpl->id]);

    $body = $this->deleteJson('/api/admin/freight-templates/'.$tpl->id, [], $adminAuth)->json();

    expect($body['code'])->toBe(40009)
        ->and(FreightTemplate::find($tpl->id))->not->toBeNull();
});
