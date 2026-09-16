<?php

use App\Models\BalanceRecharge;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentLog;
use App\Models\SysUser;
use App\Services\Common\CaptchaService;
use App\Services\Payment\Dto\QueryResult;
use App\Services\Payment\Gateways\MockGateway;
use App\Services\Payment\PaymentGatewayFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** 假网关：query() 恒返回「渠道已支付」，用于验证主动查单补单 */
class FakePaidGateway extends MockGateway
{
    public function query(Payment $payment, array $config): QueryResult
    {
        return QueryResult::success(Payment::STATUS_SUCCESS, 'TRADE-OK', (string) $payment->amount);
    }
}

/**
 * 集成测试：支付可靠性（收银台方案 §7.2 / §7.3，Roadmap P7）
 *
 * 覆盖：充值单超时关单 / 订单超时取消 / 主动查单补单成功 / 查单次数超限标记失败。
 */
beforeEach(function () {
    seedRoles();
    config(['app.debug' => true]);

    $username = 'rely'.uniqid();
    $cap = app(CaptchaService::class)->generate();
    $this->postJson('/api/auth/register', [
        'username' => $username,
        'password' => 'Test@1234',
        'password_confirmation' => 'Test@1234',
        'code' => $cap['debug_code'],
        'captcha_id' => $cap['captcha_id'],
    ]);
    $this->userId = SysUser::where('username', $username)->value('id');
});

/** 建一笔充值单 + 关联支付单，返回 [recharge, payment] */
function makeRechargePayment(int $userId, string $status = BalanceRecharge::STATUS_PENDING, string $expiredAt = '-1 minute'): array
{
    $recharge = BalanceRecharge::create([
        'recharge_no' => 'RC'.now()->format('Ymd').str_pad((string) random_int(1, 9999), 4, '0'),
        'user_id' => $userId,
        'amount' => '100.00',
        'gift_amount' => '0.00',
        'channel' => Payment::CHANNEL_MOCK,
        'status' => $status,
        'expired_at' => now()->modify($expiredAt),
    ]);

    $payment = Payment::create([
        'payment_no' => 'PAY-'.uniqid(),
        'order_id' => null,
        'order_no' => null,
        'user_id' => $userId,
        'channel' => Payment::CHANNEL_MOCK,
        'amount' => '100.00',
        'status' => Payment::STATUS_PENDING,
        'biz_type' => Payment::BIZ_TYPE_RECHARGE,
        'biz_no' => $recharge->recharge_no,
    ]);
    $recharge->forceFill(['payment_id' => $payment->id])->save();

    return [$recharge, $payment];
}

/* ------------------------------------------------------------------ */
/* §7.3 超时关单                                                        */
/* ------------------------------------------------------------------ */

test('充值单超时：关闭支付单并将充值单置 closed（不入账）', function () {
    [$recharge, $payment] = makeRechargePayment($this->userId, BalanceRecharge::STATUS_PENDING, '-1 minute');

    $this->artisan('payments:cancel-timeout')->assertSuccessful();

    expect($recharge->fresh()->status)->toBe(BalanceRecharge::STATUS_CLOSED)
        ->and($payment->fresh()->status)->toBe(Payment::STATUS_CLOSED)
        ->and(DB::table('user_balance_logs')->where('user_id', $this->userId)->count())->toBe(0);
});

test('未超时的充值单不受影响', function () {
    [$recharge, $payment] = makeRechargePayment($this->userId, BalanceRecharge::STATUS_PENDING, '+30 minutes');

    $this->artisan('payments:cancel-timeout')->assertSuccessful();

    expect($recharge->fresh()->status)->toBe(BalanceRecharge::STATUS_PENDING)
        ->and($payment->fresh()->status)->toBe(Payment::STATUS_PENDING);
});

test('订单超时：订单取消且待支付单关闭', function () {
    $order = Order::create([
        'user_id' => $this->userId,
        'order_no' => 'SO'.uniqid(),
        'status' => Order::STATUS_PENDING_PAYMENT,
        'pay_amount' => '70.00',
        'total_amount' => '70.00',
        'address_id' => 1,
        'address_snapshot' => ['contact_name' => '张三', 'contact_phone' => '13800001111', 'detail' => '科技园'],
    ]);
    $payment = Payment::create([
        'payment_no' => 'PAY-'.uniqid(),
        'order_id' => $order->id,
        'order_no' => $order->order_no,
        'user_id' => $this->userId,
        'channel' => Payment::CHANNEL_MOCK,
        'amount' => '70.00',
        'status' => Payment::STATUS_PENDING,
        'biz_type' => Payment::BIZ_TYPE_ORDER,
        'biz_no' => $order->order_no,
    ]);
    // 回填创建时间到 40 分钟前（超过 30 分钟超时阈值）
    DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subMinutes(40)]);

    $this->artisan('payments:cancel-timeout')->assertSuccessful();

    expect($order->fresh()->status)->toBe(Order::STATUS_CANCELLED)
        ->and($payment->fresh()->status)->toBe(Payment::STATUS_CLOSED);
});

test('dry-run 不改变任何状态', function () {
    [$recharge] = makeRechargePayment($this->userId, BalanceRecharge::STATUS_PENDING, '-1 minute');

    $this->artisan('payments:cancel-timeout', ['--dry-run' => true])->assertSuccessful();

    expect($recharge->fresh()->status)->toBe(BalanceRecharge::STATUS_PENDING);
});

/* ------------------------------------------------------------------ */
/* §7.2 主动查单补偿                                                    */
/* ------------------------------------------------------------------ */

test('sync-pending：渠道已支付但回调未到时补单并入账', function () {
    [$recharge, $payment] = makeRechargePayment($this->userId, BalanceRecharge::STATUS_PENDING, '+30 minutes');
    // 落入 2~30 分钟扫描窗口
    DB::table('payments')->where('id', $payment->id)->update(['created_at' => now()->subMinutes(5)]);

    // 假网关：query() 返回渠道已支付
    $this->mock(PaymentGatewayFactory::class, function ($mock) {
        $mock->shouldReceive('make')->andReturn(new FakePaidGateway());
    });

    $this->artisan('payments:sync-pending')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(Payment::STATUS_SUCCESS)
        ->and($recharge->fresh()->status)->toBe(BalanceRecharge::STATUS_SUCCESS)
        ->and(app(\App\Services\Payment\BalanceService::class)->balance($this->userId))->toBe('100.00');
});

test('sync-pending：查单次数超限标记失败并记日志', function () {
    [$recharge, $payment] = makeRechargePayment($this->userId, BalanceRecharge::STATUS_PENDING, '+30 minutes');
    DB::table('payments')->where('id', $payment->id)->update(['created_at' => now()->subMinutes(5)]);

    // 配置最大查单次数为 1，并预置一条 query 日志使计数达上限
    app(\App\Services\Common\ConfigService::class)->set('payment.query_max_attempts', '1');
    PaymentLog::create([
        'payment_id' => $payment->id,
        'payment_no' => $payment->payment_no,
        'event' => PaymentLog::EVENT_QUERY,
        'request_data' => [],
        'response_data' => [],
    ]);

    $this->artisan('payments:sync-pending')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(Payment::STATUS_FAILED)
        ->and($recharge->fresh()->status)->toBe(BalanceRecharge::STATUS_FAILED)
        ->and(PaymentLog::where('payment_id', $payment->id)->where('event', PaymentLog::EVENT_QUERY)->count())->toBe(2);
});
