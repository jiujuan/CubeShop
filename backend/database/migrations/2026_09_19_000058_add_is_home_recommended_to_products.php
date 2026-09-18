<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 首页推荐（P-HomeRecommend）：
 * products.is_home_recommended —— 后台商品编辑页勾选后，该商品出现在前台首页「产品推荐」栏。
 *
 * 语义要点：
 * - 只是一条「推荐标记」，与上下架解耦：下架商品即使勾了也不在前台露出；
 * - 前台排序为 sort 倒序 → 上架时间（created_at）倒序，由公开接口保证，不依赖调用方。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_home_recommended')->default(false)->after('sort')->comment('首页推荐：1=在前台首页产品推荐栏展示');
            // 前台查询固定命中 (is_home_recommended, status) 并按 sort 排序
            $table->index(['is_home_recommended', 'status', 'sort'], 'products_home_recommended_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_home_recommended_index');
            $table->dropColumn('is_home_recommended');
        });
    }
};
