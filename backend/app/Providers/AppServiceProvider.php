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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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

        // V1.1 F02 / T-018：业务事件 → 通知监听器（站内信 + 邮件）
        Event::listen(\App\Events\OrderPaid::class, \App\Listeners\SendOrderPaidNotification::class);
        Event::listen(\App\Events\OrderShipped::class, \App\Listeners\SendOrderShippedNotification::class);
        Event::listen(\App\Events\RefundResult::class, \App\Listeners\SendRefundResultNotification::class);
        Event::listen(\App\Events\ReviewReplied::class, \App\Listeners\SendReviewRepliedNotification::class);
        Event::listen(\App\Events\LowStockAlert::class, \App\Listeners\SendLowStockNotification::class);
    }
}
