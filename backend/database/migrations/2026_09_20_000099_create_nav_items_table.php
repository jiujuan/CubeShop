<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 前台顶部导航条目表（导航可管理化）
 *
 * 一条导航 = 一个条目，两类：
 * - `category`：引用商品分类（存 category_id），标题与链接由分类派生 —— 分类改名、停用自动跟随
 * - `custom`：自定义标题 + 任意 URL（站内 /news 或站外 https://…），可设 target
 *
 * 位置由统一 sort 决定（越大越前，与 categories.sort 体例一致），
 * 因此自定义条目可以插在两个分类中间。
 *
 * ⚠️ category_id **不加物理外键**：分类是软删除（SoftDeletes），物理外键会挡住软删；
 * 失效引用改由公开接口聚合时跳过（见 NavController）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nav_items', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16)->comment('category=商品分类引用 / custom=自定义链接');
            $table->string('title', 64)->nullable()->comment('仅 custom 用；category 型标题取自分类');
            $table->string('url', 255)->nullable()->comment('仅 custom 用；站内 /news 或站外 https://…');
            $table->unsignedBigInteger('category_id')->nullable()->comment('仅 category 用');
            $table->string('target', 16)->default('_self')->comment('_self / _blank');
            $table->integer('sort')->default(0)->comment('越大越前');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // 同一分类只登记一次（MySQL/pgsql 下多个 NULL 互不冲突）
            $table->unique('category_id', 'nav_items_category_id_unique');
            $table->index(['is_active', 'sort'], 'nav_items_active_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nav_items');
    }
};
