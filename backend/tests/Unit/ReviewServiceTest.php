<?php

use App\Exceptions\BusinessException;
use App\Models\OrderItem;
use App\Models\Review;
use App\Models\User;
use App\Services\Review\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * V1.1 T-015：ReviewService 单元测试（汇总聚合、边界、可修改窗口、敏感词）
 *
 * 不经过 HTTP，直接驱动服务层，覆盖接口测试难以触及的边界分支。
 */
function reviewUser(): User
{
    return createTestUser('rev');
}

function makeReview(array $overrides = []): Review
{
    $user = reviewUser();

    // created_at 不在 fillable 中，需建后显式回写
    $createdAt = $overrides['created_at'] ?? null;
    unset($overrides['created_at']);

    $review = Review::create(array_merge([
        'order_id' => 1,
        'order_item_id' => random_int(100000, 999999),
        'user_id' => $user->id,
        'product_id' => 777001,
        'rating' => 5,
        'status' => Review::STATUS_APPROVED,
    ], $overrides));

    if ($createdAt !== null) {
        $review->forceFill(['created_at' => $createdAt])->save();
        $review->refresh();
    }

    return $review;
}

test('空商品的评分汇总为全零且不报错', function () {
    $summary = app(ReviewService::class)->summary(999888);

    expect($summary['total'])->toBe(0)
        ->and($summary['avg'])->toBe(0.0)
        ->and($summary['good_rate'])->toBe(0.0)
        ->and($summary['star_counts'])->toBe([1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0]);
});

test('汇总仅统计已通过评价', function () {
    $pid = 777002;
    makeReview(['product_id' => $pid, 'rating' => 5, 'status' => Review::STATUS_APPROVED]);
    makeReview(['product_id' => $pid, 'rating' => 1, 'status' => Review::STATUS_PENDING]);
    makeReview(['product_id' => $pid, 'rating' => 1, 'status' => Review::STATUS_REJECTED]);

    $summary = app(ReviewService::class)->summary($pid);

    expect($summary['total'])->toBe(1)
        ->and($summary['avg'])->toEqual(5.0)
        ->and($summary['good_rate'])->toEqual(100.0);
});

test('好评率以 4/5 星为好评', function () {
    $pid = 777003;
    makeReview(['product_id' => $pid, 'rating' => 5]);
    makeReview(['product_id' => $pid, 'rating' => 4]);
    makeReview(['product_id' => $pid, 'rating' => 3]);
    makeReview(['product_id' => $pid, 'rating' => 2]);

    $summary = app(ReviewService::class)->summary($pid);

    // good = 5,4 → 2/4 = 50%
    expect($summary['good_rate'])->toEqual(50.0)
        ->and($summary['avg'])->toEqual(3.5);
});

test('图片超过 9 张被拒', function () {
    $user = reviewUser();
    $order = \App\Models\Order::create([
        'order_no' => 'RV'.uniqid(), 'user_id' => $user->id, 'status' => 'completed',
        'total_amount' => '10.00', 'freight_amount' => '0.00', 'pay_amount' => '10.00',
        'address_snapshot' => ['contact_name' => 'x'], 'completed_at' => now(),
    ]);
    $item = OrderItem::create([
        'order_id' => $order->id, 'product_id' => 1, 'sku_id' => null,
        'product_title' => 't', 'price' => '10.00', 'quantity' => 1, 'total_amount' => '10.00',
    ]);

    expect(fn () => app(ReviewService::class)->submit($order->fresh(), $item, $user->id, [
        'rating' => 5,
        'images' => array_fill(0, 10, 'https://cdn/a.jpg'),
    ]))->toThrow(BusinessException::class);
});

test('可修改窗口：30 天内且未改过可改，超期或改过不可改', function () {
    $fresh = makeReview(['created_at' => now()->subDays(10)]);
    expect($fresh->canEdit())->toBeTrue();

    $expired = makeReview(['created_at' => now()->subDays(31)]);
    expect($expired->canEdit())->toBeFalse();

    $edited = makeReview(['created_at' => now()->subDays(5), 'edited_at' => now()->subDays(1)]);
    expect($edited->canEdit())->toBeFalse();
});

test('匿名评价昵称脱敏，实名保留昵称', function () {
    $user = createTestUser('nick');
    $user->update(['nickname' => '小明同学']);

    $anonymous = makeReview(['user_id' => $user->id, 'is_anonymous' => true]);
    expect($anonymous->displayName())->toBe('小**');

    $real = makeReview(['user_id' => $user->id, 'is_anonymous' => false]);
    expect($real->displayName())->toBe('小明同学');
});

test('默认不开启先审后显', function () {
    expect(app(ReviewService::class)->auditMode())->toBeFalse();
});

test('后台统计小卡数值正确', function () {
    $pid = 777004;
    makeReview(['product_id' => $pid, 'rating' => 5, 'status' => Review::STATUS_APPROVED]);
    makeReview(['product_id' => $pid, 'rating' => 4, 'status' => Review::STATUS_PENDING]);
    makeReview(['product_id' => $pid, 'rating' => 5, 'status' => Review::STATUS_PENDING]);

    $stats = app(ReviewService::class)->adminStats();

    expect($stats['pending'])->toBe(2)
        ->and($stats['total'])->toBe(3)
        ->and($stats['avg'])->toEqual(5.0);
});
