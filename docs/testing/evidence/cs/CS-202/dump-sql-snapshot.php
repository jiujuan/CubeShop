<?php

/**
 * CS-202 证据脚本：order_snapshot 与底层订单/物流/退款表的交叉核对。
 *
 * 用法（开发库 PG cubeshop）：
 *   php artisan tinker --execute="require 'D:/codeproject/PHP/CubeShop/docs/testing/evidence/cs/CS-202/dump-sql-snapshot.php';" > sql-CS-202.md
 *
 * 仅读取 orders / order_items / shippings / shipping_traces / refunds，不修改任何数据。
 */

use Illuminate\Support\Facades\DB;

$ticketId = 1;
$ticket = DB::table('cs_ticket')->where('id', $ticketId)->first();
$orderId = (int) ($ticket->order_id ?? 0);

$out = [];
$out[] = '# CS-202 证据：order_snapshot ↔ 底层表交叉核对';
$out[] = '';
$out[] = '- 生成时间：' . date('Y-m-d H:i:s');
$out[] = '- 数据库：' . DB::connection()->getDriverName() . ' / ' . DB::connection()->getDatabaseName();
$out[] = "- 工单：cs_ticket#{$ticketId} → order#{$orderId}";
$out[] = '';
$out[] = '快照由 `CsTicketService::orderSnapshot()` 实时聚合以下表，本文件列出原始行以证明字段来源一致。';
$out[] = '';

$order = DB::table('orders')->where('id', $orderId)->first();
$addr = json_decode((string) ($order->address_snapshot ?? ''), true) ?: [];

$out[] = '## 1. orders（快照头字段来源）';
$out[] = '';
$out[] = '| 字段 | 原始值 | 快照字段 |';
$out[] = '|------|--------|----------|';
$out[] = "| id | {$order->id} | order_id |";
$out[] = "| order_no | {$order->order_no} | order_no |";
$out[] = "| status | {$order->status} | status / status_label |";
$out[] = "| pay_amount | {$order->pay_amount} | pay_amount |";
$out[] = "| created_at | {$order->created_at} | created_at |";
$out[] = "| address_snapshot.contact_name | " . ($addr['contact_name'] ?? '') . ' | address.contact_name |';
$out[] = "| address_snapshot.contact_phone | " . ($addr['contact_phone'] ?? '') . ' | address.phone_masked（脱敏） |';
$out[] = '';

$items = DB::table('order_items')->where('order_id', $orderId)->orderBy('id')->get();
$out[] = '## 2. order_items（商品清单来源，读快照列 `sku_image` / `product_title`，不查 products 表）';
$out[] = '';
$out[] = '| id | product_title（→ 快照 title） | sku_image（→ 快照 image） | price | quantity |';
$out[] = '|----|------|-----------|-------|----------|';
$qtySum = 0;
foreach ($items as $it) {
    $qtySum += (int) $it->quantity;
    $img = $it->sku_image === null ? 'null' : $it->sku_image;
    $out[] = "| {$it->id} | {$it->product_title} | {$img} | {$it->price} | {$it->quantity} |";
}
$out[] = '';
$out[] = "> 明细行数 = " . count($items) . "，数量合计 = {$qtySum}；快照 `item_count` = 数量合计。";
$out[] = '';

$ship = DB::table('shippings')->where('order_id', $orderId)->orderByDesc('id')->first();
$out[] = '## 3. shippings / shipping_traces（物流来源）';
$out[] = '';
if ($ship) {
    $out[] = "| shipping.id | company_code | company_name | tracking_no | trace_status |";
    $out[] = '|-------------|--------------|--------------|-------------|--------------|';
    $out[] = "| {$ship->id} | {$ship->company_code} | {$ship->company_name} | {$ship->tracking_no} | {$ship->trace_status} |";
    $out[] = '';
    $traces = DB::table('shipping_traces')->where('shipping_id', $ship->id)
        ->orderByDesc('occurred_at')->orderByDesc('id')->get();
    $out[] = '轨迹（按 occurred_at 倒序，快照取第 1 条为 latest_trace）：';
    $out[] = '';
    $out[] = '| id | occurred_at | context |';
    $out[] = '|----|-------------|---------|';
    foreach ($traces as $i => $tr) {
        $mark = $i === 0 ? ' ← latest' : '';
        $out[] = "| {$tr->id} | {$tr->occurred_at} | {$tr->context}{$mark} |";
    }
} else {
    $out[] = '_该订单无物流记录_';
}
$out[] = '';

$refunds = DB::table('refunds')->where('order_id', $orderId)->orderByDesc('id')->get();
$out[] = '## 4. refunds（退款记录来源，按 id 倒序）';
$out[] = '';
if ($refunds->count()) {
    $out[] = '| id | refund_no | amount | status | admin_remark |';
    $out[] = '|----|-----------|--------|--------|--------------|';
    foreach ($refunds as $r) {
        $remark = (string) ($r->admin_remark ?? '');
        $out[] = "| {$r->id} | {$r->refund_no} | {$r->amount} | {$r->status} | {$remark} |";
    }
} else {
    $out[] = '_该订单无退款记录_';
}
$out[] = '';
$out[] = '> `admin_remark` 仅后台快照（forStaff=true）返回，买家端剥离；`refund_no` 亦进 `jump.latest_refund_no`。';
$out[] = '';

echo implode("\n", $out) . "\n";
