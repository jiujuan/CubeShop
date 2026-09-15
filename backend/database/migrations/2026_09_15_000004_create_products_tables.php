<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 商品主表 / SKU / 商品图片
 * 对应设计文档：2.3 products、product_skus、product_images
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('category_id')->nullable()->comment('分类');
            $table->string('title', 255)->comment('商品标题');
            $table->string('subtitle', 255)->nullable()->comment('副标题');
            $table->string('main_image', 512)->nullable()->comment('主图 URL');
            $table->text('description')->nullable()->comment('详情（富文本/HTML）');
            $table->decimal('price', 12, 2)->comment('展示价');
            $table->smallInteger('status')->default(0)->comment('0=下架 1=上架');
            $table->integer('sales_count')->default(0)->comment('销量（冗余）');
            $table->integer('sort')->default(0)->comment('排序');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('category_id')->references('id')->on('categories')->nullOnDelete();
            $table->index(['status', 'category_id']);
            $table->index('title');
        });

        Schema::create('product_skus', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_id')->comment('所属商品');
            $table->string('sku_code', 64)->nullable()->unique()->comment('SKU 编码');
            $table->jsonb('specs')->nullable()->comment('规格，如 {"颜色":"红","尺码":"L"}');
            $table->decimal('price', 12, 2)->comment('售价');
            $table->smallInteger('status')->default(1)->comment('1=启用 0=禁用');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->index('product_id');
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_id')->comment('所属商品');
            $table->string('url', 512)->comment('图片 URL');
            $table->integer('sort')->default(0)->comment('排序');
            $table->timestamp('created_at')->nullable();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_skus');
        Schema::dropIfExists('products');
    }
};
