<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * P2-11 终态改造：核心表增加存储型 public_id（ULID）列。
 *
 * - 对外标识由「sqids 编码 int 主键」升级为「数据库持久化的 ULID public_id」，
 *   不再依赖 app.key，且时间有序（避免 UUIDv4 的索引页分裂）。
 * - 仅作为边界（出口/路由入参）使用的对外标识；内部 whereIn/JOIN/报表仍用 int 主键。
 * - 后台（已鉴权）仍可显示真实 id，本迁移不影响内部查询。
 */
return new class extends Migration
{
    /** 必须（users/products/product_skus/orders/cs_ticket）+ 建议（categories/brands） */
    private const TABLES = [
        'users', 'products', 'product_skus', 'orders', 'cs_ticket', 'categories', 'brands',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'public_id')) {
                Schema::table($table, function (Blueprint $t) {
                    // SQLite 忽略 after()，统一追加到末尾；char(26) 适配 ULID 长度
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
