<?php

use App\Models\CartItem;
use App\Models\Order;
use App\Models\Refund;
use App\Models\RefundDispute;
use App\Models\UserAddress;
use App\Services\Common\CaptchaService;
use App\Services\Common\ConfigService;
use App\Services\Order\OrderService;
use App\Services\Refund\RefundService;
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

    // 建买家 + paid 订单（同 RefundReturnApiTest 模式：createTestUser + createToken 拿真实 sanctum token；
    // 订单归属必须是持 token 买家本人，否则 ownOrder 404）。本地闭包避免与其它测试文件全局函数重名。
    $this->mkPaidOrder = function (): array {
        $buyer = createTestUser('policybuyer');
        $auth = ['Authorization' => 'Bearer '.$buyer->createToken('policy')->plainTextToken];

        $sku = createTestSku(stock: 20, price: '100.00');
        CartItem::create(['user_id' => $buyer->id, 'sku_id' => $sku->id, 'quantity' => 1]);
        $address = UserAddress::create([
            'user_id' => $buyer->id, 'contact_name' => 'a', 'contact_phone' => 'b',
            'province' => 'p', 'city' => 'c', 'district' => 'd', 'detail_address' => 'e',
        ]);
        $svc = app(OrderService::class);
        $order = $svc->createFromCart($buyer->id, $address->id, null, null);
        $order = $svc->transitionTo($order, Order::STATUS_PAID);

        return [$buyer, $order, $auth];
    };
});

test('退款策略读取默认值、部分更新与模板清空', function () {
    // 默认值（Seeder 播种由 updateOrCreate 保证，测试库无种子 → 未配置键走 RefundSettings 兜底）
    $res = $this->getJson('/api/admin/refunds/policy', $this->adminAuth);
    $res->assertOk();
    expect($res->json('data'))->toBe([
        'auto_approve_amount' => '0.00',
        'max_retry' => 3,
        'dispute_sla_hours' => 48,
        'return_address_template' => '',
    ]);

    // 全量更新：金额归一两位小数
    $this->putJson('/api/admin/refunds/policy', array_merge([
        'auto_approve_amount' => '30.5',
        'max_retry' => 5,
        'dispute_sla_hours' => 24,
    ], []), $this->adminAuth)->assertOk();

    $saved = $this->getJson('/api/admin/refunds/policy', $this->adminAuth)->json('data');
    expect($saved['auto_approve_amount'])->toBe('30.50')
        ->and($saved['max_retry'])->toBe(5)
        ->and($saved['dispute_sla_hours'])->toBe(24)
        ->and($saved['return_address_template'])->toBe('');

    // 只更新出现的键：仅改 max_retry，其余不动
    $this->putJson('/api/admin/refunds/policy', ['max_retry' => 2], $this->adminAuth)->assertOk();
    $saved = $this->getJson('/api/admin/refunds/policy', $this->adminAuth)->json('data');
    expect($saved['max_retry'])->toBe(2)
        ->and($saved['auto_approve_amount'])->toBe('30.50')
        ->and($saved['dispute_sla_hours'])->toBe(24);

    // 模板写入与清空（null = 清空）
    $this->putJson('/api/admin/refunds/policy', ['return_address_template' => "广东省深圳市\n科技路 1 号 3 楼"], $this->adminAuth)->assertOk();
    expect($this->getJson('/api/admin/refunds/policy', $this->adminAuth)->json('data.return_address_template'))
        ->toBe("广东省深圳市\n科技路 1 号 3 楼");
    $this->putJson('/api/admin/refunds/policy', ['return_address_template' => null], $this->adminAuth)->assertOk();
    expect($this->getJson('/api/admin/refunds/policy', $this->adminAuth)->json('data.return_address_template'))->toBe('');

    // 校验：max_retry 非法值 422
    $this->putJson('/api/admin/refunds/policy', ['max_retry' => -1], $this->adminAuth)->assertStatus(422);
});

test('auto_approve_amount 命中阈值时买家申请自动审核通过', function () {
    app(ConfigService::class)->set('refund.auto_approve_amount', '50.00');

    [$user, $order, $buyerAuth] = ($this->mkPaidOrder)();

    // 30 元 ≤ 50 → 自动同意。用退货退款类型验证（审核通过停在待寄回，不发起渠道退款——
    // 本用例订单无支付单，仅退款类型会因找不到支付单 404，渠道打款路径由后台审核用例覆盖）
    $res = $this->postJson("/api/orders/{$order->public_id}/refund", [
        'type' => 'return_refund',
        'amount' => 30,
        'reason' => '不想要了',
        'return_details' => [
            ['sku_id' => $order->items->first()->sku_id, 'quantity' => 1],
        ],
    ], $buyerAuth);
    $res->assertOk();
    expect($res->json('data.status'))->toBe('approved')
        ->and($res->json('data.return_status'))->toBe('waiting_return');

    $auto = Refund::where('order_id', $order->id)->first();
    expect($auto->status)->toBe('approved')
        ->and($auto->return_status)->toBe('waiting_return')
        ->and($auto->admin_remark)->toBe('系统自动审核：退款金额未超自动同意阈值')
        ->and($auto->processed_by)->toBe(0);
});

test('auto_approve_amount 超阈值仍走人工审核', function () {
    app(ConfigService::class)->set('refund.auto_approve_amount', '50.00');

    [$user, $order, $buyerAuth] = ($this->mkPaidOrder)();

    $res = $this->postJson("/api/orders/{$order->public_id}/refund", [
        'type' => 'refund',
        'amount' => 80,
        'reason' => '质量问题',
    ], $buyerAuth);
    $res->assertOk();
    expect($res->json('data.status'))->toBe('pending');

    $refund = Refund::where('order_id', $order->id)->first();
    expect($refund->status)->toBe('pending')->and($refund->processed_by)->toBeNull();
});

test('max_retry 配置生效：retry 上限与异常队列按新阈值判定', function () {
    app(ConfigService::class)->set('refund.max_retry', '1');

    [$user, $order, $buyerAuth] = ($this->mkPaidOrder)();

    $refund = Refund::create([
        'refund_no' => 'RFPOLICY1',
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'user_id' => $user->id,
        'amount' => '10.00',
        'status' => 'failed',
        'retry_count' => 1,
        'failed_reason' => '渠道超时',
    ]);

    // retry_count=1 已达新上限 → 拒绝重试
    app(RefundService::class)->retry($refund);
})->throws(Exception::class, '已达最大重试次数（1），请转人工处理');

test('dispute_sla_hours 生效：纠纷列表返回 sla_overdue', function () {
    app(ConfigService::class)->set('refund.dispute_sla_hours', '24');

    [$user, $order, $buyerAuth] = ($this->mkPaidOrder)();

    $refund = Refund::create([
        'refund_no' => 'RFSLA1',
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'user_id' => $user->id,
        'amount' => '10.00',
        'status' => 'rejected',
    ]);

    $dispute = RefundDispute::create([
        'refund_id' => $refund->id,
        'order_id' => $order->id,
        'user_id' => $user->id,
        'reason_code' => RefundDispute::REASON_REFUND_REJECTED,
        'description' => '超时未处理',
        'status' => RefundDispute::STATUS_OPENED,
    ]);

    // 开单 30h 前（SLA 24h）→ 超时
    $dispute->forceFill(['created_at' => now()->subHours(30)])->save();
    $rows = collect($this->getJson('/api/admin/refund-disputes', $this->adminAuth)->json('data.list'));
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['sla_hours'])->toBe(24)
        ->and($rows[0]['sla_overdue'])->toBeTrue();

    // 刚开单（未超 SLA）→ 不超时；resolved 终态也不参与超时判定
    $dispute->forceFill(['created_at' => now()])->save();
    $rows = collect($this->getJson('/api/admin/refund-disputes', $this->adminAuth)->json('data.list'));
    expect($rows)->toHaveCount(1)->and($rows[0]['sla_overdue'])->toBeFalse();

    $dispute->update(['status' => RefundDispute::STATUS_RESOLVED_REJECT]);
    $dispute->forceFill(['created_at' => now()->subHours(30)])->save();
    $rows = collect($this->getJson('/api/admin/refund-disputes', $this->adminAuth)->json('data.list'));
    expect($rows)->toHaveCount(1)->and($rows[0]['sla_overdue'])->toBeFalse();
});

test('退货退款申请响应下发退货地址模板', function () {
    app(ConfigService::class)->set('refund.return_address_template', "广东省深圳市南山区\n退货仓 3 号门");

    [$user, $order, $buyerAuth] = ($this->mkPaidOrder)();

    $res = $this->postJson("/api/orders/{$order->public_id}/refund", [
        'type' => 'return_refund',
        'reason' => '尺码不合适',
        'return_details' => [
            ['sku_id' => $order->items->first()->sku_id, 'quantity' => 1],
        ],
    ], $buyerAuth);
    $res->assertOk();
    expect($res->json('data.return_address'))->toBe("广东省深圳市南山区\n退货仓 3 号门");

    // 仅退款类型不下发地址
    [$user2, $order2, $buyerAuth2] = ($this->mkPaidOrder)();
    $res2 = $this->postJson("/api/orders/{$order2->public_id}/refund", [
        'type' => 'refund',
        'reason' => '不想要了',
    ], $buyerAuth2);
    $res2->assertOk();
    expect($res2->json('data.return_address'))->toBeNull();
});
