<?php

namespace App\Providers;

use App\Models\SysUser;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 物流轨迹渠道适配器（V1.1 T-045）：按配置切换，留空 = NullChannel 降级
        $this->app->bind(\App\Support\Shipping\ShippingChannelInterface::class, function () {
            return match (config('services.shipping.channel')) {
                'mock' => new \App\Support\Shipping\MockChannel(),
                'kuaidi100' => new \App\Support\Shipping\Kuaidi100Channel(),
                default => new \App\Support\Shipping\NullChannel(),
            };
        });

        // 电子面单申请渠道（出单侧，与轨迹查询对称，V1.2）：按配置切换，留空 = NullWaybillChannel 降级
        $this->app->bind(\App\Support\Shipping\WaybillChannelInterface::class, function () {
            return match (config('services.waybill.channel')) {
                'mock' => new \App\Support\Shipping\MockWaybillChannel(),
                'kuaidi100' => new \App\Support\Shipping\Kuaidi100WaybillChannel(),
                default => new \App\Support\Shipping\NullWaybillChannel(),
            };
        });
    }

    /**
     * 支付安全配置启动自检（SEC-01 / SEC-02）
     *
     * 目标：把「生产误配导致的 0 元购与伪造回调」从线上事故前移到启动阶段。
     * 原则：只做 fail-fast 与留痕告警，不改变任何业务行为。
     */
    private function assertPaymentSecurityConfig(): void
    {
        // SEC-02：空密钥 → HMAC 退化为可预测，任何人都能构造合法 sign 伪造「支付成功」回调
        if (blank(config('payments.secret'))) {
            logger()->critical('[SEC-02] PAY_SIGN_SECRET 未配置：支付回调验签形同虚设，攻击者可伪造支付成功回调。'
                .' 生成方式：php -r "echo bin2hex(random_bytes(32));"');

            // local/testing 与 artisan 命令不中断（保留本地开发与线上修复通道），其余环境直接 500
            if (! $this->app->environment('local', 'testing') && ! $this->app->runningInConsole()) {
                abort(500, '支付签名密钥未配置（PAY_SIGN_SECRET），请联系管理员');
            }
        }

        // SEC-01：生产误开沙箱（路由层按环境注册、服务层已断言，此处仅留痕告警便于发现误配）
        if (! $this->app->environment('local', 'testing', 'staging') && config('payments.sandbox')) {
            logger()->critical('[SEC-01] 生产环境 PAYMENT_SANDBOX=true：沙箱路由虽已按环境注销，'
                .'仍请立即置为 false，避免其他链路误用。');
        }
    }

    /**
     * 物流查询渠道：后台配置（system_configs.shipping.channel）覆盖 .env
     *
     * 目的是让运营能在后台切换渠道而无需改配置重启；留空表示跟随 .env 的 SHIPPING_CHANNEL。
     * ⚠️ 密钥（key/customer）不入库，仍走 .env——凭证扩散风险高于便利性收益。
     */
    private function applyShippingChannelOverride(): void
    {
        try {
            $override = trim((string) (app(\App\Services\Common\ConfigService::class)->get('shipping.channel') ?? ''));
        } catch (\Throwable) {
            // 系统表未建立（安装/迁移前）或数据库不可用时，保持 env 配置
            return;
        }

        if ($override === '') {
            return;
        }

        // off = 强制关闭查询（覆盖 env 中已配置的渠道）
        config(['services.shipping.channel' => $override === 'off' ? null : $override]);
    }

    /**
     * 电子面单申请渠道：后台配置（system_configs.waybill.channel）覆盖 .env
     *
     * 与 applyShippingChannelOverride 完全对称——运营可在后台切换发货时是否自动出单，
     * 不必改 .env 重启。留空表示跟随 .env 的 WAYBILL_CHANNEL。
     * ⚠️ 密钥（key/customer）不入库，仍走 .env（凭证扩散风险高于便利性收益）。
     */
    private function applyWaybillChannelOverride(): void
    {
        try {
            $override = trim((string) (app(\App\Services\Common\ConfigService::class)->get('waybill.channel') ?? ''));
        } catch (\Throwable) {
            // 系统表未建立（安装/迁移前）或数据库不可用时，保持 env 配置
            return;
        }

        if ($override === '') {
            return;
        }

        // off = 强制手动录入（覆盖 env 中已配置的渠道，不出电子面单）
        config(['services.waybill.channel' => $override === 'off' ? null : $override]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->assertPaymentSecurityConfig();
        $this->applyShippingChannelOverride();
        $this->applyWaybillChannelOverride();

        // 超级管理员绕过全部权限校验：角色定义上超管即拥有所有权限，
        // 避免后续新增权限码时因未同步授权而导致超管被误判为无权限（V1.1 reports 403 问题）
        Gate::before(function ($user) {
            return $user instanceof SysUser && $user->hasRole('super_admin') ? true : null;
        });

        // 认证类接口限流（API 文档 11.4：登录/验证码/重置密码）
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // 登录接口更严格：同 IP + 用户名 5 次/分钟
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->input('username', '').'|'.$request->ip());
        });

        // 下单接口限流（API 文档 11.4：下单接口建议限流）：同用户 10 次/分钟
        RateLimiter::for('order', function (Request $request) {
            return Limit::perMinute(10)->by('order|'.($request->user()?->id ?? $request->ip()));
        });

        // 主动查单补偿（结果页轮询兜底）：同用户 10 次/分钟
        RateLimiter::for('sync', function (Request $request) {
            return Limit::perMinute(10)->by('sync|'.($request->user()?->id ?? $request->ip()));
        });

        // 凭证上传：同用户 30 次/分钟（另有单日 20 张业务上限）
        RateLimiter::for('voucher', function (Request $request) {
            return Limit::perMinute(30)->by('voucher|'.($request->user()?->id ?? $request->ip()));
        });

        // 发起充值：同用户 10 次/分钟（§6.5 风控，避免刷单）
        RateLimiter::for('recharge', function (Request $request) {
            return Limit::perMinute(10)->by('recharge|'.($request->user()?->id ?? $request->ip()));
        });

        // 领券（V1.1 二期 T-033）：同用户 20 次/分钟（配合后端限领与原子防超发）
        RateLimiter::for('coupon', function (Request $request) {
            return Limit::perMinute(20)->by('coupon|'.($request->user()?->id ?? $request->ip()));
        });

        // WMS 回调入口（WMS 计划 P3 / Step 2）：公开无认证，按 IP 限流防洪水
        RateLimiter::for('wms-callback', function (Request $request) {
            return Limit::perMinute(120)->by('wms-callback|'.$request->ip());
        });

        // V1.1 F02 / T-018：业务事件 → 通知监听器（站内信 + 邮件）
        Event::listen(\App\Events\OrderPaid::class, \App\Listeners\SendOrderPaidNotification::class);
        Event::listen(\App\Events\OrderShipped::class, \App\Listeners\SendOrderShippedNotification::class);
        Event::listen(\App\Events\RefundResult::class, \App\Listeners\SendRefundResultNotification::class);
        Event::listen(\App\Events\ReviewReplied::class, \App\Listeners\SendReviewRepliedNotification::class);
        Event::listen(\App\Events\LowStockAlert::class, \App\Listeners\SendLowStockNotification::class);

        // WMS 计划 P1：订单进入待发货 → 建发货单（未启用 WMS 时监听器直接返回，零影响）
        Event::listen(\App\Events\OrderAcceptedForShipment::class, \App\Listeners\Wms\CreateFulfillmentOrder::class);

        // WMS 计划 P4：退货退款审核通过 → 建退货入库单（未启用 WMS 时监听器捕获异常留痕，零影响）
        Event::listen(\App\Events\RefundApproved::class, \App\Listeners\CreateReturnInboundOrder::class);
    }
}
