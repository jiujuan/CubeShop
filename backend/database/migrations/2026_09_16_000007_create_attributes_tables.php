<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E01 商品属性与 SPU 体系：品牌库 / 属性库 / 分类属性模板 / 商品参数值
 * 对应设计文档：CubeShop_V1.1_Backend_Design §1.5（E01）
 *
 * 兼容性：product_skus.specs 的读取路径完全保留，本迁移不做任何字段删除或结构变更。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 品牌库
        Schema::create('brands', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 64)->unique()->comment('品牌名');
            $table->string('logo', 500)->nullable()->comment('品牌 logo');
            $table->integer('sort')->default(0);
            $table->smallInteger('status')->default(1)->comment('0=停用 1=启用');
            $table->timestamps();
        });

        // 属性库（规格属性 spec / 参数属性 param）
        Schema::create('attributes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 50)->comment('属性名，如 颜色 / 材质 / 功率');
            $table->string('type', 10)->comment('spec=规格属性 param=参数属性');
            $table->boolean('is_filterable')->default(false)->comment('是否可用于前台筛选');
            $table->boolean('is_multiple')->default(false)->comment('参数属性是否多值');
            $table->boolean('allow_custom')->default(false)->comment('参数属性是否允许自由文本值');
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->index('type');
        });

        // 属性值
        Schema::create('attribute_values', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('attribute_id');
            $table->string('value', 64);
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->foreign('attribute_id')->references('id')->on('attributes')->cascadeOnDelete();
            $table->unique(['attribute_id', 'value']);
        });

        // 分类属性模板
        Schema::create('category_attributes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('attribute_id');
            $table->boolean('is_required')->default(false);
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
            $table->foreign('attribute_id')->references('id')->on('attributes')->cascadeOnDelete();
            $table->unique(['category_id', 'attribute_id']);
        });

        // 商品参数值（参数类属性的取值，规格类走 SKU）
        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('attribute_id');
            $table->string('value', 255);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('attribute_id')->references('id')->on('attributes')->cascadeOnDelete();
            $table->index(['attribute_id', 'value']);
            $table->index('product_id');
        });

        // 商品主表扩展：品牌 / 重量 / 视频 / 搜索关键词
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('brand_id')->nullable()->comment('品牌');
            $table->integer('weight')->default(0)->comment('重量（克），运费按重量计费使用');
            $table->string('video_url', 512)->nullable()->comment('主图视频');
            $table->text('keywords')->nullable()->comment('搜索关键词');

            $table->foreign('brand_id')->references('id')->on('brands')->nullOnDelete();
            $table->index('brand_id');
        });

        // 订单行项目：规格快照（结构化），供后续属性体系下的一致性追溯
        Schema::table('order_items', function (Blueprint $table) {
            $table->jsonb('sku_specs_snapshot')->nullable()->comment('规格快照（结构化）');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('sku_specs_snapshot');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
            $table->dropIndex(['brand_id']);
            $table->dropColumn(['brand_id', 'weight', 'video_url', 'keywords']);
        });

        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('category_attributes');
        Schema::dropIfExists('attribute_values');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('brands');
    }
};
