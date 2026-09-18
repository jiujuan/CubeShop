<?php

use App\Services\Common\NoGeneratorService;
use App\Services\Common\NoGeneratorService as NoGen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
 * SEC-03 验收（评审文档 §9）：
 *   - 输出不匹配旧的 /^CS\d{8}\d{6}$/ 递增模式
 *   - 相邻单号差值无固定规律，无法由已知单号推出下一个
 *   - 大样本零重复
 */

test('SEC-03 单号格式：前缀 + 日期 + 10 位随机段，不再匹配旧的 6 位序列模式', function (string $prefix) {
    $no = app(NoGeneratorService::class)->generate($prefix);

    expect($no)->toStartWith($prefix)
        ->and(strlen($no))->toBe(strlen($prefix) + 8 + 10)
        ->and(substr($no, strlen($prefix), 8))->toBe(now()->format('Ymd'))
        // 旧格式 {prefix}{Ymd}{6位} 长度恰好 16，新格式必不为 16
        ->and($no)->not->toMatch('/^[A-Z]{2,3}\d{8}\d{6}$/')
        ->and($no)->toMatch('/^[A-Z]{2,3}\d{8}\d{10}$/');
})->with([
    '订单' => [NoGen::PREFIX_ORDER],
    '支付' => [NoGen::PREFIX_PAYMENT],
    '退款' => [NoGen::PREFIX_REFUND],
    '充值' => [NoGen::PREFIX_RECHARGE],
    '工单' => [NoGen::PREFIX_TICKET],
]);

test('SEC-03 随机段为首非零定长数字', function () {
    $service = app(NoGeneratorService::class);

    for ($i = 0; $i < 200; $i++) {
        $seg = $service->randomSegment();
        expect($seg)->toHaveLength(10)
            ->and($seg)->toMatch('/^[1-9]\d{9}$/');
    }
});

test('SEC-03 连续 1000 个订单号零重复（落库唯一性预检）', function () {
    $service = app(NoGeneratorService::class);
    $set = [];
    for ($i = 0; $i < 1000; $i++) {
        $set[] = $service->generateOrderNo();
    }

    expect(count($set))->toBe(1000)
        ->and(count(array_unique($set)))->toBe(1000);
});

test('SEC-03 相邻单号的随机段差值无固定规律（不可枚举）', function () {
    $service = app(NoGeneratorService::class);

    $segments = [];
    for ($i = 0; $i < 1000; $i++) {
        $no = $service->generateOrderNo();
        $segments[] = (int) substr($no, -10);
    }

    $diffs = [];
    for ($i = 1; $i < count($segments); $i++) {
        $diffs[] = $segments[$i] - $segments[$i - 1];
    }

    // 旧实现差值恒为 1（严格递增）；新实现差值应几乎两两不同
    expect(count(array_unique($diffs)))->toBeGreaterThan(950)
        ->and(abs($diffs[0]))->toBeGreaterThan(1)
        ->and(count(array_unique($segments)))->toBe(1000);
});

test('SEC-03 已知单号无法推出下一个单号', function () {
    $service = app(NoGeneratorService::class);

    $known = [];
    for ($i = 0; $i < 10; $i++) {
        $known[] = (int) substr($service->generateOrderNo(), -10);
    }

    // 构造"猜测集合"：每个已知值的 ±1..100 邻域
    $guessed = [];
    foreach ($known as $v) {
        for ($d = -100; $d <= 100; $d++) {
            $guessed[$v + $d] = true;
        }
    }

    $misses = 0;
    for ($i = 0; $i < 50; $i++) {
        $next = (int) substr($service->generateOrderNo(), -10);
        if (! isset($guessed[$next])) {
            $misses++;
        }
    }

    // 50 次猜测全部落空才算通过（单号可预测时该断言必然失败）
    expect($misses)->toBe(50);
});

test('SEC-03 10 万次随机段采样：碰撞数在泊松期望内且非单调', function () {
    $service = app(NoGeneratorService::class);

    $seen = [];
    $duplicates = 0;
    $prev = null;
    $monotonic = true;

    for ($i = 0; $i < 100000; $i++) {
        $seg = $service->randomSegment();

        if (isset($seen[$seg])) {
            $duplicates++;
        } else {
            $seen[$seg] = true;
        }

        if ($prev !== null && $seg <= $prev) {
            $monotonic = false; // 只要出现一次非递增即证明无单调性
        }
        $prev = $seg;
    }

    // 取值空间 9×10^9，N=10^5 时期望碰撞对数 λ = N(N-1)/(2M) ≈ 0.556
    // 泊松分布下 P(X ≥ 6) ≈ 3×10^-5，取 6 为上界可避免用例 flaky
    expect($duplicates)->toBeLessThanOrEqual(6)
        ->and($monotonic)->toBeFalse();
});

test('SEC-03 撞号时自动重新摇号（唯一性预检生效）', function () {
    $date = now()->format('Ymd');
    $conflict = NoGen::PREFIX_ORDER.$date.'1111111111';

    insertNoProbeOrder($conflict);

    // 强制第一次摇到已存在的号，第二次给出可用号
    $service = new class extends NoGeneratorService
    {
        public int $calls = 0;

        public function randomSegment(int $length = self::RANDOM_LENGTH): string
        {
            $this->calls++;

            return $this->calls === 1 ? '1111111111' : '2222222222';
        }
    };

    $no = $service->generate(NoGen::PREFIX_ORDER);

    expect($service->calls)->toBe(2)
        ->and($no)->toBe(NoGen::PREFIX_ORDER.$date.'2222222222');
});

test('SEC-03 连续冲突超过重试上限时抛异常（快速失败而非返回重复号）', function () {
    $service = new class extends NoGeneratorService
    {
        public function randomSegment(int $length = self::RANDOM_LENGTH): string
        {
            return '1111111111'; // 恒定值，必定与预置记录冲突
        }
    };

    $date = now()->format('Ymd');
    insertNoProbeOrder(NoGen::PREFIX_ORDER.$date.'1111111111');

    expect(fn () => $service->generate(NoGen::PREFIX_ORDER))
        ->toThrow(RuntimeException::class);
});

/** 写入一条仅用于单号唯一性预检的订单（补齐 NOT NULL 字段） */
function insertNoProbeOrder(string $orderNo): void
{
    DB::table('orders')->insert([
        'order_no' => $orderNo,
        'user_id' => createTestUser()->id,
        'status' => 'pending_payment',
        'total_amount' => 0,
        'freight_amount' => 0,
        'pay_amount' => 0,
        'address_snapshot' => json_encode([], JSON_UNESCAPED_UNICODE),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('SEC-03 生成过程不再依赖 biz_no_sequences（序列表已退役）', function () {
    $service = app(NoGeneratorService::class);

    for ($i = 0; $i < 20; $i++) {
        $service->generateOrderNo();
        $service->generateTicketNo();
    }

    // 序列表应在迁移中退役（DROP）；若仍在，单号生成也不应写入它
    if (Schema::hasTable('biz_no_sequences')) {
        expect(DB::table('biz_no_sequences')->count())->toBe(0);
    } else {
        expect(Schema::hasTable('biz_no_sequences'))->toBeFalse();
    }
});

test('generateRechargeNo 使用 RC 前缀', function () {
    expect(app(NoGeneratorService::class)->generateRechargeNo())->toStartWith(NoGen::PREFIX_RECHARGE);
});
