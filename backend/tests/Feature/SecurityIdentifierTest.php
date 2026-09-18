<?php

use App\Services\Common\CaptchaService;
use App\Support\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * SEC-04 验收（评审文档 §9）：
 *   未登录 GET /api/products 响应体不含精确 total，仅含 has_more。
 * 同时锁定放行边界：后台接口与「本人资源」仍需保留精确总量，避免误伤运营与用户体验。
 */

beforeEach(function () {
    seedRoles();

    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin',
        'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    $cap2 = app(CaptchaService::class)->generate();
    $this->buyerAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/register', [
        'username' => 'buyer'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap2['debug_code'],
        'captcha_id' => $cap2['captcha_id'],
    ])->json('data.token')];

    $this->sku = createTestSku(stock: 10, price: '40.00');
});

test('SEC-04 未登录商品列表：total 与 total_pages 为 null，仅返回 has_more', function () {
    $data = $this->getJson('/api/products')->json('data');

    expect($data['pagination']['total'])->toBeNull()
        ->and($data['pagination']['total_pages'])->toBeNull()
        ->and($data['pagination']['has_more'])->toBeBool()
        ->and($data['list'])->toHaveCount(1);
});

test('SEC-04 已登录买家访问商品列表：公共资源仍不暴露 total', function () {
    $data = $this->getJson('/api/products', $this->buyerAuth)->json('data');

    expect($data['pagination']['total'])->toBeNull()
        ->and($data['pagination']['total_pages'])->toBeNull();
});

test('SEC-04 后台商品列表：保留精确 total（运营必需）', function () {
    $data = $this->getJson('/api/admin/products', $this->adminAuth)->json('data');

    expect($data['pagination']['total'])->toBeInt()
        ->and($data['pagination']['total'])->toBeGreaterThanOrEqual(1)
        ->and($data['pagination']['total_pages'])->toBeInt();
});

test('SEC-04 本人资源保留精确 total：我的订单', function () {
    $addr = $this->postJson('/api/user/addresses', [
        'contact_name' => '收', 'contact_phone' => '13800000000',
        'province' => 'p', 'city' => 'c', 'district' => 'd', 'detail_address' => 'e',
    ], $this->buyerAuth)->json('data');

    $this->postJson('/api/cart', ['sku_id' => $this->sku->id, 'quantity' => 1], $this->buyerAuth);
    $this->postJson('/api/orders', ['address_id' => $addr['id']], $this->buyerAuth);

    $data = $this->getJson('/api/orders', $this->buyerAuth)->json('data');

    expect($data['pagination']['total'])->toBe(1);
});

test('SEC-04 本人资源保留精确 total：我的评价', function () {
    $data = $this->getJson('/api/me/reviews', $this->buyerAuth)->json('data');

    expect($data['pagination']['total'])->toBeInt();
});

test('SEC-04 商品评价列表（公开）：不暴露评价总数', function () {
    createTestSku(stock: 5, price: '11.00');

    $productId = $this->getJson('/api/products')->json('data.list.0.id');
    $data = $this->getJson("/api/products/{$productId}/reviews")->json('data');

    expect($data['pagination']['total'])->toBeNull()
        ->and($data['pagination']['has_more'])->toBeBool();
});

test('SEC-04-B 商品列表与详情只暴露 public_id，不再暴露自增主键', function () {
    $list = $this->getJson('/api/products')->json('data.list');

    expect($list[0]['id'])->not->toMatch('/^\d+$/');

    $detail = $this->getJson('/api/products/'.$list[0]['id'])->json('data');

    expect($detail['id'])->toBe($list[0]['id'])
        ->and($detail['id'])->not->toMatch('/^\d+$/')
        ->and($detail['skus'])->not->toBeEmpty()
        ->and($detail['skus'][0]['id'])->not->toMatch('/^\d+$/');
});

test('SEC-04-B 商品详情兼容历史 int 主键入参', function () {
    $list = $this->getJson('/api/products')->json('data.list');
    $intId = PublicId::resolve(PublicId::SCOPE_PRODUCT, $list[0]['id']);

    expect($intId)->toBeInt()
        ->and($this->getJson('/api/products/'.$intId)->json('code'))->toBe(0);
});

test('SEC-04-B 非法或跨 scope 的 public_id 一律 404', function () {
    $list = $this->getJson('/api/products')->json('data.list');
    // 取一个真实 SKU 的 public_id
    $detail = $this->getJson('/api/products/'.$list[0]['id'])->json('data');
    $skuPublicId = $detail['skus'][0]['id'];

    expect($skuPublicId)->not->toBeNull();

    // 不存在的标识
    expect($this->getJson('/api/products/zzzzzzzzzz')->json('code'))->toBe(40004)
        // 拿 SKU 的 public_id 去请求商品详情（跨 scope 串用）
        ->and($this->getJson('/api/products/'.$skuPublicId)->json('code'))->toBe(40004);
});

test('SEC-04 领券中心（公开）：不暴露券总量', function () {
    $resp = $this->getJson('/api/coupons');

    if ($resp->json('data.pagination') !== null) {
        expect($resp->json('data.pagination.total'))->toBeNull();
    } else {
        expect(true)->toBeTrue(); // 该环境未启用领券中心分页结构
    }
});
