<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 站内搜索（S1-02）：为 products 增加检索文本列；PG 额外建生成列与 GIN 索引
 *
 * 设计文档：docs/design/CubeShop_Search_Design_v1.0.md §4.2
 *
 * 分两层，因为测试固定跑 SQLite（`phpunit.xml` 写死 `sqlite :memory:`），
 * 而 PG 的 tsvector/GIN 在 SQLite 上根本不存在：
 *
 * 1. **通用层**：`search_title` / `search_body` 两个 text 列（两种驱动都建）。
 *    内容由 PHP 侧 `SearchIndexWriter` 分词后写入，本身不含任何 PG 特性，
 *    因此降级引擎（LIKE）与单元测试在 SQLite 下也能完整跑。
 * 2. **PG 层**：`search_vector` 生成列（`to_tsvector('simple', ...)` 加权拼接）+ GIN 索引。
 *    向量由 PG 自动派生 —— **不需要触发器、不需要同步任务、应用层不写这段 SQL**。
 *
 * 为什么用生成列而不是触发器：生成列是声明式的，不可能与源列不一致；
 * 触发器则可能在某条写入路径上被绕过而静默失修。
 * PG < 12 不支持生成列，届时的触发器写法见设计文档 §10.2（当前 PG 16，仅备案）。
 *
 * ⚠️ `to_tsvector` 必须显式传 regconfig（这里写死 `'simple'`）：
 * 单参版本 `to_tsvector(text)` 是 STABLE（依赖 default_text_search_config），
 * 生成列要求表达式 IMMUTABLE，否则 PG 直接拒绝建列。
 * `'simple'` 不做词干还原，正好匹配「PHP 侧已分好词」的输入。
 */
return new class extends Migration
{
    /** GIN 索引名（down 时按名删除） */
    private const GIN_INDEX = 'products_search_vector_gin';

    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->text('search_title')->nullable()->comment('检索文本·标题域（应用层分词写入）');
            $table->text('search_body')->nullable()->comment('检索文本·正文域（副标题/关键词/SKU/品牌/分类/属性）');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE products
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('simple', coalesce(search_title, '')), 'A') ||
                setweight(to_tsvector('simple', coalesce(search_body,  '')), 'B')
            ) STORED
        SQL);

        DB::statement('CREATE INDEX '.self::GIN_INDEX.' ON products USING GIN (search_vector)');

        DB::statement("COMMENT ON COLUMN products.search_vector IS '全文检索向量（生成列，由 search_title/search_body 派生）'");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            // 先删索引再删列；都用 IF EXISTS，保证半途失败后重跑回滚也不会炸
            DB::statement('DROP INDEX IF EXISTS '.self::GIN_INDEX);
            DB::statement('ALTER TABLE products DROP COLUMN IF EXISTS search_vector');
        }

        // PG 下删除被生成列依赖的源列会自动连带删掉生成列，故上面已显式删过，这里安全
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['search_title', 'search_body']);
        });
    }
};
