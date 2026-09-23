<?php

use App\Models\Product;
use App\Support\Search\SearchIndexWriter;
use Illuminate\Database\Migrations\Migration;

/**
 * 站内搜索（S1-02）：存量商品回填检索列
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.2
 *
 * 迁移 000119 只建了空列，历史商品的两列都是 null —— 不回填则线上搜不到任何老商品。
 *
 * 幂等：字段构建是纯函数（同输入恒同输出），且值未变化的行不写回，
 * 故重复执行无副作用；中途失败可直接重跑。
 *
 * 两个刻意的取舍：
 * - **含软删商品**：软删商品可被恢复，恢复后若索引为空就只能等下次 reindex，
 *   与其留坑不如一并写；查询侧统一 `where status = 1` 过滤，不影响召回。
 * - **走 query builder 而非模型 save**：不触发模型事件、不刷 `updated_at`，
 *   避免"跑一次迁移把全表更新时间刷一遍"（会污染按更新时间排序的运营视图）。
 */
return new class extends Migration
{
    /** 分块大小（与 media:scan 同量级，避免单块内存与锁持有时间过长） */
    private const CHUNK = 500;

    public function up(): void
    {
        // 与 search:reindex、品牌/分类改名级联共用同一份批量逻辑，避免三处各写一遍
        app(SearchIndexWriter::class)->reindexQuery(Product::query()->withTrashed(), self::CHUNK);
    }

    /**
     * 无需回滚：000119 的 down 会把两列直接删掉，回填的值随列一起消失。
     * 这里刻意留空而不是「置 null」，因为反向迁移已在更底层把数据清干净了。
     */
    public function down(): void
    {
        //
    }
};
