<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * P2-11 终态改造（续）：子表增加存储型 public_id（ULID）列。
 *
 * 范围：order_items / reviews / refunds / user_addresses。
 * 这些表的 int 主键会出现在 API 响应体（订单行、评价、退款）或作为路由入参，
 * 与核心表一致，对外一律改用持久化 ULID public_id，退役 sqids：
 * - order_items：订单行 id 既在订单详情中暴露，又作为评价路由入参（/orders/{o}/items/{i}/review）。
 * - reviews：评价 id 在「我的评价 / 商品评价」列表与 PUT /reviews/{id} 入参中出现。
 * - refunds：refund_id 在订单详情退款列表中暴露。
 * - user_addresses：address_id 作为下单入参；即 SCOPE_ADDRESS 的归宿列。
 */
return new class extends Migration
{
    /** 必须（order_items/reviews/refunds）+ 建议（user_addresses） */
    private const TABLES = [
        'order_items', 'reviews', 'refunds', 'user_addresses',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'public_id')) {
                Schema::table($table, function (Blueprint $t) {
                    // char(26) 适配 ULID 长度；唯一索引防止重复
                    $t->char('public_id', 26)->unique()->nullable();
                });
            }
        }

        $this->backfill();
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'public_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropUnique(['public_id']);
                    $t->dropColumn('public_id');
                });
            }
        }
    }

    /**
     * 存量数据回填：按 id 分块为每行生成 ULID。
     * 新建行由 App\Models\Traits\HasPublicId 的 creating 钩子自动生成。
     */
    private function backfill(): void
    {
        foreach (self::TABLES as $table) {
            DB::table($table)
                ->whereNull('public_id')
                ->select('id')
                ->chunkById(500, function ($rows) use ($table) {
                    foreach ($rows as $row) {
                        DB::table($table)
                            ->where('id', $row->id)
                            ->update(['public_id' => (string) Str::ulid()]);
                    }
                }, 'id');
        }
    }
};
