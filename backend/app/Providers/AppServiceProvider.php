<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
    }
}
