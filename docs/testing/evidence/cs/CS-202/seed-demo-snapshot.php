<?php

/**
 * CS-202 证据脚本：为开发库工单 #1 的关联订单补齐「物流 + 退款 + 第二件商品」演示数据（幂等）
 *
 * 目的：curl 快照响应需要同时展示 订单 / 商品 / 收货 / 物流轨迹 / 退款记录 五类信息。
 * 幂等：已存在则跳过，可重复执行。
 *
 * 用法：php artisan tinker --execute="require '.../seed-demo-snapshot.php';"
 */

use Illuminate\Support\Facades\DB;

$ticketId = 1;
$orderId = (int) DB::table('cs_ticket')->where('id', $ticketId)->value('order_id');

if ($orderId === 0) {
    echo '工单 #'.$ticketId.' 未关联订单，无法构造演示数据'.PHP_EOL;

    return;
}

$order = DB::table('orders')->where('id', $orderId)->first();
echo '工单 #'.$ticketId.' → 订单 #'.$orderId.'（'.$order->order_no.'，状态 '.$order->status.'）'.PHP_EOL;

// 1. 第二件商品（展示商品清单为多行）
$itemCount = DB::table('order_items')->where('order_id', $orderId)->count();
if ($itemCount < 2) {
    DB::table('order_items')->insert([
        'order_id' => $orderId,
        'product_id' => null,
        'sku_id' => null,
        'product_title' => '磁吸充电线 1m',
        'sku_specs' => json_encode(['颜色' => '白'], JSON_UNESCAPED_UNICODE),
        'sku_image' => '/storage/demo/charge-cable.png',
        'price' => '39.00',
        'quantity' => 2,
        'total_amount' => '78.00',
        'coupon_share' => '0.00',
        'promotion_share' => '0.00',
        'created_at' => now(),
    ]);
    echo '  + 新增订单明细 1 行'.PHP_EOL;
}

// 2. 发货记录 + 轨迹
$shipping = DB::table('shippings')->where('order_id', $orderId)->orderByDesc('id')->first();
if ($shipping === null) {
    $shippingId = DB::table('shippings')->insertGetId([
        'order_id' => $orderId,
        'company_code' => 'SF',
        'company_name' => '顺丰速运',
        'tracking_no' => 'SF2026091700'.$orderId,
        'trace_status' => 'in_transit',
        'pull_fail_count' => 0,
        'shipped_at' => now()->subDays(2),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    echo '  + 新增发货记录 #'.$shippingId.PHP_EOL;
} else {
    $shippingId = $shipping->id;
    echo '  = 发货记录已存在 #'.$shippingId.'（'.$shipping->tracking_no.'）'.PHP_EOL;
}

$traceCount = DB::table('shipping_traces')->where('shipping_id', $shippingId)->count();
if ($traceCount === 0) {
    foreach ([
        ['已揽收', now()->subDays(2)],
        ['运输中（深圳转运中心）', now()->subDay()],
        ['派送中（预计今日送达）', now()->subHours(2)],
    ] as [$context, $at]) {
        DB::table('shipping_traces')->insert([
            'shipping_id' => $shippingId,
            'context' => $context,
            'occurred_at' => $at,
            'raw' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    echo '  + 新增物流轨迹 3 条'.PHP_EOL;
}

// 3. 退款记录
$refundCount = DB::table('refunds')->where('order_id', $orderId)->count();
if ($refundCount === 0) {
    $refundNo = 'RF'.date('Ymd').str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
    DB::table('refunds')->insert([
        'refund_no' => $refundNo,
        'order_id' => $orderId,
        'order_no' => $order->order_no,
        'user_id' => $order->user_id,
        'amount' => '39.00',
        'reason' => '商品外包装破损',
        'status' => 'pending',
        'admin_remark' => '客服内部：已核对凭证，待仓库确认',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    echo '  + 新增退款单 '.$refundNo.PHP_EOL;
}

echo '完成。订单 #'.$orderId.' 现有：明细 '.DB::table('order_items')->where('order_id', $orderId)->count()
    .' 行 / 物流 '.DB::table('shippings')->where('order_id', $orderId)->count()
    .' 条 / 轨迹 '.DB::table('shipping_traces')->where('shipping_id', $shippingId)->count()
    .' 条 / 退款 '.DB::table('refunds')->where('order_id', $orderId)->count().' 单'.PHP_EOL;
