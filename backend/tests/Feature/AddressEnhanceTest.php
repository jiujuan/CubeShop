<?php

use App\Models\UserAddress;
use App\Services\Address\AddressService;
use App\Services\Common\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 E04 / T-028：地址增强（标签 / 级联数据 / 智能解析 / 使用频次 / 默认接替）
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $cap = app(CaptchaService::class)->generate();
    $this->auth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/register', [
        'username' => 'addr'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token')];
});

function makeAddress($test, array $overrides = []): array
{
    return $test->postJson('/api/user/addresses', array_merge([
        'contact_name' => '张三', 'contact_phone' => '13800000000',
        'province' => '广东省', 'city' => '深圳市', 'district' => '南山区',
        'detail_address' => '科技路 1 号',
    ], $overrides), $test->auth)->json('data');
}

// ---------- 标签 ----------

test('TC-ADDR-E01 保存地址标签并回显', function () {
    $addr = makeAddress($this, ['label' => '公司']);
    expect($addr['label'])->toBe('公司');
});

test('TC-ADDR-E02 标签超长返回 422', function () {
    $resp = $this->postJson('/api/user/addresses', [
        'contact_name' => '张三', 'contact_phone' => '13800000000',
        'detail_address' => '科技路 1 号', 'label' => str_repeat('长', 20),
    ], $this->auth);
    $resp->assertStatus(422);
});

// ---------- 级联数据 ----------

test('TC-ADDR-E03 行政区划接口返回三级结构且含中国香港/澳门/台湾', function () {
    $resp = $this->getJson('/api/regions')->json();
    expect($resp['code'])->toBe(0);

    $provinces = collect($resp['data']['provinces']);
    $names = $provinces->pluck('name')->all();

    expect($names)->toContain('中国香港')
        ->and($names)->toContain('中国澳门')
        ->and($names)->toContain('中国台湾')
        ->and($names)->toContain('广东省');

    $gd = $provinces->firstWhere('name', '广东省');
    expect($gd['cities'])->not->toBeEmpty()
        ->and($gd['cities'][0])->toHaveKeys(['name', 'districts']);
});

// ---------- 智能解析 ----------

test('TC-ADDR-E04 解析完整一行地址', function () {
    $service = app(AddressService::class);
    $r = $service->parse('张三 13800000000 广东省深圳市南山区科技路1号');

    expect($r['contact_name'])->toBe('张三')
        ->and($r['contact_phone'])->toBe('13800000000')
        ->and($r['province'])->toBe('广东省')
        ->and($r['city'])->toBe('深圳市')
        ->and($r['district'])->toBe('南山区')
        ->and($r['detail_address'])->toBe('科技路1号')
        ->and($r['confidence'])->toBeGreaterThan(0.5);
});

test('TC-ADDR-E05 解析手机号在中间且无空格', function () {
    $service = app(AddressService::class);
    $r = $service->parse('李四13800000000浙江省杭州市西湖区文三路');

    expect($r['contact_phone'])->toBe('13800000000')
        ->and($r['province'])->toBe('浙江省')
        ->and($r['city'])->toBe('杭州市')
        ->and($r['district'])->toBe('西湖区');
});

test('TC-ADDR-E06 解析无法识别时返回空字段而非报错', function () {
    $resp = $this->postJson('/api/user/addresses/parse', ['text' => '随便一段无法识别的文本'], $this->auth)->json();
    expect($resp['code'])->toBe(0)
        ->and($resp['data']['province'])->toBe('')
        ->and($resp['data']['detail_address'])->not->toBe('');

    // 空文本 422
    $this->postJson('/api/user/addresses/parse', ['text' => ''], $this->auth)->assertStatus(422);
});

test('TC-ADDR-E07 解析接口未登录 401', function () {
    $this->postJson('/api/user/addresses/parse', ['text' => '张三 13800000000 广东省深圳市'])->assertStatus(401);
});

// ---------- 使用频次 ----------

test('TC-ADDR-E08 下单后地址使用频次递增', function () {
    $addr = makeAddress($this, ['is_default' => true]);
    $sku = createTestSku(stock: 10, price: '66.00');
    $this->postJson('/api/cart', ['sku_id' => $sku->id, 'quantity' => 1], $this->auth);

    $this->postJson('/api/orders', ['address_id' => $addr['id']], $this->auth)->assertOk();

    $row = UserAddress::find($addr['id']);
    expect($row->used_count)->toBe(1)
        ->and($row->last_used_at)->not->toBeNull();
});

test('TC-ADDR-E09 地址列表按使用频次倒序（默认置顶除外）', function () {
    $a1 = makeAddress($this, ['label' => '低频']);
    $a2 = makeAddress($this, ['label' => '高频']);
    // 两条均排除默认态，验证纯频次排序
    UserAddress::whereIn('id', [$a1['id'], $a2['id']])->update(['is_default' => false]);
    UserAddress::whereKey($a2['id'])->update(['used_count' => 5]);

    $list = $this->getJson('/api/user/addresses', $this->auth)->json('data');
    expect($list[0]['id'])->toBe($a2['id']) // 高频靠前
        ->and($list[0]['used_count'])->toBe(5);
});

// ---------- 默认接替 ----------

test('TC-ADDR-E10 删除默认地址后自动接替为剩余最新一条', function () {
    $a1 = makeAddress($this, ['is_default' => true]);
    $a2 = makeAddress($this, ['detail_address' => '滨海大道 2 号']);

    $this->deleteJson("/api/user/addresses/{$a1['id']}", [], $this->auth)->assertOk();

    $remaining = UserAddress::where('id', $a2['id'])->first();
    expect((bool) $remaining->is_default)->toBeTrue();
});

test('TC-ADDR-E11 删除唯一地址后无默认（明确空态）', function () {
    $a1 = makeAddress($this, ['is_default' => true]);
    $this->deleteJson("/api/user/addresses/{$a1['id']}", [], $this->auth)->assertOk();

    expect($this->getJson('/api/user/addresses', $this->auth)->json('data'))->toHaveCount(0);
});

test('TC-ADDR-E12 手机号列表脱敏、详情含完整号码', function () {
    $a = makeAddress($this);
    expect($a['contact_phone'])->toBe('138****0000')
        ->and($a['contact_phone_full'])->toBe('13800000000');
});
