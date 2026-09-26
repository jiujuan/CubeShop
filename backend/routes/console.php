<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 订单超时自动取消 + 充值单超时关闭（收银台方案 §7.3）：每分钟一次，覆盖 order / recharge
// （订单分支复用 OrderService::cancelExpired：释放锁定库存 + 关支付单 + 订单流水）
Schedule::command('payments:cancel-timeout')->everyMinute()->withoutOverlapping();

// 主动查单补偿（收银台方案 §7.2）：每分钟扫描卡在处理中的在线支付单
Schedule::command('payments:sync-pending')->everyMinute()->withoutOverlapping();

// 退款异步兜底轮询（Phase 4）：每 15 分钟扫描微信退款 processing 超时单并向渠道查单
Schedule::command('refunds:sync-processing')->everyFifteenMinutes()->withoutOverlapping();

// 支付渠道日终对账（A7）：每日 02:00 跑前一日，差异生成工单交运营处置
Schedule::command('payments:reconcile')->dailyAt('02:00')->withoutOverlapping();

// 订单自动确认收货（V1.1 T-003）：每小时检查 shipped 超期订单
Schedule::command('orders:auto-complete')->hourly()->withoutOverlapping();

// 优惠券过期收敛（V1.1 二期 T-033 / T-037）：每小时把过期未用券置 expired
Schedule::command('coupons:expire')->hourly()->withoutOverlapping();

// 物流轨迹拉取（V1.1 二期 T-045）：每 30 分钟拉取在途运单轨迹；未配置渠道时命令内部安全跳过
Schedule::command('shipping:pull-traces')->everyThirtyMinutes()->withoutOverlapping();

// 图片资产扫描（媒体治理 P0）：每天登记磁盘文件并重算引用计数，便于孤儿识别
Schedule::command('media:scan')->dailyAt('03:10')->withoutOverlapping();

// 图片回收通知（媒体治理 P0）：每天早上列出「软删满 30 天且无引用」的清单。
// ⚠️ 只通知不删除 —— 物理删除必须人工执行 `media:prune --force`（会二次确认）。
Schedule::command('media:prune --notify')->dailyAt('03:20')->withoutOverlapping();

// 检索索引校准（站内搜索 S1-08）：每天全量重算一次商品的 search_title / search_body。
// 幂等且只写变化行 —— 兜住「直接改库 / 批量导入 / 开关从关改开 / 同步路径漏行」四类陈旧。
Schedule::command('search:reindex')->dailyAt('03:40')->withoutOverlapping();

// WMS 回调幂等登记清理（WMS 计划 P3 / Step 6）：每天 04:20 清理过期登记
Schedule::command('wms:prune-callbacks')->dailyAt('04:20')->withoutOverlapping();

/*
 | WMS 库存同步 / 对账 / 日志清理 / 健康巡检（WMS 计划 P5）
 |
 | ⚠️ 全部受 `config('wms.schedule.*')` 开关控制：总开关 WMS_SCHEDULE_ENABLED=false
 | 即整体不注册（本地开发不被打扰），单项 env 可只停某一路。
 | 开关判定与频率见 {@see \App\Console\WmsScheduler}。
 */
(new \App\Console\WmsScheduler)(Schedule::getFacadeRoot());
