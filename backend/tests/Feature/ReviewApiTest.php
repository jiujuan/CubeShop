<?php

use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Review;
use App\Services\Common\CaptchaService;
use App\Services\Review\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 T-015 / T-016 / T-017：商品评价（提交/展示/我的/后台管理）
 *
 * 覆盖：可评价条件、唯一性、评分与内容校验、敏感词、匿名脱敏、
 *       先审后显开关、评分汇总、修改窗口、后台审核/驳回/回复/删除、权限。
 */
beforeEach(function () {
    seedRoles();
    $this->seed(\Database\Seeders\ReviewNotifySeeder::class);
    config(['app.debug' => true]);

    // 管理员（超管，含 review.manage + config.manage）
    $cap = app(CaptchaService::class)->generate();
    $this->adminAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'admin',
        'password' => 'Admin@123',
        'captcha_id' => $cap['captcha_id'],
        'captcha_code' => $cap['debug_code'],
    ])->json('data.token')];

    // 运营（有 review.manage，无 config.manage）
    $capOp = app(CaptchaService::class)->generate();
    $this->operatorAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/login', [
        'username' => 'operator',
        'password' => 'Operator@123',
        'captcha_id' => $capOp['captcha_id'],
        'captcha_code' => $capOp['debug_code'],
    ])->json('data.token')];

    // 买家
    $cap2 = app(CaptchaService::class)->generate();
    $this->buyerAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/register', [
        'username' => 'revbuyer'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap2['debug_code'],
        'captcha_id' => $cap2['captcha_id'],
    ])->json('data.token')];
    $this->buyerId = $this->getJson('/api/auth/me', $this->buyerAuth)->json('data.id');

    // 商品与 SKU
    $this->sku = createTestSku(stock: 10, price: '58.00');
    $this->productId = $this->sku->product_id;
});

/**
 * 直接铺已完成订单（避开下单/支付链路），返回 [order, item]
 *
 * @return array{0: Order, 1: OrderItem}
 */
function seedReviewableOrder(int $userId, string $status = Order::STATUS_COMPLETED, ?int $productId = null, ?int $skuId = null): array
{
    static $seq = 0;
    $seq++;

    $order = Order::create([
        'order_no' => 'RV'.now()->format('Ymd').str_pad((string) $seq, 6, '0', STR_PAD_LEFT).random_int(10, 99),
        'user_id' => $userId,
        'status' => $status,
        'total_amount' => '58.00',
        'freight_amount' => '0.00',
        'pay_amount' => '58.00',
        'address_snapshot' => ['contact_name' => '张三', 'contact_phone' => '13800000000', 'full_address' => '广东省深圳市南山区科技路 1 号'],
        'paid_at' => now(),
        'shipped_at' => now(),
        'completed_at' => $status === Order::STATUS_COMPLETED ? now() : null,
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $productId,
        'sku_id' => $skuId,
        'product_title' => '测试商品',
        'sku_specs' => ['规格' => '标准'],
        'sku_image' => null,
        'price' => '58.00',
        'quantity' => 1,
        'total_amount' => '58.00',
    ]);

    return [$order, $item];
}

// ---------- T-015 提交评价 ----------

test('TC-REV-001 已完成订单可提交评价且默认直接通过', function () {
    [$order, $item] = seedReviewableOrder($this->buyerId, productId: $this->productId, skuId: $this->sku->id);

    $resp = $this->postJson("/api/orders/{$order->id}/items/{$item->id}/review", [
        'rating' => 5,
        'content' => '东西不错，物流很快',
        'images' => ['https://cdn.example.com/a.jpg'],
    ], $this->buyerAuth);

    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('data.status'))->toBe(Review::STATUS_APPROVED);

    $review = Review::where('order_item_id', $item->id)->first();
    expect($review)->not->toBeNull()
        ->and($review->rating)->toBe(5)
        ->and($review->product_id)->toBe($this->productId)
        ->and($review->images)->toBe(['https://cdn.example.com/a.jpg']);
});

test('TC-REV-002 同一行项目重复评价被拒', function () {
    [$order, $item] = seedReviewableOrder($this->buyerId, productId: $this->productId, skuId: $this->sku->id);

    $this->postJson("/api/orders/{$order->id}/items/{$item->id}/review", ['rating' => 5], $this->buyerAuth)
        ->assertStatus(200);

    $second = $this->postJson("/api/orders/{$order->id}/items/{$item->id}/review", ['rating' => 4], $this->buyerAuth);

    expect($second->json('code'))->toBe(40009);
});

test('TC-REV-003 未完成订单不可评价', function () {
    [$order, $item] = seedReviewableOrder($this->buyerId, Order::STATUS_SHIPPED, $this->productId, $this->sku->id);

    $resp = $this->postJson("/api/orders/{$order->id}/items/{$item->id}/review", ['rating' => 5], $this->buyerAuth);

    expect($resp->json('code'))->toBe(40009);
});

test('TC-REV-004 他人订单不可评价', function () {
    [$order, $item] = seedReviewableOrder($this->buyerId, productId: $this->productId, skuId: $this->sku->id);

    // 另一个买家
    $cap = app(CaptchaService::class)->generate();
    $otherAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/register', [
        'username' => 'other'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token')];

    $this->postJson("/api/orders/{$order->id}/items/{$item->id}/review", ['rating' => 5], $otherAuth)
        ->assertStatus(404);
});

test('TC-REV-005 评分越界返回 422', function () {
    [$order, $item] = seedReviewableOrder($this->buyerId, productId: $this->productId, skuId: $this->sku->id);

    $this->postJson("/api/orders/{$order->id}/items/{$item->id}/review", ['rating' => 0], $this->buyerAuth)
        ->assertStatus(422);
    $this->postJson("/api/orders/{$order->id}/items/{$item->id}/review", ['rating' => 6], $this->buyerAuth)
        ->assertStatus(422);
});

test('TC-REV-006 内容超过 500 字被拒', function () {
    [$order, $item] = seedReviewableOrder($this->buyerId, productId: $this->productId, skuId: $this->sku->id);

    $resp = $this->postJson("/api/orders/{$order->id}/items/{$item->id}/review", [
        'rating' => 5,
        'content' => str_repeat('好', 501),
    ], $this->buyerAuth);

    // 422（验证器 max:500）
    expect($resp->status())->toBe(422);
});

test('TC-REV-007 敏感词命中被拒', function () {
    [$order, $item] = seedReviewableOrder($this->buyerId, productId: $this->productId, skuId: $this->sku->id);

    $resp = $this->postJson("/api/orders/{$order->id}/items/{$item->id}/review", [
        'rating' => 5,
        'content' => '这个可以帮人刷单',
    ], $this->buyerAuth);

    expect($resp->json('code'))->toBe(40000);
});

test('TC-REV-008 开启先审后显后新评价进入待审核', function () {
    app(\App\Services\Common\ConfigService::class)->set('review.audit_mode', '1');

    [$order, $item] = seedReviewableOrder($this->buyerId, productId: $this->productId, skuId: $this->sku->id);
    $resp = $this->postJson("/api/orders/{$order->id}/items/{$item->id}/review", ['rating' => 5], $this->buyerAuth);

    expect($resp->json('data.status'))->toBe(Review::STATUS_PENDING);
});

test('TC-REV-009 未登录提交评价返回 401', function () {
    [$order, $item] = seedReviewableOrder($this->buyerId, productId: $this->productId, skuId: $this->sku->id);
    $this->postJson("/api/orders/{$order->id}/items/{$item->id}/review", ['rating' => 5])->assertStatus(401);
});

// ---------- T-016 评价展示 ----------

test('TC-REV-010 商品评价列表仅返回已通过评价', function () {
    // 三条：approved / pending / rejected
    Review::create(['order_id' => 1, 'order_item_id' => 9001, 'user_id' => $this->buyerId, 'product_id' => $this->productId, 'rating' => 5, 'content' => 'approved one', 'status' => Review::STATUS_APPROVED]);
    Review::create(['order_id' => 1, 'order_item_id' => 9002, 'user_id' => $this->buyerId, 'product_id' => $this->productId, 'rating' => 1, 'content' => 'pending one', 'status' => Review::STATUS_PENDING]);
    Review::create(['order_id' => 1, 'order_item_id' => 9003, 'user_id' => $this->buyerId, 'product_id' => $this->productId, 'rating' => 1, 'content' => 'rejected one', 'status' => Review::STATUS_REJECTED]);

    $resp = $this->getJson("/api/products/{$this->productId}/reviews")->json('data');

    // SEC-04：评价总数属平台经营指标，公开接口只给 has_more，不给精确 total
    expect($resp['list'])->toHaveCount(1)
        ->and($resp['list'][0]['content'])->toBe('approved one')
        ->and($resp['pagination']['total'])->toBeNull()
        ->and($resp['pagination']['total_pages'])->toBeNull()
        ->and($resp['pagination']['has_more'])->toBeFalse();
});

test('TC-REV-011 评分汇总均值/星级分布/好评率正确', function () {
    foreach ([5, 5, 4, 3, 1] as $i => $star) {
        Review::create([
            'order_id' => 1, 'order_item_id' => 8000 + $i, 'user_id' => $this->buyerId,
            'product_id' => $this->productId, 'rating' => $star, 'status' => Review::STATUS_APPROVED,
        ]);
    }

    $summary = $this->getJson("/api/products/{$this->productId}/reviews")->json('data.summary');

    // avg = (5+5+4+3+1)/5 = 3.6 ; good(4,5)=3 → 60%
    expect($summary['total'])->toBe(5)
        ->and($summary['avg'])->toEqual(3.6)
        ->and($summary['star_counts']['5'])->toBe(2)
        ->and($summary['star_counts']['1'])->toBe(1)
        ->and($summary['good_rate'])->toEqual(60.0);
});

test('TC-REV-012 匿名评价昵称脱敏且不返回头像', function () {
    [$order, $item] = seedReviewableOrder($this->buyerId, productId: $this->productId, skuId: $this->sku->id);
    $this->postJson("/api/orders/{$order->id}/items/{$item->id}/review", [
        'rating' => 5, 'content' => '匿名评价', 'is_anonymous' => true,
    ], $this->buyerAuth);

    $row = $this->getJson("/api/products/{$this->productId}/reviews")->json('data.list.0');

    expect($row['is_anonymous'])->toBeTrue()
        ->and($row['avatar'])->toBeNull()
        ->and($row['nickname'])->toContain('**');
});

test('TC-REV-013 按评分筛选评价', function () {
    Review::create(['order_id' => 1, 'order_item_id' => 7001, 'user_id' => $this->buyerId, 'product_id' => $this->productId, 'rating' => 5, 'status' => Review::STATUS_APPROVED]);
    Review::create(['order_id' => 1, 'order_item_id' => 7002, 'user_id' => $this->buyerId, 'product_id' => $this->productId, 'rating' => 2, 'status' => Review::STATUS_APPROVED]);

    $resp = $this->getJson("/api/products/{$this->productId}/reviews?rating=5")->json('data');

    expect($resp['list'])->toHaveCount(1)
        ->and($resp['list'][0]['rating'])->toBe(5);
});

test('TC-REV-014 我的评价列表只含本人评价', function () {
    [$order, $item] = seedReviewableOrder($this->buyerId, productId: $this->productId, skuId: $this->sku->id);
    $this->postJson("/api/orders/{$order->id}/items/{$item->id}/review", ['rating' => 5, 'content' => '我的'], $this->buyerAuth);

    // 他人评价
    Review::create(['order_id' => 1, 'order_item_id' => 6001, 'user_id' => 999999, 'product_id' => $this->productId, 'rating' => 3, 'status' => Review::STATUS_APPROVED]);

    $resp = $this->getJson('/api/me/reviews', $this->buyerAuth)->json('data');

    expect($resp['pagination']['total'])->toBe(1)
        ->and($resp['list'][0]['content'])->toBe('我的');
});

// ---------- P2-11：评价出口 public_id（修复订单详情「修改评价」定位失败） ----------

test('TC-PID-052-001 我的评价 order_id/order_item_id/product_id 均为 public_id', function () {
    [$order, $item] = seedReviewableOrder($this->buyerId, productId: $this->productId, skuId: $this->sku->id);
    $this->postJson("/api/orders/{$order->id}/items/{$item->id}/review", ['rating' => 5, 'content' => '好'], $this->buyerAuth);

    $row = $this->getJson('/api/me/reviews', $this->buyerAuth)->json('data.list.0');

    expect($row['order_item_id'])->toBe($item->public_id)
        ->and($row['order_id'])->toBe($order->public_id)
        ->and($row['product_id'])->toBe(Product::find($this->productId)->public_id)
        ->and($row['can_edit'])->toBeTrue();

    // 与订单详情出口的 items[].id 完全一致（前端据此匹配「修改评价」行项目）
    $detail = $this->getJson("/api/orders/{$order->public_id}", $this->buyerAuth)->json('data');
    expect($detail['items'][0]['id'])->toBe($row['order_item_id']);
});

test('TC-PID-052-002 修改评价支持 public_id 定位', function () {
    [$order, $item] = seedReviewableOrder($this->buyerId, productId: $this->productId, skuId: $this->sku->id);
    $this->postJson("/api/orders/{$order->id}/items/{$item->id}/review", ['rating' => 5, 'content' => '初版'], $this->buyerAuth);
    $review = Review::where('order_item_id', $item->id)->first();

    $resp = $this->putJson("/api/reviews/{$review->public_id}", ['rating' => 2, 'content' => '改版'], $this->buyerAuth);

    expect($resp->json('code'))->toBe(0)
        ->and($resp->json('data.id'))->toBe($review->public_id);
    $review->refresh();
    expect($review->rating)->toBe(2)
        ->and($review->content)->toBe('改版');
});

// ---------- T-016 修改评价 ----------

test('TC-REV-015 30 天内未修改过可修改一次并置 edited_at', function () {
    [$order, $item] = seedReviewableOrder($this->buyerId, productId: $this->productId, skuId: $this->sku->id);
    $this->postJson("/api/orders/{$order->id}/items/{$item->id}/review", ['rating' => 5, 'content' => '初版'], $this->buyerAuth);
    $review = Review::where('order_item_id', $item->id)->first();

    $resp = $this->putJson("/api/reviews/{$review->id}", ['rating' => 3, 'content' => '改版'], $this->buyerAuth);

    expect($resp->json('code'))->toBe(0);
    $review->refresh();
    expect($review->rating)->toBe(3)
        ->and($review->content)->toBe('改版')
        ->and($review->edited_at)->not->toBeNull();
});

test('TC-REV-016 已修改过的评价不可再次修改', function () {
    [$order, $item] = seedReviewableOrder($this->buyerId, productId: $this->productId, skuId: $this->sku->id);
    $this->postJson("/api/orders/{$order->id}/items/{$item->id}/review", ['rating' => 5], $this->buyerAuth);
    $review = Review::where('order_item_id', $item->id)->first();

    $this->putJson("/api/reviews/{$review->id}", ['rating' => 4], $this->buyerAuth)->assertStatus(200);
    $second = $this->putJson("/api/reviews/{$review->id}", ['rating' => 2], $this->buyerAuth);

    expect($second->json('code'))->toBe(40009);
});

test('TC-REV-017 修改他人评价被拒', function () {
    [$order, $item] = seedReviewableOrder($this->buyerId, productId: $this->productId, skuId: $this->sku->id);
    $this->postJson("/api/orders/{$order->id}/items/{$item->id}/review", ['rating' => 5], $this->buyerAuth);
    $review = Review::where('order_item_id', $item->id)->first();

    $cap = app(CaptchaService::class)->generate();
    $otherAuth = ['Authorization' => 'Bearer '.$this->postJson('/api/auth/register', [
        'username' => 'other2'.uniqid(),
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ])->json('data.token')];

    $resp = $this->putJson("/api/reviews/{$review->id}", ['rating' => 1], $otherAuth);

    expect($resp->json('code'))->toBe(40003);
});

// ---------- T-017 后台管理 ----------

test('TC-REV-018 后台评价列表待审核优先且带统计', function () {
    Review::create(['order_id' => 1, 'order_item_id' => 5001, 'user_id' => $this->buyerId, 'product_id' => $this->productId, 'rating' => 5, 'status' => Review::STATUS_APPROVED]);
    Review::create(['order_id' => 1, 'order_item_id' => 5002, 'user_id' => $this->buyerId, 'product_id' => $this->productId, 'rating' => 3, 'status' => Review::STATUS_PENDING]);

    $resp = $this->getJson('/api/admin/reviews', $this->adminAuth)->json('data');

    expect($resp['list'][0]['status'])->toBe(Review::STATUS_PENDING)
        ->and($resp['stats']['pending'])->toBe(1)
        ->and($resp['stats']['total'])->toBe(2);
});

test('TC-REV-019 后台审核通过 / 驳回', function () {
    $r = Review::create(['order_id' => 1, 'order_item_id' => 5003, 'user_id' => $this->buyerId, 'product_id' => $this->productId, 'rating' => 3, 'status' => Review::STATUS_PENDING]);

    $this->postJson("/api/admin/reviews/{$r->id}/approve", [], $this->adminAuth)->assertStatus(200);
    expect($r->fresh()->status)->toBe(Review::STATUS_APPROVED);

    $this->postJson("/api/admin/reviews/{$r->id}/reject", ['reason' => '含广告信息'], $this->adminAuth)->assertStatus(200);
    $r->refresh();
    expect($r->status)->toBe(Review::STATUS_REJECTED)
        ->and($r->reject_reason)->toBe('含广告信息');
});

test('TC-REV-020 后台回复产生商家回复并通知买家', function () {
    [$order, $item] = seedReviewableOrder($this->buyerId, productId: $this->productId, skuId: $this->sku->id);
    $this->postJson("/api/orders/{$order->id}/items/{$item->id}/review", ['rating' => 5], $this->buyerAuth);
    $review = Review::where('order_item_id', $item->id)->first();

    $this->postJson("/api/admin/reviews/{$review->id}/reply", ['content' => '感谢支持～'], $this->adminAuth)
        ->assertStatus(200);

    $review->refresh();
    expect($review->reply_content)->toBe('感谢支持～')
        ->and($review->reply_at)->not->toBeNull();

    // 触发 ReviewReplied → 买家收到通知
    $notify = Notification::where('user_id', $this->buyerId)
        ->where('type', \App\Services\Notification\NotificationService::TYPE_REVIEW_REPLIED)
        ->first();
    expect($notify)->not->toBeNull();
});

test('TC-REV-021 后台删除评价', function () {
    $r = Review::create(['order_id' => 1, 'order_item_id' => 5004, 'user_id' => $this->buyerId, 'product_id' => $this->productId, 'rating' => 5, 'status' => Review::STATUS_APPROVED]);

    $this->deleteJson("/api/admin/reviews/{$r->id}", [], $this->adminAuth)->assertStatus(200);
    expect(Review::find($r->id))->toBeNull();
});

test('TC-REV-022 无 review.manage 权限访问后台被拒', function () {
    // 买家无后台权限
    $this->getJson('/api/admin/reviews', $this->buyerAuth)->assertStatus(403);
});

test('TC-REV-023 审核模式开关仅超管可改', function () {
    $this->postJson('/api/admin/reviews/audit-mode', ['enabled' => true], $this->adminAuth)->assertStatus(200);
    expect(app(ReviewService::class)->auditMode())->toBeTrue();

    // 运营无 config.manage → 403
    $this->postJson('/api/admin/reviews/audit-mode', ['enabled' => false], $this->operatorAuth)->assertStatus(403);
});

test('TC-REV-024 评价后评分汇总缓存失效', function () {
    // 先读一次建立缓存
    $before = $this->getJson("/api/products/{$this->productId}/reviews")->json('data.summary.total');
    expect($before)->toBe(0);

    [$order, $item] = seedReviewableOrder($this->buyerId, productId: $this->productId, skuId: $this->sku->id);
    $this->postJson("/api/orders/{$order->id}/items/{$item->id}/review", ['rating' => 5], $this->buyerAuth);

    $after = $this->getJson("/api/products/{$this->productId}/reviews")->json('data.summary.total');
    expect($after)->toBe(1);
});

// ---------- 评价隐藏 / 显示（V1.2） ----------

test('TC-REV-025 后台隐藏评价后前台不再展示且不计入评分，可恢复', function () {
    [$order, $item] = seedReviewableOrder($this->buyerId, productId: $this->productId, skuId: $this->sku->id);
    $this->postJson("/api/orders/{$order->id}/items/{$item->id}/review", ['rating' => 5], $this->buyerAuth);
    $review = Review::where('order_item_id', $item->id)->first();

    // 隐藏前：列表可见、汇总计数 1
    expect($this->getJson("/api/products/{$this->productId}/reviews")->json('data.list'))->toHaveCount(1)
        ->and($this->getJson("/api/products/{$this->productId}/reviews")->json('data.summary.total'))->toBe(1);

    // 隐藏
    $this->postJson("/api/admin/reviews/{$review->id}/hidden", ['hidden' => true], $this->adminAuth)
        ->assertStatus(200);

    expect($review->fresh()->is_hidden)->toBeTrue();

    // 商品评价列表不再展示，评分汇总也不计入（缓存已失效）
    expect($this->getJson("/api/products/{$this->productId}/reviews")->json('data.list'))->toHaveCount(0)
        ->and($this->getJson("/api/products/{$this->productId}/reviews")->json('data.summary.total'))->toBe(0);

    // 买家的「我的评价」同样不展示
    expect($this->getJson('/api/me/reviews', $this->buyerAuth)->json('data.list'))->toHaveCount(0);

    // 后台列表仍可见（支持按 hidden 过滤）
    expect($this->getJson('/api/admin/reviews?hidden=1', $this->adminAuth)->json('data.list'))->toHaveCount(1);
    expect($this->getJson('/api/admin/reviews?hidden=0', $this->adminAuth)->json('data.list'))->toHaveCount(0);

    // 恢复显示
    $this->postJson("/api/admin/reviews/{$review->id}/hidden", ['hidden' => false], $this->adminAuth)
        ->assertStatus(200);

    expect($this->getJson("/api/products/{$this->productId}/reviews")->json('data.list'))->toHaveCount(1)
        ->and($this->getJson("/api/products/{$this->productId}/reviews")->json('data.summary.total'))->toBe(1);
});

test('TC-REV-026 无 review.manage 权限隐藏评价被拒', function () {
    $r = Review::create(['order_id' => 1, 'order_item_id' => 5005, 'user_id' => $this->buyerId, 'product_id' => $this->productId, 'rating' => 5, 'status' => Review::STATUS_APPROVED]);

    // 买家无后台权限
    $this->postJson("/api/admin/reviews/{$r->id}/hidden", ['hidden' => true], $this->buyerAuth)->assertStatus(403);
    expect($r->fresh()->is_hidden)->toBeFalse();
});
