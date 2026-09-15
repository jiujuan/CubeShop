<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 订单超时自动取消（Roadmap P4）：每分钟检查一次 pending_payment 超时订单
Schedule::command('orders:cancel-expired')->everyMinute();

// 订单自动确认收货（V1.1 T-003）：每小时检查 shipped 超期订单
Schedule::command('orders:auto-complete')->hourly()->withoutOverlapping();
